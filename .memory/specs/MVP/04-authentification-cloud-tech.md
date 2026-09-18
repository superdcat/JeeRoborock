# Spec technique — UC04 : Authentification au cloud Roborock

> **Spec fonctionnelle** : `04-authentification-cloud.md` · **Dépend de** : UC03 (pont PHP↔démon)
> **Statut** : plan validé, à implémenter

## Périmètre

Livrer le flux « code e-mail » de bout en bout : deux opérations démon (`demanderCode`, `validerCode`),
une troisième de remise en état au démarrage (`restaurerSession`), la persistance chiffrée du `UserData`,
et le bloc d'interface correspondant dans la page de configuration plugin.

**Aucune nouvelle route HTTP, aucune nouvelle classe PHP, aucune nouvelle dépendance, `canal.py`
intouché.** Le seul point d'extension utilisé est `canal.enregistrer()` / `jeeroborockDaemon::appeler()`,
conformément au contrat figé en UC03.

### Couverture des critères d'acceptation

| AC | Réalisé par |
|---|---|
| **AC1** — accusé « code envoyé » | Opération `demanderCode` → `RoborockApiClient.request_code_v4()` ; action AJAX `demanderCode` (budget 25 s) ; JS affiche « Code envoyé, vérifiez vos e-mails ». Succès = HTTP 200 du cloud sans exception typée — c'est le maximum vérifiable côté plugin, la réception réelle de l'e-mail se constate en recette (R-1) |
| **AC2** — état « compte lié », sans redemander l'e-mail | `validerCode` → `UserData` → `jeeroborock::enregistrerSession()` → `config::save('userData'\|'baseUrl')` ; badge `#jeeroborockEtatCompte` rendu **serveur** depuis `jeeroborock::estCompteLie()`, réécrit par le JS après succès. L'e-mail n'est **jamais** redemandé : les deux actions AJAX le lisent dans `config::byKey('email','jeeroborock')` |
| **AC3** — « code invalide » distinct, redemande possible, sans rechargement | `AUTH_CODE_INVALID` (déjà dans `tableMessages()`, UC03) → `ajax::error($e->getMessage())` → `donnees.result` inséré par `.text()`. Champ code et bouton « Envoyer un code » restent en place et sont réactivés. Chemin complet au § *Server vs Client* |
| **AC4** — message de quota, sans relance automatique | Deux sources déjà mappées en UC03 : limiteur interne à la lib (1/s, 3/min, 10/h, 20/j) → `RoborockRateLimit` → `RATE_LIMIT` ; refus serveur `9002` → `RoborockTooFrequentCodeRequests` → `AUTH_CODE_TOO_FREQUENT`. **Aucune retentative nulle part** : pas de boucle, pas de cron, pas de `retry` jQuery, verrou de bouton pendant l'appel |
| **AC5** — aucune donnée sensible en config/DOM/logs | 7 mesures structurelles, § *Garantie AC5* |
| **AC6** — information d'accès chiffrée | `userData` est **déjà** dans `jeeroborock::$_encryptConfigKey` (UC01, `jeeroborock.class.php:41`) ; aucun champ `.configKey` pour cette clé ; valeur stockée **base64 opaque**. Vérifiable en base (R-2) |

Le point « À confirmer » de la spec fonctionnelle (CGU à réaccepter) **reste ouvert** : les deux messages
existent déjà (`AUTH_AGREEMENT_REQUIRED` 3009, `AUTH_AGREEMENT_OUTDATED` 3006), mais aucun compte de test
ne présente ce cas (R-11).

## Architecture

### Fichiers

| Fichier | État | Ce qui y entre | Indentation / EOL |
|---|---|---|---|
| `resources/demond/authentification.py` | **créé** | 3 coroutines d'opération + helpers de sérialisation/validation + `enregistrer_operations()` | **4 espaces, LF** |
| `resources/demond/jeeroborockd.py` | modifié | 2 clés dans `contexte` (`auth`, `session`) ; `import authentification` ; appel `enregistrer_operations()` avant `construire_application()` | 4 espaces, LF |
| `resources/demond/erreurs.py` | modifié | `code_pour_exception()` consulte `__cause__` quand le MRO ne donne que `ROBOROCK_ERROR` | 4 espaces, LF |
| `resources/demond/canal.py` | **non modifié** | registre, middlewares et `/sante` restent tels quels (non-régression UC03) | — |
| `core/class/jeeroborock.class.php` | modifié | 6 méthodes de session, réécriture de `preConfig_email`, 1 ligne dans `deamon_start()` | 2 espaces, CRLF |
| `core/class/jeeroborockDaemon.class.php` | modifié | 2 constantes de budget + **1 entrée** dans `tableMessages()` | 2 espaces, CRLF |
| `core/class/jeeroborockException.class.php` | **non modifié** | le code ajouté est de **famille C** (opération), pas canal — § *Codes d'erreur* | — |
| `core/ajax/jeeroborock.ajax.php` | modifié | 2 `case` (`demanderCode`, `validerCode`) dans le `switch` existant | 2 espaces, CRLF |
| `plugin_info/configuration.txt` | modifié | bloc « État du compte » + bloc « Code de connexion » + JS inline | 2 espaces, CRLF |
| `plugin_info/configuration.php` | **régénéré par `cp`, jamais édité** | copie conforme du `.txt` ; contrôle par `git status --short plugin_info/configuration.php` | idem |
| `desktop/modal/modal.jeeroborock.php` | **non modifié** | décision D-04-1 : formulaire **inline**, pas de modale | — |
| `core/php/jeeroborock.inc.php` | non touché | aucune classe annexe créée | — |
| `core/config/jeeroborock.config.ini` | non touché | pas de défaut pour `userData`/`baseUrl` : la chaîne vide est le bon état initial, donc **aucun risque de court-circuit de `preConfig_`** sur ces clés | — |
| `plugin_info/packages.json` | non touché | aucune dépendance nouvelle (`aiohttp` déjà transitif de `python-roborock`) | — |
| `plugin_info/info.json` | non touché | `pluginVersion` incrémenté par le hook `pre-commit` | — |
| `core/php/jeeJeeroborock.php` | non touché | aucun push démon → Jeedom en UC04 | — |
| `desktop/php/*`, `desktop/js/jeeroborock.js` | non touchés | l'UI d'UC04 vit **exclusivement** dans la page de configuration plugin ; `desktop/js/jeeroborock.js` n'y est pas chargé (UC01) | — |
| `core/i18n/*.json` | non touchés | traduction par le sous-agent `translator` en fin de cycle | — |

**Nouvelle clé de configuration plugin** : `baseUrl` (non chiffrée, non éditable, **aucun champ de
formulaire**). `userData` existe déjà (UC01) et reste la seule clé chiffrée.

### Décisions d'architecture

**D-04-1 — Formulaire inline, pas de modale.** AC3 exige que « Envoyer un code » et « Valider le code »
coexistent sans rechargement ; une modale masquerait le champ e-mail et l'état du compte. Cela évite en
outre de dépendre du contrat de chargement des modales depuis le panneau de configuration plugin, non
vérifié dans ce contexte. `desktop/modal/modal.jeeroborock.php` est resté le squelette « Exemple de
modale » jamais personnalisé : aucun travail existant n'est gâché.
⚠️ **Conséquence documentaire** : la ligne de `CLAUDE.md` décrivant ce fichier comme portant « modale(s)
de la page de config (dont celle du code e-mail) » doit être corrigée, sinon une UC ultérieure
réintroduira la modale contre ce choix acté.

**D-04-2 — L'état d'authentification en cours vit dans `contexte['auth']` du démon, sans TTL.**
Forme : `{'client': RoborockApiClient, 'email': str}`. Le `contexte` est déjà le porteur canonique passé à
chaque coroutine par `handler_rpc` (`requete.app['contexte']`), ce qui évite une variable globale de
module et garde l'état testable. Une seule demande en cours à la fois (dernier écrivain gagne ; page
d'administration mono-utilisateur).

*Durée de vie = celle du processus démon, sans expiration.* L'instance ne contient **aucun secret**
(username, `base_url`, `device_identifier` aléatoire, cache régional — pas de jeton), donc la conserver ne
crée aucun risque ; et l'expirer serait **nuisible**, puisque cela régénérerait `device_identifier` donc
`header_clientid` (`base64(md5(email + device_identifier))`, `web_api.py` l. 130-134) et risquerait
d'invalider un code déjà envoyé.

*Deuxième clic sur « Envoyer un code »* : l'instance est **réutilisée si l'e-mail est identique**, recréée
seulement si l'e-mail a changé. Cela stabilise `header_clientid` entre plusieurs demandes (un code reçu au
premier envoi reste validable) et réutilise le cache `_iot_login_info` (économise le balayage
`getUrlByEmail`).

**D-04-3 — Si le démon redémarre entre les deux étapes, le plugin refuse de valider.** L'instance est
perdue, donc `header_clientid` changerait. `valider_code` exige un `contexte['auth']` correspondant et
lève sinon `AUTH_NO_PENDING_CODE`. *Alternative écartée* : tenter quand même avec une instance neuve —
cela brûlerait le code **et** un jeton du quota de 20/jour pour un échec probable, et afficherait un
« code invalide » mensonger. La tolérance du serveur Roborock à un `clientid` différent entre les deux
moitiés du login reste **une hypothèse non confirmée** (R-1) ; Home Assistant conserve l'instance, on
s'aligne.

**D-04-4 — `UserData` stocké en `base64(JSON compact)` opaque, jamais en JSON nu.** Ce n'est pas une
mesure de confidentialité (le chiffrement du core la porte), c'est une mesure de **fidélité d'aller-retour**
et de **confinement** :

- `config::byKey` applique `is_json($v, $v)`, qui **convertit en tableau PHP** toute valeur décodable
  (contrat vérifié en UC01 contre la source du core, l. 178). Un `userData` stocké en JSON reviendrait
  donc en **tableau PHP**, et il faudrait le ré-encoder pour le renvoyer au démon — ce qui casse la
  fidélité : un objet JSON vide (`Reference` dont tous les champs sont `None`, supprimés par `as_dict()`)
  devient `array()` en PHP puis **un tableau JSON vide** au ré-encodage, et `Reference.from_dict([])`
  retourne `None` → `rriot.r` **perdu silencieusement**.
- Avec le base64, le PHP ne manipule **jamais** une structure navigable du secret : aucun champ ne peut
  fuir par un `print_r`, un `var_dump` ou une réponse AJAX mal filtrée, et une **seule** regex valide
  l'ensemble.

**D-04-5 — La restauration de session est poussée par le PHP dans `deamon_start()`.** Point de passage
**unique** de tous les (re)démarrages (manuel, modale démon, `plugin::checkDeamon`), inséré juste avant
`message::removeAll('jeeroborock','demarrageDemon'); return true;` — donc uniquement quand
`state == 'ok'`.
⚠️ Ne pas confondre avec l'interdit d'UC03, qui porte sur **`deamon_info()`** (appelée chaque minute,
sans `try/catch` côté core), pas sur `deamon_start()`.

*Alternatives écartées* : (i) passage en **argument de ligne de commande** — visible dans `ps` par tout
utilisateur de la machine, rédhibitoire pour un secret complet de compte ; (ii) **pull par le démon** — il
n'a aucun accès à la base ; (iii) demande via le callback démon → Jeedom — `jeedom_com` est
*fire-and-forget*, il faudrait un aller-retour et une modification de `jeeJeeroborock.php` pour aucun gain.

**D-04-6 — Aucun redémarrage du démon après un login réussi.** `valider_code` a déjà posé la session dans
`contexte['session']`. ⚠️ **Écart assumé** avec `jeeroborock-architecture.md` D4 étape 4 (« le PHP
persiste … puis (re)démarre le démon ») : un redémarrage ferait attendre 30 s dans un appel AJAX et
couperait le canal sans raison. **D4 doit être corrigé** à l'étape de capitalisation mémoire.

**D-04-7 — `restaurerSession` ne fait aucun appel réseau et ne consomme aucun quota.** Elle ne
« vérifie » pas la session (ce serait un `homedata` par redémarrage, sur un quota de 40/jour). La
vérification du lien est l'objet explicite d'UC05.

**D-04-8 — Source de vérité « compte lié » = présence de la clé `userData` en configuration plugin**,
exposée par `jeeroborock::estCompteLie()`. L'état runtime du démon (`contexte['session']`) en est un
**dérivé**, jamais une source : il est vide juste après un redémarrage, tant que `restaurerSession` n'a
pas eu lieu.

**D-04-9 — `/sante` n'est pas modifié** (contrat gelé en UC03) : y ajouter un `authentifie` créerait un
second indicateur, plus faible, susceptible de contredire le badge. Le « le démon détient-il la
session ? » est le sujet d'UC05 ; en recette UC04, la ligne de log du démon suffit (R-6bis).

**D-04-10 — Aucun bouton « Délier le compte ».** Aucun AC ne le demande, et le chemin de récupération
existe déjà : redemander un code écrase la session, changer ou vider l'e-mail la supprime.

## Server vs Client

**Tout le métier est côté serveur.** Le JS de `configuration.txt` ne porte aucune règle métier, aucun code
d'erreur, aucun port, aucune apikey : il déclenche une action AJAX et affiche la chaîne que le serveur lui
renvoie.

Le JS est **inline dans `configuration.txt`** (et non dans un `.js` séparé) parce que ses libellés doivent
passer par le moteur i18n du core, qui n'opère que sur les fichiers rendus — même raison qu'en UC03.

### Chemin JS/AJAX de la validation du code (AC3, sans rechargement)

```
clic bt_jeeroborockValiderCode
  -> si verrou actif : sortie immediate
  -> code = valeur du champ, trim
     si vide : zone de message .text("<i18n Saisissez le code recu par e-mail.>") ; STOP (aucune requete)
  -> verrou = true ; les deux boutons recoivent addClass('disabled')
  -> zone de message .text("<i18n Validation en cours...>")
  -> $.ajax({ type:'POST', url:'plugins/jeeroborock/core/ajax/jeeroborock.ajax.php',
              data:{action:'validerCode', code:code}, dataType:'json', timeout:30000 })
     error   -> .text("<i18n Le demon ne repond pas.>")   // litterale DEJA presente (UC03)
     success -> si donnees.state != 'ok' :
                   .text(donnees.result)   // « Code invalide ou expire. » -> AC3
                   // le champ code et le bouton « Envoyer un code » restent en place et reactives
                si donnees.state == 'ok' :
                   .text("<i18n Authentification reussie, le compte est lie.>")
                   badge etat : .text("<i18n Compte Roborock lie>") + swap de classe
                   champ code : .val('')   // AC5 : le code ne reste pas dans le DOM
     complete -> verrou = false ; removeClass('disabled')
```

`ajax::error($e->getMessage())` renvoie **HTTP 200** avec `state:"error"`, `result:"<message>"`, `code:0`
(vérifié dans la source du core, `core/class/ajax.class.php` branche `alpha`, `getResponse()` l. 93-103) →
le callback `success` de jQuery est appelé et `donnees.result` **est la chaîne** à afficher. C'est ce qui
rend AC3 réalisable sans rechargement.

⚠️ Ce mécanisme n'est **pas** une hypothèse : il est **déjà en production** dans ce même fichier pour
`santeCanal` (`configuration.txt:73-77`, `donnees.result` affiché sans rechargement).

Le bouton « Envoyer un code » utilise le même squelette avec `action:'demanderCode'` (**sans paramètre** :
l'e-mail est lu côté serveur), et affiche « Code envoyé, vérifiez vos e-mails » — d'où la possibilité de
redemander un code immédiatement après un échec, sans rechargement.

**Verrou anti-double-soumission** : variable booléenne **plus** `addClass('disabled')`. Un `<a class="btn">`
ignore `prop('disabled')`, et un double clic coûterait un code **et** un jeton du quota.

## Contrats externes

Tous les appels sont faits **par `python-roborock` 7.8.0 dans le démon** — aucun appel PHP. Sources lues
en v7.8.0 : `roborock/web_api.py`, `roborock/data/containers.py`, `roborock/__init__.py`, `pyproject.toml`.

### 1. Découverte régionale (implicite, déclenchée par les deux étapes)

`POST <base>/api/v1/getUrlByEmail?email=<email>&needtwostepauth=false`, balayage **séquentiel** de
`BASE_URLS = [usiot, euiot, cniot, ruiot].roborock.com` (`web_api.py` l. 34-39, `_get_iot_login_info`
l. 79-112). Réponse `{code:200, data:{url, country, countrycode}}` → mis en cache dans l'instance
(`self._iot_login_info`).
Erreurs : `2003` → `RoborockInvalidEmail` ; `1001` → `RoborockMissingParameters` ; aucune base exploitable
→ `RoborockNoResponseFromBaseURL`. **Aucun limiteur sur cet appel.**

### 2. Envoi du code

`await client.request_code_v4() -> None` (l. 233-271).

- Si `country`/`country_code` sont `None` → **repli** automatique sur `request_code()` →
  `POST /api/v1/sendEmailCode?username=&type=auth` (l. 206-232).
- Prend un jeton : `_login_limiter.try_acquire_async("login", blocking=True, timeout=1)` →
  `RoborockRateLimit` si aucun jeton en 1 s. Rates `1/s, 3/min, 10/h, 20/jour` (l. 52-66),
  **attribut de classe** → compteur **par processus**.
- `POST <regional>/api/v4/email/code/send`, form `{email, type:"login", platform:""}`, en-têtes
  `header_clientid`, `header_clientlang: en`, `Content-Type: application/x-www-form-urlencoded`.
- Erreurs : `2008` → `RoborockAccountDoesNotExist` ; `9002` → `RoborockTooFrequentCodeRequests` ;
  `3030` → retire la base courante, invalide le cache régional et **se rappelle récursivement**
  (⇒ consomme un **second** jeton, R-4) ; sinon `RoborockException`.

### 3. Validation du code

`await client.code_login_v4(code, country=None, country_code=None) -> UserData` (l. 295-363).

- Avec `country`/`country_code` omis, la méthode les résout depuis le cache régional de l'instance ; si
  l'un est `None` → repli `code_login(code)` → `POST /api/v1/loginWithCode` (l. 407-438).
- Signature de requête : tirage `x-mercy-ks` (16 car. alphanum.) puis
  `POST <regional>/api/v3/key/sign?s=<ks>` → `data.k` = `x-mercy-k` (`_sign_key_v3`, l. 273-293).
- `POST <regional>/api/v4/auth/email/login/code`, form
  `{country, countryCode, email, code, majorVersion:14, minorVersion:0}` (les deux versions = version des
  CGU, **en dur dans la lib**), en-têtes `header_clientid`, `x-mercy-ks`, `x-mercy-k`,
  `header_clientlang: en`, `header_appversion: 4.54.02`, `header_phonesystem: iOS`,
  `header_phonemodel: iPhone16,1`.
- Réponse `{code:200, data:{…UserData…}}` → `UserData.from_dict(data)`.
- Erreurs : `2018` → `RoborockInvalidCode` ; `3009` → `RoborockNoUserAgreement` ; `3006` →
  `RoborockInvalidUserAgreement` ; `3039` → `RoborockAccountDoesNotExist` ; sinon `RoborockException`.
- ⚠️ **`code_login_v4` ne prend AUCUN jeton du limiteur** (vérifié : pas de `try_acquire_async` dans la
  méthode, contrairement à `request_code*`/`pass_login`). **Seul l'envoi du code est plafonné côté
  client** (R-6).

### 4. Transport — contrainte forte sur le budget de temps

`PreparedRequest.request()` (l. 796-819) : **aucun `timeout=` n'est passé à `session.request`** → aucun
plafond par requête côté lib. `session=None` implique qu'une `aiohttp.ClientSession` est créée et fermée
par requête (`finally: await session.close()`), y compris si la coroutine est annulée → pas de session à
gérer, pas de fuite sur `OPERATION_TIMEOUT`.

⚠️ `except (aiohttp.ClientError, TimeoutError, OSError) as err: raise RoborockException(f"Network error
contacting {_url}: {err}") from err` (l. 815-816) : **une panne réseau remonte en `RoborockException`
nue**, donc `ROBOROCK_ERROR` (« Erreur Roborock non identifiée ») et **pas** `CLOUD_UNREACHABLE` comme la
table d'UC03 le supposait → correction ciblée dans `erreurs.py` (R-2).

TLS : aucun paramètre `ssl=`/`verify_ssl=` nulle part → vérification par défaut d'aiohttp. Rien à faire,
rien à désactiver.

### 5. `UserData` — forme exacte et sérialisation

`containers.py` l. 226-237 :

```
UserData(RoborockBase): rriot: RRiot (REQUIS, sans défaut) ; uid, tokentype, token, rruid,
                        region, countrycode, country, nickname, tuya_device_state, avatarurl (tous |None)
RRiot(RoborockBase):    u, s, h, k (requis), r: Reference
Reference(RoborockBase): r, a, m, l (tous |None)   # r.a = base API IoT, r.m = broker MQTT
```

- `as_dict(exclude=None) -> dict` (l. 164-173) : `asdict()` avec un `dict_factory` qui **camélise** les
  clés (`_camelize`, l. 23-27) et **supprime toute valeur `None`**. `tuya_device_state` →
  `tuyaDeviceState` ; `tokentype`/`rruid`/`countrycode`/`avatarurl` inchangés (pas d'underscore).
- `from_dict(data)` (l. 105-129) : `_decamelize` chaque clé (l. 29-37), **ignore silencieusement** toute
  clé inconnue (log `debug`), reconstruit récursivement `RRiot`/`Reference`, puis `cls(**result)`. Un dict
  sans `rriot` lève `TypeError` (champ requis).
- Aller-retour `UserData.from_dict(UserData.as_dict())` : **fidèle** (vérifié clé par clé contre
  `_camelize`/`_decamelize`).
- ⚠️ `roborock/__init__.py` en 7.8.0 **n'expose pas `__version__`** (version statique dans
  `pyproject.toml`) → dette, § *Dette*.

## Signatures

### `resources/demond/authentification.py` *(créé)*

```python
_IMPORT_OK: bool          # False si l'import de la lib echoue -> operations en INTERNAL_ERROR
# from roborock.data import UserData ; from roborock.web_api import RoborockApiClient
#   (chemins utilises par la lib elle-meme ; import garde par try/except ImportError
#    pour que le demon reste lancable sur un venv partiellement installe)

async def demander_code(parametres, contexte) -> dict       # {"envoye": True}
async def valider_code(parametres, contexte) -> dict        # {"userData": "<base64>", "baseUrl": "<url>"}
async def restaurer_session(parametres, contexte) -> dict   # {"authentifie": bool}
def enregistrer_operations() -> None                        # canal.enregistrer(...) x3
def _client_pour(email, contexte) -> RoborockApiClient      # reutilise l'instance si email identique
def _encoder_user_data(user_data) -> str                    # base64(json compact(as_dict()))
def _decoder_user_data(valeur) -> UserData                  # leve ErreurDemon('AUTH_EXPIRED')
def _email_valide(valeur) -> bool
```

**`demander_code`** — valide l'e-mail (`_email_valide` : arobase présente, ≤ 254 caractères, pas de blanc
ni de caractère de contrôle — défense en profondeur, le démon ne fait pas confiance au PHP) sinon
`ErreurDemon('AUTH_EMAIL_INVALID')` ; `_client_pour()` **puis stockage dans `contexte['auth']` AVANT le
`await`** (un `OPERATION_TIMEOUT` ou une erreur ne doit pas perdre l'instance alors que l'e-mail a
peut-être été envoyé) ; `await client.request_code_v4()`.
Les exceptions de la lib remontent **telles quelles** à `handler_rpc`, qui les mappe — le module ne
rattrape ni ne reformule aucune erreur Roborock.

**`valider_code`** — valide `code` contre une regex **ancrée** `[A-Za-z0-9]{4,12}` sinon
`ErreurDemon('AUTH_CODE_INVALID')` ; exige `contexte['auth']` **et** `auth['email'] == email` sinon
`ErreurDemon('AUTH_NO_PENDING_CODE')` ; `await client.code_login_v4(code)` ;
`base_url = await client.base_url` (servi par le cache régional, sans appel réseau) ; stocke
`contexte['session'] = {'userData', 'baseUrl', 'email'}` ; **remet `contexte['auth'] = None` uniquement en
cas de succès** (sur échec, l'utilisateur doit pouvoir corriger une faute de frappe sans redemander un
code) ; retourne le blob encodé.

**`restaurer_session`** — `userData` vide/absent → `contexte['session'] = None` + `{"authentifie": False}`
(**ce n'est pas une erreur** : « non authentifié » est un état normal, et le démon doit rester lançable
sans identifiants) ; sinon décode et stocke. **Aucun appel réseau, aucun quota consommé** (D-04-7).

**`_decoder_user_data`** — `base64.b64decode(valeur, validate=True)` → `json.loads` → dict →
`UserData.from_dict` → **contrôle explicite** que `token`, `rriot` et `rriot.r` sont non vides (parce que
`from_dict` ignore silencieusement les clés inconnues : une montée de version de la lib produirait sinon
une session tronquée **sans erreur**, R-7). Tout échec → `ErreurDemon('AUTH_EXPIRED')`, **sans jamais
placer la valeur décodée dans l'exception ni dans un log**.

**`_encoder_user_data`** — `json.dumps(as_dict(), separators=(',',':'), ensure_ascii=True)` puis base64.
Pas d'`exclude` : on stocke le `UserData` **complet**, pour que l'aller-retour soit fidèle à ce que le
cloud a renvoyé.

⚠️ **R1 d'UC03 (boucle asyncio unique) : sans objet ici.** Les trois coroutines sont 100 % `async`
(aiohttp + limiteur async) — **aucun `asyncio.to_thread` n'est nécessaire**, et aucun appel bloquant ne
doit être introduit.

### `resources/demond/jeeroborockd.py` *(modifié)*

`contexte` reçoit `'auth': None` et `'session': None`. `authentification.enregistrer_operations()` est
appelé **explicitement** dans `principal_async` (pas d'enregistrement par effet de bord d'import : ordre
explicite, échec visible au démarrage).

⚠️ `handler_sante` construit sa réponse **champ par champ** : les deux nouvelles clés du contexte n'en
sortent donc pas. **Ne jamais sérialiser `contexte` en bloc** — un `UserData` n'est pas JSON-sérialisable
(donc plantage), mais surtout ce serait une fuite.

### `resources/demond/erreurs.py` *(modifié — 1 fonction)*

```python
def code_pour_exception(exc) -> tuple[str, str]
```

Règle ajoutée, déterministe : si le parcours du MRO retourne `ROBOROCK_ERROR` (c'est-à-dire qu'on n'a
matché que la classe de base) **et** que `exc.__cause__` existe, résoudre la cause ; si elle donne un code
autre que `CODE_DEFAUT`, l'utiliser. Sinon conserver `ROBOROCK_ERROR`.

Motif : `PreparedRequest` convertit `ClientError`/`OSError`/`TimeoutError` en `RoborockException` nue
(`raise … from err`) ; sans cette règle, « Jeedom n'a pas Internet » s'affiche « Erreur Roborock non
identifiée » au lieu de « Le cloud Roborock est injoignable ».

La fonction continue de **ne jamais lever** et n'importe toujours pas `roborock.exceptions` (décision
UC03 préservée).

### `core/class/jeeroborock.class.php` *(modifié)*

```php
const LONGUEUR_MAX_USERDATA = 8192;   // garde-fou sur la valeur renvoyee par le demon

public  static function estCompteLie()                 // -> bool
public  static function getUserData()                  // -> string (base64) ou ''
public  static function getBaseUrlCompte()             // -> string ou ''
public  static function enregistrerSession($_donnees)  // -> bool ; NE LEVE JAMAIS
private static function oublierSession()               // -> void ; NE LEVE JAMAIS
private static function restaurerSessionDemon()        // -> void ; NE LEVE JAMAIS
public  static function preConfig_email($_value)       // REECRITE (cf. ci-dessous)
public  static function deamon_start()                 // MODIFIEE : 1 appel a restaurerSessionDemon()
```

**`getUserData()`** — `config::byKey('userData','jeeroborock','')`, puis `if (!is_string($v)) return '';`.
Ce garde n'est pas cosmétique : `config::byKey` applique `is_json($v, $v)` et **convertirait en tableau
PHP** toute valeur qui serait du JSON — c'est précisément ce que l'encodage base64 empêche (D-04-4).

**`enregistrerSession($_donnees)`** — reçoit le tableau retourné par `appeler('validerCode', …)`. Valide
`userData` (regex base64 **ancrée**, `64 ≤ longueur ≤ LONGUEUR_MAX_USERDATA`) et `baseUrl` (regex ancrée
sur un sous-domaine de `roborock.com`, sinon chaîne vide — une base non-Roborock est écartée, la
découverte régionale la retrouvera). Enregistre **`baseUrl` d'abord, `userData` ensuite** (« compte lié »
ne devient vrai qu'en dernier). `config::save` est enveloppé dans un `try/catch (Throwable)` **interne** :
cette méthode est la seule frame qui détient le secret, elle **ne doit pas laisser remonter d'exception**
(règle UC01). Retour `false` + `log::add('error', …)` **sans aucune valeur**.

#### `preConfig_email($_value)` — pseudo-code complet et définitif

⚠️ Le code existant (`jeeroborock.class.php` l. 47-56) retourne sur `if ($_value == '') { return $_value; }`
**avant** toute autre logique. Greffer un enchaînement linéaire sur cette structure laisserait le **vidage**
du champ e-mail sans déliaison — c'est-à-dire un `userData` chiffré et valide rattaché à un compte qui
n'est plus configuré, que le démon rechargerait au prochain `deamon_start()`. Ce retour anticipé n'est
**pas** une sémantique de « rien à faire » : c'est un raccourci de validation, et il doit disparaître.

```
public static function preConfig_email($_value)      // -> string ; throws Exception

  1. $valeur = trim((string) $_value)                // cast defensif : config::save peut
                                                     // passer autre chose qu'une chaine
  2. $ancien = trim((string) config::byKey('email', 'jeeroborock', ''))
                                                     // LU EN PREMIER : preConfig_ s'execute
                                                     // avant l'ecriture, byKey rend l'ancienne valeur

  3. VALIDATION D'ABORD, ET SEULEMENT SI NON VIDE :
     si $valeur != '' et !filter_var($valeur, FILTER_VALIDATE_EMAIL)
         throw new Exception(__('L\'adresse e-mail du compte Roborock est invalide.', __FILE__))
     // -> AUCUNE deliaison sur ce chemin : la boucle addKey du core est interrompue,
     //    la nouvelle valeur n'est pas enregistree, la session doit rester coherente
     //    avec l'ancien e-mail

  4. $aChange = (strcasecmp($valeur, $ancien) !== 0)

  5. si $aChange et self::estCompteLie()
         self::oublierSession()                      // privee, NE LEVE JAMAIS

  6. return $valeur                                  // la chaine vide reste une valeur legitime
```

| Entrée | Validation | Déliaison | Valeur enregistrée |
|---|---|---|---|
| **vide**, ancien non vide, compte lié | ignorée (vide autorisé) | **oui** | `''` |
| **vide**, ancien déjà vide | ignorée | non (`$aChange` faux) | `''` |
| **invalide** (`toto@`) | **`Exception`** | **non** (jamais atteint) | rien — la boucle du core s'arrête |
| **inchangée** (même adresse, même casse) | passe | **non** — indispensable : `preConfig_` est appelé à **chaque** enregistrement de la page | identique |
| **changement de casse seule** | passe | **non** (`strcasecmp`) | telle que saisie |
| **nouvelle adresse valide**, compte lié | passe | **oui** | nouvelle |
| nouvelle adresse valide, **compte non lié** | passe | non (rien à délier) | nouvelle |

**Deux points d'ordre non négociables** : (i) la validation **précède** la déliaison — une faute de frappe
ne doit pas détruire une session fonctionnelle ; (ii) `config::byKey('email')` est lu **avant** tout, c'est
la seule fenêtre où l'ancienne valeur est encore disponible (rappel UC01 : `postConfig_` reçoit la valeur
déjà enregistrée, il ne peut pas comparer).

**`strcasecmp` plutôt que `!==`** : la casse de l'e-mail n'entre que dans le `header_clientid` du **flux de
login**, jamais dans la validité de la session stockée (un `token` + un `rriot`). Délier sur un simple
changement de casse imposerait une ré-authentification — donc un code e-mail et un jeton du quota de
20/jour — pour un compte identique. La valeur est en revanche **enregistrée telle que saisie** : UC01 a
explicitement décidé de ne pas normaliser la casse.

**Pas de court-circuit possible** : `email` n'a pas de valeur par défaut dans
`core/config/jeeroborock.config.ini` (qui ne définit que `portDemonHttp`), donc le piège « valeur égale au
défaut → ligne supprimée et `preConfig_` non appelé » ne s'applique pas à cette clé.

#### `oublierSession()` — et le verrou de session PHP

⚠️ **Piège générique Jeedom, vérifié sur la source du core** : `core/ajax/config.ajax.php` (branche
`alpha`) ne contient **aucun** `session_write_close()`. Son action `addKey` (l. 69-78) est une simple
boucle `foreach ($values as $key => $value) { config::save(...); }` : les hooks `preConfig_<clé>` sont donc
invoqués **synchronement, verrou de session tenu**. Tout hook qui appelle le démon **fige l'interface
Jeedom** pendant toute la durée de l'appel. Le hook doit donc relâcher lui-même le verrou.

```
private static function oublierSession()          // NE LEVE JAMAIS
  1. try { config::save('userData', '', 'jeeroborock')   // DB d'abord : l'etat persistant
  2.       config::save('baseUrl',  '', 'jeeroborock') } // est correct meme si la suite derape
     catch (Throwable $e) { log::add('jeeroborock', 'error', '... : ' . $e->getMessage()) }
  3. log::add('jeeroborock', 'info', 'Compte Roborock delie (e-mail modifie)')
  4. message::removeAll('jeeroborock', 'session_deliee')
     message::add('jeeroborock', <message FR>, '', 'session_deliee')
  5. si (session_status() === PHP_SESSION_ACTIVE) session_write_close()
  6. try { jeeroborockDaemon::appeler('restaurerSession',
             array('userData' => '', 'baseUrl' => '', 'email' => ''),
             jeeroborockDaemon::TIMEOUT_SESSION) }
     catch (Throwable $e) { log::add('jeeroborock', 'warning', '... : ' . $e->getMessage()) }
```

⚠️ **Les étapes 1-2 doivent être gardées par leur propre `try/catch`, au même titre que l'étape 6** —
signalé indépendamment par les deux reviewers au tour 1. Sans cela, la mention « NE LÈVE JAMAIS » est
**fausse** : un `config::save` qui lève (panne DB) ferait remonter l'exception à travers
`preConfig_email()` et **interromprait la boucle `addKey` de `core/ajax/config.ajax.php`** en pleine
sauvegarde de la page de configuration. Forme à calquer sur `enregistrerSession()`, qui garde déjà ses
propres `config::save`. Le `log::add` ne porte que `$e->getMessage()` — **jamais** une valeur de
configuration, jamais `getTraceAsString()`.
⚠️ Le `try/catch` de l'étape 6 ne doit **pas** englober `session_write_close()` : le verrou doit être
relâché quoi qu'il arrive avant l'appel réseau.

**Précédent dans notre propre code, pour le motif identique** : `jeeroborock::deamon_start()` l. 179-182
fait déjà `if (session_status() === PHP_SESSION_ACTIVE) { session_write_close(); }` parce que le hook est
aussi appelé depuis le cron. Le garde rend l'opération **idempotente** : sur le chemin `deamon_start`, la
session est déjà fermée quand `restaurerSessionDemon()` s'exécute, donc aucun double appel.

**Innocuité vérifiée** : après les hooks, `addKey` n'exécute plus que la suite de la boucle
`config::save()` (DB + cache de configuration, aucun accès à `$_SESSION`) puis `ajax::success()` (`echo` +
`die()`). `isConnect()`, `ajax::init()` et `unautorizedInDemo()` sont tous **antérieurs** à la boucle.
Fermer le verrou à ce point ne prive donc aucun code du core d'un `$_SESSION` en écriture.

**Pourquoi l'appel au démon n'est pas simplement supprimé** : sans lui, le démon conserverait en RAM la
session de l'ancien compte, et UC05 rapporterait un lien valide vers un compte qui n'est plus configuré.
*Alternatives écartées* : (a) déplacer l'appel dans `postConfig_email` — **ne corrige rien**, appelé depuis
le même `config::save()` dans la même boucle, verrou tenu ; (b) `deamon_stop()` — l'utilisateur perd le
démon jusqu'à une minute (relance par `plugin::checkDeamon`) pour un simple changement d'e-mail.

**`restaurerSessionDemon()`** — sort immédiatement si `!estCompteLie()` ; sinon
`appeler('restaurerSession', array('userData'=>…, 'baseUrl'=>…, 'email'=>…), TIMEOUT_SESSION)` en
`try/catch (Throwable)` qui ne journalise que `getMessage()` (message FR déjà traduit, sans secret) en
`warning`. **Jamais de `message::add`** ici : l'appel a lieu pendant un démarrage, éventuellement
déclenché par le cron.

### `core/class/jeeroborockDaemon.class.php` *(modifié)*

```php
const TIMEOUT_AUTH    = 25;   // s, demanderCode / validerCode (chaine 2 a 6 requetes HTTPS)
const TIMEOUT_SESSION = 3;    // s, restaurerSession (aucune I/O reseau)
```

Budgets en **constantes de classe**, jamais une clé de configuration (décision UC03). `appeler()` borne
son 3ᵉ paramètre à `[1, TIMEOUT_MAX = 60]` (`jeeroborockDaemon.class.php:32-39`) : les deux valeurs
passent.

**Cascade de budgets cohérente, obtenue sans toucher `canal.py`** : PHP envoie `budgetMs = 25000` dans le
corps JSON ; `handler_rpc` le clamp à `[1000, 60000]` (inchangé) puis fait
`asyncio.wait_for(fonction(...), (25000 - 1000) / 1000) = 24.0 s` — `MARGE_MS = 1000` est une constante
**générique** de `canal.py` (l. 44), il n'y a aucune constante par opération. La cascade
**démon 24 s < PHP 25 s < jQuery 30 s** est donc une **conséquence automatique** de `TIMEOUT_AUTH = 25`.
C'est le démon qui coupe le premier, donc l'utilisateur voit `OPERATION_TIMEOUT` (« L'opération n'a pas
abouti dans le délai imparti. ») et non un silence.

25 s reste sous les `max_execution_time`/`fastcgi_read_timeout` usuels (UC03 R12, qui recommande « les
opérations UC04+ devraient rester ≤ 30 s »). **C'est le seul plafond réel** : la lib n'impose aucun timeout
par requête, et le balayage régional peut enchaîner jusqu'à 4 POST séquentiels.

`TIMEOUT_SESSION = 3` (et non 5) : `restaurerSession` ne fait **aucune I/O réseau** — le coût nominal est
un aller-retour loopback (ordre de la milliseconde), et sur le chemin de déliaison le `userData` est vide,
donc l'opération sort immédiatement. Côté démon, `handler_rpc` calcule `(3000 − 1000)/1000 = 2 s` de
`wait_for` — très large pour un `base64`/`json.loads` de 1 Ko. 3 s borne le pire cas visible par
l'utilisateur (§ *Risques*, R-16) tout en restant au-dessus de toute exécution réaliste.

## Server Actions / API

### `core/ajax/jeeroborock.ajax.php` *(modifié — 2 `case`)*

Séquence inchangée : `isConnect('admin')` → `ajax::init()` → **`session_write_close()` déjà en place**
(l. 31) → `switch`. C'est indispensable ici : un appel de 25 s figerait sinon toute l'interface Jeedom.

Endpoint **admin** assumé : ces actions déclenchent des appels cloud qui consomment un quota de compte.

**`case 'demanderCode'`**
1. Lit l'e-mail **en configuration** (`config::byKey`, `trim`, `FILTER_VALIDATE_EMAIL`) — **jamais depuis
   le formulaire**.
2. Vide/invalide → `ajax::error(__('Renseignez l\'adresse e-mail du compte Roborock et enregistrez la
   configuration avant de demander un code.', __FILE__))` **sans appeler le démon** (aucun quota consommé).
3. Sinon `jeeroborockDaemon::appeler('demanderCode', array('email' => $email),
   jeeroborockDaemon::TIMEOUT_AUTH)` puis `ajax::success(array('envoye' => true))`.

**`case 'validerCode'`**
1. Même lecture d'e-mail (mêmes garanties).
2. `$code = trim((string) init('code'))`, validé par `preg_match` avec une regex **ancrée** `\A…\z`
   (jamais `^`/`$`) sur `[A-Za-z0-9]{4,12}` ; sinon `ajax::error(__('Saisissez le code reçu par e-mail
   (4 à 12 caractères alphanumériques).', __FILE__))`.
3. `$resultat = appeler('validerCode', array('email' => $email, 'code' => $code), TIMEOUT_AUTH)`.
4. `if (!jeeroborock::enregistrerSession($resultat)) { ajax::error(__('Le compte a été authentifié mais la
   session n\'a pas pu être enregistrée. Consultez le log du plugin.', __FILE__)); }`
5. `ajax::success(array('lie' => true))`.

⚠️ **La réponse est reconstruite champ par champ** : `$resultat`, qui contient le `userData`, ne repart
**jamais** vers le navigateur. C'est l'invariant posé en UC03 qui paie ici.

### `plugin_info/configuration.txt` *(modifié, puis `cp` vers le `.php`)*

Dans le `fieldset` « Compte Roborock » existant, après le champ e-mail :

1. **`#jeeroborockEtatCompte`** — un `span` de classe `label label-success` ou `label label-default` dont
   le texte est choisi **côté serveur** par un `if (jeeroborock::estCompteLie())`.
2. **Bloc « Code de connexion »** — lien-bouton `bt_jeeroborockDemanderCode`, champ texte
   `jeeroborockCodeConnexion` (`autocomplete="off"`, `maxlength="12"`, `inputmode="numeric"`), lien-bouton
   `bt_jeeroborockValiderCode`.
   ⚠️⚠️ **Ce champ ne porte PAS la classe `configKey`** — sinon le code serait enregistré comme clé de
   configuration plugin et deviendrait lisible par `core/ajax/config.ajax.php` action `getKey`, qui n'est
   **pas** admin-only (règle posée en UC01). **Même interdiction absolue pour un champ `userData`.**
3. **`#jeeroborockResultatAuth`** — zone de message, alimentée **uniquement** par `.text()`.
4. Un bloc `div.alert.alert-info` rappelant que la ré-authentification n'est jamais automatique.

⚠️ Fichier **rendu** : aucune méta-séquence i18n littérale, y compris dans le JS (attention aux accolades
consécutives) et dans les commentaires. Lancer `python .claude/scripts/verif-plugin.py` (colonne `meta=`)
**avant chaque commit**, puis `cp plugin_info/configuration.txt plugin_info/configuration.php`, et
contrôler par `git status --short plugin_info/configuration.php` — **ne jamais relire le `.php`**.

## Validation & erreurs

| Quoi | Où (autoritaire) | Comportement / message |
|---|---|---|
| e-mail du compte | **serveur**, action AJAX (`config::byKey` + `FILTER_VALIDATE_EMAIL`) — **jamais depuis le formulaire** | vide/invalide → « Renseignez l'adresse e-mail… », **sans appeler le démon** |
| e-mail (défense en profondeur) | démon, `_email_valide` | `AUTH_EMAIL_INVALID` |
| e-mail (enregistrement) | PHP, `preConfig_email` | `Exception` « L'adresse e-mail du compte Roborock est invalide. » (littérale UC01) |
| code saisi | client (non vide) **puis** serveur (regex ancrée) **puis** démon (même regex) | « Saisissez le code reçu par e-mail (4 à 12 caractères alphanumériques). » / `AUTH_CODE_INVALID` |
| demande en cours | démon (`contexte['auth']` + égalité d'e-mail) | `AUTH_NO_PENDING_CODE` |
| blob `userData` reçu du démon | PHP, `enregistrerSession` (regex base64 + bornes de longueur) | `false` → « Le compte a été authentifié mais la session n'a pas pu être enregistrée… » |
| `baseUrl` reçu du démon | PHP, regex sur un sous-domaine de `roborock.com` | non conforme → chaîne vide (dégradation silencieuse, sans conséquence) |
| blob relu au démarrage | démon, `_decoder_user_data` + contrôle `token`/`rriot`/`rriot.r` | `AUTH_EXPIRED` |
| budget de temps | démon (24 s) < PHP (25 s) < jQuery (30 s) | `OPERATION_TIMEOUT` puis `DAEMON_TIMEOUT` en dernier recours |
| droits | `isConnect('admin')` du fichier AJAX existant | endpoint **admin** |

**Typage** : `jeeroborockException` sur tout le chemin du canal (les appelants affichent `getMessage()`
sans traitement) ; `ErreurDemon` côté Python ; les exceptions de `python-roborock` remontent **non
rattrapées** jusqu'à `handler_rpc`, qui est le **point de mapping unique**.

**Aucune retentative, nulle part** — une seule retentative = un quota de login consommé, y compris dans
l'application mobile de l'utilisateur.

**Aucun garde-fou de cadence côté PHP** : le verrou de bouton + le limiteur de la lib + les messages
explicites suffisent ; un `cache::set` de cooldown serait une machinerie de plus pour un risque que
l'utilisateur ne peut s'infliger qu'en redémarrant volontairement le démon.

### Codes d'erreur stables

**Un seul code nouveau**, de **famille C** (erreur d'opération, HTTP 200 `success:false`) :

| Code | Émis par | Message FR | À ajouter dans |
|---|---|---|---|
| `AUTH_NO_PENDING_CODE` | `authentification.valider_code` (aucune demande en cours, ou e-mail changé depuis la demande) | « Aucune demande de code en cours (le démon a peut-être redémarré) : demandez un nouveau code. » | **`jeeroborockDaemon::tableMessages()` uniquement** |

⚠️ **Rattachement à `estErreurCanal()` : non.** La dette d'UC03 impose d'ajouter tout code de **famille A
ou B** dans les **deux** fichiers (`tableMessages()` **et** `jeeroborockException::estErreurCanal()`), sans
contrôle automatique. `AUTH_NO_PENDING_CODE` décrit un refus **applicatif** du flux d'authentification, pas
une défaillance du tuyau PHP↔démon : il reste hors de `estErreurCanal()`, exactement comme
`AUTH_CODE_INVALID`. **`jeeroborockException.class.php` n'est donc pas modifié.**

**Codes réutilisés sans aucune modification** (tous déjà présents et traduits depuis UC03, vérifiés dans
`tableMessages()` l. 205-231) : `AUTH_CODE_INVALID`, `AUTH_CODE_TOO_FREQUENT`, `AUTH_EMAIL_INVALID`,
`AUTH_ACCOUNT_UNKNOWN`, `AUTH_AGREEMENT_REQUIRED`, `AUTH_AGREEMENT_OUTDATED`, `AUTH_EXPIRED` (réemployé
pour un `userData` stocké illisible/incomplet — son message prescrit exactement la bonne action),
`RATE_LIMIT`, `RATE_LIMIT_REMOTE`, `CLOUD_UNREACHABLE`, `CLOUD_REGION_UNKNOWN`, `CLOUD_BAD_REQUEST`,
`OPERATION_TIMEOUT`, `INTERNAL_ERROR`, `ROBOROCK_ERROR`.

### Garantie AC5 — sept mesures structurelles

1. **Le code n'est persisté nulle part** : champ **sans** classe `configKey` (donc jamais enregistré,
   jamais relu par `getKey`), `autocomplete="off"`, et vidage du champ après succès.
2. **Le `userData` ne descend jamais au navigateur** : réponse AJAX reconstruite champ par champ
   (`array('lie' => true)`), jamais `ajax::success($resultat)`. Aucun champ de formulaire ne porte la clé,
   donc `jeedom.config.load` ne la demande pas et elle n'entre pas dans le DOM.
3. **Journalisation du canal** : `appeler()` ne journalise que les **noms** de clés
   (`implode(',', array_keys(...))`, UC03) ; `handler_rpc` idem (jointure des clés triées). Ni `code` ni
   `userData` ne peuvent atteindre un `log::add` ou un `logging` — **quel que soit le niveau de log**.
4. **Chemin d'exception** : `displayException()` est proscrit dans tout le plugin (et n'est appelé nulle
   part dans le code réel) ; les `catch` ne journalisent que `getMessage()`, jamais `getTraceAsString()`.
   **Et les secrets sont toujours passés à l'intérieur du tableau `$_parametres`**, jamais en paramètre
   scalaire : même une trace PHP brute (fatal dans `log/php`, où PHP tronque les arguments chaîne à 15
   caractères) n'affiche alors que `Array`.
5. **`enregistrerSession()` ne lève jamais** : c'est la seule frame PHP qui détient le blob, et elle
   rattrape elle-même un échec de `config::save`. Aucune exception ne peut donc naître d'une frame portant
   le secret.
6. **Côté démon** : `ErreurDemon` ne transporte qu'un code stable et un `detail` filtré aux scalaires par
   `reponse_erreur()` ; le démon ne renvoie jamais `str(exception)` ; `handler_sante` et toutes les
   réponses sont construites champ par champ (un `logging` avec `exc_info=True` imprime la trace et les
   lignes de code, **pas la valeur des variables**).
7. **Côté librairie** : vérification exhaustive des 13 appels `_LOGGER.*` de `web_api.py` — les corps de
   réponse ne sont journalisés que sur des chemins d'**échec** (aucun jeton dedans) et en niveau `info`,
   muet au niveau par défaut. Le `code_login_v4` **réussi n'est pas journalisé**. Seul résidu :
   `PreparedRequest` journalise le corps brut sur `ContentTypeError` (l. 806-812) — accepté (R-9).

**Corollaire acté en UC03** : le canal **transporte** le secret en clair sur la boucle locale
(`127.0.0.1`), ce qu'AC6 n'interdit pas ; TLS sur loopback est écarté (il imposerait un certificat
auto-signé, donc une dérogation à « ne jamais désactiver la vérification TLS »).

## Dépendances

**Aucune nouveauté.** `python-roborock 7.8.0` est déjà déclaré dans `plugin_info/packages.json` (UC02) et
`aiohttp` en est une dépendance transitive. `plugin_info/packages.json` n'est pas modifié.

## Impact i18n (français uniquement dans cette UC)

`core/i18n/*.json` **non touchés** : le sous-agent `translator` intervient en fin de cycle, sur le code
figé.

**`plugin_info/configuration.txt` → `.php`** — **13 littérales nouvelles** :

1. « État du compte »
2. « Compte Roborock lié » *(écrite **à la fois** par le PHP en rendu serveur et par le JS après succès,
   dans le **même fichier** → **une seule** clé i18n)*
3. « Compte Roborock non lié »
4. « Envoyer un code »
5. « Code de connexion »
6. « Valider le code »
7. « Roborock envoie un code à usage unique à l'adresse e-mail enregistrée. Enregistrez la configuration
   avant de demander un code. »
8. « La ré-authentification n'est jamais automatique : si la session expire, redemandez un code de
   connexion. »
9. « Envoi du code en cours… »
10. « Code envoyé, vérifiez vos e-mails »
11. « Validation en cours… »
12. « Saisissez le code reçu par e-mail. »
13. « Authentification réussie, le compte est lié. »

⚠️ **Non comptée, car déjà présente** dans ce fichier depuis UC03 (`configuration.txt:71`) : « Le démon ne
répond pas. » — même clé, réutilisée par les deux nouveaux gestionnaires `error` de jQuery. **Ne pas la
redéclarer.**

⚠️ **Le badge et le message de résultat sont deux chaînes distinctes**, et volontairement lexicalement
différentes : badge « Compte Roborock lié » (sans ponctuation, style `label`), message
« Authentification réussie, le compte est lié. » (phrase complète). Éviter deux clés qui ne diffèrent que
par un point final — piège classique pour le `translator`.

**`core/ajax/jeeroborock.ajax.php`** — 3 littérales : « Renseignez l'adresse e-mail du compte Roborock et
enregistrez la configuration avant de demander un code. » · « Saisissez le code reçu par e-mail (4 à 12
caractères alphanumériques). » · « Le compte a été authentifié mais la session n'a pas pu être
enregistrée. Consultez le log du plugin. »

**`core/class/jeeroborockDaemon.class.php`** — 1 littérale : « Aucune demande de code en cours (le démon a
peut-être redémarré) : demandez un nouveau code. »

**`core/class/jeeroborock.class.php`** — 1 littérale : « L'adresse e-mail du compte Roborock a changé : le
compte a été délié, demandez un nouveau code de connexion. » *(rédaction volontairement valable aussi pour
un e-mail **vidé** — un seul message pour les deux cas)*.
⚠️ « L'adresse e-mail du compte Roborock est invalide. » existe **déjà** dans ce fichier (UC01) : elle
n'est pas une nouveauté, elle est simplement conservée dans la nouvelle `preConfig_email`.

**Total UC04 : 18 littérales françaises nouvelles**, réparties sur 4 fichiers.

**Déjà couvert par UC03, à ne pas dupliquer** : 4 des 8 chaînes anticipées par la spec fonctionnelle
(« Code invalide ou expiré », quota, CGU, compte inexistant) sont **déjà** dans `tableMessages()`.

Messages `log::add` et messages du démon : français **non enveloppé**. Codes stables : **anglais**.

## Risques & pièges

- **R-1 (majeur) — continuité de `header_clientid` non confirmée.** Hypothèse de cadrage (D3) : le serveur
  Roborock refuse un `clientid` différent entre l'envoi et la validation. Conséquence assumée : un
  redémarrage du démon entre les deux étapes force une nouvelle demande de code (`AUTH_NO_PENDING_CODE`).
  **À confirmer sur le matériel/compte réel.**
- **R-2 (majeur) — panne réseau mal qualifiée par la lib.** `PreparedRequest` convertit
  `ClientError`/`OSError`/`TimeoutError` en `RoborockException` nue (`web_api.py` l. 815-816) →
  `ROBOROCK_ERROR`. **Écart avec la table d'UC03**, qui annonçait `CLOUD_UNREACHABLE` pour
  `aiohttp.ClientError`/`OSError` : ce mapping est en pratique **inatteignable** via `web_api`. Corrigé par
  la lecture de `__cause__` dans `code_pour_exception`.
- **R-3 (majeur) — quotas durs, partagés avec l'application mobile.** Chaque « Envoyer un code » consomme
  1 des **20/jour** et envoie un vrai e-mail. Le limiteur de la lib est un **attribut de classe**, donc
  **par processus** : un redémarrage du démon remet le compteur local à zéro alors que le quota serveur
  court toujours. Le verrou de bouton est la protection principale contre le double clic.
- **R-4 — une demande peut consommer deux jetons.** Sur code `3030`, `request_code_v4` retire la base
  courante et **se rappelle**, en reprenant un jeton (l. 263-266).
- **R-5 — aucun timeout par requête dans la lib.** Une base régionale en trou noir consomme tout le budget
  dans le balayage `getUrlByEmail` (jusqu'à 4 POST séquentiels) → `OPERATION_TIMEOUT` **sans qu'aucun code
  n'ait été envoyé**. Aucun réglage ne corrige cela : seul le budget global borne l'attente.
- **R-6 — `code_login_v4` n'est pas plafonnée côté client** : une rafale de validations ne consomme aucun
  jeton local mais peut déclencher un refus serveur. D'où le verrou de bouton et l'absence totale de
  retentative.
- **R-7 — sérialisation silencieusement tolérante.** `as_dict()` supprime les `None` et camélise ;
  `from_dict()` **ignore sans erreur** toute clé inconnue. Une montée de version de la lib qui renommerait
  un champ de `UserData` produirait une session **tronquée sans échec visible** → d'où le contrôle
  explicite `token`/`rriot`/`rriot.r` après décodage. **À revérifier à chaque changement de version.**
- **R-8 — `core/ajax/config.ajax.php` ne relâche pas le verrou de session.** Piège **générique Jeedom**,
  vérifié sur la source du core (branche `alpha`) : zéro `session_write_close()`. Tout hook
  `preConfig_<clé>`/`postConfig_<clé>` qui appelle le démon s'exécute **verrou tenu** et fige l'interface
  Jeedom pendant toute la durée de l'appel. Parade retenue : `session_write_close()` sous garde
  `session_status() === PHP_SESSION_ACTIVE` dans `oublierSession()`, et `TIMEOUT_SESSION` réduit à 3 s.
  **Pire cas résiduel, chiffré et accepté** : 3 s ajoutées à l'enregistrement de la page, **et seulement
  si** (i) l'e-mail change réellement, (ii) un compte est lié, **et** (iii) le démon écoute mais ne répond
  pas. Un démon arrêté ne coûte rien (connexion refusée immédiate sur `127.0.0.1`). Un démon vivant répond
  en millisecondes — sa boucle asyncio n'est jamais bloquée par les opérations UC04, 100 % `async`.
- **R-9 — résidu de journalisation de la lib** : corps de réponse journalisé sur `ContentTypeError`
  (niveau `info`, muet par défaut) et contexte des logins **échoués** (sans jeton). Accepté.
- **R-10 — format du code inconnu.** Hypothèse : 6 chiffres. Regex volontairement permissive
  (alphanumérique, 4 à 12) pour ne pas rejeter un code valide. **À confirmer en recette** ; resserrer si
  confirmé numérique.
- **R-11 — CGU (3006/3009) non testables** sans compte dans cet état : le point « À confirmer » de la spec
  fonctionnelle reste ouvert.
- **R-12 — perte de quota si `config::save` échoue** après un login réussi : le lien n'est pas persisté,
  l'utilisateur doit refaire le flux. Message explicite, pas de correction automatique.
- **R-13 — désynchronisation `configuration.txt`/`.php`** et **méta-séquences** dans un fichier rendu
  (rappels UC03) : `verif-plugin.py` puis `cp`, contrôle par `git status --short`.
- **R-14 — CSRF.** `ajax::init()` ne vérifie plus de jeton (`getToken()` déprécié depuis 4.4, retourne une
  chaîne vide) : la protection est la session admin. Une requête forgée pourrait au pire faire envoyer un
  code (brûler un quota) ; la validation exige un code que l'attaquant n'a pas. Exposition **identique à
  tout endpoint AJAX de plugin Jeedom**, hors périmètre.
- **R-15 — contrainte sur l'avenir** : `restaurerSession` ne vérifie pas la session auprès du cloud. UC05
  devra donc faire la vérification, et UC11 (ré-authentification) devra prévoir que le démon peut détenir
  une session périmée **sans le savoir**.
- **R-16 — `deamon_start()` s'allonge de la restauration de session.** Plafond théorique **33 s**
  (`DELAI_DEMARRAGE_DEMON = 30` + `TIMEOUT_SESSION = 3`), contre 30 s aujourd'hui. Reste sous le garde-fou
  de 45 s consigné dans `.memory/analyse/jeedom-dependances-et-demon.md` § 4, avec 12 s de marge. En marche
  nominale, le démarrage observé est de 2-4 s et la restauration de l'ordre de la milliseconde (aucune I/O
  réseau) : les 3 s ne sont atteintes que si le démon écoute sans répondre — cas où le démarrage était déjà
  pathologique.

## Recette (à confirmer sur une Jeedom réelle, Debian 12+)

| # | AC | Vérification | Attendu |
|---|---|---|---|
| R-1 | AC1 | e-mail valide enregistré, clic « Envoyer un code » | « Code envoyé, vérifiez vos e-mails » en moins de 25 s **et** e-mail Roborock effectivement reçu |
| R-2 | AC2/AC6 | saisir le bon code | « Authentification réussie, le compte est lié. », badge basculé **sans rechargement** ; en base, la ligne `config` (plugin `jeeroborock`, clé `userData`) est illisible en clair |
| R-3 | AC3 | saisir un code erroné | « Code invalide ou expiré. », page non rechargée, « Envoyer un code » réutilisable immédiatement, puis un nouveau code fonctionne |
| R-4 | AC4 | 4 clics « Envoyer un code » en moins d'une minute | le 4ᵉ affiche le message de quota (le limiteur attend 1 s puis refuse au-delà de 3/min) ; **aucune requête supplémentaire** dans le log du démon. ⚠️ consomme 3 codes du quota quotidien |
| R-5 | AC5 | niveau de log `debug`, rejouer tout le flux | ni le code ni aucun blob base64 dans `log/jeeroborock`, `log/jeeroborock_demon`, `log/php` ; onglet réseau du navigateur sans `userData` ; champ code vide après succès |
| R-6 | D-04-5 | redémarrer le démon depuis la modale | ligne « session restaurée » dans le log du démon ; `GET /sante` répond comme avant (non-régression UC03) |
| R-6bis | R-16 | compte lié, arrêt puis démarrage manuel du démon depuis la modale démon, **chronométré** | la modale rend la main en **≤ 35 s** (nominal attendu : 2-5 s) ; log du démon contenant la ligne de session restaurée ; `GET /sante` inchangé |
| R-7 | D-04-3 | demander un code, redémarrer le démon, **puis** valider | « Aucune demande de code en cours… » — **pas** « code invalide » |
| R-8a | D-04-8 | compte lié, remplacer l'e-mail par **une autre adresse valide**, enregistrer | message « compte délié » au centre de messages, badge « Compte Roborock non lié » à la réouverture, log du démon montrant la session vidée |
| R-8b | MAJOR 1 | compte lié, **vider** le champ e-mail, enregistrer | **même résultat** qu'en R-8a (cas de régression du trou corrigé) |
| R-8c | D-04-8 | compte lié, réenregistrer la page **sans rien changer**, puis en changeant **seulement la casse** | **aucune** déliaison, badge toujours « Compte Roborock lié », aucun message |
| R-8d | — | compte lié, saisir un e-mail **invalide**, enregistrer | erreur « L'adresse e-mail du compte Roborock est invalide. », e-mail **inchangé** en base, **compte toujours lié** |
| R-9 | AC1 | démon arrêté, clic « Envoyer un code » | « Le démon ne répond pas… » en moins de 5 s, interface Jeedom fluide pendant l'appel |
| R-10 | R-10/R-11 | noter le format réel du code ; tenter un compte à CGU non acceptées si disponible | ajuster la regex ; vérifier les messages CGU |

## Dette

Points identifiés pendant ce cycle, **volontairement non traités en UC04** :

- **`python-roborock` n'expose pas `__version__` → le garde-fou de version d'UC02 est inerte.** En 7.8.0,
  `roborock/__init__.py` ne définit pas `__version__` (version statique dans `pyproject.toml`) :
  `detecter_version()` retourne « inconnue », la bannière de démarrage affiche « python-roborock inconnue »
  et **l'alerte de majeure ne se déclenche jamais** — alors que c'est le garde-fou documenté contre la
  dérive de version. Correctif suggéré : `importlib.metadata.version("python-roborock")`. **Dette UC02**,
  hors périmètre UC04.
- **`estErreurCanal()` duplique la liste des codes de famille A/B** sans aucun contrôle automatique
  (dette héritée d'UC03). UC04 n'ajoute qu'un code de famille C, donc ne l'aggrave pas, mais le piège
  reste entier pour les UC suivantes.
- **`jeeroborock-architecture.md` D4 étape 4 est à corriger** : elle prescrit un (re)démarrage du démon
  après persistance du `UserData`, que D-04-6 écarte. À traiter à la capitalisation mémoire.
- **La ligne de `CLAUDE.md` sur `desktop/modal/modal.jeeroborock.php`** (« dont celle du code e-mail ») est
  rendue fausse par D-04-1. À corriger à la capitalisation mémoire, sinon une UC ultérieure réintroduira la
  modale.
- **Lecture/validation de l'e-mail dupliquée entre les deux `case`** de `core/ajax/jeeroborock.ajax.php`
  (`trim` + `config::byKey` + `FILTER_VALIDATE_EMAIL` + message d'erreur identique). Relevé en
  `suggestion` au tour 1 de review, **volontairement non factorisé** : la duplication est fidèle au
  pseudo-code de cette spec, et deux occurrences ne justifient pas un helper. **À extraire à la 3ᵉ
  occurrence**, c'est-à-dire dès qu'UC05+ ajoute une opération soumise à la même règle.
