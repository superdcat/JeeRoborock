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
# Lecture de l'etat courant d'un robot (UC07) : PREMIERE operation qui ouvre reellement
# le canal vers le robot (MQTT/local), jamais utilise jusqu'ici par le plugin.
#
# D-07-1 : le DeviceManager est construit PARESSEUSEMENT (a la premiere lecture) et
# MEMORISE dans contexte["gestionnaire"] : le reconstruire a chaque lecture ferait un
# homedata (quota 5/h, 40/jour) et rouvrirait une session MQTT a chaque clic. Le cache
# passe a la librairie est un InMemoryCache (jamais un FileCache) : le CacheData qu'il
# porte contient le HomeData complet, donc les local_key - les ecrire sur disque
# contredirait l'invariant "les secrets ne quittent pas le demon".
#
# D-07-2 : le gestionnaire memorise est invalide par EMPREINTE de session (sha256 du
# userData recu), sans toucher a authentification.py : le PHP reste la source de verite
# de la session (D-05-8).
#
# obtenir_appareil() est le POINT D'EXTENSION de toute UC qui pilote le robot par le
# canal V1 (UC08, actions) : ces UC doivent l'appeler et ne JAMAIS reconstruire un
# DeviceManager elles-memes (R-14) - une seconde construction doublerait la
# consommation de quota et ouvrirait une seconde session MQTT.
#
# CORRECTION UC09 (D-09-1) : contrairement a ce qu'annoncait R-14, les ROUTINES ne
# passent PAS par ici. Verifie sur la source de python-roborock 7.8.0 : le trait
# device.v1_properties.routines n'est qu'un wrapper de RoborockApiClient.get_scenes /
# execute_scene, donc sans aucun apport, et y acceder imposerait de construire le
# DeviceManager - soit 1 homedata (quota dur) + 1 session MQTT, ce qui casserait
# l'independance au canal robot exigee par UC09. Voir routines.py : chemin HTTPS pur,
# strictement disjoint de ce module.
#
# UC08 (commandes de pilotage de base) ajoute envoyer_commande() : liste blanche
# FERMEE de 5 actions (ACTIONS), reclassement des erreurs d'envoi (_erreur_envoi) et
# relecture best-effort post-action. _VERROU_GESTIONNAIRE protege desormais la
# CONSTRUCTION du gestionnaire (double-checked locking) : UC08 multiplie les points
# d'appel concurrents (6 boutons au dashboard) par rapport au bouton admin unique
# d'UC07, rendant une double construction (2 homedata + 2 sessions MQTT) nettement
# plus probable - dette latente d'UC07, corrigee ici de facon retroactive (profite
# aussi a lire_etat).

import asyncio
import hashlib
import logging
import time

from roborock import RoborockCommand, StatusField, StatusV2
from roborock.devices.cache import InMemoryCache
from roborock.devices.device_manager import UserParams, create_device_manager

import canal
import libelles
import session
from erreurs import ErreurDemon, code_pour_exception
from textes import texte as _texte

DELAI_CONSTRUCTION_S = 15
DELAI_ATTENTE_CONNEXION_S = 3
DELAI_LECTURE_S = 12
PAS_ATTENTE_S = 0.25

# Budget de envoyer_commande (echeance globale : demon <= 30 s < canal 34 s < PHP 35 s,
# cf. jeeroborockDaemon::TIMEOUT_ACTION).
DELAI_TOTAL_ACTION_S = 30
DELAI_ENVOI_MAX_S = 22          # plafond ; la borne reelle est ce qui reste de l'echeance
DELAI_RELECTURE_S = 8
PAUSE_AVANT_RELECTURE_S = 2.0
RESTE_MINIMAL_RELECTURE_S = 4

# Liste blanche FERMEE : le PHP ne peut JAMAIS faire emettre une RPC arbitraire au
# robot, seulement une des 5 clefs ci-dessous.
ACTIONS = {
    "demarrer": RoborockCommand.APP_START,
    "pause": RoborockCommand.APP_PAUSE,
    "arreter": RoborockCommand.APP_STOP,
    "retour_base": RoborockCommand.APP_CHARGE,
    "localiser": RoborockCommand.FIND_ME,
}

# Protege la CONSTRUCTION du gestionnaire (pas le chemin rapide memorise) : deux
# appels concurrents sur un contexte["gestionnaire"] vide construiraient deux
# DeviceManager = deux homedata (quota dur 5/h, 40/jour, partage avec l'application
# mobile de l'utilisateur) et deux sessions MQTT sur le meme compte.
_VERROU_GESTIONNAIRE = asyncio.Lock()

# Codes stables consideres comme "le robot n'a pas repondu" pendant la lecture de
# l'etat (§ Budget de temps) : etatLu passe a False mais l'operation reste un SUCCES
# (les indicateurs de connexion doivent toujours etre renvoyes, AC6). Toute autre
# exception (AUTH_EXPIRED, RATE_LIMIT, PARSING_ERROR...) n'est PAS avalee : elle
# remonte telle quelle jusqu'a handler_rpc.
_MOTIFS_LECTURE_ECHOUEE = frozenset({
    "ROBOROCK_ERROR",
    "ROBOROCK_TIMEOUT",
    "CONNECTION_FAILED",
    "RETRY_EXHAUSTED",
    "DEVICE_BUSY",
})

def _empreinte(user_data_brut):
    return hashlib.sha256(user_data_brut.encode("utf-8")).hexdigest()


async def _construire(user_data, base_url, email):
    """Construit un nouveau DeviceManager : 1 homedata (quota 5/h, 40/jour) + ouverture
    de la session MQTT paresseuse. Borne a DELAI_CONSTRUCTION_S (§ Budget de temps)."""
    parametres = UserParams(username=email, user_data=user_data, base_url=(base_url or None))
    try:
        return await asyncio.wait_for(
            create_device_manager(parametres, cache=InMemoryCache(), prefer_cache=True),
            DELAI_CONSTRUCTION_S,
        )
    except asyncio.TimeoutError:
        raise ErreurDemon("OPERATION_TIMEOUT") from None


async def _gestionnaire(parametres, contexte):
    """Retourne le DeviceManager memorise dans contexte["gestionnaire"], en le
    (re)construisant si absent ou si l'empreinte de session a change (D-07-2).

    UC08 : la CONSTRUCTION est protegee par _VERROU_GESTIONNAIRE (double-checked
    locking) - le chemin rapide (gestionnaire deja memorise, empreinte identique)
    reste HORS verrou. Une seule boucle asyncio, le verrou n'encapsule aucun await
    qui rebouclerait dessus : pas de risque d'interblocage."""
    user_data_brut = parametres.get("userData") or ""
    empreinte = _empreinte(user_data_brut)

    memorise = contexte.get("gestionnaire")
    if memorise is not None and memorise.get("empreinte") == empreinte:
        return memorise["objet"]

    async with _VERROU_GESTIONNAIRE:
        # Double verification : un autre appel concurrent a pu construire pendant
        # l'attente du verrou.
        memorise = contexte.get("gestionnaire")
        if memorise is not None and memorise.get("empreinte") == empreinte:
            return memorise["objet"]

        if memorise is not None:
            contexte["gestionnaire"] = None
            try:
                await memorise["objet"].close()
            except Exception as erreur:
                logging.warning("Fermeture de l'ancien gestionnaire en erreur : %s", erreur)

        user_data = session.decoder_user_data(user_data_brut)
        base_url = parametres.get("baseUrl") or ""
        email = parametres.get("email") or ""

        objet = await _construire(user_data, base_url, email)
        contexte["gestionnaire"] = {"objet": objet, "empreinte": empreinte}
        return objet


async def obtenir_appareil(parametres, contexte):
    """POINT D'EXTENSION UC08/UC09 (R-14). Decode/valide la session, obtient ou
    construit le gestionnaire memorise, resout le duid. Leve ErreurDemon typee sur
    chaque defense en profondeur ; ne construit JAMAIS un DeviceManager elle-meme
    (delegue integralement a _gestionnaire)."""
    if not session.IMPORT_OK:
        raise ErreurDemon("INTERNAL_ERROR")

    user_data_brut = parametres.get("userData") or ""
    if user_data_brut == "":
        raise ErreurDemon("NOT_AUTHENTICATED")

    duid = str(parametres.get("duid") or "").strip()
    if duid == "":
        raise ErreurDemon("DEVICE_UNKNOWN")

    # Validation du format AVANT tout acces au gestionnaire (AUTH_EXPIRED sur un blob
    # illisible) : _gestionnaire() redecode le blob de son cote s'il doit (re)construire
    # - double decodage assume, cout negligeable (base64 + JSON), cf. spec technique.
    session.decoder_user_data(user_data_brut)

    gestionnaire = await _gestionnaire(parametres, contexte)

    appareil = await gestionnaire.get_device(duid)
    if appareil is None:
        raise ErreurDemon("DEVICE_UNKNOWN")
    return appareil


async def obtenir_gestionnaire(parametres, contexte):
    """Wrapper public de _gestionnaire() (UC10) : robots.py reste le SEUL module qui
    construit un DeviceManager, si bien que _VERROU_GESTIONNAIRE protege aussi le
    superviseur (supervision.py). Ne resout AUCUN duid, contrairement a
    obtenir_appareil()."""
    return await _gestionnaire(parametres, contexte)


async def attendre_connexion(appareil):
    """Attend jusqu'a DELAI_ATTENTE_CONNEXION_S que le canal V1 signale une connexion
    etablie. EXTRAITE de lire_etat (UC07), comportement strictement identique - partagee
    avec envoyer_commande (UC08) et supervision.py (UC10). Retourne l'etat final de
    appareil.is_connected."""
    attente = 0.0
    while not appareil.is_connected and attente < DELAI_ATTENTE_CONNEXION_S:
        await asyncio.sleep(PAS_ATTENTE_S)
        attente += PAS_ATTENTE_S
    return appareil.is_connected


async def fermer_gestionnaire(contexte):
    """Ferme le gestionnaire memorise (arret du demon). Idempotent, NE LEVE JAMAIS."""
    memorise = contexte.get("gestionnaire")
    if memorise is None:
        return
    contexte["gestionnaire"] = None
    try:
        await memorise["objet"].close()
    except Exception as erreur:
        logging.warning("Fermeture du gestionnaire en erreur (arret du demon) : %s", erreur)


def capacites_etat(status, features):
    """7 booleens - DEUX FAMILLES, VOLONTAIREMENT NON FACTORISEES (cf. spec § Detection
    de capacites). NE PAS unifier : device_features.py (l.98-99) renvoie True par
    defaut quand un champ n'a AUCUNE metadonnee, donc "X or is_field_supported(...)"
    vaudrait True en permanence pour clean_area/clean_time."""
    # Famille 1 (metadonnee dps/feature) : is_field_supported(...) OR valeur presente -
    # garde-fou contre un faux negatif quand product.supported_schema_ids vaut set()
    # (HomeDataProduct.schema absent).
    etat = features.is_field_supported(StatusV2, StatusField.STATE) or (status.state is not None)
    batterie = features.is_field_supported(StatusV2, StatusField.BATTERY) or (status.battery is not None)
    erreur = features.is_field_supported(StatusV2, StatusField.ERROR_CODE) or (status.error_code is not None)
    avancement = features.is_field_supported(StatusV2, StatusField.CLEAN_PERCENT) or (status.clean_percent is not None)

    # Famille 2 (AUCUNE metadonnee) : valeur is not None, SANS appeler
    # is_field_supported (il renverrait True).
    surface_nettoyee = status.clean_area is not None
    duree_nettoyage = status.clean_time is not None

    return {
        "etat": etat,
        "batterie": batterie,
        "enNettoyage": etat,
        "erreur": erreur,
        "surfaceNettoyee": surface_nettoyee,
        "dureeNettoyage": duree_nettoyage,
        "avancement": avancement,
    }


def valeurs_etat(status):
    """Cles ABSENTES quand la valeur correspondante est None : jamais de 0/'' par
    defaut (AC4). Conversions faites ICI, jamais en PHP (clean_area est en mm2, unite
    m2 via la PROPRIETE de la librairie square_meter_clean_area ; clean_time est en
    secondes, converti en minutes entieres)."""
    valeurs = {}
    if status.state is not None:
        valeurs["etatCode"] = int(status.state)
        valeurs["etatLibelle"] = libelles.libelle_etat(status.state)
        valeurs["enNettoyage"] = bool(libelles.est_en_nettoyage(status.state))
    if status.battery is not None:
        valeurs["batterie"] = int(status.battery)
    if status.error_code is not None:
        valeurs["erreurCode"] = int(status.error_code)
        valeurs["erreurLibelle"] = libelles.libelle_erreur(status.error_code)
    if status.clean_area is not None:
        valeurs["surfaceNettoyeeM2"] = float(status.square_meter_clean_area)
    if status.clean_time is not None:
        valeurs["dureeNettoyageMin"] = int(status.clean_time // 60)
    if status.clean_percent is not None:
        valeurs["avancement"] = int(status.clean_percent)
    return valeurs


async def lire_etat(parametres, contexte):
    duid_brut = str(parametres.get("duid") or "").strip()

    appareil = await obtenir_appareil(parametres, contexte)
    duid = appareil.duid

    en_ligne = appareil.device_info.online

    await attendre_connexion(appareil)

    if not appareil.is_connected:
        return {
            "duid": duid,
            "enLigne": en_ligne,
            "connecte": False,
            "etatLu": False,
            "motifEchec": "DEVICE_OFFLINE",
            "capacites": {},
        }

    try:
        await asyncio.wait_for(appareil.v1_properties.status.refresh(), DELAI_LECTURE_S)
    except asyncio.TimeoutError:
        logging.info("lireEtat : lecture hors delai duid=%s", _texte(duid_brut, 16))
        return {
            "duid": duid,
            "enLigne": en_ligne,
            "connecte": appareil.is_connected,
            "etatLu": False,
            "motifEchec": "ROBOROCK_TIMEOUT",
            "capacites": {},
        }
    except Exception as erreur:
        code, _nom_classe = code_pour_exception(erreur)
        if code not in _MOTIFS_LECTURE_ECHOUEE:
            # AUTH_EXPIRED, RATE_LIMIT, PARSING_ERROR... : ne doivent pas etre avales.
            raise
        logging.info("lireEtat : lecture en echec duid=%s motif=%s", _texte(duid_brut, 16), code)
        return {
            "duid": duid,
            "enLigne": en_ligne,
            "connecte": appareil.is_connected,
            "etatLu": False,
            "motifEchec": code,
            "capacites": {},
        }

    status = appareil.v1_properties.status
    features = appareil.v1_properties.device_features
    capacites = capacites_etat(status, features)
    valeurs = valeurs_etat(status)
    # Relu APRES la RPC : c'est cette valeur (pas celle d'avant la tentative de
    # connexion) qui porte AC6.
    connecte = appareil.is_connected

    # contexte["session"] n'est PAS reecrit ici : cette operation ne reamorce pas la
    # session (aucune 5e occurrence du dict inline, la dette d'UC06 n'est pas aggravee).

    return {
        "duid": duid,
        "enLigne": en_ligne,
        "connecte": connecte,
        "etatLu": True,
        "motifEchec": "",
        "capacites": capacites,
        "etat": valeurs,
    }


def _erreur_envoi(erreur):
    """Classe une exception levee par command.send() en ErreurDemon typee. ORDRE
    IMPOSE, chaque test est source (cf. spec technique UC08 § Classement des erreurs
    d'envoi) :

    1. TimeoutError (notre wait_for, ou __cause__ pose par la librairie) -> DEVICE_OFFLINE.
       SANS ce test, code_pour_exception() suivrait __cause__ jusqu'a TimeoutError, dont
       le MRO contient OSError (Python >= 3.11) -> CLOUD_UNREACHABLE, un message FAUX
       ("verifiez l'acces a Internet de Jeedom") alors que c'est le ROBOT qui ne repond
       pas.
    2. erreur.args[0] est un dict portant un "code" entier -> DEVICE_ACTION_REFUSED
       (AC8). Detection DE FORME, jamais d'un message anglais : _create_api_error
       (protocols/v1_protocol.py) est le seul endroit de la librairie qui construit une
       RoborockException a partir d'un dict.
    3. Sinon code_pour_exception(erreur), qui preserve RoborockInvalidStatus ->
       DEVICE_ACTION_REFUSED (deja dans TABLE_CODES), AUTH_EXPIRED, RATE_LIMIT,
       UNSUPPORTED... Aucune exception n'est avalee.

    Approximation assumee : le test 1 amalgame silence reel du robot et notre propre
    wait_for qui a coupe court faute de budget - les deux se presentent a l'utilisateur
    comme "robot hors ligne", ce qu'AC7 attend."""
    if isinstance(erreur, TimeoutError) or isinstance(erreur.__cause__, TimeoutError):
        return ErreurDemon("DEVICE_OFFLINE")

    args = getattr(erreur, "args", None)
    if args and isinstance(args[0], dict) and isinstance(args[0].get("code"), int):
        logging.info("envoyerCommande : refus du robot code=%s", args[0].get("code"))
        return ErreurDemon("DEVICE_ACTION_REFUSED")

    code, _nom_classe = code_pour_exception(erreur)
    return ErreurDemon(code)


async def envoyer_commande(parametres, contexte):
    """Transmet une des 5 actions de la liste blanche ACTIONS au robot par le canal V1
    deja ouvert par obtenir_appareil() (UC07), puis tente une relecture best-effort de
    l'etat (§ Rafraichissement post-action) pour que le dashboard reflete l'effet de
    l'action sans second aller-retour (ni push - UC10 -, ni cron - D-07-9)."""
    echeance = time.monotonic() + DELAI_TOTAL_ACTION_S

    action = str(parametres.get("action") or "")
    commande = ACTIONS.get(action)
    if commande is None:
        logging.error("envoyerCommande : action hors liste blanche : %s", _texte(action, 32))
        raise ErreurDemon("INTERNAL_ERROR")

    appareil = await obtenir_appareil(parametres, contexte)
    duid = appareil.duid

    connecte = await attendre_connexion(appareil)
    if not connecte:
        # Chemin rapide (AC7) : aucune RPC emise sur un canal que is_connected signale
        # deja comme non etabli.
        raise ErreurDemon("DEVICE_OFFLINE")

    budget = min(DELAI_ENVOI_MAX_S, echeance - time.monotonic())
    if budget < 3:
        raise ErreurDemon("OPERATION_TIMEOUT")

    try:
        resultat = await asyncio.wait_for(appareil.v1_properties.command.send(commande), budget)
    except Exception as erreur:
        raise _erreur_envoi(erreur) from erreur

    # Le payload decode (typiquement ["ok"]) n'est PAS renvoye au PHP (donnee externe
    # inutile cote Jeedom) : journalise en debug, et en info s'il vaut autre chose que
    # "ok"/["ok"] (signal de recette).
    if resultat in ("ok", ["ok"]):
        logging.debug("envoyerCommande : action=%s duid=%s transmise, reponse=%s", action, _texte(duid, 16), _texte(resultat, 128))
    else:
        logging.info("envoyerCommande : action=%s duid=%s transmise, reponse inattendue=%s", action, _texte(duid, 16), _texte(resultat, 128))

    etat_lu = False
    motif_echec = ""
    capacites = {}
    etat = {}

    restant = echeance - time.monotonic()
    if restant < RESTE_MINIMAL_RELECTURE_S:
        logging.info("envoyerCommande : relecture sautee (budget insuffisant) action=%s duid=%s", action, _texte(duid, 16))
    else:
        await asyncio.sleep(min(PAUSE_AVANT_RELECTURE_S, restant / 4))
        restant = echeance - time.monotonic()
        try:
            await asyncio.wait_for(appareil.v1_properties.status.refresh(), min(DELAI_RELECTURE_S, restant))
            status = appareil.v1_properties.status
            features = appareil.v1_properties.device_features
            capacites = capacites_etat(status, features)
            etat = valeurs_etat(status)
            etat_lu = True
        except Exception as erreur:
            # Relecture BEST-EFFORT : ne relever JAMAIS (l'action a reussi, la transformer
            # en erreur serait un faux negatif).
            code, _nom_classe = code_pour_exception(erreur)
            logging.info("envoyerCommande : relecture post-action en echec action=%s duid=%s motif=%s", action, _texte(duid, 16), code)

    # motifEchec reste vide par construction : structurellement aligne sur le payload
    # de lireEtat, mais ici l'action a deja reussi (sinon on serait sorti via
    # _erreur_envoi ci-dessus) - executerAction() ignore ce champ.
    return {
        "duid": duid,
        "action": action,
        "enLigne": appareil.device_info.online,
        "connecte": appareil.is_connected,
        "etatLu": etat_lu,
        "motifEchec": motif_echec,
        "capacites": capacites,
        "etat": etat,
    }


def enregistrer_operations():
    canal.enregistrer("lireEtat", lire_etat)
    canal.enregistrer("envoyerCommande", envoyer_commande)
