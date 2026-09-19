# Spec technique — UC11 : Robustesse, quotas et ré-authentification

> **Spec fonctionnelle** : `11-robustesse-et-reauthentification.md` · **Dépend de** : UC03 (canal),
> UC04 (authentification), UC05 (`etatCompte`), UC07/UC08 (état, actions), UC09 (routines),
> UC10 (superviseur, chien de garde de fraîcheur)
> **Contrats externes vérifiés** le 2026-09-19 sur le code réel du dépôt et sur la source de
> `python-roborock` v7.8.0. Plan relu et challengé le même jour : aucune affirmation à numéro de ligne
> prise en défaut ; les deux mécanismes les plus risqués (boucle de l'entonnoir, verrou de session PHP)
> ont été vérifiés **sur le code** et non seulement affirmés.

## Résumé

UC11 ajoute un **état absorbant « ré-authentification requise »**, persisté côté PHP en configuration
plugin, qui coupe toutes les boucles automatiques consommatrices de quota ; plus deux **backoffs**
(sonde de robot côté démon, relance du démon côté PHP) et un **durcissement de la journalisation** du
démon. Le socle UC10 (superviseur, chien de garde de fraîcheur) n'est pas refondu : il est gardé en amont.

## Couverture des critères

| AC | Réalisé par | Nouveau code ? |
|---|---|---|
| AC1 bascule visible + plus aucune tentative auto | Détection : entonnoir unique `jeeroborockDaemon::appeler()` sur le code `AUTH_EXPIRED` + push `compte` du superviseur + sonde de session du cron. Visibilité : `message::add` persistant + badge existant. Absorption : garde dans `restaurerSessionDemon()` (point de passage unique de tous les réarmements : `deamon_start()` l.548, `cron()` l.760, `enregistrerSession()` l.354) | oui |
| AC2 retour normal par UC04 | `enregistrerSession()` efface le drapeau dans le même bloc try et juste avant `config::save('userData', …)` (l.349), puis `restaurerSessionDemon()` réarme le superviseur | oui, 1 ligne |
| AC3 pas de tentatives rapprochées | 3 boucles : `_superviser` (`DELAIS_REPRISE_S = (60,300,900,1800)`, déjà en place) ; `_sonde` (escalade nouvelle 120 -> 300 -> 600 s) ; cron (`DELAI_MIN_RELANCE_SUPERVISION=600` déjà en place + backoff nouveau de la sonde de compte 900/1800/3600/7200/21600 s) | partiel |
| AC4 dernières valeurs conservées | Acquis par construction, 0 ligne (démonstration § Validation) | non |
| AC5 messages d'action distincts | Ordre des gardes dans `executerAction()` : `DEVICE_UNKNOWN` puis `NOT_AUTHENTICATED` puis **`AUTH_EXPIRED` (nouveau, local, avant tout appel démon)** puis `UNSUPPORTED_COMMAND` puis codes du canal (`DAEMON_UNREACHABLE` / `DEVICE_OFFLINE`). Les 3 messages existent déjà dans `tableMessages()` (l.222, 236, 237) | oui, 4 lignes |
| AC6 aucun secret en clair | Plafond par logger tiers dans `jeeroborockd.py` + suppression des `exc_info=True` de `canal.py` au profit de `erreurs.trace_sure()` + procédure de recette grep reproductible | oui |
| AC7 pas de boucle serrée de redémarrage | Backoff porté par `deamon_info()['launchable']`, seul levier consulté par le coeur (`plugin::checkDeamon` n'appelle `deamon_start()` que si `launchable == 'ok' && state == 'nok'`) | oui |

## Contrats externes

### 1. Détection fiable de l'invalidation des identifiants

`AUTH_EXPIRED` n'a que deux sources dans tout le plugin, et aucune des deux ne peut être produite par
une panne réseau ni par un quota :

- `roborock.RoborockInvalidCredentials` -> `AUTH_EXPIRED` : levée à un seul endroit de la librairie,
  `web_api.py::_get_home_id()`, `if home_id_response.get("code") == 2010: raise RoborockInvalidCredentials(...)`.
  C'est un corps JSON applicatif (HTTP 200) portant `code == 2010`, jamais un code de transport.
  (`resources/demond/erreurs.py` l.31)
- `session.decoder_user_data()` -> `ErreurDemon("AUTH_EXPIRED")` : blob `userData` illisible ou
  structurellement incomplet (`token`/`rriot`/`rriot.r` absents) — cause locale, jamais réseau.
  (`resources/demond/session.py` l.55-69)

Les causes concurrentes sortent ailleurs :
- Panne réseau : `PreparedRequest.request()` fait `except (aiohttp.ClientError, TimeoutError, OSError) as err:
  raise RoborockException(f"Network error contacting {_url}: {err}") from err` -> `code_pour_exception()`
  suit `__cause__` (`erreurs.py` l.84-87) -> `CLOUD_UNREACHABLE`.
- Quota : `RoborockRateLimit` -> `RATE_LIMIT`, refusé par le limiteur avant le moindre paquet pour `home_data`.

ECART SIGNALE (analyse interne vs source) : `jeeroborock-cloud-api.md` § 4.1 l.181-183 avertit que
« tout autre code de refus arrive en RoborockException nue ». C'est un risque de faux négatif
(révocation signalée par un code different de 2010 -> on resterait en « robot hors ligne »), pas de faux
positif. Conservé tel quel, porté en Risques (R5) et en point de recette.

### 2. Sonde de session sans quota

`RoborockApiClient._get_home_id()` (`GET /api/v1/getHomeDetail`) est la seule requête authentifiée de
`web_api.py` sans limiteur. Elle est déjà câblée : opération démon `etatCompte` avec
`avecInventaire = false` (`authentification.py` l.152-198). UC11 la réutilise telle quelle, sans
nouvelle opération de canal.

### 3. Hook d'autorisation de la librairie (évalué, écarté)

`create_device_manager(..., mqtt_session_unauthorized_hook, ...)` existe et est appelé (a) sur
`MqttCodeError` avec `err.rc == 135` et (b) dans `UserWebApiClient.get_home_data / get_routines /
get_rooms / execute_routine` sur `RoborockInvalidCredentials`. Écarté — voir « Hors périmètre proposé ».

### 4. Boucle de reconnexion MQTT de la librairie (bornes, pour AC3)

`MIN_BACKOFF_INTERVAL = 10 s`, `BACKOFF_MULTIPLIER = 1.5`, `MAX_BACKOFF_INTERVAL = 6 h` ; sur refus
d'autorisation elle force le backoff au maximum (6 h) mais ne s'arrête jamais. Conséquence : laisser un
`DeviceManager` vivant sur une session morte laisse tourner une reconnexion 6 h — d'où la fermeture
explicite du gestionnaire.

### 5. Coeur Jeedom

`deamon_start()` n'est appelé que si `launchable == 'ok' && state == 'nok'`, avec un verrou de 45 s entre
deux lancements ; `deamon_info()` est appelée chaque minute sans try/catch et son `launchable_message`
est injecté en HTML (littérales + entiers uniquement).
(`.memory/analyse/jeedom-dependances-et-demon.md` § 4 l.89-101)

## Architecture — fichiers

| Chemin | État | Ce qui y entre |
|---|---|---|
| `core/class/jeeroborock.class.php` | modifié | 8 constantes ; 3 accesseurs du drapeau ; `traiterEtatCompte()` ; sonde de compte + backoff ; garde dans `restaurerSessionDemon()` ; gardes dans `executerAction()` / `rafraichirEtat()` / `executerRoutine()` / `synchroniserRoutines()` / `synchroniserEquipements()` ; effacement dans `enregistrerSession()` et `oublierSession()` ; `cron()` étendu ; backoff de relance dans `deamon_info()` / `deamon_start()` |
| `core/class/jeeroborockDaemon.class.php` | modifié | entonnoir unique : `appeler()` intercepte `jeeroborockException` de code `AUTH_EXPIRED`, appelle `jeeroborock::signalerReauthRequise('CANAL')`, relance. ~6 lignes. `tableMessages()` inchangée |
| `core/class/jeeroborockException.class.php` | inchangé | Aucun code d'erreur nouveau -> piège du double-fichier `estErreurCanal()`/`tableMessages()` sans objet |
| `core/php/jeeJeeroborock.php` | modifié | 4 lignes : branche `compte` dans le dispatch des lots d'UC10 (l.39-51) |
| `core/ajax/jeeroborock.ajax.php` | modifié | 1 ligne : `jeeroborock::effacerReauthRequise();` dans la branche `authentifie` de `testerConnexion` (avant l.162) |
| `core/php/jeeroborock.inc.php` | inchangé | Aucune classe PHP nouvelle -> autoload sans objet |
| `resources/demond/supervision.py` | modifié | `_publier_compte()` + appel dans `_superviser` sur `CODES_ARRET` ; escalade `CADENCES_ECHEC_S` dans `_sonde` ; renommage `CADENCE_ECHEC_S` -> `CADENCE_ERREUR_INTERNE_S` |
| `resources/demond/erreurs.py` | modifié | `trace_sure(exc)` : trace sans aucun message d'exception |
| `resources/demond/canal.py` | modifié | 3 `exc_info=True` (l.184, 187, 191) remplacés par `trace_sure()` |
| `resources/demond/jeeroborockd.py` | modifié | `brider_loggers_tiers(niveau)` appelée juste après `jeedom_utils.set_log_level()` (l.132) |
| `authentification.py`, `robots.py`, `session.py`, `routines.py`, `libelles.py`, `textes.py` | inchangés | dette R13 UC06/UC10 non rouverte |
| `packages.json`, `info.json`, `jeeroborock.config.ini`, `configuration.txt`/`.php`, `desktop/`, `core/i18n/*.json` | inchangés | aucune dépendance ; **aucune ligne de défaut .ini pour `reauthRequise`** ; aucun champ de formulaire ; traduction déléguée |

Indentation : PHP 2 espaces CRLF ; Python 4 espaces LF.

## Signatures & responsabilités

### A. État absorbant — PHP

Où il vit : **côté PHP uniquement, en configuration plugin**. Trois raisons :
1. Il doit survivre au redémarrage du démon (un état en `contexte` serait remis à zéro par le
   redémarrage que `plugin::checkDeamon()` déclenche chaque minute).
2. Il ne doit pas expirer tout seul -> configuration et pas cache (un TTL qui expire relance les
   tentatives ; un cache purgé ferait de même).
3. Le démon n'a pas besoin de le connaître : le PHP lui envoie une session vide
   (`restaurerSession` avec `userData = ''`), ce qui suffit à le rendre quiescent.

- `const CLE_CONFIG_REAUTH = 'reauthRequise'` — clé de config plugin, sans ligne dans
  `jeeroborock.config.ini`, sans champ de formulaire, hors `$_encryptConfigKey` (ce n'est pas un secret).
  Précédent : `baseUrl` (UC04).
- `public static function reauthRequise()` — `trim((string) config::byKey('reauthRequise','jeeroborock','')) === '1'`.
  Comparaison stricte à `'1'` : neutralise la divergence `byKey`/`byKeys` sur la chaîne vide et le
  `is_json()` appliqué par `config::byKey`. Ne lève jamais.
- `public static function signalerReauthRequise($_origine)` — ne lève jamais, idempotente (sort si
  déjà vrai, re-entrance bloquée par un `static $enCours`). Séquence : `config::save('reauthRequise','1',…)`
  -> `log::add(warning, …origine…)` (origine validée par une regex `[A-Z_]{1,16}` ancrée)
  -> `message::removeAll('jeeroborock','reauth')` + `message::add(...)` (littérale, aucun contenu externe)
  -> notification du démon :
  `jeeroborockDaemon::appeler('restaurerSession', array('userData'=>'','baseUrl'=>'','email'=>''), TIMEOUT_SESSION)`
  sous try/catch (Throwable). Origines réellement émises : **`CANAL`** (entonnoir — couvre aussi la sonde
  de compte du cron, qui passe par `appeler()`) et **`DEMON`** (push du superviseur). ⚠️ Il n'y a
  **pas** d'origine `SONDE` : `sonderCompte()` n'appelle jamais `signalerReauthRequise()` en propre, le
  drapeau étant déjà levé par l'entonnoir au moment où elle reçoit son verdict. Contrepartie acceptée :
  une détection venue de la sonde se journalise `origine : CANAL`.
- `public static function effacerReauthRequise()` — ne lève jamais. `config::save('reauthRequise','',…)`
  (valeur vide = défaut -> ligne supprimée en base, effet voulu, évite tout écart de comparaison lâche
  entre 0 et chaîne vide selon la version de PHP) + `message::removeAll('jeeroborock','reauth')`
  + `oublierSondeCompte()`.
- `public static function traiterEtatCompte($_donnees)` — point d'entrée du lot `compte` poussé par le
  démon. Ne lève jamais. Refuse un non-tableau ; exige `!empty($_donnees['reauthRequise'])` ; sort si
  `!estCompteLie()` ; puis `signalerReauthRequise('DEMON')`.

Écriture (3 chemins, 1 seul point d'entrée) :
- `jeeroborockDaemon::appeler()` — entonnoir : tout appel démon qui revient en `AUTH_EXPIRED` lève le
  drapeau. Couvre `envoyerCommande`, `lireEtat`, `decouvrirEquipements`, `listerRoutines`,
  `executerRoutine`, `etatCompte`, `restaurerSession`. `erreurLocale()` n'est pas concernée (refus
  local, jamais un signal cloud) : le drapeau ne se ré-écrit donc pas lui-même.
- `traiterEtatCompte()` — push du superviseur (`CODES_ARRET`).
- La sonde du cron — passe par `appeler()`, donc par l'entonnoir.

Lecture (gardes) : `restaurerSessionDemon()` (couvre à lui seul `deamon_start()`, `cron()` et tout futur
réarmement) ; `executerAction()` ; `rafraichirEtat()` ; `executerRoutine()` ; `synchroniserRoutines()` ;
`synchroniserEquipements()` — ces dernières lèvent `jeeroborockDaemon::erreurLocale('AUTH_EXPIRED')`
avant tout appel démon, donc à coût réseau et quota nul.

Réarmement — chemin exact (AC2) : UC04 -> `core/ajax/jeeroborock.ajax.php` `validerCode` (l.62) ->
`jeeroborock::enregistrerSession($resultat)` -> dans le bloc try (l.345-355), dans cet ordre :
`config::save('baseUrl',…)` -> **`self::effacerReauthRequise();`** -> `config::save('userData',…)` ->
`oublierInventaireCompte()` -> `restaurerSessionDemon()` (garde levée) -> le démon réarme le superviseur
-> les sondes repartent -> la fraîcheur avance -> le chien de garde du cron se tait. Le placement de
l'effacement immédiatement avant l'écriture de `userData`, dans la même fonction et le même try, rend
structurellement impossible « nouvelle session persistée sans drapeau effacé ».

Second chemin de guérison (faux positif) : bouton « Tester la connexion » -> branche `authentifie` ->
`effacerReauthRequise()`. Coût quota nul (`_get_home_id`, `avecInventaire` déjà gardé par le cache
d'inventaire l.94-95). Parade explicite au risque « faux positif coûteux ».
Troisième chemin : `oublierSession()` (changement d'e-mail) -> efface le drapeau.

### B. Backoffs

B.1 — Sondes du superviseur, côté démon (`supervision.py`) :
- avant : `CADENCE_ECHEC_S = 120` (palier unique après `SEUIL_ECHECS = 3`)
- après : `CADENCES_ECHEC_S = (120, 300, 600)`, indexée par `min(echecs_consecutifs - SEUIL_ECHECS, 2)` ;
  remise à zéro au premier succès (comportement existant conservé).
  Motif : un robot éteint est un état nominal de longue durée ; 120 s constant produit 720 RPC MQTT et
  720 POST de callback par jour et par robot, chacun démarrant un bootstrap complet du coeur Jeedom.
  Plafond 600 s aligné sur `DELAI_MIN_RELANCE_SUPERVISION`. Le retour en service passe par le push
  (l'attente sur événement réveille la sonde immédiatement, l.283).
- `CADENCE_ECHEC_S` utilisée aussi par le `except Exception` englobant (l.320) -> renommée
  `CADENCE_ERREUR_INTERNE_S = 120`, inchangée sur ce chemin.
- `DELAIS_REPRISE_S = (60, 300, 900, 1800)` de `_superviser` (l.56) inchangé, déjà conforme à AC3 et
  déjà protégé contre le doublement par `demarrer()` (l.102).

B.2 — Redémarrage du démon, côté PHP (AC7). Le délai croissant se pose dans `deamon_info()`, pas
ailleurs : seul point où le coeur consulte le plugin avant de relancer. Poser le délai dans
`deamon_start()` reviendrait à se battre contre `plugin::checkDeamon()` et à bloquer un cron du coeur.
- `const DUREE_VIE_MIN_DEMON_S = 120` — un démon qui ne survit pas 120 s n'est pas « démarré ».
- `const DELAIS_RELANCE_DEMON_S = array(60, 300, 900, 1800)` — base 60 s (supérieure au verrou de 45 s
  du coeur), plafond 1800 s : un démon durablement cassé est relancé 48 fois par jour au lieu de 1440,
  et une installation réparée repart en 30 min au plus.
- `const CLE_CACHE_DEMARRAGE_DEMON = 'jeeroborock::demarrageDemon'` / `DUREE_CACHE_DEMARRAGE_DEMON = 86400`
  — compteur `array('echecs'=>int,'horodatage'=>int)`. Cache et non configuration : un compteur
  d'incident doit pouvoir s'oublier tout seul.
- `compteurDemarrages()` / `marquerDemarrageDemon()` / `oublierDemarragesDemon()` — **même squelette** que
  `relanceSupervisionRecente()` / `marquerRelanceSupervision()` (l.1484-1503) : try/catch englobant,
  lecture par `cache::byKey(...)->getValue('')`, écriture par `cache::set(...)`. ⚠️ **La valeur stockée
  diffère** : un tableau `array('echecs','horodatage')` ici, un horodatage scalaire là-bas — donc la
  lecture doit valider la forme (`is_array()` + `intval()` sur chaque champ) avant usage, et retomber sur
  `array('echecs' => 0, 'horodatage' => 0)` sinon. Ne lèvent jamais.
- `deamon_start()` — marque la tentative (`marquerDemarrageDemon()` : incrément pessimiste + horodatage)
  **après** le contrôle `if ($infos['launchable'] != 'ok')` et **juste avant** l'`exec()`, jamais à
  l'entrée. ⚠️ **Cet ordre est la partie non négociable du mécanisme, et l'inverser le casse
  totalement** : `deamon_start()` appelle lui-même `deamon_info()` (contrat hérité d'UC02) : marquer
  d'abord ferait relire par `deamon_info()` le compteur que l'on vient d'écrire, donc un écoulé de ~1 s,
  toujours inférieur à `DELAIS_RELANCE_DEMON_S[0]`. Le démon serait alors déclaré non lançable **à chaque
  appel, y compris le tout premier d'une installation neuve** — et comme chaque tentative re-daterait le
  compteur, l'auto-blocage serait **définitif**, pas borné à 60 s. Avec le bon ordre, `deamon_info()` ne
  voit que la tentative **précédente**, ce qui est exactement la grandeur que le backoff doit mesurer.
- `deamon_info()` — branche `state == 'ok'` : si echecs positif et
  `time() - horodatage >= DUREE_VIE_MIN_DEMON_S` -> `oublierDemarragesDemon()` (au plus une écriture par
  cycle de panne). Branche démon absent, après `causeNonLancable()` (qui reste prioritaire) : si echecs
  positif et `time() - horodatage < DELAIS_RELANCE_DEMON_S[min($echecs-1, 3)]` -> `launchable = 'nok'` +
  `launchable_message` = littérale + 2 entiers. Ne lève toujours jamais.

Le compteur n'est remis à zéro que par l'observation d'un démon sain — jamais par le seul écoulement du
temps. Et parce que le marquage est placé **après** le `throw`, une tentative **refusée** par le backoff
ne s'ajoute pas au compteur et ne repousse pas l'horodatage : le compteur mesure des `exec()` réellement
tentés, jamais des refus. Sans cette propriété, un bouton « Démarrer » cliqué pendant la fenêtre
d'attente rallongerait cette même fenêtre — un backoff qui s'auto-alimente.

⚠️ La remise à zéro passe uniquement par la branche `state == 'ok'` de `deamon_info()`, atteinte par les
appels **externes** du core (cron, `checkDeamon`) pendant que le démon tourne — jamais par le
`deamon_info()` interne de `deamon_start()`, qui suit toujours un `deamon_stop()` et voit donc un démon
absent. C'est correct, mais cela signifie que **la guérison du compteur dépend du cron du core**, pas du
plugin seul.

### C. Côté démon

- `supervision._publier_compte(contexte, code)` — ne lève jamais.
  `contexte['com'].add_changes('compte', {'reauthRequise': True, 'motif': code})`. Appelée par
  `_superviser` dans la branche `code in CODES_ARRET` (l.176-178), avant le return. Aucune opération RPC
  nouvelle, aucune route HTTP nouvelle. Clé de premier niveau `compte`, sans double deux-points
  (contrainte de `add_changes`, R8 d'UC10).
- `erreurs.trace_sure(exc, limite=12)` — chaîne « frames + chaîne de classes » :
  `traceback.format_tb(exc.__traceback__)` (fichier, ligne, fonction, texte source — jamais de valeur :
  contrairement à PHP, une trace Python n'imprime pas les arguments de frame) + les noms de classes de
  la chaîne de causes. Aucun `str(exception)`. Ne lève jamais.
- `jeeroborockd.brider_loggers_tiers(niveau)` — appelée juste après `jeedom_utils.set_log_level(args.loglevel)`
  (l.132). Table : `{'roborock': INFO, 'aiohttp': INFO, 'aiomqtt': INFO, 'asyncio': INFO,
  'roborock.web_api': WARNING}` appliquée par `logging.getLogger(nom).setLevel(max(niveau_racine, plafond))`.

Le `max()` n'est pas cosmétique : `logging.basicConfig()` installe un handler de niveau `NOTSET`, donc un
`setLevel(INFO)` sur un logger enfant alors que la racine est à `ERROR` augmenterait la verbosité au lieu
de la réduire.

Le PHP n'envoie aucune opération nouvelle au démon : l'état absorbant se propage par
`restaurerSession(userData='')`, déjà écrit (`authentification.py` l.137-140), qui vide la session du
contexte et appelle `supervision.arreter(contexte)` — lequel annule les sondes, débranche les écouteurs
de push et ferme le `DeviceManager` (`fermer_gestionnaire=True` par défaut, l.125-127), donc coupe aussi
la reconnexion MQTT à 6 h. Zéro ligne de démon pour l'absorption.

## Validation & erreurs

AC4 — vérifié sur le code réel, acquis par construction, aucune ligne à écrire. Quatre verrous déjà en place :
1. `appliquerEtatPartiel()` (l.978-987) : `appliquerCapacites()` et `appliquerValeurs()` ne sont appelées
   que si le lot porte un état effectivement lu. Un lot en échec ne touche aucune commande de valeur.
2. `robots.valeurs_etat()` (l.255-276) : chaque clé est absente quand la valeur source est nulle —
   jamais de 0 ni de chaîne vide par défaut.
3. `appliquerValeurs()` (l.1182-1226) : liste blanche fermée de 9 clés, chacune gardée par `isset(...)`.
4. `appliquerCapacites()` (l.1120-1177) : ne supprime ni ne remet à zéro aucune commande.
Les seules écritures sur un chemin dégradé sont `connecte` (0) et éventuellement `en_ligne`.
Garde-fou de non-régression à porter en review : aucune des méthodes ajoutées par UC11 n'écrit de
commande info ; seul `cron()` écrit, et uniquement `connecte`.

AC5 — le classement est déjà fait par `jeeroborockDaemon::lever()` (l.166-189) ; UC11 n'ajoute qu'un
refus local en amont.

| Cause | Code | Message (existant) |
|---|---|---|
| Démon arrêté / injoignable | `DAEMON_UNREACHABLE` | « Le démon ne répond pas : vérifiez qu'il est démarré dans la configuration du plugin. » (l.222) |
| Robot hors ligne | `DEVICE_OFFLINE` | « Le robot est hors ligne : il ne répond pas au cloud Roborock. » (l.236) |
| Ré-authentification requise | `AUTH_EXPIRED` | « Session Roborock expirée : une nouvelle authentification par code e-mail est nécessaire. » (l.237) |

Le piège du double fichier est sans objet, et c'est un choix : `AUTH_EXPIRED` appartient à la famille C
(état métier), donc correctement absent de `jeeroborockException::estErreurCanal()` (l.46-54, familles A
et B seulement). `erreurLocale('AUTH_EXPIRED')` passe le filtre l.204-207 sans rien modifier. UC11
n'introduit aucun code d'erreur stable nouveau. Créer un code dédié `REAUTH_REQUIRED` aurait imposé la
synchronisation à deux fichiers pour un message identique : écarté.

Priorité compte vs robot (question ouverte de la spec, tranchée) : le compte prime, toujours.
(1) c'est la seule cause que l'utilisateur peut corriger, et une session morte rend tous les robots
muets ; (2) la priorité est structurelle, pas conditionnelle : la garde `reauthRequise()` est placée
avant tout appel démon. Ordre final : `DEVICE_UNKNOWN` -> `NOT_AUTHENTICATED` (jamais lié) ->
`AUTH_EXPIRED` (lié mais session morte) -> `UNSUPPORTED_COMMAND` -> codes du canal.

AC6 — ce que le code journalise aujourd'hui (vérifié) :
- Code du plugin (PHP et démon) : aucun secret. Le handler RPC de `canal.py` ne journalise que les noms
  de clés (l.173-175) ; son filtre de détail n'admet que des scalaires et journalise les clés écartées
  sans leur valeur (l.71-91) ; `deamon_start()` masque l'apikey sous sa forme échappée (l.530-533) ;
  `interpreterReponse()` ne journalise ni le corps ni sa nature (l.133-135) ; le helper de neutralisation
  de `textes.py` couvre toute chaîne d'origine cloud.
- Deux fuites réelles, venues des loggers de la librairie, qui atteignent le log du démon parce que
  `jeedom_utils.set_log_level()` fait `logging.basicConfig()` sur le logger racine (`jeedom/jeedom.py`
  l.162-164) et que les loggers de `roborock` y propagent :
  1. Niveau INFO — dans la requête préparée de `web_api.py`, sur erreur de type de contenu :
     `_LOGGER.info("Resp: %s", resp_json)` puis `_LOGGER.info("Resp raw: %s", resp_raw)` -> le corps brut
     de la réponse HTTP. Sur le login par code, ce corps EST le `UserData` (jeton + secrets `rriot`).
  2. Niveau ERROR — donc au niveau par défaut de Jeedom — le handler RPC journalise avec la trace
     complète (l.184, 187, 191). Une trace Python n'imprime pas les variables locales, mais elle imprime
     le texte de chaque exception de la chaîne, et `web_api.py` construit des messages du type
     « home_response result was an unexpected type » suivis de la réponse `homedata`, qui contient les
     `local_key` de tous les robots.
  Effets secondaires moindres au niveau DEBUG : la session MQTT de la librairie journalise le nom
  d'utilisateur MQTT, les topics non caviardés et les trames.

Ce qu'UC11 met en place :
1. Plafond par logger tiers (`brider_loggers_tiers`) : `roborock.web_api` -> WARNING (supprime les deux
   lignes INFO qui impriment un corps HTTP) ; `roborock`, `aiohttp`, `aiomqtt`, `asyncio` -> INFO
   (conserve les lignes utiles à AC3, supprime les DEBUG de trames et de topics). Les appels de
   journalisation du plugin restent sur la racine, donc le niveau `debug` du plugin garde toute sa valeur.
2. `erreurs.trace_sure()` en remplacement des trois traces complètes de `canal.py`.
3. Contrôle reproductible, à inscrire dans la recette :
   - statique : un grep des traces complètes sous `resources/demond/` doit renvoyer exactement 3
     occurrences, toutes dans `supervision.py` (l.110, 131, 359 — chemins locaux, messages produits par
     le plugin) ;
   - dynamique : niveau debug, redémarrage du démon, rejeu des 7 chemins (demande de code, validation,
     test de connexion, découverte, rafraîchissement, action, usage) puis, sur les deux logs, un grep des
     motifs `rriot`, `local_key`, `localKey`, du libellé de jeton, des deux préfixes de réponse brute, des
     16 premiers caractères du blob `userData` et de l'apikey -> aucune ligne attendue.

Validation des entrées du nouveau chemin externe (`compte` reçu par callback) : non-tableau refusé ;
l'indicateur est lu par `!empty()` ; le motif est ignoré sauf s'il matche une regex ancrée
`[A-Z_]{1,32}`, et uniquement pour le log ; aucun `message::add` construit à partir d'une valeur reçue.
`traiterEtatCompte()` ne lève jamais.

Budget de temps. `cron()` : boucle de fraîcheur locale (coût nul) + au plus un `etatCompte`
(`TIMEOUT_COMPTE = 20 s`, réutilisé) toutes les 900 s au minimum + `deamon_info()` (0,3 s de `fsockopen`
au plus) => pire cas environ 21 s une fois par quart d'heure, nominal quasi nul.
`signalerReauthRequise()` : 3 s au plus (`TIMEOUT_SESSION`), y compris depuis le callback — sans
interblocage, le POST de callback partant du thread de communication et non de la boucle asyncio.
`deamon_info()` : une lecture de cache en plus.

Sonde de compte — cadencement et garde anti-faux-positif. Déclenchée seulement si tous les équipements
actifs sont périmés (périmés égal actifs, et actifs non nul) : un seul robot éteint — cas nominal — ne
déclenche rien. Au moins un robot frais -> `oublierSondeCompte()`. Escalade
`DELAIS_SONDE_COMPTE_S = array(900, 1800, 3600, 7200, 21600)`, stockée dans
`cache::set('jeeroborock::sondeCompte', array('rang','horodatage'), 604800)`.

Verdicts et effet sur le rang :

| Verdict | Signification | Rang |
|---|---|---|
| `OK` | la session vit ; on réarme le superviseur comme aujourd'hui | **remis à zéro** (`oublierSondeCompte()`) |
| `AUTH_EXPIRED` | drapeau déjà levé par l'entonnoir ; on sort sans réarmer | **avance** |
| `INDISPONIBLE` (tout autre code : `CLOUD_UNREACHABLE`, `RATE_LIMIT`, `DAEMON_*`, `ROBOROCK_ERROR`…) | le cloud ou le démon est muet ; inutile de payer un `homedata` | **avance** |

Autrement dit le rang n'avance que sur une sonde **non concluante**, et un seul succès efface toute
l'escalade. `avecInventaire` est forcé à `false` sur ce chemin : coût quota nul.

## Impact i18n (FR uniquement)

Trois littérales nouvelles, toutes dans `core/class/jeeroborock.class.php`, en `__('…', __FILE__)` :
1. « Le compte Roborock doit être ré-authentifié : la session a expiré ou a été révoquée. Ouvrez la
   configuration du plugin et demandez un nouveau code de connexion. » — `message::add`.
2. « Si vous pensez qu'il s'agit d'une erreur, le bouton "Tester la connexion" revérifie la session sans
   consommer de quota. » — concaténée à la précédente.
3. « Démarrage du démon différé après %s échec(s) rapproché(s) : nouvelle tentative dans %s seconde(s). »
   — `launchable_message`, via `sprintf()` avec deux entiers (`intval()`), message injecté en HTML.

Aucune chaîne nouvelle côté AJAX, JS, `configuration.txt`/`.php` ni template. Les messages d'AC5
réutilisent `tableMessages()`. Ne pas toucher `core/i18n/*.json`.

## Risques & pièges

1. R1 — `setLevel()` sans `max()` augmenterait la verbosité (handler de `basicConfig` en NOTSET).
2. R2 — Perte de diagnostic assumée : plafonner `roborock.web_api` à WARNING supprime aussi la ligne INFO
   « Get Home Id failed with the following context », seule ligne révélant un code de refus autre que
   2010. Compensation : la sonde de compte journalise un warning explicite sur `ROBOROCK_ERROR`.
3. R3 — Suppression des traces complètes dans `canal.py` : perte du texte des exceptions de la librairie.
   Parade : `trace_sure()` conserve frames + chaîne de classes, le code stable est déjà journalisé.
4. R4 — Faux positif de l'état absorbant : un code 2010 émis pour une raison autre qu'une session morte
   est indistinguable. Parades : « Tester la connexion » guérit sans quota ; le message le dit.
   Aucun quota de login brûlé par un faux positif.
5. R5 — Faux négatif : révocation signalée par un code autre que 2010 -> `ROBOROCK_ERROR`, le plugin
   reste en « robot hors ligne ». Non détectable sans matériel. Point de recette. A CONFIRMER.
6. R6 — `launchable = 'nok'` grise aussi le bouton « Démarrer » de la modale démon pendant la fenêtre de
   backoff. Contrepartie assumée ; le message affiche les secondes restantes, plafond 1800 s.
7. R7 — **Compteur incrémenté à l'entrée de `deamon_start()` : il compte des démarrages, pas des crashs.**
   Trois chemins légitimes l'incrémentent donc sans qu'il y ait eu la moindre panne :
   - deux arrêts/relances manuels depuis la modale démon en moins de `DUREE_VIE_MIN_DEMON_S` ;
   - **`postConfig_portDemonHttp()` (l.585-595)**, qui appelle `deamon_stop()` **seul** quand l'admin
     change le port : c'est `plugin::checkDeamon()` qui relance à la minute suivante, et cette relance —
     voulue par l'admin — est comptée comme un échec ;
   - toute sauvegarde de configuration qui passe par le même patron.
   Conséquence bornée et identique dans les trois cas : au plus 60 s d'attente avant la relance suivante,
   effacée dès que le démon a vécu `DUREE_VIE_MIN_DEMON_S` sans mourir. ⚠️ **Ne pas traiter ce
   comportement comme un bug en review** : c'est le prix de l'alternative écartée (n'incrémenter qu'après
   un échec constaté), qui ne détecte justement pas le cas central d'AC7 — un démon qui démarre, répond
   au contrôle, puis meurt.
8. R8 — Purge du cache : remet à zéro le rang de sonde et le compteur de démarrages ; le drapeau, lui,
   vit en configuration.
9. R9 — La boucle MQTT de la librairie ne s'arrête jamais seule (plafond 6 h) ; c'est la fermeture du
   `DeviceManager` par `supervision.arreter()` qui la coupe. Si l'appel au démon échoue au moment du
   signalement, la reconnexion 6 h survit jusqu'au prochain redémarrage.
10. R10 — `supervision.arreter()` fermant le gestionnaire pendant usage : mécanisme déjà d'UC10 ; point
    de recette (absence de tâche dont l'exception n'est jamais récupérée).
11. R11 — Un futur chemin d'écriture de `userData` qui oublierait d'effacer le drapeau casserait AC2.
    Parade structurelle : `enregistrerSession()` est le seul écrivain, effacement adjacent. Alternative
    écartée : stocker l'empreinte du `userData` mort (placerait un condensat d'un secret dans une clé de
    config lisible par `config::getKey`, qui n'est pas admin-only).
12. R12 — Détection différée sur le chemin mémorisé : quand le `DeviceManager` est déjà construit, un
    réarmement ne refait aucun appel HTTPS. D'où l'utilité du push `compte` au redémarrage du démon :
    sans lui, chaque redémarrage sur session morte reconstruirait le gestionnaire et consommerait un
    jeton `homedata` (le limiteur est pris avant l'appel), à raison d'un réarmement toutes les 600 s —
    6 par heure pour un quota de 5 par heure.
13. R13 — Cadence d'échec à 600 s : si le push MQTT ne porte pas le réveil d'un robot rallumé, la
    détection peut prendre jusqu'à 600 s. A CONFIRMER en recette sur le Qrevo Curv.
14. R14 — Dette UC06/UC10 (dict de session construit inline en 4 exemplaires) non soldée : UC11 n'ouvre
    pas `authentification.py`. Écart conscient.
15. R15 — Volume de messages : `signalerReauthRequise()` idempotente et `message::removeAll` précède
    chaque ajout.

## Hors périmètre proposé (identifié, non planifié)

- Le hook d'autorisation MQTT de `create_device_manager` : donnerait une détection immédiate d'une
  révocation au niveau MQTT (code 135). Écarté : la sonde de compte couvre AC1 ; le hook impose un module
  supplémentaire pour éviter un cycle d'import entre `robots` et `supervision` ; il faudrait garder sa
  présence par introspection de signature pour ne pas risquer un second `create_device_manager` sur une
  montée de version.
- Bandeau serveur « ré-authentification requise » dans `configuration.txt` : redondant avec le centre de
  messages.
- Bornage de l'entrée brute avant `json_decode` dans `core/php/jeeJeeroborock.php` (dette de revue
  sécurité UC10, sévérité low).
- Diagnostic / désactivation du canal local (D9) : explicitement hors périmètre par la spec.

## Questions ouvertes

Aucune. Les deux points « À confirmer » de la spec sont traités : la durée de vie réelle des identifiants
dérivés reste inconnue mais n'engage aucune décision de conception ; la priorité « compte vs robot » est
tranchée en faveur du compte, structurellement.

## Hypothèses non vérifiables depuis ce dépôt

Deux affirmations de ce document reposent sur des sources absentes du dépôt et n'ont **pas** été relues
directement. Elles ne changent pas la conception, mais une recette qui les infirme rouvrirait le point :

1. **Comportement de la modale démon du core face à `launchable = 'nok'`** (R6) — le code du core Jeedom
   n'est pas dans ce dépôt. La conception s'appuie sur `.memory/analyse/jeedom-dependances-et-demon.md`
   § 4, déjà capitalisé et vérifié sur ce projet.
2. **Mapping `code == 2010` -> `RoborockInvalidCredentials`** dans `web_api.py` — `python-roborock` est
   une dépendance pip, non vendorée. Lu en source amont à la version épinglée 7.8.0, et recoupé de façon
   cohérente avec les specs techniques UC03, UC05, UC06 et UC07 qui citent toutes le même contrat.

## Points de recette ajoutés après review

1. **Démarrage à froid du démon (AC7, contre-test).** Distinct du scénario « échecs répétés -> délai
   croissant » : purger le cache du plugin, arrêter proprement le démon, puis le relancer depuis la page
   de configuration. **Il doit démarrer immédiatement**, sans message de délai. ⚠️ Ce test est celui qui
   intercepte l'inversion d'ordre décrite ci-dessus en B.2 — un backoff qui se déclenche sur une
   installation saine est indiscernable d'un plugin cassé pour l'utilisateur.
2. **Couverture de la liste blanche des loggers (AC6).** `PLAFONDS_LOGGERS_TIERS` est une liste **fermée**
   de 5 noms : un logger tiers non listé resterait au niveau racine choisi par l'utilisateur. En recette,
   démon lancé en `debug` avec connexion et MQTT établis, lister les loggers réellement instanciés
   (`logging.Logger.manager.loggerDict`) et vérifier qu'aucun logger actif de `python-roborock` 7.8.0 ni
   de ses dépendances n'échappe à la table. Ajouter les manquants le cas échéant.

## Dette

Findings de review sous la gate (`minor` / `low`), volontairement non corrigés dans cette UC.

- **Course entre deux process PHP sur `signalerReauthRequise()`** (sécurité, `low`) — la garde de
  ré-entrance est un `static` de méthode : elle ferme la récursion **dans un process**, pas la fenêtre
  entre deux requêtes concurrentes (ex. le cron et une action utilisateur au même instant), qui peuvent
  toutes deux lire le drapeau à faux avant que l'une ait persisté. Effets dupliqués **idempotents et sans
  coût de quota** (un `message::removeAll` + `message::add`, un appel `restaurerSession` local).
  Fermeture propre possible via un verrou de cache, non faite ici faute d'enjeu.
- **4ᵉ occurrence du patron « compteur en cache »** (qualité, `minor`) — `sondeCompteEtat`/`avancer`/
  `oublier` et `compteurDemarrages`/`marquer`/`oublier` reproduisent le squelette déjà présent en UC09
  (`synchroRoutinesRecente`) et UC10 (`relanceSupervisionRecente`). Un helper privé générique
  (`lireCompteurCache` / `ecrireCompteurCache`) est à extraire **au prochain cycle qui touche déjà cette
  zone** du fichier — pas dans cette UC, qui l'aurait fait au prix d'un refactor de trois UC livrées.

- **R14 (rappel)** — le dict `contexte['session']` reste construit inline en 4 exemplaires (dette
  d'UC06/UC10). UC11 n'ouvre pas `authentification.py`, la condition « au prochain cycle qui modifie déjà
  ce fichier » n'est donc pas remplie. À solder au prochain cycle qui touche la chaîne d'authentification.
