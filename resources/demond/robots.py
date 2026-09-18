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
# obtenir_appareil() est le POINT D'EXTENSION d'UC08 (actions) et d'UC09 (routines,
# via device.v1_properties.routines) : ces UC doivent l'appeler et ne JAMAIS
# reconstruire un DeviceManager elles-memes (R-14) - une seconde construction
# doublerait la consommation de quota et ouvrirait une seconde session MQTT.

import asyncio
import hashlib
import logging
import re

from roborock import StatusField, StatusV2
from roborock.devices.cache import InMemoryCache
from roborock.devices.device_manager import UserParams, create_device_manager

import canal
import libelles
import session
from erreurs import ErreurDemon, code_pour_exception

DELAI_CONSTRUCTION_S = 15
DELAI_ATTENTE_CONNEXION_S = 3
DELAI_LECTURE_S = 12
PAS_ATTENTE_S = 0.25

LONGUEUR_MAX_TEXTE = 128

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

_CARACTERES_CONTROLE = re.compile(r"[\x00-\x1f\x7f]")


def _texte(valeur, longueur_max=LONGUEUR_MAX_TEXTE):
    """'' si None ; str() ; neutralisation des caracteres de controle AVANT troncature,
    pour empecher une injection de fausse ligne dans les logging.* de ce fichier.
    PRIVE A CE MODULE : equipements.py porte sa PROPRE copie (duplication consciente,
    cf. R-15 de la spec technique - ne rien importer d'un symbole prive d'un autre
    module de domaine)."""
    if valeur is None:
        return ""
    return _CARACTERES_CONTROLE.sub("", str(valeur))[:longueur_max]


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
    (re)construisant si absent ou si l'empreinte de session a change (D-07-2)."""
    user_data_brut = parametres.get("userData") or ""
    empreinte = _empreinte(user_data_brut)

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


def _capacites(status, features):
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


def _valeurs(status):
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

    attente = 0.0
    while not appareil.is_connected and attente < DELAI_ATTENTE_CONNEXION_S:
        await asyncio.sleep(PAS_ATTENTE_S)
        attente += PAS_ATTENTE_S

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
    capacites = _capacites(status, features)
    valeurs = _valeurs(status)
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


def enregistrer_operations():
    canal.enregistrer("lireEtat", lire_etat)
