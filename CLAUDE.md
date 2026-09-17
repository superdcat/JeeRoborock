# CLAUDE.md

Ce fichier guide Claude Code (claude.ai/code) lorsqu'il travaille sur ce dépôt.

> **Langue des réponses** : toujours s'adresser à l'utilisateur **en français** (explications, résumés,
> questions, messages de commit et de PR). Le français est la langue de travail du projet — ne jamais
> répondre en anglais.

## Présentation

Ce dépôt est un **template de plugin Jeedom** : une base réutilisable pour créer n'importe quel plugin
Jeedom. L'id du plugin est **`template`** (classes `template` / `templateCmd`) ; c'est un **placeholder à
renommer** au début d'un vrai projet.

- **Renommage du squelette** : l'assistant remplace l'id `template` par l'id réel (contenu + noms de
  fichiers + `info.json`). Deux implémentations équivalentes :
  - `plugin_info/helperConfiguration.php` — assistant CLI officiel Jeedom (`php helperConfiguration.php`),
    **interactif**, nécessite `php`.
  - `plugin_info/helperConfiguration.py` — **port Python** non interactif (utile **sans `php` en local**) :
    `python plugin_info/helperConfiguration.py --id <id> --name "<Nom>" --category <cat> --daemon <yes|no>
    --dependency <yes|no>` (avec `--dry-run` pour prévisualiser). Il préserve la ligne `plugin.template`
    (asset core), ne touche pas `configuration.php` ni `core/template/`, et supprime `resources/` si
    `--daemon no`. **C'est ce port qu'utilise `/init-plugin`** pour renommer automatiquement.
  Tant que le renommage n'a pas eu lieu, l'id reste `template` ; les références de cette doc utilisent
  `template`/`templateCmd`.
- Un plugin Jeedom **n'est pas autonome** : il s'installe sous `<jeedom>/plugins/<id>/`, et tout le PHP
  dépend du core Jeedom, atteint via `require_once __DIR__ . '/../../../../core/php/core.inc.php';`.
- **Pas de build local** ; la validation se fait en CI (voir « Workflows / CI »).

Ce dépôt embarque, en plus du code du template, un **outillage Claude Code** pour développer des features
de manière structurée :
- **`.claude/`** — commandes `/init-plugin` (cadrage), `/feature` (implémentation d'une UC),
  `/auto-dev` (enchaînement autonome de plusieurs UC) et `/change` (revenir sur une décision de
  `/auto-dev`) ; sous-agents (`jeedom-plugin-architect`, `jeedom-tech-planner`, `spec-writer`,
  `php-jeedom-dev`, `auto-dev-runner`, `code-reviewer`, `security-reviewer`, `translator`) ; skills
  `spec` et `dev` ; templates partagés (`.claude/templates/`) ; scripts (`.claude/scripts/`) ;
  mémoire d'agent.
- **`.memory/`** — connaissance interne **versionnée** : `specs/` (specs fonctionnelles/techniques des
  features), `analyse/` (décisions/pièges Jeedom réutilisables), `external/doc/` (index de la doc externe).

## Architecture

Disposition Jeedom fixe (type MVC). Pièces principales, toutes nommées d'après l'id `template` :

- **`core/class/template.class.php`** — le cœur du plugin. Deux classes (**1 classe ↔ 1 fichier**, cf.
  Conventions/Autoload) :
  - `template extends eqLogic` — **une instance par équipement**. Hooks de cycle de vie
    (`preSave`/`postSave`, `preInsert`/`postInsert`, `preRemove`/`postRemove`…), hooks cron statiques
    (`cron()` chaque minute, `cron5`, `cron10`, `cron15`, `cron30`, `cronHourly`, `cronDaily`), hooks
    `preConfig_<clé>`/`postConfig_<clé>` pour valider/réagir à la config plugin. `$_encryptConfigKey`
    chiffre automatiquement les champs de config **plugin** sensibles.
  - `templateCmd extends cmd` — commande (info ou action). `execute($_options)` exécute une action
    (typiquement un `switch` sur `logicalId`).
- **`core/ajax/template.ajax.php`** — endpoint AJAX **admin** de la page de configuration : inclut le core,
  `isConnect('admin')`, `ajax::init()`, puis aiguille sur `init('action')` en branches
  `if (init('action') == '...')`. Pour un endpoint **non-admin** (widget de dashboard, page-panneau), créer
  un fichier AJAX **distinct** avec `isConnect()` + contrôle fin `hasRight('r')` par équipement.
- **`core/php/template.inc.php`** — includes/constantes internes du plugin.
- **`core/template/{dashboard,mobile}/cmd.<type>.<subType>.<nom>.html`** — widgets de commande
  personnalisés (dashboard + mobile = **deux fichiers synchronisés**). Posés sur une commande via
  `setTemplate('dashboard'|'mobile', 'template::<nom>')`. Détail : `.memory/analyse/jeedom-widgets-commandes.md`.
- **`desktop/php/template.php`** — page de configuration admin (HTML), protégée par `isConnect('admin')`.
  Liaison au modèle via `data-l1key`/`data-l2key`. i18n via `{{...}}`. Se termine en incluant le JS du
  plugin puis le JS générique de page plugin **fourni par le core**
  (`include_file('core', 'plugin.template', 'js')` → asset du core, **à ne pas renommer/modifier**).
  ⚠️ **Ces fichiers `desktop/php/*.php` sont indentés en tabulations + fins de ligne CRLF** (contrairement
  à `core/class/*.php` en 2 espaces) — cf. mémoire d'agent `feedback-edit-tool-tab-indented-files`.
- **`desktop/js/template.js`** — front-end (lignes de commandes, tri, helpers `jeedom.*`).
- **`desktop/modal/modal.template.php`** — modale(s) de la page de config.
- **`desktop/php/<fichier>.php` déclaré par `info.json "display"`** — page-panneau optionnelle au **menu
  d'accueil** Jeedom (usage utilisateur, `isConnect()` non-admin). Détail :
  `.memory/analyse/jeedom-panel-page-menu.md`.
- **`plugin_info/configuration.php`** — formulaire de la page de config **plugin** (`gotoPluginConf`).
  Champs liés en `class="configKey" data-l1key="<clé>"` (auto-load/save core via
  `config::byKey/save(..., 'template')`).

> ⚠️ **Accès restreint à `plugin_info/configuration.php`** — Claude Code **ne peut ni lire ni éditer**
> ce fichier via les outils Read/Edit/Write (refusé par les permissions de session), et même un
> `diff`/`md5sum`/`cat` Bash dessus est refusé. Une copie synchronisée **`plugin_info/configuration.txt`**
> sert de miroir éditable, et le `.php` est régénéré depuis le `.txt` par une simple copie :
> - **Lecture** : toujours lire `configuration.txt` (jamais `configuration.php`).
> - **Écriture** : modifier **uniquement** `configuration.txt` (outils Edit/Write). Le `.txt` est la
>   **source de vérité éditable** du formulaire de config.
> - **Synchronisation** : le `.php` étant la version réellement exécutée par Jeedom, **les deux fichiers
>   doivent rester identiques**. Après **chaque** modification du `.txt`, écraser le `.php` via bash :
>   ```bash
>   cp plugin_info/configuration.txt plugin_info/configuration.php
>   ```
>   La copie remplace intégralement le fichier (pas de fusion). Le `cp` **write** passe sans erreur ; ne
>   pas tenter de vérifier le résultat en **relisant** `configuration.php` (refusé) — utiliser
>   `git status --short plugin_info/configuration.php`. Cf. mémoire
>   `feedback-configuration-php-permission-scope`.

- **`plugin_info/info.json`** — manifeste (id, `name`, version, `require`, OS min/max, `category`,
  `hasDependency`, `hasOwnDeamon`, langues, `compatibility`, liens doc/forum). La `description` multilingue
  se met **dans `info.json`** (objet à clés de langue), pas dans les fichiers i18n (cf. i18n).
- **`plugin_info/install.php`** — `template_install/update/remove()` ; `pre_install.php` →
  `template_pre_update()`.
- **`plugin_info/packages.json`** — dépendances système/pip du démon (voir Démon & dépendances).
- **`plugin_info/helperConfiguration.php`** — assistant CLI de renommage (cf. Présentation).
- **`resources/demond/`** — **squelette de démon Python** (réutilisable si le plugin a besoin d'un
  processus persistant : MQTT, WebSocket, port série, polling temps réel…). Contient `demond.py` +
  la lib `jeedom/` (`jeedom_socket` PHP→démon, `jeedom_com` démon→Jeedom). **Désactivé par défaut**
  (`info.json "hasOwnDeamon": false`) ; l'activer implique `hasOwnDeamon: true`, les hooks
  `deamon_info/deamon_start/deamon_stop` dans la classe principale, et un pont PHP↔démon
  (`sendToDaemon()` côté PHP, callback `core/php/jee<Id>.php` côté démon→Jeedom).

## Configuration & secrets

- **Config plugin** (`config::save/byKey(..., 'template')`) : clés globales du plugin (identifiants d'API,
  URL de broker, options). Les clés **sensibles** (secrets, mots de passe) se déclarent dans
  `public static $_encryptConfigKey = array('cle1', 'cle2');` sur la classe principale → le core les
  **chiffre/déchiffre automatiquement**. Les hooks `preConfig_<clé>($value)` permettent de valider/purger
  avant enregistrement (⚠️ `preConfig_<clé>` est un **nom de méthode fixe** — pas d'itération dynamique sur
  des clés inconnues).
- **Config par équipement** (`$eqLogic->getConfiguration('<clé>')`) : réglages propres à chaque instance.
  Les champs sensibles d'un **équipement** se chiffrent via les méthodes d'instance `encrypt()`/`decrypt()`.
- **Tokens / états volatils** : cache **chiffré** via la classe `cache` (`cache::set/byKey/delete`).
  Adapté à des jetons OAuth à durée courte (refresh proactif/réactif).
- ⚠️ **Jamais** de secret/token/mot de passe en clair dans les logs, le DOM, les réponses AJAX ou les
  commentaires.

## Démon & dépendances

- Un démon n'est justifié que si le plugin a besoin d'un **canal persistant / temps réel** (MQTT,
  WebSocket, série, push). Pour un plugin **REST + polling cron**, préférer **sans démon** (100 % PHP,
  `hasOwnDeamon: false`) : c'est plus simple et suffisant.
- Les dépendances (Python/pip) se déclarent dans **`plugin_info/packages.json`** (uniquement `pip3`).
  ⚠️⚠️ **Pièges connus du format `packages.json`** (règles génériques Jeedom, coûteuses à redécouvrir) :
  - La **version se met dans la VALEUR, pas dans la clé** : `"paho-mqtt": {"version": "1.6.1"}`, **jamais**
    `"paho-mqtt==1.6.1": {}`. Le core compare la **clé** (nom nu) à `pip list` via
    `isset($installPackage[strtolower($clé)])` (`system::checkAndInstall`). Une clé contenant `==x.y.z` ne
    matche jamais le nom installé → paquet vu « à installer » en permanence → indicateur bloqué NOK +
    réinstallation forcée à chaque passe.
  - **Jamais de `<`/`>` dans le champ `version`** (ex. `"<2.0.0"`) : `installPackage` colle `$package .=
    $version` non quoté → redirection shell (`2.0.0: No such file or directory`, paquet jamais installé).
    Toujours une **version exacte** sans opérateur.
  - **Ne PAS définir `<id>::dependancy_info()`** : dès que `packages.json` existe, le core calcule l'état
    **uniquement** depuis `checkAndInstall(packages.json)` et n'appelle jamais cette méthode statique (code
    mort). Pour un contrôle *supplémentaire* (post-`packages.json`), le hook officiel est
    `additionnalDependancyCheck()` (appelé seulement si l'état `packages.json` est déjà `ok`).

## Workflows / CI

CI déléguée aux workflows réutilisables de Jeedom (`jeedom/workflows`) :
- **`.github/workflows/work.yml`** — check complet du plugin sur push/PR vers `beta`, PR vers `master`.
- **`.github/workflows/prettier.yml`** — pousser sur la branche **`prettier`** déclenche un bot qui
  reformate le code et commite (formatage automatique ; pas de config prettier locale).

Pas de commande de lint/test locale ; la validation tourne dans ces workflows contre un Jeedom réel. Une
recette fonctionnelle manuelle peut être maintenue sous `.memory/specs/` (cf. workflow `/feature`).

## Versioning automatique

Le plugin utilise un système **automatisé de versioning** via git hooks. À chaque commit qui touche du **code livré** (répertoires `core/`, `desktop/`, `plugin_info/`, `resources/`), le hook `pre-commit` incrémente automatiquement la **dernière composante numérique** de `pluginVersion` dans `plugin_info/info.json`.

**Pourquoi** : Jeedom Market (GitHub) compare les versions pour proposer des mises à jour. Sans bump de version, l'installation garde le code ancien.

**Règles** :
- Incrémente **uniquement** si le commit touche du code livrés au plugin (pas `.memory/`, `.claude/`, `.github/`, `docs/`)
- Augmente juste la **dernière composante** : "0.1" → "0.2", "1.4.2" → "1.4.3"
- Monter major/minor reste manuel : écrivez la nouvelle version à la main dans `info.json`, le hook repart de là
- N'enregistre **jamais** les modifications de `info.json` non préparées (évite de commiter du code non revu)

**Activation** (dans chaque clone) : 
```bash
git config core.hooksPath .githooks
```

Le hook est **non-bloquant** : il avertit si la version n'a pas pu être incrémentée, mais le commit passe quand même.

## Conventions

- **Français = langue source** : code, commentaires, noms de variables, messages de `log::add` et chaînes
  UI sont écrits en français (langue **par défaut** de Jeedom — pas de `fr_FR.json`).
- **Autoload Jeedom (règle critique, fatale au runtime, invisible à `php -l`)** : l'autoloader mappe
  **1 classe ↔ 1 fichier** `<NomClasse>.class.php` (`glob('plugins/*/core/class/<NomClasse>.class.php')`).
  Toute classe référencée depuis un **point d'entrée externe** (`core/ajax/*.ajax.php`, hooks cron,
  `desktop/php/*.php`, `install.php`) — via `Classe::`, `new Classe`, `catch (Classe …)` — doit soit avoir
  son **propre** fichier `<Classe>.class.php`, soit voir son chargement assuré en transitant par la classe
  principale `template`/`templateCmd` (dont le fichier `template.class.php` charge du même coup les classes
  annexes qu'il contient). Un appel **direct** à une classe annexe (ex. un client API `templateApi`) depuis
  un point d'entrée externe = `Fatal error: Class not found` au runtime.
- **Aucune méta-séquence littérale dans un commentaire ou une chaîne (fatale, et invisible à la
  relecture)** — un délimiteur écrit au milieu d'une phrase n'est pas du texte : le parseur le prend pour
  lui. **Cas vécu** sur un plugin issu de ce squelette : `mb_*/intl` écrit dans un docblock. Le `*/` du
  milieu a **fermé le commentaire**, `intl, …` a été relu comme du code (`syntax error, unexpected
  'intl'`), le fichier de classe n'a plus été chargé en entier et **toute la classe principale est
  devenue introuvable** — page de configuration mutilée, sans rapport apparent avec la cause. Trois
  séquences, un seul mécanisme :

  | Séquence | Où elle est fatale | Ce qui se passe |
  |---|---|---|
  | `*/` **collé à du texte** | commentaire `/* … */` | ferme le commentaire ici ; la suite est relue comme du code |
  | `?>` | commentaire `//` ou `#` | PHP **quitte le mode PHP** ; la fin de la ligne part telle quelle au navigateur |
  | `{{` | fichier **rendu** (`desktop/`, `plugin_info/configuration.*`, `core/template/`), **commentaire compris** | le moteur i18n du core y voit un début de clé et avale tout jusqu'à la fermeture suivante |

  Écrire `mb_* ou intl`, « balise fermante PHP », « double accolade ouvrante ». ⚠️ Ne comptez pas sur un
  filet en amont : `php` n'est pas forcément installé sur la machine de dev, et la CI Jeedom ne se
  déclenche que sur push `beta` ou PR. Le contrôle en place est
  `python .claude/scripts/verif-plugin.py` (colonne **`meta=`**) — **à lancer avant chaque commit**.
- **Centraliser les accès externes** : si le plugin appelle une API HTTP, faire transiter **tous** les
  appels par une **brique unique** (ex. une classe `templateApi`) plutôt que du cURL épars. Si le plugin a
  un démon, **toute** commande sortante passe par le pont démon (jamais de socket/MQTT épars).
- Indentation **2 espaces** en PHP/JS pour `core/class`, `core/ajax`, `desktop/js`… ; ⚠️ **exception** :
  `desktop/php/*.php` (pages) sont en **tabulations + CRLF** — respecter l'existant fichier par fichier.
- Logs via `log::add('template', 'debug'|'info'|'warning'|'error', $msg)` ; **jamais** de secret exposé.
- **Robustesse cron** : un équipement en erreur ne doit **pas** interrompre la boucle → `try/catch` **par
  équipement**. Respecter tout **rate-limit / quota** d'une API tierce (backoff sur 429, cooldown).
- Les `.htaccess` de `core/php`, `core/class`, `core/ajax`, `resources/`… interdisent l'accès web direct —
  **les conserver**.
- `docs/<langue>/` = documentation **utilisateur** ; `.memory/` = analyse & specs **internes** (français).

## Internationalisation (i18n) — natif multilingue

Le plugin est **nativement multilingue**. Langue **source = français** (`fr_FR`, pas de fichier de
traduction : la clé EST le texte français). Langues cibles usuelles : **`en_US`**, **`de_DE`**, **`es_ES`**
(ajustables selon `info.json "language"`).

- Toute chaîne UI est **enveloppée** : `{{Texte français}}` en HTML/JS, `__('Texte français', __FILE__)`
  en PHP. La clé est **toujours** le texte source français.
  - ⚠️ **Toujours une chaîne LITTÉRALE** dans `__()` — jamais `__($variable)`. L'extraction i18n (dont le
    sous-agent `translator`) est un **scan statique** : un nom stocké dans une variable puis passé à `__()`
    échappe à la traduction. Mettre `__('Libellé', __FILE__)` **dans** la table de définitions, pas
    `__($nom)` au moment de l'usage.
- Les traductions vivent dans `core/i18n/<langue>.json`, **un fichier par langue cible** (pas de
  `fr_FR.json`). Format :
  ```json
  { "plugins/template/<chemin/relatif/fichier>": { "Texte français": "Traduction" } }
  ```
- ⚠️ **Exception `info.json` — mécanisme DISTINCT** : la `description` (et le `name`) du manifeste se
  traduit via un **objet à clés de langue INLINE dans `plugin_info/info.json`** —
  `"description": {"fr_FR": …, "en_US": …, …}` — **PAS** via une section `"info.json"` des fichiers
  `core/i18n/*.json`. La `description` doit faire **≥ 80 caractères** par langue (règle du market Jeedom).
- **Règle d'or** : toute clé UI **livrée** doit avoir ses traductions dans toutes les langues cibles.
  *Quand* les produire :
  - **Dans `/feature`** : traduction faite **en fin de cycle** par le sous-agent `translator` (code figé,
    contexte isolé). Pendant le dev on enveloppe en français mais on **ne touche pas** aux `*.json`.
  - **Hors workflow** : ajouter/mettre à jour la clé dans les fichiers cibles dès qu'on l'introduit.

## Feuille de route, specs & mémoire interne

- **`.memory/specs/`** — specs des features à développer. Convention : une feature = une spec
  **fonctionnelle** `NN-nom.md` (critères d'acceptation = *definition of done*) + une spec **technique**
  `NN-nom-tech.md` (plan d'implémentation). Voir `.memory/specs/README.md`. Le template est **livré sans
  specs** : elles se créent au fil des features (l'orchestrateur `/feature` écrit la spec technique).
- **`.memory/analyse/`** — connaissance Jeedom **transverse et réutilisable** (décisions, pièges vérifiés
  contre la source du core), **découvrable via `.memory/analyse/INDEX.md`**. Contenu générique fourni :
  `jeedom-widgets-commandes.md` (widgets de commande), `jeedom-panel-page-menu.md` (page au menu).
- **`.memory/external/doc/jeedom/INDEX.md`** — index de la doc développeur Jeedom (pour un `WebFetch`
  ciblé sans re-parcourir le sommaire).

L'outillage `/` fonctionne en **trois temps** :

- **Bootstrap — `/init-plugin`** (à lancer une fois sur le template vierge) : interroge l'utilisateur sur
  le but du plugin, fait analyser l'intégration (agent `jeedom-plugin-architect` → fichiers
  `.memory/analyse/`), met à jour `CLAUDE.md`/`README.md`, puis génère **toutes les specs fonctionnelles**
  (`.memory/specs/MVP/` + post-MVP par domaine, via les agents `spec-writer` + skill `spec`). Il **ne
  produit pas** de spec technique ni de code, et **ne renomme pas** le squelette (rôle de
  `helperConfiguration.php`).
- **Implémentation — `/feature <spec>`** (à lancer par UC) : à partir d'une spec fonctionnelle, **fait
  produire le plan technique par l'agent `jeedom-tech-planner`**, le fait valider par l'utilisateur, écrit
  la spec technique, délègue l'implémentation à l'agent `php-jeedom-dev` (skill `dev`), lance les reviews
  croisées (`code-reviewer`, `security-reviewer`), puis la traduction (`translator`) et la capitalisation
  mémoire.
  ⚠️ **Chaque sous-agent épingle son `effort` dans son frontmatter** : sans cette ligne il **hérite de
  l'effort de la session**, et la boucle d'édition mécanique tourne au niveau de réflexion de
  l'orchestrateur — c'est là que partent les tokens, pas dans le raisonnement de l'orchestrateur lui-même.
  La réflexion coûteuse est concentrée là où une erreur se paie cher : le **plan**
  (`jeedom-tech-planner`, Opus `xhigh`) et les **reviews** (`code-reviewer`/`security-reviewer`, `high`).
  Elle est volontairement basse là où le travail est mécanique et déjà cadré (`php-jeedom-dev` `medium`,
  `spec-writer` `medium`, `translator` `low`). Les commandes s'élèvent elles-mêmes : `/feature` en `high`
  (il ne fait plus que piloter), `/init-plugin` en `xhigh` (cadrage = arbitrage).
- **Enchaînement autonome — `/auto-dev "<liste d'UC>"`** puis **`/change <explication>`** : le mode
  « sans humain dans la boucle », détaillé ci-dessous.

### Mode autonome — `/auto-dev` et `/change`

`/auto-dev "MVP 04 .. MVP 08"` (intervalle) ou `/auto-dev "MVP 04, MVP 06, MVP 08"` (liste) enchaîne
des cycles `/feature` complets **sans poser de question** : à chaque gate humaine, l'agent **tranche**
selon la grille `.claude/templates/principes-arbitrage.md` puis **journalise** l'arbitrage. Une UC =
**un sous-agent `auto-dev-runner`** en contexte neuf, et **un commit sur `master`** — jamais de `push`.

⚠️ **Prérequis : des specs fonctionnelles.** Le template est livré **sans specs** : sur un dépôt
vierge, `/auto-dev` n'a rien à résoudre. L'ordre est donc `/init-plugin` (qui produit les specs
fonctionnelles) **puis** `/auto-dev`.

⚠️ **À faire après `/init-plugin` : relire `.claude/templates/principes-arbitrage.md`.** C'est cette
grille (P1→P8) qui **remplace les réponses de l'utilisateur** — deux de ses principes (P2 invariants
du projet, P5 dépendances/démon) citent des choix propres au plugin. Une grille laissée générique
produit des décisions génériques, donc fausses.

- **Le runner ne réécrit pas `/feature`** : il invoque la skill `feature` telle quelle et ne surcharge
  que les **gates humaines** (spec absente → `bloque` sans rien inventer ; questions du planner →
  tranchées ; validation du plan → automatique sous conditions objectives ; `fix`/`continue` → toujours
  `fix`, un lot consolidé par tour, puis **dette journalisée** après le tour 2). Tout le reste — ordre
  des étapes, plafond de deux tours de review, répartition d'effort — reste porté par `/feature`.
- **Contexte plat par construction** : l'orchestrateur n'ouvre **aucun** fichier de spec ni de code ;
  il ne voit que la sortie tabulaire du script et un rapport de 25 lignes par UC. C'est ce qui permet
  d'enchaîner 5 UC sans que la dernière coûte cinq fois la première.
- **Reprise après coupure** (crédit épuisé, réseau, session fermée) : relancer la **même** demande
  reprend le run et saute les UC terminées ; `/auto-dev` **sans argument** reprend le dernier run
  interrompu. L'état vit dans `.memory/auto-dev/<run>/etat.json` + `journal.jsonl`, écrits **au fil de
  l'eau**, et une reprise se fie d'abord au **constat sur le disque** (spec technique présente ? arbre
  sale ? commit existant ?), pas à la phase journalisée — un process tué ne journalise pas sa dernière
  seconde.
- **`recap.md` à la racine** — ⚠️ **fichier GÉNÉRÉ**, jamais édité à la main : réassemblé par
  `python .claude/scripts/auto-dev.py recap` depuis les `decisions.md` des runners et les révisions
  (son titre est repris d'`info.json`, il suit donc le renommage du squelette). Chaque entrée est
  autoportante — question, décision, alternatives écartées, portée dans le code, coût d'un revirement,
  migration de l'existant — parce que son lecteur cible démarre en **contexte vide**.
- **`/change <explication>`** (ou `/change D-MVP04-02 <explication>`, `/change --liste`) : retrouve la
  décision dans `recap.md`, charge **uniquement** ce qu'elle cite, applique l'autre option (code, spec
  technique, i18n, et la **migration de l'existant** : clé de config renommée, `logicalId` déjà posé
  sur des équipements, cache au format précédent), puis ajoute une **révision** — l'ancienne décision
  reste visible, marquée révisée.
- **`.claude/scripts/auto-dev.py`** porte tout le mécanique (résolution de la demande en specs,
  journal, assemblage du récap) : `resolve`, `init`, `status`, `event`, `recap`. Le format d'une entrée
  de décision est figé dans `.claude/templates/recap-section.md` — **ses marqueurs sont lus à la
  lettre** par le script (`### D-<UC><NN> — …`, `- **Statut** : …`, `- **Révise** : D-…`) : les changer
  sans toucher au script casse silencieusement l'index et le lien de révision.

> **Maintenance de ce fichier** : `CLAUDE.md` est lu par **toute** future session. Le tenir à jour quand
> l'architecture, les conventions ou l'outillage changent — mais ne pas y consigner l'avancement détaillé
> d'un plugin concret (ça, c'est le rôle des specs et de la doc du plugin réel une fois le template
> renommé).
