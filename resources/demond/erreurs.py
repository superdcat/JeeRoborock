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
# Table de traduction "exception -> code stable" du plugin JeeRoborock (UC03).
#
# Le mapping se fait sur le NOM de classe (via le MRO), sans importer roborock.exceptions :
# si une future version de la librairie renomme/supprime une exception, le demon ne casse
# pas a l'import (D-h de la spec technique UC03). L'exception retombe alors sur
# RoborockException (toujours presente dans le MRO) -> ROBOROCK_ERROR.

CODE_DEFAUT = "INTERNAL_ERROR"

# Nom de classe d'exception (python-roborock 7.8.0) -> code stable du canal.
# Complet des 23 classes de roborock/exceptions.py v7.8.0, plus les cas hors-lib
# (aiohttp.ClientError, OSError) traites en repli plus bas.
TABLE_CODES = {
    "RoborockException": "ROBOROCK_ERROR",
    "RoborockInvalidCredentials": "AUTH_EXPIRED",
    "RoborockInvalidCode": "AUTH_CODE_INVALID",
    "RoborockTooFrequentCodeRequests": "AUTH_CODE_TOO_FREQUENT",
    "RoborockInvalidEmail": "AUTH_EMAIL_INVALID",
    "RoborockAccountDoesNotExist": "AUTH_ACCOUNT_UNKNOWN",
    "RoborockNoUserAgreement": "AUTH_AGREEMENT_REQUIRED",
    "RoborockInvalidUserAgreement": "AUTH_AGREEMENT_OUTDATED",
    "RoborockRateLimit": "RATE_LIMIT",
    "RoborockTooManyRequest": "RATE_LIMIT_REMOTE",
    "RoborockNoResponseFromBaseURL": "CLOUD_UNREACHABLE",
    "RoborockUrlException": "CLOUD_REGION_UNKNOWN",
    "RoborockMissingParameters": "CLOUD_BAD_REQUEST",
    "RoborockParsingException": "PARSING_ERROR",
    "RoborockConnectionException": "CONNECTION_FAILED",
    "RoborockTimeout": "ROBOROCK_TIMEOUT",
    "RoborockBackoffException": "RETRY_EXHAUSTED",
    "RoborockDeviceBusy": "DEVICE_BUSY",
    "RoborockInvalidStatus": "DEVICE_ACTION_REFUSED",
    "VacuumError": "DEVICE_ERROR",
    "CommandVacuumError": "DEVICE_COMMAND_ERROR",
    "RoborockUnsupportedFeature": "UNSUPPORTED",
    "UnknownMethodError": "UNSUPPORTED_COMMAND",
    # Hors roborock.exceptions, traitees comme des pannes de transport cloud.
    "ClientError": "CLOUD_UNREACHABLE",
    "OSError": "CLOUD_UNREACHABLE",
}


class ErreurDemon(Exception):
    """Exception typee du canal : porte un code stable et un detail optionnel qui ne
    doit contenir que des valeurs scalaires (jamais de chaine libre, cf. AC6). La
    contrainte est appliquee (pas seulement documentee) par canal.reponse_erreur, point
    de sortie unique du canal : toute valeur non scalaire y est ecartee avant serialisation."""

    def __init__(self, code, detail=None):
        super().__init__(code)
        self.code = code
        self.detail = detail


def code_pour_exception(exc):
    """Parcourt type(exc).__mro__ et retourne (code_stable, nom_de_classe). Ne leve
    jamais : retombe sur CODE_DEFAUT si aucune classe du MRO n'est connue."""
    for classe in type(exc).__mro__:
        nom = classe.__name__
        if nom in TABLE_CODES:
            return (TABLE_CODES[nom], nom)
    return (CODE_DEFAUT, type(exc).__name__)
