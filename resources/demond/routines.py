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
# Routines ("usages") du compte Roborock (UC09) : synchronisation et execution.
#
# Chemin STRICTEMENT DISJOINT du DeviceManager, de MQTT et du quota homedata (AC7,
# D-09-1) : ce module N'IMPORTE PAS robots.py et ne touche jamais a
# contexte["gestionnaire"]. Une routine s'execute par un unique POST HTTPS signe
# Hawk, relaye par le cloud - c'est ce qui la rend utilisable meme quand le canal
# MQTT du robot est indisponible.
#
# get_scenes / execute_scene (RoborockApiClient, web_api.py) sont les seuls
# endroits de la librairie qui construisent une RoborockException a partir de la
# reponse HTTP brute (dict) : _erreur_cloud() classe sur cette FORME, jamais sur un
# message anglais. Ne pas reutiliser robots._erreur_envoi() (classifieur RPC V1,
# semantique differente).

import asyncio
import logging

import session
from erreurs import ErreurDemon, code_pour_exception
from textes import texte as _texte

import canal

DELAI_LISTE_S = 15
DELAI_EXECUTION_S = 15
LIMITE_ROUTINES = 64


def _erreur_cloud(erreur, code_si_refus):
    """Classe une exception levee par get_scenes/execute_scene. Detection DE FORME
    (args[0] est un dict), jamais d'un message anglais : ce sont les deux seuls
    endroits de la librairie qui construisent une RoborockException a partir de la
    reponse HTTP brute (web_api.py l. 603-604, 692-693). Le code numerique est
    journalise : seule source qui rendra concluant le point de recette R-10."""
    args = getattr(erreur, "args", None)
    if args and isinstance(args[0], dict):
        logging.info("routines : refus du cloud code=%s", args[0].get("code"))
        return ErreurDemon(code_si_refus)

    code, _nom_classe = code_pour_exception(erreur)
    return ErreurDemon(code)


async def lister(parametres, contexte):
    if not session.IMPORT_OK:
        raise ErreurDemon("INTERNAL_ERROR")

    user_data_brut = parametres.get("userData") or ""
    if user_data_brut == "":
        raise ErreurDemon("NOT_AUTHENTICATED")

    duid = str(parametres.get("duid") or "").strip()
    if duid == "":
        raise ErreurDemon("DEVICE_UNKNOWN")

    user_data = session.decoder_user_data(user_data_brut)
    base_url = parametres.get("baseUrl") or ""
    email = parametres.get("email") or ""
    client = session.creer_client(email, base_url)

    try:
        scenes = await asyncio.wait_for(client.get_scenes(user_data, duid), DELAI_LISTE_S)
    except asyncio.TimeoutError:
        raise ErreurDemon("OPERATION_TIMEOUT") from None
    except Exception as erreur:
        raise _erreur_cloud(erreur, "ROBOROCK_ERROR") from erreur

    routines = []
    nb_total = len(scenes)
    omises = 0
    for scene in scenes:
        if len(routines) >= LIMITE_ROUTINES:
            omises += 1
            continue
        try:
            identifiant = int(scene.id)
        except (TypeError, ValueError):
            logging.warning("listerRoutines : identifiant de scene ignore (non entier)")
            continue
        if identifiant <= 0:
            logging.warning("listerRoutines : identifiant de scene ignore (<= 0)")
            continue
        nom = _texte(scene.name)
        routines.append({"id": identifiant, "nom": nom})

    if omises:
        logging.warning("listerRoutines : liste tronquee a %s (%s omise(s))", LIMITE_ROUTINES, omises)

    return {"duid": _texte(duid, 128), "nbTotal": nb_total, "routines": routines}


async def executer(parametres, contexte):
    if not session.IMPORT_OK:
        raise ErreurDemon("INTERNAL_ERROR")

    user_data_brut = parametres.get("userData") or ""
    if user_data_brut == "":
        raise ErreurDemon("NOT_AUTHENTICATED")

    duid = str(parametres.get("duid") or "").strip()
    if duid == "":
        raise ErreurDemon("DEVICE_UNKNOWN")

    user_data = session.decoder_user_data(user_data_brut)
    base_url = parametres.get("baseUrl") or ""
    email = parametres.get("email") or ""
    client = session.creer_client(email, base_url)

    try:
        scene_id = int(parametres.get("sceneId"))
    except (TypeError, ValueError):
        scene_id = None
    if scene_id is None or scene_id <= 0:
        # Desynchronisation PHP/demon (defense en profondeur) : le PHP a deja valide
        # le format du logicalId avant d'appeler cette operation.
        logging.error("executerRoutine : sceneId invalide recu du plugin")
        raise ErreurDemon("INTERNAL_ERROR")

    try:
        await asyncio.wait_for(client.execute_scene(user_data, scene_id), DELAI_EXECUTION_S)
    except asyncio.TimeoutError:
        raise ErreurDemon("OPERATION_TIMEOUT") from None
    except Exception as erreur:
        raise _erreur_cloud(erreur, "ROUTINE_INTROUVABLE") from erreur

    logging.info("executerRoutine : usage %s lance pour duid=%s", scene_id, _texte(duid, 16))

    return {"duid": _texte(duid, 128), "sceneId": scene_id}


def enregistrer_operations():
    canal.enregistrer("listerRoutines", lister)
    canal.enregistrer("executerRoutine", executer)
