# Spec technique — UC07 : Commandes info (état, batterie, erreurs)

> **Spec fonctionnelle** : `07-commandes-info-etat.md` · **Dépend de** : UC03 (pont), UC04 (session),
> UC06 (équipements) · **Prépare** : UC08 (actions), UC09 (routines), UC10 (push)

## Périmètre

Faire remonter dans Jeedom l'état courant d'un robot déjà créé en UC06 :

- une opération démon **`lireEtat`** dans un nouveau module `robots.py` — c'est la **première UC qui ouvre
  réellement le canal vers le robot** (MQTT/local), jamais utilisé jusqu'ici par le plugin ;
- une méthode d'instance **`jeeroborock::rafraichirEtat()`**, point d'entrée **unique** du
  rafraîchissement, qui synchronise les commandes puis écrit leurs valeurs ;
- un **bouton d'administration** « Rafraîchir l'état » sur la page équipement, pour rendre AC1/AC6
  recettables sans empiéter sur UC08.

### Couverture des critères d'acceptation

| AC | Réalisé par | Statut |
|---|---|---|
| **AC1** — commandes visibles et cohérentes après un premier rafraîchissement | `lireEtat` (démon) → `rafraichirEtat()` (PHP) → `appliquerCapacites()` crée les cmd, `appliquerValeurs()` écrit les valeurs. Déclencheur livré par UC07 : bouton admin `bt_jeeroborockRafraichirEtat` (§ Frontière UC07/UC08) | couvert |
| **AC2** — texte français + code numérique séparé | `etat` (info/string) ← `libelles.libelle_etat(status.state)` **composé dans le démon** ; `etat_code` (info/numeric) ← `int(status.state)`. Aucune table de libellés d'état en PHP | couvert |
| **AC3** — erreur neutre quand aucune erreur | `erreur` = **chaîne vide**, `erreur_code` = **0** dès que `status.error_code` vaut `RoborockErrorCode.none` (0). ⚠️ `status.error_code_name` vaut littéralement `"none"` : **jamais** renvoyé tel quel | couvert |
| **AC4** — aucune commande vide/figée pour une capacité absente | `capacites` calculées dans le démon selon **deux familles de critères** (§ Détection de capacités). Capacité fausse ⇒ **aucune** cmd créée ; capacité vraie mais valeur `None` ⇒ cmd créée, **aucune valeur écrite** (jamais de 0/"" par défaut) | couvert |
| **AC5** — pas de doublon entre deux synchronisations | Recherche par `$eqLogic->getCmd('info', <logicalId>)` ; branche création **uniquement** si absente ; champs cosmétiques posés **à la création seulement**. Plus `jeeroborockCmd::dontRemoveCmd()` → `true` (R-6) | couvert |
| **AC6** — indicateurs de connexion qui basculent | `connecte` ← `device.is_connected`, **réécrit à chaque lecture, même quand l'état n'a pas pu être lu** ; `en_ligne` ← `device_info.online` ; `derniere_maj` écrite **seulement** si la lecture a abouti | couvert, avec la réserve d'`en_ligne` (R-4) |

Les deux points « À confirmer » de la spec fonctionnelle sont **tranchés** (§ Unités et arrondis) ; la
disponibilité de `clean_percent` sur le Qrevo Curv reste une hypothèse de recette (R-8).

### Arbitrages utilisateur de ce cycle

- **`en_ligne` est créée dès le MVP**, bien qu'elle reste gelée jusqu'à UC10 (R-4). Motif retenu : la spec
  fonctionnelle la demande explicitement, `connecte` porte déjà l'information temps réel, et une commande
  absente puis créée plus tard est moins gênante qu'une commande retirée.
- **Le bouton d'administration « Rafraîchir l'état » sera conservé après UC08.** Il reste le seul
  déclencheur utilisable quand aucune commande n'existe encore, et sert au diagnostic en support. UC08
  n'a donc **pas** à le retirer.

## Frontière UC07 / UC08

**UC07 livre** : l'opération démon `lireEtat` ; `jeeroborock::rafraichirEtat()` ; la
création/synchronisation des 12 commandes info ; un déclencheur d'administration (`case 'rafraichirEtat'`
dans l'AJAX admin existant + bouton dans la barre de gestion de l'équipement).

**UC08 livrera** : la commande **action** `rafraichir` (`jeeroborockCmd::execute()` →
`$this->getEqLogic()->rafraichirEtat()`), plus `demarrer`/`pause`/`arreter`/`retour_base`/`localiser`.
**UC08 ne réécrit rien** : il branche une cmd sur la méthode d'UC07.

Motif : un bouton de page d'administration n'est **pas** une commande Jeedom — il ne préempte ni le
`logicalId` `rafraichir`, ni le catalogue d'actions, ni les scénarios. Sans lui, AC1 et AC6 seraient
invérifiables à la livraison d'UC07 (même motif que D-f d'UC03 pour « Vérifier le canal »).

*Alternative écartée* : enchaîner un rafraîchissement dans `synchroniserEquipements()` (UC06). Refusée —
la synchro boucle sur N robots, chacun coûtant jusqu'à 30 s de canal ⇒ dépassement certain du budget de
20 s d'UC06 et régression sur une UC livrée.

## Architecture

| Fichier | État | Ce qui y entre | Indentation / EOL |
|---|---|---|---|
| `resources/demond/robots.py` | **créé** | `lire_etat()` + gestionnaire mémorisé + `obtenir_appareil()` + `fermer_gestionnaire()` + `enregistrer_operations()` + `_texte()` privé | **4 espaces, LF** |
| `resources/demond/libelles.py` | **créé** | 2 tables de données (`ETATS`, `ERREURS`) + `ETATS_NETTOYAGE` + 3 résolveurs. **Aucune opération enregistrée** | **4 espaces, LF** |
| `resources/demond/jeeroborockd.py` | modifié (4 lignes) | `import robots` ; `robots.enregistrer_operations()` ; clé `"gestionnaire": None` dans `contexte` ; `await robots.fermer_gestionnaire(contexte)` dans le `finally` d'arrêt | 4 espaces, LF |
| `resources/demond/session.py`, `authentification.py`, `equipements.py`, `canal.py`, `erreurs.py` | **non modifiés** | Non-régression UC03→UC06. **Aucun** symbole privé emprunté | — |
| `core/class/jeeroborock.class.php` | modifié | `rafraichirEtat()` + 5 privées + table de définitions des commandes + `jeeroborockCmd::dontRemoveCmd()` → `true` | **2 espaces, CRLF** |
| `core/class/jeeroborockDaemon.class.php` | modifié | **2 ajouts** : `const TIMEOUT_ETAT = 35;` et la méthode publique `erreurLocale()`. `lever()` **reste privée**, `tableMessages()` **non modifiée** | 2 espaces, CRLF |
| `core/class/jeeroborockException.class.php` | **non modifié** | Aucun code de famille A/B ajouté ⇒ le piège de la liste dupliquée ne s'applique pas à cette UC | — |
| `core/ajax/jeeroborock.ajax.php` | modifié | 1 `case 'rafraichirEtat'`. `isConnect('admin')` + `session_write_close()` **déjà en place** | 2 espaces, CRLF |
| `desktop/php/jeeroborock.php` | modifié | Bouton « Rafraîchir l'état » ; **suppression** du bouton « Ajouter une commande » | ⚠️ **TABULATIONS + CRLF** |
| `desktop/js/jeeroborock.js` | modifié | 1 gestionnaire `#bt_jeeroborockRafraichirEtat` (AJAX + `showAlert`) | **2 espaces**, CRLF |
| `core/php/jeeroborock.inc.php` | **non touché** | **Autoload sans objet** : aucune classe PHP nouvelle | — |
| `plugin_info/configuration.txt` / `.php` | **non touchés** | Rien à ajouter à la page de config plugin ⇒ **pas de `cp`**, pas de risque de désynchronisation | — |
| `plugin_info/packages.json`, `info.json`, `core/config/*.ini`, `core/template/**`, `core/php/jeeJeeroborock.php`, `desktop/modal/*` | **non touchés** | Aucune dépendance, aucune clé de config, aucun widget custom (MVP = widgets par défaut), aucun push | — |
| `core/i18n/*.json` | **non touchés** | Traduction par le sous-agent `translator` en fin de cycle | — |

### Décisions d'architecture

**D-07-1 — Un `DeviceManager` unique, mémorisé dans `contexte`, avec un `InMemoryCache`.**
`create_device_manager()` fait un `homedata` (quota 5/h, 40/jour) **et** ouvre la session MQTT : le
reconstruire à chaque lecture brûlerait le quota en quelques clics. Il est donc construit **paresseusement
à la première lecture** et conservé. Le cache passé à la lib est **`InMemoryCache`** et **pas** `FileCache` :
le `CacheData` contient le `HomeData` complet, donc les **`local_key`** — les écrire sur disque
contredirait l'invariant « les secrets ne quittent pas le démon » et imposerait une politique de purge.
Coût assumé : **un `homedata` par démarrage de démon**. *Solde R-10 d'UC06.* Une persistance disque est un
sujet d'UC10/UC11.

**D-07-2 — Invalidation du gestionnaire par empreinte de session, sans toucher à `authentification.py`.**
`contexte["gestionnaire"] = {"objet": <DeviceManager>, "empreinte": sha256(userData)}`. À chaque
`lireEtat`, si l'empreinte diffère de celle du `userData` reçu, l'ancien gestionnaire est **fermé**
(`await close()`) puis reconstruit. Le PHP reste la source de vérité de la session (D-05-8) et **aucun
fichier livré d'UC04 n'est modifié** — même prudence que D-06-2. *Alternative écartée* : un hook
d'invalidation appelé depuis `valider_code`/`restaurer_session` — ferait porter un risque de régression
sur le chemin d'authentification à un cycle qui ne le concerne pas.

**D-07-3 — Les libellés d'état et d'erreur sont composés dans le démon, avec repli sur l'identifiant de
la librairie.** `libelles.py` porte deux tables `display_name → libellé français` (~43 états, ~55 erreurs).
Le résolveur applique : `ETATS.get(cle)` → sinon `cle.replace("_", " ")` (identifiant anglais stable et
lisible) → et journalise en `info` le libellé manquant. AC2 est tenu, **aucune** table de libellés d'état
n'existe en PHP, et un code ajouté par une future version de la lib reste **affiché** au lieu de produire
un vide ou un code brut. Les tables sont indexées sur **`display_name`** (pas `.name`), pour que les
doublons de la lib (états 23/25, erreurs 36/45) partagent une entrée. Module **de données** séparé et non
`robots.py` : ~100 lignes de table au milieu de la logique la rendraient illisible, et UC08/UC12
réutiliseront ces tables telles quelles.

**D-07-4 — `en_nettoyage` est dérivé de `state`, pas de `in_cleaning`.** `RoborockInCleaning` vaut 1/2/3
tant qu'une **session** de nettoyage est inachevée — donc aussi en pause et pendant le retour à la base.
Le dashboard afficherait « en nettoyage » pour un robot à l'arrêt. On dérive donc de l'ensemble
`ETATS_NETTOYAGE`, défini à côté de la table de libellés, sur les **mêmes clés stables**.

**D-07-5 — La lecture ne fait aucun `homedata` et n'exige aucun cache PHP.** Après construction,
`lireEtat` n'émet **qu'une** RPC `get_status` sur le canal du robot. Un rafraîchissement est donc
**quota-neutre** : aucune protection PHP, aucun cooldown, **aucune retentative** (invariant du cadrage).

**D-07-6 — `jeeroborockDaemon::erreurLocale($_code)`, nouvelle méthode publique restreinte ; `lever()`
reste privée.** `rafraichirEtat()` doit lever `NOT_AUTHENTICATED` / `DEVICE_UNKNOWN` / `DEVICE_OFFLINE`
avec **exactement** le message français de `tableMessages()`, sans aller-retour avec le démon. Rendre
`lever()` publique exposerait ses deux effets de bord (écriture au centre de messages sur `UNAUTHORIZED`,
`sprintf` sur `UNKNOWN_OPERATION`) et permettrait de fabriquer localement un code de **famille A/B**,
alors que ces familles ne peuvent, par construction, provenir que du transport ou du protocole — c'est
l'invariant qui rend AC5 d'UC03 structurel. Le filtrage **délègue à `estErreurCanal()`** pour ne pas créer
une **troisième** copie de la liste des codes de canal (cf. Dette d'UC03).

**D-07-7 — `jeeroborockCmd::dontRemoveCmd()` renvoie `true`.** `core/ajax/eqLogic.ajax.php` l. 598-602
**supprime** toute commande absente du tableau posté par la page, sauf si `dontRemoveCmd()`. Sans ce
garde-fou, un simple « Sauvegarder » depuis un onglet ouvert **avant** un rafraîchissement détruit
silencieusement des commandes référencées par des scénarios, avec leur historique. Les commandes de ce
plugin sont **entièrement gérées par le plugin**. Contrepartie assumée : l'icône « supprimer » d'une
commande devient sans effet. Corollaire cohérent : le bouton **« Ajouter une commande » est retiré** de la
page (même motif que D-06-7 — un `logicalId` saisi à la main pourrait entrer en collision).

**D-07-8 — Aucune capacité stockée en configuration d'équipement.**
`.memory/analyse/jeeroborock-modele-equipement.md` § 1 évoquait de ranger « le résultat de la découverte
de capacités » dans la configuration. **Écarté** : la liste des commandes existantes **est** déjà cet
état ; le dupliquer crée deux sources de vérité qui divergeront.

**D-07-9 — Aucun hook cron.** Le rafraîchissement automatique est UC10. `cron()`/`cron5()` restent
commentés.

## Server vs Client

**Serveur (PHP)** : toute la logique de synchronisation et d'écriture des commandes. Le client ne fait
qu'émettre la requête et afficher le retour.

**Client (JS)** : un gestionnaire de clic, un verrou d'anti-double-soumission, `timeout: 50000`, et un
`showAlert`. **Aucune** logique de décision.

**Endpoint admin assumé** : l'action ouvre le canal du robot et écrit des commandes. **Aucun endpoint
non-admin n'est créé par UC07** — le dashboard consommera les commandes via le mécanisme standard du
core, pas via cet AJAX.

⚠️ **`desktop/js/jeeroborock.js` est un fichier RENDU** (§ Risques R-7) : il passe par `getResource.php`,
qui lui applique `translate::exec(..., $_backslash = true)`. Conséquences sur l'écriture du code, cf. R-7.

## Contrats externes

Toutes les lectures passent par **`python-roborock` 7.8.0 dans le démon**. Aucun appel réseau PHP.
Sources lues **verbatim au tag `v7.8.0`**.

### 1. `create_device_manager(user_params, *, cache=None, prefer_cache=True, …) -> DeviceManager`
`roborock/devices/device_manager.py` l. 188-196.
- `cache is None` → `NoCache()` (l. 216-217).
- `DeviceManager.discover_devices(prefer_cache=True)` (l. 82-119) : si le cache n'a pas de `home_data`,
  appelle `UserWebApiClient.get_home_data()` → `get_home_data_v3()` (`web_api.py` l. 885-892) ⇒
  **consomme le quota `homedata` (3/min, 5/h, 40/jour)**. Puis crée un `RoborockDevice` par appareil et
  `await asyncio.gather(*[d.start_connect()])`.
- Ouvre une **session MQTT paresseuse** (`create_lazy_mqtt_session`, l. 224-227) — `aiomqtt`, **100 %
  asyncio**, aucun appel bloquant (vérifié : `roborock/mqtt/roborock_session.py` n'importe ni `threading`
  ni `run_in_executor`) ⇒ **aucun `asyncio.to_thread` requis** (R1 d'UC03 respecté).
- `RoborockInvalidCredentials` est **re-levée** après le hook (`web_api.py` l. 888-892) ⇒ `AUTH_EXPIRED`.

### 2. `RoborockDevice` — `roborock/devices/device.py`
`duid` (l. 87), `name` (l. 91), `device_info -> HomeDataDevice` (l. 95), `product` (l. 101),
**`is_connected`** (l. 107-109 → `self._channel.is_connected`), `is_local_connected` (l. 111).
- **`start_connect()` (l. 137-192) ne lève JAMAIS** : il attend au plus `START_ATTEMPT_TIMEOUT = 15 s`
  (l. 38) la **première** tentative, puis reboucle en tâche de fond avec backoff 10 s → 30 min (l. 33-34).
  ⚠️ Conséquence : juste après la construction du gestionnaire, `is_connected` peut être faux et
  `device_features` entièrement à `False`.
- `connect()` (l. 190-200) → `v1_properties.start()` → `discover_features()`.

### 3. `PropertiesApi` (V1) — `roborock/devices/traits/v1/__init__.py`
Traits **toujours** présents (l. 154-168) : `status`, `command`, `device_features`, `routines`,
`consumables`… `start()` (l. 245-251) → `discover_features()` (l. 268-308) → `device_features.refresh()`
(RPC `APP_GET_INIT_STATUS`, **mis en cache par appareil**) puis résolution du type de station.

### 4. `StatusTrait` — `roborock/devices/traits/v1/status.py` l. 32
`class StatusTrait(StatusV2, common.V1TraitMixin, TraitUpdateListener)` ⇒ **le trait EST le dataclass
d'état**. `command = RoborockCommand.GET_STATUS`. `refresh()` hérité de `common.V1TraitMixin`.
⚠️ `RpcChannel.send_command` (`devices/rpc/v1_channel.py` l. 81-111) essaie **chaque stratégie en
séquence** (local puis MQTT) avec `_TIMEOUT = 10.0 s` **par stratégie** (l. 49) ⇒ jusqu'à **20 s** pour un
seul `get_status`. Un dépassement produit une `RoborockException` **nue** (l. 157) ⇒ `ROBOROCK_ERROR` sans
borne explicite. **C'est ce qui impose le `wait_for` du § Budget.**

### 5. `StatusV2` — `roborock/data/v1/v1_containers.py` l. 76-238
```
state: RoborockStateCode|None      (l. 82,  metadata dps=STATE 121)
battery: int|None                  (l. 83,  metadata dps=BATTERY 122)
clean_time: int|None               (l. 84,  SECONDES, AUCUNE metadata)
clean_area: int|None               (l. 85,  AUCUNE metadata)
error_code: RoborockErrorCode|None (l. 86,  metadata dps=ERROR_CODE 120)
in_cleaning: RoborockInCleaning|None (l. 88)
clean_percent: int|None            (l. 130, metadata feature="is_support_clean_estimate")
@property square_meter_clean_area -> round(self.clean_area / 1000000, 1)   (l. 141-143)
@property error_code_name -> self.error_code.display_name                  (l. 145-147)
@property state_name      -> self.state.display_name                       (l. 149-151)
```
⚠️ **Écart avec l'analyse interne** : `.memory/analyse/jeeroborock-modele-equipement.md` § 2.1 indique
« `clean_area` (cm² → m²) ». Le diviseur réel est **1 000 000** ⇒ `clean_area` est en **mm²**, pas en cm².
La source (le code de la lib) fait foi ; l'analyse est **à corriger** (R-12).

### 6. `RoborockEnum` — `roborock/data/code_mappings.py` l. 9-40
- `display_name` = `self._display_name_ or self.name`, et `name` est **minusculisé** ⇒ pour
  `RoborockStateCode`, `display_name` rend un **identifiant anglais snake_case stable** (`"cleaning"`,
  `"charging_complete"`), **pas** un libellé présentable. D'où la table de libellés côté démon.
- `_missing_` : si la classe a un membre `unknown`, un code non répertorié y retombe **silencieusement**.
  - `RoborockStateCode` **a** `unknown = 0` (`v1_code_mappings.py` l. 403) ⇒ un état inconnu devient
    **code 0**, le code brut est perdu (la lib logge `Missing RoborockStateCode code: X`). Cf. R-1.
  - ⚠️ `RoborockErrorCode` **n'a pas** de membre `unknown` (l. 190-245) ⇒ `_missing_` retombe sur le
    **premier membre**, c'est-à-dire **`none = 0`**. **Un code d'erreur inconnu sera donc rapporté comme
    « aucune erreur »** (R-2).
- `washing_the_mop_2 = (25, "washing_the_mop")` et `mopping_roller_2 = (45, "mopping_roller_1")` ⇒
  **indexer les tables de libellés sur `display_name`**, jamais sur `.name`.

### 7. `DeviceFeaturesTrait.is_field_supported(cls, field_name)`
`roborock/devices/traits/v1/device_features.py` l. 72-99. Trois mécanismes selon la métadonnée du champ :
`feature` → booléen sur `DeviceFeatures` ; `dock_feature` → booléen station ; `dps` →
`int(dps) in self._product.supported_schema_ids`.
⚠️⚠️ **Sans métadonnée : `return True`** (l. 98-99, commentaire `# No metadata, field is assumed always
supported`). **C'est le piège central d'AC4** — cf. § Détection de capacités.
⚠️ `HomeDataProduct.supported_schema_ids` (`data/containers.py` l. 283-292) renvoie **`set()` quand
`schema is None`** ⇒ sur un produit sans schéma, `state`/`battery`/`error_code` seraient déclarés **non
supportés** et le plugin ne créerait **aucune** commande. D'où la branche de garde du § suivant.

### 8. Core Jeedom — `core/class/cmd.class.php`
Toutes les méthodes utilisées par `creerCommande()` existent : `setEqLogic_id()` l. 3016, `setEqType()`
l. 3171, `setLogicalId()` l. 3161, `setName()` l. 2981, `setType()` l. 2995, `setSubType()` l. 3007,
`setUnite()` l. 3028, `setGeneric_type()` l. 2921, `setOrder()` l. 3148, `setIsVisible()` l. 3132,
`setIsHistorized()` l. 3022, `setConfiguration()` l. 3057.
- `setEqType()` est **facultatif** : `save()` l. 1091-1093 le dérive de l'eqLogic s'il est vide. On le pose
  quand même explicitement pour éviter au `save()` un `getEqLogic()` (requête supplémentaire).
- ⚠️ **`save()` lève sur quatre invariants, et trois de ces exceptions embarquent `print_r($this, true)`**
  (l. 1076-1087) : `name` vide, `type` vide, `subType` vide, `eqLogic_id` vide — plus `minValue > maxValue`
  (l. 1088-1090). **Même piège que R-5 d'UC06.** Cf. R-16.
- ⚠️ **`setTemplate()` est inutile** : `save()` pose `core::default` en dashboard et mobile si rien n'est
  défini (l. 1098-1103) ⇒ confirme « widgets par défaut au MVP ».
- `eqLogic::getCmd('info', <logicalId>)` : `eqLogic.class.php` l. 1787-1807 — le cache interne `_cmds`
  n'est alimenté **que** sur un objet trouvé (l. 1803-1805), donc une commande créée dans la foulée sera
  bien relue ensuite.
- `eqLogic::checkAndUpdateCmd()` : l. 680 — ne déclenche un `event()` **que si la valeur a changé**, et
  **retourne `false` sans rien écrire si l'eqLogic est désactivé** (l. 681-683).

### 9. Core Jeedom — chaîne de rendu d'un `.js` de plugin
`core/php/utils.inc.php` l. 95-103 (branche `js` de `include_file()`) → un `.js` hors `3rdparty` et hors
`.min.js` est servi via `core/php/getResource.php` → l. 49-54 : `translate::exec(file_get_contents($file),
init('file'), true)` → `core/class/translate.class.php` l. 98 puis l. 134-161.
⇒ **`desktop/js/jeeroborock.js` est un fichier rendu et traduit.** Cf. R-7.

## Détection de capacités (AC4)

Calculée **dans le démon**, et **uniquement quand le `get_status` a abouti** (sinon `capacites = {}` et le
PHP ne touche à aucune commande d'état).

⚠️⚠️ **Deux familles de champs, deux critères. Ne JAMAIS les unifier en une seule expression** :
`is_field_supported()` renvoie `True` **par défaut** quand le champ n'a aucune métadonnée
(`device_features.py` l. 98-99), donc tout `… or is_field_supported(…)` vaut `True` en permanence pour la
famille 2 — et les commandes correspondantes seraient créées puis laissées vides, exactement ce qu'AC4
interdit.

**Famille 1 — champs porteurs d'une métadonnée** (`state`, `battery`, `error_code`, `clean_percent`) :
```
supporte = features.is_field_supported(StatusV2, StatusField.X) or (valeur is not None)
```
La branche `or (valeur is not None)` est un **garde-fou contre un faux négatif**, pas une commodité : pour
les trois champs annotés `dps`, `is_field_supported` teste `int(dps) in product.supported_schema_ids`, qui
vaut `set()` dès que `HomeDataProduct.schema` est `None` — sans ce garde, un produit sans schéma ne
produirait **aucune** commande de base. Pour `clean_percent` (annoté `feature`), la branche est
inoffensive : une valeur réellement reçue prouve la capacité mieux que le drapeau.

**Famille 2 — champs sans aucune métadonnée** (`clean_area`, `clean_time`) :
```
supporte = (valeur is not None)      # is_field_supported() N'EST PAS APPELE : il renverrait True
```

| clé de `capacites` | champ `StatusV2` | métadonnée | famille | critère exact |
|---|---|---|---|---|
| `etat` | `state` (l. 82) | `dps=STATE` (121) | 1 | `is_field_supported(StatusV2, StatusField.STATE) or status.state is not None` |
| `batterie` | `battery` (l. 83) | `dps=BATTERY` (122) | 1 | `is_field_supported(StatusV2, StatusField.BATTERY) or status.battery is not None` |
| `erreur` | `error_code` (l. 86) | `dps=ERROR_CODE` (120) | 1 | `is_field_supported(StatusV2, StatusField.ERROR_CODE) or status.error_code is not None` |
| `avancement` | `clean_percent` (l. 130) | `feature="is_support_clean_estimate"` | 1 | `is_field_supported(StatusV2, StatusField.CLEAN_PERCENT) or status.clean_percent is not None` |
| `surfaceNettoyee` | `clean_area` (l. 85) | **aucune** | **2** | `status.clean_area is not None` |
| `dureeNettoyage` | `clean_time` (l. 84) | **aucune** | **2** | `status.clean_time is not None` |
| `enNettoyage` | dérivé de `state` | — | — | `capacites["etat"]` |

**`enLigne` / `connecte` / `derniereMaj`** ne sont **pas** des capacités robot : les 3 commandes sont
créées **inconditionnellement**, y compris quand la lecture d'état échoue — sans quoi un robot jamais
joignable n'aurait aucune commande et AC6 serait invérifiable.

**Champ présent mais `None`** — ne peut se produire **que** dans la famille 1 (en famille 2 le critère est
la valeur elle-même) : la capacité reste vraie si la lib la déclare ⇒ la commande **est créée**, la clé
correspondante est **absente** du dict `etat`, le PHP **n'écrit rien**. La commande reste vide plutôt que
figée à une valeur par défaut — c'est la lettre d'AC4.

**Capacité disparue à une resync ultérieure** : la commande existante est **conservée** (jamais supprimée)
et garde sa dernière valeur. Même motif que D-06-6 : supprimer détruirait l'historique et casserait les
scénarios. La suppression reste une action utilisateur.

## Unités et arrondis

| Commande | Valeur envoyée par le démon | Unité Jeedom | Justification |
|---|---|---|---|
| `surface_nettoyee` | `status.square_meter_clean_area` → `round(clean_area/1000000, 1)` | **`m²`**, 1 décimale | On utilise la **propriété de la librairie** (`v1_containers.py` l. 141-143) plutôt que de refaire la conversion : c'est la seule source qui documente le diviseur réel, et elle fixe déjà l'arrondi. Aucune règle d'arrondi maison à maintenir |
| `duree_nettoyage` | `int(clean_time // 60)` | **`min`**, entier | `clean_time` est en **secondes** (aucune propriété de conversion dans la lib). La minute est l'unité affichée par l'application mobile et la seule lisible dans un widget. Contrepartie assumée : les 59 premières secondes d'un cycle affichent `0` |
| `batterie` / `avancement` | entier | **`%`** | `minValue=0`, `maxValue=100` posés à la création |

Conversions faites **dans le démon**, jamais en PHP : le PHP ne doit pas avoir à savoir que `clean_area`
est en mm². Les clés sont donc en français (`surfaceNettoyeeM2`, `dureeNettoyageMin`), conformément à
D-06-10.

## Erreur neutre (AC3)

| | Aucune erreur | Erreur en cours | Valeur jamais reçue (`error_code is None`) |
|---|---|---|---|
| `erreur` (info/**string**) | **`''`** (chaîne vide) | libellé FR | clé **absente** du payload ⇒ rien n'est écrit |
| `erreur_code` (info/**numeric**) | **`0`** | code entier | clé **absente** ⇒ rien n'est écrit |

- `''` plutôt que « Aucune erreur » : une valeur textuelle serait un libellé figé de plus, et
  `#[…][…][erreur]# != ''` est le test naturel en scénario.
- `0` plutôt que vide pour le code : une commande numérique vide casse les comparaisons de scénario ; `0`
  est la valeur canonique de `RoborockErrorCode.none`.
- ⚠️ **Interdit** : renvoyer `status.error_code_name` tel quel — il vaut la chaîne `"none"` quand tout va
  bien (AC3 le proscrit explicitement).

## Indicateurs de connexion (AC6)

| Commande | type/subType | Source | Quand elle bascule |
|---|---|---|---|
| `en_ligne` | info / **binary** | `device.device_info.online` (`HomeDataDevice.online`, `containers.py` l. 311) — **vue cloud** | À la (re)construction du gestionnaire démon. ⚠️ **Gelée entre-temps** (R-4). `null` ⇒ **aucune écriture** (on n'affirme pas « hors ligne ») |
| `connecte` | info / **binary** | `device.is_connected` — canal du démon vers le robot | **À chaque `rafraichirEtat()`**, y compris quand la lecture d'état échoue. **C'est cette commande qui porte AC6** |
| `derniere_maj` | info / **string** | `date('Y-m-d H:i:s')` posé côté PHP | **Uniquement** quand `etatLu` est vrai ⇒ un horodatage qui ne bouge plus est le signal visible de données périmées |

La lettre d'AC6 (« l'indicateur *en ligne* **ou** *connecté* bascule ») est satisfaite par `connecte` seul.

## Budget de temps

Le pire cas enchaîne : `homedata` (2 requêtes HTTPS, **aucun timeout par requête dans la lib**) +
connexion MQTT + `start_connect` (≤ 15 s) + `get_status` (≤ 2 × 10 s). Trois bornes explicites côté démon :

| Borne | Valeur | Où |
|---|---|---|
| Construction du gestionnaire | `asyncio.wait_for(..., DELAI_CONSTRUCTION_S = 15)` | `robots.py` ; dépassement → fermeture du gestionnaire partiel **puis** `ErreurDemon('OPERATION_TIMEOUT')` |
| Attente de connexion après construction | `DELAI_ATTENTE_CONNEXION_S = 3` (boucle `is_connected` par pas de 0,25 s) | évite un « robot hors ligne » mensonger à la toute première lecture |
| Lecture d'état | `asyncio.wait_for(status.refresh(), DELAI_LECTURE_S = 12)` | dépassement → **`etatLu=False` + `motifEchec`**, jamais `OPERATION_TIMEOUT` : les indicateurs de connexion doivent **toujours** être renvoyés (AC6) |

Somme démon ≤ **30 s** < `wait_for` du canal **34 s** (= 35 000 − `MARGE_MS`) < PHP **`TIMEOUT_ETAT = 35 s`**
< jQuery **50 000 ms**.
⚠️ **`timeout: 50000` côté JS est obligatoire** : les gestionnaires existants sont à `30000`, ce qui
couperait **avant** le serveur et afficherait « Le démon ne répond pas » à tort. 35 s dépasse de 5 s la
recommandation R12 d'UC03 (« ≤ 30 s ») ; c'est assumé et chiffré, et reste sous `TIMEOUT_MAX = 60` et sous
les `fastcgi_read_timeout`/`max_execution_time` usuels (60 s).

## Server Actions / API

### Opération démon `lireEtat`

**Enregistrement** : `canal.enregistrer("lireEtat", lire_etat)` depuis `robots.enregistrer_operations()`,
appelé **explicitement** dans `jeeroborockd.principal_async`. Aucune nouvelle route HTTP.

**Entrée** : `{"userData": <str base64, requis>, "baseUrl": <str, peut être vide>, "email": <str>,
"duid": <str, requis>}` — même quadruplet de session que `etatCompte`/`decouvrirEquipements` (D-05-8 : le
PHP pousse la session, le démon ne la présume jamais).

**Sortie** (`data`) :
```
{ "duid": str,
  "enLigne": bool|null,          # device_info.online
  "connecte": bool,              # device.is_connected
  "etatLu": bool,
  "motifEchec": str,             # "" si etatLu, sinon code stable
  "capacites": { "etat":bool, "batterie":bool, "enNettoyage":bool, "erreur":bool,
                 "surfaceNettoyee":bool, "dureeNettoyage":bool, "avancement":bool },   # {} si !etatLu
  "etat": { "etatCode":int, "etatLibelle":str, "batterie":int, "enNettoyage":bool,
            "erreurCode":int, "erreurLibelle":str,
            "surfaceNettoyeeM2":float, "dureeNettoyageMin":int, "avancement":int } }   # cle ABSENTE si valeur None
```
⚠️ **Tout champ est converti explicitement en type Python natif** (`int()`, `float()`, `bool()`, `str()`) :
aucun `Enum` ni objet de la lib ne traverse le canal.
⚠️ `home`, `device_info` complet, `manager.diagnostic_data()` et `device.diagnostic_data()` ne sont **ni
journalisés ni sérialisés** (`local_key`).

**Codes d'erreur stables — AUCUN NOUVEAU.** Utilisés tels quels : `INTERNAL_ERROR` (import KO),
`NOT_AUTHENTICATED` (userData vide), `AUTH_EXPIRED` (blob illisible / `2010` cloud), **`DEVICE_UNKNOWN`**
(duid absent du gestionnaire — code défini en UC03 avec la mention « état démon, **dès UC07** »),
`RATE_LIMIT`/`RATE_LIMIT_REMOTE`, `CLOUD_UNREACHABLE`, `OPERATION_TIMEOUT`, plus les familles A/B du canal.
⇒ **`jeeroborockDaemon::tableMessages()` et `jeeroborockException::estErreurCanal()` ne sont ni l'un ni
l'autre modifiés : le piège de la liste dupliquée héritée d'UC03 ne s'applique pas à cette UC.**

`motifEchec` est un code de **famille C déjà présent dans la table** : `DEVICE_OFFLINE` (défaut),
`ROBOROCK_TIMEOUT`, `CONNECTION_FAILED`. Le PHP le **valide contre une liste blanche fermée** et retombe
sur `DEVICE_OFFLINE` en cas d'écart.

## Signatures

### `resources/demond/libelles.py` *(créé)*
```python
ETATS: dict[str, str]              # display_name (RoborockStateCode) -> libelle francais
ERREURS: dict[str, str]            # display_name (RoborockErrorCode) -> libelle francais
ETATS_NETTOYAGE: frozenset[str]    # display_name consideres comme "en nettoyage"

def libelle_etat(etat) -> str
#   "" si etat None ; sinon ETATS[display_name] ; sinon display_name avec "_" -> " "
#   + logging.info une seule fois par cle inconnue (c'est ce qui rend la recette actionnable)

def libelle_erreur(erreur) -> str
#   "" si erreur None OU int(erreur) == 0   <- AC3, ne JAMAIS renvoyer "none"
#   sinon ERREURS[display_name] ; sinon display_name avec "_" -> " "

def est_en_nettoyage(etat) -> bool
#   False si etat None ; sinon display_name in ETATS_NETTOYAGE
```
Aucun import de `roborock.*` (règle D-h d'UC03) — les fonctions ne lisent que
`getattr(valeur, "display_name", ...)`.

### `resources/demond/robots.py` *(créé)*
```python
DELAI_CONSTRUCTION_S = 15
DELAI_ATTENTE_CONNEXION_S = 3
DELAI_LECTURE_S = 12
PAS_ATTENTE_S = 0.25

async def lire_etat(parametres, contexte) -> dict
#   parametres : userData (requis), baseUrl, email, duid (requis)
#   leve : ErreurDemon('INTERNAL_ERROR')     -> session.IMPORT_OK faux
#          ErreurDemon('NOT_AUTHENTICATED')  -> userData vide (defense en profondeur)
#          ErreurDemon('AUTH_EXPIRED')       -> blob illisible (session.decoder_user_data)
#          ErreurDemon('DEVICE_UNKNOWN')     -> duid absent/vide ou inconnu du gestionnaire
#          ErreurDemon('OPERATION_TIMEOUT')  -> construction du gestionnaire hors budget
#   les exceptions python-roborock de la CONSTRUCTION remontent telles quelles a handler_rpc
#   (point de mapping unique) : RoborockRateLimit -> RATE_LIMIT, InvalidCredentials -> AUTH_EXPIRED

async def obtenir_appareil(parametres, contexte)   # -> RoborockDevice ; POINT D'EXTENSION UC08/UC09
#   decode la session, obtient/construit le gestionnaire, resout le duid.
#   UC08 (actions) et UC09 (routines) appellent CETTE fonction, jamais create_device_manager.

async def _gestionnaire(parametres, contexte)      # -> DeviceManager ; memorise + empreinte (D-07-2)
async def _construire(user_data, base_url, email)  # -> DeviceManager ; wait_for(DELAI_CONSTRUCTION_S)
async def fermer_gestionnaire(contexte) -> None    # idempotent, ne leve jamais (arret du demon)
def _texte(valeur, longueur)                       # neutralisation + troncature, PRIVE A CE MODULE
def _capacites(status, features) -> dict           # 7 booleens
#   DEUX FAMILLES, VOLONTAIREMENT NON FACTORISEES (cf. spec § Detection de capacites) :
#     famille 1 (metadonnee dps/feature) : is_field_supported(...) OR valeur is not None
#     famille 2 (AUCUNE metadonnee)      : valeur is not None, SANS appeler is_field_supported
#   NE PAS unifier : device_features.py l.98-99 renvoie True par defaut sans metadonnee,
#   donc "X or is_field_supported(...)" vaudrait True en permanence pour clean_area/clean_time.
def _valeurs(status) -> dict                       # cles ABSENTES quand la valeur est None
def enregistrer_operations() -> None               # canal.enregistrer("lireEtat", lire_etat)
```

**Déroulé de `lire_etat`, ordre imposé** :
1. `session.IMPORT_OK` faux → `INTERNAL_ERROR`.
2. `userData` vide → `NOT_AUTHENTICATED` ; `duid` vide → `DEVICE_UNKNOWN`.
3. `user_data = session.decoder_user_data(...)` (→ `AUTH_EXPIRED`).
4. `gestionnaire = await _gestionnaire(...)` — construit ou réutilisé selon l'empreinte.
5. `device = await gestionnaire.get_device(duid)` ; `None` → `DEVICE_UNKNOWN`.
6. `enLigne = device.device_info.online` ; attente bornée (`DELAI_ATTENTE_CONNEXION_S`) que
   `device.is_connected` devienne vrai.
7. Si toujours non connecté → retour **succès** `etatLu=False, motifEchec="DEVICE_OFFLINE"`, **sans aucune
   RPC**.
8. Sinon `await asyncio.wait_for(device.v1_properties.status.refresh(), DELAI_LECTURE_S)` ;
   `asyncio.TimeoutError` **ou** exception dont `code_pour_exception()` rend
   `ROBOROCK_ERROR`/`ROBOROCK_TIMEOUT`/`CONNECTION_FAILED`/`RETRY_EXHAUSTED`/`DEVICE_BUSY` →
   `etatLu=False` + ce code en `motifEchec` (`logging.info`). **Toute autre exception remonte**
   (`AUTH_EXPIRED`, `RATE_LIMIT`, `PARSING_ERROR`… ne doivent pas être avalées).
9. `status = device.v1_properties.status` ; `features = device.v1_properties.device_features` ;
   composition de `capacites` puis de `etat` ; `connecte = device.is_connected` **relu après** la RPC.
10. `contexte["session"]` **n'est pas réécrit** ici : la session n'est pas réamorcée par cette opération.
    Aucune 5ᵉ occurrence du dict inline n'est créée — **la dette d'UC06 n'est pas aggravée**.

⚠️ `home`/`HomeData`/`local_key` ne sont **jamais** journalisés. Les logs sont composés à partir de nos
propres champs, `duid` **tronqué à 16 caractères**, chaque valeur passée par **`_texte()`, fonction privée
définie dans `robots.py`** (neutralisation des caractères de contrôle `\x00-\x1F`/`\x7F` **avant**
troncature, pour empêcher la forge d'une fausse ligne de log). ⚠️ **Ne rien importer d'`equipements.py`** :
`_texte` y est un symbole privé d'un autre module de domaine, et `session.py` est le seul module de helpers
partagés — il porte la session, pas le texte. Duplication consciente, cf. R-15.

### `resources/demond/jeeroborockd.py` *(modifié — 4 lignes)*
`import robots` ; `"gestionnaire": None` dans `contexte` (commentaire : « UC07 : porte le DeviceManager et
sa session MQTT — **contient des secrets, ne jamais sérialiser** ») ; `robots.enregistrer_operations()`
après `equipements.enregistrer_operations()` ; `await robots.fermer_gestionnaire(contexte)` dans le
`finally` d'arrêt, **avant** `site.stop()`.

### `core/class/jeeroborock.class.php` *(modifié)*
```php
const LONGUEUR_MAX_LIBELLE_ETAT = 128;

public  function rafraichirEtat()
//   -> array('etatLu'=>bool, 'cmdCreees'=>int, 'cmdTotal'=>int)
//   throws jeeroborockException : NOT_AUTHENTICATED, DEVICE_UNKNOWN, DEVICE_OFFLINE,
//          + tout code du canal. A APPELER SOUS try/catch PAR EQUIPEMENT dans une boucle.
private static function definitionsCommandes()          // -> array logicalId => definition
private function appliquerConnexion($_reponse)          // -> void ; TOUJOURS executee
private function appliquerCapacites($_capacites)        // -> int (nb de commandes creees)
private function appliquerValeurs($_etat)               // -> void ; liste blanche fermee
private function creerCommande($_logicalId)             // -> jeeroborockCmd|null
```

**`definitionsCommandes()`** — table **statique**, littérales `__()` **dans la table** (jamais
`__($variable)` au point d'usage) :

| `logicalId` | nom FR | subType | unité | generic | visible | historisé | ordre |
|---|---|---|---|---|---|---|---|
| `etat` | État | string | — | — | 1 | 0 | 0 |
| `etat_code` | Code d'état | numeric | — | — | **0** | 0 | 1 |
| `batterie` | Batterie | numeric | % | `BATTERY` | 1 | **1** | 2 |
| `en_nettoyage` | En nettoyage | binary | — | — | 1 | 0 | 3 |
| `erreur` | Erreur | string | — | — | 1 | 0 | 4 |
| `erreur_code` | Code d'erreur | numeric | — | — | **0** | 0 | 5 |
| `surface_nettoyee` | Surface nettoyée | numeric | m² | — | 1 | 0 | 6 |
| `duree_nettoyage` | Durée de nettoyage | numeric | min | — | 1 | 0 | 7 |
| `avancement` | Avancement | numeric | % | — | 1 | 0 | 8 |
| `en_ligne` | En ligne | binary | — | — | 1 | 0 | 9 |
| `connecte` | Connecté | binary | — | — | 1 | 0 | 10 |
| `derniere_maj` | Dernière mise à jour | string | — | — | 1 | 0 | 11 |

Historisation : **`batterie` seule**. C'est la seule série temporelle exploitable ; historiser un texte
d'état ou une surface remise à zéro à chaque cycle pollue la base sans usage. Les deux commandes « code »
sont **invisibles par défaut** (doublon visuel de l'état lisible) mais présentes et utilisables en
scénario, ce qu'AC2 demande ; l'utilisateur peut les afficher.

**`rafraichirEtat()`**, ordre imposé :
1. `$duid = trim((string) $this->getLogicalId());` — `!self::duidValide($duid)` →
   `throw jeeroborockDaemon::erreurLocale('DEVICE_UNKNOWN')`.
2. `!self::estCompteLie()` → `throw jeeroborockDaemon::erreurLocale('NOT_AUTHENTICATED')` — **aucun appel
   démon**.
3. `$r = jeeroborockDaemon::appeler('lireEtat', array('userData'=>self::getUserData(),
   'baseUrl'=>self::getBaseUrlCompte(), 'email'=>self::getEmailCompte(), 'duid'=>$duid),
   jeeroborockDaemon::TIMEOUT_ETAT);`
4. **`$this->appliquerConnexion($r)` — TOUJOURS, avant tout test d'échec.** C'est ce qui rend AC6 vrai même
   robot éteint.
5. Si `empty($r['etatLu'])` : `$motif` validé par `preg_match('/\A[A-Z0-9_]{1,40}\z/')` **et**
   appartenance à la liste blanche `DEVICE_OFFLINE|ROBOROCK_TIMEOUT|CONNECTION_FAILED` (sinon
   `DEVICE_OFFLINE`) → `log::add(info)` puis `throw jeeroborockDaemon::erreurLocale($motif)`.
6. Sinon : `$creees = $this->appliquerCapacites($r['capacites']); $this->appliquerValeurs($r['etat']);`
   puis `log::add('jeeroborock', 'debug', ...)`.

**`appliquerCapacites()`** (idempotence, AC5) : pour chaque capacité vraie,
`$cmd = $this->getCmd('info', $logicalId);`. Si `is_object($cmd)` : **on ne réécrit que le structurel**
(`type`, `subType`, `unite`, `generic_type`, `configuration[minValue|maxValue]`) pour qu'un correctif de
subType converge sur les installations existantes, et **on ne touche jamais** `name` / `isVisible` /
`isHistorized` / `order` (personnalisation utilisateur). Sinon `creerCommande()`. Une capacité
**nouvellement** détectée à une resync ultérieure crée sa commande **à son ordre défini**, sans
renumérotation ni doublon. Une capacité disparue laisse sa commande en place.

**Séquence exacte de `creerCommande()`**, sourcée sur `core/class/cmd.class.php` :
```php
$cmd = new jeeroborockCmd();
$cmd->setEqLogic_id($this->getId());   // l.3016 - OBLIGATOIRE (save() leve l.1085-1087)
$cmd->setEqType('jeeroborock');        // l.3171 - facultatif (save() le derive l.1091-1093),
                                       //          pose explicitement pour eviter un getEqLogic()
$cmd->setLogicalId($_logicalId);       // l.3161
$cmd->setName(<litterale __()>);       // l.2981 - OBLIGATOIRE (save() leve l.1076-1078)
$cmd->setType('info');                 // l.2995 - OBLIGATOIRE (save() leve l.1079-1081)
$cmd->setSubType(<string|numeric|binary>); // l.3007 - OBLIGATOIRE (save() leve l.1082-1084)
$cmd->setUnite(<'' | '%' | 'm²' | 'min'>); // l.3028
$cmd->setGeneric_type('BATTERY');      // l.2921 - batterie UNIQUEMENT
$cmd->setIsVisible(0|1);               // l.3132
$cmd->setIsHistorized(0|1);            // l.3022
$cmd->setOrder(<0..11>);               // l.3148
$cmd->setConfiguration('minValue', 0); // l.3057 - batterie / avancement seulement
$cmd->setConfiguration('maxValue', 100);
$cmd->save();
```
⚠️ **Ne PAS appeler `setTemplate()`** : `save()` pose `core::default` en dashboard et mobile
(l. 1098-1103). Le MVP se contente des widgets par défaut.
⚠️ **`minValue <= maxValue` est un invariant contrôlé par le core** (l. 1088-1090) : ne jamais poser l'un
sans l'autre.

**Robustesse** : la boucle de `appliquerCapacites()` enveloppe **chaque commande** dans un
`try/catch (Throwable)` — un échec de création n'interrompt pas les autres, il incrémente un compteur et
journalise `self::nettoyerPourLog(substr($e->getMessage(), 0, 256))`. **La troncature à 256 caractères est
obligatoire** : trois des quatre exceptions de `cmd::save()` embarquent `print_r($this, true)` (l. 1077,
1080, 1083), qui saturerait le log et la réponse AJAX (R-16). Même parade que R-5 d'UC06, même règle que
le `try/catch` par robot de `synchroniserEquipements()`.

**`appliquerValeurs()`** : **liste blanche fermée** de 9 clés, aucune boucle générique sur `$_etat`. Chaque
valeur est validée puis écrite par **`$this->checkAndUpdateCmd(<logicalId>, <valeur>)`**
(`eqLogic.class.php` l. 680) — helper du core qui ne déclenche un `event()` **que si la valeur a changé**
et qui ignore silencieusement une commande absente (donc cohérent avec la création conditionnelle). Une
clé absente du payload ⇒ **aucun appel** ⇒ la commande reste à sa valeur précédente. Validation :
`batterie`/`avancement` bornés `[0,100]` ; `surfaceNettoyeeM2` `floatval >= 0` ; `dureeNettoyageMin`
`intval >= 0` ; `etatCode`/`erreurCode` `intval >= 0` ; `enNettoyage` → `0|1` ;
`etatLibelle`/`erreurLibelle` → `self::texteInventaire(...)` (`nettoyerPourLog` + `trim` + troncature 128)
**avant** base et DOM — défense en profondeur, la chaîne vient du démon mais transite par le canal.

**`appliquerConnexion()`** : crée si besoin `en_ligne`/`connecte`/`derniere_maj` (inconditionnel), puis
`checkAndUpdateCmd('connecte', 0|1)` ; `en_ligne` écrite **seulement** si `$r['enLigne']` est un booléen
(jamais sur `null`) ; `derniere_maj` écrite **seulement** si `etatLu`.

### `core/class/jeeroborockDaemon.class.php` *(modifié — 2 ajouts)*
```php
const TIMEOUT_ETAT = 35;

// Fabrique une exception typee a partir d'un code stable de FAMILLE C (etat metier), pour un
// refus decide par le PHP lui-meme. Retourne l'exception, ne la leve PAS : utiliser
//   throw jeeroborockDaemon::erreurLocale('DEVICE_OFFLINE');
// Les codes de canal (familles A/B) sont REFUSES : ils ne peuvent provenir que du transport ou
// du protocole (AC5 d'UC03). Le filtrage delegue a estErreurCanal() pour ne PAS creer une
// troisieme copie de la liste des codes de canal (cf. Dette d'UC03).
public static function erreurLocale($_code) {
  $messages = self::tableMessages();
  if (!isset($messages[$_code])) { /* log warning + repli INTERNAL_ERROR */ }
  $exception = new jeeroborockException($_code, $messages[$_code]);
  if ($exception->estErreurCanal()) { /* log warning + repli INTERNAL_ERROR */ }
  return $exception;
}
```
Les cinq codes utilisés par UC07 (`NOT_AUTHENTICATED`, `DEVICE_UNKNOWN`, `DEVICE_OFFLINE`,
`ROBOROCK_TIMEOUT`, `CONNECTION_FAILED`) sont tous de famille C et passent le filtre. Le repli
`INTERNAL_ERROR` n'est atteignable que sur une erreur de programmation, signalée par un
`log::add(warning)` — son message est volontairement générique. **Aucune littérale i18n nouvelle.**
⚠️ `lever()` **reste `private`** et `tableMessages()` n'est pas modifiée.

### `core/ajax/jeeroborock.ajax.php` *(modifié — 1 `case`)*
Séquence inchangée (`isConnect('admin')` → `ajax::init()` → `session_write_close()` **déjà en place,
indispensable : l'appel peut durer 35 s**).
```
case 'rafraichirEtat':
  1. $eqLogic = eqLogic::byId(intval(init('id')));            // byId caste vers jeeroborock (eqLogic.class.php l.87-94)
     !($eqLogic instanceof jeeroborock) -> ajax::error(<litterale 1>) ; break
  2. $eqLogic->getIsEnable() == 0      -> ajax::error(<litterale 2>) ; break
  3. $r = $eqLogic->rafraichirEtat();   // jeeroborockException -> catch global du fichier
  4. $message = ($r['cmdCreees'] > 0) ? sprintf(<litterale 4>, $r['cmdCreees']) : <litterale 3>;
  5. ajax::success(array('message'=>$message, 'cmdCreees'=>intval($r['cmdCreees'])));
```
⚠️ Réponse **reconstruite champ par champ** (2 scalaires) : `$r` ne repart jamais brut. Invariant UC03→UC06
préservé. ⚠️ **Aucun `message::add()`** (rendu en HTML).

### `desktop/php/jeeroborock.php` *(modifié — ⚠️ TABULATIONS + CRLF)*
1. Dans `<span class="input-group-btn">`, **après** `data-action="configure"` et **avant**
   `data-action="copy"` (l. 63-64 ; les balises de fermeture sont volontairement placées à la ligne
   suivante — respecter ce style) :
   `<a class="btn btn-sm btn-default" id="bt_jeeroborockRafraichirEtat"><i class="fas fa-sync"></i><span class="hidden-xs"> {{Rafraîchir l'état}}</span>`
2. Suppression de la ligne « Ajouter une commande » (l. 188, `data-action="add"`) — D-07-7.

Aucune zone de résultat ajoutée : le retour passe par `$('#div_alert').showAlert(...)`, mécanisme standard
déjà disponible sur cette page.

### `desktop/js/jeeroborock.js` *(modifié — 2 espaces)*
Un gestionnaire `$('#bt_jeeroborockRafraichirEtat').on('click', …)` : garde « un robot est-il sélectionné »
(`$('.eqLogicAttr[data-l1key=id]').value()`), verrou **propre** `jeeroborockVerrouEtat` (booléen **ET**
`addClass('disabled')` — une balise `<a class="btn">` ignore `prop('disabled')`), `timeout: 50000`,
`success` → `showAlert({level:'success'|'danger'})` selon `donnees.state`, `error` → littérale « Le démon
ne répond pas. ».
⚠️ **Fichier RENDU** : cf. R-7 — aucune double accolade ouvrante littérale hors clé i18n, et **littérales
traduisibles en apostrophes simples**.

## Validation

| Quoi | Où (autoritaire) | Comportement / message |
|---|---|---|
| équipement existant et du bon type | PHP, `instanceof jeeroborock` | littérale « Équipement introuvable… » |
| équipement activé | PHP, `getIsEnable()` | littérale dédiée. Motif non cosmétique : `checkAndUpdateCmd` **retourne `false` sans rien écrire** si l'eqLogic est désactivé (`eqLogic.class.php` l. 681-683) — sans ce garde, l'utilisateur verrait « État rafraîchi » sans aucune valeur |
| compte lié | PHP, `estCompteLie()` | `NOT_AUTHENTICATED`, **sans appeler le démon** |
| `duid` de l'équipement | PHP, `duidValide()` (regex ancrée) | `DEVICE_UNKNOWN` |
| `userData` vide / illisible | démon (défense en profondeur) | `NOT_AUTHENTICATED` / `AUTH_EXPIRED` |
| session refusée par le cloud | cloud (code `2010`) | `RoborockInvalidCredentials` → `AUTH_EXPIRED` |
| quota `homedata` (construction) | démon, limiteur de la lib, **avant tout réseau** | `RATE_LIMIT`, **non rattrapé, aucune retentative** |
| robot inconnu du gestionnaire | démon | `DEVICE_UNKNOWN` (« relancez une synchronisation ») |
| robot non connecté | démon, `device.is_connected` | succès partiel `etatLu=false` + `DEVICE_OFFLINE` ⇒ PHP écrit `connecte=0` **puis** lève |
| RPC `get_status` muette | démon, `wait_for(12 s)` | idem, `motifEchec` issu de `code_pour_exception` |
| structure de la réponse | PHP, `is_array` + listes blanches fermées | non conforme → rien écrit, aucun plantage |
| bornes des valeurs | PHP (`is_numeric`, `[0,100]`, `>= 0`) | hors bornes → valeur **ignorée**, jamais écrasée par un défaut |
| chaînes d'origine démon | PHP, `texteInventaire()` | `nettoyerPourLog` + `trim` + 128 avant base/DOM |
| création d'une commande | PHP, `try/catch` **par commande** + troncature 256 | un échec n'interrompt pas les autres (R-16) |
| budget | démon 30 s < canal 34 s < PHP 35 s < jQuery 50 s | `OPERATION_TIMEOUT` puis `DAEMON_TIMEOUT` en dernier recours |
| droits | `isConnect('admin')` du fichier AJAX | endpoint **admin** uniquement |

**Typage** : `jeeroborockException` sur tout le chemin PHP (l'AJAX affiche `getMessage()` via le `catch`
global existant) ; `ErreurDemon` côté Python ; les exceptions `python-roborock` de la **construction**
remontent non rattrapées jusqu'à `handler_rpc`, **point de mapping unique**. Celles de la **lecture** sont
converties en succès partiel, et seulement pour la famille « le robot n'a pas répondu ».

**Secrets** : `local_key`, `HomeData`, `UserData` ne quittent pas le démon (le `userData` transite dans le
**tableau** de paramètres, jamais en scalaire) ; `contexte["gestionnaire"]` n'est jamais sérialisé ;
`appeler()` ne journalise que les **noms** de clés ; réponse AJAX reconstruite champ par champ ; aucun
`displayException()` ; aucun `diagnostic_data()`.

## Dépendances

**Aucune nouvelle dépendance.** `python-roborock` 7.8.0 reste la seule, déjà déclarée dans
`plugin_info/packages.json`. `aiohttp` et `aiomqtt` sont des dépendances transitives déjà présentes.
`plugin_info/packages.json` **n'est pas modifié**.

## Impact i18n (français uniquement dans cette UC)

`core/i18n/*.json` **non touchés** — traduction par le sous-agent `translator` en fin de cycle.
**20 littérales françaises nouvelles**, toutes **littérales** (`sprintf` **autour** de `__()`).

**`core/ajax/jeeroborock.ajax.php` — 4**
1. « Équipement introuvable ou non géré par ce plugin. »
2. « Cet équipement est désactivé : activez-le avant de rafraîchir son état. »
3. « État rafraîchi. »
4. « État rafraîchi — %s nouvelle(s) commande(s) créée(s). Rechargez la page pour les voir. »

**`core/class/jeeroborock.class.php` — 12** (noms de commandes, **dans** `definitionsCommandes()`)
5. « État » · 6. « Code d'état » · 7. « Batterie » · 8. « En nettoyage » · 9. « Erreur » ·
10. « Code d'erreur » · 11. « Surface nettoyée » · 12. « Durée de nettoyage » · 13. « Avancement » ·
14. « En ligne » · 15. « Connecté » · 16. « Dernière mise à jour »

**`desktop/php/jeeroborock.php` — 1**
17. « Rafraîchir l'état »

**`desktop/js/jeeroborock.js` — 3** *(⚠️ **nouvelle entrée i18n** : ce chemin est absent des trois fichiers
`core/i18n/*.json`)*
18. « Sélectionnez d'abord un robot. » · 19. « Rafraîchissement en cours… » · 20. « Le démon ne répond
pas. »

⚠️ **À signaler au `translator`** :
- « Le démon ne répond pas. » existe déjà sous `plugin_info/configuration.php` ; les fichiers i18n étant
  indexés **par fichier**, elle doit exister **aussi** sous `desktop/js/jeeroborock.js` — **ce n'est pas un
  doublon à factoriser** (même situation qu'UC03/UC05).
- `desktop/js/jeeroborock.js` porte déjà des doubles accolades **héritées du squelette** (« Nom de la
  commande », « Tester », « Historiser »…) jamais traduites : le `translator` les solde au passage.
- **Clé orpheline** : « Ajouter une commande » disparaît de `desktop/php/jeeroborock.php` (D-07-7) → à
  nettoyer.
- Les **libellés d'état et d'erreur** produits par `resources/demond/libelles.py` sont des **valeurs de
  commande**, pas des chaînes d'UI : ils ne passent par aucun mécanisme i18n (R-5).

Logs `log::add` et logs du démon : français **non enveloppé**. Codes stables et `motifEchec` : **anglais /
jetons stables**.

## Risques & pièges

- **R-1 (majeur) — un code d'état inconnu est écrasé en `unknown` (0) par la librairie.**
  `RoborockEnum._missing_` retombe sur `unknown` **avant** que le démon ne voie la valeur : `etat_code`
  vaudra 0, pas le code réel. La lib logge `Missing RoborockStateCode code: X` — seule trace du code brut.
  Non corrigeable sans réimplémenter la désérialisation. À lever en recette.
- **R-2 (majeur, touche AC3) — un code d'erreur inconnu est rapporté comme « aucune erreur ».**
  `RoborockErrorCode` n'a **pas** de membre `unknown` ⇒ `_missing_` retombe sur le **premier** membre,
  `none = 0`. Le plugin affichera donc une erreur vide alors que le robot est en défaut. Même trace
  `Missing RoborockErrorCode code: X` dans le log du démon. **Point de recette obligatoire** : provoquer
  une erreur réelle (bac retiré) et vérifier que `erreur_code` n'est pas 0.
- **R-3 (majeur) — la première lecture après un démarrage du démon coûte un `homedata` et peut répondre
  « robot hors ligne ».** Construction du gestionnaire = 1 appel sur 40/jour (partagé avec l'application
  mobile et avec « Tester la connexion »/« Synchroniser »), et `start_connect()` ne garantit pas la
  connexion. L'attente bornée de 3 s réduit la fenêtre sans la supprimer. **Aucune retentative
  automatique** : c'est l'utilisateur qui reclique.
- **R-4 (majeur, touche AC6) — `en_ligne` est gelée pendant toute la vie du démon.** Elle vient de
  `HomeDataDevice.online`, lu dans le `HomeData` mis en cache à la construction du gestionnaire. Elle ne
  peut pas être rafraîchie sans rebrûler un `homedata`. **AC6 reste satisfait par `connecte`**, qui bascule
  à chaque lecture. Levée prévue en **UC10** (push `RoborockDataProtocol.OFFLINE_STATUS = 135`).
  **Arbitrage utilisateur de ce cycle : la commande est créée quand même.**
- **R-5 — les libellés d'état/erreur ne sont pas traduisibles.** Composés en français dans `libelles.py` :
  un utilisateur `en_US`/`de_DE`/`es_ES` verra du français pour ces **valeurs** (les **noms** de commandes,
  eux, sont traduits). Corollaire assumé de « les libellés viennent du démon », déjà acté par la spec
  fonctionnelle. Un code non répertorié affiche l'identifiant anglais de la librairie, jamais un vide.
- **R-6 — sans `dontRemoveCmd()`, un « Sauvegarder » depuis un onglet périmé détruit les commandes.**
  `core/ajax/eqLogic.ajax.php` l. 598-602. Neutralisé par D-07-7. Coût d'un revirement : 1 ligne, mais la
  casse serait silencieuse et irréversible (historique perdu, scénarios cassés).
- **R-7 — `desktop/js/jeeroborock.js` est un fichier RENDU.** Vérifié : `utils.inc.php` l. 95-103 →
  `getResource.php` l. 49-54 → `translate::exec(..., true)`. Deux conséquences :
  **(a)** aucune double accolade ouvrante littérale hors clé i18n, commentaires et littéraux objet JS
  compris — `python .claude/scripts/verif-plugin.py` (colonne `meta=`) avant commit ;
  **(b)** les littérales traduisibles s'y écrivent **en apostrophes simples**, à l'inverse de
  `configuration.txt` : c'est `$_backslash = true` qui protège ce délimiteur (`translate.class.php`
  l. 155-157), et lui seul — une traduction contenant un guillemet double casserait un littéral en
  guillemets doubles, sans échappement pour la rattraper.
  ⚠️ **`verif-plugin.py` ne contrôle pas ce fichier** (son `re.finditer` porte sur `CONFIG_TXT`) —
  relecture manuelle obligatoire.
  ⚠️ **Correction de mémoire projet** : l'affirmation d'UC03 « un fichier servi par balise `script src`
  n'est pas traduit » ne vaut que pour `3rdparty`/`*.min.js`. La **décision** d'UC03 (JS inline dans
  `configuration.txt`) reste valide — aucun `.js` n'existe pour la page de configuration plugin, et en
  créer un pour ~30 lignes ajouterait un fichier et une requête — seul son **motif écrit** est à réécrire.
- **R-8 — `clean_percent` (avancement) non vérifié sur le Qrevo Curv.** `is_support_clean_estimate` doit
  être vrai (ioBroker déclare `CleanPercent` pour l'`a135`) mais ce n'est pas démontré. Si la capacité est
  fausse, **aucune** commande `avancement` n'est créée — comportement attendu par AC4, pas un bug.
- **R-9 — dérivation de `en_nettoyage` à confirmer.** L'ensemble `ETATS_NETTOYAGE` est bâti sur la liste de
  `RoborockStateCode` ; le code réellement rapporté par le Qrevo Curv en aspiration+lavage n'est pas
  vérifié. **Hypothèse à confirmer sur le matériel réel.**
- **R-10 — le canal V1 tente toujours le TCP local.** Non désactivable en 7.8.0 (D9 de
  `jeeroborock-architecture.md`). Si le robot est joignable localement mais que la liaison est instable,
  `send_command` peut consommer **10 s** sur la stratégie locale avant de basculer sur MQTT — c'est
  précisément ce que borne `DELAI_LECTURE_S = 12` (au prix d'un `etatLu=False` au lieu d'un basculement
  réussi). À observer en recette.
- **R-11 — une session déliée laisse le gestionnaire (et sa session MQTT) ouvert** jusqu'au prochain
  `lireEtat` ou à l'arrêt du démon : `oublierSession()` appelle `restaurerSession` avec un `userData` vide,
  et UC07 ne touche pas `authentification.py` (D-07-2). Fuite de ressource, pas de secret. **À solder en
  UC11**, qui modifiera déjà ce fichier.
- **R-12 — écart d'analyse à corriger** : `.memory/analyse/jeeroborock-modele-equipement.md` § 2.1 annonce
  `clean_area` en **cm²** ; la source dit **mm²** (diviseur 1 000 000). À reprendre en capitalisation, avec
  la règle « utiliser `square_meter_clean_area`, pas une conversion maison » et le § 7 point 3 marqué
  tranché.
- **R-13 — `desktop/php/jeeroborock.php` est en tabulations + CRLF**, seule exception du dépôt. Un bloc
  inséré en 2 espaces produit un fichier mixte.
- **R-14 — contrainte sur l'avenir (UC08/UC09).** `robots.obtenir_appareil()` est le point d'extension :
  UC08 (actions) et UC09 (routines, qui passent par `device.v1_properties.routines` donc par le **même**
  gestionnaire) doivent l'appeler et **jamais** reconstruire un `DeviceManager`. Une seconde construction
  doublerait la consommation de quota et ouvrirait une seconde session MQTT sur le même compte.
- **R-15 — duplication consciente de `_texte()`** entre `equipements.py` et `robots.py` (4 lignes, corps
  identique). Conséquence assumée du refus de toucher un fichier livré pour un gain nul. **À factoriser
  dans un module utilitaire commun au prochain cycle qui modifie déjà `equipements.py` pour d'autres
  raisons ; une 3ᵉ occurrence doit déclencher la factorisation.**
- **R-16 — `cmd::save()` lève avec `print_r($this, true)`** sur nom / type / sous-type / rattachement vide
  (`cmd.class.php` l. 1076-1087), et refuse `minValue > maxValue` (l. 1088-1090). Neutralisé par la table
  de définitions statique (les quatre champs sont toujours renseignés), par le `try/catch` **par commande**
  et par la troncature à 256 caractères. Sans ces mesures, un défaut de définition produit un log illisible
  et une erreur AJAX géante.

## Recette (à confirmer sur une Jeedom réelle, Debian 12+)

| # | AC | Vérification | Attendu |
|---|---|---|---|
| R-1 | AC1 | robot créé (UC06), démon démarré, clic « Rafraîchir l'état » | Commandes créées et peuplées ; valeurs cohérentes avec l'application mobile |
| R-2 | AC2 | onglet Commandes | `État` = texte français (« En nettoyage », « À la base »…) ; `Code d'état` = entier correspondant |
| R-3 | **AC3** | robot sans défaut, puis bac retiré, re-rafraîchir | `Erreur` **vide** et `Code d'erreur` **0** au repos ; libellé FR et code non nul en défaut (si code 0 en défaut → **R-2 du § Risques**, relever le code dans le log du démon) |
| R-4 | **AC4** | log du démon en `debug`, inspecter `capacites` | Aucune commande créée pour une capacité fausse ; `avancement` présent **ou** absent selon `is_support_clean_estimate`, jamais figé à 0 ; `surface_nettoyee`/`duree_nettoyage` absentes si le robot ne les remonte pas |
| R-5 | **AC5** | rafraîchir 3 fois de suite | **Même nombre** de commandes ; aucun doublon ; un renommage manuel de commande **survit** au rafraîchissement suivant |
| R-6 | **AC6** | éteindre le robot, rafraîchir | `Connecté` passe à 0 ; `Dernière mise à jour` **ne bouge pas** ; message « Le robot est hors ligne… » ; les anciennes valeurs d'état restent affichées |
| R-7 | budget | démon arrêté, clic « Rafraîchir l'état » | « Le démon ne répond pas… » en moins de 5 s, interface Jeedom fluide (preuve du `session_write_close()`) |
| R-8 | quota | rafraîchir 10 fois d'affilée | **Une seule** ligne de construction du gestionnaire dans le log du démon ; **aucun** `homedata` supplémentaire ; aucun `RATE_LIMIT` |
| R-9 | D-07-2 | ré-authentifier (UC04) puis rafraîchir | Gestionnaire reconstruit (log), état lu avec la nouvelle session |
| R-10 | D-07-7 | rafraîchir, puis « Sauvegarder » l'équipement sans recharger | **Aucune** commande supprimée |
| R-11 | secrets | log en `debug`, rejouer tout le scénario | Ni `local_key`, ni jeton, ni blob base64 dans `log/jeeroborock`, `log/jeeroborock_demon`, `log/php` ; onglet réseau du navigateur sans secret |
| R-12 | i18n | changer la langue de Jeedom en `en_US` | Les **noms** de commandes sont traduits ; les **valeurs** d'état/erreur restent en français (R-5, attendu) |
| R-13 | non-régression | rejouer UC04 (code e-mail), UC05 (tester la connexion), UC06 (synchroniser) | Comportement identique — **aucun** fichier de ces UC n'a été modifié |

## Dette

*Les deux reviews du tour 1 sont ressorties `pass` — aucun finding `critical`/`high`/`blocker`/`major`,
donc pas de tour 2. Le seul défaut du cycle (3 chaînes UI du `.js` non enveloppées, trouvé en vérification
d'orchestration) a été corrigé en passe de finition. Reste ci-dessous ce qui est sciemment reporté.*

- **R-15** — `_texte()` dupliqué entre `equipements.py` et `robots.py`. À factoriser au prochain cycle qui
  modifie déjà `equipements.py` ; une 3ᵉ occurrence doit déclencher la factorisation.
- **R-11** — le gestionnaire reste ouvert après une déliaison de session, jusqu'au prochain `lireEtat` ou à
  l'arrêt du démon. À solder en UC11.
- **`canal.py::handler_rpc` journalise avec `exc_info=True`** toute exception non typée — chemin
  **nouvellement atteignable** par la construction du `DeviceManager` (UC07). Relevé par la review
  sécurité, non élevé en finding : un traceback Python n'expose pas les arguments de frame comme PHP, et
  rien de ce contenu n'atteint la réponse AJAX ni le DOM. **Mais** si une future version de
  `python-roborock` plaçait le `userData` dans le message d'une exception, il atterrirait dans le log du
  démon. Le fichier est hors périmètre d'UC07 (non-régression UC03) — **à traiter au prochain cycle qui
  modifie déjà `canal.py`**.
- **Héritée d'UC06, non aggravée** — le dict `contexte['session']` reste construit inline par les
  4 opérations qui le réamorcent. `lireEtat` n'en crée **pas** de 5ᵉ occurrence.
