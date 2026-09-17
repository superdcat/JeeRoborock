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
# Canal HTTP local PHP <-> demon du plugin JeeRoborock (UC03).
#
# Porte le routage des operations (REGISTRE), les middlewares (apikey, mise en forme des
# erreurs) et les deux handlers HTTP (GET /sante, POST /rpc). handler_sante,
# verifier_apikey et construire_application sont DEPLACES depuis jeeroborockd.py (D-g de
# la spec technique UC03) : jeeroborockd.py va accueillir tout le metier d'UC04->09 et
# depassait deja 224 lignes. Le contrat de GET /sante reste strictement identique
# (non-regression UC02, cf. point de recette R-8).
#
# ATTENTION (a porter dans les specs techniques UC04->09) : les coroutines du REGISTRE
# tournent dans la boucle asyncio UNIQUE du demon. Tout code bloquant (I/O synchrone, CPU)
# gele TOUT le canal, /sante compris, et se presentera a l'utilisateur comme "le demon ne
# repond pas" alors qu'il est vivant. Tout code bloquant doit passer par asyncio.to_thread.

import asyncio
import hmac
import logging
import os
import time

from aiohttp import web

from erreurs import CODE_DEFAUT, ErreurDemon, code_pour_exception

BUDGET_DEFAUT_MS = 10000
BUDGET_MAX_MS = 60000
BUDGET_MIN_MS = 1000
MARGE_MS = 1000
TAILLE_MAX_CORPS = 256 * 1024

# nom d'operation -> coroutine(parametres, contexte) -> dict | None
REGISTRE = {}


def enregistrer(nom, fonction):
    """Point d'extension consomme par UC04+ : REGISTRE['authentifier'] = ma_coroutine."""
    REGISTRE[nom] = fonction


def reponse_succes(data):
    return web.json_response({"success": True, "data": data})


def reponse_erreur(code, message, statut=200, detail=None):
    """Point de sortie unique du canal pour une erreur : la contrainte "error.detail ne
    contient que des valeurs numeriques ou booleennes" (cf. AC6) y est appliquee, pas
    seulement documentee, pour couvrir aussi un detail construit ailleurs (ErreurDemon)."""
    erreur = {"code": code, "message": message}
    detail_filtre = _filtrer_detail(detail)
    if detail_filtre is not None:
        erreur["detail"] = detail_filtre
    return web.json_response({"success": False, "error": erreur}, status=statut)


def _filtrer_detail(detail):
    """Ne conserve que les entrees dont la valeur est int, float ou bool (jamais None) ;
    toute autre valeur est ecartee (pas remplacee par une chaine). Les cles ecartees sont
    journalisees en warning (jamais leur valeur, qui peut porter un secret)."""
    if not isinstance(detail, dict):
        return None

    filtre = {}
    cles_ecartees = []
    for cle, valeur in detail.items():
        if isinstance(valeur, (bool, int, float)):
            filtre[cle] = valeur
        else:
            cles_ecartees.append(cle)

    if cles_ecartees:
        logging.warning("Cles de detail ecartees (valeur non scalaire) : %s", ",".join(sorted(cles_ecartees)))

    if not filtre:
        return None
    return filtre


@web.middleware
async def verifier_apikey(requete, gestionnaire):
    apikey_recue = requete.headers.get("X-Apikey", "")
    if not hmac.compare_digest(apikey_recue, requete.app["apikey"]):
        return web.json_response(
            {"success": False, "error": {"code": "UNAUTHORIZED", "message": "Apikey invalide"}},
            status=401,
        )
    return await gestionnaire(requete)


@web.middleware
async def normaliser_erreurs(requete, gestionnaire):
    """Traduit les HTTPException levees par aiohttp lui-meme (ex. 413 sur
    client_max_size) dans l'enveloppe {success, error} du canal : le PHP n'a alors
    jamais a distinguer une erreur applicative d'une erreur de transport aiohttp."""
    try:
        return await gestionnaire(requete)
    except web.HTTPException as exception:
        if exception.status == 413:
            return reponse_erreur("BAD_REQUEST", "Corps de requete trop volumineux", statut=400)
        return reponse_erreur(CODE_DEFAUT, "Erreur HTTP %s" % exception.status, statut=exception.status)


async def handler_sante(requete):
    contexte = requete.app["contexte"]
    duree = int(time.time() - contexte["demarrage"])
    return web.json_response({
        "success": True,
        "data": {
            "version": contexte["version"],
            "majeureValidee": contexte["majeureValidee"],
            "avertissementVersion": contexte["avertissement"],
            "callback": contexte["callback_ok"],
            "pid": os.getpid(),
            "dureeFonctionnement": duree,
        },
    })


async def handler_rpc(requete):
    try:
        corps = await requete.json()
    except web.HTTPException:
        # Notamment HTTPRequestEntityTooLarge (413, client_max_size) : laissee remonter
        # pour etre mise en forme par le middleware normaliser_erreurs.
        raise
    except Exception:
        return reponse_erreur("BAD_REQUEST", "Corps JSON invalide", statut=400)

    if not isinstance(corps, dict):
        return reponse_erreur("BAD_REQUEST", "Corps JSON invalide", statut=400)

    operation = corps.get("operation")
    if not isinstance(operation, str) or operation == "":
        return reponse_erreur("BAD_REQUEST", "Champ operation absent ou invalide", statut=400)

    fonction = REGISTRE.get(operation)
    if fonction is None:
        return reponse_erreur(
            "UNKNOWN_OPERATION",
            "Operation inconnue",
            statut=404,
            detail={"operation": operation},
        )

    parametres = corps.get("parametres", {})
    if parametres is None:
        parametres = {}
    if not isinstance(parametres, dict):
        return reponse_erreur("BAD_REQUEST", "Champ parametres invalide", statut=400)

    budget = corps.get("budgetMs", BUDGET_DEFAUT_MS)
    try:
        budget = int(budget)
    except (TypeError, ValueError):
        budget = BUDGET_DEFAUT_MS
    budget = max(BUDGET_MIN_MS, min(BUDGET_MAX_MS, budget))

    # Journalisation defensive : uniquement les NOMS de cles, jamais les valeurs
    # (AC6 - une liste noire de cles sensibles laisserait passer toute cle nouvelle).
    logging.debug("Operation %s parametres=%s", operation, ",".join(sorted(parametres.keys())))

    try:
        data = await asyncio.wait_for(
            fonction(parametres, requete.app["contexte"]),
            (budget - MARGE_MS) / 1000,
        )
        return reponse_succes(data)
    except ErreurDemon as erreur:
        logging.error("Operation %s en erreur [%s]", operation, erreur.code, exc_info=True)
        return reponse_erreur(erreur.code, erreur.code, detail=erreur.detail)
    except asyncio.TimeoutError:
        logging.error("Operation %s en erreur [OPERATION_TIMEOUT]", operation, exc_info=True)
        return reponse_erreur("OPERATION_TIMEOUT", "OPERATION_TIMEOUT")
    except Exception as erreur:
        code, nom_classe = code_pour_exception(erreur)
        logging.error("Operation %s en erreur [%s]", operation, code, exc_info=True)
        return reponse_erreur(code, nom_classe)


def construire_application(apikey, contexte):
    application = web.Application(
        middlewares=[normaliser_erreurs, verifier_apikey],
        client_max_size=TAILLE_MAX_CORPS,
    )
    application["apikey"] = apikey
    application["contexte"] = contexte
    application.router.add_get("/sante", handler_sante)
    application.router.add_post("/rpc", handler_rpc)
    return application
