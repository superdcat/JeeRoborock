# CLAUDE.md

Ce fichier guide Claude Code (claude.ai/code) lorsqu'il travaille sur ce dépôt.

> **Langue des réponses** : toujours s'adresser à l'utilisateur **en français** (explications, résumés,
> questions, messages de commit et de PR). Le français est la langue de travail du projet — ne jamais
> répondre en anglais.

## Présentation

**JeeRoborock** (id **`jeeroborock`**, classes `jeeroborock` / `jeeroborockCmd`) pilote les **aspirateurs
robots Roborock** depuis Jeedom, en passant par le **cloud Roborock** : remontée des états, commandes de
nettoyage, et surtout exécution des **routines (« usages »)** — les scénarios de nettoyage paramétrés par
l'utilisateur dans l'application mobile Roborock.

- **Matériel de référence** : Roborock **Qrevo Curv** (`roborock.vacuum.a135`), protocole V1. C'est le
  **seul** robot testable ; tout comportement non vérifiable dessus est marqué « À confirmer » dans les
  specs et les analyses, et se lève en recette, pas en review.
- **Le plugin ne parle jamais au cloud Roborock en PHP.** Le pilotage d'un robot V1 n'est pas du REST :
  les commandes sont des RPC encapsulées dans un protocole binaire propriétaire, chiffré par le
  `local_key` du robot et transporté sur un **MQTT persistant** qui pousse aussi des mises à jour non
  sollicitées. Tout ce contrat vit dans un **démon Python** adossé à la librairie **`python-roborock`**
  (celle de l'intégration Home Assistant). Le PHP ne parle **qu'au démon**.
- **Authentification** : **code à usage unique reçu par e-mail**, uniquement. Le mot de passe de compte
  n'est ni proposé ni stocké. Conséquence assumée : **la ré-authentification n'est pas automatisable** —
  le plugin affiche « ré-authentification requise » et s'arrête là.
- Un plugin Jeedom **n'est pas autonome** : il s'installe sous `<jeedom>/plugins/jeeroborock/`, et tout le
  PHP dépend du core Jeedom, atteint via `require_once __DIR__ . '/../../../../core/php/core.inc.php';`.
- **Pas de build local** ; la validation se fait en CI (voir « Workflows / CI ») et en recette sur une
  Jeedom réelle.

> Le squelette a été renommé depuis le template Jeedom au bootstrap (`/init-plugin`, 2026-09-17).
> `plugin_info/helperConfiguration.php` et `.py` restent présents mais **n'ont plus d'usage** : ne les
> rejoue pas, ils reviendraient renommer un plugin déjà nommé.

Ce dépôt embarque, en plus du code du plugin, un **outillage Claude Code** pour développer des features de
manière structurée :
- **`.claude/`** — commandes `/feature` (implémentation d'une UC), `/auto-dev` (enchaînement autonome de
  plusieurs UC) et `/change` (revenir sur une décision de `/auto-dev`) ; sous-agents
  (`jeedom-tech-planner`, `php-jeedom-dev`, `auto-dev-runner`, `code-reviewer`, `security-reviewer`,
  `translator`, et `jeedom-plugin-architect` / `spec-writer` pour ajouter un domaine) ; skills `spec` et
  `dev` ; templates partagés (`.claude/templates/`) ; scripts (`.claude/scripts/`) ; mémoire d'agent.
- **`.memory/`** — connaissance interne **versionnée** : `specs/` (specs fonctionnelles/techniques des
  features), `analyse/` (décisions et pièges réutilisables, Jeedom **et** Roborock), `external/doc/`
  (index de la doc externe).

## Architecture

Disposition Jeedom fixe (type MVC), nommée d'après l'id `jeeroborock`.

### Côté PHP

- **`core/class/jeeroborock.class.php`** — le cœur du plugin. Deux classes (**1 classe ↔ 1 fichier**, cf.
  Conventions/Autoload) :
  - `jeeroborock extends eqLogic` — **une instance par robot**. `logicalId` = le **`duid`** Roborock,
    identifiant stable insensible au renommage dans l'application. Hooks de cycle de vie
    (`preSave`/`postSave`, `preInsert`/`postInsert`, `preRemove`/`postRemove`…), hooks cron statiques
    (`cron()`, `cron5`…) utilisés en **chien de garde** (démon vivant ? donnée périmée ?), hooks
    `preConfig_<clé>`/`postConfig_<clé>`, et les hooks de démon `deamon_info`/`deamon_start`/`deamon_stop`.
    `$_encryptConfigKey` chiffre les champs de config **plugin** sensibles.
  - `jeeroborockCmd extends cmd` — commande info ou action. `execute($_options)` route l'action vers le
    pont démon (`switch` sur `logicalId`, posé en UC08). Trois contrats du cœur à respecter, tous
    vérifiés sur la source et détaillés dans `.memory/analyse/jeedom-widgets-commandes.md` § 4 :
    `execute()` doit retourner un **scalaire** (`formatValue()` écrase un tableau en chaîne vide,
    silencieusement) ; il doit faire lui-même son **`session_write_close()`** sous garde
    (`cmd.ajax.php` ne le fait pas, et un appel démon de 35 s figerait toute l'interface Jeedom) ; et
    une commande **action** ne doit pas porter de `value` liée (sinon `isAlreadyInStateAllow()` peut
    faire **sauter** l'exécution en la présentant comme un succès).
- **`core/class/jeeroborockDaemon.class.php`** — ⚠️ **la brique unique d'accès au démon**, dans son
  **propre** fichier parce qu'elle est appelée depuis des points d'entrée externes (AJAX, cron, callback)
  et doit donc être trouvable par l'autoloader. **Tout** échange PHP → démon passe par là : aucun appel
  HTTP épars ailleurs dans le code. Posée en UC03 : `appeler('<operation>', array(...))` est le **seul**
  point d'extension des UC suivantes — jamais une nouvelle route HTTP. `tableMessages()` y traduit les
  codes d'erreur stables du démon en français.
- **`core/class/jeeroborockException.class.php`** — `jeeroborockException` porte les erreurs typées du
  plugin (code stable en propriété dédiée : `Exception::getCode()` est typé `int` et ne peut pas le
  porter). Elle a son **propre** fichier, même règle d'autoload que ci-dessus. ⚠️ `estErreurCanal()` y
  duplique la liste des codes « canal » de `tableMessages()` — un nouveau code de famille A ou B doit être
  ajouté **dans les deux fichiers**, et aucun contrôle automatique ne détecte l'oubli.
- **`core/ajax/jeeroborock.ajax.php`** — endpoint AJAX **admin** de la page de configuration : inclut le
  core, `isConnect('admin')`, `ajax::init()`, puis aiguille sur `init('action')`. Pour un endpoint
  **non-admin** (widget de dashboard, page-panneau — ex. la vue carte), créer un fichier AJAX **distinct**
  avec `isConnect()` + contrôle fin `hasRight('r')` par équipement.
- **`core/php/jeeroborock.inc.php`** — includes/constantes internes.
- **`core/template/{dashboard,mobile}/cmd.<type>.<subType>.<nom>.html`** — widgets de commande
  personnalisés (dashboard + mobile = **deux fichiers synchronisés**), posés via
  `setTemplate('dashboard'|'mobile', 'jeeroborock::<nom>')`. Détail :
  `.memory/analyse/jeedom-widgets-commandes.md`.
- **`desktop/php/jeeroborock.php`** — page de configuration admin, protégée par `isConnect('admin')`.
  Liaison au modèle via `data-l1key`/`data-l2key`, i18n via la double accolade. Se termine en incluant le
  JS du plugin puis le JS générique de page plugin **fourni par le core**
  (`include_file('core', 'plugin.template', 'js')` → asset du core, **à ne pas renommer/modifier**).
  ⚠️ **Ces fichiers `desktop/php/*.php` sont indentés en tabulations + fins de ligne CRLF** (contrairement
  à `core/class/*.php` en 2 espaces) — cf. mémoire d'agent `feedback-edit-tool-tab-indented-files`.
- **`desktop/js/jeeroborock.js`** — front-end (lignes de commandes, tri, helpers `jeedom.*`).
- **`desktop/modal/modal.jeeroborock.php`** — modale(s) de la page de config. ⚠️ **Encore le squelette du
  template, jamais personnalisé.** La saisie du code e-mail **n'y est pas** : UC04 l'a implémentée en
  **bloc inline** dans `plugin_info/configuration.*` (décision D-04-1 — AC3 exige que « Envoyer un code »
  et « Valider le code » coexistent sans rechargement, ce qu'une modale empêche en masquant le champ
  e-mail et l'état du compte). **Ne pas réintroduire de modale pour l'authentification.**
- **`plugin_info/configuration.php`** — formulaire de la page de config **plugin** (`gotoPluginConf`).
  Champs liés en `class="configKey" data-l1key="<clé>"`. Protégé par `isConnect('admin')` **dans le
  fichier lui-même** : le core n'applique aucun contrôle admin sur cette inclusion.
- **`core/config/jeeroborock.config.ini`** — **valeurs par défaut** des clés de config plugin (section
  `[jeeroborock]`). ⚠️ C'est le **seul** mécanisme qui pré-remplit réellement un champ : un `value=` en
  dur dans le HTML est écrasé au chargement par `setJeeValues`. Piège associé : enregistrer une valeur
  **égale au défaut** supprime la ligne en base **et court-circuite `preConfig_<clé>`**. Détail et autres
  pièges du cycle de vie d'une config plugin : `.memory/analyse/jeedom-config-plugin-defauts.md`.

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

- **`plugin_info/info.json`** — manifeste. Valeurs actées au cadrage : `category`
  **`devicecommunication`**, `hasDependency` **true**, `hasOwnDeamon` **true**,
  `maxDependancyInstallTime` **15**, `os.min` **12** (imposé par `python-roborock`, qui exige
  Python ≥ 3.11 — **Debian 11 est exclu**), langues `fr_FR`/`en_US`/`de_DE`/`es_ES`.
  ⚠️ **`os.min` n'est lu nulle part par le core** : c'est un champ *market*. Le verrou d'activation
  réel, posé en UC02, est **`requireOsVersion`** (`"12"`) — cf. `jeedom-dependances-et-demon.md` § 3.
  La `description`
  multilingue se met **dans `info.json`** (objet à clés de langue), pas dans les fichiers i18n (cf. i18n).
- **`plugin_info/install.php`** — `jeeroborock_install/update/remove()` ; `pre_install.php` →
  `jeeroborock_pre_update()`.
- **`plugin_info/packages.json`** — dépendances pip du démon (voir Démon & dépendances).

### Côté démon

- **`resources/demond/`** — le démon Python. Il porte **tout** le contrat Roborock via `python-roborock` :
  authentification cloud, canal MQTT chiffré, décodage des états, catalogue de commandes, et les appels
  HTTPS des routines. Point d'entrée : **`jeeroborockd.py`** (renommé depuis le `demond.py` du squelette
  en UC02 — `system::kill` procède par `ps | grep`, garder le nom du template tuerait les démons des
  autres plugins qui en sont issus). La lib `jeedom/` a été **forkée et allégée** en UC02 : elle ne
  fournit plus que `jeedom_com` (démon→Jeedom) et `jeedom_utils`, sur `urllib.request` — celle du
  squelette importe `serial`/`pyudev`/`requests`, absents du venv, et levait un `ImportError` au
  démarrage. Depuis UC03, le routage vit dans **`canal.py`** (registre d'opérations, middlewares,
  `POST /rpc` + `GET /sante`) et le mapping des exceptions dans **`erreurs.py`** ; `jeeroborockd.py` ne
  garde que le cycle de vie. Ajouter une opération = `canal.enregistrer('<nom>', <coroutine>)`.
  UC04 a posé **`authentification.py`** (opérations `demanderCode`, `validerCode`, `restaurerSession`,
  rejointes en UC05 par `etatCompte`), UC06 **`equipements.py`** (`decouvrirEquipements`) et UC07
  **`robots.py`** (`lireEtat`, rejointe en UC08 par `envoyerCommande` — liste blanche **fermée** de
  5 actions V1) + **`libelles.py`** (tables de libellés FR des états et erreurs, pures
  données, aucune opération enregistrée). UC09 a posé **`routines.py`** (`listerRoutines`,
  `executerRoutine` — chemin HTTPS pur, cf. ci-dessous) et **`textes.py`** (neutralisation et troncature
  des chaînes d'origine cloud, factorisée depuis `equipements.py`/`robots.py` à la 3ᵉ occurrence :
  **tout nouveau module qui journalise ou renvoie une chaîne venue du cloud l'importe de là**). UC10 a
  posé **`supervision.py`** — le **superviseur temps réel**, seul module du démon qui n'enregistre
  **aucune** opération RPC : il vit en tâches de fond (une `_sonde` asyncio **par robot**, plus un
  écouteur de push sur le trait `status`) et publie ses lots par le callback `jeedom_com`. Son état vit
  dans `contexte['superviseur']` ; `demarrer()` est **idempotente** (à empreinte de session identique,
  une sonde vivante n'est ni annulée ni recréée — c'est une exigence d'acceptation, pas une
  optimisation) : **un robot en défaut ne doit jamais interrompre le suivi d'un autre**. Bilan :
  **un module par domaine fonctionnel**, enregistré explicitement depuis
  `jeeroborockd.py` — pas par effet de bord d'import. L'instance `RoborockApiClient` vit dans
  `contexte['auth']` et **doit** survivre entre l'envoi du code et sa validation (`header_clientid` dérive
  d'un identifiant régénéré à chaque instanciation) ; la session restaurée vit dans `contexte['session']`.
  UC07 a posé le **canal vers le robot** : un **`DeviceManager` unique**, construit paresseusement à la
  première lecture et mémorisé dans `contexte['gestionnaire']` (avec une empreinte de session qui le fait
  reconstruire quand le `userData` change). ⚠️ Il porte le `HomeData` complet, **donc les `local_key`** :
  ne jamais le sérialiser ni le journaliser. ⚠️ Le construire coûte un appel **`homedata`** (quota dur) —
  **toute UC qui a besoin du canal V1 passe par `robots.obtenir_appareil()`** — ou, quand c'est le
  gestionnaire lui-même qu'il faut (énumérer les robots du compte, comme le superviseur d'UC10), par
  **`robots.obtenir_gestionnaire()`** — et ne rappelle
  **jamais** `create_device_manager()`, sous peine de doubler la consommation de quota et d'ouvrir une
  seconde session MQTT sur le même compte. ⚠️ Corollaire d'UC10, contre-intuitif : le superviseur reçoit
  le dict **brut** envoyé par le PHP, **jamais** `contexte['session']`, dont le `userData` a été
  ré-encodé et peut différer octet à octet — l'empreinte serait alors recalculée et le gestionnaire
  reconstruit à chaque alternance, soit **2 `homedata` et 2 sessions MQTT** sans que rien ne le signale.
  ⚠️ **Mais toutes les UC n'ont pas besoin de ce canal.** Les **routines** (UC09) s'exécutent par un
  **POST HTTPS signé**, sur un chemin **volontairement disjoint** : `routines.py` n'importe pas `robots.py`
  et ne touche jamais `contexte['gestionnaire']`. C'est ce qui les rend exécutables **robot hors ligne** —
  le cloud relaie l'ordre — et ce qui les garde **quota-neutres**. Le trait `device.v1_properties.routines`
  de la librairie est un leurre : wrapper sans apport sur `get_scenes`/`execute_scene`, il imposerait de
  construire le gestionnaire (décision **D-09-1**). Règle générale : avant d'appeler `obtenir_appareil()`,
  vérifier que l'opération a réellement besoin du **robot**, et pas seulement du **compte**. UC08 a protégé la **construction** par un verrou asyncio
  (`_VERROU_GESTIONNAIRE`, double-checked locking — le chemin rapide mémorisé reste hors verrou) :
  depuis que 6 boutons de dashboard et les scénarios peuvent déclencher un appel, deux appels
  concurrents sur un contexte vide construiraient deux gestionnaires, donc **deux `homedata`**.
  UC06 a extrait de `authentification.py` le module **`session.py`** : import gardé de la librairie
  (`IMPORT_OK`, `UserData`, `RoborockApiClient`), `creer_client()` et la sérialisation du `UserData`
  (`encoder_user_data`/`decoder_user_data`). **Tout nouveau module qui a besoin d'une session lit ces
  helpers dans `session.py`** — jamais un symbole privé d'`authentification.py`.
  ⚠️ Le dict `contexte['session']` (`userData`/`baseUrl`/`email`) est en revanche encore construit
  **inline** par chaque opération qui le réamorce (4 occurrences) : arbitrage assumé d'UC06 pour ne pas
  toucher au chemin d'authentification livré. À factoriser dans `session.py` au prochain cycle qui
  modifie déjà `authentification.py` (cf. § Dette de `06-decouverte-creation-equipements-tech.md`).
  ⚠️ **Ne jamais sérialiser `contexte` en bloc** dans une réponse : il porte désormais des secrets.
  ⚠️ Ces coroutines tournent dans la **boucle asyncio unique** du démon : tout appel bloquant gèle **tout**
  le canal, `/sante` compris, et se présente à l'utilisateur comme « le démon ne répond pas » alors que le
  démon est vivant. Tout code bloquant passe par `asyncio.to_thread`.
- **Deux canaux, et c'est le vrai point d'architecture du plugin.** Le `jeedom_socket` du squelette est
  *fire-and-forget*, or quatre opérations de la page de configuration exigent une **réponse immédiate**
  (envoyer le code e-mail, le valider, découvrir les robots, lister les routines). D'où :
  1. **Synchrone** — le démon expose un serveur HTTP sur **`127.0.0.1` uniquement**, protégé par
     l'**apikey** du plugin, interrogé par `jeeroborockDaemon` avec des timeouts courts. `aiohttp` étant
     déjà une dépendance transitive de `python-roborock`, cela n'ajoute **aucune** dépendance.
     ⚠️ `jeedom_socket` a été **supprimé** en UC02 (le canal HTTP le rend inutile, et il ouvrait un
     second port d'écoute plus un thread) : il n'y a **qu'un** port d'écoute, `portDemonHttp`.
  2. **Push** — le callback `jeedom_com` standard (démon → `core/php/jeeJeeroborock.php`, créé en UC02)
     pour les mises à jour d'état non sollicitées. ⚠️ Le `.htaccess` de `core/php` interdisant tout le
     répertoire, ce fichier y a une **exception ciblée** ; toute valeur reçue par ce callback est
     **externe** — l'échapper avant un `message::add()` (rendu **en HTML**) et la neutraliser avant un
     `log::add()`, cf. `jeedom-dependances-et-demon.md` §§ 5 et 7.
- **Corollaires non triviaux** : le démon doit être **lançable sans identifiants** (le login passe par
  lui, « non authentifié » est un état normal), et il **conserve l'instance client** entre l'envoi du code
  et sa validation — l'en-tête `header_clientid` dérive d'un identifiant régénéré à chaque instanciation.
- Le démon renvoie des **codes d'erreur stables** (`AUTH_EXPIRED`, `RATE_LIMIT`, `DEVICE_OFFLINE`…) que le
  PHP traduit en français. **Ne jamais parser un message d'erreur anglais de la librairie.**

## Configuration & secrets

- **Config plugin** (`config::save/byKey(..., 'jeeroborock')`) : e-mail du compte Roborock, **`UserData`**
  obtenu après authentification (jeton + identifiants dérivés `rriot`), `base_url` régionale, port du
  canal HTTP local du démon. Clés posées en UC01 : **`email`**, **`portDemonHttp`** (défaut **61350**, à
  lire **uniquement** via `jeeroborock::getPortDemonHttp()`) et **`userData`** ; UC04 y ajoute
  **`baseUrl`** (serveur régional retourné par le login — non chiffrée, **sans champ de formulaire**).
  ⚠️ Le `userData` est stocké en **`base64(JSON compact)` opaque**, jamais en JSON nu : `config::byKey`
  applique `is_json()` et relirait un JSON **en tableau PHP**, ce qui perdrait `rriot.r`
  **silencieusement** à l'aller-retour. L'état « compte lié » se lit **uniquement** via
  `jeeroborock::estCompteLie()` (présence de `userData`), jamais en interrogeant le démon.
  ⚠️ **Pas de clé de niveau
  de log propre au plugin** : le sélecteur « Niveau log » est fourni par le core sur cette même page et
  `log::add()` ne consulte que la clé cœur `log::level::jeeroborock`. Les clés **sensibles** — au
  minimum le `UserData` — sont
  déclarées dans `public static $_encryptConfigKey = array(...);` sur la classe principale → le core les
  **chiffre/déchiffre automatiquement**. Les hooks `preConfig_<clé>($value)` permettent de valider avant
  enregistrement (⚠️ `preConfig_<clé>` est un **nom de méthode fixe** — pas d'itération dynamique).
  **Il n'y a pas de champ mot de passe** : décision de cadrage, le flux d'authentification est le code
  e-mail.
- **Config par équipement** (`$eqLogic->getConfiguration('<clé>')`) : `duid`, modèle, produit, firmware,
  `pv`, indicateur « robot partagé ». ⚠️ **Aucun secret** ici — en particulier **jamais le `local_key`**
  du robot, qui ne quitte pas le démon.
- **Séparation des secrets, règle centrale du plugin** : `local_key`, identifiants `rriot` et jetons de
  session restent **côté Python**. Côté PHP, seul le `UserData` chiffré existe. Motif : une trace
  d'exception PHP expose les **arguments** de chaque frame — un secret passé en paramètre, plus un
  `displayException()` sur le chemin de sortie, et le secret atteint le DOM.
- **États volatils** : cache **chiffré** via la classe `cache` (`cache::set/byKey/delete`).
- ⚠️ **Jamais** de secret/token/mot de passe en clair dans les logs, le DOM, les réponses AJAX ou les
  commentaires — **quel que soit le niveau de log**.
- ⚠️ **La vérification TLS ne doit jamais être désactivée**, ni en PHP ni dans le démon, quel que soit le
  problème de certificat rencontré avec les serveurs régionaux Roborock. Contournement explicitement
  refusé au cadrage.

## Démon & dépendances

Le plugin **a un démon** (`hasOwnDeamon: true`) : le canal MQTT persistant et le protocole binaire
chiffré l'imposent. La règle générale « REST + polling cron, préférer sans démon » **ne s'applique pas
ici** ; ce point est arbitré, ne le rouvre pas.

- **Dépendance unique : `python-roborock`, version exacte `7.8.0`**, déclarée dans
  `plugin_info/packages.json` (`pip3`). Le démon doit être lancé avec l'interpréteur du **venv du plugin**
  (`system::getCmdPython3`), **jamais** `python3` en dur.
- ⚠️ **La version ne peut pas être plafonnée** : `packages.json` interdit les opérateurs (voir pièges
  ci-dessous), or la librairie casse son API entre majeures. Garde-fou retenu : le démon **logge au
  démarrage la version détectée** et alerte si la majeure dépasse celle testée. Une montée de version est
  une décision explicite, pas une dérive.
- ⚠️⚠️ **Pièges connus du format `packages.json`** (règles génériques Jeedom, coûteuses à redécouvrir) :
  - La **version se met dans la VALEUR, pas dans la clé** : `"python-roborock": {"version": "7.8.0"}`,
    **jamais** `"python-roborock==7.8.0": {}`. Le core compare la **clé** (nom nu) à `pip list` via
    `isset($installPackage[strtolower($clé)])` (`system::checkAndInstall`). Une clé contenant `==x.y.z` ne
    matche jamais le nom installé → paquet vu « à installer » en permanence → indicateur bloqué NOK +
    réinstallation forcée à chaque passe.
  - **Jamais de `<`/`>` dans le champ `version`** (ex. `"<2.0.0"`) : `installPackage` colle `$package .=
    $version` non quoté → redirection shell (`2.0.0: No such file or directory`, paquet jamais installé).
    Toujours une **version exacte** sans opérateur.
  - **Ne PAS définir `jeeroborock::dependancy_info()`** : dès que `packages.json` existe, le core calcule
    l'état **uniquement** depuis `checkAndInstall(packages.json)` et n'appelle jamais cette méthode
    statique (code mort). Pour un contrôle *supplémentaire*, le hook officiel est
    `additionnalDependancyCheck()` (appelé seulement si l'état `packages.json` est déjà `ok`).
    ⚠️ **Contrepartie découverte en UC02** : la rétrogradation automatique « Dépendances non
    installées » du core est conditionnée à `method_exists(<id>, 'dependancy_info')` — ne pas définir
    la méthode **désactive donc ce garde-fou**. C'est `deamon_info()` qui doit porter le contrôle.
  - ⚠️ **Purger les entrées `npm`/`yarn`/`composer` du squelette** (fait en UC02) : elles pointent vers
    des chemins inexistants → `dependancy_info()` renvoie `nok` **définitivement**, même dépendance pip
    installée, et sans aucun message expliquant pourquoi.

> Tous les contrats du core sur les dépendances et le cycle de vie du démon (hooks `deamon_*`, état par
> fichier PID, `requireOsVersion`, callback, pièges de journalisation) sont consignés dans
> **`.memory/analyse/jeedom-dependances-et-demon.md`** — y aller avant de redécouvrir.

### Quotas Roborock — contrainte de conception, pas un détail

Les quotas sont **durs** et **partagés avec l'application mobile** de l'utilisateur : login **3/min,
10/h, 20/jour** ; home data **3/min, 5/h, 40/jour**. Conséquences qui engagent le code :

- La **découverte des équipements n'est pas une opération de rafraîchissement** : elle se déclenche à la
  demande et son résultat est mis en cache.
- **Aucun retry automatique** sur le login. Jamais de boucle de re-tentative : au-delà du quota, c'est le
  compte Roborock de l'utilisateur qui est pénalisé, y compris dans son application mobile.
- Une erreur de quota produit un **message explicite invitant à patienter**, jamais une nouvelle
  tentative.
- La **ré-authentification exige une action humaine** (lire un code dans sa boîte mail) : elle ne peut pas
  être automatisée, et le plugin ne doit pas faire semblant d'essayer.

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
  principale `jeeroborock`/`jeeroborockCmd` (dont le fichier `jeeroborock.class.php` charge du même coup les classes
  annexes qu'il contient). Un appel **direct** à une classe annexe (ex. un client API `jeeroborockDaemon`) depuis
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
  appels par une **brique unique** (ex. une classe `jeeroborockDaemon`) plutôt que du cURL épars. Si le plugin a
  un démon, **toute** commande sortante passe par le pont démon (jamais de socket/MQTT épars).
- Indentation **2 espaces** en PHP/JS pour `core/class`, `core/ajax`, `desktop/js`… ; ⚠️ **exception** :
  `desktop/php/*.php` (pages) sont en **tabulations + CRLF** — respecter l'existant fichier par fichier.
  ⚠️ **Les fichiers Python sont en LF**, seule exception au CRLF du dépôt : `verif-plugin.py` ne les
  analyse pas, `.gitattributes` n'impose rien sur `*.py`, et le bot prettier ne les reformate pas.
- Logs via `log::add('jeeroborock', 'debug'|'info'|'warning'|'error', $msg)` ; **jamais** de secret exposé.
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
  { "plugins/jeeroborock/<chemin/relatif/fichier>": { "Texte français": "Traduction" } }
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

- **`.memory/specs/`** — specs des features. Convention : une feature = une spec **fonctionnelle**
  `NN-nom.md` (critères d'acceptation = *definition of done*) + une spec **technique** `NN-nom-tech.md`
  (plan d'implémentation, écrite par `/feature`). Voir `.memory/specs/README.md` pour la roadmap complète
  et la table d'ordre.
  - **`MVP/`** — 9 UC (01→09) : configuration → dépendances/démon → pont PHP↔démon → authentification →
    test de connexion → découverte des équipements → commandes info → commandes d'action →
    **routines (« usages »)**. L'UC 09 est l'exigence explicite de l'utilisateur ; elle passe par du pur
    HTTPS relayé par le cloud et fonctionne donc **même si le canal MQTT du robot est indisponible**.
  - **`post-mvp/05-temps-reel-et-robustesse/`** (10→11) — push MQTT et fraîcheur (UC10), puis robustesse
    et ré-authentification (UC11). Depuis UC10, le plugin ne s'arrête plus à un rafraîchissement à la
    demande : le démon porte la cadence (30 s en nettoyage / 60 s au repos) et le cron PHP s'est reconverti
    en **chien de garde de fraîcheur** (bascule « déconnecté » au-delà de 180 s, réarmement du superviseur
    sous garde anti-rafale de 600 s).
  - **`post-mvp/10-etats-detailles/`** (12→15) — consommables, station d'accueil, erreurs, widget tuile.
  - **`post-mvp/20-carte-et-pieces/`** (16→19) — pièces/segments, cartes multiples, image, vue carte.
    Domaine le plus coûteux techniquement.
  - **`post-mvp/30-pilotage-fin/`** (20→25) — aspiration, eau, itinéraire/mode, entretien, **nettoyage par
    pièce**, zone et point.
  - **`post-mvp/40-journal-et-planification/`** (26→28) — journal, statistiques, programmations.
  - **`post-mvp/50-transport-local-et-diagnostic/`** (29→31) — transport local, diagnostic, firmware.
- **`.memory/analyse/`** — connaissance **transverse et réutilisable**, **découvrable via
  `.memory/analyse/INDEX.md`** (§ 0 = incertitude → fichier). Ne redécouvre pas ce qui y est déjà vérifié.
  - Propres au plugin : `jeeroborock-architecture.md` (décisions D1→D10), `jeeroborock-cloud-api.md`
    (contrat HTTPS, login, `homedata`, **routines**, quotas), `jeeroborock-mqtt-protocole.md` (canal
    robot, framing, push, catalogue de commandes, cartes), `jeeroborock-modele-equipement.md` (mapping
    Jeedom), `jeeroborock-implementations-reference.md` (table « incertitude → implémentation de
    référence » : `python-roborock`, Home Assistant, ioBroker).
  - Génériques Jeedom : `jeedom-widgets-commandes.md`, `jeedom-panel-page-menu.md`,
    `jeedom-config-plugin-defauts.md`, `jeedom-dependances-et-demon.md`.
- **`.memory/external/doc/jeedom/INDEX.md`** — index de la doc développeur Jeedom (pour un `WebFetch`
  ciblé sans re-parcourir le sommaire).

L'outillage `/` fonctionne en **deux temps** (le bootstrap `/init-plugin` a déjà eu lieu) :

- **Implémentation — `/feature <spec>`** (à lancer par UC) : à partir d'une spec fonctionnelle, **fait
  produire le plan technique par l'agent `jeedom-tech-planner`**, le fait valider par l'utilisateur, écrit
  la spec technique, délègue l'implémentation à l'agent `php-jeedom-dev` (skill `dev`), lance les reviews
  croisées (`code-reviewer`, `security-reviewer`), puis la traduction (`translator`) et la capitalisation
  mémoire.
  ⚠️ **Chaque sous-agent épingle son `effort` dans son frontmatter** : sans cette ligne il **hérite de
  l'effort de la session**, et la boucle d'édition mécanique tourne au niveau de réflexion de
  l'orchestrateur — c'est là que partent les tokens. La réflexion coûteuse est concentrée là où une erreur
  se paie cher : le **plan** (`jeedom-tech-planner`, Opus `xhigh`) et les **reviews**
  (`code-reviewer`/`security-reviewer`, `high`). Elle est volontairement basse là où le travail est
  mécanique et déjà cadré (`php-jeedom-dev` `medium`, `spec-writer` `medium`, `translator` `low`).
- **Enchaînement autonome — `/auto-dev "<liste d'UC>"`** puis **`/change <explication>`** : le mode
  « sans humain dans la boucle », détaillé ci-dessous.

> **Ajouter un domaine plus tard** : les agents `jeedom-plugin-architect` (analyse + roadmap) et
> `spec-writer` (specs fonctionnelles) restent utilisables hors bootstrap, sans rejouer `/init-plugin`.

### Mode autonome — `/auto-dev` et `/change`

`/auto-dev "MVP 04 .. MVP 08"` (intervalle) ou `/auto-dev "MVP 04, MVP 06, MVP 08"` (liste) enchaîne
des cycles `/feature` complets **sans poser de question** : à chaque gate humaine, l'agent **tranche**
selon la grille `.claude/templates/principes-arbitrage.md` puis **journalise** l'arbitrage. Une UC =
**un sous-agent `auto-dev-runner`** en contexte neuf, et **un commit sur `master`** — jamais de `push`.

✅ **Prérequis rempli** : les 31 specs fonctionnelles existent (`/init-plugin` a été joué le
2026-09-17). `/auto-dev` a donc de quoi travailler dès maintenant — l'ordre naturel est
`/auto-dev "MVP 01 .. MVP 09"`, ou UC par UC avec `/feature` pour garder la main.

⚠️ **La grille `.claude/templates/principes-arbitrage.md` a été spécialisée pour ce plugin** (P2
invariants, P5 dépendances/démon) : elle porte les décisions de cadrage — démon obligatoire,
`python-roborock` 7.8.0 épinglé, aucun appel cloud en PHP, code e-mail seul, secrets cantonnés au démon,
quotas durs. C'est elle qui **remplace les réponses de l'utilisateur** en mode autonome. Si une décision
de cadrage évolue, mettre la grille à jour **en même temps** que `CLAUDE.md`, sinon `/auto-dev` tranchera
d'après l'ancien cadrage.

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
  (son titre est repris d'`info.json`, soit « JeeRoborock »). Chaque entrée est
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
> des UC (ça, c'est le rôle de `.memory/specs/`, du journal `/auto-dev` et de la documentation
> utilisateur sous `docs/`).
