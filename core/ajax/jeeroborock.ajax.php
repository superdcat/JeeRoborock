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
    case 'demanderCode':
      $email = trim((string) config::byKey('email', 'jeeroborock', ''));
      if ($email == '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        ajax::error(__('Renseignez l\'adresse e-mail du compte Roborock et enregistrez la configuration avant de demander un code.', __FILE__));
        break;
      }
      jeeroborockDaemon::appeler('demanderCode', array('email' => $email), jeeroborockDaemon::TIMEOUT_AUTH);
      ajax::success(array('envoye' => true));
      break;
    case 'validerCode':
      $email = trim((string) config::byKey('email', 'jeeroborock', ''));
      if ($email == '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        ajax::error(__('Renseignez l\'adresse e-mail du compte Roborock et enregistrez la configuration avant de demander un code.', __FILE__));
        break;
      }
      $code = trim((string) init('code'));
      if (!preg_match('/\A[A-Za-z0-9]{4,12}\z/', $code)) {
        ajax::error(__('Saisissez le code reçu par e-mail (4 à 12 caractères alphanumériques).', __FILE__));
        break;
      }
      $resultat = jeeroborockDaemon::appeler('validerCode', array('email' => $email, 'code' => $code), jeeroborockDaemon::TIMEOUT_AUTH);
      if (!jeeroborock::enregistrerSession($resultat)) {
        ajax::error(__('Le compte a été authentifié mais la session n\'a pas pu être enregistrée. Consultez le log du plugin.', __FILE__));
        break;
      }
      // La reponse est reconstruite champ par champ : $resultat, qui contient le userData,
      // ne repart JAMAIS vers le navigateur (AC5).
      ajax::success(array('lie' => true));
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
