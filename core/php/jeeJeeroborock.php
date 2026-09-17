<?php
/* This file is part of Jeedom.
*
* Jeedom is free software: you can redistribute it and/or modify
* it under the terms of the GNU General Public License as published by
* the Free Software Foundation, either version 3 of the License, or
* (at your option) any later version.
*
* Jeedom is distributed in the hope that it will be useful,
* but WITHOUT ANY WARRANTY; without even the implied warranty of
* MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
* GNU General Public License for more details.
*
* You should have received a copy of the GNU General Public License
* along with Jeedom. If not, see <http://www.gnu.org/licenses/>.
*/

// Callback démon -> Jeedom. Point d'entrée EXTERNE : n'appeler que jeeroborock::, jamais
// une classe annexe directement (autoload 1 classe <-> 1 fichier).
require_once __DIR__ . '/../../../../core/php/core.inc.php';

try {
  if (!jeedom::apiAccess(init('apikey'), 'jeeroborock')) {
    header('HTTP/1.1 401 Unauthorized');
    log::add('jeeroborock', 'warning', 'Callback demon : apikey invalide');
    die();
  }

  if (init('test') != '') {
    echo 'OK';
    die();
  }

  $resultat = json_decode(file_get_contents('php://input'), true);
  if (!is_array($resultat)) {
    die();
  }

  if (isset($resultat['versionLibrairie'])) {
    jeeroborock::traiterVersionLibrairie($resultat['versionLibrairie']);
  } else {
    log::add('jeeroborock', 'debug', 'Callback demon : cles recues ' . jeeroborock::nettoyerPourLog(implode(', ', array_keys($resultat))));
  }
} catch (Throwable $e) {
  // getMessage() uniquement : jamais displayException() ici, la trace expose les arguments
  // de chaque frame (des secrets y transiteront des UC04).
  log::add('jeeroborock', 'error', 'Callback demon en erreur : ' . $e->getMessage());
}
