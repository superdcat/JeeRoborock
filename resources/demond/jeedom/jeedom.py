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
# Fork ALLEGE de la lib Jeedom standard (jeedom/jeedom.py du squelette de plugin).
#
# python-roborock n'apporte ni pyserial, ni pyudev, ni requests comme dependances
# transitives : les importer ferait mourir le demon a l'import dans le venv du plugin
# (ImportError). Ce fork ne garde donc que ce qui sert au canal HTTP montant
# (jeedom_com) et aux petits utilitaires (jeedom_utils), sur la seule bibliotheque
# standard (urllib.request au lieu de requests).
#
# Supprimes par rapport au squelette : jeedom_serial, jeedom_socket,
# jeedom_socket_handler, JEEDOM_SOCKET_MESSAGE, find_tty_usb, et les helpers binaires
# (ByteToHex, dec2bin, dec2hex, testBit, clearBit, split_len, printHex, remove_accents,
# stripped).

import json
import logging
import os
import time
import urllib.error
import urllib.request
from collections.abc import Mapping
from threading import Thread

TIMEOUT_CALLBACK = 15


class jeedom_com():
    def __init__(self, apikey='', url='', cycle=0, retry=3):
        self._apikey = apikey
        self._url = url
        self._cycle = cycle
        self._retry = retry
        self._changes = {}
        if self._cycle > 0:
            Thread(target=self.__thread_changes_async, daemon=True).start()

    def __thread_changes_async(self):
        if self._cycle <= 0:
            return
        logging.info('Start changes async thread')
        while True:
            try:
                time.sleep(self._cycle)
                if len(self._changes) == 0:
                    continue
                changes = self._changes
                self._changes = {}
                self.__post_change(changes)
            except Exception as error:
                logging.error('Critical error on send_changes_async %s', error)

    def add_changes(self, key: str, value):
        if key.find('::') != -1:
            tmp_changes = {}
            changes = value
            for k in reversed(key.split('::')):
                if k not in tmp_changes:
                    tmp_changes[k] = {}
                tmp_changes[k] = changes
                changes = tmp_changes
                tmp_changes = {}
            if self._cycle <= 0:
                self.send_change_immediate(changes)
            else:
                self.merge_dict(self._changes, changes)
        else:
            if self._cycle <= 0:
                self.send_change_immediate({key: value})
            else:
                self._changes[key] = value

    def send_change_immediate(self, change):
        Thread(target=self.__post_change, args=(change,)).start()

    def __post_change(self, change):
        # Ne jamais journaliser l'URL : l'apikey y transite en query string.
        logging.debug('Send to jeedom')
        for i in range(self._retry):
            try:
                statut = self.__post(change)
                if statut == 200:
                    return True
                logging.warning('Error on send request to jeedom, return code %s', statut)
            except Exception as error:
                logging.error('Error on send request to jeedom "%s" retry: %i/%i', error, i, self._retry)
            time.sleep(0.5)
        return False

    def __post(self, donnees):
        requete = urllib.request.Request(
            self._url + '?apikey=' + self._apikey,
            data=json.dumps(donnees).encode('utf-8'),
            headers={'Content-Type': 'application/json'},
            method='POST',
        )
        with urllib.request.urlopen(requete, timeout=TIMEOUT_CALLBACK) as reponse:
            return reponse.status

    def set_change(self, changes):
        self._changes = changes

    def get_change(self):
        return self._changes

    def merge_dict(self, d1, d2):
        for k, v2 in d2.items():
            v1 = d1.get(k)
            if isinstance(v1, Mapping) and isinstance(v2, Mapping):
                self.merge_dict(v1, v2)
            else:
                d1[k] = v2

    def test(self):
        # Ne jamais journaliser l'URL : l'apikey y transite en query string.
        try:
            requete = urllib.request.Request(self._url + '?apikey=' + self._apikey + '&test=1')
            with urllib.request.urlopen(requete, timeout=TIMEOUT_CALLBACK) as reponse:
                corps = reponse.read().decode('utf-8', 'replace')
                if reponse.status != 200 or corps.strip() != 'OK':
                    logging.error('Callback error: HTTP %s. Please check your network configuration page', reponse.status)
                    return False
                return True
        except urllib.error.URLError as e:
            logging.error('Callback result as a unknown error: %s. Please check your network configuration page', e)
            return False
        except Exception as e:
            logging.error('Callback result as a unknown error: %s. Please check your network configuration page', e)
            return False


class jeedom_utils():

    @staticmethod
    def convert_log_level(level='error'):
        levels = {
            'debug': logging.DEBUG,
            'info': logging.INFO,
            'notice': logging.WARNING,
            'warning': logging.WARNING,
            'error': logging.ERROR,
            'critical': logging.CRITICAL,
            'none': logging.CRITICAL,
        }
        return levels.get(level, logging.CRITICAL)

    @staticmethod
    def set_log_level(level='error'):
        fmt = '[%(asctime)-15s][%(levelname)s] : %(message)s'
        logging.basicConfig(level=jeedom_utils.convert_log_level(level), format=fmt, datefmt="%Y-%m-%d %H:%M:%S")

    @staticmethod
    def write_pid(path):
        dossier = os.path.dirname(path)
        if dossier and not os.path.isdir(dossier):
            os.makedirs(dossier, exist_ok=True)
        pid = str(os.getpid())
        logging.debug("Writing PID %s to %s", pid, path)
        with open(path, 'w') as fichier:
            fichier.write("%s\n" % pid)
