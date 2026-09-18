# Spec technique — UC09 : Routines (« usages ») : synchronisation et exécution

> **Spec fonctionnelle** : `09-routines-usages.md` · **Dépend de** : UC03 (canal), UC06 (équipements), UC08 (routage `execute()`)
> **Contrats externes vérifiés** le 2026-09-18 sur le wheel `python_roborock-7.8.0-py3-none-any.whl` (lecture verbatim).

## Principe directeur

Une routine s'exécute par **un unique POST HTTPS signé Hawk**, sur un chemin **strictement disjoint** du
`DeviceManager`, de MQTT et du quota `homedata`. C'est ce qui rend AC7 structurel et non déclaratif :
le module `routines.py` n'importe ni `robots.py`, ni `contexte["gestionnaire"]`.

| AC | Réalisé par |
|---|---|
| **AC1** | `listerRoutines` → `synchroniserRoutines()` → `appliquerRoutines()` crée une `jeeroborockCmd` action/other par scène, `setName(<nom>)` |
| **AC2** | `execute()` → `executerRoutine($cmd)` → opération démon `executerRoutine` → `execute_scene()` |
| **AC3** | clé de rapprochement = `logicalId` (`routine_<sceneId>`), **jamais le nom** ; `setName()` réappliqué sous garde de personnalisation |
| **AC4** | `configuration['routineObsolete'] = 1` posé par la synchro ; testé **avant tout appel démon** dans `executerRoutine()` → `ROUTINE_OBSOLETE` ; `dontRemoveCmd()` assoupli (D-09-4) |
| **AC5** | création **uniquement** si le `logicalId` est absent de la table des commandes existantes |
| **AC6** | liste vide = **succès** (`ajax::success`), message pédagogique ; aucun code d'erreur créé pour ce cas |
| **AC7** | `executerRoutine` (démon) n'appelle ni `obtenir_appareil()`, ni `create_device_manager()` : **zéro `homedata`, zéro session MQTT, zéro RPC V1** |

## Contrats externes (`python-roborock` 7.8.0)

1. **`RoborockApiClient.get_scenes(user_data, device_id) -> list[HomeDataScene]`** — `web_api.py` l. 589-609.
   `GET <rriot.r.a>/user/scene/device/<duid>`, Hawk. **Aucun limiteur** (`try_acquire` absent ; les seuls
   limiteurs du fichier sont `_login_limiter` et `_home_data_limiter`, l. 52-66).
   `if not response.get("success"): raise RoborockException(response)` ⇒ **`args[0]` est le dict brut**.
2. **`RoborockApiClient.execute_scene(user_data, scene_id) -> None`** — l. 678-693.
   `POST <rriot.r.a>/user/scene/<scene_id>/execute`, **sans corps**. **Aucun retour d'état** : succès =
   absence d'exception. Idem `args[0]` = dict sur refus.
3. **`HomeDataScene`** — `data/containers.py` l. 346-348 : `id: int`, `name: str`, **exactement deux champs
   requis**. ⇒ le point « enrichir le libellé » de la spec fonctionnelle est **clos négativement**
   (`RoborockBase.from_dict` écarte les clés inconnues ; aucun accès à la réponse brute).
   ⚠️ `containers.py` n'a **pas** `from __future__ import annotations` ⇒ `convert_dict` coerce réellement,
   mais `if value == "None" or value is None: result[key] = None` (l. 145-147) ⇒ **`scene.name` peut valoir
   `None`**. ⚠️ Un champ requis absent fait lever `TypeError` sur `cls(**result)` ⇒ **toute la liste** échoue.
4. **`PreparedRequest.request()`** — l. 796-819. Base d'URL = `rriot.r.a`, **jamais** `self._base_url`
   ⇒ le `baseUrl` transmis est inopérant ici (passé par cohérence et pour le garde `base_url=(v or None)`).
   `session=None` ⇒ `ClientSession` créée et fermée par requête. **Aucun `timeout=`** ⇒ borner par
   `asyncio.wait_for`. Panne réseau → `RoborockException(str)` avec `__cause__` ⇒ `code_pour_exception()`
   la résout déjà en `CLOUD_UNREACHABLE`.

> ⚠️ **Écart avec la connaissance interne — corrigé.** UC07 § R-14 et UC08 § R-12 affirmaient que les
> routines passent par `device.v1_properties.routines`, donc par `obtenir_appareil()`. **La source dit le
> contraire** : `RoutinesTrait` (`devices/traits/v1/routines.py`) et `UserWebApiClient` (l. 869-926) ne sont
> qu'un wrapper de `get_scenes`/`execute_scene` ; y passer imposerait de construire le `DeviceManager`, soit
> **1 `homedata` (quota dur) + 1 session MQTT**, ce qu'AC7 et le cadrage quota interdisent. Le code de la
> librairie fait foi. **Fait** : UC07 § R-14 et UC08 § R-12 portent désormais un encart de correction daté,
> et le commentaire de module de `resources/demond/robots.py` qui répétait la même affirmation a été corrigé
> dans ce cycle (finding `minor` de la review qualité).

## Architecture — fichiers

| Fichier | État | Contenu | Indentation / EOL |
|---|---|---|---|
| `resources/demond/textes.py` | **créé** | `LONGUEUR_MAX_TEXTE`, `_CARACTERES_CONTROLE`, `texte()` — **solde la dette R-15 d'UC07** (3ᵉ occurrence ⇒ factorisation prescrite) | 4 espaces, **LF** |
| `resources/demond/routines.py` | **créé** | `lister()`, `executer()`, `_erreur_cloud()`, `enregistrer_operations()` | 4 espaces, **LF** |
| `resources/demond/equipements.py` | **modifié (pure suppression)** | retrait de `import re`, `LONGUEUR_MAX_TEXTE`, `_CARACTERES_CONTROLE`, `def _texte` ; ajout `from textes import texte as _texte`. **Zéro site d'appel modifié** | 4 espaces, LF |
| `resources/demond/robots.py` | **modifié (pure suppression)** | idem, strictement | 4 espaces, LF |
| `resources/demond/jeeroborockd.py` | **modifié (2 lignes)** | `import routines` + `routines.enregistrer_operations()` | 4 espaces, LF |
| `core/class/jeeroborock.class.php` | **modifié** | 7 constantes, `synchroniserRoutines()`, `appliquerRoutines()`, `executerRoutine()`, `sceneIdDepuisLogicalId()`, garde de fréquence ; routage par préfixe dans `execute()` ; `dontRemoveCmd()` assoupli | **2 espaces, CRLF** |
| `core/class/jeeroborockDaemon.class.php` | **modifié** | 2 constantes de budget + **3 littérales** dans `tableMessages()` | 2 espaces, CRLF |
| `core/ajax/jeeroborock.ajax.php` | **modifié** | 1 `case 'synchroniserRoutines'` | 2 espaces, CRLF |
| `desktop/php/jeeroborock.php` | **modifié** | 1 bouton `bt_jeeroborockSynchroniserUsages` | ⚠️ **TABULATIONS + CRLF** |
| `desktop/js/jeeroborock.js` | **modifié** | 1 gestionnaire de clic | **2 espaces, CRLF** — ⚠️ fichier **rendu** |
| `core/class/jeeroborockException.class.php` | **NON modifié** | les 3 codes sont de **famille C** ⇒ `estErreurCanal()` (l. 46-55, 7 codes A/B) ne les connaît pas et ne doit pas les connaître. **Le piège de la liste dupliquée d'UC03 ne s'applique pas** | — |
| `plugin_info/configuration.txt` / `.php` | **NON touchés** | aucune clé de config plugin ⇒ **pas de `cp`**, aucun risque de désynchronisation du miroir | — |
| `core/php/jeeroborock.inc.php`, `packages.json`, `info.json`, `core/config/*.ini`, `core/template/**`, `core/php/jeeJeeroborock.php`, `desktop/modal/*` | **non touchés** | aucune classe PHP nouvelle (autoload sans objet), aucune dépendance, widgets `core::default`, aucun push, aucune modale | — |
| `core/i18n/*.json` | **non touchés dans ce cycle** | traduction en fin de cycle par `translator` | — |

## Décisions d'architecture

- **D-09-1 — Le chemin routines n'emprunte PAS `robots.obtenir_appareil()`.** Module dédié `routines.py`,
  sans dépendance à `robots.py` ni au `contexte["gestionnaire"]`. *Écarté* : le trait `v1_properties.routines`
  — 1 `homedata` + 1 session MQTT pour zéro apport, et casse AC7.
- **D-09-2 — Déclencheur = bouton PAR ÉQUIPEMENT**, à côté de « Rafraîchir l'état » (UC07). L'endpoint est
  par appareil (`/user/scene/device/<duid>`). *Écarté* : un bouton global page plugin — boucle sur N robots
  × 20 s, hors budget (même argument qu'en UC07).
- **D-09-3 — Garde de fréquence : cooldown 60 s par équipement**, clé de cache
  `jeeroborock::synchroRoutines::<eqLogicId>`, TTL 300 s, horodatage écrit **après succès démon** (un échec
  local ne doit pas imposer une minute d'attente). Portée par la **méthode**, pas par le `case` AJAX, pour que
  tout appelant futur en hérite. **Aucune retentative, jamais.** Tranche le point « fréquence de
  resynchronisation » laissé ouvert par la spec fonctionnelle.
- **D-09-4 — `dontRemoveCmd()` devient conditionnel** : `false` **si et seulement si** `logicalId` préfixé
  `routine_` **et** `routineObsolete == 1`. AC4 exige que l'utilisateur puisse « supprimer manuellement » la
  commande ; or D-07-7 (`return true`, `jeeroborock.class.php:1173-1175`) rend l'icône de suppression inerte.
  Une commande vivante reste intouchable (R-6 d'UC07 préservé) ; un onglet périmé ne peut détruire que des
  commandes déjà mortes. *Écarté* : un bouton « purger les usages obsolètes » — une UI de plus pour un geste
  que le cœur sait déjà faire.
- **D-09-5 — Aucune relecture d'état après exécution** *(arbitré par l'utilisateur, 2026-09-18)*. Une relecture
  passe par `status.refresh()`, donc par le canal V1, donc par le `DeviceManager` : elle **annulerait AC7**.
  Message de succès volontairement neutre (« lancé », pas « en cours de nettoyage »). Incohérence assumée avec
  les 6 boutons d'UC08, qui rafraîchissent ; levée par UC10 (push MQTT). *Écarté* : une relecture opportuniste
  si le gestionnaire est déjà construit — couplage `routines.py` → `robots.py` refusé.
- **D-09-6 — La clé de rapprochement est le `logicalId`, le nom n'est jamais une clé.** `sceneId` **dérivé**
  du `logicalId` par regex ancrée — aucun stockage redondant, donc aucune divergence entre deux sources de vérité.
- **D-09-7 — Un module démon par domaine, enregistré explicitement** depuis `jeeroborockd.principal_async`
  (jamais par effet de bord d'import). Deux opérations : `listerRoutines`, `executerRoutine`. **Aucune nouvelle
  route HTTP.**
- **D-09-8 — `contexte["session"]` n'est PAS réécrit** par ces opérations ⇒ **aucune 5ᵉ occurrence** du dict
  inline : la dette d'UC06 n'est pas aggravée.
- **D-09-9 — Aucun signal visuel supplémentaire pour une routine obsolète** *(arbitré par l'utilisateur)*.
  Ni mutation du nom, ni `isVisible = 0` : le refus explicite à l'exécution et la phrase du compte rendu
  suffisent. Motif : muter le nom est non idempotent et perd le nom d'origine si la routine réapparaît ;
  masquer rend le problème invisible.
- **D-09-10 — Commandes de routine créées `isVisible = 1`** *(arbitré par l'utilisateur)*, comme les actions
  d'UC08 : après une synchro, l'utilisateur doit **voir** le résultat. Une tuile chargée se masque commande
  par commande.

## Server vs Client

Tout est **serveur**. Le client (`desktop/js/jeeroborock.js`) ne porte **aucune règle métier** : un verrou de
bouton, une garde « un robot est-il sélectionné », un appel AJAX, un `showAlert`. Aucun libellé dérivé de
compteurs (D-06-9). Motif : le compte rendu de synchronisation contient des **noms d'origine utilisateur**
(l'application mobile), qui ne doivent être composés qu'à un seul endroit, côté serveur, après neutralisation.

## Signatures & responsabilités

### `resources/demond/textes.py` *(créé — solde R-15 d'UC07)*

```python
LONGUEUR_MAX_TEXTE = 128
_CARACTERES_CONTROLE = re.compile(r"[\x00-\x1f\x7f]")
def texte(valeur, longueur_max=LONGUEUR_MAX_TEXTE) -> str
#   "" si None ; str() ; neutralisation des caracteres de controle AVANT troncature.
#   CORPS REPRIS A L'IDENTIQUE des deux copies privees d'equipements.py / robots.py.
```

`equipements.py` et `robots.py` : `from textes import texte as _texte`, **aucun site d'appel modifié**, le diff
y est une pure suppression. Vérifié : `re.` n'est utilisé nulle part ailleurs dans ces deux fichiers (seule
occurrence : `_CARACTERES_CONTROLE = re.compile(...)`). ⚠️ `resources/demond/` est `sys.path[0]` : vérifier
qu'aucun paquet de l'arbre de dépendances n'expose un module de premier niveau `textes` (même précaution que
`session.py` en UC06). Un oubli se verrait au **premier appel** (`NameError`), pas à l'import.

### `resources/demond/routines.py` *(créé)*

```python
DELAI_LISTE_S = 15
DELAI_EXECUTION_S = 15
LIMITE_ROUTINES = 64

async def lister(parametres, contexte) -> dict
async def executer(parametres, contexte) -> dict
def _erreur_cloud(erreur, code_si_refus) -> ErreurDemon
def enregistrer_operations() -> None   # canal.enregistrer("listerRoutines", lister)
                                       # canal.enregistrer("executerRoutine", executer)
```

**`lister` — ordre imposé :**
1. `session.IMPORT_OK` faux → `ErreurDemon("INTERNAL_ERROR")`.
2. `userData` vide → `NOT_AUTHENTICATED` ; `duid` vide → `DEVICE_UNKNOWN`.
3. `session.decoder_user_data(...)` (→ `AUTH_EXPIRED`).
4. `client = session.creer_client(email, baseUrl)`.
5. `await asyncio.wait_for(client.get_scenes(user_data, duid), DELAI_LISTE_S)` ; `asyncio.TimeoutError` →
   `OPERATION_TIMEOUT` ; autre → `raise _erreur_cloud(erreur, "ROBOROCK_ERROR") from erreur`.
6. Boucle bornée à `LIMITE_ROUTINES` : `identifiant = int(scene.id)` sous `try/except (TypeError, ValueError)`
   → scène **ignorée** + `logging.warning` ; `identifiant <= 0` → ignorée ; `nom = _texte(scene.name)`
   (⚠️ peut être `None`). Dépassement de la borne → `logging.warning` comptant les omis.
7. Retour `{"duid": _texte(duid, 128), "nbTotal": <nb de scènes reçues>, "routines": [{"id": int, "nom": str}, …]}`.

⚠️ **Aucun `asyncio.to_thread`** : `get_scenes` est 100 % asyncio (aiohttp) et le post-traitement est une boucle
bornée en mémoire (R1 d'UC03 respecté). ⚠️ La **réponse brute du cloud n'est jamais journalisée**.

**`executer` — ordre imposé :**
1-4. identiques, plus : `scene_id = int(parametres.get("sceneId"))` sous garde ; `<= 0` ou non convertible →
   `logging.error` + `ErreurDemon("INTERNAL_ERROR")` (désynchronisation PHP/démon — défense en profondeur,
   le PHP a déjà validé).
5. `await asyncio.wait_for(client.execute_scene(user_data, scene_id), DELAI_EXECUTION_S)` ; `TimeoutError` →
   `OPERATION_TIMEOUT` ; autre → `raise _erreur_cloud(erreur, "ROUTINE_INTROUVABLE") from erreur`.
6. `logging.info("executerRoutine : usage %s lance pour duid=%s", scene_id, _texte(duid, 16))`.
7. Retour `{"duid": …, "sceneId": scene_id}`.

**`_erreur_cloud(erreur, code_si_refus)` — ordre imposé :**
1. `args[0]` est un **`dict`** → `logging.info("routines : refus du cloud code=%s", args[0].get("code"))` puis
   `ErreurDemon(code_si_refus)`. Détection **de forme**, jamais d'un message anglais : `get_scenes`/`execute_scene`
   sont les seuls endroits qui construisent une `RoborockException` à partir de la réponse (l. 603-604, 692-693).
   Le code numérique est **journalisé** — c'est la seule source qui rendra le point de recette R-10 concluant.
2. Sinon `code_pour_exception(erreur)` → préserve `CLOUD_UNREACHABLE` (via `__cause__`), `AUTH_EXPIRED`,
   `RATE_LIMIT`, `PARSING_ERROR`… **Aucune exception n'est avalée.**

⚠️⚠️ **Ne PAS réutiliser `robots._erreur_envoi()`** : son test classe tout `args[0]` dict en
`DEVICE_ACTION_REFUSED`, sémantique conçue pour le dict d'erreur **RPC V1** (`_create_api_error`) ; ici le dict
est une **réponse HTTP cloud**, de forme et de sens différents. Deux classifieurs, volontairement non factorisés.

### `core/class/jeeroborockDaemon.class.php`

```php
const TIMEOUT_ROUTINES_SYNC = 20;   // s, listerRoutines
const TIMEOUT_ROUTINE_EXEC  = 20;   // s, executerRoutine
```

Cascade : `appeler()` envoie `budgetMs = 20000` → `canal.py` calcule `wait_for((20000 − 1000)/1000) = 19 s` →
démon borné à **15 s**. ⇒ **démon 15 s < canal 19 s < PHP 20 s < jQuery 30 s** : le démon coupe le premier,
l'utilisateur lit `OPERATION_TIMEOUT` et non un silence. Sous `TIMEOUT_MAX = 60` (R12 d'UC03).
⚠️ Pour `executerRoutine`, le chemin est `cmd.ajax.php` → `jeedom.cmd.execute`, **sans timeout jQuery** : rien
à régler côté client.

Trois entrées ajoutées à `tableMessages()` (**famille C uniquement**).

### `core/class/jeeroborock.class.php`

```php
const PREFIXE_CMD_ROUTINE          = 'routine_';
const NB_MAX_ROUTINES              = 64;
const LONGUEUR_MAX_NOM_ROUTINE     = 127;  // aligne sur la troncature de cmd::setName
const ORDRE_BASE_ROUTINES          = 30;   // info 0-11, reserve 12-19, actions 20-25
const DELAI_MIN_SYNCHRO_ROUTINES   = 60;   // s, garde anti-rafale (D-09-3)
const DUREE_CACHE_SYNCHRO_ROUTINES = 300;  // s, TTL de l'horodatage
const CLE_CACHE_SYNCHRO_ROUTINES   = 'jeeroborock::synchroRoutines::';

public  function synchroniserRoutines()
//   -> array('creees'=>int,'misAJour'=>int,'echecs'=>int,'total'=>int,'obsoletes'=>array<string>)
//   throws jeeroborockException. A APPELER SOUS try/catch PAR EQUIPEMENT.
private function appliquerRoutines($_routines)  // -> array de compteurs ; NE LEVE JAMAIS
public  function executerRoutine($_cmd)         // -> string (message FR de succes)
private static function sceneIdDepuisLogicalId($_logicalId)  // -> int|null
private function synchroRoutinesRecente()       // -> bool
private function marquerSynchroRoutines()       // -> void ; NE LEVE JAMAIS
```

**`synchroniserRoutines()` — ordre imposé :**
1. `!self::duidValide(trim((string) $this->getLogicalId()))` → `erreurLocale('DEVICE_UNKNOWN')`.
2. `!self::estCompteLie()` → `erreurLocale('NOT_AUTHENTICATED')` — **aucun appel démon, aucun paquet réseau**.
3. `synchroRoutinesRecente()` → `erreurLocale('ROUTINE_SYNC_RECENTE')` (D-09-3).
4. `jeeroborockDaemon::appeler('listerRoutines', array('userData'=>…, 'baseUrl'=>…, 'email'=>…, 'duid'=>$duid),
   jeeroborockDaemon::TIMEOUT_ROUTINES_SYNC)`.
   ⚠️ Le `userData` **uniquement dans le tableau**, jamais en argument scalaire (invariant UC04/UC08 face à
   `displayException`).
5. `marquerSynchroRoutines()` (après succès).
6. Lecture **défensive** : `$routines = (isset($r['routines']) && is_array($r['routines']))
   ? array_slice($r['routines'], 0, self::NB_MAX_ROUTINES) : array();`
7. `$compteurs = $this->appliquerRoutines($routines);`
8. `log::add` récapitulatif. Mentionner explicitement **l'absence de consommation de quota `homedata`**
   (contrairement à UC06) : évite une fausse alerte en support.
9. Retour des compteurs, listes **déjà neutralisées** par `texteInventaire()`.

**`appliquerRoutines($_routines)` — cœur d'AC1/AC3/AC4/AC5 :**
1. Construire `$existantes` : `cmd::byEqLogicId($this->getId(), 'action')`, filtrée sur
   `strpos($logicalId, self::PREFIXE_CMD_ROUTINE) === 0`, indexée par `logicalId`. ⚠️ **Ne pas passer par
   `$this->getCmd()`** : son cache interne `_cmds` et l'énumération fraîche mélangeraient deux vues. Un
   `logicalId` déjà indexé (duplicata possible via « Dupliquer », R-9 d'UC06) → conserver la première occurrence
   et journaliser un `warning`.
2. Pour chaque routine, sous `try/catch (Throwable)` **par routine**, log **tronqué à 256 caractères** (trois
   exceptions de `cmd::save()` embarquent `print_r($this, true)`) :
   - validation de l'identifiant : `is_numeric` **et** `intval(...) > 0` **et**
     `(string) intval($id) === trim((string) $id)` (rejette `1e3`, les décimaux, les dépassements d'entier).
     Échec → `echecs++`, `log::add(warning)` avec `nettoyerPourLog(substr(..., 0, 64))`.
   - `$logicalId = self::PREFIXE_CMD_ROUTINE . intval($id)` ;
     `$nom = self::texteInventaire($routine['nom'], self::LONGUEUR_MAX_NOM_ROUTINE)`.
   - **Commande existante** :
     - `setConfiguration('routineObsolete', 0)` (une routine réapparue redevient exécutable) ;
     - **AC3 sous garde de personnalisation** :
       `if ($cmd->getConfiguration('nomRoutine','') === '' || $cmd->getConfiguration('nomRoutine','') === $cmd->getName()) { $cmd->setName($nom); }`
       puis, **après** `setName()`, `setConfiguration('nomRoutine', $cmd->getName())` — on stocke la valeur
       **relue** (donc déjà passée par `cleanComponanteName`), sans quoi la comparaison serait fausse dès qu'un
       nom contient un caractère filtré.
     - Convergence structurelle **uniquement** : `setType('action')`, `setSubType('other')`. **Jamais**
       `isVisible`/`order` (même règle qu'`appliquerActions()`/`appliquerCapacites()`).
     - `save()` ; `misAJour++` ; retirer la clé de `$existantes`.
   - **Commande absente** : `new jeeroborockCmd()` → `setEqLogic_id()`, `setEqType('jeeroborock')`,
     `setLogicalId($logicalId)`, `setName($nom)`, **puis repli**
     `if ($cmd->getName() == '') { $cmd->setName(sprintf(__('Usage Roborock %s', __FILE__), intval($id))); }`,
     `setType('action')`, `setSubType('other')`, `setIsVisible(1)` (D-09-10),
     `setOrder(self::ORDRE_BASE_ROUTINES + $rang)`, `setConfiguration('routineObsolete', 0)`,
     `setConfiguration('nomRoutine', $cmd->getName())`, `save()` ; `creees++`.
     ⚠️ **Pas de `setValue()`** (sinon `isAlreadyInStateAllow()` peut faire *sauter* l'exécution en la présentant
     comme un succès), **pas de `setTemplate()`** (le cœur pose `core::default`), **pas de `setGeneric_type()`**,
     **pas de `setIsHistorized()`**.
3. **AC4** — pour chaque entrée **restante** de `$existantes` : si `getConfiguration('routineObsolete', 0) != 1`
   → `setConfiguration('routineObsolete', 1)`, `save()`, `$obsoletes[] = $cmd->getName()`, `log::add(warning)`.
   Une commande **déjà** marquée reste marquée et **n'est pas recomptée** (le compte rendu ne ré-alerte pas à
   chaque synchro). **Jamais de `remove()`.**

**`executerRoutine($_cmd)` — ordre imposé (AC2/AC4/AC7) :**
1. `!self::duidValide(...)` → `erreurLocale('DEVICE_UNKNOWN')`.
2. `!self::estCompteLie()` → `erreurLocale('NOT_AUTHENTICATED')` — **aucun appel démon**.
3. ⚠️ `$_cmd->getConfiguration('routineObsolete', 0) == 1` → `erreurLocale('ROUTINE_OBSOLETE')` — **AVANT tout
   appel démon, donc zéro requête HTTPS**. C'est le chemin déterministe d'AC4.
4. `$sceneId = self::sceneIdDepuisLogicalId($_cmd->getLogicalId())` —
   `preg_match('/\Aroutine_([1-9][0-9]{0,17})\z/', …)`, **ancres `\A`/`\z`** (jamais `^`/`$`, cf. forge de ligne
   de log, UC03). `null` → `log::add(warning)` + `erreurLocale('UNSUPPORTED_COMMAND')`.
5. `jeeroborockDaemon::appeler('executerRoutine', array(…, 'duid'=>$duid, 'sceneId'=>$sceneId),
   jeeroborockDaemon::TIMEOUT_ROUTINE_EXEC)`.
6. `catch (jeeroborockException $e)` : **aucun effet de bord**. En particulier, **on ne marque PAS la commande
   obsolète sur `ROUTINE_INTROUVABLE`** — un refus cloud transitoire (session expirée, incident) rendrait
   définitivement inexécutable une routine parfaitement valide. Le marqueur n'est posé **que** par la
   synchronisation, seule vue complète et autoritaire. Journaliser en `warning` et relever.
   *(Différence assumée avec UC08, qui écrit `connecte = 0` sur `DEVICE_OFFLINE` : valeur d'info volatile, pas
   un marqueur structurel persistant.)*
7. `log::add(info)` avec l'id de scène et l'id d'équipement.
8. Retour `sprintf(__('Usage « %s » lancé.', __FILE__), $_cmd->getName())` — **formulation honnête** : le cloud
   a accepté l'ordre, ce qui n'est pas la preuve que le robot a démarré (`execute_scene` ne renvoie aucun état).
   ⚠️ **Scalaire obligatoire** : `formatValue()` écrase un tableau en chaîne vide.

**`synchroRoutinesRecente()` / `marquerSynchroRoutines()`** : `cache::byKey(...)->getValue('')` /
`cache::set(…, time(), self::DUREE_CACHE_SYNCHRO_ROUTINES)` sous `try/catch`, **ne lèvent jamais** (idiome
d'`enregistrerInventaireCompte()`, `:150-155`). Un incident de cache dégrade la garde, il ne casse pas la synchro.

### `jeeroborockCmd`

```php
public function dontRemoveCmd() {
  // D-09-4 : une commande de routine MARQUEE OBSOLETE redevient supprimable par le
  // chemin standard du coeur, ce qu'AC4 exige. Toute autre commande reste protegee
  // (D-07-7 / R-6 d'UC07 inchanges).
}
public function execute($_options = array()) {
  // 1. type != 'action' -> false                       (inchange)
  // 2. session_write_close() sous garde                (inchange, OBLIGATOIRE)
  // 3. $eqLogic = $this->getEqLogic()                  (inchange)
  // 4. NOUVEAU, AVANT le switch :
  //      if (strpos((string) $this->getLogicalId(), jeeroborock::PREFIXE_CMD_ROUTINE) === 0)
  //        return $eqLogic->executerRoutine($this);
  // 5. switch existant ('rafraichir' | default -> executerAction)
}
```

⚠️ Le test de préfixe doit précéder le `switch` : sans lui, un `routine_*` tomberait dans `default` →
`executerAction()` → `UNSUPPORTED_COMMAND` (« Le robot ne reconnaît pas cette commande »), message faux.
Vérifié : aucun `logicalId` existant (`rafraichir`, `demarrer`, `pause`, `arreter`, `retour_base`, `localiser`)
ne commence par `routine_` ⇒ aucune capture accidentelle.
⚠️ `session_write_close()` reste **obligatoire** : `cmd.ajax.php` ne le fait pas et l'appel peut durer 20 s.

### `core/ajax/jeeroborock.ajax.php` *(1 `case`)*

Séquence inchangée (`isConnect('admin')` → `ajax::init()` → `session_write_close()` **déjà en place**).

```
case 'synchroniserRoutines':
  1. $eqLogic = eqLogic::byId(intval(init('id')));
     !($eqLogic instanceof jeeroborock) -> ajax::error(<litterale EXISTANTE d'UC07>) ; break
     // pas de garde getIsEnable() : contrairement a 'rafraichirEtat', aucune valeur n'est ecrite
     // par checkAndUpdateCmd ; cmd::save() fonctionne sur un equipement desactive.
  2. $r = $eqLogic->synchroniserRoutines();   // jeeroborockException -> catch global du fichier
  3. composition du message (phrases jointes par un espace) :
       - si creees + misAJour > 0 : "Synchronisation des usages terminee : %1$s usage(s) cree(s), %2$s mis a jour."
       - sinon si total == 0      : message pedagogique AC6
       - si obsoletes             : message AC4 (5 premiers noms + suite)
       - si echecs > 0            : "%s usage(s) n'ont pas pu etre enregistres dans Jeedom. Consultez le log du plugin."
       - REPLI OBLIGATOIRE : si le message compose est VIDE -> "Synchronisation des usages terminee."
         (cas limite : des scenes recues mais toutes rejetees cote demon -> les 4 branches sont fausses
          et showAlert afficherait un toast vide)
  4. ajax::success(array('message'=>$message,'creees'=>int,'misAJour'=>int,'obsoletes'=>count(...),'echecs'=>int));
```

⚠️ Réponse **reconstruite champ par champ** (5 scalaires) : `$r` ne repart jamais brut, le `userData` n'y entre à
aucun moment. ⚠️ **Aucun `message::add()`** : il est rendu **en HTML** et le rapport contient des noms de routines
d'origine utilisateur. Le compte rendu est inséré par `.text()`/`showAlert`.

### `desktop/php/jeeroborock.php` *(⚠️ TABULATIONS + CRLF)*

Une balise, insérée **après** `bt_jeeroborockRafraichirEtat` et **avant** `data-action="copy"` — ⚠️ respecter le
style du fichier : les balises fermantes d'ancre sont volontairement rejetées à la ligne suivante, ne pas le
« corriger ».

`<a class="btn btn-sm btn-default" id="bt_jeeroborockSynchroniserUsages"><i class="fas fa-list-check"></i><span class="hidden-xs"> {{Synchroniser les usages}}</span>`

### `desktop/js/jeeroborock.js` *(2 espaces, CRLF)*

Calque du gestionnaire `#bt_jeeroborockRafraichirEtat` : verrou **propre** `jeeroborockVerrouUsages` (booléen
**ET** `addClass('disabled')` — un `<a class="btn">` ignore `prop('disabled')`), garde « un robot est-il
sélectionné », `success` → `showAlert` `success`/`danger` selon `data.state`, `error` → littérale **déjà
présente** « Le démon ne répond pas. » (**ne pas la redéclarer**).

⚠️ **`timeout: 30000` — divergence INTENTIONNELLE du calque.** Le gestionnaire `rafraichirEtat` utilise
`timeout: 50000` (`desktop/js/jeeroborock.js:110`) parce que son budget PHP est `TIMEOUT_ETAT = 35` s. Ici le
budget est `TIMEOUT_ROUTINES_SYNC = 20` s ⇒ 30 s couvre le budget serveur avec marge. **Ne pas copier 50000 par
réflexe.**

⚠️ **Fichier RENDU** (`getResource.php` → `translate::exec(…, $_backslash = true)`) : littérales traduisibles en
**apostrophes simples**, aucune double accolade ouvrante hors clé i18n — et `verif-plugin.py` **ne contrôle pas
ce fichier**, relecture manuelle obligatoire.

## Validation

| Quoi | Où (autoritaire) | Comportement |
|---|---|---|
| Droits d'exécution d'une commande | **cœur** (`cmd.ajax.php` : `isConnect()` + `hasRight('x')`) | rien à écrire |
| Droits de synchronisation | `isConnect('admin')` du fichier AJAX | endpoint **admin** : l'action crée/modifie des commandes |
| Équipement désactivé (exécution) | **cœur** (`execCmd()`) | exception du cœur ; aucun garde plugin |
| Équipement existant et du bon type | PHP, `instanceof jeeroborock` | littérale **existante** d'UC07 |
| `duid` de l'équipement | PHP, `duidValide()` (regex ancrée) | `DEVICE_UNKNOWN` |
| Compte lié | PHP, `estCompteLie()` | `NOT_AUTHENTICATED`, **sans appeler le démon** |
| Fréquence de synchro | PHP, cooldown 60 s (cache) | `ROUTINE_SYNC_RECENTE`, **sans appeler le démon** |
| **Routine obsolète** | PHP, `cmd.configuration['routineObsolete']`, **avant l'appel démon** | `ROUTINE_OBSOLETE` (AC4) |
| Format du `logicalId` de routine | PHP, `preg_match('/\Aroutine_([1-9][0-9]{0,17})\z/')` | `UNSUPPORTED_COMMAND` + `log warning` |
| `userData` vide / illisible | démon (défense en profondeur) | `NOT_AUTHENTICATED` / `AUTH_EXPIRED` |
| `sceneId` reçu par le démon | démon, `int()` + `> 0` | `INTERNAL_ERROR` (désynchronisation PHP/démon) |
| Identifiant de scène reçu du cloud | PHP **et** démon | scène ignorée, `echecs++`, `log warning` |
| Nom `None` / vide après `cleanComponanteName` | PHP, relecture de `getName()` **après** `setName()` | repli « Usage Roborock &lt;sceneId&gt; » |
| Toute chaîne d'origine cloud | démon `textes.texte()` **puis** PHP `texteInventaire()` | neutralisation + troncature, **avant** log, base et DOM |
| Refus du cloud sur l'exécution | démon, `args[0]` est un dict | `ROUTINE_INTROUVABLE`, code numérique **journalisé** |
| Refus du cloud sur la liste | démon, `args[0]` est un dict | `ROBOROCK_ERROR`, code numérique **journalisé** — à enrichir après recette |
| Panne réseau | démon, `code_pour_exception` via `__cause__` | `CLOUD_UNREACHABLE` |
| Structure de la réponse démon | PHP, `is_array` + `array_slice` à 64 | non conforme → listes vides, aucun plantage |
| Création/mise à jour d'une commande | PHP, `try/catch (Throwable)` **par routine** + troncature 256 | un échec n'interrompt ni les autres, ni la synchro |
| Budget | démon 15 s < canal 19 s < PHP 20 s < jQuery 30 s | `OPERATION_TIMEOUT` puis `DAEMON_TIMEOUT` |

### Codes d'erreur — trois nouveaux, tous de FAMILLE C

Vérifié sur `jeeroborockException.class.php:46-55` : `estErreurCanal()` porte les 7 codes des familles A et B
(`DAEMON_UNREACHABLE`, `DAEMON_TIMEOUT`, `DAEMON_INVALID_RESPONSE`, `UNAUTHORIZED`, `BAD_REQUEST`,
`UNKNOWN_OPERATION`, `INTERNAL_ERROR`). **Aucun des trois n'en fait partie** ⇒ `jeeroborockException.class.php`
n'est **pas** modifié et le piège de la liste dupliquée d'UC03 ne s'applique pas à cette UC.

| Code | Émis par | Message FR |
|---|---|---|
| `ROUTINE_OBSOLETE` | **PHP** (`erreurLocale`) | « Cet usage a été supprimé dans l'application Roborock : supprimez cette commande ou relancez une synchronisation des usages. » |
| `ROUTINE_INTROUVABLE` | **démon** | « Le cloud Roborock n'a pas pu exécuter cet usage : il est peut-être introuvable ou obsolète. Relancez une synchronisation des usages. » |
| `ROUTINE_SYNC_RECENTE` | **PHP** (`erreurLocale`) | « Une synchronisation des usages vient d'être effectuée : patientez une minute avant de relancer. » |

**Secrets** : `userData` **uniquement dans le tableau de paramètres**, jamais en argument scalaire (protège le
chemin `displayException()` de `cmd.ajax.php`, R-5 d'UC08) ; `contexte` jamais sérialisé ; réponse AJAX
reconstruite champ par champ ; aucun `displayException()` ; aucun `getTraceAsString()`. **Aucune nouvelle
surface** : `routines.py` ne touche ni au `HomeData`, ni aux `local_key`, ni au `contexte["gestionnaire"]`.

**Robustesse cron** : aucun hook cron ajouté (D-07-9 maintenu). `synchroniserRoutines()` est conçue pour être
appelée sous `try/catch` **par équipement**.

**Quota** : opération **quota-neutre** au sens `homedata`/`login` — ni `get_home_data_v3`, ni
`create_device_manager`, ni login. Le seul risque de rafale est sur `/user/scene/…`, non plafonné côté lib,
couvert par D-09-3.

## Dépendances

**Aucune.** `packages.json` inchangé : `get_scenes`/`execute_scene` sont dans `python-roborock` 7.8.0 déjà
épinglé, et `aiohttp` est une dépendance transitive déjà présente.

## Impact i18n (français uniquement dans ce cycle)

**12 littérales nouvelles**, toutes **littérales** (`sprintf` **autour** de `__()`, jamais `__($variable)`).

- `jeeroborockDaemon.class.php` — **3** : les trois messages de `tableMessages()` ci-dessus.
- `jeeroborock.class.php` — **2** : « Usage Roborock %s » (repli de nom) ; « Usage « %s » lancé. ».
- `jeeroborock.ajax.php` — **5** : les 4 phrases du compte rendu + le repli « Synchronisation des usages terminée. ».
- `desktop/php/jeeroborock.php` — **1** : « Synchroniser les usages ».
- `desktop/js/jeeroborock.js` — **2** (⚠️ apostrophes simples) : « Sélectionnez un robot avant de synchroniser
  ses usages. » ; « Synchronisation des usages en cours… ».

⚠️ **À signaler au `translator`** :
- « Équipement introuvable ou non géré par ce plugin. » (AJAX) et « Le démon ne répond pas. » (`.js`) **existent
  déjà dans ces mêmes fichiers** → **rien à ajouter**, ne pas les redéclarer.
- Les **noms** de commandes de routine viennent de l'application mobile de l'utilisateur et **ne sont pas
  traduisibles** — c'est voulu.
- Codes stables (`ROUTINE_*`) et clés de `logicalId` (`routine_<id>`) : **jetons stables**, jamais traduits.
- Logs `log::add` et logs du démon : français **non enveloppé**.

## Risques & pièges

- **R-1 (majeur, AC4)** — **`dontRemoveCmd()` rendait AC4 inapplicable.** Sans D-09-4, l'utilisateur ne peut
  **pas** « supprimer manuellement » une commande de routine obsolète : l'icône de suppression est inerte et la
  commande réapparaît après « Sauvegarder ». **Principal correctif rétroactif de cette UC.**
  ⚠️ La citation `eqLogic.ajax.php l. 598-601` qui fonde ce point vient du **core Jeedom, absent de ce dépôt** :
  elle repose sur une lecture externe (source `jeedom/core` master, 2026-09-18), cohérente avec le commentaire
  déjà en place sur `dontRemoveCmd()`, mais **non contre-vérifiable ici**. À confirmer en recette (R-5b).
- **R-2 (majeur)** — **`ROUTINE_INTROUVABLE` peut masquer une session expirée.** Sur `/user/scene/…`, un refus
  cloud arrive en `RoborockException(dict)` sans mapping `2010` (la lib ne mappe `RoborockInvalidCredentials` que
  sur `getHomeDetail`). Un jeton périmé pourrait donc s'afficher « usage introuvable » au lieu de
  « ré-authentification requise ». Mitigation : la synchronisation échouerait de la même manière et le « Tester
  la connexion » (UC05) tranche sans ambiguïté. **Le code numérique du cloud est journalisé en `info`** — c'est
  ce qui permettra d'enrichir le mapping après recette.
- **R-3 (majeur, recette)** — **le code cloud d'une scène supprimée n'est pas connu.** Le chemin nominal d'AC4
  est la détection **locale** (marqueur posé par la synchro), qui ne dépend d'aucun contrat cloud ;
  `ROUTINE_INTROUVABLE` n'est qu'un filet quand l'utilisateur exécute une routine supprimée **sans avoir
  resynchronisé**. À relever : refus (`success:false`) ou **succès silencieux** — dans ce dernier cas, le plugin
  afficherait « lancé » sans effet. **Non corrigeable** sans lister les scènes avant chaque exécution — écarté.
- **R-4 (majeur)** — **un succès d'exécution ne prouve pas que le robot a démarré.** `execute_scene` ne renvoie
  aucun état et le cloud relaie l'ordre de façon asynchrone. D'où le message neutre « lancé ». Combiné à D-09-5,
  **le dashboard ne reflétera le nettoyage qu'au prochain rafraîchissement manuel** — incohérence visible avec
  UC08, **arbitrée et assumée**, levée par UC10.
- **R-5** — **factorisation de `_texte()` : risque de non-régression sur deux fichiers livrés.** Réduit au minimum
  par l'alias d'import : **aucun site d'appel n'est modifié**, le diff est une pure suppression. Points sensibles :
  retirer `import re` (vérifié inutilisé ailleurs) et ne pas laisser `LONGUEUR_MAX_TEXTE` orphelin.
- **R-6** — **`HomeDataScene.name` peut valoir `None`** et `cleanComponanteName` peut vider un nom exotique. Sans
  le repli, `cmd::save()` lève avec `print_r($this, true)` : log illisible et réponse AJAX géante.
- **R-7** — **un champ requis absent dans une scène fait échouer toute la liste** (`TypeError` →
  `INTERNAL_ERROR`), pas seulement la scène fautive. Même famille que R-8 d'UC06, non corrigeable sans
  réimplémenter la désérialisation.
- **R-8** — **`execute_scene` n'exige pas le `duid`** (docstring du trait : « the API does not require the device
  ID to execute them »). Un `sceneId` d'un autre robot **du même compte** s'exécuterait. Non vérifié par le
  plugin (coûterait une requête par clic) ; risque nul en pratique, le `sceneId` ne venant que d'un `logicalId`
  posé par notre propre synchronisation.
- **R-9** — **la garde de personnalisation d'AC3 est un mécanisme NOUVEAU**, pas une réutilisation d'idiome :
  `appliquerActions()` (`:812-823`) et `appliquerCapacites()` (`:1024-1038`) ne réécrivent **jamais** `name` sur
  une commande existante. Elle est motivée par un besoin nouveau (propager un renommage depuis une source externe
  mutable). Contrepartie : une commande renommée **dans Jeedom** ne suivra plus les renommages de l'application.
  AC3 reste vérifiable en recette (le scénario renomme dans l'application, pas dans Jeedom).
- **R-10** — **le cooldown est écrit après un appel démon réussi** : deux onglets cliquant exactement en même
  temps passent tous deux la garde. Couvert par le verrou de bouton JS, pas structurellement. Acceptable : deux
  `GET /user/scene/…` ne consomment aucun quota documenté.
- **R-11** — **`cache::set` peut ne rien persister sans lever** : la garde dégrade alors vers « pas de garde ».
  Jamais bloquant.
- **R-12** — **le cœur renumérote les `order`** à chaque sauvegarde d'équipement : `ORDRE_BASE_ROUTINES = 30`
  n'est qu'une valeur initiale. Sans conséquence.
- **R-13** — **contrainte sur l'avenir** : la commande générique paramétrée `routine_executer` (hors périmètre)
  réutilisera **l'opération démon `executerRoutine` telle quelle** ; ne pas y ajouter de logique propre à la
  commande unitaire. UC10 (push) est le seul chemin prévu pour rafraîchir l'état après une routine.
- **R-14** — **méta-séquences** : `desktop/php/jeeroborock.php` et `desktop/js/jeeroborock.js` sont **rendus** —
  aucune double accolade ouvrante littérale, commentaires compris. `python .claude/scripts/verif-plugin.py`
  (colonne `meta=`) **avant chaque commit** ; ⚠️ il ne contrôle **pas** le `.js`.
- **R-15** — **indentation** : `desktop/php/jeeroborock.php` en **tabulations + CRLF** ; `resources/demond/*.py`
  en **LF** ; tout le reste en 2 espaces + CRLF.

## Recette (Jeedom réelle, Debian 12+)

| # | AC | Vérification | Attendu |
|---|---|---|---|
| R-1 | AC1 | au moins 1 usage créé dans l'app, clic « Synchroniser les usages » | 1 commande action par usage, portant son nom |
| R-2 | **AC2** | déclencher la commande | le robot exécute la routine ; retour « Usage « … » lancé. » |
| R-3 | **AC5** | resynchroniser sans rien changer | « 0 créé(s), N mis à jour » ; **même nombre** de commandes |
| R-4 | **AC3** | renommer l'usage dans l'app, resynchroniser | **même** commande, nouveau nom ; **aucune** création |
| R-5 | **AC4** | supprimer l'usage dans l'app, resynchroniser, puis exécuter | commande **toujours présente** ; message « Cet usage a été supprimé… » ; **aucune requête HTTPS** dans le log du démon (preuve du refus local) |
| R-5b | **AC4 / D-09-4** | sur cette commande obsolète : icône « supprimer » puis « Sauvegarder » | la commande **disparaît** ; les autres commandes du robot sont **intactes** |
| R-6 | **AC6** | robot sans aucun usage | message pédagogique, **`state = ok`** (pas une alerte rouge) |
| R-7 | **AC7** | couper le Wi-Fi du robot ; « Démarrer » (UC08) puis la routine | UC08 → « Le robot est hors ligne… » ; **la routine part quand même** ; log du démon **sans** construction de gestionnaire ni `homedata` |
| R-8 | quota / D-09-1 | 10 synchros + 10 exécutions, log du démon en `debug` | **aucune** ligne `homedata`, **aucune** construction de `DeviceManager`, aucun `RATE_LIMIT` |
| R-9 | D-09-3 | deux synchros en moins d'une minute | 2ᵉ refusée avec le message de patience, **aucun** paquet réseau |
| R-10 | R-2/R-3 | provoquer un refus cloud (usage supprimé sans resynchro, puis session expirée) | **relever le code numérique** dans le log du démon et enrichir le mapping |
| R-11 | **non-régression** | rejouer UC04→UC08, en particulier « Rafraîchir l'état » et les 5 actions | comportement identique malgré l'extraction de `textes.py` |
| R-12 | secrets | log **global** en `debug`, rejouer une exécution en erreur | ni jeton, ni blob base64 dans le DOM, `log/jeeroborock`, `log/jeeroborock_demon`, `log/php` |
| R-13 | scénario | exécuter une routine depuis un **scénario** Jeedom | exécution OK ; message d'erreur explicite si la routine est obsolète |
| R-14 | i18n | commande dont le nom contient une esperluette ou une apostrophe | nom mutilé par `cleanComponanteName` (attendu) ; nom vide → repli « Usage Roborock &lt;id&gt; » |

## Dette

*Reviews du 2026-09-18 : sécurité **0 finding**, qualité **1 `minor`** (commentaire obsolète de `robots.py`,
**corrigé dans le cycle**). Aucun finding au-dessus de la gate ⇒ un seul tour de review. Rien n'a été reporté
en dette par les reviews ; les deux points ci-dessous sont des dettes **de conception**, connues d'avance.*

- **Enrichissement du mapping d'erreur cloud** — `ROBOROCK_ERROR` sur la liste et `ROUTINE_INTROUVABLE` sur
  l'exécution sont des classements génériques. Les codes numériques sont journalisés ; après la recette R-10,
  affiner `_erreur_cloud()` pour distinguer une session expirée d'une scène introuvable.
- **Dict `contexte['session']` construit inline** (hérité d'UC06, 4 occurrences) : UC09 **n'en ajoute pas une
  5ᵉ** (D-09-8), mais la factorisation dans `session.py` reste à faire au prochain cycle qui modifie déjà
  `authentification.py`.
