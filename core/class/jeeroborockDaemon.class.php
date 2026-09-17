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

// Brique UNIQUE d'acces au demon du plugin JeeRoborock (UC03). Tout echange PHP -> demon
// passe par cette classe : aucun appel HTTP eparse ailleurs dans le code. Dans son propre
// fichier (1 classe <-> 1 fichier) car appelee depuis des points d'entree externes (AJAX,
// cron, callback).
class jeeroborockDaemon {
  const TIMEOUT_DEFAUT       = 10;    // s, budget d'une operation courante
  const TIMEOUT_SANTE        = 3;     // s, /sante ne fait aucune I/O
  const TIMEOUT_MAX          = 60;    // s, plafond dur - garantit AC1 quel que soit l'appelant
  const TIMEOUT_CONNEXION_MS = 2000;  // ms, connexion loopback
  const MARGE_BUDGET_MS      = 1000;  // ms laissees au demon pour serialiser sa reponse

  // Appelle une operation du demon et retourne son resultat ('data'). Retourne toujours
  // un tableau (array() si data est null) : un data scalaire leve DAEMON_INVALID_RESPONSE,
  // pour que l'appelant n'ait jamais a tester le type.
  public static function appeler($_operation, $_parametres = array(), $_timeout = self::TIMEOUT_DEFAUT) {
    $timeout = intval($_timeout);
    if ($timeout < 1) {
      $timeout = 1;
    }
    if ($timeout > self::TIMEOUT_MAX) {
      $timeout = self::TIMEOUT_MAX;
    }

    $corps = json_encode(array(
      'operation'  => (string) $_operation,
      'parametres' => is_array($_parametres) ? $_parametres : array(),
      'budgetMs'   => $timeout * 1000,
    ));

    log::add('jeeroborock', 'debug', 'Appel demon operation=' . $_operation . ' parametres=' . implode(',', array_keys(is_array($_parametres) ? $_parametres : array())));

    $reponse = self::executerRequete('POST', '/rpc', $corps, $timeout);
    return self::interpreterReponse($reponse['httpCode'], $reponse['corps'], $_operation);
  }

  // Sonde de sante du canal local (bouton "Verifier le canal"). Ne teste PAS le lien au
  // compte Roborock (cf. UC05 "Tester la connexion").
  public static function sante() {
    $reponse = self::executerRequete('GET', '/sante', null, self::TIMEOUT_SANTE);
    return self::interpreterReponse($reponse['httpCode'], $reponse['corps'], 'sante');
  }

  private static function urlBase() {
    // Adresse IPv4 litterale, jamais 'localhost' : 'localhost' peut resoudre en ::1 alors
    // que le demon n'ecoute qu'en IPv4 (R3 de la spec technique).
    return 'http://127.0.0.1:' . jeeroborock::getPortDemonHttp();
  }

  // Transport cURL brut. Retourne toujours array('httpCode', 'corps') ; leve
  // jeeroborockException (DAEMON_UNREACHABLE / DAEMON_TIMEOUT) sur echec de transport.
  private static function executerRequete($_methode, $_chemin, $_corpsJson, $_timeout) {
    if (!function_exists('curl_init')) {
      throw self::lever('INTERNAL_ERROR');
    }

    $ch = curl_init();
    $entetes = array(
      'X-Apikey: ' . jeedom::getApiKey('jeeroborock'),
      'Content-Type: application/json',
      'Expect:',
    );

    $options = array(
      CURLOPT_URL             => self::urlBase() . $_chemin,
      CURLOPT_CUSTOMREQUEST   => $_methode,
      CURLOPT_RETURNTRANSFER  => true,
      CURLOPT_PROXY           => '',
      CURLOPT_CONNECTTIMEOUT_MS => self::TIMEOUT_CONNEXION_MS,
      CURLOPT_TIMEOUT_MS      => $_timeout * 1000,
      CURLOPT_HTTPHEADER      => $entetes,
    );
    if ($_corpsJson !== null) {
      $options[CURLOPT_POSTFIELDS] = $_corpsJson;
    }
    curl_setopt_array($ch, $options);

    $corps = curl_exec($ch);
    $erreurNo = curl_errno($ch);
    $erreurTexte = curl_error($ch);
    $httpCode = intval(curl_getinfo($ch, CURLINFO_HTTP_CODE));
    curl_close($ch);

    if ($corps === false || $erreurNo !== 0) {
      // curl_errno/curl_error sont journalises (anglais), jamais affiches.
      log::add('jeeroborock', 'debug', 'Echec transport demon (errno=' . $erreurNo . ') : ' . $erreurTexte);
      if ($erreurNo === CURLE_OPERATION_TIMEDOUT) {
        throw self::lever('DAEMON_TIMEOUT');
      }
      throw self::lever('DAEMON_UNREACHABLE');
    }

    return array('httpCode' => $httpCode, 'corps' => (string) $corps);
  }

  // Interprete l'enveloppe du canal. Ordre deterministe (cf. spec technique) :
  // 1. httpCode == 401 avant tout parsing JSON (un 401 a corps non-JSON reste UNAUTHORIZED)
  // 2. json_decode invalide -> DAEMON_INVALID_RESPONSE
  // 3. success === true -> data (array() si null, sinon DAEMON_INVALID_RESPONSE si scalaire)
  // 4. success === false -> code valide -> exception ; sinon INTERNAL_ERROR
  // 5. enveloppe non conforme -> DAEMON_INVALID_RESPONSE
  private static function interpreterReponse($_httpCode, $_corps, $_operation) {
    if ($_httpCode === 401) {
      throw self::lever('UNAUTHORIZED', $_operation);
    }

    $donnees = json_decode($_corps, true);
    if (!is_array($donnees)) {
      // Le corps n'est pas journalise (peut contenir un secret dès UC04) : seulement le
      // code HTTP et la longueur.
      log::add('jeeroborock', 'debug', 'Reponse demon non-JSON, httpCode=' . $_httpCode . ' longueur=' . strlen($_corps));
      throw self::lever('DAEMON_INVALID_RESPONSE', $_operation);
    }

    if (array_key_exists('success', $donnees) && $donnees['success'] === true) {
      $data = array_key_exists('data', $donnees) ? $donnees['data'] : null;
      if ($data === null) {
        return array();
      }
      if (!is_array($data)) {
        throw self::lever('DAEMON_INVALID_RESPONSE', $_operation);
      }
      return $data;
    }

    if (array_key_exists('success', $donnees) && $donnees['success'] === false) {
      $code = '';
      if (isset($donnees['error']) && is_array($donnees['error']) && isset($donnees['error']['code'])) {
        $code = (string) $donnees['error']['code'];
      }
      if (!preg_match('/\A[A-Z0-9_]{1,40}\z/', $code)) {
        $code = 'INTERNAL_ERROR';
      }
      throw self::lever($code, $_operation);
    }

    throw self::lever('DAEMON_INVALID_RESPONSE', $_operation);
  }

  // Fabrique : code stable -> message francais -> exception. Gere le cas UNAUTHORIZED
  // (centre de messages) et le cas d'un code hors table (desynchronisation demon/plugin).
  private static function lever($_codeErreur, $_operation = '') {
    $messages = self::tableMessages();

    if ($_codeErreur === 'UNAUTHORIZED') {
      log::add('jeeroborock', 'warning', 'Apikey refusee par le demon (operation=' . $_operation . ')');
      message::removeAll('jeeroborock', 'apikey_demon');
      message::add('jeeroborock', $messages['UNAUTHORIZED'], '', 'apikey_demon');
      return new jeeroborockException($_codeErreur, $messages['UNAUTHORIZED']);
    }

    if ($_codeErreur === 'UNKNOWN_OPERATION') {
      $messageFormate = sprintf($messages['UNKNOWN_OPERATION'], $_operation);
      return new jeeroborockException($_codeErreur, $messageFormate);
    }

    if (isset($messages[$_codeErreur])) {
      return new jeeroborockException($_codeErreur, $messages[$_codeErreur]);
    }

    // Seul generique de la spec : signale une desynchronisation demon/plugin, pas une
    // categorie d'erreur oubliee.
    log::add('jeeroborock', 'warning', 'Code d\'erreur inconnu du plugin recu du demon : ' . $_codeErreur);
    return new jeeroborockException($_codeErreur, __('Erreur inattendue du démon. Consultez le log du plugin.', __FILE__));
  }

  // Table code stable -> litterale __(). Jamais __($variable) : l'extraction i18n est un
  // scan statique.
  //
  // Cette table ne viole PAS l'invariant "pas de libelle en dur" du projet : elle traduit
  // une taxonomie STABLE de la librairie python-roborock (epinglee en version exacte
  // 7.8.0) et des codes de protocole propres au canal, jamais un libelle d'etat robot
  // (qui depend du modele/firmware et doit venir du demon). str(exception) n'est jamais
  // affiche : aucun texte de la librairie ne fuit vers l'UI.
  private static function tableMessages() {
    return array(
      // Famille A - canal, produite par le PHP (ne peuvent jamais provenir du demon)
      'DAEMON_UNREACHABLE'      => __('Le démon ne répond pas : vérifiez qu\'il est démarré dans la configuration du plugin.', __FILE__),
      'DAEMON_TIMEOUT'          => __('Le démon n\'a pas répondu dans le délai imparti.', __FILE__),
      'DAEMON_INVALID_RESPONSE' => __('Réponse inattendue du démon. Consultez le log du démon.', __FILE__),

      // Famille B - protocole du canal, produite par le demon (HTTP != 200)
      'UNAUTHORIZED'      => __('Le démon a refusé la clé d\'API du plugin. Redémarrez le démon depuis la configuration du plugin.', __FILE__),
      'BAD_REQUEST'       => __('Le démon a rejeté la requête du plugin. Consultez le log du démon.', __FILE__),
      'UNKNOWN_OPERATION' => __('Le démon ne connaît pas l\'opération demandée (%s). Redémarrez le démon après une mise à jour du plugin.', __FILE__),
      'INTERNAL_ERROR'    => __('Erreur interne du démon. Consultez le log du démon.', __FILE__),

      // Famille C - operation (HTTP 200, success: false)
      'OPERATION_TIMEOUT'        => __('L\'opération n\'a pas abouti dans le délai imparti.', __FILE__),
      'NOT_AUTHENTICATED'        => __('Le compte Roborock n\'est pas lié : authentifiez-vous depuis la configuration du plugin.', __FILE__),
      'DEVICE_UNKNOWN'           => __('Robot inconnu du démon : relancez une synchronisation des équipements.', __FILE__),
      'DEVICE_OFFLINE'           => __('Le robot est hors ligne : il ne répond pas au cloud Roborock.', __FILE__),
      'AUTH_EXPIRED'             => __('Session Roborock expirée : une nouvelle authentification par code e-mail est nécessaire.', __FILE__),
      'AUTH_CODE_INVALID'        => __('Code de connexion invalide ou expiré.', __FILE__),
      'AUTH_CODE_TOO_FREQUENT'   => __('Trop de demandes de code de connexion : patientez quelques minutes avant de réessayer.', __FILE__),
      'AUTH_EMAIL_INVALID'       => __('L\'adresse e-mail du compte Roborock est invalide.', __FILE__),
      'AUTH_ACCOUNT_UNKNOWN'     => __('Aucun compte Roborock ne correspond à cette adresse e-mail.', __FILE__),
      'AUTH_AGREEMENT_REQUIRED'  => __('Les conditions d\'utilisation Roborock n\'ont pas été acceptées : ouvrez l\'application mobile Roborock pour les accepter.', __FILE__),
      'AUTH_AGREEMENT_OUTDATED'  => __('Les conditions d\'utilisation Roborock ont changé : ouvrez l\'application mobile Roborock pour les accepter à nouveau.', __FILE__),
      'RATE_LIMIT'               => __('Quota d\'appels Roborock atteint : patientez avant de réessayer. Ce quota est partagé avec l\'application mobile Roborock.', __FILE__),
      'RATE_LIMIT_REMOTE'        => __('Le cloud Roborock a refusé la demande (trop de requêtes) : patientez avant de réessayer.', __FILE__),
      'CLOUD_UNREACHABLE'        => __('Le cloud Roborock est injoignable : vérifiez l\'accès à Internet de Jeedom.', __FILE__),
      'CLOUD_REGION_UNKNOWN'     => __('Impossible de déterminer le serveur Roborock de ce compte.', __FILE__),
      'CLOUD_BAD_REQUEST'        => __('Le cloud Roborock a rejeté la demande (paramètres manquants). Consultez le log du démon.', __FILE__),
      'PARSING_ERROR'            => __('Réponse incompréhensible du cloud Roborock. Consultez le log du démon.', __FILE__),
      'CONNECTION_FAILED'        => __('La connexion avec le cloud Roborock ou le robot a échoué.', __FILE__),
      'ROBOROCK_TIMEOUT'         => __('Le cloud Roborock ou le robot n\'a pas répondu dans le délai imparti.', __FILE__),
      'RETRY_EXHAUSTED'          => __('Plusieurs tentatives de communication ont échoué : réessayez plus tard.', __FILE__),
      'DEVICE_BUSY'              => __('Le robot est occupé : il ne peut pas traiter cette demande maintenant.', __FILE__),
      'DEVICE_ACTION_REFUSED'    => __('Le robot a refusé l\'action dans son état actuel.', __FILE__),
      'DEVICE_ERROR'             => __('Le robot signale une erreur : consultez son état dans l\'application Roborock.', __FILE__),
      'DEVICE_COMMAND_ERROR'     => __('Le robot a signalé une erreur en exécutant la commande.', __FILE__),
      'UNSUPPORTED'              => __('Cette fonction n\'est pas disponible sur ce modèle de robot.', __FILE__),
      'UNSUPPORTED_COMMAND'      => __('Le robot ne reconnaît pas cette commande.', __FILE__),
      'ROBOROCK_ERROR'           => __('Erreur Roborock non identifiée. Consultez le log du démon.', __FILE__),
    );
  }
}
