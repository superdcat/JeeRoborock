# Index des analyses internes — connaissance Jeedom réutilisable

> **But** : rendre la connaissance interne (décisions d'architecture, limites/pièges, apprentissages
> durables) **découvrable et lazy-loadable** par le workflow de dev, sans tout charger. L'agent lit cet
> index (gratuit, local), repère le fichier d'analyse utile, puis ouvre **uniquement** ce fichier.
>
> `.memory/analyse/` complète `.memory/specs/` (intention des features) et la doc externe
> (`.memory/external/doc/`) : ici on consigne ce que **le projet a tranché** ou ce qu'on a **appris en
> codant** — ce que ni le code, ni git, ni `CLAUDE.md` ne disent déjà.
>
> **Maintenance** : à chaque enseignement durable (Étape 12 du workflow `/feature`), écrire dans le bon
> fichier thématique (ou en créer un) **et mettre à jour cet index** (ligne + déclencheurs § 0 + date).
>
> Deux analyses **génériques Jeedom** (vérifiées contre la source du core) sont réutilisables par tout
> plugin ; les analyses préfixées **`jeeroborock-`** sont **propres à ce plugin** (intégration Roborock).
>
> **Dernière mise à jour** : 2026-09-18 (UC08 : `jeedom-widgets-commandes.md` § 4 **corrigé** — un
> retour `array`/`object` de `cmd::execute()` est écrasé en chaîne vide par `formatValue()`, l'ancienne
> rédaction laissait croire l'inverse ; `cmd.ajax.php` ne relâche pas le verrou de session et sort par
> `displayException()` ; piège de la `value` sur une commande action (`isAlreadyInStateAllow`) ;
> `numberTryWithoutSuccess` jamais incrémenté. Avant : UC07 : codes d'état/erreur inconnus écrasés en 0 par la
> librairie ; `is_field_supported()` vaut `True` par défaut sans métadonnée ; `clean_area` est en
> **mm²** et non cm² ; un `.js` de plugin est bien traduit. Avant : UC06 : `jeeroborock-modele-equipement.md` § 1 — le critère de
> compatibilité V1 exige **aussi** la catégorie `VACUUM` et un produit résolu, pas seulement `pv` ;
> `device_products` perd silencieusement un produit inconnu ; l'indicateur « partagé » est
> `received_devices` et **jamais** `HomeDataDevice.share`. Avant : UC05 : `jeeroborock-cloud-api.md` § 4.1 — sonde de session sans
> quota via `getHomeDetail`, refus du limiteur `home_data` détecté avant tout appel réseau,
> `get_all_devices()` = robots propres + partagés, piège `base_url=""`. Avant : UC04 —
> `jeedom-config-plugin-defauts.md` § 6 — le verrou de
> session tenu pendant les hooks `preConfig_` ; `jeeroborock-cloud-api.md` §§ 2.1/2.3 — pièges du limiteur
> de login et de la sérialisation de `UserData`, § 8.2 — mapping réseau corrigé via `__cause__` ;
> `jeeroborock-architecture.md` D4 étape 4 corrigée. Avant : UC03 — contrat du canal PHP↔démon référencé au
> § 0 ; § 8 rendu exhaustif, 23 exceptions `python-roborock` 7.8.0).

---

## 0. Correspondance « incertitude » → fichier d'analyse (raccourci)

| Si l'incertitude porte sur… | Fichier |
|---|---|
| **Widget de commande** Jeedom (fichier `cmd.<type>.<subType>.<nom>.html`, `setTemplate`, tokens `#id#`…) | `jeedom-widgets-commandes.md` §§ 1-2 |
| Widget pilotant **plusieurs commandes** (tuile + actions) ; résoudre les sœurs par `byEqLogic` | `jeedom-widgets-commandes.md` § 3 |
| Exécuter une action depuis un widget + récupérer le retour PHP ; auth/CSRF AJAX ; AJAX plugin admin-only | `jeedom-widgets-commandes.md` §§ 4-5 |
| **Confirmation avant une action sensible** (dialog anti-fausse-manip) : comment l'activer côté serveur | `jeedom-widgets-commandes.md` § 4 (`actionConfirm=1` → -32006) |
| ⚠️ **Ce que `cmd::execute()` peut RETOURNER** : un `array`/`object` arrive au widget en **chaîne vide** (`formatValue`), silencieusement → scalaire ou `json_encode()` | `jeedom-widgets-commandes.md` § 4 |
| ⚠️ **Une commande action LENTE (démon, API tierce) fige l'interface Jeedom** : `cmd.ajax.php` ne relâche jamais le verrou de session → `session_write_close()` dans `execute()`, sous garde | `jeedom-widgets-commandes.md` § 4 |
| **Secret et trace d'exception sur le chemin d'une commande** : `cmd.ajax.php` sort par `displayException()` → `getTraceAsString()` dans le DOM en log `debug` global ⇒ jamais de secret en **argument scalaire** | `jeedom-widgets-commandes.md` § 4 |
| Commande **action** sautée en silence (« succès » sans exécution) : piège de la `value` liée et d'`isAlreadyInStateAllow()` ; `numberTryWithoutSuccess` jamais incrémenté par le cœur | `jeedom-widgets-commandes.md` § 4 |
| **Commande action PARAMÉTRÉE** (saisie utilisateur : subType `message`, valeur dans `$_options['message']`) | `jeedom-widgets-commandes.md` § 4 |
| Appliquer un **template de widget sans écraser** le choix utilisateur (« si vide ») | `jeedom-widgets-commandes.md` § 6 |
| **CSP Jeedom bloque tout média/image EXTERNE** → proxy same-origin (ex. tuile carte) | `jeedom-widgets-commandes.md` § 7 |
| Ajouter une **PAGE** au menu Jeedom (panel) ; toggle natif `displayDesktopPanel/Mobile` ; page non-admin | `jeedom-panel-page-menu.md` |
| **Afficher une image externe dans un panel** (carte…) : `data:` URI inline (panel serveur) vs proxy (widget client) | `jeedom-panel-page-menu.md` § 4 |
| **Pourquoi un démon** ici, pont PHP↔démon (canal synchrone + callback), venv Python, `packages.json`, `info.json` du plugin | `jeeroborock-architecture.md` |
| **Appeler le démon depuis le PHP** : `jeeroborockDaemon::appeler()`, format de requête/réponse du canal (`POST /rpc`, enveloppe `{success,data|error}`), budgets de temps, en-tête `X-Apikey` | `.memory/specs/MVP/03-pont-php-demon-tech.md` § Contrat du canal |
| **Ajouter une nouvelle opération au démon** : `canal.enregistrer('<operation>', <coroutine>)` côté Python + `jeeroborockDaemon::appeler('<operation>', array(...))` côté PHP — **jamais** une nouvelle route HTTP | `.memory/specs/MVP/03-pont-php-demon-tech.md` §§ Signatures, Contrat du canal |
| Opération du démon qui **fige tout le canal** (`/sante` compris) et se voit comme « le démon ne répond pas » : **boucle asyncio unique** → tout code bloquant passe par `asyncio.to_thread` | `.memory/specs/MVP/03-pont-php-demon-tech.md` R1 |
| **Quel code d'erreur** pour telle situation, et son message FR ; ⚠️ **ajouter un code de famille A ou B se fait dans DEUX fichiers** (`jeeroborockDaemon::tableMessages()` **et** `jeeroborockException::estErreurCanal()`) — aucun contrôle automatique ne détecte l'oubli | `.memory/specs/MVP/03-pont-php-demon-tech.md` § Table exhaustive + § Dette |
| Exceptions `python-roborock` 7.8.0 (**23**) → code stable → message français | `jeeroborock-cloud-api.md` § 8 (source de vérité : spec technique UC03) |
| Où **stocker les identifiants Roborock** (config chiffrée vs cache), ré-authentification, quotas/rate-limits | `jeeroborock-architecture.md` D4/D6 + `jeeroborock-cloud-api.md` §§ 2, 4 |
| **Vérifier qu'une session Roborock est encore valide SANS consommer de quota** (sonde `getHomeDetail`, seule requête authentifiée sans limiteur) ; détecter un quota `homedata` atteint **sans appel réseau** ; compter les robots (propres **et** partagés) ; ⚠️ piège `base_url=""` | `jeeroborock-cloud-api.md` § 4.1 |
| **Clés de configuration plugin** (`email`, `portDemonHttp`, `userData`), **port du canal local** (valeur, défaut `.ini`, `getPortDemonHttp()`), validation `preConfig_` | `jeeroborock-architecture.md` D3/D4 + `.memory/specs/MVP/01-config-plugin-tech.md` |
| **Valeur par défaut d'une config plugin** Jeedom : pourquoi un `value=` HTML ne marche pas, et le piège du court-circuit de `preConfig_` | `jeedom-config-plugin-defauts.md` |
| ⚠️ **Un hook `preConfig_`/`postConfig_` qui appelle le réseau FIGE l'interface Jeedom** : `core/ajax/config.ajax.php` ne relâche **jamais** le verrou de session → le hook doit faire son propre `session_write_close()` sous garde | `jeedom-config-plugin-defauts.md` § 6 |
| **`packages.json`** : indicateur de dépendance bloqué à NOK sans cause, entrées `npm`/`yarn`/`composer` du template, venv, `getCmdPython3` | `jeedom-dependances-et-demon.md` §§ 1-2 |
| **Bloquer l'activation** sous une version d'OS : `requireOsVersion` (et pourquoi `os.min` ne sert à rien pour ça) | `jeedom-dependances-et-demon.md` § 3 |
| **Hooks `deamon_info`/`deamon_start`/`deamon_stop`** : ce que le core avale, garde-fou 45 s, état par fichier PID, nommage du démon | `jeedom-dependances-et-demon.md` § 4 |
| **Valeur externe affichée ou journalisée** : `message::add()` rend du HTML ; forge de lignes de log ; `mb_scrub` | `jeedom-dependances-et-demon.md` § 5 |
| **Masquer un secret dans un log** alors que la commande est passée à `escapeshellarg()` | `jeedom-dependances-et-demon.md` § 6 |
| **Callback démon → Jeedom** (`core/php/jee<Id>.php`) : apikey, `401`, blocage par le `.htaccess` du template | `jeedom-dependances-et-demon.md` § 7 |
| Démon Python qui **ne démarre pas** : lib `jeedom/jeedom.py` du template non importable ; log muet au niveau par défaut | `jeedom-dependances-et-demon.md` §§ 8-9 |
| **Login Roborock** (code e-mail vs mot de passe), `UserData`/`rriot`, signature Hawk, serveur régional | `jeeroborock-cloud-api.md` §§ 1-3 |
| ⚠️ **Pièges du limiteur de login** : `code_login_v4` ne consomme **aucun** jeton, compteur **par processus**, une demande peut coûter **2** jetons, **aucun timeout par requête** dans la lib ; `__version__` absent | `jeeroborock-cloud-api.md` § 2.1 |
| **Persister `UserData`** : `as_dict`/`from_dict` silencieusement tolérants → contrôler `token`/`rriot`/`rriot.r` ; pourquoi le stockage est en **base64 opaque** et jamais en JSON nu | `jeeroborock-cloud-api.md` § 2.3 |
| Une erreur réseau remonte en **`RoborockException` nue** (pas `CLOUD_UNREACHABLE`) : la lib enveloppe par `raise … from err` → tout mapping par type doit regarder **`__cause__`** | `jeeroborock-cloud-api.md` § 8.2 |
| **Routines / « usages »** : lister et exécuter (endpoints, modèle, limites) | `jeeroborock-cloud-api.md` § 5 |
| `homedata` : `duid`, `local_key`, `pv` (V1/A01/B01), produits, pièces ; quotas de découverte | `jeeroborock-cloud-api.md` § 4 |
| **MQTT Roborock** : dérivation des identifiants, topics, framing 101/102, **push dps** (batterie, état…) | `jeeroborock-mqtt-protocole.md` §§ 1-3 |
| Quels **états/commandes** existent sur un robot V1, capacités **dépendantes du modèle**, cartes & pièces | `jeeroborock-mqtt-protocole.md` §§ 4-8 |
| Mapping **eqLogic/commandes Jeedom** (logicalId = `duid`), commandes de routine, création conditionnelle | `jeeroborock-modele-equipement.md` |
| ⚠️ **Un robot est-il supporté ?** Le critère n'est **pas** seulement `pv == "1.0"` : il faut **aussi** la catégorie `robot.vacuum.cleaner` **et** un produit résolu (`get_home_data_v3` renvoie aussi les non-robots du compte) ; ne pas utiliser `device_products`, qui perd silencieusement un produit inconnu | `jeeroborock-modele-equipement.md` § 1 |
| ⚠️ **Détecter un robot PARTAGÉ** : c'est l'appartenance à `received_devices` — le champ `HomeDataDevice.share` existe mais n'est lu **nulle part** dans la librairie, sa sémantique n'est corroborée par rien | `jeeroborock-modele-equipement.md` § 1 |
| « Où lire le contrat ? » — fichiers de `python-roborock`, Home Assistant, ioBroker + points non confirmés | `jeeroborock-implementations-reference.md` |
| ⚠️ **Un code d'état / d'erreur inconnu de la librairie est écrasé SILENCIEUSEMENT** : `RoborockStateCode` retombe sur `unknown` (0), et `RoborockErrorCode`, qui n'a **pas** de membre `unknown`, retombe sur son premier membre — `none` (0) — donc **une erreur inconnue se présente comme « aucune erreur »** | `jeeroborock-mqtt-protocole.md` § 4 |
| ⚠️ **Une capacité de `StatusV2` est-elle supportée ?** `is_field_supported()` renvoie **`True` par défaut** pour un champ **sans métadonnée** (`clean_area`, `clean_time`) : un critère `valeur is not None OR is_field_supported(…)` vaut alors `True` en permanence — deux familles de champs, deux critères, à ne jamais unifier | `jeeroborock-modele-equipement.md` § 2.1 |
| ⚠️ **Un `.js` de plugin EST traduit** (via `getResource.php` → `translate::exec(…, true)`) : seuls `3rdparty` et `*.min.js` ne le sont pas. Ses littérales traduisibles s'écrivent en **apostrophes simples**, à l'inverse de `configuration.txt` | `jeedom-widgets-commandes.md` § i18n |

> Si aucun fichier ne couvre le sujet : ce n'est pas (encore) analysé en interne → passer à la doc externe
> (`.memory/external/doc/jeedom/INDEX.md` pour le core Jeedom, ou la doc de l'API tierce du plugin), et
> penser à capitaliser en Étape 12.

---

## 1. Catalogue des analyses

| Fichier | Sujet | Points clés indexés |
|---|---|---|
| `jeedom-widgets-commandes.md` | Widgets de commande Jeedom (templates dashboard/mobile), vérifié contre la source du core. | `cmd.<type>.<subType>.<nom>.html` + `setTemplate('<id>::<nom>')` ; tokens (`#id#`/`#logicalId#`/`#eqLogic_id#`/`#uid#`…) ; `#cmd_id[…]#` & `jeedom.cmd.byEqLogicId` **n'existent pas** → résoudre par AJAX **`byEqLogic`** ; **masqué ≠ non-exécutable** ; `jeedom.cmd.execute` (CSRF/droits, `success.result`=retour PHP) ; confirmation d'action `actionConfirm=1` → -32006 ; commande **paramétrée** subType `message` ; AJAX plugin admin-only inutilisable au dashboard ; **§ 7 CSP : média/image externe bloqué → proxy same-origin**. |
| `jeedom-panel-page-menu.md` | Page de plugin au **menu** Jeedom (panel) & toggle d'affichage natif. | `info.json "display"`/`"mobile"` enregistre une page-panneau ; le core ajoute nativement les cases « Afficher le panneau desktop/mobile » (`displayDesktopPanel`/`displayMobilePanel`, masqué par défaut) → aucun toggle custom ; `plugin::getDisplay()` statique ; page panel = `isConnect()` non-admin + accès par eqLogic `hasRight('r')` + sélection par équipement ; **image externe : `data:` URI inline en panel serveur vs proxy same-origin en widget client** ; réf. `jeedom/plugin-gsl`. |

| `jeedom-dependances-et-demon.md` | **Dépendances Python et cycle de vie d'un démon**, vérifié contre la source du core (2026-09-17, UC02). | ⚠️ Entrées `npm`/`yarn`/`composer` du **template** → `dependancy_info` **`nok` à vie** même dépendance installée ; version déclarée = **minimum** alors que l'install **épingle** ; `/tmp/jeedom_install_in_progress_<id>` en dur ; `getCmdPython3` **ne dépend pas** du venv → tester `getPython3VenvDir() . '/bin/python3'` ; ⚠️ **ne pas définir `dependancy_info()` désactive le garde-fou « dépendances » du core** (`method_exists`) → le porter dans `deamon_info()` ; **`requireOsVersion`** bloque l'activation, **`os.min` ne sert qu'au market** (clé **non documentée**) ; `deamon_info()` appelé **sans try/catch** et chaque minute → ne jamais lever, rester peu coûteuse, poser les **4 clés** ; exception de `deamon_start()` **avalée** par le core → passer par `launchable_message`/`message::add` ; **garde-fou 45 s** ; **bug de la boucle d'attente du wiki** ; état par **fichier PID** → PID recyclé (chemin **absolu**) + purge `/tmp` **10 jours** ; ⚠️ **renommer le démon** (`system::kill` par `ps\|grep` tue ceux des autres plugins) ; **`message::add()` rend du HTML** → `htmlspecialchars`, tronquer ne protège pas ; forge de lignes de log, `mb_scrub` ; ⚠️ **`escapeshellarg()` casse un masquage de secret par `str_replace`** ; callback : **`401`** (le wiki dit 200), `.htaccess` du template → **403** ; lib `jeedom/jeedom.py` du template **non importable** (`serial`/`pyudev`/`requests`) ; **niveau de log `error` par défaut** → `logging.info` muet. |
| `jeedom-config-plugin-defauts.md` | **Valeurs par défaut et cycle de vie d'une config plugin**, vérifié contre la source du core (2026-09-17). | Une valeur par défaut ne peut **PAS** venir d'un `value=` HTML (`setJeeValues` l'écrase) → **seul** `core/config/<id>.config.ini` marche, section = id ; ⚠️ enregistrer **la valeur par défaut supprime la ligne** et **court-circuite `preConfig_`** ; ordre réel défaut → `preConfig_` → chiffrement ; **divergence `byKey`/`byKeys`** sur la chaîne vide → normaliser « vide → défaut » à l'écriture ; `is_json` ne convertit que les **tableaux** ; **`getKey` n'est pas admin-only** → jamais de champ `.configKey` pour un secret, jamais de `preConfig_`/`postConfig_` dessus (trace `displayException`) ; page de config **non protégée par le core** → `isConnect('admin')` dans le fichier ; **sélecteur « Niveau log » fourni par le core** (`log::level::<id>`) → pas de clé `logLevel` maison ; une exception dans `preConfig_` **interrompt** la boucle d'enregistrement. |

### Analyses propres au plugin JeeRoborock (2026-09-17)

| Fichier | Sujet | Points clés indexés |
|---|---|---|
| `jeeroborock-architecture.md` | **Décisions d'architecture du plugin** (2026-09-17) : pourquoi un démon Python sur `python-roborock`, pont PHP↔démon, secrets, quotas, dépendances. | D1 démon obligatoire (MQTT + protocole binaire + push) ; D2 aucun appel Roborock en PHP ; D3 **deux canaux** (HTTP local synchrone `127.0.0.1` + callback `jeedom_com`), **port `61350`**, clé `portDemonHttp`, lecture via `jeeroborock::getPortDemonHttp()` ; D4 auth par **code e-mail** — **aucun champ mot de passe** (révisé en UC01) —, `UserData` en config **chiffrée** ; D5 1 eqLogic = 1 robot (`logicalId` = `duid`) ; D6 quotas durs (20 logins/j, 40 homedata/j) + cadence 30/60 s ; D7 `packages.json` `python-roborock` + **venv par plugin** + **Python ≥ 3.11 → Debian 12 mini** ; D8 catégorie **`devicecommunication`** ; D9 « cloud only » **non exposé** par la lib ; D10 `jeeroborockDaemon` en fichier de classe dédié (autoload). |
| `jeeroborock-cloud-api.md` | Contrat **HTTPS** du cloud Roborock (auth, homedata, **routines**), reconstitué depuis `python-roborock` 7.8.0. | `getUrlByEmail` (serveur régional) ; `request_code_v4` + `code_login_v4` (signature `x-mercy-ks/k`) ; `pass_login` fragile / `pass_login_v3` **non implémenté** ; `UserData`/`rriot` (`r.a` API, `r.m` MQTT) ; signature **Hawk** ; `home_data_v3` (`duid`, `local_key`, `pv`, `schema`) ; **scènes** `GET /user/scene/device/<duid>` + `POST /user/scene/<id>/execute` ; `jobs` (planifications) ; OTA ; **limiteurs** login 20/j et homedata 5/h-40/j ; § 8 **23 exceptions typées → codes stables → messages FR** (table exhaustive, source de vérité : spec technique UC03). |
| `jeeroborock-mqtt-protocole.md` | Canal robot : MQTT chiffré, protocole **V1**, commandes et états disponibles. | Identifiants MQTT dérivés de `rriot` (md5) ; session **unique** partagée ; topics `rr/m/i|o/...` ; `RPC_REQUEST=101`/`RESPONSE=102`, `MAP_RESPONSE=301` ; **push dps** 120-135 (état, batterie, erreur, conso) ; pull `refresh()` vs push ; `StatusV2` (état, batterie, erreur, surface, durée, station) ; `RoborockStateCode` ; consommables + durées de référence ; catalogue `RoborockCommand` (base / fin / lecture) ; **traits optionnels selon capacités** ; cartes (PNG rendu par `vacuum-map-parser-roborock`) & pièces (`segment_id`) ; journal ; cycle de vie `start_connect`/backoff ; valeurs `a135` (fan 101-104 + **108 Max+**, eau 200-203, itinéraire 300/301/303). |
| `jeeroborock-modele-equipement.md` | Mapping **Jeedom** : eqLogic, commandes MVP, routines. | `logicalId` = `duid` ; config d'équipement **sans secret** ; 11 commandes info + 6 actions du socle ; **une commande action par routine** (`routine_<sceneId>`) + politique d'obsolescence ; création **conditionnelle** selon capacités ; options de modes **dynamiques** ; push démon → callback, cron = **chien de garde** ; widgets par défaut au MVP, carte en post-MVP (CSP). |
| `jeeroborock-implementations-reference.md` | **Où lire le contrat** (aucune doc officielle Roborock) : table « incertitude → fichier » des dépôts de référence. | Hiérarchie des sources (python-roborock > Home Assistant > ioBroker > Roborockmitmproxy) ; comment récupérer la version exacte du wheel ; table par fichier (`web_api.py`, `containers.py`, `v1_channel.py`, `traits/v1/*`…) ; HA (`config_flow`, `coordinator`, `const`) ; ioBroker (`a135_features.ts`) ; **divergences** entre sources ; **liste des points à confirmer en recette**. |
