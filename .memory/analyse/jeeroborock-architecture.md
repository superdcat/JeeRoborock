# JeeRoborock — Architecture d'intégration (décisions structurantes)

> **Portée** : décisions d'architecture du plugin `jeeroborock` (pilotage d'aspirateurs Roborock via le
> cloud Roborock). Ce fichier porte les **arbitrages** ; le contrat externe est détaillé dans
> `jeeroborock-cloud-api.md` (HTTPS) et `jeeroborock-mqtt-protocole.md` (MQTT / commandes robot), le
> mapping Jeedom dans `jeeroborock-modele-equipement.md`, et les sources de vérité dans
> `jeeroborock-implementations-reference.md`.
>
> **Date d'analyse** : 2026-09-17. **Cible de test** : Roborock Qrevo Curv (`roborock.vacuum.a135`).
> **Rien ici n'est issu d'une doc officielle Roborock** (elle n'existe pas) : tout provient de la lecture
> du code de `python-roborock` 7.8.0 et d'implémentations de référence — cf. § Sources.

---

## D1 — Démon Python obligatoire, appuyé sur `python-roborock`

**Décision** : `hasOwnDeamon: true`, `hasDependency: true`. Le plugin embarque un démon Python
(`resources/demond/` renommé) qui dépend de la librairie **`python-roborock`** (PyPI).

**Pourquoi** (et non « REST + polling cron 100 % PHP », pourtant le défaut du squelette) :

1. Le pilotage d'un robot V1 **ne passe pas par du REST**. Les commandes (`app_start`, `get_status`,
   `app_segment_clean`…) sont des RPC encapsulées dans un **protocole binaire propriétaire** transporté
   par **MQTT** (chiffrement dérivé du `local_key` du robot, framing versionné, séquence/nonce, CRC).
   Réimplémenter ce protocole en PHP = plusieurs milliers de lignes à maintenir contre une cible mouvante.
2. Le cloud pousse des **mises à jour non sollicitées** (`RoborockDataProtocol` : `STATE=121`,
   `BATTERY=122`, `ERROR_CODE=120`, `FAN_POWER=123`…) sur la souscription MQTT : c'est un canal
   **persistant**, incompatible avec un cycle PHP cron sans démon.
3. `python-roborock` est la lib utilisée par l'intégration **Home Assistant** officielle : elle suit les
   changements du cloud Roborock (le protocole évolue **sans préavis**). S'y adosser transfère une grande
   partie du coût de maintenance.

**Conséquence de charge** : le canal MQTT reste ouvert en permanence (un seul socket pour tout le compte,
partagé par les robots) ; le démon doit donc tourner en continu, pas être lancé à la demande.

---

## D2 — Tout le contrat Roborock vit côté Python, aucun appel Roborock en PHP

**Décision** : le PHP **ne parle jamais** au cloud Roborock. Il ne parle qu'au démon.

**Pourquoi** : la partie HTTPS (login, `homedata`, scènes) serait techniquement faisable en PHP (cURL +
HMAC-SHA256 « Hawk »), mais cela dupliquerait un contrat non documenté et **désynchroniserait** le plugin
de la lib à chaque changement d'endpoint (ex. : la lib est passée de `code_login` v1 à
`code_login_v4` avec signature `x-mercy-ks`/`x-mercy-k`). Un seul point de vérité = une seule mise à jour
à faire quand Roborock casse quelque chose.

**Conséquence** : le démon doit être **lançable et utile sans identifiants valides** — c'est lui qui porte
le formulaire de login (voir D3/D4). Un démon « non authentifié » est un état normal, pas une erreur.

---

## D3 — Pont PHP ↔ démon : deux canaux (synchrone montant, asynchrone descendant)

Le squelette fournit `jeedom_socket` (PHP → démon, **fire-and-forget**) et `jeedom_com` (démon → Jeedom,
POST HTTP vers un callback). Le fire-and-forget **ne suffit pas** : plusieurs opérations exigent une
**réponse immédiate** dans la page de configuration ou dans un scénario :

| Opération | Besoin de réponse |
|---|---|
| Demander un code e-mail / valider le code | oui (message d'erreur exact : code invalide, trop de demandes…) |
| Tester la connexion | oui |
| Découvrir les équipements (`homedata`) | oui (liste à afficher/créer) |
| Lister les routines (« usages ») | oui |
| Exécuter une commande / une routine | souhaitable (accusé + erreur robot) |

**Décision** :
- **Jeedom → démon (synchrone)** : le démon expose un **serveur HTTP local** sur `127.0.0.1:<port>`
  (aiohttp, **déjà** dépendance transitive de `python-roborock` — aucune dépendance supplémentaire),
  protégé par l'**apikey** du plugin. Le PHP appelle en cURL avec un timeout court (≈ 10 s, plus long
  pour la découverte). Réponse JSON `{success, data|error}`.
- **Démon → Jeedom (asynchrone)** : `jeedom_com` POST vers le callback `core/php/jee<Id>.php`, pour les
  **push** (états dps MQTT, passage online/offline, fin de tâche) et les événements longs.

**Alternatives écartées** :
- *Script Python « one-shot » appelé en `exec()` par PHP pour les opérations synchrones* : casse la
  continuité de l'instance `RoborockApiClient` entre `request_code` et `code_login`. Le header
  `header_clientid` est `base64(md5(email + device_identifier))` où `device_identifier` est un
  `secrets.token_urlsafe(16)` **régénéré à chaque instanciation** (`web_api.py`,
  `RoborockApiClient.__init__` / `_get_header_client_id`). Deux processus = deux clientid pour les deux
  moitiés du même login. **À confirmer** : le serveur Roborock tolère-t-il un clientid différent entre
  l'envoi du code et sa validation ? Home Assistant conserve l'instance en mémoire entre les deux étapes
  (`config_flow.py`), ce qui suggère que non — on s'aligne par prudence.
- *Tout en PHP* : cf. D1/D2.
- *`jeedom_socket` seul* : pas de retour, donc pas de message d'erreur utilisable dans la page de config.

**Garde-fous** : bind strict sur `127.0.0.1` (jamais `0.0.0.0`), apikey vérifiée sur **chaque** requête,
aucun secret dans les réponses (ni `local_key`, ni `rriot`, ni token), et `.htaccess` conservés.

---

## D4 — Authentification : code e-mail d'abord, `UserData` persisté chiffré

**Flux nominal** (celui de Home Assistant, cf. `jeeroborock-cloud-api.md` § 2) :
1. l'utilisateur saisit son **e-mail** dans la config du plugin ;
2. bouton « Envoyer un code » → démon `request_code_v4()` → Roborock envoie un code par e-mail ;
3. l'utilisateur saisit le code → démon `code_login_v4(code)` → renvoie un objet **`UserData`**
   (contient `token`, `rruid`, `region`, et surtout `rriot` : `u`, `s`, `h`, `k` + endpoints `r.a` API et
   `r.m` broker MQTT) ;
4. le PHP persiste `UserData` (JSON) **et** `base_url` en configuration plugin **chiffrée**, puis
   (re)démarre le démon.

**Le mot de passe est un chemin secondaire, pas le chemin principal.** `pass_login()` existe encore
(`POST /api/v1/login`, mot de passe en clair dans les paramètres) mais :
- de nombreux comptes Roborock **n'ont pas de mot de passe** (création par code) ;
- le endpoint v1 renvoie fréquemment `2031 need two step validate` ou `9002 request too frequency` ;
- `pass_login_v3()` (le flux moderne, mot de passe chiffré) est **explicitement `NotImplementedError`**
  dans la lib ;
- Home Assistant ne propose **que** le code e-mail.

→ **Décision** : proposer le mot de passe en option « si votre compte en a un », mais construire tout le
MVP sur le code e-mail. Le champ mot de passe reste dans `$_encryptConfigKey`.

**Stockage** :

| Donnée | Où | Chiffrement |
|---|---|---|
| `email` | config plugin | non |
| `password` (optionnel) | config plugin | **oui** (`$_encryptConfigKey`) |
| `userData` (JSON complet, dont `rriot`) | config plugin | **oui** (`$_encryptConfigKey`) |
| `baseUrl` (ex. `https://euiot.roborock.com`) | config plugin | non |
| `homedata` / capacités / cache carte | `cache::set` (TTL) ou fichier du démon | volatile |
| `local_key` d'un robot | **jamais côté PHP** — le démon le tient de `homedata` | — |

**Pourquoi `UserData` en config chiffrée et pas en `cache`** : un cache purgé = **re-login par code
e-mail**, donc une action manuelle de l'utilisateur. Ce n'est pas un jeton court rafraîchissable en
silence. **À confirmer** : durée de vie réelle de `rriot` (non documentée ; HA ne la rafraîchit jamais et
attend l'échec `RoborockInvalidCredentials` pour déclencher une ré-authentification).

**Ré-authentification** : sur `RoborockInvalidCredentials` (ou un `unauthorized_hook` MQTT), le plugin
passe en état « ré-authentification requise » (commande info + message en config) et **cesse** de
retenter. Interdit : boucler sur le login — les quotas sont durs (cf. D6).

---

## D5 — Un eqLogic = un robot ; `logicalId` = `duid`

Le `duid` est l'identifiant stable du robot dans `homedata` (`HomeDataDevice.duid`), utilisé partout
(topic MQTT, endpoints `/user/scene/device/<duid>`, `/user/devices/<duid>/jobs`). Il ne change pas quand
l'utilisateur renomme le robot dans l'app.

Pas d'eqLogic « compte » au MVP : l'état du lien cloud est exposé **sur chaque robot** (commandes info
`connecte`, `en_ligne`, `derniere_maj`). Détail complet dans `jeeroborock-modele-equipement.md`.

---

## D6 — Quotas, cadence et mode dégradé

`python-roborock` embarque ses **propres limiteurs** (`web_api.py`, classe `RoborockApiClient`) — ils
lèvent `RoborockRateLimit` **avant** même d'appeler Roborock :

| Groupe | Limites |
|---|---|
| Login (`request_code*`, `pass_login`) | 1/s, 3/min, 10/h, **20/jour** |
| `get_home_data*` | 1/s, 3/min, **5/h**, **40/jour** |

**Conséquences dures** :
- la **découverte** (`homedata`) n'est PAS une opération de rafraîchissement : ≤ 5/h. Elle est appelée à
  la demande (bouton « Synchroniser ») et au démarrage du démon, avec **cache** (`prefer_cache=True`
  côté lib ; la lib réutilise le cache si l'appel échoue) ;
- l'état courant ne se lit **jamais** via `homedata` mais via le canal robot (`get_status` / push dps) ;
- un bouton « Tester » ne doit pas enchaîner login + homedata sans garde-fou.

**Cadence de rafraîchissement retenue** (alignée sur Home Assistant, `const.py` de l'intégration) :
robot en nettoyage **30 s**, robot au repos **60 s** — le push dps comble l'intervalle. Jeedom n'ayant
pas de cron < 1 minute, la boucle de polling vit **dans le démon** (asyncio), pas dans `cron()`. Le cron
PHP sert au **chien de garde** : démon vivant ? fraîcheur des données ? ré-auth nécessaire ?

**Mode dégradé** : robot hors ligne / MQTT coupé → les commandes info conservent leur dernière valeur,
une info `connecte`/`derniere_maj` bascule, les commandes action remontent une erreur explicite plutôt
que d'échouer en silence.

---

## D7 — Dépendances : `packages.json`, venv, version de Python

**`packages.json`** — une seule entrée, les dépendances transitives étant résolues par pip :

```json
{ "pip3": { "python-roborock": { "version": "7.8.0" } } }
```

Rappels de format (cf. `CLAUDE.md`, vérifiés ici dans `system.class.php` du core) : la version va dans la
**valeur** ; **jamais** de `<`/`>` (le core fait `$package .= $version` non quoté → redirection shell) ;
ne **pas** définir `dependancy_info()`.

Sémantique exacte du contrôle (core `system::checkAndInstall`) : `version_compare($installée,
$requise) < 0` → à installer. Donc la version déclarée est un **minimum**, alors que la commande
d'installation épingle (`pip install --force-reinstall --upgrade python-roborock==7.8.0`).

**Risque de dérive de version (élevé)** : `python-roborock` publie à un rythme soutenu et casse son API
entre majeures (5.x → 6.x → 7.x en deux mois : 5.29.0 le 2026-07-12, 6.0.0 le 2026-07-27, 7.1.1 le
2026-08-22, 7.8.0 le 2026-09-13 — source PyPI). Comme on **ne peut pas** plafonner dans `packages.json`,
le démon doit **logger la version détectée au démarrage** et **avertir explicitement** si la majeure
dépasse celle testée. L'API `roborock.devices` est d'ailleurs annoncée comme **expérimentale et sujette à
changement sans préavis** (docstring de `roborock/devices/device.py`).

**Interpréteur Python** : sur Debian ≥ 12, le core installe les paquets pip dans un **venv par plugin**
(`plugins/<id>/resources/python_venv`) et le démon doit être lancé avec
`system::getCmdPython3('<id>')` → `.../resources/python_venv/bin/python3` (source : `system.class.php`,
`getPython3VenvDir` / `getCmdPython3` / `splitpackageByPlugin`). Ne pas coder `python3` en dur.
`resources/python_venv/` doit être **ignoré par git**.

**Contrainte de version Python (bloquante)** : `python-roborock` 7.x exige **Python ≥ 3.11**
(`requires_python: <4,>=3.11`, et déjà >= 3.11 sur toute la série 5.x/6.x). Debian 11 fournit 3.9 →
**incompatible**. Donc `info.json` : `"os": {"min": 12, ...}` (Debian 12 = Python 3.11, Debian 13 = 3.13).
**À confirmer** : borne `max` selon la matrice de support Jeedom du moment.

**Dépendances transitives** (PyPI, python-roborock 7.8.0) : `aiohttp`, `aiomqtt` (→ `paho-mqtt ≥ 2.1`),
`construct`, `protobuf ≥ 6.31.1`, `pycryptodome ~3.18`, `pyrate-limiter ≥ 4`,
`vacuum-map-parser-roborock` (→ `vacuum-map-parser-base`, **`Pillow`**).
→ Pas de `numpy`. **Pillow / pycryptodome / protobuf** sont des paquets compilés : wheels manylinux
disponibles en `aarch64`, mais une installation sur ARM 32 bits peut compiler et durer longtemps.
Prévoir `maxDependancyInstallTime` généreux (**≈ 15 min**) — au lieu de `0` dans le squelette.

---

## D8 — Manifeste `info.json`

| Champ | Valeur proposée | Justification |
|---|---|---|
| `id` / `name` | `jeeroborock` / `JeeRoborock` | demande utilisateur |
| `category` | **`devicecommunication`** | libellé officiel « Objets Connectés » (doc `structure_info_json`) ; `home automation protocol` = « Passerelle domotique » et `automation protocol` = « Protocole domotique » décrivent des passerelles/protocoles, pas un appareil cloud |
| `hasOwnDeamon` | `true` | D1 |
| `hasDependency` | `true` | D7 |
| `maxDependancyInstallTime` | `15` | D7 |
| `os.min` | `12` | Python ≥ 3.11 (D7) |
| `description` | multilingue **inline**, ≥ 80 caractères par langue | règle market (cf. `CLAUDE.md` i18n) |

---

## D9 — « Cloud uniquement » : ce que la lib permet réellement

L'utilisateur a demandé **cloud only** (pas de transport LAN). **Attention** : en 7.8.0, `python-roborock`
**tente systématiquement** une connexion **TCP locale** au robot lors de la souscription V1, et ne
l'expose pas comme une option désactivable (`roborock/devices/rpc/v1_channel.py`, `subscribe()` :
`_local_connect(prefer_cache=True)` puis tâche de fond `_background_reconnect`, et souscription MQTT
maintenue en parallèle pour les push dps). Aucun paramètre `create_device_manager(...)` ne désactive le
local (vérifié par recherche sur l'arbre du wheel 7.8.0).

**Décision** : ne pas promettre « cloud only » dans le code. Le canal local est un **accélérateur
transparent** quand le robot est joignable sur le même LAN que Jeedom (l'IP vient d'un appel cloud
`get_network_info`), et il retombe seul sur MQTT sinon. Aucune UC MVP ne dépend du local ; un domaine
post-MVP isolé traitera son diagnostic/désactivation. **À confirmer** sur le Qrevo Curv : est-il joignable
en TCP local, et faut-il subir 15 s de tentative au démarrage (`START_ATTEMPT_TIMEOUT`) ?

---

## D10 — Autoload et briques PHP

Rappel de la règle critique `CLAUDE.md` (1 classe ↔ 1 fichier `<Classe>.class.php`) appliquée ici :
- `jeeroborock` / `jeeroborockCmd` dans `core/class/jeeroborock.class.php` ;
- **une brique unique** pour parler au démon : `jeeroborockDaemon` (canal HTTP local + apikey + timeouts +
  traduction des erreurs) — **fichier dédié** `core/class/jeeroborockDaemon.class.php` car elle sera
  appelée depuis des points d'entrée externes (`core/ajax/*.ajax.php`, callback démon, hooks cron) ;
- exception dédiée `jeeroborockException` (fichier dédié) pour distinguer « démon injoignable » /
  « non authentifié » / « erreur Roborock » / « robot hors ligne ».

Aucun appel cURL/socket vers le démon en dehors de `jeeroborockDaemon`.

---

## Risques majeurs (à porter dans la doc et les specs)

1. **API non documentée et mouvante** : Roborock peut casser le login ou le protocole du jour au
   lendemain (cf. l'épisode `code_login_v4` cassé sur HA en mars 2026). Mitigation : dépendre de la lib,
   afficher clairement l'état d'authentification, ne pas boucler sur les erreurs.
2. **Ré-authentification non automatisable** : elle exige un code reçu par e-mail → action humaine.
3. **Quotas** : 20 logins/jour, 40 `homedata`/jour pour tout le compte, partagés avec l'app mobile et
   toute autre intégration (HA, ioBroker) du même compte.
4. **Version de la lib** non plafonnable dans `packages.json` (D7).
5. **Un seul modèle testable** (`a135`) : toute capacité dépendante du modèle doit être **découverte**
   (device features / schéma produit), jamais codée en dur.

---

## Sources

- `python-roborock` **7.8.0** (PyPI, wheel `python_roborock-7.8.0-py3-none-any.whl`, publié 2026-09-13) —
  lecture du code : `roborock/web_api.py`, `roborock/devices/device_manager.py`, `roborock/devices/device.py`,
  `roborock/devices/rpc/v1_channel.py`, `roborock/devices/traits/v1/*`, `roborock/protocol.py`,
  `roborock/roborock_message.py`, `roborock/exceptions.py`, `roborock/const.py`, `roborock/cli.py`,
  `examples/example.py`. Dépôt : https://github.com/Python-roborock/python-roborock
- Métadonnées PyPI (versions, `requires_python`, `requires_dist`) : https://pypi.org/pypi/python-roborock/json
- Intégration **Home Assistant** `roborock` (branche `dev`, consultée le 2026-09-17) :
  `homeassistant/components/roborock/config_flow.py`, `__init__.py`, `coordinator.py`, `const.py`.
- Adaptateur **ioBroker.roborock** (MIT, actif) : https://github.com/copystring/ioBroker.roborock
- Core Jeedom `system.class.php` (branche `alpha`) : gestion `packages.json`, venv pip par plugin.
- Doc Jeedom `structure_info_json` : https://doc.jeedom.com/fr_FR/dev/structure_info_json
