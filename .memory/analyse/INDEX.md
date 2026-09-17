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
> **Dernière mise à jour** : 2026-09-17 (cadrage `/init-plugin` : ajout des cinq analyses `jeeroborock-*`).

---

## 0. Correspondance « incertitude » → fichier d'analyse (raccourci)

| Si l'incertitude porte sur… | Fichier |
|---|---|
| **Widget de commande** Jeedom (fichier `cmd.<type>.<subType>.<nom>.html`, `setTemplate`, tokens `#id#`…) | `jeedom-widgets-commandes.md` §§ 1-2 |
| Widget pilotant **plusieurs commandes** (tuile + actions) ; résoudre les sœurs par `byEqLogic` | `jeedom-widgets-commandes.md` § 3 |
| Exécuter une action depuis un widget + récupérer le retour PHP ; auth/CSRF AJAX ; AJAX plugin admin-only | `jeedom-widgets-commandes.md` §§ 4-5 |
| **Confirmation avant une action sensible** (dialog anti-fausse-manip) : comment l'activer côté serveur | `jeedom-widgets-commandes.md` § 4 (`actionConfirm=1` → -32006) |
| **Commande action PARAMÉTRÉE** (saisie utilisateur : subType `message`, valeur dans `$_options['message']`) | `jeedom-widgets-commandes.md` § 4 |
| Appliquer un **template de widget sans écraser** le choix utilisateur (« si vide ») | `jeedom-widgets-commandes.md` § 6 |
| **CSP Jeedom bloque tout média/image EXTERNE** → proxy same-origin (ex. tuile carte) | `jeedom-widgets-commandes.md` § 7 |
| Ajouter une **PAGE** au menu Jeedom (panel) ; toggle natif `displayDesktopPanel/Mobile` ; page non-admin | `jeedom-panel-page-menu.md` |
| **Afficher une image externe dans un panel** (carte…) : `data:` URI inline (panel serveur) vs proxy (widget client) | `jeedom-panel-page-menu.md` § 4 |
| **Pourquoi un démon** ici, pont PHP↔démon (canal synchrone + callback), venv Python, `packages.json`, `info.json` du plugin | `jeeroborock-architecture.md` |
| Où **stocker les identifiants Roborock** (config chiffrée vs cache), ré-authentification, quotas/rate-limits | `jeeroborock-architecture.md` D4/D6 + `jeeroborock-cloud-api.md` §§ 2, 4 |
| **Login Roborock** (code e-mail vs mot de passe), `UserData`/`rriot`, signature Hawk, serveur régional | `jeeroborock-cloud-api.md` §§ 1-3 |
| **Routines / « usages »** : lister et exécuter (endpoints, modèle, limites) | `jeeroborock-cloud-api.md` § 5 |
| `homedata` : `duid`, `local_key`, `pv` (V1/A01/B01), produits, pièces ; quotas de découverte | `jeeroborock-cloud-api.md` § 4 |
| **MQTT Roborock** : dérivation des identifiants, topics, framing 101/102, **push dps** (batterie, état…) | `jeeroborock-mqtt-protocole.md` §§ 1-3 |
| Quels **états/commandes** existent sur un robot V1, capacités **dépendantes du modèle**, cartes & pièces | `jeeroborock-mqtt-protocole.md` §§ 4-8 |
| Mapping **eqLogic/commandes Jeedom** (logicalId = `duid`), commandes de routine, création conditionnelle | `jeeroborock-modele-equipement.md` |
| « Où lire le contrat ? » — fichiers de `python-roborock`, Home Assistant, ioBroker + points non confirmés | `jeeroborock-implementations-reference.md` |

> Si aucun fichier ne couvre le sujet : ce n'est pas (encore) analysé en interne → passer à la doc externe
> (`.memory/external/doc/jeedom/INDEX.md` pour le core Jeedom, ou la doc de l'API tierce du plugin), et
> penser à capitaliser en Étape 12.

---

## 1. Catalogue des analyses

| Fichier | Sujet | Points clés indexés |
|---|---|---|
| `jeedom-widgets-commandes.md` | Widgets de commande Jeedom (templates dashboard/mobile), vérifié contre la source du core. | `cmd.<type>.<subType>.<nom>.html` + `setTemplate('<id>::<nom>')` ; tokens (`#id#`/`#logicalId#`/`#eqLogic_id#`/`#uid#`…) ; `#cmd_id[…]#` & `jeedom.cmd.byEqLogicId` **n'existent pas** → résoudre par AJAX **`byEqLogic`** ; **masqué ≠ non-exécutable** ; `jeedom.cmd.execute` (CSRF/droits, `success.result`=retour PHP) ; confirmation d'action `actionConfirm=1` → -32006 ; commande **paramétrée** subType `message` ; AJAX plugin admin-only inutilisable au dashboard ; **§ 7 CSP : média/image externe bloqué → proxy same-origin**. |
| `jeedom-panel-page-menu.md` | Page de plugin au **menu** Jeedom (panel) & toggle d'affichage natif. | `info.json "display"`/`"mobile"` enregistre une page-panneau ; le core ajoute nativement les cases « Afficher le panneau desktop/mobile » (`displayDesktopPanel`/`displayMobilePanel`, masqué par défaut) → aucun toggle custom ; `plugin::getDisplay()` statique ; page panel = `isConnect()` non-admin + accès par eqLogic `hasRight('r')` + sélection par équipement ; **image externe : `data:` URI inline en panel serveur vs proxy same-origin en widget client** ; réf. `jeedom/plugin-gsl`. |

### Analyses propres au plugin JeeRoborock (2026-09-17)

| Fichier | Sujet | Points clés indexés |
|---|---|---|
| `jeeroborock-architecture.md` | **Décisions d'architecture du plugin** (2026-09-17) : pourquoi un démon Python sur `python-roborock`, pont PHP↔démon, secrets, quotas, dépendances. | D1 démon obligatoire (MQTT + protocole binaire + push) ; D2 aucun appel Roborock en PHP ; D3 **deux canaux** (HTTP local synchrone `127.0.0.1` + callback `jeedom_com`) ; D4 auth par **code e-mail**, `UserData` en config **chiffrée** ; D5 1 eqLogic = 1 robot (`logicalId` = `duid`) ; D6 quotas durs (20 logins/j, 40 homedata/j) + cadence 30/60 s ; D7 `packages.json` `python-roborock` + **venv par plugin** + **Python ≥ 3.11 → Debian 12 mini** ; D8 catégorie **`devicecommunication`** ; D9 « cloud only » **non exposé** par la lib ; D10 `jeeroborockDaemon` en fichier de classe dédié (autoload). |
| `jeeroborock-cloud-api.md` | Contrat **HTTPS** du cloud Roborock (auth, homedata, **routines**), reconstitué depuis `python-roborock` 7.8.0. | `getUrlByEmail` (serveur régional) ; `request_code_v4` + `code_login_v4` (signature `x-mercy-ks/k`) ; `pass_login` fragile / `pass_login_v3` **non implémenté** ; `UserData`/`rriot` (`r.a` API, `r.m` MQTT) ; signature **Hawk** ; `home_data_v3` (`duid`, `local_key`, `pv`, `schema`) ; **scènes** `GET /user/scene/device/<duid>` + `POST /user/scene/<id>/execute` ; `jobs` (planifications) ; OTA ; **limiteurs** login 20/j et homedata 5/h-40/j ; exceptions typées → codes stables. |
| `jeeroborock-mqtt-protocole.md` | Canal robot : MQTT chiffré, protocole **V1**, commandes et états disponibles. | Identifiants MQTT dérivés de `rriot` (md5) ; session **unique** partagée ; topics `rr/m/i|o/...` ; `RPC_REQUEST=101`/`RESPONSE=102`, `MAP_RESPONSE=301` ; **push dps** 120-135 (état, batterie, erreur, conso) ; pull `refresh()` vs push ; `StatusV2` (état, batterie, erreur, surface, durée, station) ; `RoborockStateCode` ; consommables + durées de référence ; catalogue `RoborockCommand` (base / fin / lecture) ; **traits optionnels selon capacités** ; cartes (PNG rendu par `vacuum-map-parser-roborock`) & pièces (`segment_id`) ; journal ; cycle de vie `start_connect`/backoff ; valeurs `a135` (fan 101-104 + **108 Max+**, eau 200-203, itinéraire 300/301/303). |
| `jeeroborock-modele-equipement.md` | Mapping **Jeedom** : eqLogic, commandes MVP, routines. | `logicalId` = `duid` ; config d'équipement **sans secret** ; 11 commandes info + 6 actions du socle ; **une commande action par routine** (`routine_<sceneId>`) + politique d'obsolescence ; création **conditionnelle** selon capacités ; options de modes **dynamiques** ; push démon → callback, cron = **chien de garde** ; widgets par défaut au MVP, carte en post-MVP (CSP). |
| `jeeroborock-implementations-reference.md` | **Où lire le contrat** (aucune doc officielle Roborock) : table « incertitude → fichier » des dépôts de référence. | Hiérarchie des sources (python-roborock > Home Assistant > ioBroker > Roborockmitmproxy) ; comment récupérer la version exacte du wheel ; table par fichier (`web_api.py`, `containers.py`, `v1_channel.py`, `traits/v1/*`…) ; HA (`config_flow`, `coordinator`, `const`) ; ioBroker (`a135_features.ts`) ; **divergences** entre sources ; **liste des points à confirmer en recette**. |
