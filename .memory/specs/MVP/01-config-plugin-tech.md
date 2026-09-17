# Spec technique — UC01 : Configuration du plugin

> **Spec fonctionnelle** : `.memory/specs/MVP/01-config-plugin.md` · **Domaine** : MVP · **Dépend de** : —
> **Plan validé le** : 2026-09-17

## Périmètre

Poser le socle de configuration plugin de `jeeroborock` : formulaire `plugin_info/configuration.php`
(e-mail du compte Roborock, port du canal HTTP local avec le démon), clé chiffrée `userData` réservée à
l'UC04, validation serveur des deux champs, et brique réutilisable de détection de port occupé.

**Aucun appel réseau sortant n'est introduit** — ni vers le cloud Roborock (UC04), ni vers le démon
(UC02/UC03). La seule socket ouverte est une sonde locale sur `127.0.0.1`.

### Couverture des critères d'acceptation

| AC | Couvert par | Statut |
|---|---|---|
| **AC1** — champ e-mail + champ port (valeur par défaut renseignée) + sélecteur de niveau de log ; aucun champ mot de passe | Réécriture du formulaire de `plugin_info/configuration.txt` (2 champs `configKey`) + création de `core/config/jeeroborock.config.ini` + **sélecteur de log natif du core**, déjà rendu sur cette page (clé cœur `log::level::jeeroborock`), signalé par une ligne d'information. Aucun champ ni clé `password` nulle part. | **couvert, sous réserve de R8b** (le pré-remplissage effectif par le `.ini` se constate en recette sur une Jeedom réelle) |
| **AC2** — persistance après rafraîchissement | Mécanisme standard du core, aucun code plugin. Cf. § Chargement et sauvegarde. | couvert |
| **AC3** — clé `userData` déclarée chiffrée, jamais en clair | `$_encryptConfigKey = array('userData')` + absence totale de la clé dans le DOM, les logs et les réponses AJAX. | couvert |
| **AC4** — message lisible si port occupé | UC01 livre la **brique** `estPortLocalOccupe()` + le message FR + un avertissement **non bloquant** à la sauvegarde. Le déclencheur décrit par la spec fonctionnelle (« démarrage du démon ou test de connexion ») n'existe pas encore : il sera branché sur cette même méthode par **UC02** (`deamon_start`/`deamon_info`) et **UC05**. | **partiellement couvert** — ne pas cocher AC4 dans la spec fonctionnelle à l'issue d'UC01 |
| **AC5** — chaînes FR enveloppées | Double accolade dans `configuration.txt`, `__('…', __FILE__)` dans `jeeroborock.class.php`. | couvert |

## Architecture

### Fichiers

Tous les fichiers du dépôt sont en **CRLF** dans la copie de travail (`core.autocrlf=true`) — ne pas
convertir. L'indentation se décide fichier par fichier.

| Fichier | État | Indentation |
|---|---|---|
| `plugin_info/configuration.txt` | **modifié** — réécriture du formulaire | 2 espaces (existant) |
| `plugin_info/configuration.php` | **régénéré par `cp`, jamais édité** | idem (copie) |
| `core/class/jeeroborock.class.php` | **modifié** — constante, `$_encryptConfigKey`, 5 méthodes statiques | 2 espaces (existant) |
| `core/config/jeeroborock.config.ini` | **créé** (répertoire `core/config/` à créer) | n/a |
| `core/config/.htaccess` | **créé** — copie conforme de `core/class/.htaccess` | n/a |

**Explicitement non modifiés** : `core/php/jeeroborock.inc.php` (aucune classe annexe créée, donc aucun
`require_once` à ajouter), `plugin_info/packages.json` (aucune dépendance en UC01 ; la remise en forme du
fichier squelette relève de l'UC02), `plugin_info/info.json` (`pluginVersion` incrémenté par le hook
`pre-commit`), `desktop/php/jeeroborock.php`, `desktop/js/jeeroborock.js`, `core/ajax/jeeroborock.ajax.php`,
`desktop/modal/*`, `resources/demond/*`.

⚠️ **`desktop/js/jeeroborock.js` n'est pas chargé dans le contexte de la page de configuration plugin**
(il est inclus par `desktop/php/jeeroborock.php` uniquement) — n'y placer aucun JS de validation.

### Briques volontairement non créées en UC01

`jeeroborockDaemon` et `jeeroborockException` (D10) : aucun point d'entrée externe ne les référence dans
cette UC (aucun endpoint AJAX plugin, aucun cron, aucun hook `deamon_*` implémenté). La règle d'autoload
« 1 classe ↔ 1 fichier » n'est engagée que le jour où un point d'entrée externe les appelle — l'UC03 les
introduira avec leur vraie sémantique (codes `AUTH_EXPIRED`, `DEVICE_OFFLINE`…). Aucun accesseur
`getUserData()/setUserData()` non plus : UC04.

### Formulaire `plugin_info/configuration.txt`

> ⚠️ **Rappel de procédure** — le `.txt` est la **source de vérité éditable** ; le `.php` est régénéré par
> `cp plugin_info/configuration.txt plugin_info/configuration.php` **immédiatement après chaque édition**.
> Contrôle : `git status --short plugin_info/configuration.php` (la relecture du `.php` est refusée par
> les permissions de session). Oublier le `cp` produit un formulaire silencieusement inchangé, sans erreur.

- En-tête PHP existant conservé, avec **durcissement de la garde** : `isConnect()` → `isConnect('admin')`.
  Justification : `index.php` (l. 79-81) inclut ce fichier via `?v=d&plugin=<id>&configure=1` avec un
  simple `include_file('core','authentification','php')` — **le core n'applique aucun contrôle admin** sur
  cette inclusion, la garde du fichier est la seule protection. L'enregistrement, lui, est déjà admin-only.
- Deux `fieldset` :
  1. **« Compte Roborock »** → `<input type="email" class="configKey form-control" data-l1key="email"/>`
  2. **« Canal local avec le démon »** →
     `<input type="number" min="1024" max="65535" step="1" class="configKey form-control" data-l1key="portDemonHttp"/>`
- Une `div.alert.alert-info` renvoyant au bloc « Log » natif de la page.
- **Aucun champ mot de passe** (décision de cadrage), **aucun champ `userData`**.

### Valeur par défaut du port : `61350`

Elle vit dans `core/config/jeeroborock.config.ini`, section **`[jeeroborock]`** (le nom de section doit
être l'id du plugin : le lookup est `$defaultConfiguration[$_plugin][$_key]`) :

```ini
; Valeur par defaut du port du canal HTTP local avec le demon.
; A garder synchronisee avec jeeroborock::PORT_DEMON_HTTP_DEFAUT (core/class/jeeroborock.class.php).
[jeeroborock]
portDemonHttp = "61350"
```

Pas de défaut pour `email` : la chaîne vide est le bon état initial.

**Pourquoi le `.ini` et pas un `value=` HTML** : c'est le **seul** mécanisme qui satisfait AC1. Le core
charge le formulaire par `jeedom.config.load` **puis** applique `setJeeValues(data, '.configKey')`
(`desktop/js/plugin.js` l. 340-366) — un attribut `value="…"` codé en dur dans le HTML est donc **écrasé**.
Et il n'existe pas de champ `defaultConfiguration` dans `info.json` : `config::getDefaultConfiguration()`
ne lit que `plugins/<id>/core/config/<id>.config.ini`.

**Pourquoi 61350** :
- **Au-dessus de la plage éphémère Linux par défaut** (`net.ipv4.ip_local_port_range = 32768 60999`) : le
  noyau n'y attribue jamais un port source sortant, donc le `bind` du démon ne peut pas échouer
  aléatoirement à cause d'une connexion sortante — précisément le symptôme qu'AC4 cherche à rendre lisible.
- **IANA n'attribue rien au-dessus de 49151** (plage Dynamic/Private).
- **Hors du cluster des démons Jeedom** : le squelette `resources/demond/demond.py` fixe
  `_socket_port = 55009` ; la plage 55000-55100 est la zone de collision réelle de l'écosystème.
- **Hors des interfaces web domotiques usuelles** : 8080/8090, 8091, 8096, 8112, 8123, 1880, 3000, 9000/9090.
- Le démon écoutant **exclusivement sur `127.0.0.1`** (D3), seuls les conflits locaux comptent.

**Duplication assumée** : la valeur existe dans le `.ini` **et** dans `PORT_DEMON_HTTP_DEFAUT`
(`parse_ini_file` ne peut pas lire une constante PHP). Commentaire de synchronisation obligatoire des
deux côtés.

**Nommage `portDemonHttp`** (et non `portDemon`) : la clé désigne le canal **HTTP synchrone** de D3. Si
l'UC02 devait conserver en plus le `jeedom_socket` du squelette (port 55009), `portDemon` deviendrait
ambigu — et renommer une clé de configuration après livraison impose une migration. Le coût est nul
aujourd'hui, aucune donnée utilisateur n'existe.

## Server vs Client

**Tout est serveur.** Aucun JavaScript custom n'est ajouté :
- le JS du plugin n'est pas chargé dans cette page (cf. ci-dessus) ;
- accrocher le bouton d'enregistrement du core créerait une dépendance à un contrat front non garanti
  entre versions de Jeedom ;
- le chemin d'erreur existe déjà : `savePluginConfig` affiche `error.message` via `jeedomUtils.showAlert`
  (`desktop/js/plugin.js` l. 428-440).

Les attributs HTML5 (`type="email"`, `type="number" min/max/step`) sont une **aide à la saisie**, pas une
validation : l'enregistrement se fait en AJAX, pas par soumission de formulaire, donc la validation native
du navigateur ne bloque rien. **Le serveur est autoritaire.**

## Chargement et sauvegarde (contrat du core)

Contrats vérifiés dans la source du core (branche `alpha`, consultée le 2026-09-17), pas dans le wiki.

1. **`config::save($_key, $_value, $_plugin = 'core')`** (`core/class/config.class.php` l. 59-110) :
   - l. 67-75 : **si la valeur enregistrée est égale au défaut du `.ini`, la ligne est SUPPRIMÉE de la base
     et `preConfig_<clé>` n'est PAS appelé** (seul `postConfig_` l'est).
   - l. 83-86 : appel de `preConfig_<clé>` après `str_replace(array('::', ':', '-'), '_', $_key)` ; nos clés
     sont alphanumériques → noms de méthode directs.
   - l. 89-90 : chiffrement **après** `preConfig_`, si la clé figure dans `$_encryptConfigKey`.
2. **`config::byKey($_key, $_plugin, $_default = '', $_forceFresh = false)`** (l. 149-181) : base → sinon
   `.ini` → sinon `$_default`. Déchiffre si la clé est listée. Applique `is_json($v, $v)` (l. 178) qui ne
   convertit **que** si le décodage donne un **tableau** : `'61350'` reste la chaîne `'61350'`, mais
   `userData` (objet JSON) sera relu **en tableau PHP** — à retenir pour l'UC04.
3. **`config::byKeys($_keys, $_plugin, $_default = '')`** (l. 183-228) : alimente le chargement du
   formulaire. **Divergence avec `byKey`** : une ligne existante contenant la **chaîne vide** est renvoyée
   telle quelle, le défaut `.ini` n'étant pas appliqué. D'où la normalisation « vide → défaut » **à
   l'écriture** dans `preConfig_portDemonHttp`.
4. **`core/ajax/config.ajax.php`** : `addKey` exige `isConnect('admin')` et boucle `config::save()` clé par
   clé dans l'ordre du DOM → **une exception levée dans un `preConfig_` interrompt la boucle** et les clés
   déjà traitées restent enregistrées. Conséquence pratique : placer le champ e-mail **avant** le champ port
   dans le DOM n'a pas d'incidence fonctionnelle, mais une sauvegarde partielle reste possible et c'est
   acceptable (les deux champs sont indépendants).
5. **Sélecteur de niveau de log** : le core rend **systématiquement**, sur la page de configuration plugin,
   le groupe radio « Niveau log » lié à la clé **cœur** `log::level::jeeroborock` (`desktop/js/plugin.js`
   l. 299-311, alimenté par `core/ajax/plugin.ajax.php` l. 40-41 qui pose toujours `logs[-1]`). Et
   `log::add()` (`core/class/log.class.php` l. 111-118) filtre **uniquement** sur
   `log::getLogLevel('jeeroborock')`. **Aucune clé `logLevel` propre au plugin n'est créée** : elle serait
   décorative et créerait deux sources de vérité contradictoires. L'UC02 lira
   `log::convertLogLevel(log::getLogLevel('jeeroborock'))` pour l'argument de niveau de log du démon.

## Signatures

Toutes dans `class jeeroborock` (`core/class/jeeroborock.class.php`). **Pas de type de retour déclaré**,
pour rester aligné sur le style du core et éviter un `TypeError` sur entrée inattendue.

```
const PORT_DEMON_HTTP_DEFAUT = 61350;   // synchrone avec core/config/jeeroborock.config.ini

public  static $_encryptConfigKey = array('userData');

public  static function preConfig_email($_value)         // -> string ; throws Exception
public  static function preConfig_portDemonHttp($_value) // -> string ; throws Exception
public  static function getPortDemonHttp()               // -> int    ; jamais d'exception
public  static function estPortLocalOccupe($_port)       // -> bool   ; jamais d'exception
private static function signalerPortOccupe($_port)       // -> void   ; jamais d'exception
```

### `preConfig_email($_value)`

`trim`, puis : chaîne vide acceptée (état initial légitime), sinon `filter_var(…, FILTER_VALIDATE_EMAIL)`
et **`Exception`** si invalide. Retourne la valeur normalisée.

⚠️ **Pas de mise en minuscules** : l'e-mail entre dans le `header_clientid` côté démon, on ne touche pas à
sa casse.

### `preConfig_portDemonHttp($_value)`

Trois étapes, dans cet ordre :
1. `trim` ; chaîne vide → retourne `(string) self::PORT_DEMON_HTTP_DEFAUT` (normalisation à l'écriture,
   cf. divergence `byKey`/`byKeys` au contrat n° 3).
2. `ctype_digit` **et** plage **1024-65535** → sinon **`Exception`** (sous 1024 = ports privilégiés, le
   démon lancé par Jeedom ne peut pas s'y lier).
3. Si la nouvelle valeur **diffère** de `self::getPortDemonHttp()` (l'ancienne), déléguer à
   `self::signalerPortOccupe($port)`.

Retourne `(string) intval($port)`.

**Pourquoi la sonde ici et pas dans `postConfig_`** : `preConfig_` est le seul hook qui puisse comparer
l'ancienne et la nouvelle valeur (`postConfig_` reçoit la valeur déjà enregistrée). Cette comparaison est
ce qui évite, dès l'UC02, un faux positif quand **notre propre démon** écoute déjà sur le port inchangé.

### `signalerPortOccupe($_port)` *(privée)*

Extraite de `preConfig_portDemonHttp` pour ne pas y cumuler normalisation, validation et notification.
- `message::removeAll('jeeroborock', 'port_occupe')` **systématiquement** d'abord, pour qu'un conflit résolu
  ne laisse pas de message fantôme ;
- puis, si `self::estPortLocalOccupe($_port)` : `log::add('jeeroborock', 'warning', …)` +
  `message::add('jeeroborock', <message FR>, '', 'port_occupe')`.

**Non bloquant** : la sonde est une heuristique (service transitoire, service que l'utilisateur va
justement arrêter). Bloquer l'enregistrement sur une heuristique est disproportionné, et la spec
fonctionnelle place elle-même le message bloquant au **démarrage du démon**.

### `getPortDemonHttp()`

**Unique** point de lecture du port dans tout le plugin (normalisation à la lecture) :
`config::byKey('portDemonHttp', 'jeeroborock', self::PORT_DEMON_HTTP_DEFAUT)`, `intval`, et retour à
`PORT_DEMON_HTTP_DEFAUT` si la valeur est non numérique ou hors 1024-65535 — protège d'un `.ini` absent ou
corrompu, ou d'une valeur écrite hors formulaire.

**UC02 et UC03 consommeront cette méthode, jamais `config::byKey` directement.**

### `estPortLocalOccupe($_port)`

Sonde **non intrusive** : `@fsockopen('127.0.0.1', intval($_port), $errno, $errstr, 0.3)`, fermeture
immédiate de la ressource si la connexion aboutit → `true`. Guard `function_exists('fsockopen')` : fonction
désactivée par `disable_functions` → `log::add(…, 'debug', …)` et retour `false` (on ne bloque jamais sur
une capacité PHP absente).

**Pourquoi un test de connexion et pas un test de bind** : un `bind` de test risque de retenir le port et
échoue pour des raisons de droits. Le `connect` sur `127.0.0.1` répond exactement à la question utile,
puisque le démon se liera **sur `127.0.0.1` uniquement** (D3) — un service lié à une autre interface n'entre
pas en conflit et, correctement, n'est pas détecté.

**Budget de temps** : une seule sonde, plafonnée à **0,3 s**, uniquement à la sauvegarde du formulaire —
jamais en cron, jamais en boucle. Sur la boucle locale, un port libre répond par un RST immédiat : le
plafond n'est en pratique jamais atteint.

## Validation & erreurs

| Champ | Aide à la saisie (client) | Validation serveur (autoritaire) | Message utilisateur |
|---|---|---|---|
| e-mail | `type="email"` | `preConfig_email` : `trim`, vide autorisé, sinon `FILTER_VALIDATE_EMAIL` → `Exception` | « L'adresse e-mail du compte Roborock est invalide. » |
| port | `type="number" min="1024" max="65535" step="1"` — effet de bord : une saisie invalide renvoie `''`, que le serveur normalise en **valeur par défaut** (pas de rejet visible) | `preConfig_portDemonHttp` : vide → défaut ; `ctype_digit` + plage → `Exception` ; puis sonde non bloquante | « Le port du canal local doit être un nombre entier compris entre 1024 et 65535. » / « Le port du canal local %s est déjà utilisé sur cette machine, veuillez en choisir un autre. » |

- **Typage des exceptions** : `Exception` nue, message français via `__(…, __FILE__)`, **sans code** (le code
  remonte tel quel dans `ajax::error`). Pas de `jeeroborockException` en UC01 : elle n'apporterait rien ici,
  `config.ajax.php` n'exploitant que le message.
- Le message paramétré utilise `sprintf()` **autour** d'une chaîne **littérale** passée à `__()` — jamais
  `__($variable)`, qui échapperait au scan statique d'extraction i18n.
- **Canal du message « port occupé »** : centre de messages Jeedom (`message::add`, `logicalId` =
  `'port_occupe'`) + `log::add('jeeroborock', 'warning', …)`.

## Server Actions / API

**Aucune.** Aucun endpoint AJAX plugin n'est créé (aucun bouton d'action sur la page). Par conséquent :
ni `session_write_close()` à arbitrer, ni `isConnect()` supplémentaire, ni `catch (Throwable)` final à poser.

## Dépendances

**Aucune.** UC01 n'introduit aucun paquet pip, npm ou composer. `plugin_info/packages.json` n'est pas touché.

## Impact i18n (français uniquement dans cette UC)

Aucun fichier `core/i18n/*.json` n'est modifié pendant l'implémentation : la traduction est déléguée au
sous-agent `translator` en fin de cycle, sur le code figé.

**`plugin_info/configuration.txt` → `.php`** (enveloppage par double accolade) :
- « Compte Roborock »
- « E-mail du compte Roborock »
- « Adresse e-mail du compte Roborock. Le mot de passe n'est jamais demandé : l'authentification se fait par un code reçu par e-mail. »
- « Canal local avec le démon »
- « Port du canal local »
- « Port TCP utilisé sur 127.0.0.1 pour dialoguer avec le démon. À ne changer qu'en cas de conflit avec un autre service. »
- « Le niveau de journalisation se règle dans le bloc Log de cette page. »

**`core/class/jeeroborock.class.php`** (`__('…', __FILE__)`) :
- « L'adresse e-mail du compte Roborock est invalide. »
- « Le port du canal local doit être un nombre entier compris entre 1024 et 65535. »
- « Le port du canal local %s est déjà utilisé sur cette machine, veuillez en choisir un autre. »

Les messages `log::add` restent en français **non enveloppés** (convention Jeedom : les logs ne se
traduisent pas).

⚠️ `configuration.txt`/`.php` est un fichier **rendu** : aucune double accolade ouvrante littérale dans un
commentaire, et aucun délimiteur de fin de commentaire bloc collé à du texte dans les commentaires PHP de
la classe. Lancer `python .claude/scripts/verif-plugin.py` (colonne `meta=`) avant commit.

## Risques & pièges

- **R1 — `getKey` n'est pas admin-only côté core.** `core/ajax/config.ajax.php` action `getKey` n'exige que
  `isConnect()` et renvoie les valeurs **déchiffrées**. Un utilisateur Jeedom non-admin connecté peut donc
  lire `userData` en devinant le nom de la clé. C'est un comportement **du core**, commun à tous les plugins,
  hors périmètre UC01 et non contournable sans violer D4. Mitigations appliquées : chiffré au repos, jamais
  dans le DOM, jamais dans un log. **À ne pas « corriger » par un contournement maison.**
- **R2 — `displayException` expose la trace.** `displayException()` (`core/php/utils.inc.php` l. 252-259)
  ajoute `getTraceAsString()` quand `DEBUG !== 0`, et une trace PHP contient les **arguments** de chaque
  frame. Conséquence dure : **ne jamais définir `preConfig_userData` / `postConfig_userData`**, et ne jamais
  lever d'exception depuis une frame qui reçoit le `userData` en paramètre — sinon le secret atteint le DOM.
  À inscrire en commentaire au-dessus de `$_encryptConfigKey`.
- **R3 — court-circuit de `preConfig_` sur la valeur par défaut.** Si l'utilisateur saisit exactement 61350,
  `config::save` supprime la ligne et n'appelle pas `preConfig_` → **pas de sonde de port**. Rattrapé par
  l'UC02 au démarrage du démon. Corollaire de recette : la ligne `portDemonHttp` **disparaît de la table
  `config`** dans ce cas — ce n'est pas une régression d'AC2, et AC2 doit être testé avec une valeur ≠ défaut.
- **R4 — fiabilité de la sonde.** `fsockopen` détecte un socket **en écoute** sur `127.0.0.1` : une
  réservation UDP, un port lié sans `listen()` ou une pile réseau isolée (conteneur) passent au travers ;
  `fsockopen` désactivé désactive silencieusement le contrôle (log `debug`). **Confort, pas garantie.**
- **R5 — écart à corriger dans l'analyse interne.** `.memory/analyse/jeeroborock-architecture.md` § D4
  (l. 111-112) dit encore « proposer le mot de passe en option […] Le champ mot de passe reste dans
  `$_encryptConfigKey` », ce que `CLAUDE.md` et l'AC1 de la spec fonctionnelle contredisent frontalement.
  **`CLAUDE.md` fait foi** → aucun champ ni aucune clé `password`. **D4 doit être corrigé** à l'étape de
  capitalisation mémoire, sinon une UC ultérieure réintroduira le champ.
- **R6 — désynchronisation `configuration.txt` / `configuration.php`.** Oublier le `cp` produit un formulaire
  silencieusement inchangé, sans erreur : c'est le `.php` qu'`index.php` inclut. Contrôle obligatoire :
  `git status --short plugin_info/configuration.php` doit montrer le fichier modifié.

## Recette (à confirmer sur une Jeedom réelle)

- **R8a** — le bloc « Log » natif est bien visible sur la page de configuration du plugin et pilote
  effectivement `log::add('jeeroborock', …)`.
- **R8b** — le champ port s'affiche pré-rempli à **61350 avant toute sauvegarde** (preuve que le `.ini` est
  pris en compte) — c'est la réserve qui pèse sur AC1.
- **R8c** — un message d'erreur levé par `preConfig_` s'affiche bien en alerte dans la page de configuration.
- **R8d** — 61350 est libre sur la machine cible.
- **AC2** — tester avec une valeur **différente** du défaut (cf. R3).

## Dette

Findings et constats n'atteignant pas la gate de review (`blocker`/`major`, `critical`/`high`), non
corrigés dans ce cycle :

- **Chaînes UI du squelette non traduites** — `verif-plugin.py` signale 54 clés françaises héritées du
  template Jeedom, réparties sur 4 fichiers **hors périmètre UC01** : `desktop/php/jeeroborock.php`
  (39 clés), `desktop/js/jeeroborock.js` (12), `core/ajax/jeeroborock.ajax.php` (2),
  `desktop/modal/modal.jeeroborock.php` (1). Elles ne sont pas traduites en `en_US`/`de_DE`/`es_ES`.
  → **À traiter quand ces fichiers seront réellement travaillés** (UC02 et suivantes), pas avant : les
  traduire aujourd'hui produirait des clés orphelines dès que le squelette sera réécrit.
- **Espace en fin de ligne** dans `core/class/jeeroborock.class.php` (l. 143) — **préexistant**, dans un
  bloc commenté du squelette, hors des lignes touchées par cette UC. Avis non bloquant du script.
- **`plugin_info/packages.json` encore au format squelette** (`pyserial`, `requests`, entrées npm/composer
  /yarn parasites) — remise en forme explicitement renvoyée à l'UC02, qui introduit `python-roborock`.
