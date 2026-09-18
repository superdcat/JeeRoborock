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

/* * ***************************Includes********************************* */
require_once __DIR__  . '/../../../../core/php/core.inc.php';

class jeeroborock extends eqLogic {
  /*     * *************************Attributs****************************** */

  /*
  * Permet de définir les possibilités de personnalisation du widget (en cas d'utilisation de la fonction 'toHtml' par exemple)
  * Tableau multidimensionnel - exemple: array('custom' => true, 'custom::layout' => false)
  public static $_widgetPossibility = array();
  */

  // Valeur par défaut du port du canal HTTP local avec le démon.
  // A garder synchronisée avec core/config/jeeroborock.config.ini.
  const PORT_DEMON_HTTP_DEFAUT = 61350;

  // Délai maximal (secondes) laissé au démon pour démarrer avant de considérer l'échec.
  const DELAI_DEMARRAGE_DEMON = 30;

  // Garde-fou sur la valeur userData renvoyée par le démon (UC04). Aucun aller-retour
  // légitime n'approche cette taille (UserData complet en base64 fait quelques centaines
  // d'octets).
  const LONGUEUR_MAX_USERDATA = 8192;

  // Cache PHP de l'inventaire du compte (UC05, D-05-1) : survit au redémarrage du démon
  // comme à celui de Jeedom, contrairement au limiteur de quota de python-roborock (compteur
  // par processus). TTL 24h : arbitrage utilisateur explicite, pas une valeur par défaut.
  const DUREE_CACHE_INVENTAIRE = 86400;
  const CLE_CACHE_INVENTAIRE = 'jeeroborock::inventaireCompte';

  // Synchronisation des équipements (UC06). Neutralisation défensive des chaînes
  // d'origine cloud avant log/DOM/base ; garde-fou symétrique de LIMITE_APPAREILS côté démon.
  const LONGUEUR_MAX_TEXTE_INVENTAIRE = 128;
  const NB_MAX_ROBOTS_SYNCHRO = 64;

  // Attention : userData contient le jeton de session et les identifiants dérivés rriot.
  // Ne jamais définir preConfig_userData / postConfig_userData : une exception levée depuis
  // une frame qui reçoit ce paramètre exposerait le secret via displayException() (trace complète
  // des arguments de frame en mode debug).
  public static $_encryptConfigKey = array('userData');

  /*     * ***********************Methode static*************************** */

  // Adresse e-mail du compte Roborock. Chaîne vide autorisée (état initial). La casse n'est pas
  // normalisée à l'enregistrement (UC01 : elle entre dans le header_clientid du démon), mais
  // n'entre pas non plus dans la comparaison de changement (UC04, cf. strcasecmp ci-dessous).
  //
  // UC04 : si l'adresse change réellement (hors casse) et qu'un compte est lié, la session est
  // déliée. La validation précède toujours la déliaison : une faute de frappe ne doit jamais
  // détruire une session fonctionnelle. Pas de court-circuit possible : 'email' n'a pas de valeur
  // par défaut dans core/config/jeeroborock.config.ini.
  public static function preConfig_email($_value) {
    $valeur = trim((string) $_value);
    $ancien = trim((string) config::byKey('email', 'jeeroborock', ''));

    if ($valeur != '' && !filter_var($valeur, FILTER_VALIDATE_EMAIL)) {
      throw new Exception(__('L\'adresse e-mail du compte Roborock est invalide.', __FILE__));
    }

    $aChange = (strcasecmp($valeur, $ancien) !== 0);
    if ($aChange && self::estCompteLie()) {
      self::oublierSession();
    }

    return $valeur;
  }

  /*     * ***********************Session Roborock (UC04)********************* */

  // Source de vérité "compte lié" (D-04-8) : présence de la clé userData en configuration
  // plugin. L'état runtime du démon (contexte['session']) en est un dérivé, jamais une source.
  public static function estCompteLie() {
    return self::getUserData() != '';
  }

  // Unique point de lecture de l'e-mail du compte (UC05, D-05-1 de la spec technique).
  // Retombe sur une chaîne vide si absent ou invalide, sans lever. Solde la dette explicite
  // d'UC04 : demanderCode/validerCode l'utilisent désormais aussi, messages inchangés.
  public static function getEmailCompte() {
    $valeur = trim((string) config::byKey('email', 'jeeroborock', ''));
    if ($valeur == '' || !filter_var($valeur, FILTER_VALIDATE_EMAIL)) {
      return '';
    }
    return $valeur;
  }

  // Unique point de lecture du blob userData. Le garde is_string n'est pas cosmétique :
  // config::byKey applique is_json($v, $v), qui convertirait en tableau PHP toute valeur
  // décodable comme JSON - précisément ce que l'encodage base64 (D-04-4) empêche.
  public static function getUserData() {
    $valeur = config::byKey('userData', 'jeeroborock', '');
    if (!is_string($valeur)) {
      return '';
    }
    return $valeur;
  }

  public static function getBaseUrlCompte() {
    $valeur = config::byKey('baseUrl', 'jeeroborock', '');
    if (!is_string($valeur)) {
      return '';
    }
    return $valeur;
  }

  /*     * ***********************Cache de l'inventaire du compte (UC05)********** */

  // Lit le cache PHP de l'inventaire (D-05-1). Retourne null si absent, expiré ou non
  // conforme : l'expiration est portée par le moteur de cache du core (getValue('') sur
  // une entrée expirée) - ne jamais recalculer le TTL à la main, c'est ce mécanisme qui
  // porte AC4.
  public static function getInventaireCompte() {
    $valeur = cache::byKey(self::CLE_CACHE_INVENTAIRE)->getValue('');
    if (!is_array($valeur) || !isset($valeur['nbRobots'], $valeur['horodatage'])) {
      return null;
    }
    if (!is_numeric($valeur['nbRobots']) || intval($valeur['nbRobots']) < 0) {
      return null;
    }
    if (!is_numeric($valeur['horodatage']) || intval($valeur['horodatage']) < 0) {
      return null;
    }
    return array('nbRobots' => intval($valeur['nbRobots']), 'horodatage' => intval($valeur['horodatage']));
  }

  // Met en cache le nombre de robots (24h, D-05-4). NE LEVE JAMAIS : un incident de cache
  // ne doit jamais faire échouer un test de connexion.
  public static function enregistrerInventaireCompte($_nbRobots) {
    try {
      cache::set(self::CLE_CACHE_INVENTAIRE, array('nbRobots' => intval($_nbRobots), 'horodatage' => time()), self::DUREE_CACHE_INVENTAIRE);
    } catch (Throwable $e) {
      log::add('jeeroborock', 'warning', 'enregistrerInventaireCompte : échec de mise en cache : ' . $e->getMessage());
    }
  }

  // Purge le cache de l'inventaire (déliaison/nouvelle liaison, D-05-4) : sans quoi le test
  // afficherait le nombre de robots de l'ancien compte. NE LEVE JAMAIS.
  public static function oublierInventaireCompte() {
    try {
      cache::delete(self::CLE_CACHE_INVENTAIRE);
    } catch (Throwable $e) {
      log::add('jeeroborock', 'warning', 'oublierInventaireCompte : échec de purge : ' . $e->getMessage());
    }
  }

  /*     * ***********************Découverte des équipements (UC06)************ */

  // Transforme l'inventaire du compte Roborock en équipements Jeedom stables et
  // idempotents. Aucune retentative sur un refus de quota (D-06-4) : jeeroborockException
  // remonte telle quelle à l'appelant (AJAX).
  public static function synchroniserEquipements() {
    $r = jeeroborockDaemon::appeler(
      'decouvrirEquipements',
      array('userData' => self::getUserData(), 'baseUrl' => self::getBaseUrlCompte(), 'email' => self::getEmailCompte()),
      jeeroborockDaemon::TIMEOUT_DECOUVERTE
    );

    $robots = (isset($r['robots']) && is_array($r['robots'])) ? array_slice($r['robots'], 0, self::NB_MAX_ROBOTS_SYNCHRO) : array();
    $nonSupportesBruts = (isset($r['nonSupportes']) && is_array($r['nonSupportes'])) ? array_slice($r['nonSupportes'], 0, self::NB_MAX_ROBOTS_SYNCHRO) : array();

    $crees = 0;
    $misAJour = 0;
    $echecs = 0;
    $partages = array();

    foreach ($robots as $robot) {
      if (!is_array($robot)) {
        $echecs++;
        continue;
      }
      try {
        $resultat = self::appliquerRobot($robot);
        if ($resultat == 'cree') {
          $crees++;
        } else {
          $misAJour++;
        }
        if (!empty($robot['shared'])) {
          $partages[] = self::texteInventaire(isset($robot['nomRoborock']) ? $robot['nomRoborock'] : '');
        }
      } catch (Throwable $e) {
        $echecs++;
        log::add('jeeroborock', 'error', 'synchroniserEquipements : échec d\'enregistrement d\'un robot : ' . self::nettoyerPourLog(substr($e->getMessage(), 0, 256)));
      }
    }

    $nonSupportes = array();
    foreach ($nonSupportesBruts as $nonSupporte) {
      if (!is_array($nonSupporte)) {
        continue;
      }
      $nonSupportes[] = self::texteInventaire(isset($nonSupporte['nomRoborock']) ? $nonSupporte['nomRoborock'] : '');
    }

    if (isset($r['nbTotal']) && is_numeric($r['nbTotal']) && intval($r['nbTotal']) >= 0) {
      self::enregistrerInventaireCompte(intval($r['nbTotal']));
    }

    log::add('jeeroborock', 'info', 'Synchronisation des équipements : inventaire homedata, quota 40/jour - ' . $crees . ' créé(s), ' . $misAJour . ' mis à jour, ' . $echecs . ' échec(s), ' . count($nonSupportes) . ' non supporté(s)');

    return array(
      'crees'        => $crees,
      'misAJour'     => $misAJour,
      'echecs'       => $echecs,
      'nbTotal'      => isset($r['nbTotal']) ? intval($r['nbTotal']) : 0,
      'nonSupportes' => $nonSupportes,
      'partages'     => $partages,
    );
  }

  // Crée ou met à jour l'équipement Jeedom correspondant à un robot découvert. Retourne
  // 'cree' ou 'misAJour'. logicalId = duid (identité stable, insensible au renommage).
  private static function appliquerRobot($_robot) {
    $duid = trim((string) (isset($_robot['duid']) ? $_robot['duid'] : ''));
    if (!self::duidValide($duid)) {
      log::add('jeeroborock', 'warning', 'Robot ignoré : duid invalide : ' . self::nettoyerPourLog(substr($duid, 0, 128)));
      throw new Exception('duid invalide');
    }

    $eqLogic = eqLogic::byLogicalId($duid, 'jeeroborock');
    $creation = !is_object($eqLogic);

    if ($creation) {
      $eqLogic = new jeeroborock();
      $eqLogic->setEqType_name('jeeroborock');
      $eqLogic->setLogicalId($duid);
      $eqLogic->setName(self::texteInventaire(isset($_robot['nomRoborock']) ? $_robot['nomRoborock'] : ''));
      if ($eqLogic->getName() == '') {
        $eqLogic->setName(sprintf(__('Robot Roborock %s', __FILE__), substr($duid, 0, 8)));
      }
      $eqLogic->setIsEnable(1);
      $eqLogic->setIsVisible(1);
    }

    $eqLogic->setConfiguration('duid', self::texteInventaire($duid));
    $eqLogic->setConfiguration('model', self::texteInventaire(isset($_robot['model']) ? $_robot['model'] : ''));
    $eqLogic->setConfiguration('productName', self::texteInventaire(isset($_robot['productName']) ? $_robot['productName'] : ''));
    $eqLogic->setConfiguration('fv', self::texteInventaire(isset($_robot['fv']) ? $_robot['fv'] : ''));
    $eqLogic->setConfiguration('pv', self::texteInventaire(isset($_robot['pv']) ? $_robot['pv'] : ''));
    $eqLogic->setConfiguration('sn', self::texteInventaire(isset($_robot['sn']) ? $_robot['sn'] : ''));
    $eqLogic->setConfiguration('shared', !empty($_robot['shared']) ? 1 : 0);
    $eqLogic->setConfiguration('nomRoborock', self::texteInventaire(isset($_robot['nomRoborock']) ? $_robot['nomRoborock'] : ''));

    $eqLogic->save();

    return $creation ? 'cree' : 'misAJour';
  }

  // Valide un duid d'origine cloud avant usage comme logicalId et dans un log::add()
  // (ancres \A/\z, jamais ^/$ : cf. rappel UC03 sur la forge de ligne de log).
  private static function duidValide($_duid) {
    return preg_match('/\A[A-Za-z0-9_.:-]{4,128}\z/', $_duid) === 1;
  }

  // Neutralise une chaîne d'origine cloud avant log/DOM/base : nettoyerPourLog() + trim +
  // troncature.
  private static function texteInventaire($_valeur, $_longueurMax = self::LONGUEUR_MAX_TEXTE_INVENTAIRE) {
    $valeur = trim(self::nettoyerPourLog((string) $_valeur));
    return substr($valeur, 0, $_longueurMax);
  }

  // Persiste la session obtenue par jeeroborockDaemon::appeler('validerCode', ...). NE LEVE
  // JAMAIS : cette méthode est la seule frame PHP qui détient le blob userData ; une exception
  // levée depuis cette frame exposerait le secret via displayException() (arguments de frame).
  public static function enregistrerSession($_donnees) {
    if (!is_array($_donnees)) {
      log::add('jeeroborock', 'error', 'enregistrerSession : réponse du démon invalide');
      return false;
    }
    $userData = isset($_donnees['userData']) ? (string) $_donnees['userData'] : '';
    $baseUrl = isset($_donnees['baseUrl']) ? (string) $_donnees['baseUrl'] : '';

    $longueur = strlen($userData);
    if ($longueur < 64 || $longueur > self::LONGUEUR_MAX_USERDATA || !preg_match('/\A[A-Za-z0-9+\/]+={0,2}\z/', $userData)) {
      log::add('jeeroborock', 'error', 'enregistrerSession : userData reçu du démon non conforme');
      return false;
    }
    if (!preg_match('/\Ahttps:\/\/[A-Za-z0-9.-]+\.roborock\.com\z/', $baseUrl)) {
      // Dégradation silencieuse et sans conséquence : la découverte régionale la retrouvera.
      $baseUrl = '';
    }

    try {
      // baseUrl d'abord, userData ensuite : "compte lié" (estCompteLie()) ne devient vrai
      // qu'au tout dernier enregistrement.
      config::save('baseUrl', $baseUrl, 'jeeroborock');
      config::save('userData', $userData, 'jeeroborock');
      self::oublierInventaireCompte();
      return true;
    } catch (Throwable $e) {
      log::add('jeeroborock', 'error', 'enregistrerSession : échec de persistance, consultez la configuration du plugin');
      return false;
    }
  }

  // Délie la session : vide userData/baseUrl en configuration, prévient l'utilisateur, et
  // demande au démon d'oublier sa session en RAM. NE LEVE JAMAIS.
  //
  // Piège générique Jeedom (R-8 de la spec technique) : core/ajax/config.ajax.php n'appelle
  // JAMAIS session_write_close() avant d'invoquer les hooks preConfig_<clé> - le verrou de
  // session PHP est donc tenu ici. Sans le relâcher nous-mêmes, tout appel au démon fige
  // l'interface Jeedom pendant sa durée. Même précaution déjà en place dans deamon_start().
  private static function oublierSession() {
    try {
      config::save('userData', '', 'jeeroborock');
      config::save('baseUrl', '', 'jeeroborock');
    } catch (Throwable $e) {
      log::add('jeeroborock', 'error', 'oublierSession : échec de persistance : ' . $e->getMessage());
    }
    self::oublierInventaireCompte();

    log::add('jeeroborock', 'info', 'Compte Roborock délié (e-mail modifié)');
    message::removeAll('jeeroborock', 'session_deliee');
    message::add(
      'jeeroborock',
      __('L\'adresse e-mail du compte Roborock a changé : le compte a été délié, demandez un nouveau code de connexion.', __FILE__),
      '',
      'session_deliee'
    );

    if (session_status() === PHP_SESSION_ACTIVE) {
      session_write_close();
    }

    try {
      jeeroborockDaemon::appeler('restaurerSession', array('userData' => '', 'baseUrl' => '', 'email' => ''), jeeroborockDaemon::TIMEOUT_SESSION);
    } catch (Throwable $e) {
      log::add('jeeroborock', 'warning', 'oublierSession : le démon n\'a pas pu être notifié : ' . $e->getMessage());
    }
  }

  // Repousse au démon la session persistée en configuration plugin (D-04-5) : point de
  // passage UNIQUE de tous les (re)démarrages du démon. Aucun appel réseau, aucun quota
  // consommé (D-04-7) : ne fait que recharger en RAM ce que le PHP a déjà persisté.
  // NE LEVE JAMAIS.
  private static function restaurerSessionDemon() {
    if (!self::estCompteLie()) {
      return;
    }
    try {
      jeeroborockDaemon::appeler(
        'restaurerSession',
        array('userData' => self::getUserData(), 'baseUrl' => self::getBaseUrlCompte(), 'email' => trim((string) config::byKey('email', 'jeeroborock', ''))),
        jeeroborockDaemon::TIMEOUT_SESSION
      );
    } catch (Throwable $e) {
      // Jamais de message::add ici : peut être déclenché par le cron, sans utilisateur en train de regarder.
      log::add('jeeroborock', 'warning', 'restaurerSessionDemon : ' . $e->getMessage());
    }
  }

  // Port du canal HTTP local avec le démon. Normalisé à l'écriture, valide la plage, et
  // signale un éventuel conflit de port déjà occupé sur la machine (non bloquant).
  public static function preConfig_portDemonHttp($_value) {
    $_value = trim($_value);
    if ($_value == '') {
      return (string) self::PORT_DEMON_HTTP_DEFAUT;
    }
    if (!ctype_digit($_value)) {
      throw new Exception(__('Le port du canal local doit être un nombre entier compris entre 1024 et 65535.', __FILE__));
    }
    $port = intval($_value);
    if ($port < 1024 || $port > 65535) {
      throw new Exception(__('Le port du canal local doit être un nombre entier compris entre 1024 et 65535.', __FILE__));
    }
    if ($port != self::getPortDemonHttp()) {
      self::signalerPortOccupe($port);
    }
    return (string) $port;
  }

  // Unique point de lecture du port dans tout le plugin. Retombe sur la valeur par défaut si
  // la configuration est absente, non numérique ou hors plage.
  public static function getPortDemonHttp() {
    $port = intval(config::byKey('portDemonHttp', 'jeeroborock', self::PORT_DEMON_HTTP_DEFAUT));
    if ($port < 1024 || $port > 65535) {
      return self::PORT_DEMON_HTTP_DEFAUT;
    }
    return $port;
  }

  // Sonde non intrusive : détecte un service déjà en écoute sur 127.0.0.1:$_port.
  // Confort, pas garantie (cf. risques R4 de la spec technique UC01).
  public static function estPortLocalOccupe($_port) {
    if (!function_exists('fsockopen')) {
      log::add('jeeroborock', 'debug', 'fsockopen indisponible (disable_functions), sonde de port ignoree');
      return false;
    }
    $connexion = @fsockopen('127.0.0.1', intval($_port), $errno, $errstr, 0.3);
    if ($connexion) {
      fclose($connexion);
      return true;
    }
    return false;
  }

  // Notifie un conflit de port (message centre de messages + log), sans bloquer l'enregistrement.
  private static function signalerPortOccupe($_port) {
    message::removeAll('jeeroborock', 'port_occupe');
    if (self::estPortLocalOccupe($_port)) {
      log::add('jeeroborock', 'warning', 'Port du canal local ' . $_port . ' deja occupe sur cette machine');
      message::add('jeeroborock', sprintf(__('Le port du canal local %s est déjà utilisé sur cette machine, veuillez en choisir un autre.', __FILE__), $_port), '', 'port_occupe');
    }
  }

  /*     * *****************Cycle de vie du démon**************************** */

  // Etat du démon pour l'interface standard Jeedom (modale démon, plugin::checkDeamon).
  // NE LEVE JAMAIS (appelée sans try/catch par le core) et ne consulte jamais email/userData
  // (le démon doit être "actif" sans compte Roborock configuré).
  public static function deamon_info() {
    $retour = array(
      'log' => 'jeeroborock_demon',
      'launchable' => 'nok',
      'launchable_message' => '',
      'state' => 'nok',
    );
    try {
      if (self::pidDemonActif() > 0) {
        $retour['state'] = 'ok';
        $retour['launchable'] = 'ok';
        return $retour;
      }
      $cause = self::causeNonLancable();
      if ($cause == '') {
        $retour['launchable'] = 'ok';
      } else {
        $retour['launchable_message'] = $cause;
      }
      return $retour;
    } catch (Throwable $e) {
      log::add('jeeroborock', 'error', 'deamon_info en erreur : ' . $e->getMessage());
      return array(
        'log' => 'jeeroborock_demon',
        'launchable' => 'nok',
        'launchable_message' => __('Impossible de déterminer l\'état du démon, consultez le log du plugin.', __FILE__),
        'state' => 'nok',
      );
    }
  }

  // Démarre le démon Python. Appelée par le core uniquement si launchable == 'ok' et
  // state == 'nok', et enveloppée dans un catch qui se contente d'un log::add(error) :
  // la cause de l'échec doit donc être portée par launchable_message + message::add.
  public static function deamon_start() {
    self::deamon_stop();

    $infos = self::deamon_info();
    if ($infos['launchable'] != 'ok') {
      throw new Exception(sprintf(__('Le démon ne peut pas être démarré : %s', __FILE__), $infos['launchable_message']));
    }

    $port = self::getPortDemonHttp();
    $apikey = jeedom::getApiKey('jeeroborock');
    $callback = network::getNetworkAccess('internal', 'http:127.0.0.1:port:comp') . '/plugins/jeeroborock/core/php/jeeJeeroborock.php';
    $commande = system::getCmdPython3('jeeroborock')
      . realpath(__DIR__ . '/../../resources/demond') . '/jeeroborockd.py'
      . ' --loglevel ' . log::convertLogLevel(log::getLogLevel('jeeroborock'))
      . ' --port ' . escapeshellarg($port)
      . ' --callback ' . escapeshellarg($callback)
      . ' --apikey ' . escapeshellarg($apikey)
      . ' --pid ' . self::cheminFichierPid();

    // L'apikey est masquée dans le log : elle ne doit apparaître nulle part en clair.
    // On cherche la forme ECHAPPEE, la seule réellement présente dans la commande : chercher la
    // forme brute échouerait silencieusement dès que escapeshellarg() la fragmente (apostrophe).
    log::add('jeeroborock', 'debug', 'Démarrage du démon : ' . str_replace(escapeshellarg($apikey), escapeshellarg('********'), $commande));

    file_put_contents(self::cheminFichierPort(), $port);

    exec($commande . ' >> ' . log::getPathToLog('jeeroborock_demon') . ' 2>&1 &');

    // Le hook est aussi appelé depuis le cron, sans session active.
    if (session_status() === PHP_SESSION_ACTIVE) {
      session_write_close();
    }

    for ($compteur = 0; $compteur < self::DELAI_DEMARRAGE_DEMON; $compteur++) {
      sleep(1);
      $etat = self::deamon_info();
      if ($etat['state'] == 'ok') {
        self::restaurerSessionDemon();
        message::removeAll('jeeroborock', 'demarrageDemon');
        return true;
      }
    }

    log::add('jeeroborock', 'error', 'Le démon n\'a pas démarré dans le délai de ' . self::DELAI_DEMARRAGE_DEMON . ' secondes');
    $lienLog = '<a href="index.php?v=d&p=log&log=jeeroborock_demon" target="_blank">' . __('Log du démon', __FILE__) . '</a>';
    message::add(
      'jeeroborock',
      __('Le démon n\'a pas démarré dans le temps imparti, consultez le log du démon.', __FILE__) . ' ' . $lienLog,
      '',
      'demarrageDemon'
    );
    return false;
  }

  // Arrête le démon Python. Ne lève jamais (appelée par le core sans try/catch).
  public static function deamon_stop() {
    try {
      $pid = self::pidDemonActif();
      if ($pid > 0) {
        system::kill($pid);
      }
      // Filet pour un démon mort avant d'avoir écrit son PID.
      system::kill('jeeroborockd.py');
      @unlink(self::cheminFichierPid());
      @unlink(self::cheminFichierPort());
      sleep(1);
    } catch (Throwable $e) {
      log::add('jeeroborock', 'error', 'deamon_stop en erreur : ' . $e->getMessage());
    }
  }

  // Redémarre le démon si le port du canal local change réellement. Le garde est
  // indispensable : postConfig_<clé> est appelé à CHAQUE enregistrement de la page de
  // configuration, même quand la valeur ne change pas.
  public static function postConfig_portDemonHttp($_value) {
    $cheminPort = self::cheminFichierPort();
    if (!file_exists($cheminPort)) {
      return;
    }
    if (trim(file_get_contents($cheminPort)) == trim($_value)) {
      return;
    }
    log::add('jeeroborock', 'info', 'Port du canal local modifié, redémarrage du démon');
    self::deamon_stop();
  }

  // Appelée par le callback démon -> Jeedom (core/php/jeeJeeroborock.php) à chaque démarrage
  // du démon : consigne la version de python-roborock détectée, avertit si sa majeure
  // dépasse celle validée par le plugin.
  public static function traiterVersionLibrairie($_donnees) {
    if (!is_array($_donnees)) {
      return;
    }
    $version = isset($_donnees['version']) ? substr(trim((string) $_donnees['version']), 0, 32) : '';
    $versionPourLog = self::nettoyerPourLog($version);
    $majeureValidee = isset($_donnees['majeureValidee']) ? intval($_donnees['majeureValidee']) : 0;
    $avertissement = !empty($_donnees['avertissement']);

    log::add('jeeroborock', 'info', 'Démon démarré avec python-roborock ' . $versionPourLog);

    if ($avertissement) {
      log::add('jeeroborock', 'warning', 'Version de python-roborock (' . $versionPourLog . ') plus recente que la majeure validee (' . $majeureValidee . ')');
      // Echappement HTML : message::add() rend son contenu en HTML brut cote UI Jeedom.
      $versionEchappee = htmlspecialchars($version, ENT_QUOTES, 'UTF-8');
      message::add(
        'jeeroborock',
        sprintf(__('La version %s de python-roborock est plus récente que la version majeure validée par le plugin (%s) : le fonctionnement n\'est pas garanti.', __FILE__), $versionEchappee, $majeureValidee),
        '',
        'version_librairie'
      );
    } else {
      message::removeAll('jeeroborock', 'version_librairie');
    }
  }

  // Retire les caracteres de controle (forge de lignes de log) d'une valeur d'origine externe
  // et garantit un UTF-8 valide avant journalisation. A utiliser sur toute donnee externe
  // (callback demon, entree utilisateur) injectee dans un log::add().
  public static function nettoyerPourLog($_valeur) {
    $valeur = (string) $_valeur;
    $valeur = preg_replace('/[\x00-\x1F\x7F]/', '', $valeur);
    if (!mb_check_encoding($valeur, 'UTF-8')) {
      // mb_scrub, et non mb_convert_encoding de UTF-8 vers UTF-8 : le résultat de ce dernier sur
      // une séquence invalide dépend du réglage mbstring.substitute_character de l'environnement.
      $valeur = mb_scrub($valeur, 'UTF-8');
    }
    return $valeur;
  }

  // Chemin du fichier PID écrit par le démon (répertoire temporaire dédié au plugin).
  private static function cheminFichierPid() {
    return jeedom::getTmpFolder('jeeroborock') . '/demon.pid';
  }

  // Chemin du fichier mémorisant le dernier port utilisé pour lancer le démon.
  private static function cheminFichierPort() {
    return jeedom::getTmpFolder('jeeroborock') . '/demon.port';
  }

  // Retourne le PID du démon actif (0 si aucun). Nettoie un fichier PID périmé/recyclé.
  private static function pidDemonActif() {
    $cheminPid = self::cheminFichierPid();
    if (!file_exists($cheminPid)) {
      return 0;
    }
    $pid = intval(trim(file_get_contents($cheminPid)));
    if ($pid <= 0) {
      @unlink($cheminPid);
      return 0;
    }
    if (is_dir('/proc/' . $pid)) {
      $cmdline = @file_get_contents('/proc/' . $pid . '/cmdline');
      $cheminScriptAttendu = realpath(__DIR__ . '/../../resources/demond') . '/jeeroborockd.py';
      if ($cmdline !== false && strpos($cmdline, $cheminScriptAttendu) !== false) {
        return $pid;
      }
      @unlink($cheminPid);
      return 0;
    }
    // Repli si /proc/<pid> est absent (process mort, ou systeme sans /proc) : posix_getsid confirme que le PID existe.
    if (function_exists('posix_getsid') && @posix_getsid($pid) !== false) {
      return $pid;
    }
    @unlink($cheminPid);
    return 0;
  }

  // Cause de non-lancabilité du démon ('' si lançable). Premier message non vide gagne.
  private static function causeNonLancable() {
    if (file_exists('/tmp/jeedom_install_in_progress_jeeroborock')) {
      return __('Les dépendances Python sont en cours d\'installation.', __FILE__);
    }

    $cheminInterpreteur = trim(system::getCmdPython3('jeeroborock'));
    if (strpos($cheminInterpreteur, '/') === 0) {
      $cheminVenv = system::getPython3VenvDir('jeeroborock') . '/bin/python3';
      if (!file_exists($cheminVenv)) {
        log::add('jeeroborock', 'debug', 'Interpreteur Python attendu introuvable : ' . $cheminVenv);
        return __('Les dépendances Python ne sont pas installées.', __FILE__);
      }
    }

    if (self::estPortLocalOccupe(self::getPortDemonHttp())) {
      return sprintf(__('Le port du canal local %s est déjà utilisé sur cette machine, veuillez en choisir un autre.', __FILE__), self::getPortDemonHttp());
    }

    return '';
  }

  /*
  * Fonction exécutée automatiquement toutes les minutes par Jeedom
  public static function cron() {}
  */

  /*
  * Fonction exécutée automatiquement toutes les 5 minutes par Jeedom
  public static function cron5() {}
  */

  /*
  * Fonction exécutée automatiquement toutes les 10 minutes par Jeedom
  public static function cron10() {}
  */

  /*
  * Fonction exécutée automatiquement toutes les 15 minutes par Jeedom
  public static function cron15() {}
  */

  /*
  * Fonction exécutée automatiquement toutes les 30 minutes par Jeedom
  public static function cron30() {}
  */

  /*
  * Fonction exécutée automatiquement toutes les heures par Jeedom
  public static function cronHourly() {}
  */

  /*
  * Fonction exécutée automatiquement tous les jours par Jeedom
  public static function cronDaily() {}
  */

  /*
  * Permet de déclencher une action avant modification d'une variable de configuration du plugin
  * Exemple avec la variable "param3"
  public static function preConfig_param3( $value ) {
    // do some checks or modify on $value
    return $value;
  }
  */

  /*
  * Permet de déclencher une action après modification d'une variable de configuration du plugin
  * Exemple avec la variable "param3"
  public static function postConfig_param3($value) {
    // no return value
  }
  */

  /*
   * Permet d'indiquer des éléments supplémentaires à remonter dans les informations de configuration
   * lors de la création semi-automatique d'un post sur le forum community
   public static function getConfigForCommunity() {
      // Cette function doit retourner des infos complémentataires sous la forme d'un
      // string contenant les infos formatées en HTML.
      return "les infos essentiel de mon plugin";
   }
   */

  /*     * *********************Méthodes d'instance************************* */

  // Fonction exécutée automatiquement avant la création de l'équipement
  public function preInsert() {
  }

  // Fonction exécutée automatiquement après la création de l'équipement
  public function postInsert() {
  }

  // Fonction exécutée automatiquement avant la mise à jour de l'équipement
  public function preUpdate() {
  }

  // Fonction exécutée automatiquement après la mise à jour de l'équipement
  public function postUpdate() {
  }

  // Fonction exécutée automatiquement avant la sauvegarde (création ou mise à jour) de l'équipement
  public function preSave() {
  }

  // Fonction exécutée automatiquement après la sauvegarde (création ou mise à jour) de l'équipement
  public function postSave() {
  }

  // Fonction exécutée automatiquement avant la suppression de l'équipement
  public function preRemove() {
  }

  // Fonction exécutée automatiquement après la suppression de l'équipement
  public function postRemove() {
  }

  /*
  * Permet de crypter/décrypter automatiquement des champs de configuration des équipements
  * Exemple avec le champ "Mot de passe" (password)
  public function decrypt() {
    $this->setConfiguration('password', utils::decrypt($this->getConfiguration('password')));
  }
  public function encrypt() {
    $this->setConfiguration('password', utils::encrypt($this->getConfiguration('password')));
  }
  */

  /*
  * Permet de modifier l'affichage du widget (également utilisable par les commandes)
  public function toHtml($_version = 'dashboard') {}
  */

  /*     * **********************Getteur Setteur*************************** */
}

class jeeroborockCmd extends cmd {
  /*     * *************************Attributs****************************** */

  /*
  public static $_widgetPossibility = array();
  */

  /*     * ***********************Methode static*************************** */


  /*     * *********************Methode d'instance************************* */

  /*
  * Permet d'empêcher la suppression des commandes même si elles ne sont pas dans la nouvelle configuration de l'équipement envoyé en JS
  public function dontRemoveCmd() {
    return true;
  }
  */

  // Exécution d'une commande
  public function execute($_options = array()) {
  }

  /*     * **********************Getteur Setteur*************************** */
}
