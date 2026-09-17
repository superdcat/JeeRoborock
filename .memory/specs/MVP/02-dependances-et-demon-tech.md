# Spec technique — UC02 : Dépendances Python et démon

> Spec fonctionnelle : `.memory/specs/MVP/02-dependances-et-demon.md`
> Dépend de : UC01 (`01-config-plugin-tech.md`) — réutilise `getPortDemonHttp()` et `estPortLocalOccupe()`.
> Plan produit par `jeedom-tech-planner`, challengé par `code-reviewer` (advisor), validé le 2026-09-17.

## Périmètre

Rendre le plugin **installable** (dépendance pip unique, correctement déclarée) et son démon Python
**démarrable, arrêtable et observable**, avant toute authentification. Aucune logique Roborock n'est
introduite : le démon ouvre son canal HTTP local, journalise la version de `python-roborock` et répond à
un healthcheck.

### Couverture des critères d'acceptation

| AC | Réalisé par | Statut |
|---|---|---|
| **AC1** — dépendance installée sans intervention | Réécriture de `plugin_info/packages.json` (pip3 `python-roborock` 7.8.0 **seul**) + suppression des entrées `npm`/`yarn`/`composer` du squelette (R1). L'installation est 100 % core (`plugin::setIsEnable` → `plugin::checkDeamon`). | couvert |
| **AC2** — démon actif **sans compte configuré** | `deamon_info()` ne consulte **jamais** `email`/`userData`. Le démon ne fait aucun appel réseau sortant bloquant au démarrage. | couvert |
| **AC3** — arrêt/redémarrage par le bouton standard | `deamon_stop()` / `deamon_start()` ; boutons servis par le core. | couvert (garde-fou 45 s du core, cf. Recette) |
| **AC4** — version de la lib dans les logs à chaque démarrage | Bannière **non filtrée** par le niveau de log dans `log/jeeroborock_demon` **+** remontée par le callback vers `log::add('jeeroborock','info',…)`. | couvert |
| **AC5** — avertissement si majeure > majeure validée | Même bannière + `log::add(warning)` + `message::add` (logicalId stable, retiré quand conforme). **Non bloquant.** | couvert |
| **AC6** — échec de démarrage visible, cause lisible | `deamon_info()['launchable_message']` (installation en cours / dépendances absentes / port occupé) ; `message::add` + `log::add(error)` si le démon ne monte pas dans le délai ; causes fatales explicites côté démon. | couvert |

### Briques volontairement non créées en UC02

- **`jeeroborockDaemon`** et **`jeeroborockException`** → UC03. Aucun appel PHP → démon n'existe encore :
  `deamon_info()` déduit l'état du **fichier PID**, pas d'une sonde HTTP (D-f). Introduire un client HTTP
  en UC02 créerait un second chemin d'accès au démon hors de la brique unique exigée par `CLAUDE.md`.
- **`jeeroborock::dependancy_info()`** → **jamais** (cadrage : code mort dès que `packages.json` existe).
  `additionnalDependancyCheck()` n'est pas implémenté non plus : il ne tourne que si l'état
  `packages.json` est déjà `ok` et coûterait un sous-processus Python à chaque `dependancy_info`.
- **`core/php/jeeroborock.inc.php`** : aucune ligne à ajouter. Le callback n'appelle que `jeeroborock::`,
  trouvée par l'autoloader via `core/class/jeeroborock.class.php` ; `jeeroborockDaemon` (UC03) aura son
  propre fichier `<Classe>.class.php`.
- **`plugin_info/install.php` / `pre_install.php`** : le core arrête déjà le démon et purge le dossier
  temporaire à la désactivation, et relance dépendances + démon à l'activation. Rien à écrire.
- Authentification (UC04), push MQTT (UC10).

## Architecture

### Fichiers

| Fichier | État | Contenu | Indentation / EOL |
|---|---|---|---|
| `plugin_info/packages.json` | **réécrit** | `pip3` → `python-roborock` → `version: "7.8.0"`, **rien d'autre** | 2 espaces, CRLF |
| `plugin_info/info.json` | modifié | ajout de `"requireOsVersion": "12"` (D-g) | 4 espaces (existant), CRLF |
| `core/class/jeeroborock.class.php` | modifié | 1 constante, `deamon_info/start/stop`, `postConfig_portDemonHttp`, `traiterVersionLibrairie`, 4 helpers privés | 2 espaces (existant), CRLF |
| `core/php/jeeJeeroborock.php` | **créé** | callback démon → Jeedom | 2 espaces, CRLF |
| `core/php/.htaccess` | modifié | conserve l'interdiction globale, ajoute une exception ciblée (D-a) | n/a |
| `resources/demond/jeeroborockd.py` | **créé** | point d'entrée du démon | 4 espaces (PEP 8), **LF** |
| `resources/demond/demond.py` | **supprimé** | remplacé par `jeeroborockd.py` (D-b) | — |
| `resources/demond/jeedom/jeedom.py` | **réécrit, allégé** | `jeedom_utils` + `jeedom_com` sur `urllib.request` (D-d) | 4 espaces, **LF** |
| `resources/demond/jeedom/jeedom.js` | **supprimé** | lib Node morte dans un démon Python | — |
| `.gitignore` | modifié | ajout de `resources/python_venv/` | n/a |

**Non modifiés** : `core/ajax/jeeroborock.ajax.php`, `desktop/**`, `plugin_info/configuration.txt`/`.php`,
`core/config/jeeroborock.config.ini`, `core/i18n/*.json` (traduction = étape `translator` en fin de
cycle). `pluginVersion` est incrémenté par le hook `pre-commit` : ne pas y toucher à la main. `os.min`
(12) / `os.max` (13.99), `hasDependency`, `hasOwnDeamon`, `maxDependancyInstallTime` (15) et `category`
sont **déjà conformes** dans `info.json` : ne rien changer d'autre que l'ajout de `requireOsVersion`.

### Fins de ligne des fichiers Python — exception documentée

Le dépôt travaille en **CRLF** dans la copie de travail, et `verif-plugin.py` l'exige pour
`.php/.js/.ini/.txt/.html`. Les `.py` sont **hors de ce périmètre** : `verif-plugin.py` ne les analyse
pas (`EXT_CODE`/`EXT_CRLF` ne les listent pas), `.gitattributes` n'impose rien sur `*.py` (sa seule règle
vise `.githooks/**`), et le bot `prettier` ne les reformate pas. Les fichiers Python sont donc écrits en
**LF** (idiomatique Python, et résultat naturel dans l'index avec `core.autocrlf=true`).
`resources/demond/jeedom/jeedom.py` est aujourd'hui stocké **CRLF dans l'index** : il est réécrit en LF —
sans coût de diff, puisqu'il passe de 321 à ~90 lignes et est réécrit de bout en bout.
Option **non retenue** : ajouter `*.py text eol=lf` dans `.gitattributes` (l'en-tête du fichier demande
explicitement de rester minimal).

### Décisions d'architecture

- **D-a — `core/php/.htaccess` : exception ciblée plutôt que suppression.** `CLAUDE.md` impose de
  conserver les `.htaccess` ; le callback impose d'être joignable. On garde l'interdiction globale et on
  ajoute un bloc `Files` pour `jeeJeeroborock.php` autorisant l'accès, en **syntaxe Apache 2.2 cohérente
  avec l'existant** (ne pas mélanger avec la syntaxe 2.4). **Repli documenté** si la recette montre un
  403 : supprimer `core/php/.htaccess`, comme le font tous les plugins Jeedom officiels (zigbee,
  zwavejs, mqtt2, blea — aucun n'en livre, alors que le template en fournit un).
- **D-b — le démon est renommé `jeeroborockd.py`.** `deamon_stop()` doit faire un `system::kill('<nom>')`,
  qui est un `ps ax | grep -ie '<nom>'` : garder `demond.py` **tuerait les démons des autres plugins
  issus du même squelette**. Le dossier reste `resources/demond/` (imposé par `CLAUDE.md`).
- **D-c — `jeedom_socket` supprimé, pas de port 55009.** Le canal *fire-and-forget* n'apporte aucune
  capacité que le canal HTTP synchrone (D3) n'ait déjà, et ajoute un second port d'écoute (zone de
  collision 55000-55100) plus un thread. Tout PHP → démon passera par le canal HTTP (UC03).
- **D-d — la lib `jeedom/jeedom.py` est forkée et allégée.** On garde les noms publics
  (`jeedom_com.add_changes/test`, `jeedom_utils.set_log_level/convert_log_level/write_pid`) pour rester
  lisible par un développeur Jeedom ; on supprime `jeedom_serial`, `jeedom_socket*`, `find_tty_usb` et
  les helpers binaires ; on remplace `requests` par **`urllib.request` (stdlib)**. Motif **bloquant**
  (R2) : `requests`, `pyserial` et `pyudev` ne sont pas des dépendances de `python-roborock` — la lib du
  squelette lève `ImportError` dans le venv du plugin. Effet de bord bienvenu : la désactivation de
  vérification TLS du squelette disparaît par construction.
- **D-e — la majeure validée est une constante en dur du démon** (`MAJEURE_VALIDEE = 7`), pas une lecture
  de `packages.json`. Lire `packages.json` supprimerait la duplication mais **annulerait le garde-fou** :
  une montée de version deviendrait silencieuse, alors que le cadrage exige une décision explicite. La
  constante représente « la majeure contre laquelle le code a été testé », pas « celle qu'on demande à
  pip ». **Source de vérité unique côté Python** : le PHP ne fait que relayer la valeur reçue (m3 —
  aucune constante miroir en PHP, PHP et Python étant livrés par le même paquet plugin, ils ne peuvent
  pas se désynchroniser hors mise à jour partielle).
- **D-f — `state` déduit du fichier PID (+ `/proc`), pas d'un appel HTTP.** Évite d'introduire un client
  HTTP PHP hors de la brique unique `jeeroborockDaemon` (UC03). Deux durcissements **obligatoires** :
  vérifier que `/proc/<pid>/cmdline` contient le nom du démon (un PID recyclé ferait croire le démon
  vivant → jamais relancé → panne silencieuse), et **réécriture périodique du fichier PID par le démon**
  (`systemd-tmpfiles` purge `/tmp` des fichiers non touchés depuis 10 jours → un démon vivant depuis 10
  jours perdrait son PID et serait déclaré mort).
- **D-g — `"requireOsVersion": "12"` ajouté à `info.json`.** Vérifié en source : `plugin::byId()` l. 74
  lit `info.json` et l. 100 en extrait `requireOsVersion` ; `plugin::setIsEnable()` l. 967-973 refuse
  l'activation quand `getDistrib() == 'debian'` et `version_compare(system::getOsVersion(), $osVersion)
  == -1`, avec un message explicite. **`os.min`/`os.max` n'est lu nulle part dans `plugin::byId()`** :
  c'est un champ *market*, pas un verrou d'activation — c'est le trou que D-g bouche. Sans cette clé, une
  activation sur Debian 11 crée un venv Python 3.9 où pip refuse `python-roborock` → indicateur de
  dépendance bloqué à NOK sans cause lisible.
  ⚠️ **Doute résiduel assumé** : la clé est **non documentée** (absente de `structure_info_json`) et
  aucun plugin officiel inspecté ne l'utilise. Le chemin de code est sans ambiguïté, mais la tolérance du
  Market / de l'updater à une clé inconnue dans `info.json` n'est pas prouvée → point de recette
  **R-osmin**. Fail-open partout ailleurs (distribution non-Debian → test sauté ; `/etc/debian_version`
  valant `trixie/sid` → comparaison de chaînes, pas de blocage).

## Server vs Client

**Tout est serveur.** Aucun JS n'est ajouté : les boutons démarrer/arrêter/gestion automatique et le
rafraîchissement périodique de l'état sont fournis par le core (`desktop/modal/plugin.deamon.php`). Aucun
endpoint AJAX plugin n'est créé → pas de `isConnect()` à arbitrer côté handler ; le seul
`session_write_close()` est celui, **gardé**, de `deamon_start()`.

## Contrat du core (ce sur quoi on s'appuie, et ce qui ne s'applique pas)

- `plugin::deamon_info()` appelle `jeeroborock::deamon_info()` **sans try/catch** → notre méthode ne doit
  **jamais** lever, sous peine de casser la modale démon, `plugin.ajax.php` et `plugin::checkDeamon`
  (R6). Le core complète ensuite avec `auto`, `last_launch`, et force `launchable = 'nok'` si les crons
  sont désactivés ou si Jeedom n'est pas démarré.
- ⚠️ **La rétrogradation automatique « Dépendances non installées » du core est conditionnée à
  `method_exists($plugin_id, 'dependancy_info')`.** Le cadrage interdisant cette méthode, **ce garde-fou
  ne s'applique pas** : c'est `jeeroborock::deamon_info()` qui doit porter lui-même le contrôle de
  dépendance (R4).
- `plugin::deamon_start()` n'appelle notre hook que si `launchable == 'ok' && state == 'nok'`, impose
  **45 s minimum entre deux lancements**, et **enveloppe notre méthode dans un `catch` qui se contente
  d'un `log::add(error)`** → une exception levée par `deamon_start` **n'atteint jamais l'utilisateur**.
  D'où la cause portée par `launchable_message` + centre de messages.
- `plugin::deamon_stop()` n'appelle notre hook que si `state == 'ok'`.
- `plugin::checkDeamon()` : chaque minute, `dependancy_info()` (auto-install si `nok`) puis
  `deamon_start(false, true)`. C'est **tout** le mécanisme d'AC1 + AC2 — aucun code plugin supplémentaire.
- `system::checkAndInstall` apparie par **clé nue minusculée** contre `pip list --format=json` du venv
  (donc la clé *doit* être `python-roborock`) et compare par `version_compare` : la version déclarée est
  un **minimum** pour le core, alors que la commande d'installation épingle `==7.8.0`. Sur Debian ≥ 12 le
  core crée lui-même le venv `plugins/jeeroborock/resources/python_venv` et installe
  `python3 python3-pip python3-dev python3-venv` en apt → **aucune entrée `apt` nécessaire**.
- Fichier de progression d'installation : **`/tmp/jeedom_install_in_progress_jeeroborock`** (chemin
  `/tmp` **en dur** dans le core — ce n'est pas `folder::tmp`). Log d'installation :
  `log/jeeroborock_packages`.

## Signatures

```php
const DELAI_DEMARRAGE_DEMON = 30;  // secondes d'attente maximale dans deamon_start

public  static function deamon_info()                       // -> array ; NE LÈVE JAMAIS
public  static function deamon_start()                      // -> bool  ; throws Exception (avalée par le core)
public  static function deamon_stop()                       // -> void  ; ne lève jamais
public  static function postConfig_portDemonHttp($_value)   // -> void
public  static function traiterVersionLibrairie($_donnees)  // -> void  ; appelée par le callback
public  static function nettoyerPourLog($_valeur)           // -> string ; neutralise une valeur externe
private static function cheminFichierPid()                  // -> string
private static function cheminFichierPort()                 // -> string
private static function pidDemonActif()                     // -> int (0 si aucun) ; nettoie un PID périmé
private static function causeNonLancable()                  // -> string ('' si lançable)
```

Chemins : `jeedom::getTmpFolder('jeeroborock') . '/demon.pid'` et `/demon.port`.

### `deamon_info()`

Retourne **toujours** les 4 clés `log` (`'jeeroborock_demon'`), `state` (`ok|nok`), `launchable`
(`ok|nok`), `launchable_message`. Elles sont toutes posées explicitement parce que la méthode est aussi
appelée en statique direct depuis `deamon_start()`, où le complément de clés du core ne s'applique pas.

Si `pidDemonActif() > 0` → `state = 'ok'`, **retour immédiat, aucune sonde**. Sinon `causeNonLancable()`
décide de `launchable`/`launchable_message`. **Tout le corps est enveloppé dans un `try`/`catch
(Throwable)`** qui retourne un tableau valide (state `nok`, launchable `nok`, message de repli) et logge
l'erreur. **Ne consulte ni `email` ni `userData`** (AC2).

⚠️ `launchable_message` est injecté **en HTML** par la modale démon du core : n'y mettre que des
littérales traduites et des entiers, jamais une valeur libre non filtrée.

### `causeNonLancable()` *(privée)*

Dans cet ordre, premier message non vide gagne :

1. `file_exists('/tmp/jeedom_install_in_progress_jeeroborock')` → « Les dépendances Python sont en cours
   d'installation. »
2. Interpréteur du venv absent → « Les dépendances Python ne sont pas installées. »
   **`system::getCmdPython3('jeeroborock')` ne dépend pas de l'existence du venv** : c'est une pure
   fonction de l'OS (`''` ou Debian < 12 → `'python3 '` ; sinon
   `getPython3VenvDir($_plugin) . '/bin/python3 '`, espace finale incluse). Le test de forme (chemin
   absolu ou non) sert **uniquement** à savoir dans quel mode est le core ; la décision vient d'un
   `file_exists()` sur **le chemin exact que le core utilise lui-même pour créer le venv**
   (`checkAndInstall` : `python3 -m venv --upgrade-deps <getPython3VenvDir($_plugin)>`). Les deux
   viennent de la même fonction : pas d'hypothèse.
   ⚠️ **Quand cette branche déclenche, logger en `debug` le chemin résolu** — c'est ce qui rend une
   hypothèse fausse diagnosticable en une ligne plutôt qu'invisible (concession retenue face au risque
   de blocage permanent soulevé en review).
3. `estPortLocalOccupe(self::getPortDemonHttp())` → message UC01 sur le port déjà utilisé (AC6 ; c'est
   aussi le branchement promis par l'AC4 partiellement couvert d'UC01).
4. `''` (lançable).

**Ce contrôle est bloquant, et c'est délibéré** : si le binaire du venv manque, l'`exec()` ne produit
**rien** — un *fail-open* ne ferait pas démarrer le démon, il remplacerait une cause lisible par 30 s
d'attente vide **à chaque minute de cron**. Le blocage n'est pas durable : il s'auto-résout dès que pip a
créé le venv, sans action manuelle. L'étape 1 ne couvre que la fenêtre d'installation — ni
`dependancyAutoMode` à 0, ni « venv effacé ».

### `deamon_start()`

`deamon_stop()` → `deamon_info()` → `throw` si `launchable != 'ok'` (message = `launchable_message`) →
construction de la commande → `log::add(debug)` de la commande **avec l'apikey masquée** → écriture de
`demon.port` → `exec($cmd . ' >> ' . log::getPathToLog('jeeroborock_demon') . ' 2>&1 &')` →
`session_write_close()` **gardé par `session_status() === PHP_SESSION_ACTIVE`** (le hook est aussi appelé
depuis le cron, sans session) → boucle d'attente d'au plus `DELAI_DEMARRAGE_DEMON` × 1 s sur
`deamon_info()['state']`.

En échec : `log::add(error)` + `message::add(…, 'demarrageDemon')` avec un lien vers le log du démon,
retour `false`. En succès : `message::removeAll('jeeroborock', 'demarrageDemon')`, retour `true`.

⚠️ **Ne pas reproduire le bug de l'exemple du wiki** (boucle bornée à 20 puis test de sortie à 30 :
condition jamais vraie, échec jamais signalé).

Ligne de commande, arguments **dans cet ordre**. `$port`, `$apikey` et le fragment d'URL de callback
passent par `escapeshellarg()` — défense en profondeur : aucun n'est exploitable aujourd'hui
(`preConfig_portDemonHttp` garantit `ctype_digit` et la plage 1024-65535, l'apikey est générée par le
core), mais le contrat peut changer.

⚠️ **Le masquage de l'apikey dans le log doit chercher la forme ÉCHAPPÉE**
(`str_replace(escapeshellarg($apikey), escapeshellarg('********'), $commande)`), pas la forme brute :
`escapeshellarg()` transforme une apostrophe en `'\''` et **fragmente** donc la sous-chaîne recherchée.
Un masquage sur la forme brute échoue alors **silencieusement** et l'apikey part en clair dans le log —
c'est le couple `escapeshellarg()` + masquage qui crée le piège, aucun des deux pris isolément.

```php
system::getCmdPython3('jeeroborock')        // se termine déjà par une espace
  . realpath(__DIR__ . '/../../resources/demond') . '/jeeroborockd.py'
  . ' --loglevel '  . log::convertLogLevel(log::getLogLevel('jeeroborock'))
  . ' --port '      . self::getPortDemonHttp()
  . ' --callback '  . network::getNetworkAccess('internal', 'http:127.0.0.1:port:comp')
                    . '/plugins/jeeroborock/core/php/jeeJeeroborock.php'
  . ' --apikey '    . jeedom::getApiKey('jeeroborock')
  . ' --pid '       . self::cheminFichierPid()
```

### `deamon_stop()`

`system::kill($pid)` si `pidDemonActif() > 0`, puis `system::kill('jeeroborockd.py')` (filet pour un
démon mort avant d'avoir écrit son PID), `@unlink` de `demon.pid` et `demon.port`, `sleep(1)` (libération
du port avant une relance). **Ne lève jamais.**

### `pidDemonActif()` *(privée)*

Lit `demon.pid` ; vérifie que `/proc/<pid>/cmdline` contient le **chemin absolu** du script
(`realpath(__DIR__ . '/../../resources/demond') . '/jeeroborockd.py'`, le même que celui construit par
`deamon_start()`) ; repli `@posix_getsid($pid)` si `/proc/<pid>` est absent (process mort, ou système
sans `/proc`) ; PID invalide → suppression du fichier et retour `0`.

⚠️ La comparaison porte sur le **chemin absolu**, pas sur la sous-chaîne `jeeroborockd.py` : couplée à
`system::kill($pid)`, une comparaison lâche ferait tuer un processus non lié dont la ligne de commande
contient ce nom (le répertoire temporaire du plugin est prévisible).

### `postConfig_portDemonHttp($_value)`

**Ne fait rien** si `demon.port` est absent **ou égal** à la nouvelle valeur ; sinon `log::add(info)` +
`deamon_stop()` (la relance vient de `plugin::checkDeamon` sous une minute).

⚠️ **Le garde est indispensable** : `postConfig_<clé>` est appelé à **chaque** enregistrement de la page
de configuration, y compris quand la valeur ne change pas (et même quand `preConfig_<clé>` est
court-circuité par la valeur par défaut). Sans lui, enregistrer la configuration redémarrerait le démon —
ce qui, **en UC04, détruirait l'instance `RoborockApiClient` entre l'envoi du code e-mail et sa
validation**. À rappeler dans la spec technique d'UC04.

### `traiterVersionLibrairie($_donnees)`

Lit `version` (string, `trim` + troncature), `majeureValidee` (**`intval`**) et `avertissement` (bool).
`log::add(info)` systématique ; si avertissement → `log::add(warning)` +
`message::add(…, 'version_librairie')` ; sinon `message::removeAll('jeeroborock', 'version_librairie')`.

⚠️ **Règle générale, pas un cas particulier de `majeureValidee`** : le corps de `message::add()` est rendu
**en HTML brut** par l'UI Jeedom (`deamon_start()` s'en sert d'ailleurs délibérément pour injecter une
balise de lien). **Toute** valeur issue du callback — donc du réseau — doit être neutralisée avant d'y
entrer : `intval()` pour un entier, **`htmlspecialchars($v, ENT_QUOTES, 'UTF-8')` pour une chaîne
libre**. `version` est une chaîne libre : la tronquer à 32 caractères **ne protège de rien**
(`<script>alert(1)</script>` tient dans 32 caractères).

⚠️ **Journalisation d'une valeur externe** : `log::add()` n'est pas rendu en HTML, mais concaténer une
valeur non filtrée y permet de **forger de fausses lignes de log** via `\n`/`\r`. Toute valeur venant du
callback passe par `jeeroborock::nettoyerPourLog($_valeur)` (retrait des caractères de contrôle +
garantie d'UTF-8 valide) avant d'atteindre un `log::add()`. Le helper est **public** parce que
`core/php/jeeJeeroborock.php`, point d'entrée externe, doit pouvoir l'appeler — il n'appelle que
`jeeroborock::`, conformément à la règle d'autoload.

Ces deux règles valent pour **toutes** les UC suivantes : UC03 et UC04 feront transiter par ce même
callback des données de bien plus grande surface (états du robot, libellés de routines saisis par
l'utilisateur dans l'application mobile).

## Server Actions / API

### Callback démon → Jeedom : `core/php/jeeJeeroborock.php` *(créé)*

Séquence : `require_once __DIR__ . '/../../../../core/php/core.inc.php'` →
`if (!jeedom::apiAccess(init('apikey'), 'jeeroborock'))` → **HTTP 401** + `log::add(warning)` + `die()` →
`if (init('test') != '') { echo 'OK'; die(); }` → `json_decode(file_get_contents('php://input'), true)`,
`die()` si le résultat n'est pas un tableau → `isset($resultat['versionLibrairie'])` →
`jeeroborock::traiterVersionLibrairie(...)` ; sinon `log::add(debug)` des clés reçues.

Le tout dans `try`/`catch (Throwable)` avec `log::add('jeeroborock', 'error', … $e->getMessage())` —
**`getMessage()`, jamais `displayException()`** : la trace expose les arguments de chaque frame, et ce
fichier recevra des données sensibles dès UC04. **Aucune chaîne `__()`** : le seul consommateur est une
machine.

⚠️ Le squelette du wiki répond `200` avec un message sur apikey invalide, ce qui rend le test du démon
inopérant → on impose **401**.

### Canal HTTP local du démon *(contrat posé ici, consommé en UC03)*

| Élément | Valeur |
|---|---|
| Écoute | **`127.0.0.1` uniquement**, port = `jeeroborock::getPortDemonHttp()` |
| Authentification | en-tête **`X-Apikey`**, comparé en temps constant (`hmac.compare_digest`) |
| Route UC02 | `GET /sante` |
| Réponse OK | `success: true`, `data: {version, majeureValidee, avertissementVersion, callback, pid, dureeFonctionnement}` |
| Réponse KO | HTTP 401/4xx + `success: false`, `error: {code, message}` |
| Journal d'accès `aiohttp` | **désactivé** (`access_log=None`) — les URL transporteront un code e-mail en UC04 |

### Démon : `resources/demond/jeeroborockd.py` *(créé, ~180 lignes, noms français)*

```python
MAJEURE_VALIDEE = 7            # version majeure de python-roborock validée par le plugin
VERSION_VALIDEE = "7.8.0"      # à garder en phase avec plugin_info/packages.json
ADRESSE_ECOUTE  = "127.0.0.1"  # jamais d'écoute sur toutes les interfaces
INTERVALLE_MAINTIEN_PID = 60

analyser_arguments()                        -> Namespace(loglevel, port, callback, apikey, pid)
journaliser_brut(niveau, message)           -> None   # print(flush=True), format [date][NIVEAU] : msg
detecter_version()                          -> (version: str|None, avertissement: bool)
construire_application(apikey, contexte)    -> web.Application
verifier_apikey(requete, handler)           # middleware aiohttp -> 401 + enveloppe d'erreur
handler_sante(requete)                      -> web.Response (JSON)
demarrer_serveur(application, port)         -> (runner, site)   # lève OSError si bind impossible
maintenir_pid(chemin, evenement_arret)      -> None   # tâche asyncio, réécrit le PID toutes les 60 s
principal(args)                             -> int    # code de sortie
```

- Import de `roborock` dans un `try`/`except ImportError` **au niveau module** ; si absent → message
  fatal explicite + **exit 3**. L'import **réel** (et pas seulement `importlib.metadata`) est ce qui rend
  AC4 littéralement vrai (« version effectivement chargée ») et attrape une installation cassée.
- ⚠️ **La bannière de version est écrite avec `journaliser_brut`, donc non filtrée par `--loglevel`** :
  c'est ce qui sauve AC4/AC5, car le niveau de log Jeedom vaut **`error` (400) par défaut** — un
  `logging.info` serait muet sur une installation neuve.
- Ordre de démarrage : bannière version → `jeedom_com.test()` → bind du serveur → **écriture du PID** →
  push `versionLibrairie` → tâche de maintien du PID → attente de `SIGTERM`/`SIGINT`.
  **Le PID est écrit *après* un bind réussi** : `state == 'ok'` signifie donc « le démon écoute », ce qui
  est exactement la sémantique dont `deamon_info()` a besoin.
- `finally` : suppression du PID, `runner.cleanup()`.
- Codes de sortie : **0** arrêt normal, **2** port indisponible, **3** dépendance absente/inutilisable.
- **`jeedom_com.test()` en échec n'est PAS fatal en UC02** (le squelette, lui, coupe le démon) : le canal
  **montant** HTTP — celui dont dépendent UC03/UC04 — reste utilisable, et AC2 tient même avec un
  callback mal configuré. L'échec est journalisé en `error` avec le code HTTP, et `/sante` expose
  `callback: false` pour qu'UC03/UC05 puissent le remonter. **UC10 pourra le repasser en fatal.**
- ⚠️ **Ne jamais journaliser l'apikey** — le squelette la logge en clair (`demond.py` l. 119) : à
  supprimer, pas à recopier.

### Lib forkée : `resources/demond/jeedom/jeedom.py` *(réécrite, allégée)*

```python
class jeedom_utils:
    convert_log_level(level='error') -> int
    set_log_level(level='error')     -> None
    write_pid(chemin)                -> None   # crée le répertoire parent si nécessaire

class jeedom_com:
    __init__(apikey='', url='', cycle=0, retry=3)
    add_changes(cle, valeur)         -> None
    send_change_immediate(change)    -> None   # Thread
    test()                           -> bool   # GET apikey + test=1, exige HTTP 200 ET corps 'OK'
    merge_dict(d1, d2)               -> None
```

Supprimés : `jeedom_serial`, `jeedom_socket`, `jeedom_socket_handler`, `JEEDOM_SOCKET_MESSAGE`,
`find_tty_usb`, les helpers binaires (`ByteToHex`, `dec2bin`, `dec2hex`, `testBit`, `clearBit`,
`split_len`, `printHex`, `remove_accents`, `stripped`), et les imports `serial`, `pyudev`, `requests`.
En-tête de fichier signalant explicitement qu'il s'agit d'un **fork allégé** de la lib Jeedom.
`cycle=0` (envoi immédiat : aucun lot à batcher en UC02). Timeout `urlopen` = 15 s.
⚠️ **Ne jamais journaliser l'URL** : l'apikey y figure en query string.

## Validation & erreurs

| Situation | Détecté où | Restitution utilisateur |
|---|---|---|
| Dépendances en cours d'installation | `deamon_info()` (fichier de progression du core) | badge NOK + « Les dépendances Python sont en cours d'installation. » |
| Dépendances absentes (venv sans interpréteur) | `deamon_info()` | badge NOK + « Les dépendances Python ne sont pas installées. » + chemin résolu en `debug` |
| Port local occupé | `deamon_info()` via `estPortLocalOccupe()` (UC01) | badge NOK + **chaîne UC01 réutilisée** sur le port déjà utilisé |
| Démon lancé mais absent au bout de 30 s | boucle d'attente de `deamon_start()` | `log::add(error)` + message au centre de messages, avec lien vers `log/jeeroborock_demon` |
| Bind impossible côté démon | `jeeroborockd.py` (`OSError`) | ligne fatale non filtrée dans le log du démon, **exit 2** ; état NOK + cause au cycle `deamon_info` suivant |
| `python-roborock` absent / import cassé | `jeeroborockd.py` (`ImportError`) | ligne fatale non filtrée, **exit 3** |
| Majeure de la lib > 7 | `jeeroborockd.py` + callback | bannière `ATTENTION`, `warning` dans le log plugin, message au centre de messages (logicalId `version_librairie`) — **jamais bloquant** |
| Callback injoignable | `jeedom_com.test()` | `error` dans le log démon avec le code HTTP ; `callback: false` dans `/sante` ; **démon maintenu actif** |
| Apikey invalide sur `/sante` | middleware `aiohttp` | HTTP 401 + enveloppe d'erreur, code `UNAUTHORIZED` |
| Apikey invalide sur le callback | `jeeJeeroborock.php` | HTTP 401 + `log::add(warning)` |

**Typage des exceptions** : `Exception` nue (message français via `__(…, __FILE__)`) dans
`deamon_start()` — le core n'exploite que le message et l'avale en log. **Pas de `jeeroborockException`
en UC02** : elle arrive en UC03 avec ses codes stables (`AUTH_EXPIRED`, `RATE_LIMIT`,
`DEVICE_OFFLINE`…), il serait prématuré de la figer ici. `deamon_info()` et `deamon_stop()` **ne lèvent
jamais**.

**Budget de temps** : `deamon_start()` plafonné à **30 s** (les imports `protobuf`/`Pillow`/
`pycryptodome` sont lents sur ARM bas de gamme) ; la sonde de port est plafonnée à **0,3 s** et n'est
évaluée **que** démon arrêté ; `deamon_info()` en régime nominal ne fait **aucun appel réseau ni shell**
(un `file_get_contents` de PID + une lecture `/proc`) — indispensable puisqu'elle est appelée chaque
minute par `plugin::checkDeamon` **et** à chaque rafraîchissement de la modale.

**Secrets** : l'apikey du plugin transite en **argument de ligne de commande** (donc visible dans `ps` —
convention du core, sur laquelle reposent `system::kill`/`system::ps` ; limitation acceptée et
documentée) et en query string du callback. Elle ne doit apparaître **ni** dans `log::add` (masquage par
`str_replace` de l'apikey dans la commande loggée), **ni** dans les logs du démon, **ni** dans le journal
d'accès `aiohttp` (désactivé). Aucun secret Roborock n'existe encore à ce stade.

## Dépendances

`plugin_info/packages.json` est **réécrit** avec la seule entrée :

```json
{ "pip3": { "python-roborock": { "version": "7.8.0" } } }
```

- La version va dans la **valeur**, jamais dans la clé (le core apparie la **clé nue**), et **sans aucun
  opérateur de comparaison** (le core concatène le champ sans le quoter → redirection shell).
- `python-roborock` 7.8.0 exige **Python ≥ 3.11** (`requires_python: >=3.11,<4`) → confirme `os.min = 12`
  et motive D-g. Ses dépendances transitives : `aiohttp`, `aiomqtt`, `construct`, `paho-mqtt`,
  `protobuf`, `pycryptodome`, `pyrate-limiter`, `vacuum-map-parser-roborock`.
- ✅ **`aiohttp` est une dépendance directe** → le serveur HTTP local n'ajoute **aucune** dépendance.
- ⚠️ **`requests`, `pyserial` et `pyudev` n'en font PAS partie** → motif bloquant du fork de
  `jeedom/jeedom.py` (D-d, R2).
- **Aucune entrée `apt`** : sur Debian ≥ 12 le core installe lui-même `python3`, `python3-pip`,
  `python3-dev`, `python3-venv` et crée le venv.
- **Suppression obligatoire des entrées `npm`/`yarn`/`composer` du squelette** (R1).

## Impact i18n (français uniquement dans cette UC)

Nouvelles littérales dans `core/class/jeeroborock.class.php`, toutes en `__('…', __FILE__)` :

- « Les dépendances Python sont en cours d'installation. »
- « Les dépendances Python ne sont pas installées. »
- « Le démon ne peut pas être démarré : %s »
- « Le démon n'a pas démarré dans le temps imparti, consultez le log du démon. »
- « Log du démon » *(libellé du lien d'action du message)*
- « Impossible de déterminer l'état du démon, consultez le log du plugin. » *(repli du `catch (Throwable)`)*
- « La version %s de python-roborock est plus récente que la version majeure validée par le plugin (%s) :
  le fonctionnement n'est pas garanti. »

**Réutilisée sans créer de clé** : le message UC01 sur le port du canal local déjà utilisé.

Aucune nouvelle chaîne dans `configuration.txt`, `desktop/**` ou le callback. Les messages `log::add` et
ceux du démon restent en **français non enveloppé** (les logs ne se traduisent pas). `sprintf()` **autour**
de `__()`, jamais `__($variable)`. Les fichiers `core/i18n/*.json` ne sont **pas** touchés pendant
l'implémentation (étape `translator` en fin de cycle).

⚠️ Contrôle obligatoire avant commit : `python .claude/scripts/verif-plugin.py` (colonne `meta=`).

## Risques & pièges

- **R1 (bloquant AC1)** — entrées `npm`/`yarn`/`composer` du squelette : chemin comportant une faute de
  frappe (`ressources/demond`) et absence de `composer.json` → `status = 0` non optionnel →
  `dependancy_info()` renvoie `nok` **définitivement**, même dépendance pip installée. Leur suppression
  est la condition d'AC1.
- **R2 (bloquant AC2)** — la lib `jeedom/jeedom.py` du squelette ne peut pas s'importer dans le venv du
  plugin (`serial`, `pyudev`, `requests` absents). Sans le fork allégé, le démon meurt à l'import.
- **R3 (majeur)** — `core/php/.htaccess`. Si l'exception ciblée n'est pas prise en compte
  (`AllowOverride` restrictif, `mod_access_compat` absent), le callback renvoie 403/500. En UC02 :
  `test()` échoue, log d'erreur, **démon maintenu actif**. En UC10 : push cassé. **Repli** : supprimer
  `core/php/.htaccess`.
- **R4** — le garde-fou « dépendances » du core ne s'applique pas (`method_exists(…, 'dependancy_info')`)
  parce que le cadrage interdit cette méthode. Si `deamon_info()` ne porte pas le contrôle lui-même, le
  core lance le démon pendant l'installation pip, échoue trois fois et logge un souci de démon.
- **R5** — purge de `/tmp` par `systemd-tmpfiles` (10 jours), traitée par la réécriture périodique du
  PID ; sans elle, un démon vivant depuis 10 jours est déclaré mort, le port est vu occupé, et le plugin
  se bloque tout seul.
- **R6** — une exception de `deamon_info()` casse la modale démon et `checkDeamon` (aucun try/catch côté
  core) → `catch (Throwable)` interne obligatoire.
- **R7** — dérive de version : la version déclarée est un **minimum** pour le core alors que
  l'installation épingle `==7.8.0` ; un `pip install` manuel peut faire monter la lib sans que
  `packages.json` change. C'est précisément ce que la bannière et l'avertissement de majeure rendent
  visible.
- **R8** — ARM 32 bits : aucune entrée `apt` ajoutée (les wheels manylinux couvrent amd64/arm64). Sur
  armhf sans chaîne de compilation, l'installation pip peut échouer ; la cause sera dans
  `log/jeeroborock_packages`. Pénaliser 100 % des installations avec `build-essential` pour ce cas
  minoritaire n'est pas justifié. **À confirmer en recette si une cible 32 bits apparaît.**
- **R9** — `system::kill('jeeroborockd.py')` repose sur un `ps | grep` : il tuerait un autre processus
  dont la ligne de commande contient cette chaîne (éditeur, `tail`). Risque standard Jeedom, accepté ;
  c'est le **nom unique** qui le rend acceptable (D-b).
- **R10** — fiabilité de la sonde de port (héritée d'UC01 R4) : `fsockopen` ne voit qu'un socket TCP en
  écoute sur `127.0.0.1` ; c'est un confort de diagnostic, pas une garantie.
- **R11** — contrainte sur l'avenir : `postConfig_portDemonHttp` ne doit **jamais** redémarrer le démon
  inconditionnellement, sous peine de casser le flux d'authentification d'UC04. **À rappeler dans la spec
  technique d'UC04.**
- **R12** — rework prévisible en UC03 : `deamon_info()` pourra être durci en interrogeant `/sante` via
  `jeeroborockDaemon`, et les routes du démon seront probablement extraites vers un module dédié. Rien à
  anticiper aujourd'hui.

## Recette (à confirmer sur une Jeedom réelle, Debian 12+)

1. `curl` sur le callback avec `test=1` → **200** et corps `OK` (valide R3).
2. Indicateur de dépendances : NOK → *en cours* → OK **sans intervention**, en moins de 15 min (AC1) ;
   log `jeeroborock_packages` exploitable.
3. Démon actif **sans e-mail ni `userData` enregistrés** (AC2) ; `ss -ltnp` montre une écoute sur
   **`127.0.0.1` uniquement**, jamais sur toutes les interfaces.
4. `log/jeeroborock_demon` contient la bannière de version **avec le niveau de log Jeedom laissé au
   défaut (`error`)** — c'est le test qui valide AC4.
5. **AC3** : arrêt puis redémarrage. ⚠️ Un redémarrage **moins de 45 s** après le lancement précédent est
   refusé par le core — ce n'est **pas** une régression du plugin.
6. **AC6** : occuper le port 61350 (`nc -l 127.0.0.1 61350`), démon arrêté → la modale démon doit afficher
   **NOK + la phrase sur le port**.
7. **AC5** : abaisser temporairement `MAJEURE_VALIDEE` à 6 → bannière `ATTENTION` + message au centre de
   messages, **démon toujours actif**.
8. Changer le port dans la configuration → le démon s'arrête et revient sur le nouveau port en moins de
   2 min ; **réenregistrer la configuration sans changer le port ne doit rien redémarrer**.
9. **R-osmin** (D-g) — désactiver puis réactiver le plugin : l'activation doit **réussir**
   (non-régression : `requireOsVersion` ne doit pas bloquer une plateforme supportée). Vérifier aussi que
   l'installation/mise à jour depuis le dépôt GitHub accepte un `info.json` contenant `requireOsVersion`
   (clé **non documentée**, non utilisée par les plugins officiels) : **au moindre refus du Market ou de
   l'updater, retirer la clé** — ce n'est qu'un durcissement, `os.min` reste déclaré. Le refus effectif
   sous Debian 11 n'est **pas** testable sur le parc de recette : il est acquis par lecture de source
   (`plugin::setIsEnable()` l. 967-973) et doit être noté comme tel.
10. `os.max` : **13.99** est cohérent avec `python-roborock` (`>=3.11,<4`, Debian 13 = Python 3.13) —
    **aucun changement recommandé**. L'item « À confirmer » de la spec fonctionnelle est clos ainsi.

## Dette

- **Reconduite d'UC01** : les 54 chaînes françaises du squelette non traduites dans
  `desktop/php/jeeroborock.php`, `desktop/js/jeeroborock.js`, `core/ajax/jeeroborock.ajax.php`,
  `desktop/modal/modal.jeeroborock.php` — aucun de ces fichiers n'est touché par UC02.
