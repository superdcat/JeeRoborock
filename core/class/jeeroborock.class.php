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

  // Lecture de l'état courant d'un robot (UC07). Troncature défensive des libellés
  // d'état/erreur d'origine démon avant base et DOM (le démon les compose déjà, mais
  // ils transitent par le canal).
  const LONGUEUR_MAX_LIBELLE_ETAT = 128;

  // Routines ("usages", UC09). Chemin HTTPS pur, disjoint du DeviceManager/MQTT (AC7).
  const PREFIXE_CMD_ROUTINE          = 'routine_';
  const NB_MAX_ROUTINES              = 64;
  const LONGUEUR_MAX_NOM_ROUTINE     = 127;  // aligne sur la troncature de cmd::setName
  const ORDRE_BASE_ROUTINES          = 30;   // info 0-11, reserve 12-19, actions 20-25
  const DELAI_MIN_SYNCHRO_ROUTINES   = 60;   // s, garde anti-rafale (D-09-3)
  const DUREE_CACHE_SYNCHRO_ROUTINES = 300;  // s, TTL de l'horodatage
  const CLE_CACHE_SYNCHRO_ROUTINES   = 'jeeroborock::synchroRoutines::';

  // Rafraîchissement temps réel et fraîcheur (UC10). Le démon porte la cadence et le push ;
  // le cron PHP se limite à un chien de garde de fraîcheur (chemin de secours, coût nul en
  // nominal, cf. cron()).
  const DELAI_FRAICHEUR_S               = 180;   // s, garde avant bascule déconnecté
  const DELAI_MIN_RELANCE_SUPERVISION   = 600;   // s, anti-rafale du réarmement
  const DUREE_CACHE_RELANCE_SUPERVISION = 1800;  // s, TTL de l'horodatage
  const CLE_CACHE_RELANCE_SUPERVISION   = 'jeeroborock::relanceSupervision';

  // Robustesse, quotas et ré-authentification (UC11). L'état absorbant vit en
  // configuration plugin (survit au redémarrage du démon, n'expire pas tout seul, cf.
  // reauthRequise()). Le reste (sonde de compte, backoff de relance du démon) vit en
  // cache : ce sont des compteurs d'incident, qui doivent pouvoir s'oublier tout seuls.
  const CLE_CONFIG_REAUTH = 'reauthRequise';

  // Sonde de session sans quota (etatCompte, avecInventaire=false), déclenchée depuis
  // cron() seulement quand tous les équipements actifs sont périmés. Escalade à chaque
  // sonde non concluante, remise à zéro au premier succès.
  const DELAIS_SONDE_COMPTE_S      = array(900, 1800, 3600, 7200, 21600);
  const DUREE_CACHE_SONDE_COMPTE   = 604800; // s, 7 jours
  const CLE_CACHE_SONDE_COMPTE     = 'jeeroborock::sondeCompte';

  // Backoff du redémarrage du démon (AC7). Un démon qui ne survit pas
  // DUREE_VIE_MIN_DEMON_S n'est pas considéré comme "démarré" : le compteur d'échecs
  // n'est remis à zéro que par l'observation d'un démon sain, jamais par le seul
  // écoulement du temps.
  const DUREE_VIE_MIN_DEMON_S        = 120;  // s
  const DELAIS_RELANCE_DEMON_S       = array(60, 300, 900, 1800); // s
  const CLE_CACHE_DEMARRAGE_DEMON    = 'jeeroborock::demarrageDemon';
  const DUREE_CACHE_DEMARRAGE_DEMON  = 86400; // s

  // Consommables et usure (UC12). Une seule table porte a la fois l'info et l'action
  // (impossible de creer un reset orphelin, cf. definitionsConsommables()).
  // UC12 a consommé intégralement sa plage (info 12-16) : ce n'est PLUS une réserve
  // UC12-15, cf. ORDRE_BASE_STATION ci-dessous (UC13 est repartie sur une base libre).
  const ORDRE_BASE_CONSOMMABLES       = 12;    // info 12-16 (occupée en totalité par UC12)
  const ORDRE_BASE_RESET_CONSOMMABLES = 100;   // actions 100-104 (routines occupent 30-93)
  const PREFIXE_CMD_RESET_CONSO       = 'reset_';

  // État de la station d'accueil (UC13). Plages réellement occupées à ce stade : info
  // 0-11 (UC07), info 12-16 (UC12), actions 20-25 (UC08), actions routines 30-93
  // (UC09), actions reset 100-104 (UC12) — 17-19 ne laissait que 3 places pour 6
  // commandes, d'où une base franchement libre, avec de la marge pour UC14 (120) et
  // UC15 (130). L'ordre n'affecte que l'affichage, n'est jamais réécrit sur une
  // commande existante.
  const ORDRE_BASE_STATION = 110;   // info 110-115

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

  /*     * ***********************État absorbant "ré-authentification" (UC11)****** */

  // Source de vérité de l'état absorbant "ré-authentification requise" (AC1/AC2). En
  // configuration plugin, PAS en cache : il ne doit pas expirer tout seul (un TTL qui
  // expire relancerait les tentatives), et il doit survivre au redémarrage du démon
  // (déclenché chaque minute par plugin::checkDeamon, ce qui viderait un contexte['...']).
  // Comparaison stricte à '1' : neutralise la divergence byKey/byKeys sur la chaîne vide.
  // NE LÈVE JAMAIS.
  public static function reauthRequise() {
    return trim((string) config::byKey(self::CLE_CONFIG_REAUTH, 'jeeroborock', '')) === '1';
  }

  // Lève le drapeau (AC1). Idempotente (sort si déjà levé) et non ré-entrante (un appel
  // en cours n'en déclenche pas un second, notamment depuis le callback du démon).
  // NE LÈVE JAMAIS.
  public static function signalerReauthRequise($_origine) {
    static $enCours = false;
    if ($enCours) {
      return;
    }
    $enCours = true;
    try {
      if (self::reauthRequise()) {
        return;
      }
      $origine = preg_match('/\A[A-Z_]{1,16}\z/', (string) $_origine) === 1 ? $_origine : 'INCONNUE';

      try {
        config::save(self::CLE_CONFIG_REAUTH, '1', 'jeeroborock');
      } catch (Throwable $e) {
        log::add('jeeroborock', 'error', 'signalerReauthRequise : échec de persistance : ' . $e->getMessage());
      }

      log::add('jeeroborock', 'warning', 'Ré-authentification requise (origine : ' . $origine . ')');

      message::removeAll('jeeroborock', 'reauth');
      message::add(
        'jeeroborock',
        __('Le compte Roborock doit être ré-authentifié : la session a expiré ou a été révoquée. Ouvrez la configuration du plugin et demandez un nouveau code de connexion.', __FILE__)
          . ' ' . __('Si vous pensez qu\'il s\'agit d\'une erreur, le bouton "Tester la connexion" revérifie la session sans consommer de quota.', __FILE__),
        '',
        'reauth'
      );

      try {
        // Rend le démon quiescent (session vide) : aucune opération nouvelle, réutilise
        // le contrat déjà en place (restaurerSession avec userData='').
        jeeroborockDaemon::appeler('restaurerSession', array('userData' => '', 'baseUrl' => '', 'email' => ''), jeeroborockDaemon::TIMEOUT_SESSION);
      } catch (Throwable $e) {
        log::add('jeeroborock', 'warning', 'signalerReauthRequise : le démon n\'a pas pu être notifié : ' . $e->getMessage());
      }
    } catch (Throwable $e) {
      log::add('jeeroborock', 'error', 'signalerReauthRequise en erreur : ' . $e->getMessage());
    } finally {
      $enCours = false;
    }
  }

  // Efface le drapeau (AC2, guérison via UC04 ou "Tester la connexion"). NE LÈVE JAMAIS.
  public static function effacerReauthRequise() {
    try {
      // Valeur vide = valeur par défaut -> supprime la ligne en base (évite tout écart
      // de comparaison lâche entre 0 et chaîne vide selon la version de PHP).
      config::save(self::CLE_CONFIG_REAUTH, '', 'jeeroborock');
    } catch (Throwable $e) {
      log::add('jeeroborock', 'error', 'effacerReauthRequise : échec de persistance : ' . $e->getMessage());
    }
    message::removeAll('jeeroborock', 'reauth');
    self::oublierSondeCompte();
  }

  // Point d'entrée du lot 'compte' poussé par le superviseur du démon (callback
  // jeedom_com). NE LÈVE JAMAIS (appelée depuis un point d'entrée externe).
  public static function traiterEtatCompte($_donnees) {
    try {
      if (!is_array($_donnees) || empty($_donnees['reauthRequise'])) {
        return;
      }
      if (!self::estCompteLie()) {
        return;
      }
      // Le motif transmis par le démon (ex. 'AUTH_EXPIRED') n'est utilisé QUE pour le
      // log de diagnostic, jamais comme origine passée à signalerReauthRequise() (valeur
      // fixe 'DEMON' - le motif n'est pas une des origines attendues de cette méthode).
      $motif = isset($_donnees['motif']) ? (string) $_donnees['motif'] : '';
      if (preg_match('/\A[A-Z_]{1,32}\z/', $motif) !== 1) {
        $motif = '';
      }
      if ($motif != '') {
        log::add('jeeroborock', 'debug', 'traiterEtatCompte : motif transmis par le démon : ' . $motif);
      }
      self::signalerReauthRequise('DEMON');
    } catch (Throwable $e) {
      log::add('jeeroborock', 'error', 'traiterEtatCompte en erreur : ' . $e->getMessage());
    }
  }

  // Lit le compteur de la sonde de compte (rang d'escalade + horodatage du dernier
  // essai). Retombe sur un compteur neutre si absent ou non conforme. NE LÈVE JAMAIS.
  private static function sondeCompteEtat() {
    try {
      $valeur = cache::byKey(self::CLE_CACHE_SONDE_COMPTE)->getValue('');
      if (is_array($valeur) && isset($valeur['rang'], $valeur['horodatage'])) {
        return array('rang' => intval($valeur['rang']), 'horodatage' => intval($valeur['horodatage']));
      }
    } catch (Throwable $e) {
      log::add('jeeroborock', 'warning', 'sondeCompteEtat : échec de lecture du cache : ' . $e->getMessage());
    }
    return array('rang' => 0, 'horodatage' => 0);
  }

  // Oublie la progression de la sonde de compte (guérison, AC2). NE LÈVE JAMAIS.
  private static function oublierSondeCompte() {
    try {
      cache::delete(self::CLE_CACHE_SONDE_COMPTE);
    } catch (Throwable $e) {
      log::add('jeeroborock', 'warning', 'oublierSondeCompte : échec de purge : ' . $e->getMessage());
    }
  }

  // Vrai si le délai d'escalade courant de la sonde de compte est écoulé (ou si aucune
  // sonde n'a encore été tentée). NE LÈVE JAMAIS.
  private static function sondeCompteAFaire() {
    $etat = self::sondeCompteEtat();
    if ($etat['horodatage'] == 0) {
      return true;
    }
    $rang = min($etat['rang'], count(self::DELAIS_SONDE_COMPTE_S) - 1);
    return (time() - $etat['horodatage']) >= self::DELAIS_SONDE_COMPTE_S[$rang];
  }

  // Effectue la sonde de session sans quota (etatCompte, avecInventaire=false forcé) et
  // fait progresser l'escalade sur tout verdict non concluant. Retourne 'OK',
  // 'AUTH_EXPIRED' ou 'INDISPONIBLE'. NE LÈVE JAMAIS.
  private static function sonderCompte() {
    try {
      jeeroborockDaemon::appeler(
        'etatCompte',
        array('userData' => self::getUserData(), 'baseUrl' => self::getBaseUrlCompte(), 'email' => self::getEmailCompte(), 'avecInventaire' => false),
        jeeroborockDaemon::TIMEOUT_COMPTE
      );
      self::oublierSondeCompte();
      return 'OK';
    } catch (jeeroborockException $e) {
      if ($e->getCodeErreur() === 'AUTH_EXPIRED') {
        // Le drapeau est déjà levé par l'entonnoir de jeeroborockDaemon::appeler().
        self::avancerSondeCompte();
        return 'AUTH_EXPIRED';
      }
      log::add('jeeroborock', 'info', 'sonderCompte : verdict indisponible (' . $e->getCodeErreur() . ')');
      self::avancerSondeCompte();
      return 'INDISPONIBLE';
    } catch (Throwable $e) {
      log::add('jeeroborock', 'warning', 'sonderCompte : échec inattendu : ' . $e->getMessage());
      self::avancerSondeCompte();
      return 'INDISPONIBLE';
    }
  }

  // Fait progresser le rang d'escalade de la sonde de compte. NE LÈVE JAMAIS.
  private static function avancerSondeCompte() {
    try {
      $etat = self::sondeCompteEtat();
      cache::set(self::CLE_CACHE_SONDE_COMPTE, array('rang' => $etat['rang'] + 1, 'horodatage' => time()), self::DUREE_CACHE_SONDE_COMPTE);
    } catch (Throwable $e) {
      log::add('jeeroborock', 'warning', 'avancerSondeCompte : échec de mise en cache : ' . $e->getMessage());
    }
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
    if (self::reauthRequise()) {
      throw jeeroborockDaemon::erreurLocale('AUTH_EXPIRED');
    }
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

  /*     * ***********************Commandes d'état (UC07)*********************** */

  // Table statique des 18 commandes d'information posées par rafraichirEtat().
  // Littérales __() DANS la table (jamais __($variable) au point d'usage, l'extraction
  // i18n est un scan statique). 'min'/'max' absents = pas de bornes (batterie et
  // avancement seulement).
  //
  // UC13 (état de la station d'accueil) ajoute les 6 dernières entrées (station_*).
  // generic = '' sur les 6 : le cœur Jeedom n'expose aucun type générique pour un
  // manque d'eau, un niveau de réservoir ou un défaut d'appareil ; les seuls types
  // liés à l'eau (FLOOD, WATER_LEAK) décrivent la PRÉSENCE d'eau, l'inverse sémantique
  // exact de « manque d'eau » — les poser ferait remonter le robot comme détecteur
  // d'inondation auprès du cœur, de l'application mobile et des plugins agrégateurs.
  private static function definitionsCommandes() {
    return array(
      'etat'             => array('nom' => __('État', __FILE__), 'subType' => 'string', 'unite' => '', 'generic' => '', 'visible' => 1, 'historise' => 0, 'ordre' => 0),
      'etat_code'        => array('nom' => __('Code d\'état', __FILE__), 'subType' => 'numeric', 'unite' => '', 'generic' => '', 'visible' => 0, 'historise' => 0, 'ordre' => 1),
      'batterie'         => array('nom' => __('Batterie', __FILE__), 'subType' => 'numeric', 'unite' => '%', 'generic' => 'BATTERY', 'visible' => 1, 'historise' => 1, 'ordre' => 2, 'min' => 0, 'max' => 100),
      'en_nettoyage'     => array('nom' => __('En nettoyage', __FILE__), 'subType' => 'binary', 'unite' => '', 'generic' => '', 'visible' => 1, 'historise' => 0, 'ordre' => 3),
      'erreur'           => array('nom' => __('Erreur', __FILE__), 'subType' => 'string', 'unite' => '', 'generic' => '', 'visible' => 1, 'historise' => 0, 'ordre' => 4),
      'erreur_code'      => array('nom' => __('Code d\'erreur', __FILE__), 'subType' => 'numeric', 'unite' => '', 'generic' => '', 'visible' => 0, 'historise' => 0, 'ordre' => 5),
      'surface_nettoyee' => array('nom' => __('Surface nettoyée', __FILE__), 'subType' => 'numeric', 'unite' => 'm²', 'generic' => '', 'visible' => 1, 'historise' => 0, 'ordre' => 6),
      'duree_nettoyage'  => array('nom' => __('Durée de nettoyage', __FILE__), 'subType' => 'numeric', 'unite' => 'min', 'generic' => '', 'visible' => 1, 'historise' => 0, 'ordre' => 7),
      'avancement'       => array('nom' => __('Avancement', __FILE__), 'subType' => 'numeric', 'unite' => '%', 'generic' => '', 'visible' => 1, 'historise' => 0, 'ordre' => 8, 'min' => 0, 'max' => 100),
      'en_ligne'         => array('nom' => __('En ligne', __FILE__), 'subType' => 'binary', 'unite' => '', 'generic' => '', 'visible' => 1, 'historise' => 0, 'ordre' => 9),
      'connecte'         => array('nom' => __('Connecté', __FILE__), 'subType' => 'binary', 'unite' => '', 'generic' => '', 'visible' => 1, 'historise' => 0, 'ordre' => 10),
      'derniere_maj'     => array('nom' => __('Dernière mise à jour', __FILE__), 'subType' => 'string', 'unite' => '', 'generic' => '', 'visible' => 1, 'historise' => 0, 'ordre' => 11),
      'station_vidage'      => array('nom' => __('État vidage poussière', __FILE__), 'subType' => 'string', 'unite' => '', 'generic' => '', 'visible' => 1, 'historise' => 0, 'ordre' => self::ORDRE_BASE_STATION),
      'station_lavage'      => array('nom' => __('État lavage serpillière', __FILE__), 'subType' => 'string', 'unite' => '', 'generic' => '', 'visible' => 1, 'historise' => 0, 'ordre' => self::ORDRE_BASE_STATION + 1),
      'station_sechage'     => array('nom' => __('État séchage serpillière', __FILE__), 'subType' => 'string', 'unite' => '', 'generic' => '', 'visible' => 1, 'historise' => 0, 'ordre' => self::ORDRE_BASE_STATION + 2),
      'station_erreur'      => array('nom' => __('Erreur station', __FILE__), 'subType' => 'string', 'unite' => '', 'generic' => '', 'visible' => 1, 'historise' => 0, 'ordre' => self::ORDRE_BASE_STATION + 3),
      'station_erreur_code' => array('nom' => __('Code d\'erreur station', __FILE__), 'subType' => 'numeric', 'unite' => '', 'generic' => '', 'visible' => 0, 'historise' => 0, 'ordre' => self::ORDRE_BASE_STATION + 4),
      'station_manque_eau'  => array('nom' => __('Manque d\'eau', __FILE__), 'subType' => 'binary', 'unite' => '', 'generic' => '', 'visible' => 1, 'historise' => 0, 'ordre' => self::ORDRE_BASE_STATION + 5),
    );
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
      // qu'au tout dernier enregistrement. Effacement du drapeau AVANT ce dernier
      // enregistrement, dans le même try (UC11, AC2) : rend structurellement impossible
      // "nouvelle session persistée sans drapeau effacé".
      config::save('baseUrl', $baseUrl, 'jeeroborock');
      self::effacerReauthRequise();
      config::save('userData', $userData, 'jeeroborock');
      self::oublierInventaireCompte();
      // Arme le suivi temps réel (UC10) : le verrou de session est déjà relâché par
      // l'AJAX appelant, restaurerSessionDemon() relit elle-même la configuration
      // (le blob userData n'entre pas en argument d'une nouvelle frame).
      self::restaurerSessionDemon();
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
    // Le compte change : un drapeau "ré-authentification requise" hérité de l'ancien
    // compte n'a plus de sens (UC11).
    self::effacerReauthRequise();

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
    // UC11/AC1 : point de passage UNIQUE de tous les réarmements (deamon_start(), cron(),
    // enregistrerSession()) - tant que le drapeau est levé, on ne relance jamais le
    // superviseur (ce qui reconstruirait un DeviceManager et consommerait un homedata).
    if (self::reauthRequise()) {
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
        // UC11/AC7 : le compteur d'échecs de démarrage n'est remis à zéro QUE par
        // l'observation d'un démon sain ayant survécu DUREE_VIE_MIN_DEMON_S - jamais par
        // le seul écoulement du temps.
        $compteur = self::compteurDemarrages();
        if ($compteur['echecs'] > 0 && (time() - $compteur['horodatage']) >= self::DUREE_VIE_MIN_DEMON_S) {
          self::oublierDemarragesDemon();
        }
        return $retour;
      }

      $cause = self::causeNonLancable();
      if ($cause != '') {
        $retour['launchable_message'] = $cause;
        return $retour;
      }

      // UC11/AC7 : backoff de relance - consulté seulement quand causeNonLancable() ne
      // bloque déjà pas (elle reste prioritaire). Un démon durablement cassé n'est donc
      // pas relancé en boucle serrée par plugin::checkDeamon().
      $compteur = self::compteurDemarrages();
      if ($compteur['echecs'] > 0) {
        $rang = min($compteur['echecs'] - 1, count(self::DELAIS_RELANCE_DEMON_S) - 1);
        $delaiAttendu = self::DELAIS_RELANCE_DEMON_S[$rang];
        $ecoule = time() - $compteur['horodatage'];
        if ($ecoule < $delaiAttendu) {
          // launchable_message est injecté en HTML brut par le core : littérale +
          // entiers uniquement (intval()), jamais une valeur externe.
          $retour['launchable_message'] = sprintf(
            __('Démarrage du démon différé après %s échec(s) rapproché(s) : nouvelle tentative dans %s seconde(s).', __FILE__),
            intval($compteur['echecs']),
            intval($delaiAttendu - $ecoule)
          );
          return $retour;
        }
      }

      $retour['launchable'] = 'ok';
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

    // UC11/AC7 : marqué ICI, APRÈS le contrôle de launchabilité et JUSTE AVANT l'exec(),
    // JAMAIS à l'entrée de la fonction. deamon_info() ci-dessus relit ce même compteur
    // pour mesurer le temps écoulé depuis LA TENTATIVE PRÉCÉDENTE : le marquer avant de
    // s'auto-consulter ferait lire à deamon_info() un horodatage vieux d'à peine 1 s
    // (seul deamon_stop() s'est intercalé), donc systématiquement sous
    // DELAIS_RELANCE_DEMON_S[0] - deamon_start() s'auto-verrouillerait alors à chaque
    // tentative, y compris la toute première sur une installation neuve (aucun échec
    // réel n'a pourtant eu lieu). En marquant seulement APRÈS ce contrôle, deamon_info()
    // ne voit jamais que le résultat de la tentative précédente : compteur vide au
    // premier démarrage (pas de backoff, lancement immédiat) ; démon qui meurt avant
    // DUREE_VIE_MIN_DEMON_S -> la minute suivante, deamon_info() voit un échec récent et
    // répond launchable='nok' AVANT même que deamon_start() ne soit rappelée par le core.
    self::marquerDemarrageDemon();

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

  // Lit le compteur de démarrages du démon (UC11, AC7). Même squelette que
  // relanceSupervisionRecente() (UC10), mais la valeur stockée diffère : un tableau
  // array('echecs', 'horodatage') ici, un horodatage scalaire là-bas - la forme est donc
  // validée avant usage. Retombe sur un compteur neutre si absent ou non conforme.
  // NE LÈVE JAMAIS.
  private static function compteurDemarrages() {
    try {
      $valeur = cache::byKey(self::CLE_CACHE_DEMARRAGE_DEMON)->getValue('');
      if (is_array($valeur) && isset($valeur['echecs'], $valeur['horodatage'])) {
        return array('echecs' => intval($valeur['echecs']), 'horodatage' => intval($valeur['horodatage']));
      }
    } catch (Throwable $e) {
      log::add('jeeroborock', 'warning', 'compteurDemarrages : échec de lecture du cache : ' . $e->getMessage());
    }
    return array('echecs' => 0, 'horodatage' => 0);
  }

  // Incrémente le compteur de démarrages et horodate. Cache et non configuration : un
  // compteur d'incident doit pouvoir s'oublier tout seul (TTL). NE LÈVE JAMAIS.
  private static function marquerDemarrageDemon() {
    try {
      $compteur = self::compteurDemarrages();
      cache::set(self::CLE_CACHE_DEMARRAGE_DEMON, array('echecs' => $compteur['echecs'] + 1, 'horodatage' => time()), self::DUREE_CACHE_DEMARRAGE_DEMON);
    } catch (Throwable $e) {
      log::add('jeeroborock', 'warning', 'marquerDemarrageDemon : échec de mise en cache : ' . $e->getMessage());
    }
  }

  // Oublie le compteur de démarrages (démon observé sain, UC11 AC7). NE LÈVE JAMAIS.
  private static function oublierDemarragesDemon() {
    try {
      cache::delete(self::CLE_CACHE_DEMARRAGE_DEMON);
    } catch (Throwable $e) {
      log::add('jeeroborock', 'warning', 'oublierDemarragesDemon : échec de purge : ' . $e->getMessage());
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

  // Chien de garde de fraîcheur (UC10). Le démon porte désormais la cadence de
  // rafraîchissement (push + sonde) ; ce cron ne va plus chercher l'état lui-même. Il se
  // contente de vérifier que la donnée reçue pour chaque robot n'est pas trop ancienne, et
  // de demander un réarmement du superviseur démon si besoin. NE LÈVE JAMAIS (appelée par
  // le core sans try/catch) : chaque équipement est traité sous try/catch (robustesse cron).
  public static function cron() {
    if (!self::estCompteLie()) {
      return;
    }

    $reamorcageNecessaire = false;
    $nbActifs = 0;
    $nbPerimes = 0;

    foreach (eqLogic::byType('jeeroborock', true) as $eqLogic) {
      $nbActifs++;
      try {
        $cmd = $eqLogic->getCmd('info', 'derniere_maj');
        if (!is_object($cmd)) {
          // Jamais lu : on ne sait rien affirmer sur la connexion (D-10-6), on demande
          // seulement un réarmement.
          $reamorcageNecessaire = true;
          $nbPerimes++;
          continue;
        }

        // getCollectDate() est un piège (R16) : il fabrique "maintenant" pour une commande
        // jamais écrite. getCache('collectDate', '') renvoie '' quand l'entrée est absente.
        $collecte = trim((string) $cmd->getCache('collectDate', ''));
        if ($collecte == '') {
          $reamorcageNecessaire = true;
          $nbPerimes++;
          continue;
        }

        $horodatage = strtotime($collecte);
        if ($horodatage === false) {
          continue;
        }

        if ((time() - $horodatage) > self::DELAI_FRAICHEUR_S) {
          $reamorcageNecessaire = true;
          $nbPerimes++;
          $eqLogic->checkAndUpdateCmd('connecte', 0);
          log::add('jeeroborock', 'info', 'cron : donnée périmée pour l\'équipement ' . $eqLogic->getId() . ', bascule en déconnecté');
        }
      } catch (Throwable $e) {
        log::add('jeeroborock', 'error', 'cron : échec sur l\'équipement ' . $eqLogic->getId() . ' : ' . self::nettoyerPourLog(substr($e->getMessage(), 0, 256)));
      }
    }

    if (!$reamorcageNecessaire) {
      return;
    }

    $infos = self::deamon_info();
    if ($infos['state'] != 'ok') {
      // Le redémarrage du démon lui-même est l'affaire de plugin::checkDeamon().
      log::add('jeeroborock', 'debug', 'cron : réarmement différé, démon non actif');
      return;
    }

    // UC11/AC1/AC3 : sonde de session sans quota, seulement quand TOUS les équipements
    // actifs sont périmés (périmés == actifs, actifs non nul) : un seul robot éteint est
    // un cas nominal isolé, qui ne doit déclencher aucun appel cloud.
    if ($nbActifs > 0 && $nbPerimes >= $nbActifs) {
      if (!self::reauthRequise() && self::sondeCompteAFaire()) {
        $verdict = self::sonderCompte();
        if ($verdict !== 'OK') {
          // AUTH_EXPIRED : le drapeau est déjà levé par l'entonnoir de
          // jeeroborockDaemon::appeler(), restaurerSessionDemon() serait de toute façon
          // un no-op. INDISPONIBLE (cloud injoignable, etc.) : inutile de tenter un
          // réarmement MQTT immédiat. Dans les deux cas, pas de réarmement.
          return;
        }
      } elseif (self::reauthRequise()) {
        // Le drapeau est déjà levé (par un appel précédent) : aucune sonde tant que
        // l'utilisateur n'a pas relancé UC04, restaurerSessionDemon() est un no-op.
        return;
      } elseif (!self::sondeCompteAFaire()) {
        // Backoff de la sonde en cours : on ne sait pas si la session est toujours
        // valide, on ne réarme pas sur cette hypothèse.
        return;
      }
    } else {
      // Au moins un robot frais : la session fonctionne visiblement, la progression
      // d'escalade de la sonde n'a plus lieu d'être.
      self::oublierSondeCompte();
    }

    if (self::relanceSupervisionRecente()) {
      return;
    }
    self::marquerRelanceSupervision();
    self::restaurerSessionDemon();
  }

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
    $this->appliquerActions();
  }

  // Fonction exécutée automatiquement avant la suppression de l'équipement
  public function preRemove() {
  }

  // Fonction exécutée automatiquement après la suppression de l'équipement
  public function postRemove() {
  }

  /*     * ***********************Commandes d'action (UC08)********************* */

  // Table statique des 6 commandes d'action posées par appliquerActions(). Littérales
  // __() DANS la table (jamais __($variable) au point d'usage, l'extraction i18n est un
  // scan statique). Ordres 20-25 : laissent la plage 12-19 libre pour les commandes info
  // des UC 12-15, sans renumérotation. Le drapeau 'demon' distingue l'action robot
  // (transite par jeeroborockDaemon::appeler('envoyerCommande', ...)) du rafraîchissement
  // local ('rafraichir', branché sur rafraichirEtat()) : executerAction() refuse une clé
  // dont 'demon' est faux.
  private static function definitionsActions() {
    return array(
      'demarrer'     => array('nom' => __('Démarrer', __FILE__), 'generic' => '', 'ordre' => 20, 'demon' => true),
      'pause'        => array('nom' => __('Mettre en pause', __FILE__), 'generic' => '', 'ordre' => 21, 'demon' => true),
      'arreter'      => array('nom' => __('Arrêter', __FILE__), 'generic' => '', 'ordre' => 22, 'demon' => true),
      'retour_base'  => array('nom' => __('Retour à la base', __FILE__), 'generic' => 'DOCK', 'ordre' => 23, 'demon' => true),
      'localiser'    => array('nom' => __('Localiser', __FILE__), 'generic' => '', 'ordre' => 24, 'demon' => true),
      'rafraichir'   => array('nom' => __('Rafraîchir', __FILE__), 'generic' => '', 'ordre' => 25, 'demon' => false),
    );
  }

  // Crée ou fait converger la structure des 6 commandes action. Retourne le nombre de
  // commandes CRÉÉES. NE LÈVE JAMAIS : appelée depuis postSave() (ne doit pas faire
  // échouer l'enregistrement d'un équipement, ni la boucle de synchroniserEquipements())
  // et depuis rafraichirEtat() (retrofit, ne doit pas faire échouer un rafraîchissement).
  public function appliquerActions() {
    try {
      $definitions = self::definitionsActions();
    } catch (Throwable $e) {
      log::add('jeeroborock', 'error', 'appliquerActions : échec sur la table des définitions : ' . self::nettoyerPourLog(substr($e->getMessage(), 0, 256)));
      return 0;
    }
    $creees = 0;

    foreach ($definitions as $logicalId => $definition) {
      try {
        $cmd = $this->getCmd('action', $logicalId);
        if (is_object($cmd)) {
          // Commande déjà présente (idempotence) : on ne réécrit que le structurel,
          // jamais name/isVisible/order (personnalisation utilisateur préservée, même
          // règle qu'appliquerCapacites() d'UC07).
          $cmd->setType('action');
          $cmd->setSubType('other');
          if ($definition['generic'] != '') {
            $cmd->setGeneric_type($definition['generic']);
          }
          $cmd->save();
          continue;
        }

        $cmd = new jeeroborockCmd();
        $cmd->setEqLogic_id($this->getId());
        $cmd->setEqType('jeeroborock');
        $cmd->setLogicalId($logicalId);
        $cmd->setName($definition['nom']);
        $cmd->setType('action');
        $cmd->setSubType('other');
        if ($definition['generic'] != '') {
          $cmd->setGeneric_type($definition['generic']);
        }
        $cmd->setIsVisible(1);
        $cmd->setOrder($definition['ordre']);
        // Pas de setValue() (isAlreadyInStateAllow sauterait sinon l'exécution), pas de
        // setTemplate() (le cœur pose core::default), pas de setIsHistorized (forcé à 0
        // pour une action par le cœur).
        $cmd->save();
        $creees++;
      } catch (Throwable $e) {
        log::add('jeeroborock', 'error', 'appliquerActions : échec sur la commande ' . $logicalId . ' : ' . self::nettoyerPourLog(substr($e->getMessage(), 0, 256)));
      }
    }

    return $creees;
  }

  // Exécute une action de pilotage sur ce robot. Retourne le message FRANÇAIS de succès
  // (scalaire, jamais un tableau : execute() du cœur transformerait un tableau en chaîne
  // vide via formatValue()).
  public function executerAction($_action) {
    $duid = trim((string) $this->getLogicalId());
    if (!self::duidValide($duid)) {
      throw jeeroborockDaemon::erreurLocale('DEVICE_UNKNOWN');
    }
    if (!self::estCompteLie()) {
      throw jeeroborockDaemon::erreurLocale('NOT_AUTHENTICATED');
    }
    if (self::reauthRequise()) {
      // UC11/AC5 : refus local, AVANT tout appel démon (coût réseau et quota nul).
      throw jeeroborockDaemon::erreurLocale('AUTH_EXPIRED');
    }

    $definitions = self::definitionsActions();
    if (!isset($definitions[$_action]) || empty($definitions[$_action]['demon'])) {
      log::add('jeeroborock', 'warning', 'executerAction : action non supportée demandée : ' . self::nettoyerPourLog(substr((string) $_action, 0, 64)));
      throw jeeroborockDaemon::erreurLocale('UNSUPPORTED_COMMAND');
    }

    try {
      $r = jeeroborockDaemon::appeler(
        'envoyerCommande',
        array('userData' => self::getUserData(), 'baseUrl' => self::getBaseUrlCompte(), 'email' => self::getEmailCompte(), 'duid' => $duid, 'action' => $_action),
        jeeroborockDaemon::TIMEOUT_ACTION
      );
    } catch (jeeroborockException $e) {
      // Le dashboard doit rester cohérent avec le message d'erreur (AC7) : un robot dont
      // l'action n'a pas pu être transmise ne doit pas continuer à afficher 'Connecté'.
      if ($e->getCodeErreur() === 'DEVICE_OFFLINE') {
        $this->checkAndUpdateCmd('connecte', 0);
      }
      throw $e;
    }

    try {
      $this->appliquerEtatPartiel($r);
    } catch (Throwable $e) {
      // L'action, elle, a réussi : un incident d'écriture de commande ne doit pas la
      // faire apparaître en échec.
      log::add('jeeroborock', 'error', 'executerAction : échec d\'application de l\'état partiel : ' . self::nettoyerPourLog(substr($e->getMessage(), 0, 256)));
    }

    log::add('jeeroborock', 'info', 'Action « ' . $_action . ' » transmise au robot (équipement ' . $this->getId() . ')');

    return sprintf(__('Commande « %s » transmise au robot.', __FILE__), $definitions[$_action]['nom']);
  }

  // Applique le résultat de envoyerCommande (même forme que lireEtat) via les méthodes
  // privées d'UC07, réutilisées SANS modification : aucun nouveau chemin d'écriture de
  // commande, aucune nouvelle validation à maintenir.
  private function appliquerEtatPartiel($_reponse) {
    if (!is_array($_reponse)) {
      return;
    }
    $this->appliquerConnexion($_reponse);
    if (!empty($_reponse['etatLu'])) {
      $this->appliquerCapacites(isset($_reponse['capacites']) && is_array($_reponse['capacites']) ? $_reponse['capacites'] : array());
      $this->appliquerValeurs(isset($_reponse['etat']) && is_array($_reponse['etat']) ? $_reponse['etat'] : array());
    }
    // UC12 : HORS du garde etatLu (cycle et échec propres, cf. lireEtat/superviseur qui
    // publient le bloc consommables indépendamment de la lecture d'état).
    if (isset($_reponse['consommables']) && is_array($_reponse['consommables'])) {
      $this->appliquerConsommables($_reponse['consommables']);
    }
  }

  // Point d'entrée du lot poussé par le superviseur du démon (UC10, callback jeedom_com).
  // Le lot a exactement la forme de la réponse lireEtat : appliquerEtatPartiel() (ci-dessus)
  // le consomme tel quel, sans nouveau chemin d'écriture. NE LÈVE JAMAIS (appelée depuis un
  // point d'entrée externe, core/php/jeeJeeroborock.php, déjà sous try/catch global mais
  // qui ne doit jamais voir une exception d'un robot en bloquer un autre).
  public static function traiterPoussee($_robots) {
    if (!is_array($_robots)) {
      log::add('jeeroborock', 'warning', 'traiterPoussee : lot invalide (pas un tableau)');
      return;
    }

    $compteur = 0;
    foreach ($_robots as $cle => $lot) {
      if ($compteur >= self::NB_MAX_ROBOTS_SYNCHRO) {
        log::add('jeeroborock', 'warning', 'traiterPoussee : lot tronqué au-delà de ' . self::NB_MAX_ROBOTS_SYNCHRO . ' robots');
        break;
      }
      $compteur++;

      // Cast explicite : json_decode(..., true) convertit une clé de duid purement
      // numérique en clé entière (array_slice() la réindexerait, d'où le foreach+compteur).
      $duid = trim((string) $cle);
      if (!self::duidValide($duid)) {
        log::add('jeeroborock', 'warning', 'traiterPoussee : duid invalide ignoré : ' . self::nettoyerPourLog(substr($duid, 0, 128)));
        continue;
      }
      if (!is_array($lot)) {
        continue;
      }

      $eqLogic = eqLogic::byLogicalId($duid, 'jeeroborock');
      if (!is_object($eqLogic)) {
        log::add('jeeroborock', 'debug', 'traiterPoussee : robot sans équipement Jeedom ignoré');
        continue;
      }
      if ($eqLogic->getIsEnable() != 1) {
        continue;
      }

      try {
        $eqLogic->appliquerEtatPartiel($lot);
      } catch (Throwable $e) {
        log::add('jeeroborock', 'error', 'traiterPoussee : échec sur l\'équipement ' . $eqLogic->getId() . ' : ' . self::nettoyerPourLog(substr($e->getMessage(), 0, 256)));
      }
    }
  }

  /*     * ***********************Rafraîchissement de l'état (UC07)************ */

  // Point d'entrée UNIQUE du rafraîchissement d'un robot : synchronise les commandes
  // (capacités détectées) puis écrit leurs valeurs. À appeler SOUS try/catch PAR
  // ÉQUIPEMENT dans une boucle (robustesse cron, D-07-9 : aucun cron ne l'appelle
  // encore au MVP - UC08 branchera la commande action 'rafraîchir' ici, UC10 un cron).
  public function rafraichirEtat() {
    $duid = trim((string) $this->getLogicalId());
    if (!self::duidValide($duid)) {
      throw jeeroborockDaemon::erreurLocale('DEVICE_UNKNOWN');
    }
    if (!self::estCompteLie()) {
      throw jeeroborockDaemon::erreurLocale('NOT_AUTHENTICATED');
    }
    if (self::reauthRequise()) {
      // UC11/AC5 : refus local, AVANT tout appel démon (coût réseau et quota nul).
      throw jeeroborockDaemon::erreurLocale('AUTH_EXPIRED');
    }

    $r = jeeroborockDaemon::appeler(
      'lireEtat',
      array('userData' => self::getUserData(), 'baseUrl' => self::getBaseUrlCompte(), 'email' => self::getEmailCompte(), 'duid' => $duid),
      jeeroborockDaemon::TIMEOUT_ETAT
    );

    // TOUJOURS, avant tout test d'échec : c'est ce qui rend AC6 vrai même robot éteint.
    $this->appliquerConnexion($r);

    // Retrofit UC08 : garantit qu'un robot créé avant cette UC, ou jamais joignable,
    // possède quand même ses 6 commandes action - sans quoi AC7 serait invérifiable.
    // N'appelle jamais le démon (appliquerActions() est purement local).
    $this->appliquerActions();

    if (empty($r['etatLu'])) {
      $motif = isset($r['motifEchec']) ? (string) $r['motifEchec'] : '';
      if (!preg_match('/\A[A-Z0-9_]{1,40}\z/', $motif) || !in_array($motif, array('DEVICE_OFFLINE', 'ROBOROCK_TIMEOUT', 'CONNECTION_FAILED'), true)) {
        $motif = 'DEVICE_OFFLINE';
      }
      log::add('jeeroborock', 'info', 'rafraichirEtat : lecture en échec (' . $motif . ') pour l\'équipement ' . $this->getId());
      throw jeeroborockDaemon::erreurLocale($motif);
    }

    $creees = $this->appliquerCapacites(isset($r['capacites']) && is_array($r['capacites']) ? $r['capacites'] : array());
    $this->appliquerValeurs(isset($r['etat']) && is_array($r['etat']) ? $r['etat'] : array());
    if (isset($r['consommables']) && is_array($r['consommables'])) {
      $creees += $this->appliquerConsommables($r['consommables']);
    }

    $cmdTotal = 0;
    foreach (array_keys(self::definitionsCommandes()) as $logicalId) {
      if (is_object($this->getCmd('info', $logicalId))) {
        $cmdTotal++;
      }
    }

    log::add('jeeroborock', 'debug', 'rafraichirEtat : état rafraîchi pour l\'équipement ' . $this->getId() . ' (' . $creees . ' commande(s) créée(s), ' . $cmdTotal . ' au total)');

    return array('etatLu' => true, 'cmdCreees' => $creees, 'cmdTotal' => $cmdTotal);
  }

  // Écrit les indicateurs de connexion. TOUJOURS exécutée par rafraichirEtat(), y
  // compris quand la lecture d'état échoue (AC6). Crée les 3 commandes si besoin
  // (inconditionnel : ce ne sont pas des capacités robot).
  private function appliquerConnexion($_reponse) {
    foreach (array('en_ligne', 'connecte', 'derniere_maj') as $logicalId) {
      if (!is_object($this->getCmd('info', $logicalId))) {
        try {
          $this->creerCommande($logicalId);
        } catch (Throwable $e) {
          log::add('jeeroborock', 'error', 'appliquerConnexion : échec de création de la commande ' . $logicalId . ' : ' . self::nettoyerPourLog(substr($e->getMessage(), 0, 256)));
        }
      }
    }

    $this->checkAndUpdateCmd('connecte', !empty($_reponse['connecte']) ? 1 : 0);

    // 'enLigne' n'est écrite que sur un booléen explicite : jamais sur null (on
    // n'affirme pas "hors ligne" quand l'information n'a simplement pas été relevée).
    if (isset($_reponse['enLigne']) && is_bool($_reponse['enLigne'])) {
      $this->checkAndUpdateCmd('en_ligne', $_reponse['enLigne'] ? 1 : 0);
    }

    // Uniquement quand la lecture a abouti : un horodatage qui ne bouge plus est le
    // signal visible de données périmées.
    if (!empty($_reponse['etatLu'])) {
      $this->checkAndUpdateCmd('derniere_maj', date('Y-m-d H:i:s'));
    }
  }

  // Crée ou fait converger la structure des commandes correspondant aux capacités
  // détectées par le démon (AC4/AC5). Retourne le nombre de commandes CRÉÉES.
  private function appliquerCapacites($_capacites) {
    if (!is_array($_capacites)) {
      return 0;
    }

    // Une capacité du démon peut porter plusieurs commandes (le code ET le libellé
    // lisible partagent la même donnée source).
    $correspondances = array(
      'etat'           => array('etat', 'etat_code'),
      'batterie'       => array('batterie'),
      'enNettoyage'    => array('en_nettoyage'),
      'erreur'         => array('erreur', 'erreur_code'),
      'surfaceNettoyee' => array('surface_nettoyee'),
      'dureeNettoyage' => array('duree_nettoyage'),
      'avancement'     => array('avancement'),
      // UC13 - même patron qu'« erreur » ci-dessus : une capacité peut porter plusieurs
      // commandes (le code brut ET le libellé lisible partagent la même donnée source).
      'stationVidage'    => array('station_vidage'),
      'stationLavage'    => array('station_lavage'),
      'stationSechage'   => array('station_sechage'),
      'stationErreur'    => array('station_erreur', 'station_erreur_code'),
      'stationManqueEau' => array('station_manque_eau'),
    );
    $definitions = self::definitionsCommandes();
    $creees = 0;

    foreach ($correspondances as $capacite => $logicalIds) {
      if (empty($_capacites[$capacite])) {
        continue;
      }
      foreach ($logicalIds as $logicalId) {
        if (!isset($definitions[$logicalId])) {
          continue;
        }
        try {
          $definition = $definitions[$logicalId];
          $cmd = $this->getCmd('info', $logicalId);
          if (is_object($cmd)) {
            // Commande déjà présente (AC5, idempotence) : on ne réécrit que le
            // structurel, jamais name/isVisible/isHistorized/order (personnalisation
            // utilisateur).
            $cmd->setType('info');
            $cmd->setSubType($definition['subType']);
            $cmd->setUnite($definition['unite']);
            if ($definition['generic'] != '') {
              $cmd->setGeneric_type($definition['generic']);
            }
            if (isset($definition['min']) && isset($definition['max'])) {
              $cmd->setConfiguration('minValue', $definition['min']);
              $cmd->setConfiguration('maxValue', $definition['max']);
            }
            $cmd->save();
          } elseif (is_object($this->creerCommande($logicalId))) {
            $creees++;
          }
        } catch (Throwable $e) {
          // Troncature à 256 caractères OBLIGATOIRE : cmd::save() peut embarquer
          // print_r($this, true) dans son message (R-16).
          log::add('jeeroborock', 'error', 'appliquerCapacites : échec sur la commande ' . $logicalId . ' : ' . self::nettoyerPourLog(substr($e->getMessage(), 0, 256)));
        }
      }
    }

    return $creees;
  }

  // Écrit les valeurs reçues du démon. Liste blanche FERMÉE de 15 clés, aucune boucle
  // générique sur $_etat (AC4 : une clé absente laisse la commande à sa valeur
  // précédente, jamais un défaut).
  private function appliquerValeurs($_etat) {
    if (!is_array($_etat)) {
      return;
    }

    if (isset($_etat['etatCode']) && is_numeric($_etat['etatCode']) && intval($_etat['etatCode']) >= 0) {
      $this->checkAndUpdateCmd('etat_code', intval($_etat['etatCode']));
    }
    if (isset($_etat['etatLibelle'])) {
      $this->checkAndUpdateCmd('etat', self::texteInventaire((string) $_etat['etatLibelle'], self::LONGUEUR_MAX_LIBELLE_ETAT));
    }
    if (isset($_etat['enNettoyage'])) {
      $this->checkAndUpdateCmd('en_nettoyage', !empty($_etat['enNettoyage']) ? 1 : 0);
    }
    if (isset($_etat['batterie']) && is_numeric($_etat['batterie'])) {
      $valeur = intval($_etat['batterie']);
      if ($valeur >= 0 && $valeur <= 100) {
        $this->checkAndUpdateCmd('batterie', $valeur);
      }
    }
    if (isset($_etat['erreurCode']) && is_numeric($_etat['erreurCode']) && intval($_etat['erreurCode']) >= 0) {
      $this->checkAndUpdateCmd('erreur_code', intval($_etat['erreurCode']));
    }
    if (isset($_etat['erreurLibelle'])) {
      $this->checkAndUpdateCmd('erreur', self::texteInventaire((string) $_etat['erreurLibelle'], self::LONGUEUR_MAX_LIBELLE_ETAT));
    }
    if (isset($_etat['surfaceNettoyeeM2']) && is_numeric($_etat['surfaceNettoyeeM2'])) {
      $valeur = floatval($_etat['surfaceNettoyeeM2']);
      if ($valeur >= 0) {
        $this->checkAndUpdateCmd('surface_nettoyee', $valeur);
      }
    }
    if (isset($_etat['dureeNettoyageMin']) && is_numeric($_etat['dureeNettoyageMin'])) {
      $valeur = intval($_etat['dureeNettoyageMin']);
      if ($valeur >= 0) {
        $this->checkAndUpdateCmd('duree_nettoyage', $valeur);
      }
    }
    if (isset($_etat['avancement']) && is_numeric($_etat['avancement'])) {
      $valeur = intval($_etat['avancement']);
      if ($valeur >= 0 && $valeur <= 100) {
        $this->checkAndUpdateCmd('avancement', $valeur);
      }
    }

    // UC13/D-13-6 - DIVERGENCE ASSUMÉE avec la règle « clé absente quand la source est
    // None » appliquée ci-dessus : merge_trait_values() (côté démon) peut effacer
    // dock_error_status d'une réponse à l'autre, et AC5 exige que l'état normal soit
    // ÉCRIT, pas seulement non contredit — sous la règle ci-dessus, ces 6 commandes
    // resteraient figées sur le dernier incident, silencieusement. Le démon garantit
    // donc que chacune de ces clés est TOUJOURS présente dès que la capacité de station
    // correspondante est vraie, avec la valeur normale quand la source est None (« Au
    // repos », 0, '', false). Cf. spec technique UC13 § D-13-6.
    if (isset($_etat['stationVidage'])) {
      $this->checkAndUpdateCmd('station_vidage', self::texteInventaire((string) $_etat['stationVidage'], self::LONGUEUR_MAX_LIBELLE_ETAT));
    }
    if (isset($_etat['stationLavage'])) {
      $this->checkAndUpdateCmd('station_lavage', self::texteInventaire((string) $_etat['stationLavage'], self::LONGUEUR_MAX_LIBELLE_ETAT));
    }
    if (isset($_etat['stationSechage'])) {
      $this->checkAndUpdateCmd('station_sechage', self::texteInventaire((string) $_etat['stationSechage'], self::LONGUEUR_MAX_LIBELLE_ETAT));
    }
    if (isset($_etat['stationErreurLibelle'])) {
      $this->checkAndUpdateCmd('station_erreur', self::texteInventaire((string) $_etat['stationErreurLibelle'], self::LONGUEUR_MAX_LIBELLE_ETAT));
    }
    if (isset($_etat['stationErreurCode']) && is_numeric($_etat['stationErreurCode']) && intval($_etat['stationErreurCode']) >= 0) {
      $this->checkAndUpdateCmd('station_erreur_code', intval($_etat['stationErreurCode']));
    }
    if (isset($_etat['stationManqueEau'])) {
      $this->checkAndUpdateCmd('station_manque_eau', !empty($_etat['stationManqueEau']) ? 1 : 0);
    }
  }

  // Crée une commande d'information à partir de sa définition statique. Ne retourne
  // jamais null pour un logicalId défini (save() lève sur les invariants du core,
  // laissé remonter à l'appelant qui journalise et tronque, R-16).
  private function creerCommande($_logicalId) {
    $definitions = self::definitionsCommandes();
    if (!isset($definitions[$_logicalId])) {
      return null;
    }
    $definition = $definitions[$_logicalId];

    $cmd = new jeeroborockCmd();
    $cmd->setEqLogic_id($this->getId());
    $cmd->setEqType('jeeroborock');
    $cmd->setLogicalId($_logicalId);
    $cmd->setName($definition['nom']);
    $cmd->setType('info');
    $cmd->setSubType($definition['subType']);
    $cmd->setUnite($definition['unite']);
    if ($definition['generic'] != '') {
      $cmd->setGeneric_type($definition['generic']);
    }
    $cmd->setIsVisible($definition['visible']);
    $cmd->setIsHistorized($definition['historise']);
    $cmd->setOrder($definition['ordre']);
    if (isset($definition['min']) && isset($definition['max'])) {
      $cmd->setConfiguration('minValue', $definition['min']);
      $cmd->setConfiguration('maxValue', $definition['max']);
    }
    // Pas de setTemplate() : save() pose core::default en dashboard/mobile si rien
    // n'est défini (widgets par défaut au MVP).
    $cmd->save();
    return $cmd;
  }

  /*     * ***********************Consommables et usure (UC12)****************** */

  // Table statique des 5 consommables, portant À LA FOIS l'info et l'action associée
  // (impossible de créer un reset orphelin). Littérales __() DANS la table (jamais
  // __($variable) au point d'usage, l'extraction i18n est un scan statique).
  private static function definitionsConsommables() {
    return array(
      'brosse_principale' => array(
        'cleDemon'      => 'brossePrincipale',
        'logicalIdInfo' => 'usure_brosse_principale',
        'nomInfo'       => __('Usure brosse principale', __FILE__),
        'logicalIdAction' => 'reset_brosse_principale',
        'nomAction'     => __('Réinitialiser la brosse principale', __FILE__),
        'ordre'         => 0,
      ),
      'brosse_laterale' => array(
        'cleDemon'      => 'brosseLaterale',
        'logicalIdInfo' => 'usure_brosse_laterale',
        'nomInfo'       => __('Usure brosse latérale', __FILE__),
        'logicalIdAction' => 'reset_brosse_laterale',
        'nomAction'     => __('Réinitialiser la brosse latérale', __FILE__),
        'ordre'         => 1,
      ),
      'filtre' => array(
        'cleDemon'      => 'filtre',
        'logicalIdInfo' => 'usure_filtre',
        'nomInfo'       => __('Usure filtre', __FILE__),
        'logicalIdAction' => 'reset_filtre',
        'nomAction'     => __('Réinitialiser le filtre', __FILE__),
        'ordre'         => 2,
      ),
      'capteurs' => array(
        'cleDemon'      => 'capteurs',
        'logicalIdInfo' => 'usure_capteurs',
        'nomInfo'       => __('Usure capteurs', __FILE__),
        'logicalIdAction' => 'reset_capteurs',
        'nomAction'     => __('Réinitialiser les capteurs', __FILE__),
        'ordre'         => 3,
      ),
      'rouleau_serpillere' => array(
        'cleDemon'      => 'rouleauSerpillere',
        'logicalIdInfo' => 'usure_rouleau_serpillere',
        'nomInfo'       => __('Usure rouleau de serpillière', __FILE__),
        'logicalIdAction' => 'reset_rouleau_serpillere',
        'nomAction'     => __('Réinitialiser le rouleau de serpillière', __FILE__),
        'ordre'         => 4,
      ),
    );
  }

  // Crée/fait converger les commandes d'usure et de réinitialisation à partir du bloc
  // "consommables" reçu du démon (lireEtat, envoyerCommande, poussée du superviseur).
  // Retourne le nombre de commandes CRÉÉES. NE LÈVE JAMAIS : appelée depuis
  // rafraichirEtat()/appliquerEtatPartiel(), ne doit jamais faire échouer l'appelant.
  private function appliquerConsommables($_consommables) {
    if (!is_array($_consommables)) {
      return 0;
    }
    $capacites = (isset($_consommables['capacites']) && is_array($_consommables['capacites'])) ? $_consommables['capacites'] : array();
    $valeurs = (isset($_consommables['valeurs']) && is_array($_consommables['valeurs'])) ? $_consommables['valeurs'] : array();

    $creees = 0;

    foreach (self::definitionsConsommables() as $suffixe => $definition) {
      try {
        $cleDemon = $definition['cleDemon'];
        // AC2 : capacité fausse (ou absente) -> rien créé, rien écrit, pour NI l'usure
        // NI le reset.
        if (empty($capacites[$cleDemon])) {
          continue;
        }

        $cmdInfo = $this->getCmd('info', $definition['logicalIdInfo']);
        if (is_object($cmdInfo)) {
          // Commande déjà présente (idempotence) : on ne réécrit que le structurel,
          // jamais name/isVisible/order/isHistorized (personnalisation utilisateur).
          $cmdInfo->setType('info');
          $cmdInfo->setSubType('numeric');
          $cmdInfo->setUnite('%');
          $cmdInfo->setConfiguration('minValue', 0);
          $cmdInfo->setConfiguration('maxValue', 100);
          $cmdInfo->save();
        } else {
          $cmdInfo = new jeeroborockCmd();
          $cmdInfo->setEqLogic_id($this->getId());
          $cmdInfo->setEqType('jeeroborock');
          $cmdInfo->setLogicalId($definition['logicalIdInfo']);
          $cmdInfo->setName($definition['nomInfo']);
          $cmdInfo->setType('info');
          $cmdInfo->setSubType('numeric');
          $cmdInfo->setUnite('%');
          $cmdInfo->setIsVisible(1);
          $cmdInfo->setIsHistorized(0);
          $cmdInfo->setOrder(self::ORDRE_BASE_CONSOMMABLES + $definition['ordre']);
          $cmdInfo->setConfiguration('minValue', 0);
          $cmdInfo->setConfiguration('maxValue', 100);
          // Pas de setTemplate() : save() pose core::default.
          $cmdInfo->save();
          $creees++;
        }

        $cmdAction = $this->getCmd('action', $definition['logicalIdAction']);
        if (is_object($cmdAction)) {
          $cmdAction->setType('action');
          $cmdAction->setSubType('other');
          $cmdAction->setConfiguration('actionConfirm', 1);
          $cmdAction->save();
        } else {
          $cmdAction = new jeeroborockCmd();
          $cmdAction->setEqLogic_id($this->getId());
          $cmdAction->setEqType('jeeroborock');
          $cmdAction->setLogicalId($definition['logicalIdAction']);
          $cmdAction->setName($definition['nomAction']);
          $cmdAction->setType('action');
          $cmdAction->setSubType('other');
          $cmdAction->setIsVisible(1);
          $cmdAction->setOrder(self::ORDRE_BASE_RESET_CONSOMMABLES + $definition['ordre']);
          $cmdAction->setConfiguration('actionConfirm', 1);
          // Pas de setValue() (piège isAlreadyInStateAllow()), pas de setIsHistorized
          // (forcé à 0 pour une action par le cœur).
          $cmdAction->save();
          $creees++;
        }

        // Valeur : liste blanche FERMÉE (aucune boucle générique sur le payload).
        // Hors [0,100] ou non numérique -> ignorée silencieusement, valeur précédente
        // conservée (même politique qu'appliquerValeurs()).
        if (isset($valeurs[$cleDemon]) && is_numeric($valeurs[$cleDemon])) {
          $valeur = intval($valeurs[$cleDemon]);
          if ($valeur >= 0 && $valeur <= 100) {
            $this->checkAndUpdateCmd($definition['logicalIdInfo'], $valeur);
          } else {
            log::add('jeeroborock', 'debug', 'appliquerConsommables : valeur hors bornes ignorée pour ' . $definition['logicalIdInfo']);
          }
        }
      } catch (Throwable $e) {
        log::add('jeeroborock', 'error', 'appliquerConsommables : échec sur le consommable ' . $suffixe . ' : ' . self::nettoyerPourLog(substr($e->getMessage(), 0, 256)));
      }
    }

    return $creees;
  }

  // Réinitialise le compteur d'usure d'un consommable ($_cle = suffixe de
  // definitionsConsommables(), ex. 'brosse_principale'). Retourne le message FRANÇAIS de
  // succès (scalaire).
  public function reinitialiserConsommable($_cle) {
    $duid = trim((string) $this->getLogicalId());
    if (!self::duidValide($duid)) {
      throw jeeroborockDaemon::erreurLocale('DEVICE_UNKNOWN');
    }
    if (!self::estCompteLie()) {
      throw jeeroborockDaemon::erreurLocale('NOT_AUTHENTICATED');
    }
    if (self::reauthRequise()) {
      // UC11/AC5 : refus local, AVANT tout appel démon (coût réseau et quota nul).
      throw jeeroborockDaemon::erreurLocale('AUTH_EXPIRED');
    }

    $definitions = self::definitionsConsommables();
    if (!isset($definitions[$_cle])) {
      log::add('jeeroborock', 'warning', 'reinitialiserConsommable : consommable inconnu demandé : ' . self::nettoyerPourLog(substr((string) $_cle, 0, 64)));
      throw jeeroborockDaemon::erreurLocale('CONSOMMABLE_INCONNU');
    }
    $definition = $definitions[$_cle];

    // On ne réinitialise pas un compteur dont on n'a jamais constaté l'existence
    // (message pédagogique de la spec fonctionnelle en cas d'action indisponible).
    if (!is_object($this->getCmd('info', $definition['logicalIdInfo']))) {
      throw jeeroborockDaemon::erreurLocale('CONSOMMABLE_INCONNU');
    }

    try {
      $r = jeeroborockDaemon::appeler(
        'reinitialiserConsommable',
        array('userData' => self::getUserData(), 'baseUrl' => self::getBaseUrlCompte(), 'email' => self::getEmailCompte(), 'duid' => $duid, 'consommable' => $definition['cleDemon']),
        jeeroborockDaemon::TIMEOUT_CONSO_RESET
      );
    } catch (jeeroborockException $e) {
      if ($e->getCodeErreur() === 'DEVICE_OFFLINE') {
        $this->checkAndUpdateCmd('connecte', 0);
      }
      throw $e;
    }

    try {
      if (isset($r['consommables']) && is_array($r['consommables'])) {
        $this->appliquerConsommables($r['consommables']);
      }
    } catch (Throwable $e) {
      // L'action a réussi : un incident d'écriture ne doit pas la faire apparaître en échec.
      log::add('jeeroborock', 'error', 'reinitialiserConsommable : échec d\'application des valeurs : ' . self::nettoyerPourLog(substr($e->getMessage(), 0, 256)));
    }

    log::add('jeeroborock', 'info', 'Compteur d\'usure « ' . $_cle . ' » réinitialisé (équipement ' . $this->getId() . ')');

    return sprintf(__('Compteur d\'usure de « %s » réinitialisé.', __FILE__), $definition['nomInfo']);
  }

  /*     * ***********************Routines / usages (UC09)********************* */

  // Synchronise les routines ("usages") définies dans l'application mobile pour ce
  // robot : un unique POST HTTPS signé Hawk, chemin STRICTEMENT DISJOINT du
  // DeviceManager/MQTT (AC7). À appeler SOUS try/catch PAR ÉQUIPEMENT.
  public function synchroniserRoutines() {
    $duid = trim((string) $this->getLogicalId());
    if (!self::duidValide($duid)) {
      throw jeeroborockDaemon::erreurLocale('DEVICE_UNKNOWN');
    }
    if (!self::estCompteLie()) {
      throw jeeroborockDaemon::erreurLocale('NOT_AUTHENTICATED');
    }
    if (self::reauthRequise()) {
      // UC11/AC5 : refus local, AVANT tout appel démon (coût réseau et quota nul).
      throw jeeroborockDaemon::erreurLocale('AUTH_EXPIRED');
    }
    if ($this->synchroRoutinesRecente()) {
      throw jeeroborockDaemon::erreurLocale('ROUTINE_SYNC_RECENTE');
    }

    $r = jeeroborockDaemon::appeler(
      'listerRoutines',
      array('userData' => self::getUserData(), 'baseUrl' => self::getBaseUrlCompte(), 'email' => self::getEmailCompte(), 'duid' => $duid),
      jeeroborockDaemon::TIMEOUT_ROUTINES_SYNC
    );

    $this->marquerSynchroRoutines();

    $routines = (isset($r['routines']) && is_array($r['routines'])) ? array_slice($r['routines'], 0, self::NB_MAX_ROUTINES) : array();

    $compteurs = $this->appliquerRoutines($routines);

    log::add('jeeroborock', 'info', 'Synchronisation des usages : aucune consommation de quota homedata (chemin HTTPS disjoint, UC09) - ' . $compteurs['creees'] . ' créé(s), ' . $compteurs['misAJour'] . ' mis à jour, ' . $compteurs['echecs'] . ' échec(s), ' . count($compteurs['obsoletes']) . ' obsolète(s)');

    $compteurs['total'] = isset($r['nbTotal']) ? intval($r['nbTotal']) : count($routines);

    return $compteurs;
  }

  // Applique la liste des routines reçues du démon : convergence structurelle des
  // commandes existantes, création des nouvelles, marquage obsolète de celles
  // disparues (AC1/AC3/AC4/AC5). NE LÈVE JAMAIS.
  private function appliquerRoutines($_routines) {
    $creees = 0;
    $misAJour = 0;
    $echecs = 0;
    $obsoletes = array();

    // Ne PAS passer par $this->getCmd() (cache interne _cmds, mélangerait deux vues
    // avec l'énumération fraîche des commandes de routine).
    $existantes = array();
    $cmdsAction = cmd::byEqLogicId($this->getId(), 'action');
    if (is_array($cmdsAction)) {
      foreach ($cmdsAction as $cmd) {
        $logicalId = (string) $cmd->getLogicalId();
        if (strpos($logicalId, self::PREFIXE_CMD_ROUTINE) !== 0) {
          continue;
        }
        if (isset($existantes[$logicalId])) {
          log::add('jeeroborock', 'warning', 'appliquerRoutines : logicalId dupliqué ignoré : ' . self::nettoyerPourLog($logicalId));
          continue;
        }
        $existantes[$logicalId] = $cmd;
      }
    }

    $rang = 0;
    foreach ($_routines as $routine) {
      $rang++;
      try {
        $idBrut = (is_array($routine) && isset($routine['id'])) ? $routine['id'] : null;
        if (!is_array($routine) || !is_numeric($idBrut) || intval($idBrut) <= 0 || (string) intval($idBrut) !== trim((string) $idBrut)) {
          $echecs++;
          log::add('jeeroborock', 'warning', 'appliquerRoutines : identifiant de routine invalide : ' . self::nettoyerPourLog(substr((string) $idBrut, 0, 64)));
          continue;
        }

        $id = intval($routine['id']);
        $logicalId = self::PREFIXE_CMD_ROUTINE . $id;
        $nom = self::texteInventaire(isset($routine['nom']) ? $routine['nom'] : '', self::LONGUEUR_MAX_NOM_ROUTINE);

        if (isset($existantes[$logicalId])) {
          $cmd = $existantes[$logicalId];
          $cmd->setConfiguration('routineObsolete', 0);

          // AC3 sous garde de personnalisation : on ne réécrit le nom que s'il n'a
          // jamais été personnalisé dans Jeedom (comparaison à la valeur RELUE, donc
          // déjà passée par cleanComponanteName).
          if ($cmd->getConfiguration('nomRoutine', '') === '' || $cmd->getConfiguration('nomRoutine', '') === $cmd->getName()) {
            $cmd->setName($nom);
          }
          $cmd->setConfiguration('nomRoutine', $cmd->getName());

          $cmd->setType('action');
          $cmd->setSubType('other');
          $cmd->save();

          $misAJour++;
          unset($existantes[$logicalId]);
        } else {
          $cmd = new jeeroborockCmd();
          $cmd->setEqLogic_id($this->getId());
          $cmd->setEqType('jeeroborock');
          $cmd->setLogicalId($logicalId);
          $cmd->setName($nom);
          if ($cmd->getName() == '') {
            $cmd->setName(sprintf(__('Usage Roborock %s', __FILE__), $id));
          }
          $cmd->setType('action');
          $cmd->setSubType('other');
          $cmd->setIsVisible(1);
          $cmd->setOrder(self::ORDRE_BASE_ROUTINES + $rang);
          $cmd->setConfiguration('routineObsolete', 0);
          $cmd->setConfiguration('nomRoutine', $cmd->getName());
          // Pas de setValue() (isAlreadyInStateAllow ferait sauter l'exécution), pas
          // de setTemplate() (le cœur pose core::default), pas de setGeneric_type(),
          // pas de setIsHistorized().
          $cmd->save();

          $creees++;
        }
      } catch (Throwable $e) {
        $echecs++;
        log::add('jeeroborock', 'error', 'appliquerRoutines : échec sur une routine : ' . self::nettoyerPourLog(substr($e->getMessage(), 0, 256)));
      }
    }

    // AC4 : toute commande de routine restante n'a pas été confirmée par le démon ->
    // marquée obsolète (jamais supprimée automatiquement).
    foreach ($existantes as $cmd) {
      try {
        if ($cmd->getConfiguration('routineObsolete', 0) != 1) {
          $cmd->setConfiguration('routineObsolete', 1);
          $cmd->save();
          $obsoletes[] = $cmd->getName();
          log::add('jeeroborock', 'warning', 'appliquerRoutines : usage marqué obsolète : ' . self::nettoyerPourLog($cmd->getName()));
        }
      } catch (Throwable $e) {
        $echecs++;
        log::add('jeeroborock', 'error', 'appliquerRoutines : échec de marquage obsolète : ' . self::nettoyerPourLog(substr($e->getMessage(), 0, 256)));
      }
    }

    return array('creees' => $creees, 'misAJour' => $misAJour, 'echecs' => $echecs, 'obsoletes' => $obsoletes);
  }

  // Exécute une routine ("usage") sur ce robot, via le cloud HTTPS pur (AC2, AC7).
  // Retourne le message FRANÇAIS de succès (scalaire).
  public function executerRoutine($_cmd) {
    $duid = trim((string) $this->getLogicalId());
    if (!self::duidValide($duid)) {
      throw jeeroborockDaemon::erreurLocale('DEVICE_UNKNOWN');
    }
    if (!self::estCompteLie()) {
      throw jeeroborockDaemon::erreurLocale('NOT_AUTHENTICATED');
    }
    if (self::reauthRequise()) {
      // UC11/AC5 : refus local, AVANT tout appel démon (coût réseau et quota nul).
      throw jeeroborockDaemon::erreurLocale('AUTH_EXPIRED');
    }
    if ($_cmd->getConfiguration('routineObsolete', 0) == 1) {
      // AVANT tout appel démon : chemin déterministe d'AC4, zéro requête HTTPS.
      throw jeeroborockDaemon::erreurLocale('ROUTINE_OBSOLETE');
    }

    $sceneId = self::sceneIdDepuisLogicalId($_cmd->getLogicalId());
    if ($sceneId === null) {
      log::add('jeeroborock', 'warning', 'executerRoutine : logicalId de routine non conforme : ' . self::nettoyerPourLog((string) $_cmd->getLogicalId()));
      throw jeeroborockDaemon::erreurLocale('UNSUPPORTED_COMMAND');
    }

    try {
      jeeroborockDaemon::appeler(
        'executerRoutine',
        array('userData' => self::getUserData(), 'baseUrl' => self::getBaseUrlCompte(), 'email' => self::getEmailCompte(), 'duid' => $duid, 'sceneId' => $sceneId),
        jeeroborockDaemon::TIMEOUT_ROUTINE_EXEC
      );
    } catch (jeeroborockException $e) {
      // Aucun effet de bord : un refus cloud transitoire ne doit jamais marquer la
      // commande obsolète, seule la synchronisation (vue complète et autoritaire) le fait.
      log::add('jeeroborock', 'warning', 'executerRoutine : échec pour la commande ' . $_cmd->getId() . ' : ' . $e->getMessage());
      throw $e;
    }

    log::add('jeeroborock', 'info', 'Usage (scène ' . $sceneId . ') lancé pour l\'équipement ' . $this->getId());

    return sprintf(__('Usage « %s » lancé.', __FILE__), $_cmd->getName());
  }

  // Dérive le sceneId d'un logicalId de commande de routine. Ancres \A/\z (jamais
  // ^/$, cf. rappel UC03 sur la forge de ligne de log). Retourne null si le format
  // n'est pas conforme.
  private static function sceneIdDepuisLogicalId($_logicalId) {
    if (preg_match('/\Aroutine_([1-9][0-9]{0,17})\z/', (string) $_logicalId, $correspondances) !== 1) {
      return null;
    }
    return intval($correspondances[1]);
  }

  // Garde anti-rafale (D-09-3) : true si une synchronisation a réussi il y a moins de
  // DELAI_MIN_SYNCHRO_ROUTINES secondes pour CET équipement. NE LÈVE JAMAIS : un
  // incident de cache dégrade la garde, il ne casse pas la synchro.
  private function synchroRoutinesRecente() {
    try {
      $horodatage = cache::byKey(self::CLE_CACHE_SYNCHRO_ROUTINES . $this->getId())->getValue('');
      if ($horodatage === '' || !is_numeric($horodatage)) {
        return false;
      }
      return (time() - intval($horodatage)) < self::DELAI_MIN_SYNCHRO_ROUTINES;
    } catch (Throwable $e) {
      log::add('jeeroborock', 'warning', 'synchroRoutinesRecente : échec de lecture du cache : ' . $e->getMessage());
      return false;
    }
  }

  // Écrit l'horodatage de la garde anti-rafale. Écrit APRÈS succès de l'appel démon
  // (un échec local ne doit pas imposer une minute d'attente). NE LÈVE JAMAIS.
  private function marquerSynchroRoutines() {
    try {
      cache::set(self::CLE_CACHE_SYNCHRO_ROUTINES . $this->getId(), time(), self::DUREE_CACHE_SYNCHRO_ROUTINES);
    } catch (Throwable $e) {
      log::add('jeeroborock', 'warning', 'marquerSynchroRoutines : échec de mise en cache : ' . $e->getMessage());
    }
  }

  // Garde anti-rafale du réarmement du superviseur démon (UC10, D-10-1). Calque exact de
  // synchroRoutinesRecente()/marquerSynchroRoutines() (UC09), mais globale au plugin (une
  // seule clé de cache, pas par équipement) : cron() réarme le superviseur pour l'ensemble
  // des robots en une seule fois. NE LÈVE JAMAIS.
  private static function relanceSupervisionRecente() {
    try {
      $horodatage = cache::byKey(self::CLE_CACHE_RELANCE_SUPERVISION)->getValue('');
      if ($horodatage === '' || !is_numeric($horodatage)) {
        return false;
      }
      return (time() - intval($horodatage)) < self::DELAI_MIN_RELANCE_SUPERVISION;
    } catch (Throwable $e) {
      log::add('jeeroborock', 'warning', 'relanceSupervisionRecente : échec de lecture du cache : ' . $e->getMessage());
      return false;
    }
  }

  private static function marquerRelanceSupervision() {
    try {
      cache::set(self::CLE_CACHE_RELANCE_SUPERVISION, time(), self::DUREE_CACHE_RELANCE_SUPERVISION);
    } catch (Throwable $e) {
      log::add('jeeroborock', 'warning', 'marquerRelanceSupervision : échec de mise en cache : ' . $e->getMessage());
    }
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

  // Empêche la suppression des commandes par un "Sauvegarder" posté sans elles
  // (core/ajax/eqLogic.ajax.php) : les commandes de ce plugin sont ENTIÈREMENT gérées
  // par le plugin (D-07-7). Sans ce garde-fou, un onglet ouvert avant un
  // rafraîchissement détruirait silencieusement des commandes référencées par des
  // scénarios, avec leur historique. Contrepartie assumée : l'icône "supprimer" d'une
  // commande devient sans effet.
  //
  // D-09-4 (UC09) : une commande de routine MARQUÉE OBSOLÈTE redevient supprimable
  // par le chemin standard du cœur, ce qu'AC4 exige. Toute autre commande reste
  // protégée (règle ci-dessus inchangée).
  public function dontRemoveCmd() {
    if (strpos((string) $this->getLogicalId(), jeeroborock::PREFIXE_CMD_ROUTINE) === 0 && $this->getConfiguration('routineObsolete', 0) == 1) {
      return false;
    }
    return true;
  }

  // Exécution d'une commande (UC08). execCmd() du cœur a déjà garanti un eqLogic actif.
  // Doit retourner un SCALAIRE (chaîne) : formatValue() du cœur transforme un tableau en
  // chaîne vide.
  public function execute($_options = array()) {
    if ($this->getType() != 'action') {
      return false;
    }

    // OBLIGATOIRE, pas cosmétique : cmd.ajax.php et ajax::init() ne font jamais
    // session_write_close() avant execCmd(). Sans lui, tout appel au démon (jusqu'à 35 s)
    // fige l'interface Jeedom pour l'utilisateur. Le garde session_status() couvre les
    // exécutions hors HTTP (scénario, cron, API).
    if (session_status() === PHP_SESSION_ACTIVE) {
      session_write_close();
    }

    $eqLogic = $this->getEqLogic();

    // UC09 : une commande de routine ("usage") route vers executerRoutine(), AVANT le
    // switch. Sans ce test, un logicalId routine_* tomberait dans le "default" ->
    // executerAction() -> UNSUPPORTED_COMMAND, un message faux.
    if (strpos((string) $this->getLogicalId(), jeeroborock::PREFIXE_CMD_ROUTINE) === 0) {
      return $eqLogic->executerRoutine($this);
    }

    // UC12 : une commande reset_* route vers reinitialiserConsommable(), AVANT le
    // switch. Sans ce test, un logicalId reset_* tomberait dans le "default" ->
    // executerAction() -> UNSUPPORTED_COMMAND, message faux.
    if (strpos((string) $this->getLogicalId(), jeeroborock::PREFIXE_CMD_RESET_CONSO) === 0) {
      $suffixe = substr((string) $this->getLogicalId(), strlen(jeeroborock::PREFIXE_CMD_RESET_CONSO));
      return $eqLogic->reinitialiserConsommable($suffixe);
    }

    switch ($this->getLogicalId()) {
      case 'rafraichir':
        $eqLogic->rafraichirEtat();
        return __('État rafraîchi.', __FILE__);
      default:
        return $eqLogic->executerAction($this->getLogicalId());
    }
  }

  /*     * **********************Getteur Setteur*************************** */
}
