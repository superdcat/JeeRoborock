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
# Demon du plugin JeeRoborock (UC02 : dependances Python et demon ; UC03 : canal
# PHP<->demon).
#
# A ce stade, aucune logique Roborock n'est implementee : le demon ouvre son canal
# HTTP local (127.0.0.1 uniquement), journalise la version de python-roborock
# effectivement chargee et repond a un healthcheck. Renomme depuis le "demond.py" du
# squelette : garder ce nom generique aurait fait tuer, par system::kill(), les demons
# des AUTRES plugins issus du meme squelette (D-b de la spec technique UC02).
#
# handler_sante, verifier_apikey et construire_application ont ete DEPLACES vers
# canal.py en UC03 (D-g) : ce fichier va accueillir tout le metier d'UC04->09 et
# depassait deja 224 lignes. Le contrat de GET /sante est inchange.

import argparse
import asyncio
import logging
import os
import signal
import sys
import time

from aiohttp import web

import authentification
from canal import construire_application
from jeedom.jeedom import jeedom_com, jeedom_utils

MAJEURE_VALIDEE = 7            # version majeure de python-roborock validee par le plugin
VERSION_VALIDEE = "7.8.0"      # a garder en phase avec plugin_info/packages.json
ADRESSE_ECOUTE = "127.0.0.1"   # jamais d'ecoute sur toutes les interfaces
INTERVALLE_MAINTIEN_PID = 60

NOM_DEMON = "jeeroborockd.py"

try:
    import roborock
    _VERSION_ROBOROCK = getattr(roborock, "__version__", None)
    _IMPORT_ROBOROCK_OK = True
except ImportError as _erreur_import:
    _VERSION_ROBOROCK = None
    _IMPORT_ROBOROCK_OK = False
    _MESSAGE_IMPORT_ROBOROCK = str(_erreur_import)


def journaliser_brut(niveau, message):
    """Journalise SANS passer par le niveau de log Jeedom (souvent 'error' par defaut) :
    c'est ce qui garantit que la banniere de version (AC4/AC5) est visible sur une
    installation neuve."""
    horodatage = time.strftime("%Y-%m-%d %H:%M:%S")
    print("[%s][%s] : %s" % (horodatage, niveau, message), flush=True)


def analyser_arguments():
    parser = argparse.ArgumentParser(description="Demon du plugin JeeRoborock")
    parser.add_argument("--loglevel", help="Niveau de log Jeedom", type=str, default="error")
    parser.add_argument("--port", help="Port d'ecoute du canal HTTP local", type=int, required=True)
    parser.add_argument("--callback", help="URL du callback vers Jeedom", type=str, required=True)
    parser.add_argument("--apikey", help="Apikey du plugin", type=str, required=True)
    parser.add_argument("--pid", help="Chemin du fichier PID", type=str, required=True)
    return parser.parse_args()


def detecter_version():
    """Retourne (version, avertissement). avertissement = True si la majeure detectee
    depasse MAJEURE_VALIDEE."""
    if not _IMPORT_ROBOROCK_OK or not _VERSION_ROBOROCK:
        return ("inconnue", False)
    try:
        majeure = int(str(_VERSION_ROBOROCK).split(".")[0])
    except (ValueError, IndexError):
        return (str(_VERSION_ROBOROCK), False)
    return (str(_VERSION_ROBOROCK), majeure > MAJEURE_VALIDEE)


async def demarrer_serveur(application, port):
    executeur = web.AppRunner(application, access_log=None)
    await executeur.setup()
    site = web.TCPSite(executeur, ADRESSE_ECOUTE, port)
    await site.start()
    return executeur, site


async def maintenir_pid(chemin, evenement_arret):
    """Reecrit periodiquement le fichier PID : sur certains systemes, systemd-tmpfiles
    purge /tmp des fichiers non touches depuis plusieurs jours, ce qui ferait declarer
    mort un demon pourtant actif depuis longtemps (R5 de la spec technique)."""
    while not evenement_arret.is_set():
        try:
            jeedom_utils.write_pid(chemin)
        except Exception as erreur:
            logging.warning("Impossible de reecrire le fichier PID : %s", erreur)
        try:
            await asyncio.wait_for(evenement_arret.wait(), timeout=INTERVALLE_MAINTIEN_PID)
        except asyncio.TimeoutError:
            pass


async def principal_async(args):
    if not _IMPORT_ROBOROCK_OK:
        journaliser_brut("ERROR", "python-roborock est introuvable ou non chargeable : %s" % _MESSAGE_IMPORT_ROBOROCK)
        return 3

    version, avertissement = detecter_version()
    journaliser_brut("INFO", "Demarrage du demon JeeRoborock - python-roborock %s" % version)
    if avertissement:
        journaliser_brut(
            "ATTENTION",
            "La version %s de python-roborock a une majeure superieure a celle validee par le plugin (%s) : "
            "le fonctionnement n'est pas garanti." % (version, MAJEURE_VALIDEE),
        )

    jeedom_utils.set_log_level(args.loglevel)

    com = jeedom_com(apikey=args.apikey, url=args.callback, cycle=0)
    callback_ok = com.test()
    if not callback_ok:
        logging.error("Callback Jeedom injoignable au demarrage - le demon reste actif (canal montant seul affecte)")

    contexte = {
        "version": version,
        "majeureValidee": MAJEURE_VALIDEE,
        "avertissement": avertissement,
        "callback_ok": callback_ok,
        "demarrage": time.time(),
        "auth": None,       # UC04 : {'client': RoborockApiClient, 'email': str} pendant un login en cours
        "session": None,    # UC04 : {'userData': str, 'baseUrl': str, 'email': str} apres succes
    }

    # Enregistrement EXPLICITE (pas par effet de bord d'import) : ordre visible, echec
    # visible au demarrage plutot qu'un canal muet sur une operation manquante.
    authentification.enregistrer_operations()

    application = construire_application(args.apikey, contexte)
    try:
        executeur, site = await demarrer_serveur(application, args.port)
    except OSError as erreur:
        journaliser_brut("ERROR", "Impossible d'ecouter sur %s:%s - %s" % (ADRESSE_ECOUTE, args.port, erreur))
        return 2

    # Le PID n'est ecrit qu'apres un bind reussi : state == 'ok' cote PHP signifie donc
    # bien "le demon ecoute".
    jeedom_utils.write_pid(args.pid)

    if callback_ok:
        com.send_change_immediate({"versionLibrairie": {
            "version": version,
            "majeureValidee": MAJEURE_VALIDEE,
            "avertissement": avertissement,
        }})

    evenement_arret = asyncio.Event()

    def gestionnaire_signal(signum, frame=None):
        journaliser_brut("INFO", "Signal %s recu, arret en cours" % signum)
        evenement_arret.set()

    signal.signal(signal.SIGTERM, gestionnaire_signal)
    signal.signal(signal.SIGINT, gestionnaire_signal)

    tache_pid = asyncio.create_task(maintenir_pid(args.pid, evenement_arret))

    try:
        await evenement_arret.wait()
    finally:
        tache_pid.cancel()
        try:
            os.remove(args.pid)
        except OSError:
            pass
        await site.stop()
        await executeur.cleanup()

    return 0


def principal(args):
    try:
        return asyncio.run(principal_async(args))
    except Exception as erreur:
        journaliser_brut("ERROR", "Erreur fatale du demon : %s" % erreur)
        return 1


if __name__ == "__main__":
    sys.exit(principal(analyser_arguments()))
