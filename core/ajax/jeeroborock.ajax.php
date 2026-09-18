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
      $email = jeeroborock::getEmailCompte();
      if ($email == '') {
        ajax::error(__('Renseignez l\'adresse e-mail du compte Roborock et enregistrez la configuration avant de demander un code.', __FILE__));
        break;
      }
      jeeroborockDaemon::appeler('demanderCode', array('email' => $email), jeeroborockDaemon::TIMEOUT_AUTH);
      ajax::success(array('envoye' => true));
      break;
    case 'validerCode':
      $email = jeeroborock::getEmailCompte();
      if ($email == '') {
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
    case 'testerConnexion':
      // UC05 - 6 etapes, court-circuit au premier etat atteint (D-05-2). Endpoint admin
      // assume : l'action peut declencher un appel cloud impute au quota du compte.
      $email = jeeroborock::getEmailCompte();
      if ($email == '') {
        ajax::success(array(
          'etat'        => 'nonConfigure',
          'message'     => __('Aucune adresse e-mail n\'est renseignée : saisissez l\'adresse du compte Roborock dans cette page et enregistrez la configuration.', __FILE__),
          'badge'       => __('Non configuré', __FILE__),
          'badgeClasse' => 'label-default',
        ));
        break;
      }
      if (!jeeroborock::estCompteLie()) {
        ajax::success(array(
          'etat'        => 'nonAuthentifie',
          'message'     => __('Le compte Roborock n\'est pas lié : demandez un code de connexion pour authentifier le compte.', __FILE__),
          'badge'       => __('Compte Roborock non lié', __FILE__),
          'badgeClasse' => 'label-default',
        ));
        break;
      }

      $inventaire = jeeroborock::getInventaireCompte();
      $avecInventaire = ($inventaire === null);

      try {
        $r = jeeroborockDaemon::appeler(
          'etatCompte',
          array('userData' => jeeroborock::getUserData(), 'baseUrl' => jeeroborock::getBaseUrlCompte(), 'email' => $email, 'avecInventaire' => $avecInventaire),
          jeeroborockDaemon::TIMEOUT_COMPTE
        );
      } catch (jeeroborockException $e) {
        if ($e->getCodeErreur() == 'AUTH_EXPIRED') {
          log::add('jeeroborock', 'warning', 'Test de connexion : session Roborock expirée (' . $e->getMessage() . ')');
          ajax::success(array(
            'etat'        => 'reauthentification',
            'message'     => $e->getMessage(),
            'badge'       => __('Ré-authentification requise', __FILE__),
            'badgeClasse' => 'label-warning',
          ));
          break;
        }
        if ($e->getCodeErreur() == 'NOT_AUTHENTICATED') {
          ajax::success(array(
            'etat'        => 'nonAuthentifie',
            'message'     => __('Le compte Roborock n\'est pas lié : demandez un code de connexion pour authentifier le compte.', __FILE__),
            'badge'       => __('Compte Roborock non lié', __FILE__),
            'badgeClasse' => 'label-default',
          ));
          break;
        }
        throw $e;
      }

      // Tracabilite d'AC4 - trois branches distinctes, a ne pas confondre (cf. correction
      // de revue de la spec technique) : sans le garde quotaInventaire, la 2e branche
      // journaliserait "servi depuis le cache" alors qu'aucun cache n'a servi, ce qui rend
      // R-4 (AC4) et R-6 (AC6) indistinguables au log.
      $nbRobots = null;
      if (isset($r['nbRobots']) && is_numeric($r['nbRobots']) && intval($r['nbRobots']) >= 0) {
        $nbRobots = intval($r['nbRobots']);
        jeeroborock::enregistrerInventaireCompte($nbRobots);
        // Pas de relecture du cache ici : cache::set peut ne rien persister sans lever (panne
        // du backend de cache), l'ecriture ne doit alors pas conditionner la justesse du
        // message construit maintenant a partir de ce que le demon vient de repondre.
        $inventaire = array('nbRobots' => $nbRobots, 'horodatage' => time());
        log::add('jeeroborock', 'info', 'Inventaire du compte rafraîchi via homedata (quota 40/jour)');
      } elseif (!empty($r['quotaInventaire'])) {
        log::add('jeeroborock', 'info', 'Inventaire non rafraîchi : quota homedata local déjà atteint');
      } else {
        // Cache deja consulte avant l'appel demon (avecInventaire = false) : $inventaire
        // porte deja la donnee, il ne reste qu'a en extraire le nombre pour l'affichage.
        if ($inventaire !== null) {
          $nbRobots = $inventaire['nbRobots'];
        }
        log::add('jeeroborock', 'debug', 'Inventaire servi depuis le cache, aucun appel homedata');
      }

      $baseUrl = jeeroborock::getBaseUrlCompte();
      if ($nbRobots !== null && $inventaire !== null) {
        $message = sprintf(__('Authentifié — %1$s robot(s) détecté(s) (inventaire du %2$s)', __FILE__), $nbRobots, date('d/m/Y H:i', $inventaire['horodatage']));
      } elseif (!empty($r['quotaInventaire'])) {
        $message = __('Authentifié — le nombre de robots n\'a pas pu être relevé : le quota d\'inventaire Roborock est atteint, réessayez plus tard.', __FILE__);
      } else {
        $message = __('Authentifié — le nombre de robots n\'a pas pu être relevé.', __FILE__);
      }
      if ($baseUrl != '') {
        $message .= ' ' . sprintf(__('Serveur du compte : %s', __FILE__), parse_url($baseUrl, PHP_URL_HOST));
      }

      ajax::success(array(
        'etat'        => 'authentifie',
        'message'     => $message,
        'badge'       => __('Compte Roborock lié', __FILE__),
        'badgeClasse' => 'label-success',
      ));
      break;
    case 'synchroniserEquipements':
      $email = jeeroborock::getEmailCompte();
      if ($email == '') {
        ajax::error(__('Renseignez l\'adresse e-mail du compte Roborock et enregistrez la configuration avant de demander un code.', __FILE__));
        break;
      }
      if (!jeeroborock::estCompteLie()) {
        ajax::error(__('Le compte Roborock n\'est pas lié : demandez un code de connexion pour authentifier le compte.', __FILE__));
        break;
      }

      $r = jeeroborock::synchroniserEquipements();

      $phrases = array();
      if ($r['crees'] + $r['misAJour'] > 0) {
        $phrases[] = sprintf(__('Synchronisation terminée : %1$s équipement(s) créé(s), %2$s mis à jour.', __FILE__), $r['crees'], $r['misAJour']);
      } elseif (empty($r['nonSupportes'])) {
        $phrases[] = __('Aucun robot compatible n\'a été trouvé sur ce compte Roborock.', __FILE__);
      }
      if (!empty($r['nonSupportes'])) {
        $phrases[] = sprintf(__('Modèle non supporté par cette version du plugin : %s', __FILE__), implode(', ', array_slice($r['nonSupportes'], 0, 5)) . (count($r['nonSupportes']) > 5 ? '…' : ''));
      }
      if (!empty($r['partages'])) {
        $phrases[] = sprintf(__('Robot(s) partagé(s) par un autre compte : %s', __FILE__), implode(', ', array_slice($r['partages'], 0, 5)) . (count($r['partages']) > 5 ? '…' : ''));
      }
      if ($r['echecs'] > 0) {
        $phrases[] = sprintf(__('%s robot(s) n\'ont pas pu être enregistrés dans Jeedom. Consultez le log du plugin.', __FILE__), $r['echecs']);
      }
      $message = implode(' ', $phrases);

      ajax::success(array(
        'message'      => $message,
        'crees'        => $r['crees'],
        'misAJour'     => $r['misAJour'],
        'nonSupportes' => count($r['nonSupportes']),
        'echecs'       => $r['echecs'],
      ));
      break;
    case 'rafraichirEtat':
      // Endpoint admin assumé : l'action ouvre le canal du robot et peut durer
      // jusqu'à 35 s (UC07, TIMEOUT_ETAT).
      $eqLogic = eqLogic::byId(intval(init('id')));
      if (!($eqLogic instanceof jeeroborock)) {
        ajax::error(__('Équipement introuvable ou non géré par ce plugin.', __FILE__));
        break;
      }
      if ($eqLogic->getIsEnable() == 0) {
        ajax::error(__('Cet équipement est désactivé : activez-le avant de rafraîchir son état.', __FILE__));
        break;
      }
      $r = $eqLogic->rafraichirEtat();
      $message = ($r['cmdCreees'] > 0)
        ? sprintf(__('État rafraîchi — %s nouvelle(s) commande(s) créée(s). Rechargez la page pour les voir.', __FILE__), $r['cmdCreees'])
        : __('État rafraîchi.', __FILE__);
      ajax::success(array('message' => $message, 'cmdCreees' => intval($r['cmdCreees'])));
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
