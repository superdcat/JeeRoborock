# Spec technique — UC06 : Découverte et création des équipements

> **Spec fonctionnelle** : `06-decouverte-creation-equipements.md` · **Dépend de** : UC03 (canal), UC04
> (session), UC05 (`etatCompte`, cache `inventaireCompte`) · **Date** : 2026-09-18

## Périmètre

Transformer l'inventaire du compte Roborock en équipements Jeedom stables et idempotents :

- **une opération démon** `decouvrirEquipements`, dans un **module dédié** `resources/demond/equipements.py` ;
- **une extraction préalable** `resources/demond/session.py` (dette échue d'UC05) ;
- **une méthode statique** `jeeroborock::synchroniserEquipements()` ;
- **un bouton** dans la page de configuration plugin ;
- **la page équipement** `desktop/php/jeeroborock.php`, sortie du squelette.

**Aucune** nouvelle route HTTP, **aucune** nouvelle classe PHP, **aucun** nouveau code d'erreur stable,
**aucune** nouvelle clé de configuration plugin, **aucune** dépendance.

Hors périmètre : commandes info/action (UC07/08/09), cartes et pièces (post-MVP), salles
(`HomeData.rooms`).

### Couverture des critères d'acceptation

| AC | Réalisé par |
|---|---|
| **AC1** — modèle + micrologiciel | `decouvrir()` renvoie `model` (`HomeDataProduct.model`) et `fv` (`HomeDataDevice.fv`) → `appliquerRobot()` pose `setConfiguration('model'|'fv')` → affichés en lecture seule dans le bloc « Inventaire Roborock » de la page équipement |
| **AC2** — pas de doublon | Clé d'identité unique `eqLogic::byLogicalId($duid, 'jeeroborock')` ; branche création **uniquement** si `!is_object(...)` ; dédoublonnage par `duid` **aussi** côté démon |
| **AC3** — nom Jeedom jamais écrasé | `setName()` n'apparaît **que** dans la branche création. Le nom Roborock courant va dans `setConfiguration('nomRoborock')` |
| **AC4** — robot renommé côté app | `logicalId = duid`, insensible au renommage. Observable en recette : nom Jeedom figé, `nomRoborock` qui suit |
| **AC5** — robot partagé | Partage = appartenance à `home.received_devices` → `setConfiguration('shared', 1)`. Visible : libellé `label label-info` « Robot partagé » sur la vignette (**rendu serveur**) + phrase dans le compte rendu |
| **AC6** — protocole non V1 | Filtrage **dans le démon** : compatible ⇔ `pv == "1.0"` **ET** catégorie `robot.vacuum.cleaner` **ET** produit résolu. Les autres partent dans `nonSupportes[]` ; le PHP n'a **aucun** chemin de création pour eux |
| **AC7** — aucun secret | Le démon construit le dict **champ par champ** ; `home` / `device.as_dict()` / `local_key` ne traversent jamais le canal. Côté PHP, **liste blanche fermée** de 8 clés |
| **AC8** — bouton uniquement | Aucun hook `cron*` ajouté ni décommenté ; appel **uniquement** depuis `case 'synchroniserEquipements'` de l'AJAX admin |

Le point « À confirmer » de la spec fonctionnelle est tranché : `sn` est un champ optionnel de
`HomeDataDevice` et est repris ; les **salles** sont hors périmètre.

---

## Architecture

### Fichiers

| Fichier | État | Contenu | Indentation / EOL |
|---|---|---|---|
| `resources/demond/session.py` | **créé** | Import gardé de `UserData`/`RoborockApiClient` + `IMPORT_OK`, `creer_client`, `encoder_user_data`, `decoder_user_data` | **4 espaces, LF** |
| `resources/demond/equipements.py` | **créé** | `decouvrir()` + helpers + `enregistrer_operations()` | **4 espaces, LF** |
| `resources/demond/authentification.py` | modifié | **Suppressions** : `_encoder_user_data`, `_decoder_user_data`, `_client_compte`, `_IMPORT_OK`, imports `roborock.*` → `from session import …`. **Aucun autre changement** | 4 espaces, LF |
| `resources/demond/jeeroborockd.py` | modifié | `import equipements` + `equipements.enregistrer_operations()` **explicite** | 4 espaces, LF |
| `resources/demond/canal.py`, `erreurs.py` | **non modifiés** | Non-régression UC03 | — |
| `core/class/jeeroborock.class.php` | modifié | 2 constantes + `synchroniserEquipements()` + 3 privées ; **aucun** hook cron | 2 espaces, CRLF |
| `core/class/jeeroborockDaemon.class.php` | modifié | **1 ligne** : `const TIMEOUT_DECOUVERTE = 20;`. `tableMessages()` **non modifiée** | 2 espaces, CRLF |
| `core/class/jeeroborockException.class.php` | **non modifié** | Aucun code de famille A/B ajouté | — |
| `core/ajax/jeeroborock.ajax.php` | modifié | 1 `case 'synchroniserEquipements'` | 2 espaces, CRLF |
| `plugin_info/configuration.txt` | modifié | Fieldset « Équipements » + bouton + zone de résultat + gestionnaire JS inline | 2 espaces, CRLF |
| `plugin_info/configuration.php` | **régénéré par copie** | `cp plugin_info/configuration.txt plugin_info/configuration.php` ; contrôle par `git status --short`. **Jamais édité, jamais relu** | idem |
| `desktop/php/jeeroborock.php` | modifié | Retrait de la tuile « Ajouter » ; libellé « Robot partagé » ; bloc « Inventaire Roborock » lecture seule à la place des 3 champs squelette | ⚠️ **TABULATIONS + CRLF** |
| `core/php/jeeroborock.inc.php` | **non touché** | **Autoload sans objet** : aucune classe PHP nouvelle | — |
| `core/config/*.ini`, `packages.json`, `info.json`, `desktop/js/*`, `desktop/modal/*`, `core/template/**`, `core/php/jeeJeeroborock.php` | **non touchés** | Aucune clé de config, aucune dépendance, aucune ligne de commande, aucune modale, aucun push | — |
| `core/i18n/*.json` | **non touchés** | Traduction par le sous-agent `translator` en fin de cycle | — |

### Décisions d'architecture

**D-06-1 — Aucune nouvelle classe PHP ; la synchronisation vit sur `jeeroborock`.**
Elle manipule des eqLogic et leur cycle de vie : c'est le domaine de la classe principale. Une classe
`jeeroborockDecouverte` ajouterait un fichier, un risque de casse de nom (R6 d'UC03) et un point d'entrée
autoload de plus pour ~120 lignes. *Alternative écartée* : logique dans le fichier AJAX — non réutilisable
par UC07.

**D-06-2 — Un module démon par domaine : `equipements.py`, et extraction de `session.py` au périmètre
strict de la dette d'UC05.**
UC05 avait inscrit en dette : « dès qu'UC06 créera son module, `_decoder_user_data`/`_encoder_user_data`
devront être déplacés dans un module de session partagé — ne pas les importer depuis `authentification`
sous leur nom privé ». On exécute **exactement** cela, et rien de plus.

⚠️ **Arbitrage utilisateur du 2026-09-18** : le plan initial ajoutait un `memoriser_session()` factorisant
le dict `contexte["session"]` construit **inline** dans les trois call-sites `valider_code`,
`restaurer_session` et `etat_compte`. **Écarté.** Motif : cette factorisation dépasse la dette documentée
et ferait porter un risque de régression sur le chemin d'authentification **déjà livré** (UC04) et sur le
test de connexion (UC05) à un cycle qui ne les concerne pas. Conséquence assumée : `equipements.py`
construit **son propre** dict de session inline, soit une **4ᵉ** occurrence du même littéral de 3 clés.
C'est une duplication consciente, inscrite en **Dette** ci-dessous.

⚠️ **Conséquence de contrat, à respecter à la lettre** : `authentification.py` perd ses imports
`roborock.*` mais **conserve `_client_pour()` inchangé**, qui instancie `RoborockApiClient(email)` sans
`base_url`. Il doit donc réimporter le **symbole** depuis `session.py` :
`from session import IMPORT_OK, RoborockApiClient, creer_client, decoder_user_data, encoder_user_data`.
⚠️ **Ne pas y remettre `UserData`** : le symbole n'est utilisé nulle part dans
`authentification.py` (seul `decoder_user_data`, désormais dans `session.py`, s'en sert). La première
version de cette spec l'incluait à tort — import mort relevé en review.
**Ne pas** réécrire `_client_pour()` en `creer_client(email)` : le défaut de `base_url` côté
`RoborockApiClient` n'est pas vérifié, et un `base_url=None` explicite n'est pas *démontré* équivalent à
son omission. Le but de l'arbitrage est un **diff de pur déplacement**, à zéro changement de comportement
sur UC04/UC05.

⚠️ **`_IMPORT_OK` devient `IMPORT_OK`** (exporté). Toutes ses occurrences dans `authentification.py` —
en tête de `demander_code`, `valider_code`, `restaurer_session`, `etat_compte` — doivent être renommées.
Un oubli fait lever un `NameError` **au premier appel**, pas à l'import : `php -l` et le démarrage du
démon ne le verront pas.

*Vérifié* : aucun paquet de l'arbre de dépendances (`aiohttp`, `pyrate-limiter`, `paho-mqtt`,
`pycryptodome`, `Pillow`, `vacuum-map-parser-*`) n'expose un module de premier niveau `session` que
`resources/demond/` masquerait via `sys.path[0]`.

**D-06-3 — La découverte fait toujours un `homedata` frais ; elle ne consomme pas le cache d'UC05, elle
le réécrit.**
Le cache d'UC05 ne contient qu'un entier, inexploitable pour créer un équipement ; et l'utilisateur qui
clique « Synchroniser » demande explicitement un état à jour. En contrepartie, la synchronisation **met à
jour** `jeeroborock::inventaireCompte` avec `nbTotal` — **contrainte ferme** posée par R-6 d'UC05 (« UC06
devra rafraîchir ou invalider `inventaireCompte`, faute de quoi le test de connexion et la liste des
équipements se contrediront pendant 24 h »). La définition de `nbTotal` est **strictement celle d'UC05**
(`len(get_all_devices())`) pour que les deux messages restent cohérents.

**D-06-4 — Un refus de quota est une erreur dure ici, contrairement à UC05 (D-05-6).**
Dans UC05, l'inventaire était accessoire (la sonde suffisait) : le refus **local** était dégradé. Ici
l'inventaire **est** l'opération : `RoborockRateLimit` → `RATE_LIMIT` remonte tel quel, avec son message
FR existant. **Aucune retentative, aucun repli.**

**D-06-5 — Compatibilité = `pv == "1.0"` ET catégorie `VACUUM` ET produit résolu.**
Aligné sur `device_manager.py` de la librairie (cf. Contrats externes § 5), qui refuse elle-même un
`pv == "1.0"` de catégorie non-aspirateur. Sans la seconde condition, UC07 créerait des commandes pour un
appareil que la lib refusera d'instancier → « équipement fantôme ou incomplet », précisément ce qu'AC6
interdit. Les trois motifs de rejet sont distingués dans le **log** (`PROTOCOLE`, `CATEGORIE`,
`PRODUIT_INCONNU`) mais produisent **le même message utilisateur** : l'action de l'utilisateur est
identique dans les trois cas (aucune).

**Niveaux de journalisation retenus** — ce sont eux qui rendent les points de recette **R-1** et **R-6**
exécutables ; sans eux, le risque majeur R-1 (`pv == "1.0"` non vérifié sur le Qrevo Curv) ne peut pas
être levé :

- appareil **rejeté** → `logging.info` avec le `motif`, le `pv` détecté et un identifiant tronqué : visible
  en exploitation normale, c'est ce que **R-6** vient lire ;
- appareil **accepté** → `logging.debug` avec le `pv` détecté : potentiellement un log par robot du compte,
  donc réservé au diagnostic, mais c'est la **seule** source qui permet à **R-1** de confirmer la valeur
  réelle de `pv` sur le matériel.

Ces logs sont composés **à partir de nos propres champs**, passés par `_texte()` : jamais `home`, jamais
`local_key`, jamais `device.summary_info()`.

**D-06-6 — Un robot disparu du compte n'est ni supprimé, ni désactivé, ni signalé.**
Aucun AC ne le demande, et supprimer un eqLogic détruirait l'historique, les scénarios et les vues de
l'utilisateur. Décision explicite, à revoir en UC11 (robustesse) si le besoin apparaît.

**D-06-7 — La tuile « Ajouter » est retirée de la page équipement.**
Un `jeeroborock` créé à la main n'a pas de `duid`, donc pas de `logicalId` : il ne serait pilotable par
aucune UC ultérieure, ne serait jamais rattrapé par une synchronisation, et deviendrait un équipement
fantôme permanent — exactement ce qu'AC6 cherche à éviter. La découverte est l'**unique** chemin de
création légitime. La tuile « Configuration » est conservée et mène à la page où vit le bouton.

**D-06-8 — Les 3 champs « Paramètres spécifiques » du squelette sont supprimés** (`param1`, **`password`**,
`autorefresh`). Motif non cosmétique : un champ `inputPassword` sur un eqLogic contredit frontalement
l'invariant central du plugin (« configuration par équipement : **aucun secret** »), et `autorefresh`
promet un cron d'auto-actualisation qui n'existe pas et qu'AC8 interdit. UC06 est la **première** UC où
cette page porte réellement des équipements, donc la première où ces champs deviennent visibles.

**D-06-9 — Le rapport de synchronisation est composé côté serveur, en une seule chaîne.**
Le JS applique `.text(donnees.result.message)` et ne dérive aucun texte des compteurs — même parti pris
qu'UC05 : le JS ne porte ni règle métier ni libellé d'état.

**D-06-10 — Clés de payload du canal = clés de configuration d'équipement.**
`duid`, `model`, `productName`, `fv`, `pv`, `sn`, `shared`, `nomRoborock`. Le PHP fait une **copie
validée**, sans table de correspondance (une table serait une source de bugs silencieux pour un gain nul).
Convention : *une clé qui reflète un champ de l'API garde le nom de l'API ; une clé inventée par le plugin
est en français* (`nomRoborock`) — comme `userData`/`baseUrl` cohabitent déjà avec `avecInventaire`.

---

## Server vs Client

**Tout est serveur.** Le client (JS inline de `configuration.txt`) ne fait que : désarmer le bouton,
poster l'action AJAX, et insérer par `.text()` la chaîne composée par le serveur. Aucune règle de
compatibilité, aucun libellé d'état, aucun comptage n'est dérivé côté navigateur.

Motif : les trois décisions sensibles de l'UC — compatibilité V1, indicateur « partagé », identité stable
d'un équipement — sont des règles métier dont une divergence client/serveur produirait un affichage qui
contredit la base. Le filtrage de compatibilité est même poussé **plus bas encore que le PHP**, dans le
démon (D-06-5), pour que le PHP n'ait littéralement **aucun chemin de code** capable de créer un
équipement non supporté.

Le libellé « Robot partagé » de la page équipement est **rendu serveur** dans la boucle `foreach`, et non
posé par le binding `data-l1key` : il doit être fiable indépendamment du chargement du formulaire.

---

## Contrats externes

Tous les appels sont faits **par `python-roborock` 7.8.0 dans le démon**. Aucun appel réseau PHP. Sources
lues **verbatim au tag `v7.8.0`**.

### 1. `RoborockApiClient.get_home_data_v3(user_data) -> HomeData` — `roborock/web_api.py` l. 512-533

- **Première instruction** :
  `if not self._home_data_limiter.try_acquire("home_data", blocking=False): raise RoborockRateLimit(...)`
  → quota atteint détecté **avant tout paquet réseau**.
- Puis `_get_home_id(user_data)` (`GET <baseUrl>/api/v1/getHomeDetail`, en-tête `Authorization: <token>`,
  `code 2010` → `RoborockInvalidCredentials`), puis `GET <rriot.r.a>/v3/user/homes/<home_id>` signé
  **Hawk**. `home_response["result"]` → `HomeData.from_dict(...)`.
- Limiteur `_HOME_DATA_RATES = [1/s, 3/min, 5/h, 40/jour]`, **attribut de classe** (l. 58-66) ⇒ compteur
  **par processus**.
- Docstring l. 513 : « same as get_home_data, but uses a different endpoint and **includes non-robotic
  vacuums** » ⇒ la réponse contient aussi les lave-linge / Dyad / Zeo du compte. **C'est ce qui rend AC6
  réalisable** : le plugin voit les appareils incompatibles et peut les nommer au lieu de les ignorer.
- ⚠️ `PreparedRequest.request()` (l. 796-819) ne passe **aucun `timeout=`** → seul le budget global du
  canal borne l'attente.

### 2. `HomeData` — `roborock/data/containers.py` l. 379-422

```
id: int ; name: str
products: list[HomeDataProduct]        = field(default_factory=list)
devices: list[HomeDataDevice]          = field(default_factory=list)
received_devices: list[HomeDataDevice] = field(default_factory=list)
lon, lat, geo_name ; rooms: list[HomeDataRoom]

def get_all_devices() -> list[HomeDataDevice]      # devices + received_devices
cached_property product_map     -> dict[product.id, HomeDataProduct]
cached_property device_products -> dict[str, tuple[HomeDataDevice, HomeDataProduct]]
```

⚠️ **`device_products` écarte silencieusement** tout appareil dont `product_id` est absent de
`product_map` (`if (product := product_map.get(...)) is not None`). On **ne l'utilise donc pas** : on
itère `get_all_devices()` et on résout via `product_map.get(...)`, pour pouvoir **signaler** l'appareil
au lieu de le perdre.

### 3. `HomeDataDevice` — `containers.py` l. 295-332

```
duid: str            # REQUIS -> logicalId Jeedom
name: str            # REQUIS -> nom initial (creation seule) + configuration nomRoborock
local_key: str       # REQUIS -> NE QUITTE JAMAIS LE DEMON
product_id: str      # REQUIS -> jointure vers HomeDataProduct
fv / pv / sn : str | None        # micrologiciel / protocole / numero de serie
online: bool | None
share / share_time / share_type / share_expired_time   # DEFINIS MAIS JAMAIS UTILISES PAR LA LIB
... (nombreux optionnels) ; def summary_info() -> str
```

⚠️ **`share` n'est lu nulle part dans la librairie** (seules les occurrences de `received_devices`
existent dans `containers.py` / `web_api.py` / `device_manager.py`). Sa sémantique n'est **pas**
corroborée → **ne pas s'en servir**. L'indicateur retenu est **l'appartenance à `received_devices`**, seul
critère utilisé par l'écosystème et par `.memory/analyse/jeeroborock-cloud-api.md` § 4.

### 4. `HomeDataProduct` — `containers.py` l. 252-293

```
id: str ; name: str ; model: str ; category: RoborockCategory      # les 4 REQUIS
code, icon_url, attribute, capability, schema : optionnels
```

- `model` = `"roborock.vacuum.a135"` ; `name` = libellé commercial → `productName`.
- `RoborockCategory` (`roborock/data/code_mappings.py` l. 240-252) est un `Enum` **classique** (pas
  `StrEnum`) : `VACUUM = "robot.vacuum.cleaner"`, `WET_DRY_VAC`, `WASHING_MACHINE`, `MOWER`, `UNKNOWN`,
  avec `_missing_` → `UNKNOWN`. ⇒ comparer sur **`.value`**, et **sans importer** `RoborockCategory`
  (règle D-h d'UC03 : aucun symbole de la lib importé pour du mapping).

### 5. Définition officielle de « V1 » — `roborock/devices/device_manager.py` l. 48-53 et 235-281

```python
class DeviceVersion(enum.StrEnum):
    V1 = "1.0" ; A01 = "A01" ; B01 = "B01" ; UNKNOWN = "unknown"
...
match device.pv:
    case DeviceVersion.V1:
        if product.category != RoborockCategory.VACUUM:
            raise UnsupportedDeviceError("... unsupported V1 category ...")
    ...
    case _: raise UnsupportedDeviceError("... unsupported version ...")
```

⇒ **La librairie elle-même** refuse un `pv == "1.0"` dont la catégorie n'est pas `VACUUM`. Le plugin
aligne exactement son critère de compatibilité sur celui-là.

⚠️ **Écart signalé** avec `.memory/analyse/jeeroborock-modele-equipement.md` § 1, qui ne mentionne que le
critère `pv`. Ce n'est pas une contradiction de fond (l'analyse est antérieure à la lecture de
`device_manager.py`) mais l'analyse **est à compléter** — cf. Dette.

### 6. Sérialisation `RoborockBase` — `containers.py` l. 82-175

`from_dict()` décamélise les clés et **ignore silencieusement** les clés inconnues ; un champ **requis**
absent lève `TypeError`. ⇒ un produit renvoyé sans `category` ferait échouer **tout**
`HomeData.from_dict` → `INTERNAL_ERROR`. Risque préexistant depuis UC05 (même appel), noté R-8.

### Récapitulatif « où lit-on quoi »

| Donnée | Source exacte |
|---|---|
| `duid` | `HomeDataDevice.duid` |
| modèle technique | `HomeDataProduct.model` via `home.product_map[device.product_id]` |
| nom produit | `HomeDataProduct.name` |
| `fv` / `pv` / `sn` | `HomeDataDevice` |
| **partagé** | `device.duid in {d.duid for d in home.received_devices}` — **pas** `device.share` |
| `nbTotal` | `len(home.get_all_devices())` — **même définition qu'UC05** |

---

## Signatures

### `resources/demond/session.py` *(créé)*

```python
# Module de session partage du demon (UC06) : import garde de la librairie roborock et
# serialisation du UserData. Extrait d'authentification.py, corps REPRIS A L'IDENTIQUE.

IMPORT_OK: bool                  # False si l'import de roborock echoue -> INTERNAL_ERROR cote appelants
UserData                         # reexporte (None si import KO)
RoborockApiClient                # reexporte (None si import KO)

def creer_client(email, base_url=None) -> RoborockApiClient
    # RoborockApiClient(email, base_url=(base_url or None))  <- JAMAIS une chaine vide (R-8 d'UC05)
    # corps identique a l'ancien _client_compte d'authentification.py

def encoder_user_data(user_data) -> str
    # base64(JSON compact) du UserData COMPLET (aucun exclude) : D-04-4
    # corps identique a l'ancien _encoder_user_data

def decoder_user_data(valeur) -> UserData
    # leve ErreurDemon('AUTH_EXPIRED') sur tout echec, y compris session structurellement
    # incomplete (controle explicite token / rriot / rriot.r)
    # corps identique a l'ancien _decoder_user_data
```

**Règle de réalisation** : les quatre corps sont **copiés sans reformulation** depuis
`authentification.py` (l. 84-105 et 178-182). Seuls changent le nom (perte du `_` initial) et
l'emplacement. Tout écart doit être justifié explicitement — l'intérêt de l'arbitrage est un diff
relisible comme un déplacement.

### `resources/demond/authentification.py` *(modifié — déplacement pur)*

- Imports `base64` / `json` retirés **s'ils ne servent plus** ; `from erreurs import …` conservé.
- Le bloc `try: from roborock… except ImportError` est **supprimé** et remplacé par
  `from session import IMPORT_OK, RoborockApiClient, creer_client, decoder_user_data, encoder_user_data`.
⚠️ **Ne pas y remettre `UserData`** : le symbole n'est utilisé nulle part dans
`authentification.py` (seul `decoder_user_data`, désormais dans `session.py`, s'en sert). La première
version de cette spec l'incluait à tort — import mort relevé en review.
- `_IMPORT_OK` → `IMPORT_OK` : **4 occurrences** (`demander_code`, `valider_code`, `restaurer_session`,
  `etat_compte`).
- `_encoder_user_data` → `encoder_user_data` : **3 occurrences** (dans les dicts `contexte["session"]`).
- `_decoder_user_data` → `decoder_user_data` : **2 occurrences**.
- `_client_compte(email, base_url)` → `creer_client(email, base_url)` : **1 occurrence** (dans
  `etat_compte`).
- `_client_pour()` reste **strictement inchangé**, y compris son `RoborockApiClient(email)`.
- Les **trois** dicts `contexte["session"] = {...}` restent **inline et inchangés** (arbitrage D-06-2).
- Le docblock d'en-tête est mis à jour pour indiquer que la sérialisation vit désormais dans `session.py`.

### `resources/demond/equipements.py` *(créé)*

```python
PV_V1 = "1.0"
CATEGORIE_VACUUM = "robot.vacuum.cleaner"
LONGUEUR_MAX_TEXTE = 128
LIMITE_APPAREILS = 64            # borne dure sur la taille des deux listes renvoyees

async def decouvrir(parametres, contexte) -> dict
#   parametres : userData (str base64, requis), baseUrl (str, peut etre vide), email (str)
#   retour     : {"nbTotal": int,
#                 "robots": [{"duid","nomRoborock","model","productName","fv","pv","sn","shared"}],
#                 "nonSupportes": [{"nomRoborock","model","pv","motif"}]}
#   leve       : ErreurDemon('INTERNAL_ERROR')    -> IMPORT_OK faux
#                ErreurDemon('NOT_AUTHENTICATED') -> userData vide (defense en profondeur)
#                ErreurDemon('AUTH_EXPIRED')      -> blob illisible/incomplet
#   les exceptions python-roborock remontent TELLES QUELLES a handler_rpc (point de mapping unique) :
#   RoborockRateLimit -> RATE_LIMIT, RoborockInvalidCredentials -> AUTH_EXPIRED, etc.

def _categorie(produit) -> str
#   str(getattr(produit.category, "value", produit.category) or "")
#   tolere un enum, une chaine ou None, SANS importer RoborockCategory

def _texte(valeur, longueur_max=LONGUEUR_MAX_TEXTE) -> str   # "" si None ; str() ; troncature
def _decrire_robot(device, produit, partage) -> dict
def _decrire_non_supporte(device, produit, motif) -> dict
def enregistrer_operations() -> None     # canal.enregistrer("decouvrirEquipements", decouvrir)
```

**Déroulé de `decouvrir`, ordre imposé** :

1. `IMPORT_OK` faux → `INTERNAL_ERROR`.
2. `userData` vide → `NOT_AUTHENTICATED`.
3. `user_data = session.decoder_user_data(...)`.
4. `client = session.creer_client(email, baseUrl)`.
5. `home = await client.get_home_data_v3(user_data)` — **aucun `try/except` autour** (D-06-4).
6. `duids_partages = {d.duid for d in (home.received_devices or [])}` ; `produits = home.product_map`.
7. Boucle sur `home.get_all_devices()`, **dédoublonnée par `duid`** (un `duid` déjà vu est ignoré),
   plafonnée à `LIMITE_APPAREILS` par liste (dépassement → `logging.warning` comptant les omis) :
   - produit absent de `product_map` → `nonSupportes`, `motif="PRODUIT_INCONNU"` ;
   - `device.pv != PV_V1` → `nonSupportes`, `motif="PROTOCOLE"` ;
   - `_categorie(produit) != CATEGORIE_VACUUM` → `nonSupportes`, `motif="CATEGORIE"` ;
   - sinon → `robots`, avec `shared = duid in duids_partages`.
8. `contexte["session"] = {"userData": session.encoder_user_data(user_data), "baseUrl": str(baseUrl),
   "email": email}` — **dict inline**, forme **strictement identique** aux trois occurrences
   d'`authentification.py` (D-06-2). Idempotent, aligné sur `etat_compte`.
9. Retour `{"nbTotal": len(home.get_all_devices()), "robots": …, "nonSupportes": …}`.

⚠️ **`home` n'est JAMAIS journalisé ni sérialisé** (il porte les `local_key`). Ne pas appeler
`device.summary_info()` dans un log : c'est une chaîne construite par la lib — composer le log à partir de
nos propres champs.

⚠️ **Boucle asyncio unique (R1 d'UC03) : aucun `asyncio.to_thread` nécessaire.** Les deux seuls appels lib
sont `async`, `try_acquire(..., blocking=False)` est une opération mémoire en temps constant (**ne jamais
le passer à `True`**), et le post-traitement est une boucle bornée à `LIMITE_APPAREILS` sur des objets
déjà en mémoire.

### `resources/demond/jeeroborockd.py` *(modifié — 2 lignes)*

`import equipements` ; `equipements.enregistrer_operations()` **explicitement** dans `principal_async`,
juste après `authentification.enregistrer_operations()` — jamais par effet de bord d'import. `contexte`
**inchangé** : aucune clé nouvelle.

### `core/class/jeeroborockDaemon.class.php` *(modifié — 1 ligne)*

```php
const TIMEOUT_DECOUVERTE = 20;   // s, decouvrirEquipements (2 requetes HTTPS nominales)
```

Cascade **automatique**, aucune constante par opération dans `canal.py` : `appeler()` envoie
`budgetMs = 20000`, `handler_rpc` calcule `wait_for((20000 − MARGE_MS)/1000) = 19 s` ⇒ **démon 19 s < PHP
20 s < jQuery 30 s** : c'est le démon qui coupe le premier, l'utilisateur voit `OPERATION_TIMEOUT` et non
un silence. Reste sous `TIMEOUT_MAX = 60` et sous les `max_execution_time` / `fastcgi_read_timeout`
usuels (R12 d'UC03). Même schéma de nommage que `TIMEOUT_COMPTE = 20` posé en UC05.

### `core/class/jeeroborock.class.php` *(modifié)*

```php
const LONGUEUR_MAX_TEXTE_INVENTAIRE = 128;
const NB_MAX_ROBOTS_SYNCHRO         = 64;   // garde-fou symetrique de LIMITE_APPAREILS

public  static function synchroniserEquipements()
//   -> array('crees'=>int,'misAJour'=>int,'echecs'=>int,'nbTotal'=>int,
//            'nonSupportes'=>array<string>, 'partages'=>array<string>)
//   throws jeeroborockException (canal/cloud) — laissee remonter telle quelle a l'AJAX
private static function appliquerRobot($_robot)   // -> 'cree' | 'misAJour' ; throws Exception
private static function duidValide($_duid)        // -> bool
private static function texteInventaire($_valeur, $_longueurMax = self::LONGUEUR_MAX_TEXTE_INVENTAIRE)
//   -> string : (string) + nettoyerPourLog() + trim + substr
```

**`synchroniserEquipements()`**

1. `$r = jeeroborockDaemon::appeler('decouvrirEquipements', array('userData' => self::getUserData(),
   'baseUrl' => self::getBaseUrlCompte(), 'email' => self::getEmailCompte()),
   jeeroborockDaemon::TIMEOUT_DECOUVERTE);`
2. Lecture **défensive** : `$robots = (isset($r['robots']) && is_array($r['robots']))
   ? array_slice($r['robots'], 0, self::NB_MAX_ROBOTS_SYNCHRO) : array();` idem `nonSupportes`.
3. Boucle sur `$robots` avec **`try/catch (Throwable)` PAR ROBOT** (règle `CLAUDE.md` : un équipement en
   erreur n'interrompt pas la boucle) → incrémente `crees` / `misAJour` / `echecs`. Le `catch` journalise
   `substr($e->getMessage(), 0, 256)` — **troncature obligatoire** : `eqLogic::save()` lève avec un
   `print_r($this, true)` complet (`eqLogic.class.php` l. 995-997), qui saturerait le log.
4. `self::enregistrerInventaireCompte(intval($r['nbTotal']))` si `nbTotal` est un entier `>= 0` —
   **exigence R-6 d'UC05** — dans son propre `try/catch` (la méthode ne lève déjà jamais).
5. Un `log::add('jeeroborock', 'info', …)` récapitulatif mentionnant explicitement « inventaire homedata,
   quota 40/jour » (traçabilité de la consommation de quota, même esprit qu'UC05).
6. Retour du tableau de compteurs + listes **déjà neutralisées** par `texteInventaire()`.

**`appliquerRobot($_robot)`**

1. `$duid = trim((string) $_robot['duid']);`
   `if (!self::duidValide($duid)) { log::add('jeeroborock', 'warning', 'Robot ignoré : duid invalide : ' . self::nettoyerPourLog(substr($duid, 0, 128))); throw new Exception('duid invalide'); }`
   ⚠️ **La valeur journalisée passe obligatoirement par `nettoyerPourLog()`** : c'est le **seul** chemin
   où une chaîne d'origine cloud non encore neutralisée entre dans un `log::add()` (le reste du flux passe
   par `texteInventaire()`). Ne pas journaliser `$duid` brut.
2. `$eqLogic = eqLogic::byLogicalId($duid, 'jeeroborock');`
3. **Branche création** (`!is_object($eqLogic)`) — et **uniquement** ici :
   `new jeeroborock()` ; `setEqType_name('jeeroborock')` ; `setLogicalId($duid)` ;
   `setName(<nomRoborock neutralisé>)` ; **puis**
   `if ($eqLogic->getName() == '') { $eqLogic->setName(sprintf(__('Robot Roborock %s', __FILE__), substr($duid, 0, 8))); }` ;
   `setIsEnable(1)` ; `setIsVisible(1)`.
   ⚠️ Le repli de nom **n'est pas cosmétique** : `setName()` applique `cleanComponanteName()`
   (`utils.inc.php` l. 1796-1800), qui supprime `& # ] [ % \ / ' " *` et les balises — un robot nommé
   `"***"` dans l'application donnerait un nom **vide**, et `save()` lèverait en dumpant l'objet entier.
   On relit `getName()` **après** `setName()` plutôt que de réimplémenter le nettoyage du core.
4. **Toujours** (création et mise à jour) : `setConfiguration()` sur la **liste blanche fermée**
   `duid`, `model`, `productName`, `fv`, `pv`, `sn`, `shared` (0/1), `nomRoborock` — chaque valeur passée
   par `texteInventaire()`. **Aucune autre clé n'est écrite**, aucune boucle générique sur `$_robot`.
5. `$eqLogic->save();` puis retour `'cree'` / `'misAJour'`.
   *Note* : `eqLogic::save()` ne réécrit en base que si `getChanged()` — une 2ᵉ synchronisation sans
   changement ne produit donc aucune écriture. C'est aussi pour cela qu'**aucune clé d'horodatage de
   synchronisation n'est stockée** : elle forcerait une écriture à chaque passe pour zéro bénéfice.

**`duidValide()`** : `preg_match('/\A[A-Za-z0-9_.:-]{4,128}\z/', $_duid)` — ancres `\A`/`\z`, **jamais**
`^`/`$` (rappel UC03 : `$` accepte un `\n` final → forge de ligne de log). Filtre **défensif** : garantir
qu'une valeur d'origine cloud entrant dans un `logicalId` et dans un `log::add` est inoffensive. Un refus
est journalisé en `warning` pour que la recette puisse élargir la classe si un `duid` réel était rejeté.

---

## Server Actions / API

### `core/ajax/jeeroborock.ajax.php` *(modifié — 1 `case`)*

Séquence inchangée : `isConnect('admin')` → `ajax::init()` → `session_write_close()` (déjà en place,
**indispensable** : l'appel peut durer 20 s) → `switch`. Endpoint **admin** assumé : l'action consomme un
quota imputé au compte de l'utilisateur **et** crée des équipements.

```
case 'synchroniserEquipements':
  1. $email = jeeroborock::getEmailCompte();  vide -> ajax::error(<litterale EXISTANTE>) ; break
  2. !jeeroborock::estCompteLie()             -> ajax::error(<litterale EXISTANTE>) ; break
     // les deux garde-fous n'appellent PAS le demon : zero quota consomme
  3. $r = jeeroborock::synchroniserEquipements();   // jeeroborockException -> catch global du fichier
  4. composition du message (1 a 4 phrases jointes par un espace) :
       - si crees+misAJour > 0 : "Synchronisation terminee : %1$s equipement(s) cree(s), %2$s mis a jour."
       - sinon si nonSupportes vide : "Aucun robot compatible n'a ete trouve sur ce compte Roborock."
       - si nonSupportes : "Modele non supporte par cette version du plugin : %s"  (5 premiers + suite)
       - si partages     : "Robot(s) partage(s) par un autre compte : %s"          (5 premiers + suite)
       - si echecs > 0   : "%s robot(s) n'ont pas pu etre enregistres dans Jeedom. Consultez le log du plugin."
  5. ajax::success(array('message'=>$message,'crees'=>int,'misAJour'=>int,
                         'nonSupportes'=>count(...),'echecs'=>int));
```

⚠️ **Réponse reconstruite champ par champ** — 5 scalaires. `$r` ne repart **jamais** brut ; le `userData`
n'entre à aucun moment dans cette réponse. Invariant UC03/UC04/UC05 préservé.

⚠️ **Aucun `message::add()`** avec le rapport : il est rendu **en HTML** par le core et le rapport contient
des noms de robots d'origine utilisateur. Le compte rendu vit dans la zone de résultat, insérée par
`.text()`.

### `plugin_info/configuration.txt` *(modifié, puis copie vers le `.php`)*

Nouveau `fieldset` « Équipements » inséré **entre** « Compte Roborock » et « Canal local avec le démon » :
label « Synchronisation » + infobulle sur le quota, `<a class="btn btn-default"
id="bt_jeeroborockSynchroniser">`, un saut de ligne, `<span id="jeeroborockResultatSynchro"></span>`.

Gestionnaire JS inline, **squelette identique à `testerConnexion`** : verrou **propre**
`jeeroborockVerrouSynchro` (booléen **ET** `addClass('disabled')` — un `<a class="btn">` ignore
`prop('disabled')`), `timeout: 30000`, `error` → littérale **déjà présente** « Le démon ne répond pas. »
(à réutiliser, **ne pas la redéclarer**), `success` →
`if (donnees.state != 'ok') { zone.text(donnees.result); } else { zone.text(donnees.result.message); }`.

⚠️ Nouvelles littérales JS en **guillemets doubles**, comme les 5 déjà présentes (`verif-plugin.py`
l. 465-474 contrôle les chaînes JS en apostrophes simples).

⚠️ Fichier **rendu** : aucune méta-séquence i18n littérale, y compris dans le JS et les commentaires.
Procédure : `python .claude/scripts/verif-plugin.py` (colonne `meta=`) **avant commit**, puis
`cp plugin_info/configuration.txt plugin_info/configuration.php`, contrôle par
`git status --short plugin_info/configuration.php`. **Ne jamais relire le `.php`.**

### `desktop/php/jeeroborock.php` *(modifié — ⚠️ TABULATIONS + CRLF)*

1. Tuile `data-action="add"` **supprimée** (D-06-7) ; tuile « Configuration » conservée.
2. Légende « Mes jeeroborocks » → « Mes robots Roborock » ; message vide du squelette → « Aucun robot
   Roborock : ouvrez la configuration du plugin, puis lancez la synchronisation des équipements. »
3. Dans la boucle `foreach ($eqLogics as $eqLogic)` (**rendu serveur**), après `<span class="name">` :
   `if ($eqLogic->getConfiguration('shared', 0) == 1) { echo '<span class="label label-info" title="…">…</span>'; }`
4. Bloc « Paramètres spécifiques » (3 champs squelette : `param1`, `password`, `autorefresh`)
   **remplacé** par une légende « Inventaire Roborock » et **6 champs distincts, une clé par champ** :

   | Libellé | `data-l2key` |
   |---|---|
   | Modèle | `model` |
   | Micrologiciel | `fv` |
   | Version de protocole | `pv` |
   | Numéro de série | `sn` |
   | Identifiant Roborock | `duid` |
   | Nom dans l'application Roborock | `nomRoborock` |

   Chacun sous la forme
   `<input type="text" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="<clé>" readonly>`.
   ⚠️ **Une clé unique par input** — `data-l2key` n'accepte **pas** de clé composite.

   ⚠️ **`readonly`, jamais `disabled`** : un champ `disabled` n'est pas soumis, `readonly` l'est et reste
   lisible par `setValues`/`getValues` du core. **Innocuité vérifiée** : `utils::a2o`
   (`utils.class.php` l. 84-113) appelle `setConfiguration($clé, $valeur)` pour les **seules clés
   présentes** dans le POST — donc **fusion** et non remplacement ; une clé absente du formulaire
   (`productName`, `shared`) n'est **pas** effacée par une sauvegarde manuelle.
5. Une ligne d'information : « Ces informations proviennent du compte Roborock et sont rafraîchies par la
   synchronisation des équipements. »

⚠️ Fichier **rendu** : pas de double accolade ouvrante littérale hors clé i18n, commentaires compris.

---

## Validation

| Quoi | Où (autoritaire) | Comportement / message |
|---|---|---|
| e-mail du compte | **serveur**, `getEmailCompte()` | vide → littérale existante, **sans appeler le démon** (zéro quota) |
| compte lié | **serveur**, `estCompteLie()` (D-04-8) | faux → littérale existante, **sans appeler le démon** |
| `userData` vide | démon (défense en profondeur) | `NOT_AUTHENTICATED` |
| `userData` illisible / incomplet | démon, `session.decoder_user_data` (`token`/`rriot`/`rriot.r`) | `AUTH_EXPIRED` |
| session refusée par le cloud (`code 2010`) | cloud Roborock | `RoborockInvalidCredentials` → `AUTH_EXPIRED` |
| quota inventaire **local** | démon, limiteur de la lib, **avant tout réseau** | `RoborockRateLimit` → `RATE_LIMIT`, **non rattrapé** (D-06-4) |
| quota **serveur** | cloud | `RoborockTooManyRequest` → `RATE_LIMIT_REMOTE` |
| structure de la réponse démon | PHP, `is_array` + `array_slice` à 64 | non conforme → listes vides, aucun plantage |
| `duid` | démon (présence) **puis** PHP (`duidValide`, regex ancrée `\A…\z`) | refus → robot compté en `echecs`, `log::add(warning)` **avec `nettoyerPourLog()`** |
| toute chaîne d'origine cloud (`nom`, `model`, `fv`, `pv`, `sn`) | PHP, `texteInventaire()` | `nettoyerPourLog()` + `trim` + troncature 128 **avant** log, DOM et base |
| nom vide après `cleanComponanteName` | PHP, relecture de `getName()` après `setName()` | repli « Robot Roborock &lt;8 premiers caractères du duid&gt; » |
| échec d'enregistrement d'un robot | PHP, `try/catch (Throwable)` **par robot** | compteur `echecs`, log tronqué à 256 caractères, **la boucle continue** |
| budget de temps | démon 19 s < PHP 20 s < jQuery 30 s | `OPERATION_TIMEOUT` puis `DAEMON_TIMEOUT` en dernier recours |
| droits | `isConnect('admin')` du fichier AJAX | endpoint **admin** |

**Typage** : `jeeroborockException` sur tout le chemin du canal (l'AJAX affiche `getMessage()` via le
`catch` global existant) ; `ErreurDemon` côté Python ; les exceptions `python-roborock` remontent **non
rattrapées** jusqu'à `handler_rpc`, **point de mapping unique**.

### Codes d'erreur stables

**AUCUN nouveau.** Réutilisés tels quels : `NOT_AUTHENTICATED`, `AUTH_EXPIRED`, `RATE_LIMIT`,
`RATE_LIMIT_REMOTE`, `CLOUD_UNREACHABLE`, `CLOUD_REGION_UNKNOWN`, `PARSING_ERROR`, `ROBOROCK_ERROR`,
`OPERATION_TIMEOUT`, `INTERNAL_ERROR`, plus les familles A/B du canal.

⇒ `jeeroborockDaemon::tableMessages()` **et** `jeeroborockException::estErreurCanal()` ne sont **ni l'un
ni l'autre** modifiés : **le piège de la liste dupliquée héritée d'UC03 ne s'applique pas à cette UC.**

**« Aucun robot » n'est pas une erreur** : c'est un succès avec un message dédié. Aucun code stable créé.

### Secrets

`local_key` et `HomeData` ne quittent jamais le démon ; le dict de sortie est construit **champ par
champ** ; `appeler()` ne journalise que les **noms** de clés ; la réponse AJAX est reconstruite champ par
champ ; aucun `displayException()` ; les `catch` ne journalisent que `getMessage()` **tronqué**, jamais
`getTraceAsString()`.

---

## Dépendances

**Aucune.** `python-roborock 7.8.0` est déjà épinglé dans `plugin_info/packages.json` ; `aiohttp` est déjà
une dépendance transitive. `packages.json` et `info.json` ne sont **pas** touchés (`pluginVersion` est
bumpé par le hook `pre-commit`).

---

## Impact i18n (français uniquement dans cette UC)

`core/i18n/*.json` **non touchés** — traduction par le sous-agent `translator` en fin de cycle, sur le code
figé. **23 littérales françaises nouvelles**, toutes **littérales** (jamais `__($variable)` ; `sprintf`
**autour** de `__()`).

**`core/ajax/jeeroborock.ajax.php` — 5**
1. « Synchronisation terminée : %1$s équipement(s) créé(s), %2$s mis à jour. »
2. « Aucun robot compatible n'a été trouvé sur ce compte Roborock. »
3. « Modèle non supporté par cette version du plugin : %s »
4. « Robot(s) partagé(s) par un autre compte : %s »
5. « %s robot(s) n'ont pas pu être enregistrés dans Jeedom. Consultez le log du plugin. »

**`core/class/jeeroborock.class.php` — 1**
6. « Robot Roborock %s » *(nom de repli quand `cleanComponanteName` vide le nom)*

**`plugin_info/configuration.txt` → `.php` — 5**
7. « Équipements » *(legend)* · 8. « Synchronisation » *(label)* · 9. « Synchroniser les équipements »
*(bouton)* · 10. « Synchronisation en cours… » *(JS, guillemets doubles)* · 11. infobulle : « La
synchronisation interroge l'inventaire du compte Roborock, dont le quota est strictement limité et partagé
avec l'application mobile : ne la lancez que lorsque vous ajoutez ou retirez un robot. »

**`desktop/php/jeeroborock.php` — 12** *(nouvelle entrée i18n : ce fichier n'existe dans aucun
`core/i18n/*.json` aujourd'hui)*
12. « Mes robots Roborock » · 13. « Aucun robot Roborock : ouvrez la configuration du plugin, puis lancez
la synchronisation des équipements. » · 14. « Robot partagé » · 15. « Ce robot est partagé par un autre
compte Roborock : certaines actions peuvent être refusées. » · 16. « Inventaire Roborock » · 17. « Modèle »
· 18. « Micrologiciel » · 19. « Version de protocole » · 20. « Numéro de série » · 21. « Identifiant
Roborock » · 22. « Nom dans l'application Roborock » · 23. « Ces informations proviennent du compte
Roborock et sont rafraîchies par la synchronisation des équipements. »

⚠️ **À signaler au `translator`** :
- Les deux garde-fous du `case` réutilisent des littérales **déjà présentes** dans
  `core/ajax/jeeroborock.ajax.php` (« Aucune adresse e-mail n'est renseignée… » et « Le compte Roborock
  n'est pas lié… ») → **même clé, rien à ajouter**.
- « Le démon ne répond pas. » existe déjà dans `configuration.txt` → **ne pas la redéclarer**.
- ⚠️ **Clés devenues orphelines** : `core/i18n/en_US.json` (et les autres langues cibles) portent des
  entrées pour les chaînes du squelette de `desktop/php/jeeroborock.php` supprimées par D-06-8
  (« Nom du paramètre n°1 », « Mot de passe », « Auto-actualisation »…). Sans conséquence fonctionnelle ;
  le `translator` les **nettoie** au passage.

Messages `log::add` et logs du démon : français **non enveloppé**. Codes stables et `motif` :
**anglais / jetons stables**.

---

## Risques & pièges

- **R-1 (majeur) — `pv == "1.0"` est une hypothèse à confirmer sur le matériel réel.** La valeur vient de
  `DeviceVersion.V1` (`device_manager.py`), mais le Qrevo Curv n'a pas été interrogé. Si le compte réel
  renvoie `"1.00"` ou un autre littéral, **le seul robot testable serait classé « non supporté »** — panne
  totale d'UC06, avec un message trompeur. **Recette R-1 obligatoire** : lever la valeur réelle dans le log
  du démon avant toute conclusion.
- **R-2 (majeur) — l'indicateur « partagé » n'est vérifiable que sur un compte possédant un robot
  partagé.** `received_devices` est le critère retenu, corroboré par l'analyse interne et par l'absence
  totale d'usage de `HomeDataDevice.share` dans la lib, mais **non testable** sur le compte de référence.
  AC5 restera « à confirmer en recette ». Ne **pas** basculer sur `device.share` sans preuve : sa
  sémantique (booléen ? horodatage ? identifiant ?) est inconnue.
- **R-3 (majeur) — l'extraction de `session.py` est un risque de non-régression sur UC04 et UC05.** Même
  réduite au périmètre strict de la dette (D-06-2), elle touche un fichier qui porte l'authentification
  **et** le test de connexion, déjà livrés. Les points sensibles sont **nommément** : le renommage
  `_IMPORT_OK` → `IMPORT_OK` (**4** occurrences, `NameError` au premier appel et non à l'import),
  `_encoder_user_data` (**3**), `_decoder_user_data` (**2**), `_client_compte` → `creer_client` (**1**), et
  `_client_pour()` qui **doit rester inchangé** et donc continuer à disposer du symbole
  `RoborockApiClient`. Recette R-8/R-9 dédiées : **rejouer le flux complet UC04 puis UC05 avant** de tester
  UC06.
- **R-4 — quota `homedata` : 5/heure, partagé avec l'application mobile.** Aucun cache PHP ne protège la
  découverte (D-06-3), et « Tester la connexion » peut en avoir consommé un juste avant. 5
  synchronisations dans l'heure → `RATE_LIMIT`. Protection : verrou de bouton + message explicite +
  infobulle. **Aucune retentative.** Parade si la recette la réclame : un cooldown de cache court côté PHP
  (30-60 s) — **jamais** un retry.
- **R-5 — `eqLogic::save()` lève avec `print_r($this, true)`** (`eqLogic.class.php` l. 995-997) si le nom
  est vide. Neutralisé par le repli de nom **et** par la troncature à 256 caractères du message
  journalisé. Sans ces deux mesures, un robot mal nommé produit un log illisible et une erreur AJAX
  géante.
- **R-6 — `cleanComponanteName()` mutile le nom** (`& # ] [ % \ / ' " *` supprimés, balises strippées,
  troncature à 127). Un nom Roborock avec une apostrophe devient un nom sans apostrophe à la création.
  Conséquence **assumée** : le nom est modifiable par l'utilisateur, et AC3 garantit qu'il ne sera plus
  jamais touché.
- **R-7 — deux robots homonymes dans l'application** donnent deux équipements Jeedom de même nom (Jeedom
  ne l'interdit pas). Les `logicalId` diffèrent, donc aucune conséquence fonctionnelle.
- **R-8 — `HomeData.from_dict` lève `TypeError` si un champ requis manque** (`HomeDataProduct.category`,
  `HomeDataDevice.local_key`…) : **toute** la découverte échoue en `INTERNAL_ERROR`, pas seulement
  l'appareil fautif. Risque **préexistant depuis UC05** (même appel), non corrigeable sans réimplémenter la
  désérialisation. À lever en recette si le compte contient un accessoire exotique.
- **R-9 — « Dupliquer » reste disponible sur la page équipement** : un utilisateur peut créer un second
  eqLogic portant le même `logicalId`. `byLogicalId` en rendrait le premier ; la synchronisation mettrait à
  jour celui-là et l'autre deviendrait un fantôme. Non traité (retirer le bouton dépasse le périmètre
  d'AC2, qui ne porte que sur la resynchronisation).
- **R-10 — contrainte sur l'avenir (UC07).** UC06 **ne conserve pas** le `HomeData` dans le contexte du
  démon : UC07, qui a besoin du `local_key` pour ouvrir le canal MQTT, devra soit rappeler `homedata`
  (1 appel de plus sur 40/jour, à chaque redémarrage du démon), soit décider d'un cache côté démon (la lib
  fournit `roborock/devices/cache.py` et `file_cache.py`). **Arbitrage explicitement laissé à UC07** —
  suite directe de R-13 d'UC05.
- **R-11 — `sys.path` du démon.** `resources/demond/` est `sys.path[0]` : `session.py` masquerait tout
  module de premier niveau homonyme. Vérifié absent de l'arbre de dépendances actuel ; un échec se verrait
  immédiatement au démarrage.
- **R-12 — indentation de `desktop/php/jeeroborock.php`** : **tabulations + CRLF**, seule exception du
  dépôt. Un bloc inséré en 2 espaces produit un fichier mixte (mémoire d'agent
  `feedback-edit-tool-tab-indented-files`).
- **R-13 — désynchronisation `configuration.txt`/`.php` et méta-séquences** : `verif-plugin.py` (colonne
  `meta=`) puis `cp`, contrôle par `git status --short`. **Deux** fichiers rendus sont touchés cette fois
  (`configuration.txt` **et** `desktop/php/jeeroborock.php`).

---

## Recette (à confirmer sur une Jeedom réelle, Debian 12+)

| # | AC | Vérification | Attendu |
|---|---|---|---|
| R-1 | AC1 / R-1 | compte lié, 1ᵉʳ clic « Synchroniser les équipements » | 1 équipement créé, nom = nom de l'application ; `model`, `fv`, `pv`, `sn`, `duid` visibles dans « Inventaire Roborock » ; log du démon confirmant `pv="1.0"` |
| R-2 | AC2 | relancer la synchronisation sans rien changer | « 0 équipement(s) créé(s), 1 mis à jour » ; **même nombre d'équipements** avant/après |
| R-3 | AC3 | renommer l'équipement dans Jeedom, resynchroniser | nom Jeedom **inchangé** ; `nomRoborock` toujours à jour |
| R-4 | AC4 | renommer le robot dans l'application Roborock, resynchroniser | **aucun** nouvel équipement ; `nomRoborock` suit le nouveau nom ; nom Jeedom inchangé |
| R-5 | AC5 | compte disposant d'un robot partagé | équipement créé ; libellé « Robot partagé » sur la vignette ; phrase « Robot(s) partagé(s)… » dans le compte rendu |
| R-6 | AC6 | compte contenant un appareil non V1 (Dyad / Zeo / Q7) | **aucun** équipement pour cet appareil ; « Modèle non supporté par cette version du plugin : … » ; log du démon avec le `motif` |
| R-7 | AC7 | `SELECT configuration FROM eqLogic WHERE eqType_name='jeeroborock'` + log en `debug` | 8 clés exactement, **aucun** `local_key`/jeton ; rien dans `log/jeeroborock`, `log/jeeroborock_demon`, `log/php` ; onglet réseau du navigateur sans secret |
| R-8 | R-3 | **non-régression UC04** : flux complet « Envoyer un code » → « Valider le code » | identique à UC04 ; ligne de session restaurée au démarrage du démon |
| R-9 | R-3 | **non-régression UC05** : « Tester la connexion » | identique à UC05 ; après une synchronisation, le test affiche le **nouveau** nombre et un horodatage frais (preuve du rafraîchissement du cache, R-6 d'UC05) |
| R-10 | AC8 | laisser tourner le cron 15 min, niveau de log `debug` | **aucune** ligne de synchronisation, **aucun** appel `decouvrirEquipements` |
| R-11 | R-4 | 6 synchronisations en moins d'une heure | message de quota explicite, **aucun** paquet réseau supplémentaire, aucune retentative |
| R-12 | canal | démon arrêté, clic « Synchroniser » | « Le démon ne répond pas… » en moins de 5 s, interface Jeedom fluide (preuve du `session_write_close()`) |

---

## Dette

- **Duplication du dict `contexte["session"]`** — il est désormais construit **inline en 4 endroits**
  (`valider_code`, `restaurer_session`, `etat_compte` dans `authentification.py`, et `decouvrir` dans
  `equipements.py`), avec la forme `{"userData": …, "baseUrl": str(…), "email": …}`. Conséquence assumée de
  l'arbitrage D-06-2 (limiter le refactor au périmètre strict de la dette d'UC05, pour ne pas faire porter
  un risque de régression UC04/UC05 à ce cycle). **À factoriser dans `session.memoriser_session()`** lors
  d'un cycle qui touche déjà `authentification.py` pour d'autres raisons — typiquement UC11
  (ré-authentification). Une 5ᵉ occurrence doit déclencher la factorisation.
- **`.memory/analyse/jeeroborock-modele-equipement.md` § 1 est incomplet** : il ne retient que le critère
  `pv` pour la compatibilité, alors que `device_manager.py` impose aussi `category == VACUUM`. À compléter
  en capitalisation mémoire, sinon UC07 rouvrira le sujet.
- **R-9 (bouton « Dupliquer »)** : non traité, hors périmètre d'AC2.
- **R-8 (`TypeError` global sur `from_dict`)** : préexistant depuis UC05, non traité.
