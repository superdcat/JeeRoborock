# JeeRoborock — Contrat du cloud Roborock (HTTPS) : auth, homedata, routines

> **Statut** : Roborock **ne publie aucune documentation d'API**. Tout ce fichier est établi par lecture
> du code de `python-roborock` **7.8.0** (fichier `roborock/web_api.py` sauf mention contraire) et
> corroboré par l'intégration Home Assistant. Les endpoints peuvent changer sans préavis.
> **Date** : 2026-09-17.
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

## 8. Exceptions de la lib → messages utilisateur

`roborock/exceptions.py` : `RoborockInvalidEmail`, `RoborockAccountDoesNotExist`, `RoborockInvalidCode`,
`RoborockTooFrequentCodeRequests`, `RoborockNoUserAgreement`, `RoborockInvalidUserAgreement`,
`RoborockInvalidCredentials`, `RoborockRateLimit`, `RoborockTooManyRequest`,
`RoborockNoResponseFromBaseURL`, `RoborockMissingParameters`, `RoborockConnectionException`,
`RoborockTimeout`, `RoborockDeviceBusy`, `RoborockInvalidStatus` (action verrouillée),
`RoborockUnsupportedFeature`, `CommandVacuumError` / `VacuumError`, `RoborockParsingException`.

→ Le démon doit **mapper ces types en codes stables** (ex. `AUTH_CODE_INVALID`, `AUTH_EXPIRED`,
`RATE_LIMIT`, `DEVICE_OFFLINE`, `DEVICE_BUSY`, `UNSUPPORTED`) pour que le PHP traduise en français
(chaînes littérales `__()`) sans parser des messages anglais.

## Sources

- `python-roborock` 7.8.0 : `roborock/web_api.py`, `roborock/data/containers.py`,
  `roborock/devices/traits/v1/routines.py`, `roborock/exceptions.py`.
- Home Assistant `dev` : `homeassistant/components/roborock/config_flow.py` (flux code e-mail + reauth),
  `__init__.py` (persistance `user_data`/`base_url`, `RoborockInvalidCredentials` → reauth).
- Dépôt de rétro-ingénierie cité par la lib : https://github.com/Python-roborock/Roborockmitmproxy
