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
# Module de session partage du demon (UC06) : import garde de la librairie roborock et
# serialisation du UserData. Extrait d'authentification.py, corps REPRIS A L'IDENTIQUE
# (dette explicite d'UC05, D-06-2 de la spec technique) : seuls le nom (perte du prefixe
# "_") et l'emplacement changent.
#
# Import garde par try/except ImportError : le demon doit rester lancable meme sur un
# venv partiellement installe (les operations retombent alors en INTERNAL_ERROR).

import base64
import json

from erreurs import ErreurDemon

try:
    from roborock.data import UserData
    from roborock.web_api import RoborockApiClient

    IMPORT_OK = True
except ImportError:
    UserData = None
    RoborockApiClient = None
    IMPORT_OK = False


def creer_client(email, base_url=None):
    """Cree un client dedie a la sonde d'etat de compte (UC05), independant de
    contexte['auth'] (reserve au flux demanderCode/validerCode). base_url=(valeur or
    None) : passer une chaine vide au lieu de None casse TOUTES les requetes (R-8)."""
    return RoborockApiClient(email, base_url=(base_url or None))


def encoder_user_data(user_data):
    """base64(JSON compact) du UserData COMPLET (aucun exclude) : D-04-4, fidelite
    d'aller-retour et confinement du secret dans une valeur opaque."""
    corps = json.dumps(user_data.as_dict(), separators=(",", ":"), ensure_ascii=True)
    return base64.b64encode(corps.encode("utf-8")).decode("ascii")


def decoder_user_data(valeur):
    """Decode un blob produit par encoder_user_data. Leve ErreurDemon('AUTH_EXPIRED')
    sur tout echec, y compris une session structurellement incomplete (R-7 : from_dict
    ignore silencieusement les cles inconnues)."""
    try:
        corps = base64.b64decode(valeur, validate=True)
        donnees = json.loads(corps.decode("utf-8"))
        if not isinstance(donnees, dict):
            raise ValueError("userData decode : pas un objet")
        user_data = UserData.from_dict(donnees)
        if not user_data or not user_data.token or not user_data.rriot or not user_data.rriot.r:
            raise ValueError("userData decode : session incomplete")
        return user_data
    except Exception as erreur:
        raise ErreurDemon("AUTH_EXPIRED") from erreur
