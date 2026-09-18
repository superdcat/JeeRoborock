# Spec technique — UC08 : Commandes de pilotage de base

> **Spec fonctionnelle** : `08-commandes-action-base.md` · **Dépend de** : UC03 (pont), UC04 (session),
> UC06 (équipements), UC07 (canal robot, commandes info) · **Prépare** : UC09 (routines), UC10 (push)

## Périmètre

Rendre le robot pilotable depuis Jeedom :

- une opération démon **`envoyerCommande`** dans `robots.py` (module d'UC07), qui transmet une des
  **5 actions** d'une **liste blanche fermée** au robot par le canal V1 **déjà ouvert par UC07** ;
- **6 commandes action** Jeedom par robot : `demarrer`, `pause`, `arreter`, `retour_base`, `localiser`
  (démon) et `rafraichir` (local, branché sur `rafraichirEtat()` d'UC07) ;
- une **relecture d'état post-action** best-effort, dans la même opération démon, pour que le dashboard
  reflète l'effet de l'action sans second aller-retour (il n'y a ni push — UC10 — ni cron — D-07-9).

Aucun nouvel endpoint : l'exécution emprunte le chemin du cœur
`core/ajax/cmd.ajax.php` → `cmd::execCmd()` → `jeeroborockCmd::execute()`.

### Couverture des critères d'acceptation

| AC | Réalisé par | Statut |
|---|---|---|
| **AC1** — démarrer | cmd `demarrer` → `executerAction('demarrer')` → `envoyerCommande` → `RoborockCommand.APP_START`. Constat d'état : relecture post-action, ou cmd `rafraichir` | couvert |
| **AC2** — pause | idem, `RoborockCommand.APP_PAUSE` | couvert |
| **AC3** — arrêter | idem, `RoborockCommand.APP_STOP` | couvert |
| **AC4** — retour à la base | idem, `RoborockCommand.APP_CHARGE`, `generic_type` **`DOCK`** | couvert |
| **AC5** — localiser | idem, `RoborockCommand.FIND_ME` | couvert |
| **AC6** — rafraîchir | cmd `rafraichir` → `jeeroborock::rafraichirEtat()` d'UC07, **non réécrite** (cf. Frontière UC07/UC08) | couvert |
| **AC7** — robot hors ligne | deux chemins : (a) `device.is_connected` faux → `DEVICE_OFFLINE` **sans aucune RPC** ; (b) canal debout mais robot muet → **reclassement du timeout** en `DEVICE_OFFLINE` (§ Classement des erreurs d'envoi). Le PHP écrit `connecte = 0` puis relève | couvert |
| **AC8** — action refusée | `RoborockInvalidStatus` (code V1 `-10007`) et toute autre réponse d'erreur du robot → `DEVICE_ACTION_REFUSED` (« Le robot a refusé l'action dans son état actuel. ») | couvert **avec une limitation assumée** (§ AC8) |

Le point « À confirmer » de la spec fonctionnelle (pause sur un robot qui ne nettoie pas) reste un
**point de recette** (R-3 du § Recette) : le comportement dépend du firmware, et les deux issues
possibles sont traitées (succès silencieux, ou `-10007` → « action refusée »).

### AC8 — limitation assumée : « robot occupé » n'est pas démontrable

`RoborockDeviceBusy` (→ `DEVICE_BUSY`, « Le robot est occupé… ») n'est levée **que** par
`python-roborock` dans `traits/v1/home.py` (l. 110, 176) — **jamais** sur le chemin `command.send()`
qu'emprunte UC08. En pratique, seul `DEVICE_ACTION_REFUSED` sera observé.

AC8 demande « un message distinct » pour une action refusée dans l'état courant : c'est satisfait — le
message est distinct de celui du hors-ligne, explicite et en français. Mais la variante « robot occupé »
annoncée dans la spec fonctionnelle **ne sera jamais démontrée en recette** sur ce chemin.

**Arbitrage utilisateur de ce cycle** : limitation acceptée, documentée, **pas contournée**. Les deux
alternatives ont été écartées :
- déduire « occupé » de l'état courant du robot avant d'émettre l'action → heuristique non sourcée,
  fausse dès que le robot change d'état entre la lecture et l'envoi, et coûteuse (une RPC de plus) ;
- élargir le périmètre au trait `home` pour atteindre `RoborockDeviceBusy` → hors spec, et ce trait
  n'est pas sur le chemin d'une action de pilotage.

⚠️ Conséquence pour la recette : **AC8 ne doit pas être coché sans avoir constaté au moins une fois le
message « action refusée » sur le robot réel** (R-7), et le fait que « robot occupé » ne sorte jamais
est un **attendu**, pas un défaut.

## Frontière UC07 / UC08 (rappel, respectée)

UC07 avait figé la frontière : « UC08 ne réécrit rien : il branche une cmd sur la méthode d'UC07 ».
C'est tenu — `rafraichirEtat()`, `appliquerConnexion()`, `appliquerCapacites()`, `appliquerValeurs()`,
`lire_etat()`, `_capacites()`, `_valeurs()` sont **réutilisées sans modification de comportement**.

Deux exceptions, toutes deux additives et justifiées :
1. `rafraichirEtat()` gagne **un appel** à `appliquerActions()` (§ Points d'appel) — aucune ligne
   existante modifiée ;
2. `_attendre_connexion()` est **extraite** de `lire_etat()` (boucle de 3 lignes, comportement
   strictement identique) pour être partagée avec `envoyer_commande()`. ⚠️ **Non-régression à vérifier
   en recette** (R-12) : c'est la seule modification d'un chemin livré.

Le bouton d'administration « Rafraîchir l'état » d'UC07 est **conservé** (arbitrage d'UC07) : il reste le
seul déclencheur disponible quand aucune commande n'existe encore.

## Architecture

| Fichier | État | Ce qui y entre | Indentation / EOL |
|---|---|---|---|
| `resources/demond/robots.py` | **modifié** | opération `envoyer_commande` + table `ACTIONS` + `_erreur_envoi()` + `_attendre_connexion()` (extraite) + verrou de construction + constantes de budget + `canal.enregistrer("envoyerCommande", …)` | **4 espaces, LF** |
| `core/class/jeeroborock.class.php` | **modifié** | `definitionsActions()`, `appliquerActions()`, `executerAction()`, `appliquerEtatPartiel()`, appel dans `postSave()` et dans `rafraichirEtat()`, routage dans `jeeroborockCmd::execute()` | **2 espaces, CRLF** |
| `core/class/jeeroborockDaemon.class.php` | **modifié** | **1 ligne** : `const TIMEOUT_ACTION = 35;` | 2 espaces, CRLF |
| `core/class/jeeroborockException.class.php` | **non modifié** | Aucun code de famille A/B ajouté ⇒ le piège de la liste dupliquée `tableMessages()`/`estErreurCanal()` **ne s'applique pas** à cette UC (vérifié : les 8 codes utilisés sont déjà dans `tableMessages()`) | — |
| `resources/demond/jeeroborockd.py` | **non modifié** | L'opération s'enregistre depuis `robots.enregistrer_operations()`, déjà appelée | — |
| `canal.py`, `erreurs.py`, `session.py`, `authentification.py`, `equipements.py`, `libelles.py` | **non modifiés** | Non-régression UC03→UC07. `RoborockInvalidStatus → DEVICE_ACTION_REFUSED` est **déjà** dans `TABLE_CODES` | — |
| `core/ajax/jeeroborock.ajax.php` | **non modifié** | L'exécution passe par `cmd.ajax.php` (cœur) : `isConnect()` + `hasRight('x')` déjà portés par le core | — |
| `desktop/php/jeeroborock.php`, `desktop/js/jeeroborock.js` | **non modifiés** | `addCmdToTable()` gère déjà `type`/`subType` | — |
| `plugin_info/configuration.txt` / `.php` | **non touchés** | Aucune clé de config ⇒ **pas de `cp`**, pas de risque de désynchronisation du miroir | — |
| `plugin_info/packages.json`, `info.json`, `core/config/*.ini`, `core/template/**`, `core/php/jeeJeeroborock.php` | **non touchés** | Aucune dépendance, widgets par défaut (`core::default`), aucun push | — |
| `core/i18n/*.json` | **non touchés dans ce cycle** | Traduction en fin de cycle par `translator` | — |

**Autoload** : aucune classe PHP nouvelle ⇒ règle 1 classe ↔ 1 fichier sans objet ici.

## Server vs Client

**Tout côté serveur.** Aucun JavaScript n'est écrit dans cette UC.

Motif : une commande action Jeedom est rendue par le cœur (widget `core::default`), exécutée par
`jeedom.cmd.execute` du core, et son retour est affiché par le core (alerte verte avec le message de
succès, alerte rouge avec `data.result` en cas d'erreur). Écrire du JS serait dupliquer le core et
créer un second chemin d'erreur à maintenir.

Corollaire vérifié : `jeedom.cmd.execute` (`private.class.js`) n'impose **aucun** `timeout` jQuery ⇒ un
appel de 35 s n'est pas coupé côté navigateur, rien à régler côté client.

## Contrats externes

Tout le contrat Roborock vit dans le démon, via `python-roborock` **7.8.0** (sources lues au tag
`v7.8.0`). **Aucun appel réseau en PHP. Aucune requête HTTPS cloud supplémentaire ⇒ opération
quota-neutre** : le seul `homedata` reste celui de la construction du `DeviceManager` (D-07-1).

1. **`CommandTrait.send(command, params=None) -> Any`** — `roborock/devices/traits/v1/command.py`
   l. 28-38. Délègue à `self._rpc_channel.send_command(command, params=params)`.
   Docstring : *« Sending a raw command … does not update the internal state of any other traits. It is
   the responsibility of the caller to ensure that any traits affected by the command are refreshed »*
   → **c'est la librairie elle-même qui impose la relecture post-action**.
2. **Noms exacts des commandes** — `roborock/roborock_typing.py` : `APP_START = "app_start"` (l. 44),
   `APP_PAUSE = "app_pause"` (l. 24), `APP_STOP = "app_stop"` (l. 52), `APP_CHARGE = "app_charge"`
   (l. 13), `FIND_ME = "find_me"` (l. 72). ✅ Conforme à
   `.memory/analyse/jeeroborock-mqtt-protocole.md` § 6, **aucun écart**.
3. **Payload** — `RequestMessage._as_payload` (`protocols/v1_protocol.py` l. 92-99) :
   `"params": self.params or []`. Les 5 actions n'ont **aucun paramètre** à fournir.
4. **Robot muet** — `RpcChannel._send_rpc` (`devices/rpc/v1_channel.py` l. 152-157) :
   `asyncio.wait_for(future, _TIMEOUT)` avec **`_TIMEOUT = 10.0` par stratégie** (l. 49) ; dépassement →
   `RoborockException("Command timed out after 10.0s") from ex`, où `ex` est un `TimeoutError`.
   `send_command` essaie **chaque stratégie en séquence** (local puis MQTT) et relève la **dernière**
   exception ⇒ **jusqu'à 20 s** pour une seule commande.
5. **Robot qui refuse** — `_create_api_error` (`protocols/v1_protocol.py` l. 113-128) :
   `_V1_ERROR_CODE_EXCEPTIONS = {-10007: RoborockInvalidStatus}` (« invalid status - device action
   locked ») ; **tout autre code** devient une `RoborockException(error)` nue **dont `args[0]` EST le
   dict d'erreur** (pas de `__cause__`). `decode_rpc_response` l. 199-215 : `result == "unknown_method"`
   → `RoborockUnsupportedFeature` ; `result` str ≠ `"ok"` → `RoborockException("Unexpected API Result…")`.
6. **Succès** — absence d'exception. Le payload décodé (typiquement `["ok"]`) n'est **pas** renvoyé au
   PHP (donnée externe inutile côté Jeedom) : journalisé en `debug`, et en `info` s'il vaut autre chose
   que `"ok"`/`["ok"]` (signal de recette).

### Contrat du cœur Jeedom (source `jeedom/core`, `core/class/cmd.class.php`)

- `execCmd()` l. 1636-1639 **lève** si l'eqLogic est désactivé ⇒ **aucun garde à écrire** côté plugin.
- l. 1684 + `formatValue()` l. 1271-1273 : **un retour `array`/`object` devient une chaîne vide** ⇒
  `execute()` doit retourner un **scalaire**.
- l. 1700-1703 : `numberTryWithoutSuccess` est relu puis ré-écrit **à l'identique**, jamais incrémenté
  par le cœur ⇒ la désactivation automatique de l'équipement après N échecs **ne peut pas se
  déclencher** ; inutile de poser `nerverFail`. ✅ AC7 sans effet de bord.
- `isAlreadyInStateAllow()` l. 1563-1566 : retourne `false` si la commande n'a pas de `value` liée ⇒
  **ne pas poser `value`** garantit que le cœur ne « saute » jamais l'exécution. Un saut silencieux
  serait exactement le « succès trompeur » qu'AC7 interdit.
- `cmd.ajax.php` l. 22-25 / 86 : `isConnect()` + `$eqLogic->hasRight('x')` ⇒ endpoint **non-admin**
  correct, droits portés par le cœur, **rien à écrire**.
- ⚠️ `cmd.ajax.php` **ne fait aucun `session_write_close()`** (ni `ajax::init()`) ⇒ le verrou de session
  PHP est tenu pendant toute l'action et **fige l'interface Jeedom** (§ `jeeroborockCmd::execute()`).
- ⚠️ `cmd.ajax.php` sort en `ajax::error(displayException($e), …)` ; `displayException` + `log::exception`
  injectent **`getTraceAsString()` dans le DOM** quand le niveau de log **global** vaut `debug`.
  `getTraceAsString()` rend un argument tableau comme `Array` ⇒ **l'invariant UC04 « le `userData`
  transite dans le TABLEAU de paramètres, jamais en scalaire » est ce qui protège ce nouveau chemin de
  sortie** (§ Risques, R-5).
- Pas de `generic_type` « aspirateur » dans le cœur : seule la famille Robot existe, avec **`DOCK`**
  (Action/other, « Retour base ») et `DOCK_STATE` ⇒ `DOCK` sur `retour_base`, rien sur les autres.

## Server Actions / API

### Opération démon `envoyerCommande`

**Requête** (via `jeeroborockDaemon::appeler`) : `userData` (requis), `baseUrl`, `email`, `duid`
(requis), `action` (requis, clé de `ACTIONS`).

**Réponse** — **même forme que `lireEtat`**, pour que le PHP réutilise ses appliquants existants sans
créer un second chemin d'écriture de commande :

```
{ "duid", "action", "enLigne", "connecte", "etatLu", "motifEchec", "capacites", "etat" }
```

`etatLu` vaut `False` quand la relecture a été sautée ou a échoué ; dans ce cas **`capacites` et `etat`
valent `{}`** (dict vides, jamais `None`), par analogie stricte avec `lire_etat`. `motifEchec` vaut la
chaîne vide quand la relecture est sautée faute de budget. ⚠️ **Seule `executerAction()` consomme ce
payload, et elle ignore `motifEchec`** (l'action, elle, a réussi).

**Codes d'erreur levés** : `INTERNAL_ERROR` (action hors liste blanche = désynchronisation PHP/démon),
`DEVICE_OFFLINE` (AC7), `DEVICE_ACTION_REFUSED` (AC8), `OPERATION_TIMEOUT`, plus tous ceux
d'`obtenir_appareil()` (`NOT_AUTHENTICATED`, `AUTH_EXPIRED`, `DEVICE_UNKNOWN`, `RATE_LIMIT`…).

## Signatures

### `resources/demond/robots.py` *(modifié)*

```python
# Budget (echeance globale : demon <= 30 s < canal 34 s < PHP 35 s)
DELAI_TOTAL_ACTION_S      = 30
DELAI_ENVOI_MAX_S         = 22    # plafond ; borne reelle = ce qui reste de l'echeance
DELAI_RELECTURE_S         = 8
PAUSE_AVANT_RELECTURE_S   = 2.0
RESTE_MINIMAL_RELECTURE_S = 4

ACTIONS = {            # LISTE BLANCHE FERMEE : le PHP ne peut JAMAIS faire emettre une RPC arbitraire
    "demarrer":    RoborockCommand.APP_START,
    "pause":       RoborockCommand.APP_PAUSE,
    "arreter":     RoborockCommand.APP_STOP,
    "retour_base": RoborockCommand.APP_CHARGE,
    "localiser":   RoborockCommand.FIND_ME,
}
_VERROU_GESTIONNAIRE = asyncio.Lock()

async def envoyer_commande(parametres, contexte) -> dict
def _erreur_envoi(erreur) -> ErreurDemon
async def _attendre_connexion(appareil) -> bool   # EXTRAITE de lire_etat, comportement identique
def enregistrer_operations()                      # + canal.enregistrer("envoyerCommande", …)
```

**Déroulé de `envoyer_commande`, ordre imposé** :

1. `echeance = time.monotonic() + DELAI_TOTAL_ACTION_S`.
2. `commande = ACTIONS.get(action)` ; absente → `logging.error` avec `_texte(action, 32)` +
   `ErreurDemon("INTERNAL_ERROR")`. **Jamais** de chaîne libre transmise à la librairie.
3. `appareil = await obtenir_appareil(parametres, contexte)` — **seul point d'entrée** (R-14 d'UC07) ;
   ne rappelle **jamais** `create_device_manager()`.
4. `await _attendre_connexion(appareil)` ; si toujours `not appareil.is_connected` →
   `ErreurDemon("DEVICE_OFFLINE")` **sans aucune RPC** (AC7, chemin rapide).
5. `budget = min(DELAI_ENVOI_MAX_S, echeance - time.monotonic())` ; si `budget < 3` →
   `ErreurDemon("OPERATION_TIMEOUT")` **avant d'émettre** (ne pas envoyer une commande qu'on ne pourra
   pas confirmer). Sinon `await asyncio.wait_for(appareil.v1_properties.command.send(commande), budget)`.
6. Sur exception : `raise _erreur_envoi(erreur) from erreur`.
7. Relecture best-effort (§ Rafraîchissement post-action), **jamais fatale**.
8. Retour du dict décrit au § Server Actions / API.

### Classement des erreurs d'envoi — `_erreur_envoi(erreur)`

Ordre imposé, chaque test étant sourcé :

1. `isinstance(erreur, TimeoutError)` **ou** `isinstance(erreur.__cause__, TimeoutError)` →
   **`DEVICE_OFFLINE`**. Couvre notre `wait_for` **et** le timeout interne de la librairie.
   ⚠️⚠️ **Sans ce test, AC7 produit un message faux.** `code_pour_exception()` verrait
   `RoborockException` → suivrait `__cause__` → `TimeoutError`, dont le MRO contient **`OSError`**
   (Python ≥ 3.11) → `TABLE_CODES["OSError"] = "CLOUD_UNREACHABLE"` → l'utilisateur lirait « Le cloud
   Roborock est injoignable : vérifiez l'accès à Internet de Jeedom », alors que son Internet va bien et
   que c'est le **robot** qui ne répond pas.
2. `erreur.args[0]` est un `dict` portant un `code` entier → `logging.info("… refus du robot code=%s")`
   puis **`DEVICE_ACTION_REFUSED`** (AC8). Détection **de forme**, jamais d'un message anglais :
   `_create_api_error` est le **seul** endroit de la librairie qui construit une `RoborockException` à
   partir d'un dict.
3. Sinon `code_pour_exception(erreur)` → `ErreurDemon(code)`, ce qui préserve
   `RoborockInvalidStatus → DEVICE_ACTION_REFUSED` (déjà dans `TABLE_CODES`), `AUTH_EXPIRED`,
   `RATE_LIMIT`, `UNSUPPORTED`… **Aucune exception n'est avalée.**

⚠️ **Approximation assumée** : le test 1 amalgame deux causes — silence réel du robot, et notre propre
`wait_for` qui a coupé court faute de budget. Les deux se présentent à l'utilisateur comme « robot hors
ligne ». C'est le message qu'AC7 attend et il reste juste dans les deux hypothèses sur `is_connected`
(R-1), mais les deux cas sont **à distinguer en recette** (R-6 et R-6bis).

### Verrou de construction du gestionnaire

`async with _VERROU_GESTIONNAIRE` autour de la **construction** dans `_gestionnaire()`, avec
**double vérification** de l'empreinte de session après acquisition ; le **chemin rapide** (gestionnaire
mémorisé, empreinte identique) reste **hors verrou**. Pattern double-checked locking ; une seule boucle
asyncio, le verrou n'encapsule aucun `await` qui rebouclerait dessus ⇒ pas de risque d'interblocage.

**Motif** : deux appels concurrents sur un `contexte["gestionnaire"]` vide construiraient **deux**
`DeviceManager` = **deux `homedata`** (quota dur 5/h, 40/jour, **partagé avec l'application mobile de
l'utilisateur**) **et deux sessions MQTT** sur le même compte.

⚠️ **Cette race est une dette latente d'UC07, pas un risque créé par UC08.** Elle est déjà atteignable
depuis UC07 (deux clics rapprochés sur « Rafraîchir l'état »). UC08 ne l'invente pas : il **multiplie
les points d'appel concurrents** (6 boutons au dashboard + déclenchement par scénario au lieu d'un seul
bouton admin) et la rend nettement plus probable. Le correctif est donc **rétroactif** : il profite
aussi à `lireEtat`.

### `core/class/jeeroborock.class.php` *(modifié)*

```php
private static function definitionsActions()      // -> array logicalId => definition
public  function appliquerActions()               // -> int (nb de commandes creees) ; NE LEVE JAMAIS
public  function executerAction($_action)         // -> string (message FR de succes)
private function appliquerEtatPartiel($_reponse)  // reutilise appliquerConnexion/Capacites/Valeurs
```

**`definitionsActions()`** — table statique, littérales `__()` **dans la table** (jamais `__($variable)`) :

| `logicalId` | nom FR | type | subType | `generic_type` | `value` | visible | ordre | démon |
|---|---|---|---|---|---|---|---|---|
| `demarrer` | Démarrer | action | other | — | **aucune** | 1 | 20 | oui |
| `pause` | Mettre en pause | action | other | — | aucune | 1 | 21 | oui |
| `arreter` | Arrêter | action | other | — | aucune | 1 | 22 | oui |
| `retour_base` | Retour à la base | action | other | **`DOCK`** | aucune | 1 | 23 | oui |
| `localiser` | Localiser | action | other | — | aucune | 1 | 24 | oui |
| `rafraichir` | Rafraîchir | action | other | — | aucune | 1 | 25 | **non** |

Ordres 20-25 : laissent la plage 12-19 libre pour les commandes info des UC 12-15, sans renumérotation.
Le drapeau `demon` distingue l'action robot du rafraîchissement local ; `executerAction()` **refuse** une
clé dont `demon` est faux.

**`appliquerActions()`** — pour chaque définition : `getCmd('action', $logicalId)` ; absente → création ;
présente → on ne réécrit **que** le structurel (`type`, `subType`, `generic_type`) et **jamais**
`name`/`isVisible`/`order` (personnalisation utilisateur préservée, même règle qu'`appliquerCapacites()`
d'UC07). `try/catch (Throwable)` **par commande**, log **tronqué à 256 caractères** (trois exceptions de
`cmd::save()` embarquent `print_r($this, true)`). **Ne lève jamais** : appelée depuis `postSave()`, elle
ne doit faire échouer ni l'enregistrement d'un équipement, ni la boucle de `synchroniserEquipements()`.

Séquence de création : `setEqLogic_id` / `setEqType('jeeroborock')` / `setLogicalId` / `setName(<littérale>)`
/ `setType('action')` / `setSubType('other')` / `setGeneric_type('DOCK')` *(`retour_base` seul)* /
`setIsVisible(1)` / `setOrder(n)` / `save()`.
**Pas de `setTemplate()`** (le cœur pose `core::default`), **pas de `setValue()`** (cf.
`isAlreadyInStateAllow`), **pas de `setIsHistorized`** (forcé à 0 pour une action par le cœur).

**Points d'appel de `appliquerActions()`** — deux, volontairement :

1. **`postSave()`** — idiome Jeedom standard ; couvre la création d'un robot par
   `synchroniserEquipements()` (UC06 appelle `$eqLogic->save()`) **sans modifier une ligne d'UC06**, et
   tout enregistrement manuel. `postSave()` s'exécute après l'insertion, donc `getId()` est renseigné.
2. **`rafraichirEtat()`**, immédiatement **après `appliquerConnexion()`** et **avant le test d'échec** —
   retrofit des robots créés avant UC08 **sans imposer une resynchronisation** (qui coûterait un
   `homedata`, or « la découverte n'est pas une opération de rafraîchissement »), et garantie qu'un robot
   **jamais joignable** possède quand même ses boutons — sans quoi AC7 serait invérifiable.

*Alternative écartée* : créer les actions dans `appliquerRobot()` (UC06). Refusée — elle modifierait une
UC livrée sans rien gagner, et ne couvrirait pas le retrofit.
*Contrepartie connue* : comme `appliquerCapacites()`, `appliquerActions()` appelle `save()` sur une
commande déjà correcte à chaque passage, pas seulement quand une valeur change. Surcoût négligeable au
MVP (1 robot testable), **à revoir si UC10 ajoute un cron périodique sur N robots**.

**`executerAction($_action)`**, ordre imposé :

1. `$duid = trim((string) $this->getLogicalId())` ; `!self::duidValide($duid)` →
   `throw jeeroborockDaemon::erreurLocale('DEVICE_UNKNOWN')`.
2. `!self::estCompteLie()` → `throw jeeroborockDaemon::erreurLocale('NOT_AUTHENTICATED')` — **aucun
   appel démon**.
3. Liste blanche : `$definitions = self::definitionsActions()` ; clé absente **ou** `demon` faux →
   `log::add(… 'warning' …)` + `throw jeeroborockDaemon::erreurLocale('UNSUPPORTED_COMMAND')`.
4. `jeeroborockDaemon::appeler('envoyerCommande', array('userData' => …, 'baseUrl' => …, 'email' => …,
   'duid' => $duid, 'action' => $_action), jeeroborockDaemon::TIMEOUT_ACTION)`.
   ⚠️ Le `userData` **uniquement dans le tableau**, jamais en argument scalaire (cf. `displayException`).
5. `try { $this->appliquerEtatPartiel($r); } catch (Throwable $e) { log::add(… 'error' …, tronqué 256) }`
   — **l'action a réussi** : un incident d'écriture de commande ne doit pas la faire apparaître en échec.
6. `log::add('jeeroborock', 'info', …)` avec le nom de l'action et l'id de l'équipement.
7. Retourne `sprintf(__('Commande « %s » transmise au robot.', __FILE__), $definitions[$_action]['nom'])`
   — formulation **honnête** : le robot a accepté la RPC, ce qui n'est pas la même chose que
   « nettoyage démarré » (cf. R-2).
8. `catch (jeeroborockException $e)` **autour de l'étape 4 uniquement** :
   ⚠️⚠️ **si `$e->getCodeErreur() === 'DEVICE_OFFLINE'`** → `$this->checkAndUpdateCmd('connecte', 0)`
   (silencieux si la commande n'existe pas) **puis `throw $e`**. Le dashboard devient cohérent avec le
   message d'erreur (AC7 : « l'utilisateur voit clairement que l'action n'a pas pu être transmise »).

> ⚠️⚠️ **`getCodeErreur()`, jamais `getCode()`.** `jeeroborockException` porte son code stable dans une
> **propriété dédiée** : `Exception::getCode()` est typé `int` et **ne peut pas** contenir la chaîne
> `'DEVICE_OFFLINE'`. Un `$e->getCode() === 'DEVICE_OFFLINE'` écrit par réflexe PHP standard est
> **toujours faux, silencieusement** — aucune erreur PHP, simplement `connecte` jamais remis à 0, et
> AC7 à moitié cassé sans qu'un test manuel superficiel le révèle. Ce piège est documenté dans
> `jeeroborockException.class.php` lui-même.

**`appliquerEtatPartiel($_reponse)`** — `appliquerConnexion($_reponse)` **toujours** ; puis, si
`!empty($_reponse['etatLu'])`, `appliquerCapacites()` + `appliquerValeurs()`. Les trois méthodes privées
d'UC07 sont réutilisées **telles quelles, sans modification** : aucun nouveau chemin d'écriture de
commande, aucune nouvelle validation à maintenir.

### `jeeroborockCmd::execute($_options)` *(modifié)*

```php
public function execute($_options = array()) {
  // 1. type != 'action' -> return false
  // 2. session_write_close() sous garde session_status() === PHP_SESSION_ACTIVE
  // 3. $eqLogic = $this->getEqLogic()   (execCmd a deja garanti un eqLogic actif)
  // 4. switch ($this->getLogicalId()) :
  //      'rafraichir' -> $eqLogic->rafraichirEtat(); return __('État rafraîchi.', __FILE__);
  //      default      -> return $eqLogic->executerAction($this->getLogicalId());
}
```

⚠️ Le **`session_write_close()` est obligatoire, pas cosmétique** : `cmd.ajax.php` et `ajax::init()` ne
le font pas, et l'appel peut durer 35 s — sans lui, **toute l'interface Jeedom se fige** pour
l'utilisateur pendant l'action, et le symptôme (« Jeedom ne répond plus ») ne pointe pas vers le plugin.
Le garde `session_status()` couvre les exécutions **hors HTTP** (scénario, cron, API).
⚠️ `execute()` doit retourner une **chaîne** : `formatValue()` transforme un tableau en chaîne vide.

### `core/class/jeeroborockDaemon.class.php` *(modifié — 1 ligne)*

`const TIMEOUT_ACTION = 35;` — même enveloppe que `TIMEOUT_ETAT` par construction (démon borné à 30 s),
constante **distincte** pour que le budget d'une action reste modifiable sans toucher à celui de la
lecture. `tableMessages()` **et** `jeeroborockException::estErreurCanal()` restent **inchangées** : les
8 codes utilisés (`DEVICE_OFFLINE`, `DEVICE_ACTION_REFUSED`, `DEVICE_BUSY`, `UNSUPPORTED`,
`UNSUPPORTED_COMMAND`, `OPERATION_TIMEOUT`, `NOT_AUTHENTICATED`, `DEVICE_UNKNOWN`) sont tous de
**famille C** et déjà présents — vérifié un par un.

## Rafraîchissement post-action (AC6 et enchaînement action → relecture)

**Décision : la relecture est faite par le démon, dans la même opération, en best-effort, bornée par le
budget restant.** Motifs : (a) la docstring de `CommandTrait.send` impose à l'appelant de rafraîchir
lui-même les traits ; (b) sans push (UC10) ni cron (D-07-9), une action laisserait l'interface figée
jusqu'au prochain clic ; (c) la relecture coûte **une RPC sur un canal déjà ouvert**, **zéro quota
cloud** ; (d) le PHP réutilise le payload de `lireEtat`, donc **aucun** nouveau code d'écriture.

Déroulé (étape 7 de `envoyer_commande`) :

1. `restant = echeance - time.monotonic()` ; si `restant < RESTE_MINIMAL_RELECTURE_S` → on saute
   (`etatLu=False`, `capacites={}`, `etat={}`, `motifEchec=""`, `logging.info`).
2. `await asyncio.sleep(min(PAUSE_AVANT_RELECTURE_S, restant / 4))`.
3. ⚠️ **`restant` est recalculé depuis `echeance` après le `sleep`**, jamais décompté de façon figée
   avant, puis `await asyncio.wait_for(appareil.v1_properties.status.refresh(), min(DELAI_RELECTURE_S,
   restant))`.
4. Le tout dans un `except Exception` qui **ne relève jamais** (l'action a réussi : la transformer en
   erreur serait un faux négatif) et journalise le code en `info`.
5. Puis `_capacites(status, features)` + `_valeurs(status)` — fonctions **existantes du même module**,
   aucun symbole privé emprunté à un autre module (leçon R-15 d'UC07).

⚠️ **Politique volontairement différente de `lire_etat`**, qui relève `AUTH_EXPIRED`/`RATE_LIMIT`/
`PARSING_ERROR`. C'est pourquoi ce bloc **n'est pas factorisé** avec celui de `lire_etat` — et donc
pourquoi **`lire_etat` n'est pas refactorée** (zéro risque de régression UC07).

### Budget de temps

Tenu par **échéance monotone**, pas par des timeouts empilés. Chaîne vérifiée sur le code réel :
PHP `TIMEOUT_ACTION = 35` → `CURLOPT_TIMEOUT_MS = 35000` → `canal.py` calcule
`(35000 − MARGE_MS) / 1000 = 34,0 s` (marge **forfaitaire fixe** de 1000 ms) → démon borné à 30 s.

| Étape | Borne | Pire cas |
|---|---|---|
| Construction du `DeviceManager` (1ʳᵉ action après démarrage du démon) | `DELAI_CONSTRUCTION_S = 15` (UC07) | 15 s |
| Attente de connexion | `DELAI_ATTENTE_CONNEXION_S = 3` (UC07) | 3 s |
| Envoi | `min(22, échéance − maintenant)` | 12 s |
| Relecture | sautée si `restant < 4` | 0 s |
| **Total démon** | `DELAI_TOTAL_ACTION_S = 30` | **30 s** |

30 s démon < 34 s canal < 35 s PHP < plafond `TIMEOUT_MAX = 60`. Cas nominal (gestionnaire déjà
construit, robot connecté) : ~1 s d'envoi + 2 s de pause + ≤ 8 s de relecture ≈ **11 s**.

⚠️ **`DELAI_ENVOI_MAX_S = 22` est un plafond, pas la borne réelle dans tous les cas.** Il n'est
atteignable que si le gestionnaire est **déjà construit** (cas courant). À la **première action après le
démarrage du démon**, la construction et l'attente ont déjà consommé 18 s et le budget d'envoi retombe à
**12 s** — **inférieur** aux 20 s que la librairie peut légitimement consommer (2 stratégies × 10 s).
Ce n'est **pas une régression** : UC07 accepte exactement le même compromis (`DELAI_LECTURE_S = 12`
alors que le pire cas librairie est déjà de 20 s). UC08 **hérite** de ce compromis, il ne l'invente pas.
Conséquence concrète : dans ce cas de figure, un robot très lent produit `DEVICE_OFFLINE` (par le
test 1 de `_erreur_envoi`) plutôt qu'un succès — à distinguer en recette (R-6bis).

## Validation

| Quoi | Où (autoritaire) | Comportement / message |
|---|---|---|
| Droits d'exécution | **cœur** (`isConnect()` + `hasRight('x')`) | rien à écrire ; endpoint non-admin conforme |
| Équipement désactivé | **cœur** (`execCmd()`) | exception du cœur ; aucun garde plugin |
| `duid` de l'équipement | PHP, `duidValide()` (regex ancrée) | `DEVICE_UNKNOWN` |
| Compte lié | PHP, `estCompteLie()` | `NOT_AUTHENTICATED`, **sans appeler le démon** |
| Nom d'action | PHP (liste blanche fermée) **puis** démon (dict `ACTIONS`) | `UNSUPPORTED_COMMAND` / `INTERNAL_ERROR` ; **jamais** de chaîne libre passée à `command.send()` |
| `userData` vide/illisible | démon (défense en profondeur, via `obtenir_appareil`) | `NOT_AUTHENTICATED` / `AUTH_EXPIRED` |
| Robot inconnu du gestionnaire | démon | `DEVICE_UNKNOWN` |
| Robot non connecté | démon, `is_connected` **avant toute RPC** | `DEVICE_OFFLINE` (AC7) + PHP écrit `connecte = 0` |
| Robot muet | démon, `wait_for` + reclassement `_erreur_envoi` | `DEVICE_OFFLINE` (AC7) |
| Robot qui refuse | démon, `RoborockInvalidStatus` ou dict d'erreur | `DEVICE_ACTION_REFUSED` (AC8), code numérique **journalisé** |
| Quota `homedata` | démon, limiteur de la librairie, **avant tout réseau** | `RATE_LIMIT`, **non rattrapé, aucune retentative** (cadrage) |
| Structure de la réponse | PHP, `is_array` + listes blanches fermées d'UC07 | non conforme → rien écrit, aucun plantage |
| Budget | démon 30 s < canal 34 s < PHP 35 s | `OPERATION_TIMEOUT` puis `DAEMON_TIMEOUT` en dernier recours |
| Création d'une commande | PHP, `try/catch` **par commande** + troncature 256 | un échec n'interrompt ni les autres, ni `postSave()` |

**Typage** : `jeeroborockException` sur tout le chemin PHP (message français déjà traduit, affiché tel
quel par l'alerte rouge du cœur via `data.result`) ; `ErreurDemon` côté Python, **levée avec
`from erreur`** pour conserver la chaîne dans le log du démon.

**Secrets** : `local_key`, `HomeData` et `UserData` ne quittent pas le démon ; `contexte` **jamais**
sérialisé ; `appeler()` ne journalise que les **noms** de clés ; le `userData` n'apparaît **jamais** en
argument scalaire ; aucun `diagnostic_data()`.

**Boucle asyncio** : `command.send()` et `status.refresh()` sont 100 % asyncio (aiomqtt / TCP asyncio) ⇒
**aucun `asyncio.to_thread` requis**, conformément au constat d'UC07.

**Robustesse cron** : sans objet (D-07-9 maintenu, aucun hook cron dans cette UC). `executerAction()` est
toutefois conçue pour être appelable sous `try/catch` par équipement si une UC ultérieure l'utilise en
boucle.

## Dépendances

**Aucune.** `python-roborock` 7.8.0 reste l'unique dépendance ; `packages.json` n'est pas touché.

## Impact i18n (français uniquement dans cette UC)

`core/i18n/*.json` **non touchés** pendant l'implémentation (traduction en fin de cycle par
`translator`). **8 littérales françaises**, toutes dans `core/class/jeeroborock.class.php`, toutes
**littérales** (`sprintf` **autour** de `__()`, jamais `__($variable)`) :

1. « Démarrer » · 2. « Mettre en pause » · 3. « Arrêter » · 4. « Retour à la base » · 5. « Localiser »
· 6. « Rafraîchir » — *dans* `definitionsActions()`
7. « Commande « %s » transmise au robot. » — `executerAction()`
8. « État rafraîchi. » — `jeeroborockCmd::execute()`

⚠️ **À signaler au `translator`** : « État rafraîchi. » existe déjà sous
`core/ajax/jeeroborock.ajax.php` (UC07). Les fichiers i18n étant indexés **par fichier**, elle doit
exister **aussi** sous `core/class/jeeroborock.class.php` — **ce n'est pas un doublon à factoriser**
(même situation qu'UC03/UC05/UC07).

Logs `log::add` et logs du démon : français **non enveloppé**. Codes stables (`DEVICE_OFFLINE`…) et clés
d'action (`retour_base`…) : **jetons stables**, jamais traduits, jamais affichés.

## Risques & pièges

- **R-1 (majeur, AC7)** — `is_connected` **ne prouve pas** que le robot est allumé.
  `V1Channel.is_mqtt_connected` exige seulement que la session MQTT soit établie **et** l'abonnement au
  topic du robot accepté ; la docstring affirme couvrir « device offline or deleted », mais ce n'est
  **pas vérifié sur matériel réel**. Si l'abonnement réussit robot éteint, le chemin rapide ne se
  déclenche pas et l'utilisateur attend 10-22 s avant le message. C'est précisément pourquoi le timeout
  d'envoi est reclassé en `DEVICE_OFFLINE` plutôt qu'en `ROBOROCK_TIMEOUT`. **Hypothèse de recette.**
- **R-2 (majeur)** — **un timeout d'envoi ne prouve pas que la commande n'a pas été exécutée.** Le
  `publish` MQTT a pu aboutir ; seule la réponse manque. L'utilisateur verra « robot hors ligne » alors
  que le robot peut démarrer. **Non corrigeable** (le protocole V1 n'a pas d'accusé de réception
  séparé). C'est aussi ce qui justifie le message de succès neutre (« transmise au robot »).
- **R-3 (majeur)** — `demarrer` après une pause **ne « reprend » pas forcément** : `app_start` relance un
  nettoyage complet ; la reprise d'un nettoyage de pièce/zone passe par
  `resume_segment_clean`/`resume_zoned_clean`, **hors périmètre MVP** (UC24/25). Enchaîner AC2 puis AC1
  en recette peut donc produire un nettoyage complet au lieu d'une reprise. **À constater, pas à
  corriger ici.**
- **R-4** — deux constructions concurrentes du `DeviceManager` (2 `homedata` + 2 sessions MQTT).
  **Dette latente d'UC07 aggravée par UC08** (6 points d'appel au lieu d'un), neutralisée par
  `_VERROU_GESTIONNAIRE`. Sans ce verrou, le symptôme serait un `RATE_LIMIT` inexplicable, **y compris
  dans l'application mobile de l'utilisateur**.
- **R-5 (sécurité)** — **nouveau chemin de sortie non maîtrisé** : `cmd.ajax.php` sort par
  `displayException()`, qui rend `getTraceAsString()` dans le DOM quand le niveau de log **global** vaut
  `debug`. Neutralisé parce que le `userData` ne circule que **dans un tableau** (rendu `Array`) ; toute
  évolution qui le passerait en argument scalaire **rouvrirait la faille**. **Invariant à rappeler en
  revue.**
- **R-6** — verrou de session : sans `session_write_close()` dans `execute()`, l'interface Jeedom se fige
  jusqu'à 35 s par action.
- **R-7** — `dontRemoveCmd()` (D-07-7) s'applique **aussi** aux actions : l'icône « supprimer » reste
  sans effet sur les 6 nouvelles commandes. Contrepartie déjà assumée en UC07.
- **R-8** — la relecture post-action peut être **en avance sur le robot** : à 2 s, l'état lu peut encore
  être celui d'avant l'action (AC1 : « À la base » au lieu de « En nettoyage »). Sans conséquence
  durable (la valeur suivante corrige), mais **la valeur de la pause est à confirmer en recette**.
- **R-9** — `RoborockDeviceBusy` est **inatteignable** sur ce chemin (cf. § AC8). Le message « Le robot
  est occupé » ne sortira que si une UC ultérieure appelle `home`/`maps`.
- **R-10** — les 5 actions sont créées **inconditionnellement** : aucun drapeau de `DeviceFeatures` ne
  les couvre, et la « pertinence pour le modèle » est vérifiée **à l'exécution** (`unknown_method` →
  `RoborockUnsupportedFeature` → « Cette fonction n'est pas disponible sur ce modèle de robot. »).
  Arbitrage assumé : une table de capacités spéculative serait **plus fausse** que le refus réel du robot.
- **R-11** — le canal V1 **tente toujours le TCP local** (R-10 d'UC07, non désactivable en 7.8.0) : une
  liaison locale instable peut consommer 10 s avant le repli MQTT. `DELAI_ENVOI_MAX_S = 22` est
  dimensionné pour **ne jamais couper ce repli** — sauf au premier appel post-démarrage (§ Budget).
- **R-12** — contrainte pour **UC09** : les routines passent par `device.v1_properties.routines`, donc
  par `robots.obtenir_appareil()`, et **profiteront du verrou** posé ici. **Ne pas ré-ouvrir un second
  chemin de construction.**
- **R-13** — dette UC07 **inchangée, non aggravée** : `canal.py::handler_rpc` journalise toujours
  `exc_info=True` ; `_texte()` reste dupliqué entre `equipements.py` et `robots.py` (**pas de 3ᵉ
  occurrence créée**) ; `contexte['session']` reste construit inline par les 4 opérations d'origine
  (**aucune 5ᵉ occurrence créée**).
- **Rappel outillage** : `python .claude/scripts/verif-plugin.py` (colonne `meta=`) avant commit ; aucune
  double accolade ouvrante, aucun délimiteur de fin de commentaire collé à du texte, aucune balise
  fermante PHP dans les nouveaux commentaires.

## Recette (à confirmer sur une Jeedom réelle, Debian 12+)

| # | AC | Vérification | Attendu |
|---|---|---|---|
| R-1 | — | après mise à jour, cliquer « Rafraîchir l'état » sur un robot **existant** | les 6 commandes action apparaissent, **une seule fois** (retrofit) |
| R-2 | **AC1/AC2/AC3** | Démarrer → attendre → Mettre en pause → Arrêter | le robot obéit ; retour « Commande « … » transmise au robot. » ; `État` suit après la relecture |
| R-3 | **AC2 / point « À confirmer » de la spec** | Mettre en pause sur un robot **qui ne nettoie pas** | **comportement à relever** : succès silencieux **ou** « Le robot a refusé l'action… ». Consigner le résultat dans cette spec |
| R-4 | **AC4** | Retour à la base | le robot rentre ; `generic_type` `DOCK` visible dans la configuration de la commande |
| R-5 | **AC5** | Localiser | signal sonore sur le robot |
| R-6 | **AC7** | robot **éteint**, puis Démarrer | alerte rouge « Le robot est hors ligne… » **en moins de 25 s** ; `Connecté` passe à 0 ; **jamais** de succès affiché |
| R-6bis | **AC7 / R-1** | robot **allumé mais lent** (ou liaison locale instable), première action après redémarrage du démon | relever **lequel** des deux chemins s'est déclenché (garde `is_connected` = immédiat, vs. timeout = 10-22 s) dans le log du démon ; valider que le message reste juste |
| R-7 | **AC8** | Démarrer pendant un vidage de bac / station occupée | alerte « Le robot a refusé l'action dans son état actuel. » ; **code numérique visible dans le log du démon**. ⚠️ **Ne pas cocher AC8 sans ce constat** ; l'absence du message « robot occupé » est un **attendu** (§ AC8) |
| R-8 | **AC6** | Rafraîchir depuis le dashboard | commandes info mises à jour sans passer par la page d'administration |
| R-9 | quota | 10 actions d'affilée, log du démon | **une seule** construction de gestionnaire, **aucun** `homedata` supplémentaire, aucun `RATE_LIMIT` |
| R-10 | R-4 | deux actions déclenchées **simultanément** après redémarrage du démon (2 onglets) | **une seule** ligne de construction de gestionnaire (preuve du verrou) |
| R-11 | R-6 | pendant une action longue, naviguer dans Jeedom | interface **fluide** (preuve du `session_write_close()`) |
| R-12 | **non-régression** | rejouer UC04/UC05/UC06/UC07 | comportement identique ; **en particulier « Rafraîchir l'état » (UC07)**, malgré l'extraction de `_attendre_connexion()` |
| R-13 | secrets | log **global** en `debug`, rejouer une action en erreur | ni jeton ni blob base64 dans le DOM, `log/jeeroborock`, `log/jeeroborock_demon`, `log/php` |
| R-14 | scénario | action `demarrer` depuis un **scénario** Jeedom | exécution OK, message de succès dans le log du scénario ; erreur explicite si robot hors ligne |
| R-15 | R-8 | après un Démarrer, observer `État` | si l'état affiché est encore celui d'avant l'action, **relever** et ajuster `PAUSE_AVANT_RELECTURE_S` |

## Dette

*Bilan des reviews croisées (tour 1) : sécurité **0 critical / 0 high** (1 `medium`), qualité
**0 blocker / 1 `major`** (+ 3 `minor`). Le `major` et le `medium` désignaient **le même défaut** —
la réponse RPC du robot journalisée sans passer par `_texte()` — trouvé indépendamment par les deux
reviewers. Les 4 findings ont été corrigés en **un lot unique** ; pas de tour 2 (lot purement mécanique,
delta vérifié par diff en orchestration). **Aucun finding n'a été reporté en dette.** Corrigés :
neutralisation de `resultat` dans les deux `logging` post-envoi ; coercition de `action` en chaîne avant
`ACTIONS.get()` ; commentaire sur `motifEchec` vide par construction ; `definitionsActions()` ramené
dans un `try/catch` pour rendre structurel le contrat « `appliquerActions()` ne lève jamais ».*

Reste ci-dessous ce qui est sciemment reporté :

- **Héritée d'UC07, non aggravée** — `_texte()` dupliqué (`equipements.py` / `robots.py`) ;
  `canal.py::handler_rpc` avec `exc_info=True` ; `contexte['session']` construit inline par les
  4 opérations d'origine. UC08 ne crée **aucune** occurrence supplémentaire des trois.
- **`appliquerActions()` appelle `save()` à chaque passage** sur une commande déjà correcte (hérité du
  comportement d'`appliquerCapacites()` d'UC07). Sans impact au MVP (1 robot) ; **à revoir si UC10
  introduit un cron périodique sur N robots**.
