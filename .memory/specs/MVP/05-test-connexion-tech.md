# Spec technique — UC05 : Test de connexion et état du compte

> **Spec fonctionnelle** : `05-test-connexion.md` · **Dépend de** : UC03 (pont PHP↔démon), UC04 (authentification)
> **Statut** : plan validé (advisor : aucun blocage) · **Arbitrage utilisateur** : TTL du cache d'inventaire = **24 h**

## Périmètre

Ajouter à la page de configuration plugin un bouton « Tester la connexion » qui qualifie le lien au compte
Roborock en **quatre états distincts**, en s'appuyant d'abord sur l'état local (config plugin), puis sur une
**sonde cloud légère** qui ne consomme **pas** le quota `homedata`, et enfin sur un **cache PHP de
l'inventaire** qui évite de rappeler `homedata` à chaque test.

**Une seule** opération démon nouvelle, **aucune** nouvelle route HTTP, **aucune** nouvelle classe PHP,
**aucun** nouveau code d'erreur stable, **aucune** nouvelle clé de configuration.

Hors périmètre (rappel de la spec fonctionnelle) : création des équipements (UC06), pilotage (UC07/UC08),
ré-authentification effective (UC11), joignabilité MQTT du robot (UC10).

### Couverture des critères d'acceptation

| AC | Réalisé par | Requêtes HTTPS vers Roborock |
|---|---|---|
| **AC1** — « non configuré » | `case 'testerConnexion'` étape 1 : `jeeroborock::getEmailCompte()` vide → réponse immédiate. **Aucun appel démon** → répond même démon arrêté | **0** |
| **AC2** — « non authentifié » | étape 2 : `jeeroborock::estCompteLie()` faux (absence de `userData`, D-04-8). **Aucun appel démon** | **0** |
| **AC3** — « authentifié » + nombre de robots | opération démon `etatCompte` avec `avecInventaire = true` au **premier** test (cache vide) → `get_home_data_v3()` → `len(home.get_all_devices())` → mis en cache PHP 24 h et affiché | 2 |
| **AC4** — pas de nouvel `homedata` si le cache suffit | `jeeroborock::getInventaireCompte()` décide **côté PHP** de la valeur de `avecInventaire`. Quand il est renseigné, le démon n'appelle que la sonde `_get_home_id` — **hors limiteur `home_data`**. Traçabilité par `log::add` (§ Server Actions, étape 5) | 1 (jamais `homedata`) |
| **AC5** — « ré-authentification requise », sans nouvelle tentative | `RoborockInvalidCredentials` (réponse cloud `code: 2010` sur `getHomeDetail`) → `AUTH_EXPIRED` (mapping **déjà en place**, `erreurs.py:31`) → état `reauthentification`. Aucun login automatique nulle part | 1 ou 2 |
| **AC6** — quota déjà atteint, message explicite | limiteur **local** de `python-roborock` (`try_acquire("home_data", blocking=False)` → `RoborockRateLimit` → `RATE_LIMIT`, **refusé sans aucun appel réseau**) ; refus **serveur** → `RoborockTooManyRequest` → `RATE_LIMIT_REMOTE`. Aucune retentative ; verrou de bouton | **0** (limiteur local) |

**Aucun critère non couvert.** Le point « À confirmer » de la spec fonctionnelle (durée de validité réelle
d'une session `rriot`) est traité **structurellement** : le plugin n'anticipe, ne suppose et ne stocke aucune
durée — il se fie **uniquement** à l'échec effectif remonté par le cloud (limite connue : R-2).

## Architecture

### Fichiers

| Fichier | État | Ce qui y entre | Indentation / EOL |
|---|---|---|---|
| `resources/demond/authentification.py` | **modifié** | 1 coroutine `etat_compte` + 2 helpers privés + 1 ligne dans `enregistrer_operations()` ; imports `logging` et `code_pour_exception` | **4 espaces, LF** |
| `resources/demond/canal.py` | **non modifié** | registre/middlewares/budget inchangés (non-régression UC03) | — |
| `resources/demond/erreurs.py` | **non modifié** | tous les codes nécessaires existent déjà | — |
| `resources/demond/jeeroborockd.py` | **non modifié** | `enregistrer_operations()` est déjà appelé ; le contexte est inchangé | — |
| `core/class/jeeroborock.class.php` | modifié | 2 constantes + 4 méthodes statiques + purge du cache dans `oublierSession()` et `enregistrerSession()` | **2 espaces, CRLF** |
| `core/class/jeeroborockDaemon.class.php` | modifié | **1 seule ligne** : `const TIMEOUT_COMPTE = 20;`. `tableMessages()` **non modifiée** | 2 espaces, CRLF |
| `core/class/jeeroborockException.class.php` | **non modifié** | aucun code de famille A/B ajouté → la duplication `estErreurCanal()`/`tableMessages()` **n'est pas en jeu** dans cette UC | — |
| `core/ajax/jeeroborock.ajax.php` | modifié | 1 `case 'testerConnexion'` + factorisation de la lecture d'e-mail dans les 2 `case` d'UC04 | 2 espaces, CRLF |
| `plugin_info/configuration.txt` | modifié | bouton + zone de résultat dans le `form-group` « État du compte » existant + 1 gestionnaire JS inline | 2 espaces, CRLF |
| `plugin_info/configuration.php` | **régénéré par copie, jamais édité ni relu** | `cp plugin_info/configuration.txt plugin_info/configuration.php`, contrôle par `git status --short plugin_info/configuration.php` | idem |
| `core/php/jeeroborock.inc.php` | **non touché** | **autoload sans objet** — aucune classe PHP nouvelle ; `jeeroborock` et `jeeroborockDaemon` ont déjà leur fichier | — |
| `core/config/jeeroborock.config.ini` | **non touché** | aucune clé de configuration nouvelle → **aucun risque de court-circuit de `preConfig_`** | — |
| `plugin_info/packages.json`, `info.json` | **non touchés** | aucune dépendance nouvelle ; `pluginVersion` bumpé par le hook `pre-commit` | — |
| `core/php/jeeJeeroborock.php`, `desktop/**`, `core/i18n/*.json` | **non touchés** | pas de push démon→Jeedom ici ; l'UI vit dans la page de config plugin ; traduction en fin de cycle | — |

**Nouvelle clé de cache** (classe `cache`, **pas** une clé de configuration) : `jeeroborock::inventaireCompte`,
TTL **86400 s (24 h)**.

### Décisions d'architecture

#### D-05-1 — Le cache de l'inventaire vit côté PHP (classe `cache`), pas dans le démon

Motif décisif : le compteur de quota de `python-roborock` est un **attribut de classe**, donc **par
processus** — un redémarrage du démon le remet à zéro alors que le quota serveur court toujours (R-3 d'UC04).
Un cache en RAM du démon disparaîtrait exactement dans le cas où l'on a le plus besoin de lui. Le cache PHP
**survit** au redémarrage du démon comme à celui de Jeedom, et il est consulté **avant** d'appeler le démon :
le moyen le plus sûr de ne pas brûler un quota est de ne pas émettre l'appel.

Contenu : `array('nbRobots' => int, 'horodatage' => int)` — **aucun secret**.

*Alternatives écartées* : cache dans le contexte du démon (perdu au redémarrage) ; les deux à la fois (deux
horloges à maintenir, aucune garantie supplémentaire).

#### D-05-2 — Matrice état → source de vérité → appel réseau

Évaluation **dans cet ordre**, court-circuit au premier état atteint :

| État | Décidé où | Source de vérité | Appel démon | Requêtes HTTPS |
|---|---|---|---|---|
| **non configuré** | PHP, avant tout | `config::byKey('email')` vide/invalide | **non** | **0** |
| **non authentifié** | PHP, avant tout | `estCompteLie()` (présence de `userData`) | **non** | **0** |
| **authentifié**, inventaire en cache | démon (validité) + cache PHP (nombre) | cloud pour la validité, cache pour le nombre | oui, `avecInventaire:false` | **1** (`getHomeDetail`) |
| **authentifié**, inventaire absent/périmé | démon | cloud | oui, `avecInventaire:true` | **2** |
| **ré-authentification requise** | démon → `AUTH_EXPIRED` | réponse cloud `code:2010` | oui | 1 ou 2 |
| quota d'inventaire atteint | démon, limiteur **local** | limiteur de la lib | oui | **1** (sonde de repli seulement) |
| quota serveur / cloud injoignable / démon arrêté | canal | exception typée | oui / sans objet | 0 à 2 |

Conséquence remarquable : **AC1 et AC2 restent vrais démon arrêté**, ce qui est exactement ce qu'on attend
d'un outil de diagnostic.

#### D-05-3 — AC2 et AC5 sont décidés par deux mécanismes disjoints, jamais par une durée

- « **non authentifié** » = **purement local** : `userData` absent de la configuration plugin (D-04-8 inchangé).
- « **ré-authentification requise** » = **compte lié en base mais refusé par le cloud** (`2010` →
  `RoborockInvalidCredentials` → `AUTH_EXPIRED`).

Aucune connaissance de la durée de vie d'une session `rriot` n'est nécessaire, ni supposée, ni stockée. Le
message FR existe déjà (`AUTH_EXPIRED` : « Session Roborock expirée : une nouvelle authentification par code
e-mail est nécessaire. ») et prescrit la bonne action.

⚠️ **`AUTH_EXPIRED` ne déclenche AUCUNE action corrective** : pas d'effacement du `userData`, pas de
`message::add`, pas de tentative de login. Un `2010` transitoire ne doit pas détruire une session valide ; la
ré-authentification est le sujet d'UC11.

#### D-05-4 — Le nombre de robots vient d'un `homedata` émis par le premier test, pas par UC04

UC04 reste intouché : `restaurerSession` ne fait aucun appel réseau (D-04-7 tient). Le premier test après
authentification trouve le cache vide → `avecInventaire = true` → un `homedata`, mis en cache **24 h**. Tous
les tests suivants dans la fenêtre : **zéro** `homedata` (AC4).

**Coût maximal de ce bouton : 1 `homedata`/jour**, soit **2,5 % du quota quotidien de 40** — contre 40 si
chaque test rappelait l'inventaire.

Le cache est **purgé** sur déliaison (`oublierSession`) et sur nouvelle liaison (`enregistrerSession`) — sans
quoi le test afficherait le nombre de robots de l'**ancien** compte.

> **Contrepartie assumée du TTL 24 h** (arbitrage utilisateur) : un robot ajouté dans l'application mobile
> peut rester non compté par le test pendant 24 h. Deux atténuations **obligatoires**, pas optionnelles :
> l'**horodatage de l'inventaire est affiché** dans le message d'état (l'utilisateur voit la fraîcheur de la
> donnée), et **UC06 devra invalider `jeeroborock::inventaireCompte`** lors de la découverte (cf. R-6).

#### D-05-5 — Aucun compteur de quota maison

`get_home_data_v3` commence par `try_acquire("home_data", blocking=False)` : le refus survient **avant tout
paquet réseau** → `RoborockRateLimit` → `RATE_LIMIT` → message FR explicite. C'est littéralement « quota déjà
atteint » (AC6) sans rien réimplémenter.

Un compteur maison serait une **troisième** source de vérité, elle aussi par processus, et incapable de voir
la consommation de l'**application mobile** — donc structurellement fausse.

Hiérarchie retenue : **cache PHP = protection durable ; limiteur de la lib = filet local ; refus serveur
(`RATE_LIMIT_REMOTE`) = dernier recours**.

#### D-05-6 — Le refus **local** du limiteur est rattrapé et dégradé ; le refus **serveur** ne l'est pas

Si `avecInventaire = true` et que le limiteur local refuse, l'opération **ne doit pas** échouer en bloc : la
sonde `_get_home_id` est alors exécutée en repli et le test répond « authentifié, inventaire indisponible
(quota) ». Motif : le refus local signifie « le plugin a décidé de ne pas appeler » — rien n'est perdu, l'état
du compte reste vérifiable, et masquer une vérification réussie derrière une erreur de quota serait un mauvais
diagnostic.

Le refus **serveur** (`RATE_LIMIT_REMOTE`), lui, signale que le compte est peut-être **déjà pénalisé** côté
Roborock : il remonte **tel quel** comme erreur principale.

⚠️ Le rattrapage se fait **sans importer `roborock.exceptions`** (D-h d'UC03 préservé) :
`if code_pour_exception(erreur)[0] != "RATE_LIMIT": raise`. On réutilise la brique de mapping par nom de
classe déjà livrée.

> **Vérifié en revue** : `erreurs.py:38-39` porte bien deux entrées **disjointes** —
> `"RoborockRateLimit": "RATE_LIMIT"` (local) et `"RoborockTooManyRequest": "RATE_LIMIT_REMOTE"` (serveur).
> Le rattrapage cible donc **uniquement** le refus local et ne peut pas avaler un refus serveur.

#### D-05-7 — L'opération vit dans `authentification.py`, pas dans un nouveau module

Elle ne fait qu'**interroger la validité de la session** dont ce module est déjà le propriétaire
(encodage/décodage du blob, session en contexte). Créer un `compte.py` pour une coroutine d'une trentaine de
lignes imposerait d'importer `_decoder_user_data`/`_encoder_user_data` **sous leur nom privé** depuis un autre
module — un couplage plus coûteux que le gain.

UC06, qui introduit un vrai domaine (mapping produits/`duid`/capacités), créera son module ; c'est à ce
moment-là que les helpers de session devront être promus (cf. Dette).

#### D-05-8 — Le PHP pousse la session dans l'appel, il ne présume pas que le démon l'a en RAM

`etatCompte` reçoit `userData`/`baseUrl`/`email` comme `restaurerSession`. Cela rend l'opération
**idempotente et immune au redémarrage du démon** (plus de branche « le démon ne sait pas encore »), coûte
zéro quota, et réamorce la session en contexte au passage.

*Alternative écartée* : lire la session du contexte et retomber sur `NOT_AUTHENTICATED` → obligerait le PHP à
enchaîner `restaurerSession` **puis à retenter**, c'est-à-dire exactement le motif qu'on s'interdit partout
ailleurs.

#### D-05-9 — Aucun cooldown côté PHP sur la sonde

Le verrou de bouton (déjà le motif d'UC04) protège du double-clic ; la sonde n'est plafonnée par aucun
limiteur de la lib ; et un cooldown empêcherait de re-tester **juste après avoir corrigé le problème**, ce qui
est l'usage même du bouton. Conséquence assumée et chiffrée en R-4.

## Server vs Client

**Tout le métier est serveur.** Le JS ne porte **aucune** règle métier, **aucun** code d'erreur, **aucun**
libellé d'état : il applique **4 scalaires déjà traduits** par le serveur (`etat`, `message`, `badge`,
`badgeClasse`).

Justification : les états dépendent de la configuration plugin (illisible depuis le navigateur sans la
divulguer), du démon et du cloud ; et les messages d'erreur du canal sont déjà traduits côté PHP par
`tableMessages()`. Dupliquer cette table en JS créerait une seconde source de vérité à maintenir, et le champ
`etat` n'est là que pour un usage éventuel ultérieur — **le JS n'en dérive aucun texte**.

Insertion **exclusivement** par `.text()`, jamais `.html()` : la réponse contient des messages d'erreur dont
une partie provient du démon.

## Contrats externes

Sources lues en `python-roborock` **7.8.0** : `roborock/web_api.py`, `roborock/data/containers.py` (raw
GitHub, tag `v7.8.0`). **Tous** les appels sont faits par la lib **dans le démon** ; aucun appel HTTP n'est
ajouté côté PHP.

### 1. Sonde de session — `RoborockApiClient._get_home_id(user_data)`

- `GET <baseUrl>/api/v1/getHomeDetail`, en-têtes `Authorization: <user_data.token>` + `header_clientid`.
- Réponse `{code: 200, data: {rrHomeId: <int>}}`.
- Erreurs : `code == 2010` → **`RoborockInvalidCredentials`** ; réponse nulle ou tout autre `code != 200` →
  `RoborockException` **nue** ; panne réseau → `RoborockException` nue avec cause chaînée (déjà gérée par
  `code_pour_exception`).
- ⚠️ **Cette méthode n'est protégée par AUCUN limiteur** de la lib : vérifié, `try_acquire` n'apparaît que
  dans `request_code*`, `pass_login` (limiteur `login`) et `get_home_data*` (limiteur `home_data`).
  **C'est le point qui rend AC4 réalisable.**
- ⚠️ **Méthode privée** (préfixe souligné) : couplage assumé à l'interne de la lib, épinglée en version
  exacte. Voir R-1.

### 2. Inventaire — `RoborockApiClient.get_home_data_v3(user_data)` (public)

- **Première instruction** : `if not self._home_data_limiter.try_acquire("home_data", blocking=False): raise
  RoborockRateLimit(...)` → un quota atteint est détecté **sans le moindre paquet réseau**.
- Puis `home_id = await self._get_home_id(user_data)` ; puis `GET <rriot.r.a>/v3/user/homes/<home_id>` signé
  **Hawk** ; `home_response["result"]` → `HomeData.from_dict(...)`.
- `_HOME_DATA_RATES = [1/s, 3/min, 5/h, 40/jour]` — attribut **de classe** ⇒ compteur **par processus**
  (remis à zéro à chaque redémarrage du démon ; c'est pourquoi le cache durable est côté PHP).
- `HomeData` : `id, name, products[], devices[], received_devices[], lon, lat, geo_name, rooms[]` + méthode
  publique **`get_all_devices()`** qui concatène `devices` et `received_devices`. C'est elle qui donne le
  nombre de robots — robots propres **et** robots partagés, tous deux visibles dans l'application.
- ⚠️ `HomeDataDevice` porte **`local_key`** : l'objet `HomeData` **ne doit jamais** quitter le démon. La
  coroutine ne renvoie **qu'un entier**.

### 3. Constructeur — `RoborockApiClient(username, base_url=None, session=None)`

- Passer `base_url` évite **intégralement** la découverte régionale (`_get_iot_login_info` → balayage
  séquentiel de 4 `POST getUrlByEmail`). Le getter est `if self._base_url is not None: return self._base_url`.
- ⚠️ **Piège fatal** : passer une **chaîne vide** au lieu de la valeur nulle renvoie une base d'URL vide et
  casse **toutes** les requêtes. Le démon doit écrire explicitement `base_url=(valeur or None)`. Voir R-8.

## Signatures

### `resources/demond/authentification.py` *(modifié)*

```
# imports a ajouter : import logging ; from erreurs import ErreurDemon, code_pour_exception

async def etat_compte(parametres, contexte) -> dict
#   parametres : userData (str base64, requis), baseUrl (str, peut etre vide),
#                email (str), avecInventaire (bool)
#   retour     : {"verifiee": True, "nbRobots": int | None, "quotaInventaire": bool}
#   leve       : ErreurDemon('NOT_AUTHENTICATED')  -> userData vide (defense en profondeur)
#                ErreurDemon('AUTH_EXPIRED')       -> blob illisible/incomplet (_decoder_user_data)
#                ErreurDemon('INTERNAL_ERROR')     -> _IMPORT_OK faux, ou sonde absente de la lib
#   les exceptions python-roborock remontent TELLES QUELLES a handler_rpc (point de mapping unique),
#   a la seule exception du refus du limiteur local d'inventaire (D-05-6)

def _client_compte(email, base_url) -> RoborockApiClient
#   RoborockApiClient(email, base_url=(base_url or None))  <- jamais une chaine vide

async def _sonder_session(client, user_data) -> None
#   garde hasattr/callable sur la sonde ; absente -> logging.error + INTERNAL_ERROR
#   await client._get_home_id(user_data) ; ne retourne rien, seule l'absence d'exception compte

def enregistrer_operations() -> None    # + canal.enregistrer("etatCompte", etat_compte)
```

Déroulé de `etat_compte`, **ordre imposé** :

1. `_IMPORT_OK` faux → `INTERNAL_ERROR`.
2. `userData` vide → `NOT_AUTHENTICATED`.
3. `user_data = _decoder_user_data(userData)` (contrôle `token`/`rriot`/`rriot.r` déjà en place).
4. `client = _client_compte(email, baseUrl)`.
5. Si `avecInventaire` : `home = await client.get_home_data_v3(user_data)` → `nb = len(home.get_all_devices())` ;
   sur refus **local** du limiteur → `quota = True` **puis** `await _sonder_session(...)`.
   Sinon : `await _sonder_session(...)`, `nb = None`.
6. Succès → session réamorcée en contexte (`userData` ré-encodé, `baseUrl` normalisée en chaîne, `email`) ;
   retour `{"verifiee": True, "nbRobots": nb, "quotaInventaire": quota}`.
   **Sur échec, la session en contexte est laissée telle quelle** (le PHP reste la source de vérité).

⚠️ **Boucle asyncio unique (R1 d'UC03) : aucun `asyncio.to_thread` nécessaire.** Les deux appels lib sont
asynchrones, et `try_acquire(..., blocking=False)` est une opération mémoire en temps constant sans attente —
c'est `blocking=False` qui le garantit, **ne jamais le passer à vrai**. **Aucun appel bloquant ne doit être
introduit ici.**

⚠️ `home` n'est **jamais** journalisé ni sérialisé : il porte les `local_key`. Voir R-10.

### `core/class/jeeroborock.class.php` *(modifié)*

```
const DUREE_CACHE_INVENTAIRE = 86400;                          // 24 h (arbitrage utilisateur)
const CLE_CACHE_INVENTAIRE   = 'jeeroborock::inventaireCompte';

public static function getEmailCompte()                        // -> string (vide si absent ou invalide)
public static function getInventaireCompte()                   // -> array('nbRobots','horodatage') | null
public static function enregistrerInventaireCompte($_nbRobots) // -> void ; NE LEVE JAMAIS
public static function oublierInventaireCompte()               // -> void ; NE LEVE JAMAIS
// modifiees : oublierSession() et enregistrerSession() appellent oublierInventaireCompte()
```

- **`getEmailCompte()`** : `trim((string) config::byKey('email', 'jeeroborock', ''))` +
  `FILTER_VALIDATE_EMAIL`, retourne une chaîne vide sinon. Solde la **dette explicite d'UC04** (« à extraire à
  la 3ᵉ occurrence ») : les deux `case` `demanderCode`/`validerCode` l'utilisent désormais, **en conservant
  mot pour mot leurs messages existants** (zéro churn i18n).
- **`getInventaireCompte()`** : `cache::byKey(self::CLE_CACHE_INVENTAIRE)->getValue('')` ; contrôle `is_array`
  + `isset($v['nbRobots'], $v['horodatage'])` + `intval >= 0` ; tout écart → valeur nulle.
  L'expiration est portée par le **moteur de cache du core** : une entrée expirée rend un objet cache **vide**,
  donc `getValue('')` vaut la chaîne vide. **Ne pas recalculer le TTL à la main** — c'est ce mécanisme qui
  porte AC4.
- **`enregistrerInventaireCompte()` / `oublierInventaireCompte()`** : `cache::set` / `cache::delete` sous
  `try/catch (Throwable)` avec `log::add(warning)` — un incident de cache ne doit **jamais** faire échouer un
  test ni, surtout, une persistance de session.
  - Dans `enregistrerSession()` : purge placée **après** les `config::save` et dans **son propre**
    `try/catch` — la méthode doit rester « ne lève jamais » (invariant AC5 d'UC04).
  - Dans `oublierSession()` (méthode **privée**, appelée uniquement en interne) : purge placée **avant** le
    `session_write_close()`.

### `core/class/jeeroborockDaemon.class.php` *(modifié — 1 ligne)*

```
const TIMEOUT_COMPTE = 20;   // s, etatCompte (1 a 2 requetes HTTPS nominales, 6 au pire)
```

Cascade **automatique**, aucune constante par opération dans `canal.py` : `appeler()` envoie
`budgetMs = timeout * 1000` ; `handler_rpc` calcule `asyncio.wait_for(..., (budget - MARGE_MS) / 1000)` avec
`MARGE_MS = 1000` ⇒ **démon 19 s < PHP 20 s < jQuery 30 s**. C'est le démon qui coupe le premier, donc
l'utilisateur voit `OPERATION_TIMEOUT` (message précis) et non un silence.

20 s reste très en dessous des `max_execution_time` / `fastcgi_read_timeout` usuels (R12 d'UC03), et sous le
`TIMEOUT_MAX = 60` auquel `appeler()` clampe. **C'est le seul plafond réel** : la lib ne pose aucun timeout
par requête.

## Server Actions / API

### `core/ajax/jeeroborock.ajax.php` *(modifié — 1 `case` + factorisation)*

Séquence inchangée : `isConnect('admin')` → `ajax::init()` → `session_write_close()` (déjà en place —
indispensable, l'appel peut durer 20 s) → `switch`. Endpoint **admin** assumé : l'action déclenche un appel
cloud imputé au quota du compte de l'utilisateur.

`case 'testerConnexion'` — 6 étapes :

1. `$email = jeeroborock::getEmailCompte();` — vide → `ajax::success(array('etat' => 'nonConfigure',
   'badge' => ..., 'badgeClasse' => 'label-default', 'message' => ...))`.
2. `!jeeroborock::estCompteLie()` → idem avec `'etat' => 'nonAuthentifie'`, `'badgeClasse' => 'label-default'`.
3. `$inventaire = jeeroborock::getInventaireCompte(); $avecInventaire = ($inventaire === null);`
4. `$r = jeeroborockDaemon::appeler('etatCompte', array('userData' => jeeroborock::getUserData(),
   'baseUrl' => jeeroborock::getBaseUrlCompte(), 'email' => $email, 'avecInventaire' => $avecInventaire),
   jeeroborockDaemon::TIMEOUT_COMPTE);` — **dans un `try/catch (jeeroborockException $e)` local** :
   - code `AUTH_EXPIRED` → `log::add(warning)` + `ajax::success(array('etat' => 'reauthentification',
     'badgeClasse' => 'label-warning', 'message' => $e->getMessage(), ...))` ;
   - code `NOT_AUTHENTICATED` → état `nonAuthentifie` ;
   - **sinon `throw`** — repris par le `catch` global du fichier → `ajax::error($e->getMessage())`, qui porte
     déjà les messages de quota, de cloud injoignable et de démon arrêté.
5. **Traçabilité d'AC4** — trois branches distinctes, à ne pas confondre :
   - `isset($r['nbRobots'])` et entier `>= 0` → `jeeroborock::enregistrerInventaireCompte(...)` +
     `log::add('jeeroborock', 'info', 'Inventaire du compte rafraîchi via homedata (quota 40/jour)')` ;
   - sinon, si `!empty($r['quotaInventaire'])` → `log::add('jeeroborock', 'info', 'Inventaire non rafraîchi :
     quota homedata local déjà atteint')` ;
   - sinon → `log::add('jeeroborock', 'debug', 'Inventaire servi depuis le cache, aucun appel homedata')`.

   > ⚠️ **Correction de revue (advisor)** : conditionner la 2ᵉ branche sur `quotaInventaire` est
   > **nécessaire**, pas cosmétique. Sans elle, le repli quota de D-05-6 journaliserait « servi depuis le
   > cache » alors qu'aucun cache n'a servi — ce qui rend les points de recette **R-4 et R-6
   > indistinguables**, or ce sont précisément les deux preuves d'AC4 et d'AC6.

6. Composition du message « authentifié », **3 variantes** : nombre connu (avec l'horodatage de l'inventaire) /
   quota d'inventaire atteint / nombre indisponible. Complété de `sprintf(__('Serveur du compte : %s',
   __FILE__), parse_url($baseUrl, PHP_URL_HOST))` quand la base d'URL est renseignée →
   `ajax::success(array('etat' => 'authentifie', 'badgeClasse' => 'label-success', ...))`.

   > ⚠️ **Correction de revue (advisor)** : `$baseUrl` provient de **`jeeroborock::getBaseUrlCompte()` côté
   > PHP**, jamais du retour du démon. `etat_compte` ne renvoie **que** `{verifiee, nbRobots,
   > quotaInventaire}` — **ne pas y ajouter de champ `baseUrl`** : la donnée est déjà disponible en PHP, et
   > élargir la table de retour érode l'invariant « rien de superflu ne transite par le canal ».

⚠️ **Réponse reconstruite champ par champ** — exactement 4 scalaires (`etat`, `message`, `badge`,
`badgeClasse`). `$r`, qui a transporté le `userData`, **ne repart jamais** vers le navigateur. Invariant
UC03/UC04 préservé.

### `plugin_info/configuration.txt` *(modifié, puis copie vers le `.php`)*

Dans le `form-group` « État du compte » **existant**, à côté de `#jeeroborockEtatCompte` :
`<a class="btn btn-default" id="bt_jeeroborockTesterConnexion">` + `<span id="jeeroborockResultatTest"></span>`.

Gestionnaire JS inline, **même squelette que `demanderCode`** :

- verrou **propre** `jeeroborockVerrouTest` — booléen **ET** `addClass('disabled')` : un lien stylé en bouton
  ignore `prop('disabled')` ;
- `timeout: 30000` ;
- gestionnaire `error` → littérale « Le démon ne répond pas. » **déjà présente dans le fichier**, à réutiliser,
  **ne pas la redéclarer** ;
- `success` :

```
si donnees.state != 'ok' : zone.text(donnees.result) ; STOP
sinon : zone.text(donnees.result.message)
        badge.text(donnees.result.badge)
             .removeClass('label-success label-default label-warning')
             .addClass(donnees.result.badgeClasse)
```

⚠️ **Correction de revue (advisor)** : les 2 nouvelles littérales JS traduisibles s'écrivent en **guillemets
doubles**, comme les 3 messages JS déjà présents dans ce fichier. Le script `verif-plugin.py` contrôle les
chaînes JS délimitées par apostrophes simples — ne pas laisser ce point à l'interprétation.

⚠️ Fichier **rendu** : aucune méta-séquence i18n littérale, **y compris dans le JS et les commentaires**.
Procédure obligatoire : `python .claude/scripts/verif-plugin.py` (colonne `meta=`) **avant commit**, puis
`cp plugin_info/configuration.txt plugin_info/configuration.php`, contrôle par
`git status --short plugin_info/configuration.php`. **Ne jamais relire le `.php`.**

## Validation

| Quoi | Où (autoritaire) | Comportement / message |
|---|---|---|
| e-mail du compte | **serveur**, `getEmailCompte()` | vide/invalide → « non configuré », **sans appeler le démon** |
| compte lié | **serveur**, `estCompteLie()` | faux → « non authentifié », **sans appeler le démon** |
| `userData` transmis | démon, `_decoder_user_data` (contrôle `token`/`rriot`/`rriot.r`) | `AUTH_EXPIRED` → « ré-authentification requise » |
| `userData` vide | démon (défense en profondeur) | `NOT_AUTHENTICATED` → « non authentifié » |
| validité de session | **cloud Roborock** (`code:2010`) | `RoborockInvalidCredentials` → `AUTH_EXPIRED` |
| quota d'inventaire (local) | démon, limiteur de la lib | rattrapé (D-05-6) → « authentifié, inventaire indisponible (quota) » |
| quota serveur | cloud | `RATE_LIMIT_REMOTE` → `ajax::error` avec le message FR existant |
| `nbRobots` reçu du démon | PHP (`isset` + `intval >= 0`) | non conforme → traité comme « indisponible », **jamais mis en cache** |
| entrée de cache | PHP (`is_array` + clés + `intval`) | non conforme → nulle ⇒ rafraîchissement |
| budget de temps | démon 19 s < PHP 20 s < jQuery 30 s | `OPERATION_TIMEOUT` puis `DAEMON_TIMEOUT` en dernier recours |
| droits | `isConnect('admin')` du fichier AJAX | endpoint **admin** |

### Codes d'erreur stables

**AUCUN nouveau.** Tous réutilisés tels quels : `AUTH_EXPIRED`, `NOT_AUTHENTICATED`, `RATE_LIMIT`,
`RATE_LIMIT_REMOTE`, `CLOUD_UNREACHABLE`, `CLOUD_REGION_UNKNOWN`, `ROBOROCK_ERROR`, `OPERATION_TIMEOUT`,
`INTERNAL_ERROR`, plus la famille A/B du canal.

⇒ `jeeroborockDaemon::tableMessages()` **et** `jeeroborockException::estErreurCanal()` ne sont **ni l'un ni
l'autre** modifiés : **le piège de la liste dupliquée ne s'applique pas à cette UC.**

### Secrets

Le `userData` transite dans le **tableau de paramètres**, jamais en paramètre scalaire — ce qui protège même
d'une trace PHP brute (les arguments de frame). `appeler()` ne journalise que les **noms** de clés. La réponse
AJAX est reconstruite champ par champ. Aucun `displayException()`. Les `catch` ne journalisent que le message.
`local_key` et `HomeData` **ne quittent jamais le démon**.

## Dépendances

**Aucune.** `python-roborock` reste épinglé en **7.8.0** ; `packages.json` n'est pas modifié. Aucune
bibliothèque JS ajoutée.

## Impact i18n (français uniquement dans cette UC)

`core/i18n/*.json` **NON touchés** — traduction déléguée au sous-agent `translator` en fin de cycle, sur le
code figé. **12 littérales françaises nouvelles**, toutes en chaînes **littérales** (jamais de variable dans
la fonction de traduction ; `sprintf` **autour** de l'appel, jamais l'inverse).

**`core/ajax/jeeroborock.ajax.php` — 10**

1. « Non configuré » *(badge)*
2. « Aucune adresse e-mail n'est renseignée : saisissez l'adresse du compte Roborock dans cette page et enregistrez la configuration. »
3. « Compte Roborock non lié » *(badge)*
4. « Le compte Roborock n'est pas lié : demandez un code de connexion pour authentifier le compte. »
5. « Compte Roborock lié » *(badge)*
6. « Authentifié — %1$s robot(s) détecté(s) (inventaire du %2$s) »
7. « Authentifié — le nombre de robots n'a pas pu être relevé : le quota d'inventaire Roborock est atteint, réessayez plus tard. »
8. « Authentifié — le nombre de robots n'a pas pu être relevé. »
9. « Ré-authentification requise » *(badge)*
10. « Serveur du compte : %s »

⚠️ **À signaler au `translator`** : les items 3 et 5 existent **déjà** dans `plugin_info/configuration.txt`.
Les fichiers i18n étant indexés **par fichier**, ils doivent exister **aussi** sous l'entrée
`core/ajax/jeeroborock.ajax.php`. **Ce n'est pas un doublon à factoriser** — même situation qu'en UC03 pour le
message d'e-mail invalide.

**`plugin_info/configuration.txt` — 2** *(guillemets doubles, cf. correction de revue)*

11. « Tester la connexion » *(bouton)*
12. « Test en cours… »

⚠️ **Non comptée, déjà présente** : « Le démon ne répond pas. » — réutilisée par le gestionnaire `error`,
**ne pas la redéclarer**. Le message de l'état « ré-authentification requise » **réutilise** `AUTH_EXPIRED`
via `getMessage()` ⇒ **aucune littérale nouvelle**.

Messages `log::add` et logs du démon : français **non enveloppé**. Codes stables : **anglais**.

## Risques & pièges

- **R-1 (majeur) — la sonde est une méthode privée de la lib.** C'est le prix d'AC4 : c'est la **seule**
  requête authentifiée de `web_api.py` qui ne consomme pas le limiteur `home_data`. Garde `hasattr`/`callable`
  + `logging.error` explicite + `INTERNAL_ERROR` si elle disparaît — **jamais** de repli silencieux sur
  `get_home_data_v3`, qui brûlerait du quota à l'insu de l'utilisateur. **À revérifier à chaque montée de
  version de `python-roborock`.**
- **R-2 (majeur, touche AC5) — seul le code `2010` est mappé en `RoborockInvalidCredentials`.** Tout autre code
  de refus d'authentification renvoyé par `getHomeDetail` devient une `RoborockException` nue →
  `ROBOROCK_ERROR` (« Erreur Roborock non identifiée »), et l'utilisateur **ne verra pas** « ré-authentification
  requise ». Non corrigeable sans **parser un message anglais de la lib**, ce qui est interdit. **À lever en
  recette** (R-5) : si le cas se produit, relever le code réel dans le log du démon et l'ajouter au mapping.
- **R-3 (majeur) — `header_clientid` est régénéré à chaque instanciation.** Hypothèse retenue, **alignée sur
  Home Assistant** qui crée un client neuf à chaque démarrage : le serveur ne lie pas le jeton au `clientid`
  sur `getHomeDetail`/`homedata`. Si l'hypothèse est fausse, le test afficherait un « ré-authentification
  requise » **mensonger** au premier test suivant un redémarrage du démon. **À confirmer sur le compte réel**
  (recette R-10).
- **R-4 — aucun garde-fou de cadence sur la sonde** (D-05-9) : 30 clics = 30 requêtes `getHomeDetail`. Aucun
  limiteur de la lib ne s'y oppose ; l'existence d'un quota **serveur** sur cet endpoint est **inconnue**.
  Verrou de bouton seul. Si la recette révèle un refus serveur, la parade est un cooldown de cache court côté
  PHP (15-30 s) — **pas** une retentative.
- **R-5 — le compteur du limiteur de la lib est par processus** : redémarrer le démon le remet à zéro alors
  que le quota serveur court. C'est précisément pourquoi le cache durable est côté PHP (D-05-1) ; la
  protection reste **best-effort** face à l'application mobile, qui consomme le même quota sans que le plugin
  le sache.
- **R-6 (renforcé par le TTL 24 h) — inventaire périmé jusqu'à 24 h.** Conséquence **assumée** d'AC4 et de
  l'arbitrage utilisateur. Atténuation **obligatoire** : l'horodatage est affiché dans le message d'état.
  **Contrainte ferme pour UC06** : la découverte **devra** rafraîchir ou invalider
  `jeeroborock::inventaireCompte`, faute de quoi le test et la liste des équipements se contrediront pendant
  une journée entière.
- **R-7 — affirmation de `CLAUDE.md` à confirmer avant toute correction.** `CLAUDE.md` indique « États
  volatils : cache **chiffré** via la classe `cache` ». Une lecture de `core/class/cache.class.php` (branche
  alpha) semble montrer un `serialize()`/`unserialize()` **sans chiffrement**. ⚠️ **Non vérifiable depuis ce
  dépôt** (fichier du core Jeedom, hors plugin) — la revue advisor n'a pas pu le confirmer. Cette UC est
  **insensible à l'écart** (l'entrée ne contient qu'un entier et un horodatage, aucun secret). **Ne pas
  modifier `CLAUDE.md` sur cette seule base** ; à confirmer de source sûre avant qu'une UC ultérieure ne range
  un secret dans le cache.
- **R-8 — base d'URL vide au lieu de la valeur nulle** : le getter retourne toute valeur non nulle, chaîne
  vide comprise → toutes les requêtes cassent avec un symptôme obscur. Écrire explicitement
  `base_url=(valeur or None)`.
- **R-9 — budget de temps** : si `baseUrl` est vide (dégradation d'UC04), l'appel enchaîne jusqu'à 4
  `POST getUrlByEmail` **puis** 1 à 2 GET, **sans aucun timeout par requête dans la lib** →
  `OPERATION_TIMEOUT` possible à 19 s. Nominal : 1 à 2 requêtes. Seul le budget global borne l'attente.
- **R-10 — `HomeData` porte les `local_key`** : la coroutine ne retourne **qu'un entier**, et l'objet ne doit
  apparaître dans aucun `logging`. Attention à `exc_info=True`, qui imprime la trace mais **pas** la valeur
  des variables — ne pas ajouter de journalisation de `home`.
- **R-11 — le test ne qualifie que le cloud, pas la joignabilité du robot** (canal MQTT) : un robot hors ligne
  donnera « authentifié ». Conforme au périmètre (UC10 pour le temps réel), **à ne pas confondre avec un bug
  en recette**.
- **R-12 — désynchronisation `configuration.txt`/`.php` et méta-séquences** (rappels UC03/UC04) :
  `verif-plugin.py` puis la copie, contrôle par `git status --short`.
- **R-13 — contrainte sur l'avenir** : UC06 aura besoin du `HomeData` **complet**. S'il rappelle `homedata`
  juste après un test, deux appels seront consommés en quelques minutes. Ne rien anticiper aujourd'hui, mais
  UC06 devra trancher entre « réutiliser le cache PHP enrichi » et « conserver le `HomeData` dans le contexte
  du démon » (à croiser avec R-6).

## Recette (à confirmer sur une Jeedom réelle, Debian 12+)

| # | AC | Vérification | Attendu |
|---|---|---|---|
| R-1 | AC1 | plugin neuf, champ e-mail vide, **démon arrêté**, clic « Tester la connexion » | « Non configuré » **immédiatement**, aucune ligne d'appel démon dans le log |
| R-2 | AC2 | e-mail valide enregistré, compte non lié, **démon arrêté** | « Non authentifié », badge `label-default`, toujours aucun appel démon |
| R-3 | AC3 | juste après une authentification UC04 réussie, premier test | « Authentifié — N robot(s) détecté(s) (inventaire du …) » + « Serveur du compte : … » ; log `info` « Inventaire du compte rafraîchi » ; **N conforme à l'application mobile, robots partagés inclus** |
| R-4 | **AC4** | niveau de log `info`, re-cliquer 5 fois dans la foulée | **aucune** nouvelle ligne « rafraîchi via homedata » ; en `debug`, la ligne « servi depuis le cache » ; côté démon, **aucune** requête `/v3/user/homes/` supplémentaire |
| R-5 | **AC5** | révoquer l'appareil/la session depuis l'application mobile, puis tester | « Session Roborock expirée… », badge `label-warning` « Ré-authentification requise », **aucune** nouvelle demande de code. Si le message est « Erreur Roborock non identifiée », relever le code réel dans le log du démon (R-2) |
| R-6 | **AC6** | forcer le quota d'inventaire (6 rafraîchissements en moins d'1 h, cache purgé entre-temps) | « quota d'inventaire Roborock est atteint » **et** l'état reste « Authentifié » ; log `info` « quota homedata local déjà atteint » (**et non** « servi depuis le cache ») ; **aucune** requête réseau supplémentaire |
| R-7 | D-05-4 | changer l'e-mail du compte, enregistrer, puis tester | « Non authentifié » ; après re-liaison, le **premier** test rappelle `homedata` (cache purgé) |
| R-8 | AC5 / secrets | log en `debug`, rejouer tout le scénario | ni blob base64, ni `local_key`, ni jeton dans `log/jeeroborock`, `log/jeeroborock_demon`, `log/php` ; onglet réseau du navigateur **sans** `userData` |
| R-9 | canal | démon arrêté, compte lié, clic « Tester » | « Le démon ne répond pas… » en moins de 5 s, interface Jeedom fluide pendant l'appel |
| R-10 | R-3 | redémarrer le démon puis tester immédiatement | « Authentifié » sans ré-authentification (confirme que `header_clientid` n'est pas contraignant hors login) |

## Dette

- **Duplication du bloc de réponse « non authentifié »** (`core/ajax/jeeroborock.ajax.php`) : le même
  quadruplet `etat`/`message`/`badge`/`badgeClasse` est écrit deux fois — à l'étape 2 (`!estCompteLie()`) et
  dans le `catch` du code `NOT_AUTHENTICATED`. Signalé en review qualité (`nit`), **non corrigé
  volontairement** : extraire un helper dans un point d'entrée AJAX procédural coûterait plus en churn qu'il
  ne rapporte, et les deux occurrences portent un texte identique (donc **aucun** doublon de clé i18n). À
  revoir si une 3ᵉ occurrence apparaît.
- **Helpers de session Python à promouvoir** : dès qu'UC06 créera son module,
  `_decoder_user_data`/`_encoder_user_data` devront être déplacés dans un module de session partagé — **ne pas
  les importer depuis `authentification` sous leur nom privé** (c'est le motif qui a justifié D-05-7).
- **R-7 à trancher de source sûre** : confirmer si la classe `cache` du core chiffre ou non, puis corriger
  `CLAUDE.md` si l'écart est réel. Bloquant **avant** toute UC qui voudrait ranger un secret dans le cache.
- **R-2** : enrichir le mapping des codes de refus d'authentification si la recette en révèle d'autres que
  `2010`.
