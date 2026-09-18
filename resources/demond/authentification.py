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
# Authentification au cloud Roborock par code recu par e-mail (UC04).
#
# Trois operations exposees au canal : demander_code, valider_code, restaurer_session.
# Les trois coroutines sont 100% async (aiohttp + limiteur async) : aucun
# asyncio.to_thread n'est necessaire ici (R1 d'UC03 sans objet pour ce module).
#
# L'etat d'authentification en cours vit dans contexte['auth'] (forme
# {'client': RoborockApiClient, 'email': str}), sans TTL : sa duree de vie est celle du
# processus demon (D-04-2 de la spec technique). contexte['session'] porte la session
# APRES succes ({'userData': str, 'baseUrl': str, 'email': str}).
#
# UC05 ajoute etat_compte : sonde legere de l'etat du compte (session valide ? nombre de
# robots ?), pilotee par le PHP via le parametre avecInventaire (D-05-2/D-05-8). Le PHP
# pousse toujours la session en parametre (jamais de lecture de contexte['session']) :
# l'operation est idempotente et immune a un redemarrage du demon.
#
# UC06 : la serialisation du UserData (encoder_user_data/decoder_user_data), la creation
# de client (creer_client) et l'import garde de la librairie (IMPORT_OK) sont deplaces
# dans le module de session partage session.py (dette explicite d'UC05). Ce fichier ne
# porte plus que le flux d'authentification lui-meme.

import logging
import re

from erreurs import ErreurDemon, code_pour_exception
from session import IMPORT_OK, RoborockApiClient, creer_client, decoder_user_data, encoder_user_data

import canal

_RE_EMAIL_INTERDITS = re.compile(r"[\s\x00-\x1f\x7f]")
_RE_CODE = re.compile(r"\A[A-Za-z0-9]{4,12}\Z")
_LONGUEUR_MAX_EMAIL = 254


def _email_valide(valeur):
    """Defense en profondeur : le demon ne fait pas confiance au PHP. Le formulaire de
    configuration a deja valide l'e-mail via FILTER_VALIDATE_EMAIL cote PHP."""
    if not isinstance(valeur, str):
        return False
    if valeur == "" or len(valeur) > _LONGUEUR_MAX_EMAIL:
        return False
    if "@" not in valeur:
        return False
    if _RE_EMAIL_INTERDITS.search(valeur):
        return False
    return True


def _client_pour(email, contexte):
    """Reutilise l'instance RoborockApiClient si l'e-mail est identique a la demande en
    cours (stabilise header_clientid entre deux demandes successives, D-04-2) ; en cree
    une nouvelle sinon."""
    auth = contexte.get("auth")
    if auth is not None and auth.get("email") == email:
        return auth["client"]
    return RoborockApiClient(email)


async def demander_code(parametres, contexte):
    if not IMPORT_OK:
        raise ErreurDemon("INTERNAL_ERROR")

    email = parametres.get("email")
    if not _email_valide(email):
        raise ErreurDemon("AUTH_EMAIL_INVALID")

    client = _client_pour(email, contexte)
    # Stocke AVANT le await : un OPERATION_TIMEOUT ou une erreur ne doit pas perdre
    # l'instance alors que l'e-mail a peut-etre deja ete envoye (D-04-2/D-04-3).
    contexte["auth"] = {"client": client, "email": email}

    await client.request_code_v4()

    return {"envoye": True}


async def valider_code(parametres, contexte):
    if not IMPORT_OK:
        raise ErreurDemon("INTERNAL_ERROR")

    email = parametres.get("email")
    code = parametres.get("code")
    if not isinstance(code, str) or not _RE_CODE.match(code):
        raise ErreurDemon("AUTH_CODE_INVALID")

    auth = contexte.get("auth")
    if auth is None or auth.get("email") != email:
        raise ErreurDemon("AUTH_NO_PENDING_CODE")

    client = auth["client"]
    user_data = await client.code_login_v4(code)
    base_url = await client.base_url

    contexte["session"] = {
        "userData": encoder_user_data(user_data),
        "baseUrl": str(base_url),
        "email": email,
    }
    # Remis a None UNIQUEMENT en cas de succes : sur echec, l'utilisateur doit pouvoir
    # corriger une faute de frappe dans le code sans redemander un nouveau code.
    contexte["auth"] = None

    return {"userData": contexte["session"]["userData"], "baseUrl": contexte["session"]["baseUrl"]}


async def restaurer_session(parametres, contexte):
    """Aucun appel reseau, aucun quota consomme (D-04-7) : ne fait que recharger l'etat
    persiste par le PHP en RAM du demon. 'non authentifie' est un etat normal."""
    if not IMPORT_OK:
        raise ErreurDemon("INTERNAL_ERROR")

    user_data_brut = parametres.get("userData") or ""
    base_url = parametres.get("baseUrl") or ""
    email = parametres.get("email") or ""

    if user_data_brut == "":
        contexte["session"] = None
        return {"authentifie": False}

    user_data = decoder_user_data(user_data_brut)
    contexte["session"] = {
        "userData": encoder_user_data(user_data),
        "baseUrl": str(base_url),
        "email": email,
    }
    return {"authentifie": True}


async def _sonder_session(client, user_data):
    """Sonde de validite de session qui ne consomme AUCUN quota (D-05-2/R-1) : la SEULE
    requete authentifiee de web_api.py non protegee par un limiteur. Garde hasattr/callable
    : si la lib supprime/renomme cette methode privee, on n'improvise JAMAIS de repli sur
    get_home_data_v3 (qui brulerait du quota a l'insu de l'utilisateur)."""
    sonde = getattr(client, "_get_home_id", None)
    if not callable(sonde):
        logging.error("etat_compte : sonde _get_home_id absente de python-roborock (changement de version ?)")
        raise ErreurDemon("INTERNAL_ERROR")
    await sonde(user_data)


async def etat_compte(parametres, contexte):
    if not IMPORT_OK:
        raise ErreurDemon("INTERNAL_ERROR")

    user_data_brut = parametres.get("userData") or ""
    if user_data_brut == "":
        raise ErreurDemon("NOT_AUTHENTICATED")

    base_url = parametres.get("baseUrl") or ""
    email = parametres.get("email") or ""
    avec_inventaire = bool(parametres.get("avecInventaire"))

    user_data = decoder_user_data(user_data_brut)
    client = creer_client(email, base_url)

    nb_robots = None
    quota_inventaire = False

    if avec_inventaire:
        try:
            home = await client.get_home_data_v3(user_data)
            nb_robots = len(home.get_all_devices())
            # 'home' (HomeData) n'est JAMAIS journalise ni retourne : il porte les local_key
            # (R-10). Seul l'entier derive en sort.
        except Exception as erreur:
            code, _nom = code_pour_exception(erreur)
            if code != "RATE_LIMIT":
                raise
            # Refus LOCAL du limiteur uniquement (D-05-6) : degrade sans faire echouer le
            # test, la sonde confirme quand meme la validite de la session. Le refus
            # SERVEUR (RATE_LIMIT_REMOTE) n'est jamais rattrape ici : il remonte tel quel.
            quota_inventaire = True
            await _sonder_session(client, user_data)
    else:
        await _sonder_session(client, user_data)

    # Succes : la session en contexte est reamorcee (idempotent, cf. restaurer_session).
    contexte["session"] = {
        "userData": encoder_user_data(user_data),
        "baseUrl": str(base_url),
        "email": email,
    }

    return {"verifiee": True, "nbRobots": nb_robots, "quotaInventaire": quota_inventaire}


def enregistrer_operations():
    canal.enregistrer("demanderCode", demander_code)
    canal.enregistrer("validerCode", valider_code)
    canal.enregistrer("restaurerSession", restaurer_session)
    canal.enregistrer("etatCompte", etat_compte)
