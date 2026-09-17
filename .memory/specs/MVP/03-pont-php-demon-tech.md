# Spec technique — UC03 : Pont PHP↔démon

> **Spec fonctionnelle** : `.memory/specs/MVP/03-pont-php-demon.md` · **Dépend de** : UC02
> **Statut** : plan validé le 2026-09-17 (challenge advisor intégré)

## Périmètre

UC03 livre **le tuyau, sa sécurité, son budget de temps et son dictionnaire d'erreurs** — pas une seule
opération métier Roborock. Concrètement : deux classes PHP autochargées (`jeeroborockDaemon`,
`jeeroborockException`), un routage d'opérations extensible côté démon, et la table de traduction
« code d'erreur stable → message français ».

Tout ce que les UC04→09 auront à faire pour ajouter une opération : `canal.enregistrer('<nom>', <coroutine>)`
côté Python, `jeeroborockDaemon::appeler('<nom>', array(...))` côté PHP. **Aucune nouvelle route HTTP,
aucun nouveau code de transport.**

### Couverture des critères d'acceptation

| AC | Réalisé par | Statut |
|---|---|---|
| **AC1** — réponse bornée, ni blocage indéfini ni timeout muet | Budget à double détente : le PHP coupe à `budget` (`CURLOPT_TIMEOUT_MS`), le démon coupe à `budget − MARGE_BUDGET_MS` via `asyncio.wait_for` et répond `OPERATION_TIMEOUT` **avant** la coupure PHP → message précis au lieu d'un silence. Plafond dur `TIMEOUT_MAX = 60 s` appliqué par `appeler()` quel que soit l'appelant. `session_write_close()` dans le handler AJAX avant l'appel. Recettable par le bouton « Vérifier le canal » (D-f). | couvert |
| **AC2** — requête sans jeton / jeton invalide rejetée, même en local | Middleware `verifier_apikey` **déjà en place** (UC02, `hmac.compare_digest`, 401), **global** sur l'`Application` : il couvre `/rpc` sans code supplémentaire. UC03 garantit qu'il s'exécute **avant** tout parsing de corps et que le 401 porte l'enveloppe normalisée. | couvert (recette R-1) |
| **AC3** — accès depuis une autre machine impossible | `ADRESSE_ECOUTE = "127.0.0.1"` **déjà en place** (UC02) ; UC03 n'ouvre aucune nouvelle socket. Rien à coder. | couvert par l'existant (recette R-2) |
| **AC4** — chaque catégorie d'erreur Roborock → message FR distinct | Table « exception lib → code stable » côté démon (`erreurs.py`, mapping par MRO sur le **nom** de classe) + table « code stable → littérale `__()` » côté PHP (`tableMessages()`). 23 exceptions de `python-roborock` 7.8.0 + 4 états propres au démon. Le démon ne renvoie **jamais** `str(exception)`. | couvert |
| **AC5** — démon injoignable ≠ refus du cloud | Familles de codes **disjointes par construction** : `DAEMON_UNREACHABLE` / `DAEMON_TIMEOUT` / `DAEMON_INVALID_RESPONSE` sont produits **par le PHP sur échec de transport** et ne peuvent jamais résulter d'une réponse du démon. `estErreurCanal()` expose la distinction aux appelants. | couvert |
| **AC6** — aucun secret en log ni dans une réponse visible | Cinq mesures structurelles cumulées (§ Validation & erreurs). | couvert (structurel) |

Le point « À confirmer » de la spec fonctionnelle (liste exhaustive des codes d'erreur) est **consolidé et
clos** par ce document, sur la base de la lecture de `roborock/exceptions.py` v7.8.0.

### Briques volontairement non créées en UC03

- **Aucune nouvelle clé de configuration.** Les budgets de temps sont des constantes de classe (D-e).
- **`core/php/jeeroborock.inc.php` n'est pas touché** : l'autoloader du core résout
  `glob('plugins/*/core/class/<NomClasse>.class.php')` ; les deux nouvelles classes ont chacune leur
  fichier au nom exact, donc elles sont trouvables depuis n'importe quel point d'entrée externe. Ajouter
  des `require_once` dans `inc.php` serait du code mort — il n'est inclus automatiquement par personne.
- **Aucune dépendance nouvelle** : `aiohttp` est déjà une dépendance transitive directe de
  `python-roborock`. `packages.json` reste inchangé.
- **Le push démon → Jeedom reste hors périmètre** (post-MVP `05-temps-reel-et-robustesse`) :
  `core/php/jeeJeeroborock.php` n'est pas modifié.
- **Aucune retentative, aucun cache de réponse** : un retry consommerait un quota Roborock dur.

---

## Architecture

### Fichiers

| Fichier | État | Contenu | Indentation / EOL |
|---|---|---|---|
| `core/class/jeeroborockException.class.php` | **créé** | `class jeeroborockException extends Exception` : code stable en propriété dédiée, `getCodeErreur()`, `estErreurCanal()` | 2 espaces, CRLF |
| `core/class/jeeroborockDaemon.class.php` | **créé** | brique unique d'accès au démon : `appeler()`, `sante()`, transport cURL, interprétation de l'enveloppe, table des messages FR | 2 espaces, CRLF |
| `core/ajax/jeeroborock.ajax.php` | **modifié** | action `santeCanal`, `session_write_close()`, `catch (jeeroborockException)` + `catch (Throwable)`, **suppression de `displayException()`** | **2 espaces, CRLF — normalisation intégrale du fichier**, voir ci-dessous |
| `plugin_info/configuration.txt` | **modifié** | bouton « Vérifier le canal », zone de résultat, JS inline | 2 espaces, CRLF |
| `plugin_info/configuration.php` | **régénéré par `cp`, jamais édité** | copie conforme du `.txt` | idem |
| `resources/demond/erreurs.py` | **créé** | `ErreurDemon`, `TABLE_CODES`, `code_pour_exception()` | 4 espaces, **LF** |
| `resources/demond/canal.py` | **créé** | registre d'opérations, middlewares, `handler_rpc`, `handler_sante`, budget | 4 espaces, **LF** |
| `resources/demond/jeeroborockd.py` | **modifié** | délègue la construction de l'application à `canal.py` ; `handler_sante` / `verifier_apikey` / `construire_application` **déplacés** (pas dupliqués) | 4 espaces, **LF** |

#### ⚠️ Indentation de `core/ajax/jeeroborock.ajax.php` — point tranché

L'état livré par le squelette est **incohérent** : le code fonctionnel est en 4 espaces, mais le
commentaire des lignes 26-29 est déjà en 2 espaces. `CLAUDE.md` ne prévoit **qu'une seule** exception à la
règle des 2 espaces, et c'est `desktop/php/*.php` (tabulations + CRLF) — `core/ajax` y est nommément listé
en 2 espaces.

**Décision** : le fichier est **intégralement normalisé à 2 espaces** au passage. Le bloc `try` est quasi
vide (une seule instruction utile), la modification est donc une réécriture, pas une retouche : il n'y a
aucun intérêt à propager une indentation contraire à la convention documentée. **Interdiction absolue de
produire un fichier à indentation mixte** — c'est le vrai risque signalé par le challenge advisor.

#### ⚠️ Casse du nom de fichier de classe

`jeeroborockDaemon.class.php` et `jeeroborockException.class.php` doivent être committés avec **cette casse
exacte**. Le `glob` de l'autoloader est sensible à la casse sous Linux, et `core.ignorecase` sous Windows
peut masquer une casse fautive dans l'index git. Symptôme d'une erreur : `Fatal error: Class not found` au
runtime, **invisible à `php -l` et à la CI**. Contrôle : `git ls-files core/class/`.

### Décisions d'architecture

- **D-a — Erreur d'opération en HTTP 200, erreur de canal en 4xx/5xx.** Le PHP applique une règle unique :
  *corps JSON conforme → le `code` fait foi ; pas de corps exploitable → le statut HTTP fait foi.*
  Alternative écartée : 502/504 pour les erreurs Roborock, qui mélangerait une panne d'infrastructure et un
  refus applicatif et rendrait le diagnostic `curl` ambigu. Conséquence directe sur AC5 : « démon
  injoignable » ne peut pas être confondu avec une erreur métier, puisqu'il n'y a alors **aucune** réponse
  HTTP du tout.
- **D-b — Le port vient de `jeeroborock::getPortDemonHttp()`, pas du fichier `demon.port`.** Si la
  configuration a changé et que le démon tourne encore sur l'ancien port, `postConfig_portDemonHttp`
  (UC02) vient précisément de décider de le tuer : lire `demon.port` ferait dialoguer avec un démon
  condamné. L'échec transitoire (le temps que `plugin::checkDeamon` relance, < 1 min) produit
  `DAEMON_UNREACHABLE`, dont le message est exactement le bon.
- **D-c — Apikey en en-tête `X-Apikey`, pas en paramètre.** (i) Elle n'entre jamais dans une URL, donc
  jamais dans un journal d'accès si celui-ci était réactivé pour déboguer, ni dans un `Referer`, ni dans
  l'historique d'un `curl` copié-collé ; (ii) c'est déjà l'implémentation UC02, aucune régression ;
  (iii) comparaison en temps constant côté démon. **Asymétrie assumée** avec le callback démon → Jeedom,
  qui utilise la query string parce que c'est le contrat imposé par le core.
- **D-d — cURL direct, pas `file_get_contents` ni `com_http`.** `file_get_contents` + contexte de flux ne
  distingue pas « connexion refusée » de « timeout » — or AC5 l'exige —, ignore la notion de timeout de
  connexion séparé et hérite des variables d'environnement de proxy. `com_http` masque `curl_errno` et
  ajoute une dépendance à un contrat de classe susceptible d'évoluer. cURL direct permet
  `CURLOPT_PROXY => ''`, des timeouts distincts, et le mapping fin `CURLE_COULDNT_CONNECT (7)` →
  `DAEMON_UNREACHABLE` vs `CURLE_OPERATION_TIMEDOUT (28)` → `DAEMON_TIMEOUT`. L'extension cURL est un
  prérequis de Jeedom ; un garde `function_exists('curl_init')` lève néanmoins `INTERNAL_ERROR` plutôt que
  de produire un fatal.
- **D-e — Aucun timeout configurable par l'utilisateur.** Le budget dépend de l'**opération**, pas de
  l'installation : personne ne sait choisir « 12 s ». Un champ de plus dans la page de config serait une
  dette d'UI. Les valeurs vivent en constantes de classe ; l'appelant passe un budget explicite pour les
  opérations longues (login, découverte).
- **D-f — UC03 livre un bouton de diagnostic « Vérifier le canal ».** Sans consommateur, AC1 et AC5 ne
  sont pas recettables au moment de la livraison. Le bouton teste le **canal local** (`GET /sante`) ; il ne
  double pas le « Tester la connexion » d'UC05, qui testera le **lien au compte Roborock**. Vérifié :
  `configuration.txt` ne contient aujourd'hui aucun mécanisme de diagnostic. Il reste ensuite utile comme
  outil de support.
- **D-g — Le routage et le mapping d'erreurs sont extraits dans deux modules Python.** `jeeroborockd.py`
  fait déjà 224 lignes et va accueillir tout le métier d'UC04→09. Ce n'est pas un sur-périmètre : le
  rework est **explicitement anticipé par UC02 lui-même** (R12 de `02-dependances-et-demon-tech.md`).
  Contrepartie assumée : un déplacement de fonction peut introduire une régression subtile (oubli d'un
  paramètre dans une signature, changement d'ordre des middlewares) → point de recette R-8 dédié.
- **D-h — Mapping d'exception par parcours du MRO sur le *nom* de classe, sans importer
  `roborock.exceptions`.** Si une future version de la librairie renomme ou supprime une exception, le
  démon ne casse pas à l'import : l'exception retombe sur `RoborockException` (toujours présente dans son
  MRO) → `ROBOROCK_ERROR`. Alternative écartée : un dict `{ClasseImportée: code}`, qui transforme un
  renommage amont en `ImportError` fatal au démarrage du démon.

---

## Server vs Client

**Tout est serveur.** Le seul code client introduit est le JS du bouton « Vérifier le canal » : il émet un
appel AJAX vers `core/ajax/jeeroborock.ajax.php` et **affiche** le résultat. Il ne contient aucune règle
métier, ne connaît ni le port du démon, ni l'apikey, ni les codes d'erreur — il reçoit un message déjà
traduit et une poignée de champs scalaires.

Le JS est **inline dans `configuration.txt`** et non déporté dans un `.js` : c'est la seule façon pour que
ses libellés passent par le moteur i18n du core (un fichier servi par balise `script src` n'est pas
traduit). Repli documenté si une CSP l'interdisait : R7.

---

## Contrat du canal (figé ici, consommé par UC04→09)

**Aucun appel vers l'extérieur de la machine n'est introduit par UC03.** Le seul contrat réseau est le
canal loopback PHP ↔ démon.

| Élément | Valeur | Justification |
|---|---|---|
| URL | `http://127.0.0.1:<port>/rpc` — **adresse littérale IPv4**, jamais `localhost` | `localhost` peut résoudre en `::1` alors que le démon n'écoute qu'en IPv4 → refus de connexion intermittent selon `/etc/hosts` |
| Port | `jeeroborock::getPortDemonHttp()` | D-b |
| Méthode | **POST** uniquement sur `/rpc` ; `GET /sante` conservé d'UC02 | un POST unique fige le transport : les UC04+ n'ajoutent qu'un nom d'opération |
| Authentification | en-tête **`X-Apikey`** | D-c |
| En-têtes | `Content-Type: application/json`, `Expect:` vidé | un `Expect: 100-continue` sur un corps > 1 Ko coûte 1 s d'attente |
| Corps requête | `{"operation": "<nom>", "parametres": {...}, "budgetMs": <int>}` | |
| Réponse succès | **HTTP 200** + `{"success": true, "data": <objet ou null>}` | |
| Réponse erreur **d'opération** | **HTTP 200** + `{"success": false, "error": {"code": "<CODE_STABLE>", "message": "<libellé technique court>", "detail": <objet ou null>}}` | D-a |
| Réponse erreur **de canal** | HTTP **401** (apikey), **400** (JSON invalide, `operation` absente, corps trop gros), **404** (opération inconnue), **500** (exception non rattrapée) — **même enveloppe** | D-a |
| `error.message` | nom de la classe d'exception ou identifiant fixe — **jamais** `str(exception)`, **jamais affiché** | le PHP ne doit avoir aucune chaîne anglaise affichable, et `str(exception)` d'une librairie peut contenir une URL signée |
| `error.detail` | objet optionnel de valeurs **numériques/booléennes seulement** | aucune chaîne libre ne traverse le canal vers un `message::add()`, qui est rendu en HTML |
| Journal d'accès aiohttp | reste **désactivé** (`access_log=None`, UC02) | les corps transporteront un code e-mail dès UC04 |
| Taille max de corps | `client_max_size = 256 Ko` | défense en profondeur |

**Vérifié contre le code réel d'UC02** (`resources/demond/jeeroborockd.py`) : `X-Apikey`,
`hmac.compare_digest`, l'enveloppe `{success, error:{code, message}}` sur le 401, `{success, data}` sur
`/sante`, la route `GET /sante`, `ADRESSE_ECOUTE = "127.0.0.1"`, le middleware **global** et
`access_log=None` sont tous en place et sous cette forme. UC03 les reprend **sans les modifier**.

### Contrat amont référencé (non appelé en UC03)

Exceptions de `python-roborock` **7.8.0**, source : `roborock/exceptions.py` v7.8.0 — **23 classes**, toutes
dérivées de `RoborockException`. Cela **complète** `.memory/analyse/jeeroborock-cloud-api.md` § 8, qui en
listait 18 (omises : `RoborockBackoffException`, `UnknownMethodError`, `RoborockUrlException`,
`RoborockConnectionException`, et `RoborockException` elle-même). Aucune contradiction de fond : l'analyse
donnait une liste explicitement partielle.

---

## Signatures

### `core/class/jeeroborockException.class.php`

```php
class jeeroborockException extends Exception {
  public function __construct($_codeErreur, $_message, $_precedente = null)
  public function getCodeErreur()    // -> string : code stable (ex. 'RATE_LIMIT')
  public function estErreurCanal()   // -> bool
}
```

⚠️ Le code stable est une **chaîne** : il ne peut donc pas passer par `Exception::getCode()`, dont le
constructeur déclare `int $code` (TypeError en PHP 7+). D'où la propriété dédiée.

`getMessage()` porte **le message français déjà traduit**, prêt à être affiché : les appelants UC04→09
n'ont aucune traduction à faire.

#### `estErreurCanal()` — docblock obligatoire

La méthode renvoie `true` pour **deux familles de nature différente** : les codes de transport (`DAEMON_*`,
démon réellement injoignable) **et** les codes de protocole émis par un démon **qui répond bien**
(`UNAUTHORIZED`, `BAD_REQUEST`, `UNKNOWN_OPERATION`, `INTERNAL_ERROR`).

Le nom reste `estErreurCanal()` — il est sémantiquement juste : « erreur du canal plugin↔démon », par
opposition à « refus du cloud Roborock ou du robot ». Mais il **doit** porter un docblock explicite :

> `true` signifie « le problème est entre le plugin et le démon », **pas** « le démon est arrêté ». Un
> `UNAUTHORIZED` provient d'un démon parfaitement vivant. Ne jamais dériver de cette méthode un message
> du type « démarrez le démon » : utiliser le message porté par l'exception, qui est déjà le bon.

### `core/class/jeeroborockDaemon.class.php`

```php
const TIMEOUT_DEFAUT       = 10;    // s, budget d'une opération courante
const TIMEOUT_SANTE        = 3;     // s, /sante ne fait aucune I/O
const TIMEOUT_MAX          = 60;    // s, plafond dur — garantit AC1 quel que soit l'appelant
const TIMEOUT_CONNEXION_MS = 2000;  // ms, connexion loopback
const MARGE_BUDGET_MS      = 1000;  // ms laissées au démon pour sérialiser sa réponse

public  static function appeler($_operation, $_parametres = array(), $_timeout = self::TIMEOUT_DEFAUT)
        // -> array (contenu de data, array() si data null) ; throws jeeroborockException
public  static function sante()
        // -> array('version','majeureValidee','avertissementVersion','callback','pid','dureeFonctionnement')
        // ; throws jeeroborockException
private static function urlBase()                    // -> string 'http://127.0.0.1:<port>'
private static function executerRequete($_methode, $_chemin, $_corpsJson, $_timeout)
        // -> array('httpCode' => int, 'corps' => string) ; throws jeeroborockException (transport)
private static function interpreterReponse($_httpCode, $_corps, $_operation)
        // -> array ; throws jeeroborockException
private static function lever($_codeErreur, $_operation = '')   // fabrique : code -> message FR -> exception
private static function tableMessages()              // -> array code => littérale __()
```

Responsabilités :

- **`appeler()`** : borne le budget à `[1, TIMEOUT_MAX]`, construit `{"operation","parametres","budgetMs"}`
  avec `budgetMs = $_timeout * 1000`, délègue le transport, interprète l'enveloppe, journalise.
  **Retourne toujours un tableau** : un `data` scalaire lève `DAEMON_INVALID_RESPONSE`, pour que l'appelant
  n'ait jamais à tester le type.
- **`sante()`** : `GET /sante`, budget `TIMEOUT_SANTE`, même chemin d'interprétation.
- **`executerRequete()`** : `curl_init` / `curl_setopt_array` / `curl_exec`. Options notables :
  `CURLOPT_RETURNTRANSFER`, **`CURLOPT_PROXY => ''`** (R2), `CURLOPT_CONNECTTIMEOUT_MS`,
  `CURLOPT_TIMEOUT_MS`, `CURLOPT_HTTPHEADER` (`X-Apikey`, `Content-Type`, `Expect:`). **Pas** de
  `CURLOPT_FAILONERROR` : on veut lire le corps des 4xx. `curl_close` sur **tous** les chemins.
- **`tableMessages()`** : table `code => __('littérale française', __FILE__)`. **Jamais `__($variable)`** —
  l'extraction i18n est un scan statique.
- **Aucun cache statique de réponse, aucune retentative**, même sur `DAEMON_UNREACHABLE`.

#### Pourquoi `tableMessages()` ne viole pas l'invariant « pas de libellé en dur »

L'invariant du projet proscrit de figer en PHP le **vocabulaire d'état ou d'erreur du robot**, qui dépend
du modèle et du firmware — ce vocabulaire doit venir du démon. `tableMessages()` ne fait rien de tel :

- elle traduit une **taxonomie stable de la librairie** (noms de classes d'exception de `python-roborock`,
  épinglée en version exacte `7.8.0`) et des **codes de protocole du canal** propres au plugin ;
- elle ne contient **aucun** libellé d'état robot : `VacuumError` et `CommandVacuumError` retombent
  justement sur un message générique qui renvoie l'utilisateur vers l'application mobile, précisément
  parce que le libellé précis dépend du firmware ;
- `str(exception)` n'est jamais affiché, donc aucun texte de la librairie ne fuit vers l'UI.

Ce paragraphe est là pour qu'une review ultérieure ne signale pas ce point à tort.

### `resources/demond/erreurs.py`

```python
class ErreurDemon(Exception):
    def __init__(self, code, detail=None)     # code stable, detail = dict de scalaires numeriques
    code: str
    detail: dict | None

TABLE_CODES: dict[str, str]                   # nom de classe d'exception -> code stable
CODE_DEFAUT = "INTERNAL_ERROR"

def code_pour_exception(exc) -> tuple[str, str]
    """Parcourt type(exc).__mro__ et retourne (code_stable, nom_de_classe). Ne leve jamais."""
```

### `resources/demond/canal.py`

```python
BUDGET_DEFAUT_MS = 10000
BUDGET_MAX_MS    = 60000
MARGE_MS         = 1000
TAILLE_MAX_CORPS = 256 * 1024

REGISTRE: dict[str, Callable]   # nom d'operation -> coroutine(parametres, contexte) -> dict | None

def enregistrer(nom, fonction)                                  # point d'extension UC04+
def construire_application(apikey, contexte) -> web.Application # GET /sante + POST /rpc
async def verifier_apikey(requete, gestionnaire)                # middleware, deplace, inchange
async def normaliser_erreurs(requete, gestionnaire)             # middleware : HTTPException -> enveloppe
async def handler_sante(requete) -> web.Response                # deplace, contrat inchange
async def handler_rpc(requete) -> web.Response
def reponse_succes(data) -> web.Response
def reponse_erreur(code, message, statut=200, detail=None) -> web.Response
```

**`handler_rpc`**, dans l'ordre : lecture JSON (échec → 400 `BAD_REQUEST`) → `operation` chaîne non vide
(sinon 400) → présente dans `REGISTRE` (sinon **404** `UNKNOWN_OPERATION`) → `parametres` est un dict
(sinon 400) → budget borné à `[1000, BUDGET_MAX_MS]` →
`await asyncio.wait_for(fonction(parametres, contexte), (budget - MARGE_MS) / 1000)`.

Rattrapages, dans cet ordre : `ErreurDemon` → son code ; `asyncio.TimeoutError` → `OPERATION_TIMEOUT` ;
`Exception` → `code_pour_exception()`. **Toujours HTTP 200 pour ces trois cas** (D-a).

Chaque erreur écrit dans le log du démon une ligne
`logging.error("Operation %s en erreur [%s]", operation, code, exc_info=True)` : **la trace complète vit
là, jamais dans la réponse**.

> ⚠️ **Contrainte à porter dans les specs techniques UC04→09** : `REGISTRE` contient des coroutines
> exécutées dans la **boucle asyncio unique** du démon. Tout appel bloquant (CPU, I/O synchrone) gèle
> **tout** le canal, `/sante` compris, et se présentera à l'utilisateur comme « le démon ne répond pas »
> alors que le démon est vivant. Tout code bloquant doit passer par `asyncio.to_thread`.

---

## Server Actions / API

### `core/ajax/jeeroborock.ajax.php` *(modifié)*

Séquence : `core.inc.php` → `include_file('core', 'authentification', 'php')` → `isConnect('admin')` →
`ajax::init()` → **`session_write_close()`** → `switch (init('action'))`.

`session_write_close()` est **indispensable** : sans lui, la session PHP reste verrouillée pendant tout
l'appel au démon et **toute l'interface Jeedom se fige** pour l'utilisateur (aucune autre requête ne peut
aboutir). C'est la preuve observable du point de recette R-3.

Action `santeCanal` (admin) :

```php
$etat = jeeroborockDaemon::sante();
ajax::success(array(
  'version'             => substr((string) $etat['version'], 0, 32),
  'callback'            => !empty($etat['callback']),
  'dureeFonctionnement' => intval($etat['dureeFonctionnement']),
));
```

⚠️ La réponse est **reconstruite champ par champ**, jamais `ajax::success($etatBrut)`. C'est l'invariant
qui empêchera, dès UC04, qu'un `userData` légitimement transporté par le canal reparte vers le navigateur.

Gestion d'erreur — **`displayException()` est supprimé** (§ AC6) :

```php
catch (jeeroborockException $e) { ajax::error($e->getMessage()); }
catch (Throwable $e) {
  log::add('jeeroborock', 'error', $e->getMessage());
  ajax::error(__('Une erreur interne est survenue. Consultez le log du plugin.', __FILE__));
}
```

### `plugin_info/configuration.txt` *(modifié, puis `cp` vers le `.php`)*

Un bouton « Vérifier le canal », une zone de résultat, et le JS inline qui appelle l'action `santeCanal`.
Le JS insère le résultat avec `.text()`, **jamais `.html()`**.

⚠️ **Procédure obligatoire** : éditer **uniquement** le `.txt`, puis
`cp plugin_info/configuration.txt plugin_info/configuration.php`. Ne pas tenter de relire le `.php`
(refusé par les permissions) : contrôler par `git status --short plugin_info/configuration.php`.

⚠️ **Méta-séquences** : `configuration.txt` est un fichier **rendu**. Aucune double accolade ouvrante
littérale, y compris dans un commentaire et **y compris dans le JS** — attention aux accolades JavaScript
consécutives. `python .claude/scripts/verif-plugin.py` (colonne `meta=`) avant commit.

---

## Validation & erreurs

### Chemin d'interprétation côté PHP (`interpreterReponse`)

Ordre **déterministe**, corrigé après le challenge advisor (le 401 est traité **avant** l'échec de parsing,
de sorte qu'un 401 à corps non-JSON reste un `UNAUTHORIZED` et non un `DAEMON_INVALID_RESPONSE`) :

1. `curl_exec` a échoué (`httpCode == 0`) : `errno 28` → `DAEMON_TIMEOUT` ; `errno 7` ou `6` →
   `DAEMON_UNREACHABLE` ; autre → `DAEMON_UNREACHABLE`. `curl_errno`/`curl_error` sont **loggés** (anglais,
   jamais affichés).
2. `httpCode == 401` → `UNAUTHORIZED`, **sans dépendre du corps**.
3. `json_decode(..., true)` échoue ou ne renvoie pas un tableau → `DAEMON_INVALID_RESPONSE`. **Le corps
   n'est pas loggé** (il peut contenir un secret dès UC04) : on logge le code HTTP et la longueur.
4. `success === true` → retourne `data` si c'est un tableau, `array()` si `data` est `null`, sinon
   `DAEMON_INVALID_RESPONSE`.
5. `success === false` → `$code = error.code`, validé par `preg_match('/\A[A-Z0-9_]{1,40}\z/', $code)`
   (la valeur entre ensuite dans un lookup de table puis dans un log) ; échec de validation →
   `INTERNAL_ERROR`.
   ⚠️ **Les ancres sont `\A`/`\z`, surtout pas `^`/`$`.** En PCRE, `$` matche **aussi juste avant un `\n`
   final** : avec `/^...$/`, une valeur `"ABC\n"` passerait la validation en conservant son retour à la
   ligne, qui atteindrait le `log::add` de `lever()` → forgeage de ligne de journal. Non exploitable tant
   que `error.code` ne provient que de littéraux fixes du démon, mais c'est précisément ce garde-fou qui
   doit tenir quand UC04+ fera transiter des valeurs moins contrôlées.
6. Enveloppe non conforme → `DAEMON_INVALID_RESPONSE`.
7. Code absent de `tableMessages()` → message générique de repli **+**
   `log::add('jeeroborock', 'warning', ...)`. **C'est le seul générique de la spec, et il signale une
   désynchronisation démon/plugin** — pas une catégorie d'erreur oubliée.

**Cas particulier `UNAUTHORIZED`** : `log::add(warning)` puis `message::removeAll('jeeroborock',
'apikey_demon')` suivi de `message::add('jeeroborock', <message FR>, '', 'apikey_demon')`. C'est le
symptôme d'une **apikey régénérée pendant que le démon tourne** (le démon l'a reçue en argument au
démarrage) ; le core déduplique par `logicalId`, donc pas de spam depuis un cron. **Aucun redémarrage
automatique du démon** : le risque de boucle l'emporte sur le confort (R4).

### Table exhaustive — exception → code stable → message français

#### Famille A — canal, produite par le PHP

Ces trois codes **ne peuvent jamais provenir d'une réponse du démon** : c'est ce qui rend AC5 structurel.

| Code | Déclencheur | Message FR |
|---|---|---|
| `DAEMON_UNREACHABLE` | connexion refusée ou impossible | « Le démon ne répond pas : vérifiez qu'il est démarré dans la configuration du plugin. » |
| `DAEMON_TIMEOUT` | budget épuisé sans réponse | « Le démon n'a pas répondu dans le délai imparti. » |
| `DAEMON_INVALID_RESPONSE` | corps illisible / enveloppe non conforme | « Réponse inattendue du démon. Consultez le log du démon. » |

#### Famille B — protocole du canal, produite par le démon (HTTP ≠ 200)

| Code | Statut | Message FR |
|---|---|---|
| `UNAUTHORIZED` | 401 | « Le démon a refusé la clé d'API du plugin. Redémarrez le démon depuis la configuration du plugin. » |
| `BAD_REQUEST` | 400 / 413 | « Le démon a rejeté la requête du plugin. Consultez le log du démon. » |
| `UNKNOWN_OPERATION` | 404 | « Le démon ne connaît pas l'opération demandée (%s). Redémarrez le démon après une mise à jour du plugin. » |
| `INTERNAL_ERROR` | 500 | « Erreur interne du démon. Consultez le log du démon. » |

#### Famille C — opération (HTTP 200, `success: false`)

Les 23 exceptions de `python-roborock` 7.8.0 sont toutes mappées, plus 4 états propres au démon.

| Exception `python-roborock` 7.8.0 | Code stable | Message FR |
|---|---|---|
| *(aucune — `asyncio.TimeoutError`)* | `OPERATION_TIMEOUT` | « L'opération n'a pas abouti dans le délai imparti. » |
| *(état démon, dès UC05)* | `NOT_AUTHENTICATED` | « Le compte Roborock n'est pas lié : authentifiez-vous depuis la configuration du plugin. » |
| *(état démon, dès UC07)* | `DEVICE_UNKNOWN` | « Robot inconnu du démon : relancez une synchronisation des équipements. » |
| *(état démon)* | `DEVICE_OFFLINE` | « Le robot est hors ligne : il ne répond pas au cloud Roborock. » |
| `RoborockInvalidCredentials` | `AUTH_EXPIRED` | « Session Roborock expirée : une nouvelle authentification par code e-mail est nécessaire. » |
| `RoborockInvalidCode` | `AUTH_CODE_INVALID` | « Code de connexion invalide ou expiré. » |
| `RoborockTooFrequentCodeRequests` | `AUTH_CODE_TOO_FREQUENT` | « Trop de demandes de code de connexion : patientez quelques minutes avant de réessayer. » |
| `RoborockInvalidEmail` | `AUTH_EMAIL_INVALID` | « L'adresse e-mail du compte Roborock est invalide. » |
| `RoborockAccountDoesNotExist` | `AUTH_ACCOUNT_UNKNOWN` | « Aucun compte Roborock ne correspond à cette adresse e-mail. » |
| `RoborockNoUserAgreement` | `AUTH_AGREEMENT_REQUIRED` | « Les conditions d'utilisation Roborock n'ont pas été acceptées : ouvrez l'application mobile Roborock pour les accepter. » |
| `RoborockInvalidUserAgreement` | `AUTH_AGREEMENT_OUTDATED` | « Les conditions d'utilisation Roborock ont changé : ouvrez l'application mobile Roborock pour les accepter à nouveau. » |
| `RoborockRateLimit` | `RATE_LIMIT` | « Quota d'appels Roborock atteint : patientez avant de réessayer. Ce quota est partagé avec l'application mobile Roborock. » |
| `RoborockTooManyRequest` | `RATE_LIMIT_REMOTE` | « Le cloud Roborock a refusé la demande (trop de requêtes) : patientez avant de réessayer. » |
| `RoborockNoResponseFromBaseURL` | `CLOUD_UNREACHABLE` | « Le cloud Roborock est injoignable : vérifiez l'accès à Internet de Jeedom. » |
| `RoborockUrlException` | `CLOUD_REGION_UNKNOWN` | « Impossible de déterminer le serveur Roborock de ce compte. » |
| `RoborockMissingParameters` | `CLOUD_BAD_REQUEST` | « Le cloud Roborock a rejeté la demande (paramètres manquants). Consultez le log du démon. » |
| `RoborockParsingException` | `PARSING_ERROR` | « Réponse incompréhensible du cloud Roborock. Consultez le log du démon. » |
| `RoborockConnectionException` | `CONNECTION_FAILED` | « La connexion avec le cloud Roborock ou le robot a échoué. » |
| `RoborockTimeout` | `ROBOROCK_TIMEOUT` | « Le cloud Roborock ou le robot n'a pas répondu dans le délai imparti. » |
| `RoborockBackoffException` | `RETRY_EXHAUSTED` | « Plusieurs tentatives de communication ont échoué : réessayez plus tard. » |
| `RoborockDeviceBusy` | `DEVICE_BUSY` | « Le robot est occupé : il ne peut pas traiter cette demande maintenant. » |
| `RoborockInvalidStatus` | `DEVICE_ACTION_REFUSED` | « Le robot a refusé l'action dans son état actuel. » |
| `VacuumError` | `DEVICE_ERROR` | « Le robot signale une erreur : consultez son état dans l'application Roborock. » |
| `CommandVacuumError` | `DEVICE_COMMAND_ERROR` | « Le robot a signalé une erreur en exécutant la commande. » |
| `RoborockUnsupportedFeature` | `UNSUPPORTED` | « Cette fonction n'est pas disponible sur ce modèle de robot. » |
| `UnknownMethodError` | `UNSUPPORTED_COMMAND` | « Le robot ne reconnaît pas cette commande. » |
| `RoborockException` *(base, non spécialisée)* | `ROBOROCK_ERROR` | « Erreur Roborock non identifiée. Consultez le log du démon. » |
| `aiohttp.ClientError`, `OSError` | `CLOUD_UNREACHABLE` | *(idem ci-dessus)* |
| *(tout le reste)* | `INTERNAL_ERROR` | *(idem famille B)* |
| *(code hors table, côté PHP)* | — | « Erreur inattendue du démon. Consultez le log du plugin. » |

**Couverture explicite d'AC4** : identifiants expirés → `AUTH_EXPIRED` ; quota atteint → `RATE_LIMIT` /
`RATE_LIMIT_REMOTE` / `AUTH_CODE_TOO_FREQUENT` ; robot hors ligne → `DEVICE_OFFLINE` ; action refusée →
`DEVICE_ACTION_REFUSED` ; appareil occupé → `DEVICE_BUSY`. Cinq catégories, cinq messages distincts, aucun
repli sur un générique.

**Deux paires volontairement non fusionnées** : `RATE_LIMIT` (limiteur **interne à la librairie**,
déclenché avant tout appel réseau — l'attente est prévisible) vs `RATE_LIMIT_REMOTE` (refus **du serveur**
Roborock — le compte est potentiellement déjà pénalisé, y compris dans l'application mobile) ;
`DEVICE_ERROR` (le robot est en défaut) vs `DEVICE_COMMAND_ERROR` (la commande précise a échoué).

**Codes définis mais émis plus tard** (`NOT_AUTHENTICATED`, `DEVICE_UNKNOWN`, `DEVICE_OFFLINE`, toute la
famille auth) : la table est livrée **complète** en UC03. C'est une table de **données contractuelle**, pas
du code mort ; la livrer d'un bloc évite qu'UC04→09 la modifient à chaque passe et qu'un code non encore
traduit tombe sur le générique en production. Coût assumé : quelques chaînes traduites d'avance.

### Typage des exceptions

`jeeroborockException` **partout** sur le chemin du canal — aucune `Exception` nue n'est levée par
`jeeroborockDaemon`. Les appelants peuvent donc écrire `catch (jeeroborockException $e)` et afficher
`$e->getMessage()` sans traitement.

### Garantie AC6 — cinq mesures structurelles

1. **`displayException()` est proscrit dans tout le plugin.** La ligne
   `ajax::error(displayException($e), $e->getCode());` du squelette (l.38) est **supprimée**. Motif : la
   trace d'exception PHP porte les **arguments de chaque frame**, et `appeler($_operation, $_parametres,
   ...)` transportera un code e-mail dès UC04, puis le `userData` en retour. Le comportement de
   `zend.exception_ignore_args` dépend de l'environnement — on ne s'y fie pas.
2. **Aucune trace journalisée** : jamais de `getTraceAsString()` dans un `log::add`. La trace utile vit
   côté démon (`exc_info=True`), qui ne reçoit aucune trace PHP.
3. **La journalisation du canal ne contient aucune valeur de paramètre** : seuls les **noms** de clés
   sortent, via `implode(',', array_keys($parametres))`. Une liste noire de clés sensibles a été écartée :
   elle laisserait passer toute clé nouvelle. En erreur, le `message` journalisé est un nom de classe, pas
   un texte libre.
4. **`data` n'est jamais journalisé**, quel que soit le niveau de log, et le corps de réponse n'est pas
   loggé même en `DAEMON_INVALID_RESPONSE` (longueur + code HTTP seulement).
5. **Les réponses AJAX sont reconstruites champ par champ**, jamais relayées brutes ; côté JS, l'insertion
   se fait par `.text()`, jamais `.html()`.

**Corollaire documenté** : le canal **transporte** bien un secret en clair sur la boucle locale dès UC04
(le `UserData` doit remonter au PHP pour y être chiffré au repos). C'est conforme à AC6, qui interdit
l'apparition dans les **logs** et dans une **réponse visible**, pas le transit loopback. TLS sur
`127.0.0.1` est explicitement écarté : il imposerait un certificat auto-signé, donc une dérogation à la
règle « ne jamais désactiver la vérification TLS » de `CLAUDE.md`.

### Validation, côté par côté

| Quoi | Où | Comportement |
|---|---|---|
| `operation` non vide, `parametres` tableau | PHP avant envoi **et** démon à réception | défense en profondeur : le démon ne fait pas confiance au PHP |
| budget dans `[1, 60] s` | PHP (`appeler`) et démon (`[1000, 60000] ms`) | AC1 : aucun appelant ne peut bloquer la page plus de 60 s |
| `error.code` conforme à `^[A-Z0-9_]{1,40}$` | PHP | la valeur entre dans un lookup de table puis dans un log |
| taille du corps | démon (`client_max_size`) | 413 → enveloppe `BAD_REQUEST` par le middleware |
| droits | `isConnect('admin')` | `santeCanal` expose l'état du démon → admin seulement |

---

## Dépendances

**Aucune.** `aiohttp` est déjà une dépendance transitive directe de `python-roborock` 7.8.0.
`plugin_info/packages.json` n'est **pas** modifié.

---

## Impact i18n (français uniquement dans cette UC)

Aucun fichier `core/i18n/*.json` n'est touché pendant l'implémentation : la traduction est faite en fin de
cycle par le sous-agent `translator`, sur le code figé.

**`core/class/jeeroborockDaemon.class.php`** — **35 littérales** en `__('...', __FILE__)` : 34 dans
`tableMessages()`, plus le message générique de repli « Erreur inattendue du démon. Consultez le log du
plugin. », qui vit dans `lever()` et non dans la table (c'est le repli d'un code absent de la table, il n'y
a donc pas de clé sous laquelle le ranger). Jamais `__($variable)` : l'extraction i18n est un scan
statique, et elle porte sur le fichier entier — le littéral de `lever()` est donc bien extrait.

- `UNKNOWN_OPERATION` est la **seule** chaîne paramétrée :
  `sprintf(__('Le démon ne connaît pas l\'opération demandée (%s). ...', __FILE__), $operation)` —
  `sprintf` **autour** de `__()`, et `$operation` est une littérale du code plugin, jamais une saisie
  utilisateur.
- ⚠️ « L'adresse e-mail du compte Roborock est invalide. » existe **déjà** dans
  `core/class/jeeroborock.class.php` (UC01). Les fichiers i18n sont indexés **par fichier**
  (`plugins/jeeroborock/<chemin>`) : la même phrase devra donc exister dans **deux** entrées. À signaler au
  `translator` — ce n'est **pas** un doublon à factoriser.

**`core/ajax/jeeroborock.ajax.php`** — 2 littérales : « Une erreur interne est survenue. Consultez le log
du plugin. » et « Aucune méthode correspondante à » (existante, conservée).

**`plugin_info/configuration.txt`** — 5 littérales : « Vérifier le canal » ; « Vérification en cours… » ;
« Canal opérationnel » ; « Le callback vers Jeedom n'est pas joignable : les mises à jour spontanées ne
fonctionneront pas. » ; « Le démon ne répond pas. » (erreur de transport jQuery, distincte du message
serveur).

Les messages `log::add` et ceux du démon restent en français **non enveloppé** (les logs ne se traduisent
pas), et les **codes stables restent en anglais**, conformément au cadrage de `CLAUDE.md`.

---

## Risques & pièges

- **R1 (majeur, AC1)** — **boucle asyncio unique.** Une opération bloquante enregistrée en UC04+ gèle tout
  le canal, `/sante` compris : l'utilisateur verra « le démon ne répond pas » alors que le démon est
  vivant. Mitigation : `asyncio.to_thread`, à rappeler dans les specs techniques UC04→09.
- **R2 (majeur)** — **proxy d'environnement.** Un `http_proxy`/`HTTP_PROXY` défini dans l'environnement de
  PHP-FPM détournerait un appel loopback vers un proxy d'entreprise. Neutralisé par `CURLOPT_PROXY => ''`.
  Symptôme s'il est oublié : timeouts inexpliqués sur une seule installation.
- **R3 (majeur)** — **`localhost` vs `127.0.0.1`.** Résolution en `::1` sur une machine IPv6-first → refus
  de connexion permanent alors que le démon écoute. Neutralisé par l'adresse littérale.
- **R4** — **apikey régénérée pendant que le démon tourne** : 401 systématique jusqu'au redémarrage du
  démon. Rendu lisible (message + centre de messages) mais **non corrigé automatiquement** (risque de
  boucle de redémarrage). À confirmer en recette.
- **R5** — **`aiohttp` est une dépendance transitive**, pas déclarée. Si `python-roborock` cessait d'en
  dépendre, le démon casserait à l'import sans que `packages.json` change. Choix de cadrage assumé ; la
  bannière de version d'UC02 est le garde-fou.
- **R6** — **casse du nom de fichier de classe** (voir § Architecture). `Class not found` au runtime,
  invisible à `php -l` et à la CI.
- **R7** — **JS inline dans `configuration.php`** : si une CSP interdisait `unsafe-inline`, le bouton
  serait muet. Aucune preuve d'une telle CSP (de nombreux plugins officiels font ainsi), et l'inline est
  **nécessaire** pour que les libellés passent par le moteur i18n du core. Repli si la recette échoue :
  déporter le JS et passer les libellés déjà traduits en attributs `data-`.
- **R8** — **désynchronisation `configuration.txt` / `.php`** : oublier le `cp` produit un formulaire
  silencieusement inchangé. Contrôle obligatoire `git status --short plugin_info/configuration.php`.
- **R9** — **méta-séquences.** `configuration.txt` est un fichier **rendu** : aucune double accolade
  ouvrante littérale, y compris dans un commentaire et dans le JS. Dans les fichiers PHP : pas de
  délimiteur de fin de commentaire collé à du texte, pas de balise fermante PHP dans un commentaire de
  ligne. `python .claude/scripts/verif-plugin.py` avant chaque commit.
- **R10** — **contrainte sur l'avenir** : le contrat `{operation, parametres, budgetMs}` sur une route
  unique est figé ici. Une UC ultérieure qui aurait besoin de streaming ou d'un transfert binaire
  volumineux (image de carte, domaine post-MVP `20-carte-et-pieces`) devra ouvrir une route dédiée — le
  contrat RPC n'est pas adapté au binaire. Rien à anticiper aujourd'hui.
- **R11** — **`deamon_info()` ne doit jamais appeler `jeeroborockDaemon`.** Elle est appelée chaque minute
  par `plugin::checkDeamon` **et** à chaque rafraîchissement de la modale démon, sans try/catch côté core.
  La décision D-f d'UC02 tient ; le durcissement évoqué en R12 d'UC02 reste **non fait**, délibérément.
- **R12** — **`max_execution_time`** : le plafond `TIMEOUT_MAX = 60 s` doit rester sous le
  `max_execution_time` de PHP-FPM et le `fastcgi_read_timeout` du frontal. Les opérations UC04+ devraient
  rester ≤ 30 s.
- **R13** — **démon arrêté pendant un appel en vol.** `postConfig_portDemonHttp` (UC02) déclenche un
  `deamon_stop()` qui peut couper une requête `appeler()` émise depuis un autre onglet admin. Le
  comportement attendu est un `DAEMON_UNREACHABLE` **transitoire** (ou un `CURLE_RECV_ERROR` classé comme
  tel), qui disparaît dès que le démon est relancé. Documenté ici pour qu'un testeur ne le confonde pas
  avec un bug.

---

## Recette (à confirmer sur une Jeedom réelle, Debian 12+)

| # | AC | Vérification | Attendu |
|---|---|---|---|
| R-1 | AC2 | `POST /rpc` sans `X-Apikey`, puis avec une apikey fausse | **401** dans les deux cas |
| R-2 | AC3 | `ss -ltnp` ; `curl` depuis une autre machine du LAN | `127.0.0.1:61350` et **rien** sur `0.0.0.0`/`::` ; connexion refusée depuis le LAN |
| R-3 | AC1, AC5 | démon arrêté, clic sur « Vérifier le canal » | « Le démon ne répond pas… » en **moins de 5 s**, et la navigation Jeedom reste fluide pendant l'appel (preuve du `session_write_close()`) |
| R-4 | AC1 | opération de test qui dort 30 s (ou `TIMEOUT_DEFAUT` abaissé temporairement) | `OPERATION_TIMEOUT` **avant** la coupure cURL, message « L'opération n'a pas abouti dans le délai imparti. » |
| R-5 | AC4 | `curl` avec `"operation":"inexistante"` | **404** + message FR sur l'opération inconnue |
| R-6 | AC6 | niveau de log à `debug`, enchaîner plusieurs appels | `log/jeeroborock` ne contient **aucune valeur** de paramètre ni de `data`, seulement des noms de clés ; `log/jeeroborock_demon` ne contient pas l'apikey |
| R-7 | R4 | régénérer l'apikey du plugin sans redémarrer le démon | message explicite sur la clé refusée, disparaissant après redémarrage du démon |
| R-8 | D-g | non-régression UC02 : `GET /sante` avec apikey valide | répond **le même objet qu'avant** le déplacement du handler dans `canal.py` |
| R-9 | R13 | arrêter le démon pendant qu'un appel est en vol (deux onglets admin) | `DAEMON_UNREACHABLE` transitoire, rétabli après relance — **comportement attendu, pas un bug** |

---

## Dette

Findings de review du cycle UC03 n'atteignant pas la gate, conservés ici pour les UC suivantes.

- **Double source de vérité sur l'ensemble des codes « canal »** *(qualité, suggestion)* —
  `jeeroborockException::estErreurCanal()` porte une liste en dur des 7 codes des familles A et B, qui
  duplique l'ensemble déjà défini dans `jeeroborockDaemon::tableMessages()`. La duplication est un
  **compromis délibéré** : `jeeroborockException` doit rester sans dépendance vers `jeeroborockDaemon`,
  sans quoi les deux classes deviennent indissociables et l'autoload s'en trouve alourdi.
  ⚠️ **Conséquence pour UC04+** : un nouveau code de protocole du canal doit être ajouté **dans les deux
  fichiers**. Un oubli n'est détecté par aucun contrôle automatique — seulement à la relecture. À vérifier
  systématiquement en review dès qu'un code de famille A ou B est ajouté.
- **`error.detail` — contrainte désormais appliquée** *(sécurité, low — corrigé en passe de finition)* :
  le filtrage des valeurs non scalaires est fait dans `reponse_erreur()` de `canal.py`, point de sortie
  unique du canal. Une opération UC04+ qui placerait une chaîne libre dans `detail` la verra **écartée**,
  pas transmise. Ne pas contourner ce filtre en sérialisant `detail` ailleurs.
