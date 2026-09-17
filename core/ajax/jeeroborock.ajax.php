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

try {
  require_once dirname(__FILE__) . '/../../../../core/php/core.inc.php';
  include_file('core', 'authentification', 'php');

  if (!isConnect('admin')) {
    throw new Exception(__('401 - Accès non autorisé', __FILE__));
  }

  ajax::init();

  // Libere le verrou de session AVANT tout appel au demon : sans cela, la session PHP
  // reste verrouillee pendant tout l'appel et toute l'interface Jeedom se fige pour
  // l'utilisateur (aucune autre requete ne peut aboutir).
  session_write_close();

  switch (init('action')) {
    case 'santeCanal':
      $etat = jeeroborockDaemon::sante();
      ajax::success(array(
        'version'             => substr((string) $etat['version'], 0, 32),
        'callback'            => !empty($etat['callback']),
        'dureeFonctionnement' => intval($etat['dureeFonctionnement']),
      ));
      break;
    default:
      throw new Exception(__('Aucune méthode correspondante à', __FILE__) . ' : ' . init('action'));
  }
}
catch (jeeroborockException $e) {
  ajax::error($e->getMessage());
}
catch (Throwable $e) {
  log::add('jeeroborock', 'error', $e->getMessage());
  ajax::error(__('Une erreur interne est survenue. Consultez le log du plugin.', __FILE__));
}
