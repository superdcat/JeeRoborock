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
import equipements
import robots
import routines
import supervision
from canal import construire_application
from jeedom.jeedom import jeedom_com, jeedom_utils

MAJEURE_VALIDEE = 7            # version majeure de python-roborock validee par le plugin
VERSION_VALIDEE = "7.8.0"      # a garder en phase avec plugin_info/packages.json
ADRESSE_ECOUTE = "127.0.0.1"   # jamais d'ecoute sur toutes les interfaces
INTERVALLE_MAINTIEN_PID = 60

NOM_DEMON = "jeeroborockd.py"

# UC11/AC6 : plafond de verbosite par logger tiers, applique APRES set_log_level() de
# jeedom_utils (qui fait logging.basicConfig() sur la racine). setLevel() seul, sans
# max(niveau_racine, plafond), AUGMENTERAIT la verbosite au lieu de la reduire : le
# handler installe par basicConfig est en NOTSET, donc un setLevel(INFO) sur un logger
# enfant alors que la racine est a ERROR laisserait passer les lignes INFO.
# roborock.web_api -> WARNING : supprime les 2 lignes INFO qui impriment un corps HTTP
# brut (potentiellement le UserData au login, ou les local_key via homedata). Les autres
# -> INFO : conserve les lignes utiles a AC3 (reconnexion MQTT), supprime les DEBUG de
# trames/topics non caviardes.
PLAFONDS_LOGGERS_TIERS = {
    "roborock": logging.INFO,
    "aiohttp": logging.INFO,
    "aiomqtt": logging.INFO,
    "asyncio": logging.INFO,
    "roborock.web_api": logging.WARNING,
}


def brider_loggers_tiers(niveau_racine):
    """Ne leve jamais (defensif, cote demarrage du demon)."""
    try:
        for nom, plafond in PLAFONDS_LOGGERS_TIERS.items():
            logging.getLogger(nom).setLevel(max(niveau_racine, plafond))
    except Exception as erreur:
        logging.warning("brider_loggers_tiers en erreur : %s", erreur)

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
    brider_loggers_tiers(jeedom_utils.convert_log_level(args.loglevel))

    com = jeedom_com(apikey=args.apikey, url=args.callback, cycle=supervision.INTERVALLE_LOT_S)
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
        "gestionnaire": None,  # UC07 : porte le DeviceManager et sa session MQTT - contient des
                                # secrets (local_key via le HomeData mis en cache), ne jamais serialiser
        "com": com,             # UC10 : partage avec supervision.py pour la publication des lots
        "superviseur": None,    # UC10 : etat du superviseur temps reel (cf. supervision.py)
    }

    # Enregistrement EXPLICITE (pas par effet de bord d'import) : ordre visible, echec
    # visible au demarrage plutot qu'un canal muet sur une operation manquante.
    authentification.enregistrer_operations()
    equipements.enregistrer_operations()
    robots.enregistrer_operations()
    routines.enregistrer_operations()

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
        # fermer_gestionnaire=False : le gestionnaire est ferme juste apres, explicitement.
        supervision.arreter(contexte, fermer_gestionnaire=False)
        await robots.fermer_gestionnaire(contexte)
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
