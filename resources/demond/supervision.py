# This file is part of Jeedom.
#
# Jeedom is free software: you can redistribute it and/or modify
# it under the terms of the GNU General Public License as published by
# the Free Software Foundation, either version 3 of the License, or
# (at your option) any later version.
#
# Jeedom is distributed in the hope that it will be useful,
# but WITHOUT ANY WARRANTY; without even the implied warranty of
# MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
# GNU General Public License for more details.
#
# You should have received a copy of the GNU General Public License
# along with Jeedom. If not, see <http://www.gnu.org/licenses/>.
#
#
# Superviseur temps reel du plugin JeeRoborock (UC10).
#
# Branche un ecouteur de push sur le trait "status" de chaque robot V1 et fait tourner
# une sonde periodique par robot (30 s en nettoyage / 60 s au repos), puis pousse des
# lots vers Jeedom par le callback jeedom_com deja en place (canal.py/jeeroborockd.py).
#
# Le lot publie a EXACTEMENT la forme de la reponse lireEtat de robots.py (UC07) : PHP
# la consomme via jeeroborock::appliquerEtatPartiel(), sans aucune modification de cette
# methode (ni son corps, ni sa visibilite private).
#
# Deux proprietes structurelles, pas declaratives (cf. spec technique) :
# - Quota nul en regime etabli : la sonde passe par le canal RPC (local puis MQTT), qui
#   n'a AUCUN limiteur. Le seul cout est 1 homedata par construction du DeviceManager.
# - Isolation entre robots : une tache asyncio par robot, un reamorcage RECONCILIATEUR
#   (une sonde vivante n'est jamais annulee), et un try/except par robot.
#
# R4 - PIEGE D'EMPREINTE : demarrer() recoit et conserve le dict BRUT recu du PHP,
# JAMAIS contexte['session'] (qui est une valeur RE-ENCODEE par restaurer_session, donc
# potentiellement differente octet a octet). Passer contexte['session'] ferait
# reconstruire le gestionnaire a chaque alternance superviseur/action utilisateur, soit
# 2 homedata et 2 sessions MQTT.
#
# R17 - IDEMPOTENCE, EXIGENCE D'ACCEPTATION (AC5) : un reamorcage destructif (annuler
# puis tout recreer) interromprait le temps reel de TOUS les robots a chaque tick de
# cron des qu'UN robot est hors ligne - cas NOMINAL, pas une panne. demarrer() et
# _reconcilier() ne touchent donc JAMAIS une sonde vivante.

import asyncio
import hashlib
import logging
import re

import libelles
import robots
from erreurs import code_pour_exception
from textes import texte as _texte

INTERVALLE_LOT_S = 2            # fenetre de regroupement de jeedom_com
DELAI_AMORCAGE_S = 20           # avant la 1re construction du gestionnaire (garde-fou quota)
DELAIS_REPRISE_S = (60, 300, 900, 1800)
CADENCE_NETTOYAGE_S = 30
CADENCE_REPOS_S = 60
CADENCE_ECHEC_S = 120
SEUIL_ECHECS = 3
FENETRE_COALESCENCE_S = 1.0
DELAI_SONDAGE_S = 12
MOTIF_DUID = r"\A[A-Za-z0-9_.-]{4,128}\z"   # SANS deux-points (cf. R8)
CODES_ARRET = {"AUTH_EXPIRED", "NOT_AUTHENTICATED"}

_RE_DUID = re.compile(MOTIF_DUID)


def _empreinte(user_data_brut):
    return hashlib.sha256((user_data_brut or "").encode("utf-8")).hexdigest()


def demarrer(contexte, parametres):
    """Synchrone, IDEMPOTENTE, ne leve JAMAIS, retour immediat. Appelable a chaque
    minute par cron() sans effet de bord (AC5).

    parametres est le dict BRUT recu du PHP (userData/baseUrl/email), jamais
    contexte['session'] (R4, invariant)."""
    try:
        empreinte = _empreinte(parametres.get("userData"))

        etat = contexte.get("superviseur")
        if etat is not None and etat.get("empreinte") != empreinte:
            # Autre compte / nouveau login : on repart d'un etat neuf, en fermant
            # l'ancien gestionnaire (il porte l'ancienne session MQTT).
            arreter(contexte, fermer_gestionnaire=True)
            etat = None

        if etat is None:
            etat = {
                "empreinte": empreinte,
                "parametres": dict(parametres),
                "tache": None,
                "sondes": {},
            }
            contexte["superviseur"] = etat
        else:
            # Empreinte identique : on met a jour les parametres (baseUrl/email
            # peuvent varier sans que le userData change), sans toucher aux sondes.
            etat["parametres"] = dict(parametres)

        if etat["tache"] is None or etat["tache"].done():
            tache = asyncio.create_task(_superviser(contexte))
            tache.add_done_callback(_journaliser_fin)
            etat["tache"] = tache
    except Exception as erreur:
        # exc_info conserve ici : erreur de code local (manipulation de contexte/dict),
        # pas une exception venue de la librairie ou du canal RPC (cf. les 4 catch-all
        # ci-dessous qui, eux, ne journalisent jamais le message brut).
        logging.error("supervision.demarrer en erreur : %s", erreur, exc_info=True)


def arreter(contexte, fermer_gestionnaire=True):
    """Synchrone, IDEMPOTENTE, ne leve JAMAIS."""
    try:
        etat = contexte.get("superviseur")
        if etat is not None:
            for duid in list(etat.get("sondes", {}).keys()):
                _retirer(etat["sondes"], duid)
            tache = etat.get("tache")
            if tache is not None and not tache.done():
                tache.cancel()
        contexte["superviseur"] = None

        if fermer_gestionnaire:
            # Jamais attendue : restaurerSession est bornee a 3 s cote PHP.
            asyncio.create_task(robots.fermer_gestionnaire(contexte))
    except Exception as erreur:
        # exc_info conserve ici : erreur de code local (manipulation de contexte/dict),
        # pas une exception venue de la librairie ou du canal RPC.
        logging.error("supervision.arreter en erreur : %s", erreur, exc_info=True)


def _journaliser_fin(tache):
    """Filet pose sur _superviser et chaque _sonde (R18) : sans lui, une tache morte
    sur exception disparaitrait en silence (Task exception was never retrieved)."""
    try:
        if tache.cancelled():
            return
        erreur = tache.exception()
        if erreur is not None:
            code, _nom = code_pour_exception(erreur)
            logging.error(
                "supervision : tache terminee sur exception (%s, %s)",
                code, type(erreur).__name__,
            )
    except asyncio.CancelledError:
        pass
    except Exception as erreur:
        logging.error("supervision._journaliser_fin en erreur : %s", erreur)


async def _superviser(contexte):
    """Passe de reconciliation, PAS une boucle permanente : construit/obtient le
    gestionnaire, reconcilie les sondes, puis se termine. Ne leve jamais."""
    etat = contexte.get("superviseur")
    if etat is None:
        return

    if not etat["sondes"]:
        # Amorcage : ne s'applique qu'au tout premier armement, pas a une reparation
        # ciblee (une sonde deja vivante n'est jamais recreee par _reconcilier).
        await asyncio.sleep(DELAI_AMORCAGE_S)

    for delai in DELAIS_REPRISE_S:
        etat = contexte.get("superviseur")
        if etat is None:
            return
        try:
            gestionnaire = await robots.obtenir_gestionnaire(etat["parametres"], contexte)
            appareils = await gestionnaire.get_devices()
            _reconcilier(contexte, appareils)
            return
        except Exception as erreur:
            code, _nom = code_pour_exception(erreur)
            if code in CODES_ARRET:
                logging.warning("supervision._superviser : arret sans relance (%s)", code)
                return
            logging.error(
                "supervision._superviser : echec de construction/reconciliation (%s, %s), nouvelle tentative dans %s s",
                code, type(erreur).__name__, delai,
            )
            try:
                await asyncio.sleep(delai)
            except Exception:
                return

    logging.error("supervision._superviser : abandon apres epuisement des tentatives de reprise")


def _reconcilier(contexte, appareils):
    """Synchrone, coeur de l'idempotence (AC5). Ne touche JAMAIS une sonde vivante."""
    etat = contexte.get("superviseur")
    if etat is None:
        return
    sondes = etat["sondes"]

    duids_vus = set()
    for appareil in appareils:
        v1 = getattr(appareil, "v1_properties", None)
        if v1 is None:
            continue
        duid = str(getattr(appareil, "duid", "") or "")
        if not _RE_DUID.match(duid):
            logging.warning("supervision._reconcilier : duid ignore (format non conforme)")
            continue
        duids_vus.add(duid)

        existante = sondes.get(duid)
        if existante is not None and not existante["tache"].done():
            continue

        if existante is not None:
            _retirer(sondes, duid)

        evenement = asyncio.Event()
        debrancher = _brancher_push(appareil, evenement)
        tache = asyncio.create_task(_sonde(contexte, appareil, evenement))
        tache.add_done_callback(_journaliser_fin)
        sondes[duid] = {"tache": tache, "evenement": evenement, "debrancher": debrancher}

    for duid in list(sondes.keys()):
        if duid not in duids_vus:
            _retirer(sondes, duid)


def _retirer(sondes, duid):
    """Ne leve jamais."""
    try:
        entree = sondes.pop(duid, None)
        if entree is None:
            return
        debrancher = entree.get("debrancher")
        if debrancher is not None:
            try:
                debrancher()
            except Exception as erreur:
                logging.warning("supervision._retirer : echec de debranchement du push : %s", erreur)
        tache = entree.get("tache")
        if tache is not None and not tache.done():
            tache.cancel()
    except Exception as erreur:
        logging.error("supervision._retirer en erreur : %s", erreur)


def _brancher_push(appareil, evenement):
    """R1 - API de push "experimentale" (devices/device.py). Repli automatique sur la
    sonde periodique seule si l'API a disparu, logging.error UNIQUE."""
    try:
        status = appareil.v1_properties.status
        brancher = getattr(status, "add_update_listener", None)
        if not callable(brancher):
            logging.error("supervision._brancher_push : add_update_listener indisponible, repli sur le sondage seul")
            return None
        return brancher(lambda: evenement.set())
    except Exception as erreur:
        code, _nom = code_pour_exception(erreur)
        logging.error(
            "supervision._brancher_push en erreur (%s, %s), repli sur le sondage seul",
            code, type(erreur).__name__,
        )
        return None


def _en_nettoyage(status):
    return libelles.est_en_nettoyage(status.state)


async def _sonde(contexte, appareil, evenement):
    """Boucle propre a UN robot. Chaque iteration est protegee : une erreur n'arrete
    jamais la sonde ni les autres robots."""
    echecs_consecutifs = 0
    avec_en_ligne = True  # premier lot uniquement (R5 : device_info.online est figee)

    while True:
        try:
            status = appareil.v1_properties.status
            cadence = CADENCE_ECHEC_S if echecs_consecutifs >= SEUIL_ECHECS else (
                CADENCE_NETTOYAGE_S if _en_nettoyage(status) else CADENCE_REPOS_S
            )

            try:
                await asyncio.wait_for(evenement.wait(), cadence)
                reveille_par_push = True
            except asyncio.TimeoutError:
                reveille_par_push = False

            if reveille_par_push:
                evenement.clear()
                await asyncio.sleep(FENETRE_COALESCENCE_S)
                evenement.clear()
                # Le trait est deja a jour (c'est la MAJ qui a declenche le push) :
                # publication SANS RPC supplementaire.
                lot = _lot(appareil, True, "", avec_en_ligne, avec_en_ligne)
                _publier(contexte, appareil.duid, lot)
                avec_en_ligne = False
                echecs_consecutifs = 0
            else:
                try:
                    await asyncio.wait_for(appareil.v1_properties.status.refresh(), DELAI_SONDAGE_S)
                    lot = _lot(appareil, True, "", avec_en_ligne, avec_en_ligne)
                    _publier(contexte, appareil.duid, lot)
                    avec_en_ligne = False
                    echecs_consecutifs = 0
                except Exception as erreur:
                    code, _nom = code_pour_exception(erreur)
                    echecs_consecutifs += 1
                    logging.info("supervision._sonde : sondage en echec duid=%s motif=%s (%s consecutif(s))", _texte(appareil.duid, 16), code, echecs_consecutifs)
                    lot = _lot(appareil, False, code, False, avec_en_ligne)
                    _publier(contexte, appareil.duid, lot)
        except asyncio.CancelledError:
            raise
        except Exception as erreur:
            code, _nom = code_pour_exception(erreur)
            logging.error(
                "supervision._sonde : erreur inattendue dans la boucle (%s, %s)",
                code, type(erreur).__name__,
            )
            try:
                await asyncio.sleep(CADENCE_ECHEC_S)
            except Exception:
                return


def _lot(appareil, etat_lu, motif, avec_capacites, avec_en_ligne):
    """Construit le lot, forme EXACTEMENT alignee sur lireEtat (UC07)."""
    lot = {
        "duid": appareil.duid,
        "connecte": bool(getattr(appareil, "is_connected", False)),
        "etatLu": bool(etat_lu),
        "motifEchec": motif or "",
        "capacites": {},
    }
    if avec_en_ligne:
        lot["enLigne"] = bool(getattr(appareil.device_info, "online", False))
    if etat_lu:
        status = appareil.v1_properties.status
        lot["etat"] = robots.valeurs_etat(status)
        if avec_capacites:
            features = appareil.v1_properties.device_features
            lot["capacites"] = robots.capacites_etat(status, features)
    return lot


def _publier(contexte, duid, lot):
    """Ne leve jamais."""
    try:
        duid = str(duid or "")
        if not _RE_DUID.match(duid):
            logging.warning("supervision._publier : duid ignore (format non conforme)")
            return
        com = contexte.get("com")
        if com is None:
            return
        com.add_changes("robots::" + duid, lot)
    except Exception as erreur:
        # exc_info conserve ici : ne manipule qu'un dict local et l'appel jedom_com,
        # pas une exception venue de la librairie ou du canal RPC.
        logging.error("supervision._publier en erreur : %s", erreur, exc_info=True)
