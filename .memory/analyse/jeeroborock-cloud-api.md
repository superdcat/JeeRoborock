# JeeRoborock — Contrat du cloud Roborock (HTTPS) : auth, homedata, routines

> **Statut** : Roborock **ne publie aucune documentation d'API**. Tout ce fichier est établi par lecture
> du code de `python-roborock` **7.8.0** (fichier `roborock/web_api.py` sauf mention contraire) et
> corroboré par l'intégration Home Assistant. Les endpoints peuvent changer sans préavis.
> **Date** : 2026-09-17 — **§ 8 complété et rendu exhaustif le 2026-09-18** (cycle UC03).
>
> Le plugin **n'appelle pas ces endpoints directement** (cf. `jeeroborock-architecture.md` D2) : il passe
> par la lib, dans le démon. Ce fichier sert à **comprendre le coût, les limites et les échecs possibles**
> de chaque opération exposée à l'utilisateur.

---

## 1. Découverte du serveur régional

Roborock est régionalisé. Le point d'entrée fait un balayage :

```
POST <base>/api/v1/getUrlByEmail?email=<email>&needtwostepauth=false
```
sur la liste `BASE_URLS = [usiot, euiot, cniot, ruiot].roborock.com`, jusqu'à obtenir
`data.url` (base régionale), `data.country`, `data.countrycode`.

Codes d'erreur vus : `2003` e-mail mal formé, `1001` paramètres manquants. Si aucune base ne répond →
`RoborockNoResponseFromBaseURL`.

→ **Conserver `base_url`** après le premier login : cela évite ce balayage à chaque démarrage
(`UserParams.base_url`, exactement ce que fait Home Assistant en le stockant dans l'entrée de config).

En-tête présent sur tous les appels d'authentification : `header_clientid =
base64(md5(email + device_identifier))`, `device_identifier` étant un aléa **par instance** de
`RoborockApiClient` (cf. `jeeroborock-architecture.md` D3).

## 2. Authentification

### 2.1 Flux nominal — code e-mail (recommandé)

| Étape | Appel | Notes |
|---|---|---|
| 1 | `request_code_v4()` → `POST /api/v4/email/code/send` (form : `email`, `type=login`, `platform=`) | en-têtes `header_clientid`, `header_clientlang: en`. Repli automatique sur `request_code()` (`POST /api/v1/sendEmailCode`, `type=auth`) si pays/indicatif inconnus. Code `3030` → la lib retire la base courante et retente sur la suivante |
| 2 | `code_login_v4(code, country, country_code)` → `POST /api/v4/auth/email/login/code` | signe la requête : tirage `x-mercy-ks` (16 car. aléatoires) puis `x-mercy-k` obtenu via `POST /api/v3/key/sign?s=<ks>`. En-têtes simulant l'app iOS (`header_appversion: 4.54.02`, `header_phonesystem: iOS`…). Form : `country`, `countryCode`, `email`, `code`, `majorVersion: 14`, `minorVersion: 0` (**version des CGU**, en dur dans la lib) |

Erreurs typées : `2018` code invalide (`RoborockInvalidCode`), `3009` CGU non acceptées
(`RoborockNoUserAgreement`), `3006` CGU à réaccepter / compte Mi Home (`RoborockInvalidUserAgreement`),
`3039` compte inexistant, `2008` compte inexistant (envoi de code), `9002` trop de demandes de code
(`RoborockTooFrequentCodeRequests`).

#### ⚠️ Quatre pièges du flux de login, vérifiés dans `web_api.py` 7.8.0 (UC04, 2026-09-18)

1. **Le limiteur de la lib est ASYMÉTRIQUE.** `request_code_v4()` prend un jeton
   (`_login_limiter.try_acquire_async("login", blocking=True, timeout=1)` → `RoborockRateLimit` si aucun
   jeton en 1 s), mais **`code_login_v4()` n'en prend AUCUN** — pas de `try_acquire_async` dans la
   méthode, contrairement à `request_code*`/`pass_login`. Seul l'**envoi** du code est plafonné côté
   client ; une rafale de validations ne consomme aucun jeton local mais peut déclencher un refus serveur.
2. **Le limiteur est un attribut de CLASSE**, donc un compteur **par processus** : redémarrer le démon
   remet le compteur local à zéro alors que le quota serveur (3/min, 10/h, **20/jour**) court toujours.
   Rates : `1/s, 3/min, 10/h, 20/jour` (l. 52-66).
3. **Une demande peut consommer DEUX jetons.** Sur code `3030`, `request_code_v4` retire la base courante,
   invalide le cache régional et **se rappelle récursivement** (l. 263-266) — en reprenant un jeton.
4. **Aucun timeout par requête.** `PreparedRequest.request()` (l. 796-819) ne passe **jamais** de
   `timeout=` à `session.request`. Une base régionale en trou noir consomme donc tout le budget de
   l'appelant dans le balayage `getUrlByEmail` (jusqu'à 4 POST séquentiels), et peut épuiser le budget
   **sans qu'aucun code n'ait été envoyé**. Aucun réglage de la lib ne corrige cela : seul un budget
   global côté appelant borne l'attente. (`session=None` ⇒ une `ClientSession` est créée **et fermée**
   par requête, y compris sur annulation — donc pas de fuite de session.)

⚠️ **La lib n'expose pas `__version__`** en 7.8.0 (`roborock/__init__.py` ; la version est statique dans
`pyproject.toml`). Tout garde-fou de dérive de version doit passer par
`importlib.metadata.version("python-roborock")`.

### 2.2 Flux mot de passe (secondaire, fragile)

`pass_login(password)` → `POST /api/v1/login?username=&password=&needtwostepauth=false`.
`pass_login_v3()` (flux moderne, mot de passe chiffré) → **`NotImplementedError`** dans la lib.
Symptômes remontés par les utilisateurs : `2031 need two step validate`, `9002 request too frequency`.
Home Assistant n'expose **que** le code e-mail. → à proposer en option, jamais comme chemin principal.

### 2.3 `UserData` — ce qu'on persiste

```
UserData { uid, tokentype, token, rruid, region, countrycode, country, nickname,
           rriot: RRiot { u, s, h, k, r: Reference { r, a, m, l } } }
```
- `token` : bearer pour les endpoints `/api/v1/*` (ex. `getHomeDetail`).
- `rriot.u` / `s` / `h` / `k` : identité + secrets de signature **Hawk** (§ 3).
- `rriot.r.a` : **base des endpoints IoT** (homedata, rooms, scènes, jobs, OTA).
- `rriot.r.m` : **URL du broker MQTT** (cf. `jeeroborock-mqtt-protocole.md`).

⚠️ `UserData` est un **secret complet** : il donne accès au compte et aux robots. Chiffré au repos, jamais
loggé, jamais renvoyé dans une réponse AJAX.

**À confirmer** : durée de validité. Aucun mécanisme de refresh n'existe dans la lib ni dans HA ; on
détecte l'expiration par `RoborockInvalidCredentials` (code `2010` sur `getHomeDetail`) ou par un refus
MQTT (`unauthorized_hook`).

#### ⚠️ Sérialisation : tolérante au point d'être dangereuse (vérifié en UC04, 2026-09-18)

`containers.py` l. 226-237 — `rriot` est **requis** (sans défaut) ; tous les autres champs de `UserData`
sont optionnels.

- **`as_dict()`** (l. 164-173) **camélise** les clés (`_camelize`) **et supprime toute valeur `None`**.
  `tuya_device_state` → `tuyaDeviceState` ; `tokentype`/`rruid`/`countrycode`/`avatarurl` restent
  inchangés (pas d'underscore à convertir).
- **`from_dict()`** (l. 105-129) `_decamelize` chaque clé et **ignore SILENCIEUSEMENT toute clé inconnue**
  (simple log `debug`), puis `cls(**result)`. Un dict sans `rriot` lève `TypeError`.
- L'aller-retour `from_dict(as_dict())` est **fidèle** (vérifié clé par clé).

**Le risque** : une montée de version de la lib qui **renommerait** un champ de `UserData` produirait une
session **tronquée sans aucune erreur**. D'où la règle du plugin : après tout décodage, **contrôler
explicitement** que `token`, `rriot` et `rriot.r` sont non vides. À revérifier à chaque changement de
version.

**Le corollaire côté PHP** : ne **jamais** persister ce dict en **JSON nu**. `config::byKey` applique
`is_json($v, $v)` et relit donc toute valeur JSON-objet **en tableau PHP**
(cf. `jeedom-config-plugin-defauts.md` § 3) ; au ré-encodage, un objet vide — `Reference` dont tous les
champs sont `None`, supprimés par `as_dict()` — devient `array()` puis un **tableau** JSON, et
`Reference.from_dict([])` retourne `None` : **`rriot.r` est perdu silencieusement**. Le plugin stocke donc
le `UserData` en **`base64(JSON compact)` opaque**, ce qui garantit la fidélité de l'aller-retour *et*
évite que le PHP manipule une structure navigable du secret.

## 3. Signature « Hawk » des endpoints IoT

Tous les appels sur `rriot.r.a` sont signés (fonction `_get_hawk_authentication`) :

```
prestr = ts + "\n" + nonce + "\n" + method + "\n" + path + "\n"
       + md5hex(url) + "\n" + params_str + "\n" + payload_str
mac    = base64( hmac_sha256( key = rriot.h, msg = prestr ) )
Authorization: Hawk id="<rriot.u>", s="<rriot.s>", ts="…", nonce="…", mac="<mac>"
```
Conséquence pratique notée dans la lib : pour les écritures avec corps, le **JSON compact exact** signé
doit être envoyé tel quel (`data=`, pas `json=`), sinon la MAC ne correspond plus.

## 4. Home data (inventaire du compte)

| Appel | Endpoint |
|---|---|
| `_get_home_id()` | `GET <base>/api/v1/getHomeDetail` (header `Authorization: <token>`) → `data.rrHomeId` |
| `get_home_data_v3()` (utilisé par `UserWebApiClient`) | `GET <rriot.r.a>/v3/user/homes/<homeId>` |
| variantes | `/user/homes/<id>` (v1), `/v2/user/homes/<id>` (inclut les non-robots) |

Structure retournée (`roborock/data/containers.py`) :

```
HomeData { id, name, products[], devices[], received_devices[] (partagés), rooms[] }
HomeDataDevice { duid, name, local_key, product_id, fv, pv, online, sn, feature_set,
                 new_feature_set, device_status, silent_ota_switch, share, room_id, … }
HomeDataProduct { id, name, model ("roborock.vacuum.a135"), category, capability, schema[] }
HomeDataRoom { id, name }
```
Points clés :
- **`duid`** = clé stable d'un robot (→ `logicalId` Jeedom).
- **`pv`** = version de protocole : `"1.0"` (V1, aspirateurs classiques dont a135), `"A01"`
  (laveuses/aspirateurs eau, Dyad/Zeo), `"B01"` (Q7/Q10 récents). Le type d'accès en dépend entièrement.
- **`local_key`** = secret de chiffrement des trames du robot → **reste dans le démon**.
- `product.schema[]` liste les « dps » supportés : base de la **création conditionnelle** des commandes.
- `received_devices` = robots **partagés** par un autre compte (droits réduits, pièces via un endpoint
  distinct `/user/deviceshare/query/<duid>/rooms`).

**Quota** (limiteur interne à la lib) : **1/s, 3/min, 5/h, 40/jour** → `RoborockRateLimit`. Le home data
est une opération de **découverte**, pas de rafraîchissement.

## 5. Routines / scènes (« usages » programmés dans l'app) — exigence MVP

C'est du **pur HTTPS signé**, indépendant de MQTT : une routine s'exécute même si le canal robot est
indisponible (le cloud relaie vers le robot).

| Opération | Endpoint | Méthode lib |
|---|---|---|
| Lister les routines d'un robot | `GET <rriot.r.a>/user/scene/device/<duid>` | `get_scenes(user_data, device_id)` / `UserWebApiClient.get_routines(duid)` / trait `v1_properties.routines.get_routines()` |
| Exécuter une routine | `POST <rriot.r.a>/user/scene/<scene_id>/execute` | `execute_scene(user_data, scene_id)` / `execute_routine(scene_id)` |

Réponse : `{"success": true, "result": [ … ]}`. Modèle exposé par la lib :

```
HomeDataScene { id: int, name: str }
```

⚠️ **Le dataclass ne garde que `id` et `name`** : la réponse brute contient d'autres champs (le
`RoborockBase.from_dict` ignore et logge les clés inconnues). **À confirmer sur le compte réel** : y
a-t-il un `enabled`, un `param` (contenu de la routine : pièces, modes) exploitable pour un libellé plus
riche ? Si oui, il faudrait passer par la réponse brute plutôt que par le dataclass.

Remarques :
- l'exécution **n'exige pas** le `duid` (commentaire explicite du trait `routines.py` : « routines are
  per-device, but the API does not require the device ID to execute them ») ;
- aucun retour d'état : `execute` renvoie seulement `success`. Le suivi de l'exécution se fait via le
  statut du robot (`state` passant en nettoyage) ;
- pas de limiteur dédié côté lib sur ces appels → prévoir un garde-fou côté plugin (anti-rafale).
- **À confirmer** : le rafraîchissement de la liste (fréquence raisonnable ? coût ?) et le comportement
  si une routine a été supprimée dans l'app alors qu'une commande Jeedom existe encore (→ prévoir une
  resynchronisation et un message d'erreur propre, pas un crash).

## 6. Programmations (planifications de l'app) — post-MVP

| Opération | Endpoint | Méthode |
|---|---|---|
| Lister | `GET <rriot.r.a>/user/devices/<duid>/jobs` | `get_schedules(user_data, duid)` |
| Créer | `POST <rriot.r.a>/user/devices/<duid>/jobs` | `create_job(...)` — documenté dans la lib comme ciblant les **B01** |

```
HomeDataSchedule { id: int, cron: str, repeated: bool, enabled: bool, param: dict|None }
```
**À confirmer** : disponibilité et forme sur un V1 (`a135`) — la lib ne le garantit que pour B01 côté
création. En lecture, à tester.

## 7. Autres endpoints utiles (post-MVP)

| Sujet | Endpoint | Méthode |
|---|---|---|
| Pièces du domicile | `GET <rriot.r.a>/user/homes/<homeId>/rooms` | `get_rooms` |
| Pièces d'un robot partagé | `GET <rriot.r.a>/user/deviceshare/query/<duid>/rooms` | `get_shared_device_rooms` |
| Firmware / OTA | `GET <rriot.r.a>/ota/firmware/<duid>/updatev2?lang=en` | `get_firmware_info` → `FirmwareInfo{version, current_version, updatable, desc, release_time, force_update}` |
| Lancer la MAJ firmware | `POST <rriot.r.a>/ota/device/<duid>/upgrade` | `start_firmware_update` — **irréversible**, à traiter comme action sensible (confirmation) |
| MAJ silencieuse on/off | `PUT <rriot.r.a>/user/devices/<duid>` (`silentOtaSwitch`) | `set_silent_ota` |
| Catalogue produits & schémas | `GET <base>/api/v4/product` | `get_products` |

## 8. Exceptions de la lib → codes stables → messages utilisateur

> ⚠️ **Source de vérité du mapping** : `.memory/specs/MVP/03-pont-php-demon-tech.md`, section
> « Table exhaustive — exception → code stable → message français ». Elle est **figée en UC03** et
> consommée par UC04→09. Implémentations : `resources/demond/erreurs.py` (`TABLE_CODES`, exception → code)
> et `jeeroborockDaemon::tableMessages()` (code → littérale `__()`). En cas de divergence avec le tableau
> ci-dessous, **la spec technique UC03 fait foi**.

`roborock/exceptions.py` de **`python-roborock` 7.8.0** — version **exacte** épinglée par
`plugin_info/packages.json` — définit **23 classes**, toutes dérivées de `RoborockException`. La liste
ci-dessous est **exhaustive** (complétée le **2026-09-18** en UC03 ; la rédaction précédente de ce § n'en
citait que 18, explicitement à titre d'exemple). ⚠️ **À revérifier à chaque montée de version majeure**
de la librairie : l'API des exceptions n'est pas stable entre majeures — c'est précisément la raison de
l'épinglage en version exacte.

### 8.1 Les 23 exceptions de la lib → code stable (erreurs d'**opération**, HTTP 200 `success:false`)

| Exception `python-roborock` 7.8.0 | Code stable | Message français |
|---|---|---|
| `RoborockInvalidCredentials` | `AUTH_EXPIRED` | Session Roborock expirée : une nouvelle authentification par code e-mail est nécessaire. |
| `RoborockInvalidCode` | `AUTH_CODE_INVALID` | Code de connexion invalide ou expiré. |
| `RoborockTooFrequentCodeRequests` | `AUTH_CODE_TOO_FREQUENT` | Trop de demandes de code de connexion : patientez quelques minutes avant de réessayer. |
| `RoborockInvalidEmail` | `AUTH_EMAIL_INVALID` | L'adresse e-mail du compte Roborock est invalide. |
| `RoborockAccountDoesNotExist` | `AUTH_ACCOUNT_UNKNOWN` | Aucun compte Roborock ne correspond à cette adresse e-mail. |
| `RoborockNoUserAgreement` | `AUTH_AGREEMENT_REQUIRED` | Les conditions d'utilisation Roborock n'ont pas été acceptées : ouvrez l'application mobile Roborock pour les accepter. |
| `RoborockInvalidUserAgreement` | `AUTH_AGREEMENT_OUTDATED` | Les conditions d'utilisation Roborock ont changé : ouvrez l'application mobile Roborock pour les accepter à nouveau. |
| `RoborockRateLimit` | `RATE_LIMIT` | Quota d'appels Roborock atteint : patientez avant de réessayer. Ce quota est partagé avec l'application mobile Roborock. |
| `RoborockTooManyRequest` | `RATE_LIMIT_REMOTE` | Le cloud Roborock a refusé la demande (trop de requêtes) : patientez avant de réessayer. |
| `RoborockNoResponseFromBaseURL` | `CLOUD_UNREACHABLE` | Le cloud Roborock est injoignable : vérifiez l'accès à Internet de Jeedom. |
| `RoborockUrlException` | `CLOUD_REGION_UNKNOWN` | Impossible de déterminer le serveur Roborock de ce compte. |
| `RoborockMissingParameters` | `CLOUD_BAD_REQUEST` | Le cloud Roborock a rejeté la demande (paramètres manquants). Consultez le log du démon. |
| `RoborockParsingException` | `PARSING_ERROR` | Réponse incompréhensible du cloud Roborock. Consultez le log du démon. |
| `RoborockConnectionException` | `CONNECTION_FAILED` | La connexion avec le cloud Roborock ou le robot a échoué. |
| `RoborockTimeout` | `ROBOROCK_TIMEOUT` | Le cloud Roborock ou le robot n'a pas répondu dans le délai imparti. |
| `RoborockBackoffException` | `RETRY_EXHAUSTED` | Plusieurs tentatives de communication ont échoué : réessayez plus tard. |
| `RoborockDeviceBusy` | `DEVICE_BUSY` | Le robot est occupé : il ne peut pas traiter cette demande maintenant. |
| `RoborockInvalidStatus` | `DEVICE_ACTION_REFUSED` | Le robot a refusé l'action dans son état actuel. |
| `VacuumError` | `DEVICE_ERROR` | Le robot signale une erreur : consultez son état dans l'application Roborock. |
| `CommandVacuumError` | `DEVICE_COMMAND_ERROR` | Le robot a signalé une erreur en exécutant la commande. |
| `RoborockUnsupportedFeature` | `UNSUPPORTED` | Cette fonction n'est pas disponible sur ce modèle de robot. |
| `UnknownMethodError` | `UNSUPPORTED_COMMAND` | Le robot ne reconnaît pas cette commande. |
| `RoborockException` *(classe de base, non spécialisée)* | `ROBOROCK_ERROR` | Erreur Roborock non identifiée. Consultez le log du démon. |

**Deux paires volontairement non fusionnées** : `RATE_LIMIT` (limiteur **interne à la lib**, déclenché
avant tout appel réseau — l'attente est prévisible) vs `RATE_LIMIT_REMOTE` (refus **du serveur** Roborock
— le compte est potentiellement déjà pénalisé, y compris dans l'application mobile) ; `DEVICE_ERROR`
(le robot est en défaut) vs `DEVICE_COMMAND_ERROR` (la commande précise a échoué).

`VacuumError` / `CommandVacuumError` retombent **délibérément** sur un message générique renvoyant vers
l'application mobile : le libellé précis d'une erreur robot dépend du modèle et du firmware, il n'a pas
à être figé en PHP.

### 8.2 États propres au démon (même famille de codes, sans exception de la lib)

| Origine | Code stable | Message français |
|---|---|---|
| `asyncio.TimeoutError` (budget de l'opération épuisé) | `OPERATION_TIMEOUT` | L'opération n'a pas abouti dans le délai imparti. |
| état démon, émis dès UC05 | `NOT_AUTHENTICATED` | Le compte Roborock n'est pas lié : authentifiez-vous depuis la configuration du plugin. |
| état démon, émis dès UC07 | `DEVICE_UNKNOWN` | Robot inconnu du démon : relancez une synchronisation des équipements. |
| état démon | `DEVICE_OFFLINE` | Le robot est hors ligne : il ne répond pas au cloud Roborock. |
| `aiohttp.ClientError`, `OSError` | `CLOUD_UNREACHABLE` | *(idem § 8.1)* — ⚠️ **inatteignable en direct via `web_api`**, cf. ci-dessous |
| tout le reste | `INTERNAL_ERROR` | Erreur interne du démon. Consultez le log du démon. |

⚠️ **Correction du mapping annoncé en UC03** (constatée en UC04, 2026-09-18). `PreparedRequest.request()`
(`web_api.py` l. 815-816) fait :

```python
except (aiohttp.ClientError, TimeoutError, OSError) as err:
    raise RoborockException(f"Network error contacting {_url}: {err}") from err
```

Une panne réseau ne remonte donc **jamais** comme `aiohttp.ClientError`/`OSError` : elle arrive en
**`RoborockException` nue**, qui ne matche que la classe de base → `ROBOROCK_ERROR` (« Erreur Roborock non
identifiée ») au lieu de `CLOUD_UNREACHABLE`. La ligne du tableau ci-dessus est donc **inatteignable
telle quelle** pour tout appel passant par `web_api` ; « Jeedom n'a pas Internet » s'affichait comme une
erreur non identifiée.

**Parade retenue** (UC04, dans `erreurs.py`) : quand le parcours du MRO ne donne que `ROBOROCK_ERROR`
**et** que `exc.__cause__` existe, résoudre la **cause** ; si elle donne un code autre que le code par
défaut, l'utiliser. La fonction continue de ne jamais lever et de ne pas importer `roborock.exceptions`.
**Règle générale à retenir** : cette lib enveloppe ses erreurs de transport avec `raise … from err` — un
mapping par type d'exception doit **toujours** regarder `__cause__`.

### 8.3 Pour mémoire — codes du **canal** PHP↔démon (hors cloud Roborock)

Disjoints par construction des codes ci-dessus : ils ne décrivent pas un refus de Roborock mais une
défaillance du tuyau local. Justification et statuts HTTP : spec technique UC03.

| Code | Origine | Sens |
|---|---|---|
| `DAEMON_UNREACHABLE` | PHP (transport) | connexion refusée ou impossible — le démon ne répond pas |
| `DAEMON_TIMEOUT` | PHP (transport) | budget épuisé sans réponse |
| `DAEMON_INVALID_RESPONSE` | PHP (transport) | corps illisible / enveloppe non conforme |
| `UNAUTHORIZED` | démon, HTTP 401 | apikey du plugin refusée |
| `BAD_REQUEST` | démon, HTTP 400/413 | requête du plugin rejetée |
| `UNKNOWN_OPERATION` | démon, HTTP 404 | opération absente du registre |
| `INTERNAL_ERROR` | démon, HTTP 500 | exception non rattrapée |

### 8.4 Règles de mapping figées en UC03

- Mapping par **parcours du MRO sur le *nom* de classe**, sans importer `roborock.exceptions` : si une
  future version renomme ou supprime une exception, le démon ne casse pas à l'import — l'exception
  retombe sur `RoborockException`, toujours présente dans son MRO, donc sur `ROBOROCK_ERROR`.
- Le démon ne renvoie **jamais** `str(exception)` : `error.message` porte un nom de classe ou un
  identifiant fixe, et n'est **jamais affiché**. Le PHP ne doit détenir aucune chaîne anglaise affichable,
  et `str(exception)` d'une librairie peut contenir une URL signée.
- La table PHP est livrée **complète** dès UC03, codes émis plus tard compris : c'est une table de
  **données contractuelle**, pas du code mort.

## Sources

- `python-roborock` 7.8.0 : `roborock/web_api.py`, `roborock/data/containers.py`,
  `roborock/devices/traits/v1/routines.py`, `roborock/exceptions.py`.
- Home Assistant `dev` : `homeassistant/components/roborock/config_flow.py` (flux code e-mail + reauth),
  `__init__.py` (persistance `user_data`/`base_url`, `RoborockInvalidCredentials` → reauth).
- Dépôt de rétro-ingénierie cité par la lib : https://github.com/Python-roborock/Roborockmitmproxy
