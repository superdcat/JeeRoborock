# Spec technique — UC10 : Rafraîchissement temps réel et fraîcheur

> **Spec fonctionnelle** : `10-rafraichissement-et-push.md` · **Dépend de** : UC03 (canal), UC06
> (équipements), UC07 (commandes info + `lireEtat`), UC08 (routage `execute()` + `appliquerEtatPartiel()`)
> **Contrats externes vérifiés** le 2026-09-18 sur le wheel `python_roborock-7.8.0-py3-none-any.whl`
> (lecture verbatim) et sur la source du cœur Jeedom (`eqLogic.class.php`, `cmd.class.php`,
> `plugin.class.php`).

## Principe directeur

Le démon devient **superviseur** : il branche un écouteur de push sur le trait `status` de chaque robot
et fait tourner une **sonde périodique par robot** (30 s en nettoyage / 60 s au repos), puis pousse des
**lots** d'état vers Jeedom par le callback `jeedom_com` déjà en place.

Le PHP ne gagne **aucun nouveau chemin d'écriture** : le lot poussé a exactement la forme de la réponse
`lireEtat` d'UC07, donc `appliquerEtatPartiel()` (UC08) le consomme tel quel, sans modification ni de son
corps ni de sa visibilité. Le cron PHP, jusqu'ici du code mort commenté, devient chien de garde de
fraîcheur.

Deux propriétés structurelles, pas déclaratives :
- **Quota nul en régime établi.** La sonde passe par le canal RPC (local puis MQTT), qui n'a **aucun**
  limiteur : les seuls limiteurs de la librairie sont dans `web_api.py` (`_login_limiter`,
  `_home_data_limiter`). Le seul coût est **1 `homedata` par construction du `DeviceManager`**, soit une
  fois par démarrage de démon.
- **Isolation entre robots.** Une tâche asyncio par robot, un réarmement **réconciliateur** (une sonde
  vivante n'est jamais annulée), et un `try/catch` par robot côté PHP.

| AC | Réalisé par |
|---|---|
| **AC1** | Double source : push `StatusTrait.add_update_listener()` (dps 120/121/122/123/124/133/134) **et** sonde `status.refresh()` toutes les 30 s en nettoyage. `clean_area`/`clean_time`/`clean_percent` n'ayant **aucune** métadonnée `dps`, c'est la sonde qui porte la progression fluide. |
| **AC2** | `derniere_maj` **existe déjà** (UC07, `definitionsCommandes()` l.312, info/string, ordre 11), écrite par `appliquerConnexion()` sur chaque lot dont `etatLu` est vrai. **Aucune migration à écrire** : `appliquerConnexion()` crée les 3 commandes de connexion si elles manquent, et il est appelé sur chaque lot. |
| **AC3** | `deamon_start()` appelle déjà `restaurerSessionDemon()` après un démarrage réussi ; `restaurer_session` arme le superviseur. Ajout symétrique : `enregistrerSession()` appelle `restaurerSessionDemon()` après un login réussi. |
| **AC4** | Chemin rapide (≈ 12-30 s) : sur robot injoignable la sonde publie `etatLu=false` + `connecte=false` **sans** horodatage, donc `appliquerConnexion()` n'écrit pas `derniere_maj` et son `collectDate` cesse d'avancer. Chemin de secours (démon mort, callback cassé) : `cron()` force `connecte=0` au-delà de `DELAI_FRAICHEUR_S`. |
| **AC5** | Trois niveaux : (1) une tâche asyncio **par robot**, jamais de boucle séquentielle ; (2) réarmement **réconciliateur** — une sonde vivante n'est ni annulée ni recréée ; (3) `try/catch` **par robot** dans `traiterPoussee()` et **par équipement** dans `cron()`. |
| **AC6** | `rafraichirEtat()`, `executerAction()`, `lireEtat`, `envoyerCommande` **ne sont pas modifiés**. Seuls 3 helpers privés de `robots.py` sont renommés en public, plus un wrapper ajouté. Le `DeviceManager` reste unique et partagé. |
| **AC7** | Porté par le cœur : `eqLogic::checkAndUpdateCmd()` (l.680-708) n'appelle `$cmd->event()` que si `$oldValue !== $cmd->formatValue($_value) || $oldValue === ''`. **Aucun filtre à écrire.** |

## Architecture

### Fichiers

| Chemin | État | Contenu | Indentation |
|---|---|---|---|
| `resources/demond/supervision.py` | **créé** | Superviseur : armement idempotent, réconciliation, écouteurs de push, une tâche `_sonde` par robot, fabrication et publication des lots | 4 espaces, **LF** |
| `resources/demond/robots.py` | modifié | 3 renommages privé vers public + 1 wrapper public ; **aucun** changement de comportement | 4 espaces, **LF** |
| `resources/demond/authentification.py` | modifié | `restaurer_session` : arme après succès, désarme quand `userData` est vide. **3 lignes en fin de fonction**, chaîne de login non touchée | 4 espaces, **LF** |
| `resources/demond/jeeroborockd.py` | modifié | `jeedom_com(..., cycle=INTERVALLE_LOT_S)` ; `contexte['com']` ; `contexte['superviseur'] = None` ; `supervision.arreter(contexte, fermer_gestionnaire=False)` dans le `finally`, avant `robots.fermer_gestionnaire` | 4 espaces, **LF** |
| `core/class/jeeroborock.class.php` | modifié | 4 constantes ; `traiterPoussee()` ; **`cron()` : suppression du bloc de commentaire l.688-691 et écriture d'une vraie méthode** ; 2 helpers de cache de relance ; `enregistrerSession()` appelle `restaurerSessionDemon()` en fin de succès | **2 espaces, CRLF** |
| `core/php/jeeJeeroborock.php` | modifié | Dispatch restructuré en drapeau `$traite` | **2 espaces, CRLF** |
| `core/php/jeeroborock.inc.php` | **inchangé** | Aucune nouvelle classe PHP. Le point dur « autoload » est **sans objet** pour cette UC. |
| `plugin_info/packages.json` | **inchangé** | Aucune dépendance nouvelle (`aiomqtt`/`aiohttp` déjà transitifs) |
| `core/config/*.ini`, `plugin_info/configuration.txt` et `.php`, `info.json`, `desktop/`, `core/ajax/`, `core/i18n/` | **inchangés** | Cadences non paramétrables ; aucune chaîne UI nouvelle |

> ⚠️ **`appliquerEtatPartiel()` reste `private` et strictement inchangée.** `traiterPoussee()` est
> statique **dans la même classe** `jeeroborock`, et la visibilité `private` de PHP est bornée à la
> **classe déclarante**, pas à l'instance ni au contexte statique. `eqLogic::byLogicalId()` renvoie bien
> une instance de `jeeroborock` (`eqLogic.class.php` l.186-188 : `PDO::FETCH_CLASS, $_eqType_name`).
> L'élargir en `public` ouvrirait une surface d'API inutile.

### Répartition démon / PHP

- **Démon** : détection des changements, cadence, canal robot, fabrication des lots. Tout l'état vivant.
- **PHP** : application des lots sur les commandes (chemin UC07/UC08 réutilisé) et **chien de garde**
  (démon vivant ? donnée périmée ?). Aucune logique de cadence côté PHP.
- **Aucune nouvelle route HTTP, aucune nouvelle opération `canal.enregistrer()`** : le superviseur est
  une tâche de fond, pas une opération RPC.

## Contrats externes

### 1. Push robot vers démon (`python-roborock` 7.8.0, wheel inspecté)

| Élément | Contrat vérifié | Source |
|---|---|---|
| API d'abonnement retenue | `device.v1_properties.status.add_update_listener(callback)` retournant le désabonnement — **publique**, héritée de `TraitUpdateListener` | `roborock/devices/traits/common.py` l.47-70 ; `traits/v1/status.py` l.32 |
| Le callback est **synchrone**, appelé **dans la boucle asyncio** | transport `aiomqtt` (pas de thread paho) ; `_on_mqtt_message` puis `_dps_listeners` puis `PropertiesApi._on_dps_update` puis `status.update_from_dps` puis `_notify_update`. La librairie documente : « The callback ... should not block since it runs in the async loop » | `mqtt/roborock_session.py` l.18, l.286-291 ; `devices/rpc/v1_channel.py` l.531-543 ; `traits/v1/__init__.py` l.257-266 |
| Le callback **ne peut pas casser la librairie** | enveloppé par `safe_callback()` (log de l'exception) | `roborock/callbacks.py` l.13-31, l.99-102 |
| Filtre de changement **déjà côté librairie** | `DpsDataConverter.update_from_dps()` renvoie vrai seulement si un champ a réellement changé ; `_notify_update()` n'est appelé que dans ce cas | `traits/common.py` l.97-118 ; `traits/v1/status.py` l.136-143 |
| ⚠️ **`refresh()` ne notifie PAS** les listeners | `V1TraitMixin.refresh()` appelle `merge_trait_values()` et **jette** son booléen de retour | `traits/v1/common.py` l.76-100 |
| Champs réellement poussés | `state`(121), `battery`(122), `error_code`(120), `fan_power`(123), `water_box_mode`(124), `charge_status`(133), `dry_status`(134). **Pas** `clean_area`, `clean_time`, `clean_percent`, `in_cleaning` | `data/v1/v1_containers.py` l.82-128 |
| Le push arrive **uniquement par MQTT** | `_on_local_message` n'alimente pas `_dps_listeners` ; la librairie maintient volontairement l'abonnement MQTT même en local | `devices/rpc/v1_channel.py` l.376-379, l.545-549 |
| API **non** retenue | `V1Channel.add_dps_listener` est publique mais inatteignable depuis `RoborockDevice` (`_channel` privé) ; y accéder imposerait de toucher un attribut privé de `v1_properties`. **Refusé.** | `devices/device.py` l.56-121 ; `traits/v1/__init__.py` l.204 |
| Coût quota de la sonde | **Nul.** `status.refresh()` passe par `RpcChannel` : aucun limiteur, aucun HTTPS | `web_api.py` l.65-66, l.207, l.462, l.488, l.514 ; `devices/rpc/v1_channel.py` l.81-162 |
| Coût quota du superviseur | **1 `homedata` par construction du `DeviceManager`** (cache mémoire vide) | `devices/device_manager.py` l.84-98 |

> ⚠️ **Écart signalé** : `devices/device.py` l.2-5 déclare l'API `devices` « experimental and subject to
> breaking changes without notice ». La version est épinglée à 7.8.0, et un **repli explicite** est prévu
> (voir `_brancher_push`).

### 2. Démon vers Jeedom (callback `jeedom_com`)

Contrat existant, **inchangé** : POST sur l'URL de callback avec apikey, corps JSON, réponse 200 attendue.

Nouvelle clé de premier niveau : `robots`, dictionnaire duid vers lot. Le lot a **exactement** la forme
de la réponse `lireEtat` (UC07) : `duid`, `enLigne` (optionnel), `connecte`, `etatLu`, `motifEchec`,
`capacites`, `etat`.

**Vérifié** : `add_changes('robots::' + duid, lot)` produit bien, via le découpage sur le double
deux-points de `jeedom/jeedom.py` (l.67-85), la structure `$resultat['robots'][$duid] = lot` côté PHP.
La fusion de lots successifs par `merge_dict` (deep-merge des mappings, scalaires en dernier-écrit-gagne)
est saine, y compris sur des lots partiels : le garde `etatLu` côté PHP ignore de toute façon `etat` et
`capacites` quand la lecture a échoué.

### 3. Cœur Jeedom

| Contrat | Vérifié |
|---|---|
| `checkAndUpdateCmd()` n'historise pas une valeur inchangée | `eqLogic.class.php` l.680-708 : `event()` seulement si `$oldValue !== $cmd->formatValue($_value) \|\| $oldValue === ''` |
| `collectDate` est rafraîchi **même valeur inchangée** | `eqLogic.class.php` l.703-706 : sur la branche « pas d'`event()` », `$cmd->setCache('collectDate', date('Y-m-d H:i:s'))` + `setStatus('lastCommunication')`. `cmd::event()` l.2304-2305 l'écrit également. **Donc aucune régression sur un robot joignable mais immobile.** |
| ⚠️ `getCollectDate()` est un **piège** | `cmd.class.php` l.3896-3901 : délègue à `execCmd()` si `_collectDate` est vide ; `execCmd()` l.1622-1636 fait `else { $this->setCollectDate(date('Y-m-d H:i:s')); }`. Une commande **jamais écrite** rapporte donc « maintenant ». **Ne jamais l'utiliser ici** — voir R16. |
| Primitive retenue | `cmd::getCache('collectDate', '')` (l.4059-4062), publique, renvoie `''` quand l'entrée est absente |
| Cadence du cron | **Aucune** section `"cron"` dans `plugin_info/info.json` ⇒ cadence par défaut **60 s**, cohérente avec `DELAI_FRAICHEUR_S = 180` et `DELAI_MIN_RELANCE_SUPERVISION = 600` |
| Redémarrage du démon | `plugin::checkDeamon()` (`plugin.class.php` l.576-598) le fait déjà chaque minute. `cron()` ne redémarre **jamais** le démon. |

## Server vs Client

**Aucun client impliqué.** Cette UC n'ajoute ni page, ni endpoint AJAX, ni JavaScript. Tout se joue entre
le démon (tâches de fond asyncio), le callback PHP `core/php/jeeJeeroborock.php` et le cron PHP. Les
valeurs atteignent le navigateur par le mécanisme d'événement standard du cœur (`cmd::event()` puis
rafraîchissement du dashboard), sans code du plugin.

## Validation

**Validation serveur uniquement.** Le lot est une **donnée externe** même s'il vient de `127.0.0.1`.

Côté PHP, dans l'ordre :
1. `traiterPoussee()` refuse un non-tableau.
2. Troncature à `NB_MAX_ROBOTS_SYNCHRO` (64) par **`foreach` + compteur + `break`**, avec `warning` sur
   dépassement. ⚠️ **Pas d'`array_slice()`** : `json_decode(..., true)` convertit une clé de duid
   purement numérique en clé **entière**, et `array_slice()` sans `preserve_keys` la réindexerait,
   corrompant silencieusement le mapping duid vers lot. Une troncature silencieuse serait de surcroît
   indétectable.
3. `$duid = trim((string) $cle);` — cast explicite, même raison.
4. `duidValide($duid)` (existante) avant toute résolution.
5. `eqLogic::byLogicalId($duid, 'jeeroborock')` ; ignorer si absent (`log debug`) ou `getIsEnable() != 1`.
6. `appliquerEtatPartiel($lot)` sous `try/catch (Throwable)` **par robot**.

Puis les validations **déjà écrites** d'UC07, qu'il n'y a **rien à compléter** :
- `appliquerValeurs()` a une **liste blanche fermée de 9 clés** avec bornes numériques ;
- `texteInventaire()` (neutralisation, trim, troncature 128) sur `etatLibelle` et `erreurLibelle` ;
- `appliquerConnexion()` n'écrit que des booléens, et n'écrit `en_ligne` que sur un booléen explicite.

`motifEchec` est validé par une regex ancrée (majuscules, chiffres, underscore, 1 à 40) **avant** de
figurer dans un `log::add`, puis ignoré (`appliquerEtatPartiel` ne le lit pas).

Côté démon, `_publier()` valide le duid contre `MOTIF_DUID` — qui **exclut le deux-points** (voir R8) —
et abandonne avec un `warning` sinon.

**Aucun message utilisateur** n'est produit par ce chemin : pas de `message::add()`, donc pas de question
d'échappement HTML.

## Server Actions / API

### `resources/demond/supervision.py` (créé)

Constantes en tête :

```
INTERVALLE_LOT_S = 2            # fenetre de regroupement de jeedom_com
DELAI_AMORCAGE_S = 20           # avant la 1re construction du gestionnaire (garde-fou quota)
DELAIS_REPRISE_S = (60, 300, 900, 1800)
CADENCE_NETTOYAGE_S = 30
CADENCE_REPOS_S = 60
CADENCE_ECHEC_S = 120
SEUIL_ECHECS = 3
FENETRE_COALESCENCE_S = 1.0
DELAI_SONDAGE_S = 12
MOTIF_DUID = r"\A[A-Za-z0-9_.-]{4,128}\z"   # SANS deux-points (cf. R8)
CODES_ARRET = {"AUTH_EXPIRED", "NOT_AUTHENTICATED"}
```

État porté par le superviseur :

```
contexte['superviseur'] = {
  'empreinte':  <sha256 du userData BRUT recu du PHP>,
  'parametres': <copie du dict brut : userData / baseUrl / email>,
  'tache':      <Task de _superviser, ou None>,     # passe de reconciliation, ponctuelle
  'sondes':     { duid: {'tache': Task, 'evenement': asyncio.Event,
                         'debrancher': Callable|None} },
}
```

| Fonction | Rôle / entrées / sortie / exceptions |
|---|---|
| `demarrer(contexte, parametres) -> None` | **Synchrone, idempotente, ne lève jamais, retour immédiat.** Calcule `empreinte = sha256(parametres['userData'])`. Si un état existe avec une **empreinte différente** (autre compte, nouveau login) : `arreter(contexte, fermer_gestionnaire=True)` puis état neuf. Si l'**empreinte est identique** : met à jour `parametres` et **ne touche à aucune sonde**. Puis `if etat['tache'] is None or etat['tache'].done(): etat['tache'] = create_task(_superviser(contexte))` — une passe de réconciliation déjà en cours n'est jamais doublée. ⇒ appelable chaque minute par `cron()` sans effet de bord (AC5). ⚠️ `parametres` est le dict **brut du PHP**, jamais `contexte['session']` (R4). |
| `arreter(contexte, fermer_gestionnaire=True) -> None` | **Synchrone, idempotente, ne lève jamais.** `_retirer()` sur toutes les sondes, annulation de `etat['tache']`, `contexte['superviseur'] = None`. Si `fermer_gestionnaire`, planifie `robots.fermer_gestionnaire(contexte)` en tâche de fond — **jamais attendu** : `restaurerSession` est borné à 3 s côté PHP. Appelée avec `fermer_gestionnaire=False` à l'arrêt du démon (le `finally` ferme déjà le gestionnaire juste après). |
| `async _superviser(contexte)` | **Passe de réconciliation, pas une boucle permanente.** `if not etat['sondes']: await asyncio.sleep(DELAI_AMORCAGE_S)` — l'amorçage de 20 s ne s'applique qu'au tout premier armement, pas à une réparation ciblée. Puis boucle sur `DELAIS_REPRISE_S` : `robots.obtenir_gestionnaire(etat['parametres'], contexte)` puis `get_devices()` puis `_reconcilier(...)` puis **retour** (la tâche se termine, les sondes vivent leur vie). Arrêt sec **sans relance** si le code mappé appartient à `CODES_ARRET`. Ne lève jamais. Conséquence voulue : tant que `_superviser` dort dans son backoff, `done()` est faux et `demarrer()` n'enchaîne aucune rafale de `homedata`. |
| `_reconcilier(contexte, appareils) -> None` | **Synchrone, cœur de l'idempotence (AC5).** Pour chaque appareil (`v1_properties` non `None`, duid conforme à `MOTIF_DUID`) : si `sondes[duid]` existe **et** `not tache.done()` → **`continue`, on n'y touche pas** ; sinon `_retirer()` l'entrée morte puis crée `evenement`, `_brancher_push`, `create_task(_sonde(...))`. Enfin, tout duid présent dans `sondes` mais absent du compte → `_retirer()`. |
| `_retirer(sondes, duid) -> None` | `debrancher()` si non `None`, `tache.cancel()`, `del sondes[duid]`. Ne lève jamais. |
| `_journaliser_fin(tache) -> None` | `add_done_callback` posé sur `_superviser` et sur chaque `_sonde` : ignore `CancelledError`, journalise en `error` toute autre issue. Sans lui, une sonde morte sur exception disparaîtrait en silence (`Task exception was never retrieved`) — c'est exactement l'anomalie que `_reconcilier` détecte par `tache.done()`. |
| `async _sonde(contexte, appareil, evenement)` | Boucle propre à **un** robot. `try/except Exception` englobant **chaque itération** : une erreur n'arrête jamais la sonde ni les autres robots. Séquence : calcul de la cadence (`_en_nettoyage`), puis `asyncio.wait_for(evenement.wait(), reste_avant_sondage)`. Si réveillé par un push : `clear()`, attente `FENETRE_COALESCENCE_S`, `clear()`, publication **sans RPC** (le trait est déjà à jour). Si délai écoulé : `asyncio.wait_for(status.refresh(), DELAI_SONDAGE_S)` puis publication. Un sondage a lieu **au moins** toutes les `cadence` secondes même sous rafale de push. Au-delà de `SEUIL_ECHECS` échecs consécutifs : cadence `CADENCE_ECHEC_S`, remise à zéro au premier succès. |
| `_brancher_push(appareil, evenement) -> Callable \| None` | `getattr(status, "add_update_listener", None)` puis `callable()` (même garde défensive qu'UC05). Le callback enregistré fait **uniquement** `evenement.set()` : aucun await, aucune I/O. Retourne le désabonnement, ou `None` si l'API a disparu — repli **sondage seul**, `logging.error` émis une fois. |
| `_lot(appareil, etat_lu, motif, avec_capacites, avec_en_ligne) -> dict` | Construit le lot. Ajoute `enLigne` **seulement** si `avec_en_ligne` (premier lot, cf. R5). `etat` vaut `robots.valeurs_etat(status)` si `etat_lu`, sinon vide. `capacites` vaut `robots.capacites_etat(...)` au premier lot, sinon vide. |
| `_publier(contexte, duid, lot) -> None` | Valide le duid contre `MOTIF_DUID` (sinon `warning` et abandon), puis `contexte['com'].add_changes('robots::' + duid, lot)`. Ne lève jamais. |
| `_en_nettoyage(status) -> bool` | `libelles.est_en_nettoyage(status.state)` — **réutilisé, pas réécrit**. |

### `resources/demond/robots.py` (modifié) — renommages mécaniques

| Avant | Après | Raison |
|---|---|---|
| `_attendre_connexion` | `attendre_connexion` | utilisée par la sonde au premier lot |
| `_valeurs` | **`valeurs_etat`** | source unique du mapping `StatusV2` vers clés du canal |
| `_capacites` | **`capacites_etat`** | ⚠️ **surtout pas `capacites`** : `lire_etat` et `envoyer_commande` affectent une variable locale nommée `capacites` ; renommer la fonction ainsi la masquerait et produirait un `UnboundLocalError` au runtime, invisible à la relecture |
| *(nouveau)* | `async obtenir_gestionnaire(parametres, contexte)` | wrapper public de `_gestionnaire` : `robots.py` reste **le seul** module qui construit un `DeviceManager`, et `_VERROU_GESTIONNAIRE` couvre donc aussi le superviseur |

Aucun changement de comportement. Répercuter les renommages sur **tous** les appelants internes.

### `core/class/jeeroborock.class.php` (modifié)

```
const DELAI_FRAICHEUR_S               = 180;   // s, garde avant bascule deconnecte
const DELAI_MIN_RELANCE_SUPERVISION   = 600;   // s, anti-rafale du rearmement
const DUREE_CACHE_RELANCE_SUPERVISION = 1800;  // s, TTL de l horodatage
const CLE_CACHE_RELANCE_SUPERVISION   = 'jeeroborock::relanceSupervision';
```

| Méthode | Rôle |
|---|---|
| `public static function traiterPoussee($_robots)` | Point d'entrée du lot poussé, décrit en « Validation ». Ne lève jamais. |
| `appliquerEtatPartiel($_reponse)` | **Aucun changement, ni de corps ni de visibilité** — reste `private`. |
| `public static function cron()` | Chien de garde. Sort immédiatement si `!estCompteLie()`. Boucle `eqLogic::byType('jeeroborock', true)` sous `try/catch` **par équipement** : `$cmd = $eqLogic->getCmd('info', 'derniere_maj');` → si `!is_object($cmd)` ⇒ **« jamais lu »** : lève le drapeau de réarmement et `continue` **sans écrire `connecte`** ; sinon `$collecte = trim((string) $cmd->getCache('collectDate', ''));` → si vide, même traitement ; sinon `strtotime()` (si `false`, `continue` sans rien lever) et, si l'âge dépasse `DELAI_FRAICHEUR_S`, drapeau + `checkAndUpdateCmd('connecte', 0)` + `log::add(..., 'info', ...)`. **Après** la boucle et **seulement** si le drapeau est levé : si `deamon_info()['state'] != 'ok'` → `log debug` et retour (le redémarrage est l'affaire de `plugin::checkDeamon()`) ; sinon garde `relanceSupervisionRecente()` puis `marquerRelanceSupervision()` + `restaurerSessionDemon()`. ⇒ **coût nul en nominal** : ni `deamon_info()` (donc ni sa sonde `fsockopen`) ni appel démon ne sont atteints tant que rien n'est périmé. |
| `private static function relanceSupervisionRecente()` / `marquerRelanceSupervision()` | Calque exact de `synchroRoutinesRecente()` / `marquerSynchroRoutines()` d'UC09 (cache, ne lèvent jamais). |
| `enregistrerSession($_donnees)` | Ajout de `self::restaurerSessionDemon();` après `oublierInventaireCompte()`, dans la branche succès. `restaurerSessionDemon()` relit la config elle-même : le blob `userData` **n'entre pas** en argument d'une nouvelle frame (invariant trace d'exception). Le verrou de session est déjà relâché par l'AJAX (l.31). |

> ⚠️⚠️ **Consigne d'implémentation impérative pour `cron()`.** Les lignes **688-691** de
> `core/class/jeeroborock.class.php` forment un bloc de commentaire **complet et autonome** (délimiteur
> ouvrant / ligne de doc / `public static function cron() {}` / délimiteur fermant), l'un des 7 blocs du
> squelette. **`cron()` n'existe donc pas aujourd'hui.** Remplacer **le bloc entier d'un seul tenant**
> par la nouvelle méthode. **Ne jamais éditer l'intérieur d'un bloc commenté** : un délimiteur fermant
> orphelin rend la classe `jeeroborock` introuvable au runtime, sans erreur ni à la relecture ni à
> `php -l`. Les 6 autres blocs (`cron5` jusqu'à `cronDaily`) restent tels quels. Lancer
> `python .claude/scripts/verif-plugin.py` (colonne `meta=`) juste après l'édition.

### `core/php/jeeJeeroborock.php` (modifié)

Remplace les lignes 39-43. Le `log::add(debug, 'cles recues ...')` existant devient le **cas par défaut**
et ne doit plus se déclencher sur un lot `robots` (flux nominal, jusqu'à une fois toutes les 2 s) :

```
  $traite = false;
  if (isset($resultat['versionLibrairie'])) {
    jeeroborock::traiterVersionLibrairie($resultat['versionLibrairie']);
    $traite = true;
  }
  if (isset($resultat['robots']) && is_array($resultat['robots'])) {
    jeeroborock::traiterPoussee($resultat['robots']);
    $traite = true;
  }
  if (!$traite) {
    log::add('jeeroborock', 'debug', 'Callback demon : cles recues ' . jeeroborock::nettoyerPourLog(implode(', ', array_keys($resultat))));
  }
```

Le `try/catch (Throwable)` global et la règle « `getMessage()` seulement, jamais `displayException()` »
restent en place.

### `resources/demond/jeeroborockd.py` (modifié)

- `jeedom_com(..., cycle=INTERVALLE_LOT_S)` au lieu de `cycle=0` (le regroupement des lots devient actif).
- `contexte['com'] = com` et `contexte['superviseur'] = None` à l'initialisation.
- Dans le `finally` : `supervision.arreter(contexte, fermer_gestionnaire=False)` **avant**
  `robots.fermer_gestionnaire(contexte)`.
- Enregistrement explicite : le module est importé et branché depuis `jeeroborockd.py`, **jamais par
  effet de bord d'import**.

### `resources/demond/authentification.py` (modifié)

`restaurer_session` uniquement, **3 lignes en fin de fonction** : arme (`supervision.demarrer(contexte,
parametres)`) après un succès, désarme (`supervision.arreter(contexte)`) quand `userData` est vide. La
chaîne de login (`demanderCode` / `validerCode`) **n'est pas ouverte**.

## Erreurs

- **Aucun code d'erreur stable nouveau.** Le double-fichier `tableMessages()` / `estErreurCanal()` est
  donc **sans objet** pour cette UC.
- Le superviseur réutilise `erreurs.code_pour_exception()` pour **classer et journaliser**, jamais pour
  construire un message utilisateur.
- `_superviser` et `_sonde` ne laissent **rien** remonter (chaque itération sous `except Exception`),
  sinon la tâche mourrait en silence. Filet complémentaire : `_journaliser_fin`.
- Code dans `CODES_ARRET` : arrêt du superviseur avec `logging.warning` — la ré-authentification est
  **UC11**, le plugin ne doit pas faire semblant d'essayer.

### Budget de temps

| Chemin | Borne |
|---|---|
| Callback de push | `Event.set()` seul — **aucun** blocage de la boucle asyncio |
| Sonde | `wait_for(refresh(), DELAI_SONDAGE_S = 12)` |
| Construction du gestionnaire | `DELAI_CONSTRUCTION_S = 15`, déjà en place |
| POST du callback | hors boucle (thread `jeedom_com`), timeout 15 s, 3 tentatives |
| `cron()` | **coût nul en nominal** ; au plus ~3,3 s une fois par 10 min quand une donnée est périmée |

### Secrets

Le lot ne transporte que des codes, libellés d'état et booléens. `contexte` — qui porte le
`DeviceManager`, donc les clés locales des robots — n'est **jamais** sérialisé ; `contexte['com']` est
ajouté au dict mais `handler_sante` construit sa réponse champ par champ.

## Dépendances

**Aucune.** `aiomqtt` et `aiohttp` sont déjà des dépendances transitives de `python-roborock` 7.8.0.
`plugin_info/packages.json` reste inchangé.

## Impact i18n

**Aucune chaîne nouvelle.** Les libellés visibles (« Dernière mise à jour », « Connecté », « En ligne »)
sont déjà posés par `definitionsCommandes()` en UC07. Les textes ajoutés sont des messages de `log::add`
et de `logging.*`, qui ne sont pas enveloppés par convention. Aucun fichier `core/i18n/*.json` à toucher.

## Risques & pièges

1. **R1 — API de push « expérimentale ».** `devices/device.py` l.2-5 avertit que l'interface peut casser
   sans préavis. Parade : `getattr` puis `callable`, **repli automatique sur la sonde périodique seule**
   (AC1 reste tenu, la latence passe à 30 s), `logging.error` unique. **Hypothèse centrale de l'UC, à
   confirmer en recette.**
2. **R2 — `refresh()` ne notifie pas les listeners.** La sonde publie donc **explicitement** après son
   `refresh()`. Si une version future rendait `refresh()` notifiant, on publierait deux fois : sans
   conséquence (coalescence 2 s et filtre du cœur).
3. **R3 — Quota `homedata` sur boucle de redémarrage.** UC10 introduit une construction du
   `DeviceManager` à chaque démarrage du démon, alors qu'avant elle n'arrivait que sur action
   utilisateur. `plugin::checkDeamon()` relançant un démon mort chaque minute, un démon qui crashe en
   boucle **après** 20 s pourrait consommer plus de 5 `homedata` par heure. Parades : `DELAI_AMORCAGE_S
   = 20` (un crash au démarrage ne coûte rien), `DELAIS_REPRISE_S` plafonné à 1800 s, arrêt sec sur
   `AUTH_EXPIRED`, et **`_superviser` ne recrée une tâche que si la précédente est terminée** — un
   réarmement par `cron()` pendant un backoff ne déclenche aucun `homedata` supplémentaire. Le cas
   « échec durable » relève d'UC11.
4. **R4 — Piège d'empreinte : doubler le quota sans le voir.** `robots._gestionnaire()` invalide le
   gestionnaire par une empreinte du `userData` **brut**. `restaurer_session` range dans
   `contexte['session']['userData']` une valeur **ré-encodée** (`encoder_user_data(decoder_user_data(x))`),
   qui peut différer octet à octet du blob envoyé par le PHP (les clés inconnues sont ignorées à la
   désérialisation). Si le superviseur passait `contexte['session']`, chaque alternance superviseur /
   action utilisateur reconstruirait le gestionnaire, soit **2 `homedata` et 2 sessions MQTT**. ⇒
   `demarrer()` reçoit et conserve le dict **brut** reçu du PHP. **Invariant, pas détail.**
5. **R5 — `en_ligne` est un instantané figé.** `appareil.device_info.online` vient du `homedata` et
   n'est jamais rafraîchi par la librairie. Le lot ne contient donc `enLigne` **qu'au premier lot** après
   construction du gestionnaire ; le republier toutes les 30 s affirmerait un fait périmé. L'indicateur
   vivant est `connecte`. À revoir en UC11.
6. **R6 — Fenêtre de course de `jeedom_com(cycle > 0)`.** Le thread d'envoi permute le dict des
   changements pendant que la boucle asyncio y écrit : une modification peut être perdue. Fenêtre
   minuscule, comportement standard de la librairie Jeedom, **réparée par le sondage suivant** (au plus
   60 s). Second effet : si Jeedom est injoignable, l'envoi retente 3 fois (jusqu'à ~45 s) dans ce
   thread ; les lots s'accumulent et fusionnent — acceptable.
7. **R7 — Bruit d'événements.** `derniere_maj` change par construction, soit 1 à 2 événements de commande
   par minute et par robot. Les scénarios déclenchés sur « toute commande de l'équipement » s'exécuteront
   à cette cadence. Parade : la commande reste **non historisée** (`historise => 0`). AC2 parle
   d'« historique de la commande » : la valeur et sa date de collecte sont visibles au dashboard ;
   l'historisation reste un choix utilisateur, à assumer en recette.
8. **R8 — Duid et séparateur de clé.** `jeedom_com.add_changes` découpe la clé sur le double deux-points,
   et `duidValide()` côté PHP autorise le deux-points. Un duid en contenant deux produirait une
   imbrication parasite. Parade : `MOTIF_DUID` côté démon **exclut le deux-points** et écarte le robot
   avec un `warning`. Les duids Roborock observés sont hexadécimaux — à confirmer en recette.
9. **R9 — Panne de push seule, non détectable.** Si l'abonnement MQTT tombe alors que le canal TCP local
   reste actif, l'état connecté reste vrai et le sondage continue : `derniere_maj` avance, la fraîcheur ne
   signale rien, mais le temps réel est perdu (retour à 30/60 s). **Dégradation silencieuse assumée** :
   le sondage est le plancher garanti.
10. **R10 — Robots supervisés ≠ robots Jeedom.** Le superviseur énumère tous les V1 du compte ; les duids
    sans équipement sont ignorés par `traiterPoussee()` (`log debug`). Coût : quelques RPC MQTT sans
    quota. Choix assumé pour éviter toute nouvelle plomberie PHP vers démon et garantir AC3 sans que
    Jeedom ait à redire quoi que ce soit au démon.
11. **R11 — Migration des équipements créés en UC06 et jamais rafraîchis.** Ils n'ont que leurs commandes
    d'action (posées par `postSave()`). Le premier lot après armement porte `capacites`, ce qui déclenche
    `appliquerCapacites()` et crée les commandes info manquantes. Un robot **jamais joignable** restera
    sans commandes info — situation déjà celle du MVP.
12. **R12 — Pas de période de grâce après commande, et c'est justifié.** Le plugin n'écrit jamais d'état
    optimiste : toute valeur publiée provient du trait `status` partagé, alimenté uniquement par le robot.
    La relecture post-action d'UC08 et la sonde lisent le **même objet** : pas de désaccord possible,
    seulement un ordre d'arrivée, sans enjeu. *(Point « À confirmer » de la spec fonctionnelle, tranché.)*
13. **R13 — Dette d'UC06 volontairement non soldée.** `CLAUDE.md` prescrit de factoriser le dict
    `contexte['session']` dans `session.py` « au prochain cycle qui modifie déjà `authentification.py` ».
    UC10 n'ajoute que 3 lignes **en fin** de `restaurer_session` et n'ouvre pas la chaîne de login ;
    refactorer les 4 occurrences ferait porter à cette UC un risque de régression sur un chemin
    d'authentification livré, pour zéro apport fonctionnel. **Écart conscient**, à ne pas traiter comme un
    oubli en review.
14. **Codes d'état/erreur inconnus** écrasés en 0 par la librairie (`RoborockErrorCode` n'a pas de membre
    `unknown`) : risque **préexistant** d'UC07, désormais visible en continu plutôt qu'au clic. Rien à
    changer ici.
15. **Points de recette matériel** (Qrevo Curv `a135`, **jamais en review**) : réception effective des dps
    et fréquence ; absence de throttling Roborock sur une lecture d'état toutes les 30 s en MQTT ;
    utilisation ou non du canal TCP local ; pertinence pratique du délai de garde de 180 s ; volume réel
    de POST callback. Si ce volume est excessif, le levier documenté est un diff côté démon sur le dernier
    état publié — **non implémenté** volontairement.
16. **R16 — `getCollectDate()` fabrique une date « maintenant » pour une commande jamais écrite.**
    `cmd::getCollectDate()` (l.3896-3901) délègue à `execCmd()` quand `_collectDate` est vide, et
    `execCmd()` (l.1622-1636) fait `else { $this->setCollectDate(date('Y-m-d H:i:s')); }`. Une commande
    `derniere_maj` créée par un lot en échec — `appliquerConnexion()` crée les 3 commandes mais n'écrit
    `derniere_maj` que si `etatLu` — rapporterait donc une fraîcheur parfaite en permanence, et **AC4
    tomberait pour tout robot jamais lu avec succès**. ⇒ le chien de garde lit
    **`$cmd->getCache('collectDate', '')`** (l.4059-4062), qui renvoie `''` quand l'entrée est absente.
    **Ne jamais « simplifier » vers `getCollectDate()`.**
17. **R17 — Réarmement et AC5.** Un réarmement destructif (annuler puis tout recréer) violerait AC5 : un
    robot A hors ligne — cas **nominal**, pas une panne — interromprait le temps réel de B à chaque tick
    de cron. L'idempotence de `demarrer()` / `_reconcilier()` est donc une **exigence d'acceptation**, pas
    une optimisation. Point de recette : couper un robot sur deux et vérifier que `derniere_maj` du second
    continue d'avancer sans trou.
18. **R18 — Sonde morte silencieuse.** Une tâche `_sonde` qui meurt sur une exception hors de son `try`
    par itération disparaîtrait sans trace. Parades cumulées : `add_done_callback(_journaliser_fin)` (log
    `error`) et détection par `tache.done()` à la réconciliation suivante, déclenchée par le chien de
    garde au plus tard 10 min après la péremption.
19. **R19 — Purge du cache de commande.** `collectDate` vit dans `cmdCacheAttr<id>` (`cmd::setCache`,
    l.4071-4074). Si ce cache est purgé, la fraîcheur repart à « jamais lu » : le chien de garde
    **s'abstient** d'écrire `connecte=0` — on n'affirme pas une déconnexion jamais observée, règle déjà
    posée en UC07 (l.988-992 pour `en_ligne`) — et se contente de demander un réarmement. Comportement
    conservateur assumé : **pas de faux « déconnecté »**.

## Décisions arbitrées

| Décision | Choix | Alternative écartée |
|---|---|---|
| **D-10-1 — Cadences** | 30 s nettoyage / 60 s repos (décision D6), garde de fraîcheur **180 s** = 3 cycles au repos + marge de détection du cron (60 s). **Non paramétrables.** | Clés de config plugin : la spec ne le demande pas, et cela ouvrirait la normalisation `preConfig_` et le défaut `.ini` pour un réglage que l'utilisateur n'a aucun moyen d'arbitrer. |
| **D-10-2 — Granularité du push** | **Lot** de 2 s (`jeedom_com(cycle=2)`). | Un POST par changement : chaque POST déclenche un bootstrap complet du cœur Jeedom, et la fusion par duid est déjà écrite dans la librairie. |
| **D-10-3 — Périmètre** | Strictement les commandes info d'UC07. | Écouter aussi les consommables détaillés (dps 125-127) : c'est **UC12**. |
| **D-10-4 — Source de fraîcheur** | `cmd::getCache('collectDate', '')`. | `getCollectDate()` (R16, fabrique « maintenant ») et la relecture/reparse de la valeur affichée (dépend d'un format qui est un contrat du plugin, pas du cœur). |
| **D-10-5 — Réarmement** | **Réconciliateur idempotent**, déclenché dès qu'**un** robot est périmé. | « Ne réarmer que si tous les robots sont périmés » : ne relancerait **jamais** une sonde isolément morte — précisément la panne que le chien de garde doit réparer (R18). |
| **D-10-6 — « Jamais lu » ≠ « déconnecté »** | Le chien de garde ne force pas `connecte=0` sur une commande absente ; il ne lève que le drapeau de réarmement. | Traiter l'absence comme une déconnexion : produirait un faux « déconnecté » après une purge de cache (R19). |

## Dette

- **R13** — factorisation du dict `contexte['session']` dans `session.py` (héritée d'UC06), volontairement
  non traitée ici. À solder au prochain cycle qui ouvre déjà la chaîne d'authentification.
- **Corps de requête non borné avant `json_decode`** (`core/php/jeeJeeroborock.php` l.34, review sécurité
  UC10, sévérité `low`). Le point d'entrée est protégé par l'apikey, et la troncature à
  `NB_MAX_ROBOTS_SYNCHRO` est correcte — mais elle intervient **après** que tout le corps a été lu et
  décodé en mémoire. UC10 fait passer ce chemin d'un appel ponctuel (démarrage du démon) à un flux
  régulier (jusqu'à une requête toutes les 2 s), ce qui en ferait une cible plus naturelle à saturer **si
  l'apikey venait à fuiter**. Non corrigé dans ce cycle : borner `php://input` (ou `Content-Length`) est
  une décision de conception qui dépasse le périmètre d'UC10 et touche un chemin livré au MVP. Ordre de
  grandeur pour un futur plafond : le lot nominal ne dépasse pas quelques Ko pour 64 robots.
