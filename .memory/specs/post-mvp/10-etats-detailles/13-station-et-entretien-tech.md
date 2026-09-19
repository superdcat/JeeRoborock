# Spec technique — UC13 : État de la station d'accueil

> **Spec fonctionnelle** : `13-station-et-entretien.md` · **Dépend de** : UC03 (canal), UC07 (état du
> robot, `capacites_etat`/`valeurs_etat`, patron des libellés), UC10 (superviseur), UC12 (précédent le
> plus proche — création conditionnelle de commandes info)
> **Contrats externes vérifiés** le 2026-09-19 sur le wheel `python_roborock-7.8.0` extrait, lu verbatim
> **et exécuté** (imports, matrice de capacités, repli des codes inconnus vérifiés empiriquement), et sur
> le code réel du dépôt. Plan relu et challengé le même jour : toutes les affirmations à numéro de ligne
> sur le code existant ont été vérifiées par `grep` et tiennent. Les corrections issues de la revue sont
> intégrées ci-dessous et repérées par **« corrigé après revue »**.

## Résumé

Tous les champs de station vivent dans **`StatusV2`**, donc dans le trait `status` **déjà rafraîchi** par
les trois chemins de publication existants (`lireEtat` d'UC07, relecture post-action d'UC08, sonde
d'UC10). UC13 étend les **deux seules fonctions partagées par ces trois chemins** —
`robots.capacites_etat()` et `robots.valeurs_etat()` — plus une table de libellés dans `libelles.py`.

**Aucune opération RPC nouvelle, aucun appel réseau supplémentaire, aucun quota consommé, aucune nouvelle
tâche asyncio, aucune nouvelle classe PHP, aucune nouvelle méthode PHP.** Côté PHP : 6 lignes de table,
5 lignes de correspondance, 6 blocs de liste blanche.

## Couverture des critères

| AC | Couvert par |
|---|---|
| **AC1** — état du vidage, séparé de l'état robot | Capacité `stationVidage` = `RoborockDockFeatures.is_collectable` → commande info `station_vidage` (`subType=string`), valeur = `libelles.libelle_vidage(...)` → « En cours » / « Terminé » / « Au repos ». Commande **distincte** de `etat`. |
| **AC2** — lavage **et** séchage distincts | Capacités `stationLavage` = `is_washable` et `stationSechage` = `is_dryable` → **deux** commandes info `station_lavage` et `station_sechage`, deux fonctions de libellé distinctes. |
| **AC3** — station basique ⇒ aucune commande | `has_dock` faux ⇒ les 5 capacités sont fausses ⇒ `appliquerCapacites()` ne crée rien et `appliquerValeurs()` n'écrit rien (les clés sont absentes du payload). Vérifié par exécution : `from_dock_type(None)` et `from_dock_type(unknown)` rendent `has_dock = is_collectable = is_washable = is_dryable = False`. |
| **AC4** — incident station ≠ erreur robot, discriminable en scénario | Paire `station_erreur` (string, libellé FR) + `station_erreur_code` (numeric, code brut) — **copie exacte** du couple `erreur`/`erreur_code` d'UC07 ⇒ un scénario teste `station_erreur_code != 0` vs `erreur_code != 0`, sur deux commandes distinctes. Plus `station_manque_eau` (binary). |
| **AC5** — retour automatique à la normale | Les 6 valeurs sont **recalculées intégralement à chaque publication**, sans verrou ni accumulateur ni cache ; la sonde d'UC10 republie toutes les 30/60 s par robot. Exige la divergence **D-13-6** ci-dessous. |
| Hors périmètre respecté | Aucune commande **action** créée. Aucune dépendance à UC14 : les commandes robot `erreur`/`erreur_code` existent **déjà** depuis UC07, la distinction d'AC4 est donc vérifiable aujourd'hui. Aucun consommable touché. |

## Contrats externes (wheel `python_roborock-7.8.0` extrait, lu verbatim et exécuté)

**Aucun appel réseau nouveau.** Le démon n'émet **aucune RPC supplémentaire** : tous les champs exploités
sont déjà dans la réponse `get_status` que `status.refresh()` obtient. Les contrats ci-dessous sont des
contrats de **librairie**, pas de réseau.

### Champs de station réellement présents — `roborock/data/v1/v1_containers.py`

| Champ | Ligne | Type déclaré | Métadonnée | Poussé en dps ? |
|---|---|---|---|---|
| `dock_type` | l.110 | `RoborockDockTypeCode \| None` | **aucune** | non |
| `dock_error_status` | l.118 | `RoborockDockErrorCode \| None` | `{"dock_feature": "has_dock"}` | non |
| `dust_collection_status` | l.111 | **`int \| None`** | **aucune** | non |
| `auto_dust_collection` | l.112 | `int \| None` | aucune | non |
| `wash_status` | l.125 | **`int \| None`** | **aucune** | non |
| `wash_phase` | l.94 | **`int \| None`** | **aucune** | non |
| `wash_ready` | l.95 | **`int \| None`** | **aucune** | non |
| `dry_status` | l.128 | **`int \| None`** | `{"dps": DRYING_STATUS}` (134) | **oui** |
| `water_shortage_status` | l.109 | `int \| None` | `{"feature": "is_support_water_mode"}` | non |
| `has_am` | **l.162-166** | `bool \| None` (**propriété** dérivée : `(self.dss & 3) == 2`, `None` si `dss` absent) | — | non |
| `rdt` | l.129 | `int \| None` | `{"feature": "is_supported_drying"}` | non |

Chemin d'accès : `appareil.v1_properties.status.<champ>` — `StatusTrait(StatusV2, common.V1TraitMixin,
TraitUpdateListener)` (`devices/traits/v1/status.py` l.32) **hérite** de `StatusV2`, les champs sont donc
des attributs directs. Confirmé par l'usage existant dans `robots.valeurs_etat()`.

> ⚠️ **Constat majeur, il conditionne toute la section « Libellés »** : `dust_collection_status`,
> `wash_status`, `wash_phase` et `wash_ready` sont des **entiers nus, sans enum, sans documentation, et
> sans aucun consommateur dans la librairie**. Un `grep -rn` sur tout le wheel ne les trouve que dans
> `v1_containers.py` (déclaration) et `testing/v1_simulator.py` (valeurs figées). Home Assistant ne les
> expose pas non plus (vérifié sur `sensor.py` et `binary_sensor.py` de `home-assistant/core@dev`), et la
> documentation amont `status.rst` les liste sans description. **Il n'existe aucune source de vérité pour
> leurs valeurs.**

### Modèle de capacités de station — `roborock/device_features.py` l.869-1003

`RoborockDockFeatures` (dataclass gelé), docstring l.871-875 : *« This mirrors the Roborock app's DK
capability model: feature availability should be attached to the dock family instead of repeated per
trait. »*

- `from_dock_type(dock_type, has_am=None)` l.880-884, `@classmethod @cache` → **coût nul** à l'appel
  répété ; `dock_type=None` ⇒ `o0_dock`.
- `has_dock` l.892-894 · `is_pure_collect` l.896-898 · `is_pure_wash` l.900-902 · `is_collect_wash`
  l.904-906 · `is_collect_wash_dry` l.908-910 · `is_collectable` l.912-914 · `is_washable` l.916-918 ·
  `is_dryable` l.920-922.
- Familles : `_NO_DOCK_TYPES = {unknown, o0_dock}` l.682-685 ; `_PURE_COLLECT = {o1_dock, oc_dock}`
  l.687-690 ; `_PURE_WASH = {o2_dock}` l.692-694 ; `_COLLECT_WASH = {o3_dock}` l.696-698.

**Matrice vérifiée par exécution** :

| `dock_type` | `has_dock` | `is_collectable` | `is_washable` | `is_dryable` |
|---|---|---|---|---|
| `unknown` (-9999) | False | False | False | False |
| `o0_dock` (0) | False | False | False | False |
| `o1_dock` (1), `oc_dock` (5) | True | **True** | False | False |
| `o2_dock` (2) | True | False | **True** | False |
| `o3_dock` (3) | True | **True** | **True** | False |
| `o4_dock` (7), `hera_dock` (24), **tout autre code** | True | **True** | **True** | **True** |
| `None` (champ absent) | False | False | False | False |

Où le lire : `appareil.v1_properties.device_features.dock_features`, peuplé par `discover_features()`
(`devices/traits/v1/__init__.py` l.273-275), lui-même appelé par `start()` l.245-250 au `start_connect()`.
Défaut **avant** découverte : `from_dock_type(o0_dock)` (`traits/v1/device_features.py` l.66) ⇒ tout
`False` — **défaut sûr**.

### `has_am` : conservé, et pourquoi — *(corrigé après revue)*

La revue a relevé que `has_am` était la seule référence d'attribut non sourcée du plan. Elle est désormais
sourcée (`v1_containers.py` l.162-166) et **vérifiée par exécution** : `StatusV2(dss=169).has_am` → `False` ;
`StatusV2().has_am` (dss absent) → `None`, **sans `AttributeError`**. La librairie elle-même l'appelle à
l'identique en découverte : `devices/traits/v1/__init__.py` **l.274**,
`RoborockDockFeatures.from_dock_type(dock_type, has_am=self.status.has_am)`.

`has_am` est stocké (`device_features.py` l.878) et lu par deux helpers, `_matches_am_variant` (l.886-887)
et `_matches_non_am_variant` (l.889-890), utilisés par exactement **4** propriétés :
`is_clean_fluid_auto_delivery_supported` (l.932-936), `is_clean_carousel_self_clean_supported` (l.950-952),
`is_water_updown_drain_supported` (l.954-958), `is_double_serial_communication_supported` (l.972-976).

**Aucune des 4 propriétés lues par UC13 n'en dépend.** Vérifié par exécution exhaustive : 44 `dock_type`
× 3 valeurs de `has_am` (`None`/`True`/`False`) = **132 combinaisons, 0 écart** sur le quadruplet
`(has_dock, is_collectable, is_washable, is_dryable)`. L'omettre serait donc fonctionnellement neutre
*aujourd'hui*.

**Il est malgré tout conservé**, pour deux raisons :
1. **30 des 44 types de dock** ont au moins une propriété sensible à `has_am` (tous les `shell_*`, `k1*`,
   `f1*`, plus `couple`, `hera`, `o6`, `o7`, `o7h`, `type_27`). `_dock()` devient le point d'accès unique
   aux capacités de station pour UC14/UC15, et les propriétés `clear_water_box_status` /
   `clean_fluid_status` écartées en **D-13-5** portent les métadonnées `dock_feature: is_washable` et
   `dock_feature: is_clean_fluid_auto_delivery_supported` — cette dernière étant AM-sensible. L'omettre
   poserait un **piège pour la prochaine UC** : la capacité répondrait faux sur 30 docks sur 44,
   silencieusement, sans que rien dans le code ne dise pourquoi.
2. C'est **exactement** l'appel de la librairie en découverte (l.274) ; tout l'argument de D-13-2 est que
   notre recalcul lui soit strictement équivalent.

⚠️ **Ne pas écrire `getattr(status, 'has_am', None)`** : ce serait un cache-misère sur un attribut sourcé,
et cela transformerait une vraie rupture de contrat à la montée de version en **dégradation muette** —
précisément le défaut que la revue dénonçait.

### `RoborockDockFeatures` n'est PAS réexporté par `roborock/__init__.py`

**Vérifié par exécution** : `hasattr(roborock, 'RoborockDockFeatures')` → **`False`**. `roborock/__init__.py`
(l.1-27) ne fait que `from roborock.data import *`, `from roborock.exceptions import *`,
`from roborock.roborock_typing import *`, puis `from . import (const, data, devices, exceptions,
roborock_typing, web_api)` — **`device_features` n'y figure pas**.

⇒ **Import obligatoire par chemin complet** : `from roborock.device_features import RoborockDockFeatures`.
Un `from roborock import RoborockDockFeatures` lèverait un `ImportError` **au chargement de `robots.py`**,
donc **un démon qui ne démarre plus du tout**, invisible à toute vérification statique du dépôt.

> **Généralisation du piège déjà mémorisé** : la mémoire d'agent et l'INDEX formulent ce piège comme propre
> à `roborock.devices.*`. Il est **plus large** — `roborock.device_features` est un module de **premier
> niveau** et n'est pas plus réexporté. Formulation correcte : *seuls `data`, `exceptions` et
> `roborock_typing` sont réexportés (par étoile), plus les sous-modules `const/data/devices/exceptions/
> roborock_typing/web_api` comme espaces de noms ; **tout le reste s'importe par chemin complet***.
> (`RoborockDockTypeCode` et `RoborockDockErrorCode`, eux, **sont** accessibles depuis la racine via
> `roborock.data` — vérifié — mais UC13 n'en a pas besoin.)

### `RoborockDockErrorCode` — 11 membres, `roborock/data/v1/v1_code_mappings.py` l.298-341

`ok=0`, `no_dustbin_or_filter=32`, `auto_empty_dock_fan_error=33`, `duct_blockage=34`,
`auto_empty_dock_voltage_error=35`, `water_empty=38`, `waste_water_tank_full=39`,
`maintenance_brush_jammed=42`, `dirty_tank_latch_open=44`, `no_dustbin=46`,
`cleaning_tank_full_or_blocked=53`. Chaque membre porte un docstring anglais qui en donne le sens — c'est
la source des libellés FR.

> ⚠️⚠️ **Le piège « code inconnu écrasé en 0 » s'applique à l'identique, confirmé par exécution.**
> `RoborockDockErrorCode` **n'a pas de membre `unknown`** (`hasattr(..., 'unknown')` → `False`).
> `RoborockEnum._missing_` (`data/code_mappings.py` l.29-42) retombe donc sur le **premier membre
> déclaré** : `RoborockDockErrorCode(999)` rend **`ok`, valeur `0`, `display_name = 'ok'`**.
> ⇒ **une erreur de station inconnue de la 7.8.0 se présente comme « aucune erreur »**, exactement comme
> `RoborockErrorCode` en UC07.
>
> **On ne s'en prémunit pas au niveau du code** — c'est structurellement impossible sans réimplémenter
> `RoborockBase.from_dict` (le champ est typé dans le dataclass, la conversion a lieu à la
> désérialisation, la réponse brute n'est pas conservée). On **hérite** de la parade d'UC07 : (a) la
> librairie journalise `Missing RoborockDockErrorCode code: N` en **`WARNING`** (l.32-41), une fois par
> code ; (b) **aucune logique du plugin ne dépend de « pas d'erreur » comme d'une information positive** ;
> (c) c'est un **point de recette explicite** (n° 6), pas un point de review.

`RoborockDockTypeCode`, lui, **a** un membre `unknown = -9999` (l.345) : `RoborockDockTypeCode(9999)` rend
`unknown` (vérifié). Conséquence pour AC3 : un dock plus récent que la 7.8.0 se présente comme une station
basique (cf. Risques R3).

### `StatusV2.dock_state` l.218-251 — ce que la librairie fait elle-même de l'état de station

Propriété **synthétique**, docstring l.220-226 : *« It is highly recommended for consumers of this API to
use this synthesized state to determine if the vacuum is charging or docked, rather than attempting to
parse the raw integer data points. »* Elle mappe l.230-232 `RoborockStateCode.emptying_the_bin` →
`RoborockDockState.dusting`. **C'est la source qui autorise à dériver « vidage en cours » de l'état du
robot plutôt que d'un entier non documenté.** On n'utilise pas `dock_state` telle quelle : orientée
« charge/dock », elle ne dit rien du lavage ni du séchage.

### Lecture de référence — Home Assistant (`home-assistant/core@dev`)

`components/roborock/binary_sensor.py` : `water_shortage` ← `status.water_shortage_status`,
`device_class = PROBLEM` ⇒ **lecture booléenne** ; `dry_status` ← `status.dry_status`,
`device_class = RUNNING`, marqué entité de station ⇒ **lecture booléenne « séchage en cours »**.

`components/roborock/sensor.py`, capteur `dock_error` :

```python
def _dock_error_value_fn(state: DeviceState) -> str | None:
    if (status := state.status.dock_error_status) is not None and state.status.dock_type != RoborockDockTypeCode.o0_dock:
        return status.name
    return None
```

avec `support_fn=lambda api: api.device_features.is_field_supported(StatusV2, StatusField.DOCK_ERROR_STATUS)`
— c'est-à-dire, via la métadonnée `{"dock_feature": "has_dock"}`, **exactement `dock_features.has_dock`**.
L'implémentation de référence valide donc le critère retenu en D-13-2.

### Capture réelle embarquée dans la librairie — `roborock/testing/v1_simulator.py` l.53-97

`DEFAULT_STATUS` (dock `o4_dock`, robot en charge) porte `wash_phase=0`, `wash_ready=0`,
`dust_collection_status=0`, `auto_dust_collection=1`, `water_shortage_status=0`,
`dock_error_status=RoborockDockErrorCode(0)`, `dss=169` — **mais ni `wash_status` ni `dry_status`**, tous
deux **absents (`None`)** sur une station qui lave et sèche indiscutablement. C'est l'argument décisif de
**D-13-2**.

## Décisions d'architecture

### D-13-1 — Chemin de publication : les chemins existants, sans rien y ajouter

Les champs de station sont dans `StatusV2` ⇒ déjà en RAM après chaque `status.refresh()`. Les **trois**
chemins de publication (`robots.lire_etat`, `robots.envoyer_commande` relecture post-action,
`supervision._lot`) convergent tous vers **`robots.capacites_etat()`** et **`robots.valeurs_etat()`** :
les étendre couvre les trois chemins **d'un seul point de modification**.

- **Nouvelle opération RPC** : aucune. **Nouvel appel réseau / RPC Roborock** : aucun.
- **Quota : nul.** Budget de `lireEtat` **inchangé** (`DELAI_TOTAL_ETAT_S = 30`, `TIMEOUT_ETAT = 35`),
  aucune constante de délai à ajouter.
- **Cadence du push (UC10) : strictement inchangée** (30 s en nettoyage / 60 s au repos). **Aucune
  nouvelle tâche asyncio, aucun cycle lent** — contrairement à UC12.
- **Volume du push** : `_lot()` grossit de **6 clés dans `etat`** (à chaque lot) et **5 clés dans
  `capacites`** (premier lot et lots d'échec seulement, `avec_capacites`). Ordre de grandeur :
  ~150-250 octets par lot et par robot, sur un lot qui en fait déjà ~600. Sans effet sur la fenêtre de
  regroupement de `jeedom_com` (`INTERVALLE_LOT_S = 2`).
- ⚠️ **`dry_status` est le seul champ de station poussé en dps (134)** : sur un réveil par push, `_lot()`
  publie sans RPC et les 8 autres champs de station gardent leur valeur en RAM — correcte, simplement pas
  plus fraîche que le dernier sondage. C'est le comportement voulu, pas un défaut.

### D-13-2 — Détection des capacités : `RoborockDockFeatures`, **pas** le critère « valeur non nulle » d'UC12

**Écart explicite avec UC12, et il est obligatoire** :

- UC12 a retenu `getattr(conso, champ) is not None` **parce qu'aucun autre critère n'existait** :
  `ConsumableField` ne déclare que 3 des 5 champs, `is_field_supported()` est structurellement inappelable
  pour les 2 autres, et la librairie **documente elle-même** ce critère (`consumeable.py` l.48-49).
- **Ici, le critère inverse serait faux.** La capture réelle embarquée dans la librairie montre
  `wash_status = None` **et** `dry_status = None` sur un `o4_dock` qui lave et sèche. Un critère
  `is not None` produirait un **faux négatif** : aucune commande de lavage ni de séchage sur une station
  qui les supporte ⇒ **AC2 cassé**.
- La librairie fournit ici un modèle de capacité **dédié**, qui se déclare comme miroir du modèle de
  l'application Roborock, et que l'implémentation de référence (Home Assistant) utilise.

⚠️ **`is_field_supported()` n'est pas utilisable** pour la majorité de ces champs : `dust_collection_status`,
`wash_status`, `wash_phase`, `wash_ready` et `dock_type` **n'ont aucune métadonnée** ⇒ il renvoie **`True`
en permanence** (`traits/v1/device_features.py` l.93-94). C'est la « famille 2 » du piège d'UC07 — on ne
l'appelle donc **jamais** sur ces champs.

**Les 5 capacités** :

| Clé démon | Expression | AC |
|---|---|---|
| `stationVidage` | `dock.is_collectable` | AC1 |
| `stationLavage` | `dock.is_washable` | AC2 |
| `stationSechage` | `dock.is_dryable` | AC2 |
| `stationErreur` | `dock.has_dock` | AC4 |
| `stationManqueEau` | `dock.has_dock and (features.is_field_supported(StatusV2, StatusField.WATER_SHORTAGE_STATUS) or status.water_shortage_status is not None)` | AC4 |

La dernière suit la **« famille 1 »** d'UC07 (champ à métadonnée `{"feature": "is_support_water_mode"}`,
garde-fou `or valeur is not None` contre un `supported_schema_ids` vide), **conjointe à `has_dock`** pour
honorer AC3 à la lettre : la spec range « manque d'eau dans le réservoir » parmi *« les incidents propres à
la station »*, et AC3 interdit toute information d'entretien de station sur une station basique.
**Contrepartie assumée** : un robot **sans station** dont le réservoir embarqué se vide n'expose rien.
C'est hors périmètre d'UC13 (la spec parle de station) et rattrapable en domaine 30 sans migration.

### D-13-2b — `_dock()` : trois niveaux **explicites**, et le repli se **journalise** — *(corrigé après revue)*

La revue a relevé, à juste titre, que le `_dock()` initialement prévu était un dispositif de **dégradation
silencieuse** : un `try/except` générique y rendait le repli indiscernable du fonctionnement nominal. Les
trois niveaux deviennent explicites :

| Niveau | Condition | Action | Journalisation |
|---|---|---|---|
| **1 — nominal** | `status.dock_type is not None` | `from_dock_type(status.dock_type, has_am=status.has_am)`, **sans `try/except` autour** | aucune |
| **2 — repli** | `status.dock_type is None` | `getattr(features, 'dock_features', None)` | `logging.info` **une seule fois par duid** : « dock_type absent de la réponse d'état, repli sur les capacités découvertes » |
| **3 — dernier recours** | niveau 2 indisponible | `from_dock_type(None)` ⇒ tout `False` | `logging.warning` **une seule fois par duid** |

⚠️ **Pas de `try/except` sur le chemin nominal** : `dock_type` et `has_am` sont tous deux sourcés, et une
`AttributeError` à cet endroit **est** un bug de montée de version qui doit remonter bruyamment — elle sera
classée par le `try/except` du handler RPC ou de l'itération de sonde, avec son code stable. L'absorber
reproduirait exactement le défaut que la revue dénonce.

Le jeu de duids déjà signalés suit le patron `_ETATS_INCONNUS_JOURNALISES` de `libelles.py`.
**Bénéfice collatéral** : un repli permanent devient visible au journal, et le point 1 de la recette
(relever le `dock_type` réel du Qrevo Curv) devient observable **sans ajouter de log ad hoc**.

Motif du recalcul au niveau 1 plutôt que de lire `features.dock_features` d'emblée :
`features.dock_features` peut être resté au **défaut `o0_dock`** si `discover_features()` a échoué, alors
que le `status.refresh()` qu'on vient de réussir porte le `dock_type` à jour. Le recalcul est **exactement**
l'expression de la librairie en découverte (l.274), et `from_dock_type` étant `@cache`d, son coût est nul.

### D-13-3 — Libellés : tables FR figées côté démon, dans `libelles.py`

Deux régimes, parce que les champs ne sont pas de même nature.

**(a) `dock_error_status` — enum typé ⇒ table indexée sur `display_name`.** Copie conforme du patron
`libelles.ERREURS` d'UC07 (D-07-3) : indexation sur `display_name` et **jamais** sur `.name` (des codes
distincts peuvent partager un `display_name`), repli sur l'identifiant anglais avec les underscores
remplacés par des espaces, log `info` **une seule fois par clé**. 10 entrées (`ok` n'en a pas besoin :
court-circuit à `''`).

⚠️ **Règle de rédaction imposée par AC4** : **tout** libellé d'erreur de station mentionne explicitement la
station (« Bac à poussière **de la station** absent »). Sans cela, `no_dustbin` station (46) et
`no_dustbin` robot (9) produiraient le **même texte français** dans deux tuiles différentes — l'ambiguïté
qu'AC4 interdit précisément.

**(b) `dust_collection_status` / `wash_status` / `wash_phase` / `wash_ready` / `dry_status` — entiers non
documentés ⇒ libellé COMPOSÉ, dont la branche décisive vient de `RoborockStateCode`** (typé, documenté,
déjà traduit dans `libelles.ETATS`) :

```
libelle_vidage(etat, dust_collection_status):
    display_name(etat) == "emptying_the_bin"     -> "En cours"        # SOURCÉ : dock_state l.231-232
    _actif(dust_collection_status)               -> "Terminé"         # INFÉRENCE
    sinon                                         -> "Au repos"

libelle_lavage(etat, wash_status, wash_phase, wash_ready):
    display_name(etat) == "washing_the_mop"      -> "En cours"        # SOURCÉ : codes 23 et 25
    _actif(wash_status) or _actif(wash_phase)    -> "En cours"        # INFÉRENCE
    _actif(wash_ready)                           -> "Terminé"         # INFÉRENCE
    sinon                                         -> "Au repos"

libelle_sechage(etat, dry_status):
    display_name(etat) == "air_drying_stopping"  -> "Arrêt en cours"  # SOURCÉ : code 202
    _actif(dry_status)                           -> "En cours"        # SOURCÉ : HA binary_sensor RUNNING
    sinon                                         -> "Au repos"
```

**Pourquoi cette composition, et pas la lecture directe de l'entier** : elle est **robuste aux deux
hypothèses possibles** sur `dust_collection_status`. Si l'entier est un drapeau *live* (1 pendant le
vidage), la branche `etat` produit « En cours » au bon moment et « Terminé » n'apparaît qu'en transitoire.
Si l'entier signifie « du vidage a eu lieu » (lecture appuyée par `CleanRecord.dust_collection_status=1`,
`v1_containers.py` l.300), alors « Terminé » est exact. **Dans les deux cas, « En cours » et « Au repos »
sont corrects** — seule la nuance « Terminé » dépend de l'inférence, et elle est marquée comme telle dans
le code. Argument complémentaire : le *mode* de vidage a ses propres champs (`auto_dust_collection`, et
l'enum `RoborockDockDustCollectionModeCode` servie par le trait `dust_collection_mode`), donc
`dust_collection_status` est bien un **statut**, pas un réglage.

`_actif(valeur)` : helper `None`-safe et type-safe (`None` → `False` ; entier `!= 0` → `True` ; non
convertible → `False`). ⚠️ **Jamais un `if valeur:` nu** : un `"0"` en chaîne serait vrai.

**Pourquoi `libelles.py` et pas un `station.py`** (divergence assumée avec UC12) : UC12 a créé
`consommables.py` parce qu'il portait (1) une **table de contrat tiers** (`roborock.const`), (2) un
**calcul** et (3) une **liste blanche** partagée avec `robots.py` — d'où sa dérogation explicite (D-12-5) à
la règle « pas d'import `roborock.*` dans un module de pures données ». UC13 n'a rien de tout cela : des
libellés purs d'un côté, cinq booléens de l'autre.
⇒ **`libelles.py` reçoit les libellés et respecte la règle intégralement : aucun import `roborock.*`** (les
fonctions ne lisent que `display_name` via le `_cle()` existant, et des entiers). **`robots.py` reçoit le
calcul de capacités**, puisqu'il importe déjà massivement `roborock.*`. **Aucun module nouveau** : un
`station.py` de 60 lignes serait de la structure sans contenu, et `robots.py` devrait de toute façon
importer `RoborockDockFeatures`.

### D-13-3b — Pourquoi UC13 étend les méthodes d'UC07 au lieu de reproduire le patron d'UC12 — *(corrigé après revue)*

UC12 a traité les consommables par une paire **dédiée** `definitionsConsommables()`/`appliquerConsommables()`,
distincte de `definitionsCommandes()`/`appliquerCapacites()`/`appliquerValeurs()`. UC13 fait l'inverse et
étend directement les méthodes d'UC07. **Motif** : les champs de station arrivent dans le **même** bloc
`capacites`/`etat`, depuis le **même** trait `status`, à la **même** cadence que l'état robot — là où les
consommables ont leur trait (`ConsumableTrait`), leur bloc (`consommables`), leur cadence (cycle lent 1 h)
et leur opération (`reinitialiserConsommable`). Créer ici une paire dédiée **dupliquerait**
`appliquerCapacites()` sans rien isoler.

### D-13-4 — Historisation : **non**, `isHistorized = 0` sur les 6 commandes

1. Ce sont des états **transitoires à valeur nominale constante** (« Au repos », `''`, `0`) : un historique
   n'apporte rien qu'un scénario ou la timeline Jeedom ne donne déjà.
2. `checkAndUpdateCmd()` n'historise pas une répétition (`jeedom-widgets-commandes.md` § 10), mais la
   cadence du superviseur (30/60 s **par robot**) multiplierait quand même les points à chaque alternance
   d'état pendant un cycle de nettoyage — pour une donnée sans valeur analytique.
3. Cohérence : les 12 commandes d'UC07 sont à 0 sauf `batterie`, les 5 usures d'UC12 sont à 0.

Décision **réversible sans migration** : l'utilisateur peut activer l'historisation lui-même, et la règle
d'idempotence (UC07/UC08/UC12) interdit de réécrire `isHistorized` sur une commande existante — son choix
survivra à tous les rafraîchissements.

### D-13-5 — Périmètre : 6 commandes, et ce qui est volontairement laissé de côté

Sont **exclus** : `clear_water_box_status`, `dirty_water_box_status`, `dust_bag_status`,
`clean_fluid_status`, `hatch_door_status`, `dock_cool_fan_status` (propriétés dérivées des bits de
`status.dss`, `v1_containers.py` l.168-216) et `rdt`. **Motif** : aucun AC ne les exige, et les incidents
qu'ils décrivent (bac plein, réservoir d'eau sale) sont déjà couverts par `dock_error_status`
(`no_dustbin_or_filter`, `duct_blockage`, `waste_water_tank_full`, `cleaning_tank_full_or_blocked`). Le plus
petit plan qui satisfait la spec gagne. Ils portent des métadonnées `dock_feature` propres et seront donc
**triviaux à ajouter** en UC14/UC15 si la recette montre que l'utilisateur en a besoin.

### D-13-6 — AC5 : la valeur normale est **écrite**, pas seulement « pas contredite »

⚠️ **Divergence assumée avec la règle « clé absente quand la source est `None` » d'UC07 — à lire en entier
avant de la prendre pour une régression.**

`merge_trait_values()` (`devices/traits/v1/common.py` l.91-100, vérifié) **recopie tous les champs, `None`
compris** : une réponse `get_status` qui omet `dock_error_status` **efface** la valeur du trait. Sous la
règle d'UC07, `appliquerValeurs()` laisserait alors la commande **figée sur la dernière erreur** — **AC5
cassé de façon durable et silencieuse**.

⇒ **Règle UC13, bornée aux 6 clés de station** : dès que la capacité correspondante est vraie, la clé est
**toujours présente dans le payload**, avec la valeur normale quand la source est `None` :

- `stationVidage` / `stationLavage` / `stationSechage` → « Au repos » (composition de plusieurs signaux :
  l'absence de tous vaut « rien en cours ») ;
- `stationErreurCode` → `0`, `stationErreurLibelle` → `''` ;
- `stationManqueEau` → `False`.

**Justification de la divergence** : la règle d'UC07 protège contre l'écriture d'un **défaut** pour une
grandeur **jamais mesurée** (écrire `batterie = 0` quand on ne sait pas est un mensonge). Ici, le drapeau
de capacité garantit déjà l'existence de la station, et le champ appartient à la **même** réponse
`get_status` qui vient d'aboutir : son absence signifie « rien à signaler », pas « inconnu ». La sémantique
n'est pas la même, la politique non plus.

⚠️ **Cette divergence doit être commentée DANS LE CODE, aux deux bouts** — *(corrigé après revue)* : une
review de code ne lit pas cette spec en parallèle, et le projet documente ses divergences assumées dans le
code (cf. D-12-5 dans `consommables.py`). Le commentaire dit **pourquoi**, pas **quoi** :

- **Côté démon**, au-dessus du bloc station de `valeurs_etat()` — c'est là que la clé est **produite**,
  donc là qu'un développeur serait tenté de « corriger » l'incohérence apparente avec les 9 clés
  existantes ;
- **Côté PHP**, au-dessus des 6 nouveaux blocs d'`appliquerValeurs()`.

Formulation : *« D-13-6 : divergence assumée avec la règle "clé absente quand la source est None" (UC07) —
`merge_trait_values()` peut effacer `dock_error_status`, et AC5 exige que l'état normal soit **écrit**, pas
seulement non contredit. Cf. spec technique UC13. »*

## Architecture — fichiers

| Chemin | État | Ce qui y entre | Indentation |
|---|---|---|---|
| `resources/demond/libelles.py` | **modifié** | Table `ERREURS_STATION` (10 entrées, indexée `display_name`) ; jeu `_ERREURS_STATION_INCONNUES_JOURNALISEES` ; constantes de libellés d'opération ; helpers `_actif()` et `_resoudre()` ; 4 fonctions publiques. **Aucun import `roborock.*` ajouté.** `ETATS`, `ETATS_NETTOYAGE`, `ERREURS`, `libelle_etat()`, `libelle_erreur()`, `est_en_nettoyage()` **strictement inchangées**. | 4 espaces, **LF** |
| `resources/demond/robots.py` | **modifié** | 1 import (`from roborock.device_features import RoborockDockFeatures`) ; `_dock(status, features, duid)` ; 5 clés dans `capacites_etat()` ; 6 clés + **paramètre `features`** dans `valeurs_etat()` ; mise à jour des 2 appels internes ; docstring de `capacites_etat()` remise à jour. **Aucune** opération, constante de budget, liste blanche, `ACTIONS`, `obtenir_appareil`, `_gestionnaire` touchés. | 4 espaces, **LF** |
| `resources/demond/supervision.py` | **modifié** | **2 lignes** dans `_lot()` : remonter `features = appareil.v1_properties.device_features` hors du garde `avec_capacites`, et le passer à `robots.valeurs_etat()`. `demarrer()`, `arreter()`, `_reconcilier()`, `_sonde()`, `_publier()`, cadences et constantes : **intouchées** (idempotence UC10, exigence d'acceptation). | 4 espaces, **LF** |
| `resources/demond/jeeroborockd.py` | **inchangé** | Aucune opération à enregistrer, aucun logger à brider en plus. |
| `resources/demond/station.py` | **NON créé** | cf. D-13-3. |
| `consommables.py`, `textes.py`, `erreurs.py`, `canal.py`, `session.py`, `authentification.py`, `equipements.py`, `routines.py` | **inchangés** | |
| `core/class/jeeroborock.class.php` | **modifié** | 1 constante `ORDRE_BASE_STATION` ; **6 lignes** dans `definitionsCommandes()` ; **5 lignes** dans `$correspondances` d'`appliquerCapacites()` ; **6 blocs** dans `appliquerValeurs()` ; **4 commentaires-compteurs** remis à jour. **Aucune méthode nouvelle** ; `creerCommande()`, `appliquerEtatPartiel()`, `rafraichirEtat()`, `traiterPoussee()`, `execute()`, `dontRemoveCmd()`, `postSave()` **inchangées**. | 2 espaces, **CRLF** |
| `core/class/jeeroborockDaemon.class.php` | **inchangé** | Aucun code d'erreur nouveau (UC13 est en lecture seule, aucune action, aucun refus métier à exprimer), aucun `TIMEOUT_*` nouveau. |
| `core/class/jeeroborockException.class.php` | **inchangé** | Aucun code de **famille A ou B** ⇒ le piège de la double liste `tableMessages()`/`estErreurCanal()` **ne s'applique pas ici**. |
| `core/php/jeeroborock.inc.php` | **inchangé** | **Autoload sans objet** : aucune classe PHP nouvelle. |
| `core/config/jeeroborock.config.ini` | **inchangé** | Aucune clé de configuration plugin. |
| `plugin_info/packages.json` | **inchangé** | La 7.8.0 porte déjà `RoborockDockFeatures`, `RoborockDockErrorCode`, `RoborockDockTypeCode`. |
| `plugin_info/configuration.txt` **et** `.php` | **inchangés** | Pas de champ de formulaire ⇒ **pas de `cp` à jouer**. |
| `plugin_info/info.json` | `pluginVersion` **bumpé par le hook** `pre-commit` | Rien d'autre. |
| `core/ajax/*`, `desktop/php/*`, `desktop/js/*`, `desktop/modal/*`, `core/template/*` | **inchangés** | Aucun endpoint, page ni widget (le cœur pose `core::default`). |
| `core/i18n/*.json` | **inchangés** | Traduction déléguée au `translator`, en fin de cycle. |

## Signatures

### Démon — `libelles.py` (modifié, aucun import `roborock.*`)

```python
ERREURS_STATION = { <display_name>: <libellé FR> }   # 10 entrées ; 'ok' absent (court-circuit)
                                                     # chaque libellé mentionne explicitement la station

VIDAGE_EN_COURS = "En cours" ; VIDAGE_TERMINE = "Terminé" ; REPOS = "Au repos"
LAVAGE_EN_COURS = "En cours" ; LAVAGE_TERMINE = "Terminé"
SECHAGE_EN_COURS = "En cours" ; SECHAGE_ARRET = "Arrêt en cours"

def _actif(valeur) -> bool
    # None -> False ; int(valeur) != 0 -> True ; non convertible -> False. Ne lève jamais.

def _resoudre(valeur, table, journalises, contexte) -> str
    # display_name -> table[…] ; sinon identifiant anglais avec '_' -> ' ', log info UNE fois par clé.
    # Helper privé introduit pour ce cycle ; libelle_etat()/libelle_erreur() ne sont PAS réécrites
    # dessus (zéro risque de régression sur une UC livrée) — quasi-duplication de ~12 lignes
    # ASSUMÉE, sans liste à tenir synchronisée entre les deux.

def libelle_erreur_station(erreur) -> str
    # '' si None OU int(erreur) == 0 (RoborockDockErrorCode.ok) ; sinon _resoudre(...).

def libelle_vidage(etat, dust_collection_status) -> str                # AC1, 3 valeurs
def libelle_lavage(etat, wash_status, wash_phase, wash_ready) -> str   # AC2, 3 valeurs
def libelle_sechage(etat, dry_status) -> str                           # AC2, 3 valeurs
```

Aucune de ces fonctions ne lève. Aucune n'accepte de donnée venue du cloud sous forme de **texte libre**
(seulement des enums de la librairie et des entiers) ⇒ **pas d'appel à `textes.texte()` requis**, comme
pour les fonctions existantes.

### Démon — `robots.py` (modifié)

```python
from roborock.device_features import RoborockDockFeatures   # chemin COMPLET obligatoire

def _dock(status, features, duid):
    """RoborockDockFeatures effectives — trois niveaux explicites (D-13-2b).
    Niveau 1 nominal : from_dock_type(status.dock_type, has_am=status.has_am), SANS try/except.
    Niveau 2 repli   : getattr(features, 'dock_features', None) + log info une fois par duid.
    Niveau 3 recours : from_dock_type(None) -> tout False + log warning une fois par duid."""

def capacites_etat(status, features, duid) -> dict   # 7 booléens existants + 5 clés station
def valeurs_etat(status, features, duid) -> dict     # ⚠️ SIGNATURE CHANGÉE (2 paramètres ajoutés)
```

⚠️ **`duid` est un 3ᵉ paramètre des DEUX fonctions** — *(corrigé à l'implémentation)*. La rédaction
initiale de cette section ne listait que `(status, features)` alors qu'elle spécifiait `_dock(status,
features, duid)` : c'était une incohérence interne, pas un choix. `_dock()` est appelée depuis
**l'intérieur** des deux fonctions (`capacites_etat()` pour les 5 booléens, `valeurs_etat()` pour gater ses
6 clés par capacité, D-13-6) et doit journaliser ses replis **une fois par duid** (D-13-2b) : le `duid`
doit donc y descendre. Il est disponible sans coût aux 3 sites d'appel (`duid` / `appareil.duid` déjà en
scope). ⚠️ Il est **neutralisé par `_texte()` avant journalisation**, comme toute donnée d'origine cloud.

⚠️ **`valeurs_etat` prend désormais `features`, sans valeur par défaut.** Un paramètre optionnel ferait
diverger **silencieusement** le comportement d'un site d'appel oublié (capacité vraie mais valeur absente ⇒
tuile vide permanente) ; un `TypeError` bruyant est préférable. Les **3** sites d'appel sont connus et tous
mis à jour : `robots.lire_etat` (l.364/365), `robots.envoyer_commande` relecture post-action (l.489/490),
`supervision._lot` (l.369/372). *(Comptage vérifié par `grep` en revue.)*

Clés ajoutées à `capacites_etat()` : `stationVidage`, `stationLavage`, `stationSechage`, `stationErreur`,
`stationManqueEau` (expressions en D-13-2).
Clés ajoutées à `valeurs_etat()` : `stationVidage`, `stationLavage`, `stationSechage` (libellés FR, string),
`stationErreurCode` (int), `stationErreurLibelle` (string), `stationManqueEau` (bool) — **présentes dès que
la capacité correspondante est vraie**, valeur normale quand la source est `None` (D-13-6), **absentes**
quand la capacité est fausse.

⚠️ **Docstring de `capacites_etat()` à remettre à jour** (l.259-262) — *(corrigé après revue)* : « 7
booléens — DEUX FAMILLES » devient **12 booléens et TROIS familles**. Précision de rédaction :
`stationVidage`/`stationLavage`/`stationSechage`/`stationErreur` sont de la **famille 3 pure** (modèle de
capacités de dock) ; **`stationManqueEau` est un hybride famille 1 ∧ famille 3**. L'avertissement « NE PAS
unifier » reste valable tel quel pour les deux familles existantes et doit être **étendu** : la famille 3
n'appelle **jamais** `is_field_supported()`, parce que `dock_type`/`dust_collection_status`/`wash_*` n'ont
aucune métadonnée et y renverraient `True` en permanence.

### Démon — `supervision.py` (modifié, 2 lignes)

Dans `_lot()`, sous `if etat_lu:` : remonter `features = appareil.v1_properties.device_features` **avant**
`lot["etat"] = robots.valeurs_etat(status, features, appareil.duid)`, puis le réutiliser dans la branche
`avec_capacites`. Rien d'autre.

### PHP — `core/class/jeeroborock.class.php` (modifié)

```php
const ORDRE_BASE_STATION = 110;   // info 110-115
```

⚠️ **Plage d'ordre — piège de planification hérité d'UC12** : le commentaire d'UC12 annonce
`ORDRE_BASE_CONSOMMABLES = 12` comme « plage réservée UC12-15 » (12-16), mais **UC12 l'a consommée
intégralement** avec ses 5 usures. Plages réellement occupées (vérifiées dans le code) : info 0-11 (UC07),
info 12-16 (UC12), actions 20-25 (UC08), actions routines 30-93 (UC09), actions reset 100-104 (UC12).
**17-19 ne laisse que 3 places pour 6 commandes.** ⇒ base **110**, franchement libre, avec de la marge pour
UC14 (120) et UC15 (130). L'`order` n'affecte que l'affichage, n'est **jamais** réécrit sur une commande
existante, et reste ajustable plus tard sans migration.

**6 lignes ajoutées à `definitionsCommandes()`** (littérales `__()` **dans** la table, jamais `__($variable)`) :

| `logicalId` | nom FR | `subType` | `generic` | `visible` | `historise` | `ordre` |
|---|---|---|---|---|---|---|
| `station_vidage` | État vidage poussière | `string` | `''` | 1 | 0 | 110 |
| `station_lavage` | État lavage serpillière | `string` | `''` | 1 | 0 | 111 |
| `station_sechage` | État séchage serpillière | `string` | `''` | 1 | 0 | 112 |
| `station_erreur` | Erreur station | `string` | `''` | 1 | 0 | 113 |
| `station_erreur_code` | Code d'erreur station | `numeric` | `''` | **0** | 0 | 114 |
| `station_manque_eau` | Manque d'eau | `binary` | `''` | 1 | 0 | 115 |

⚠️ **`generic = ''` sur les 6 commandes, et surtout pas un type générique « eau »** — *(corrigé après
revue)*. Le cœur Jeedom n'expose **aucun** type générique pour un manque d'eau, un niveau de réservoir ou
un défaut d'appareil. Les seuls types info liés à l'eau sont **`FLOOD`** (inondation) et **`WATER_LEAK`**
(fuite d'eau) : tous deux décrivent la **présence** d'eau là où il ne devrait pas y en avoir, soit
l'**inverse sémantique** de « manque d'eau ». Les poser ne se limiterait pas à une icône inadaptée — le
type générique est ce qui dit au cœur, à l'application mobile et aux plugins agrégateurs *ce que la
commande signifie* : un robot aspirateur remonterait comme **détecteur d'inondation**, avec un risque réel
de déclencher des scénarios ou des alertes de sécurité tiers. `DOCK`, lui, est un **type d'action**, déjà
porté par `retour_base`. Pas de `setTemplate()` : le cœur pose `core::default`.

**5 lignes ajoutées au tableau `$correspondances` d'`appliquerCapacites()`** :

```
'stationVidage'    => array('station_vidage'),
'stationLavage'    => array('station_lavage'),
'stationSechage'   => array('station_sechage'),
'stationErreur'    => array('station_erreur', 'station_erreur_code'),
'stationManqueEau' => array('station_manque_eau'),
```

C'est **exactement** le patron UC07 « une capacité → plusieurs commandes » (`'erreur' =>
array('erreur','erreur_code')`, l.1473). La création, la convergence idempotente (structurel seulement,
jamais `name`/`isVisible`/`order`/`isHistorized`) et le `try/catch` **par commande** avec troncature à 256
caractères + `nettoyerPourLog()` sont **déjà** dans la méthode. **Rien à écrire de neuf.**

**6 blocs ajoutés à la liste blanche fermée d'`appliquerValeurs()`** (précédés du commentaire D-13-6) :

```
stationVidage / stationLavage / stationSechage -> checkAndUpdateCmd(..., self::texteInventaire((string) $v, self::LONGUEUR_MAX_LIBELLE_ETAT))
stationErreurLibelle                           -> idem, sur 'station_erreur'
stationErreurCode    is_numeric && >= 0        -> checkAndUpdateCmd('station_erreur_code', intval($v))
stationManqueEau                               -> checkAndUpdateCmd('station_manque_eau', !empty($v) ? 1 : 0)
```

Aucune boucle générique sur le payload (même politique que les 9 clés existantes). `checkAndUpdateCmd()`
renvoie `false` sans rien créer quand la commande n'existe pas ⇒ une valeur reçue sans capacité
correspondante est **inerte**, et la création reste pilotée **uniquement** par `appliquerCapacites()`.

**Quatre commentaires-compteurs à remettre à jour** — *(corrigé après revue)*. Ce sont exactement les
compteurs que ce projet maintient scrupuleusement ailleurs, et dont l'un a déjà été pris en défaut :

| Fichier / ligne | Avant | Après |
|---|---|---|
| `robots.py` l.259-262 | « 7 booléens — DEUX FAMILLES » | 12 booléens, **trois** familles (cf. précision ci-dessus) |
| `jeeroborock.class.php` l.503 | « Table statique des 12 commandes d'information » | **18** |
| `jeeroborock.class.php` l.1521-1523 | « Liste blanche FERMÉE de 9 clés » | **15** |
| `jeeroborock.class.php` l.99 | `ORDRE_BASE_CONSOMMABLES = 12; // info 12-16 (plage reservee UC12-15)` | la mention « réserve UC12-15 » devient **fausse** dès qu'UC13 prend 110-115 — à rectifier, sinon UC14 repartira sur une réserve qui n'existe plus (c'est le piège du risque R12) |

**Ce qui n'est pas touché, et c'est le point fort du plan** : `rafraichirEtat()` (les 6 commandes entrent
dans le `$creees` et le `$cmdTotal` existants sans une ligne de code), `appliquerEtatPartiel()` (le lot
poussé porte `capacites`/`etat`, déjà consommés), `traiterPoussee()`, `jeeroborockCmd::execute()` (aucune
action), `dontRemoveCmd()`, `postSave()`, les crons.

## Validation & erreurs

| Situation | Où | Conséquence / message |
|---|---|---|
| Station basique, absente, ou `dock_type` inconnu de la 7.8.0 | Démon, `_dock()` | 5 capacités **fausses** ⇒ aucune commande créée, aucune valeur écrite. **AC3.** Aucun message : ce n'est pas une erreur. |
| `dock_type` absent de la réponse d'état | Démon, `_dock()` niveau 2 | Repli sur `features.dock_features` + **log `info` une fois par duid**. |
| `device_features.dock_features` absent (API « experimental » disparue) | Démon, `_dock()` niveau 3 | Tout `False` + **log `warning` une fois par duid**. Jamais d'exception. |
| `AttributeError` sur `status.dock_type` / `status.has_am` | Démon, chemin **nominal**, non protégé | **Remonte bruyamment** (bug de version), classée par le `try/except` du handler RPC ou de l'itération de sonde. Volontaire (D-13-2b). |
| Code d'erreur de station inconnu | Librairie | **Écrasé en `ok` (0)** ⇒ affiché « aucune erreur ». Non corrigeable (R1). Trace `Missing RoborockDockErrorCode code: N` en `WARNING`, **filtrée au niveau de log Jeedom par défaut**. |
| `dock_error_status` renseigné et hors table FR | Démon, `_resoudre()` | Identifiant anglais avec espaces (jamais un vide, jamais un code brut), log `info` **une fois par clé** — patron UC07 exact. |
| `dust_collection_status` / `wash_*` / `dry_status` à `None` | Démon, `_actif()` | `False` ⇒ « Au repos ». Jamais d'exception, jamais de valeur numérique affichée. |
| `stationErreurCode` non numérique ou négatif | PHP, `appliquerValeurs()` | Ignoré silencieusement, valeur précédente conservée (politique existante). |
| Libellé trop long / caractères de contrôle | PHP, `texteInventaire()` | `nettoyerPourLog()` + `trim()` + troncature à `LONGUEUR_MAX_LIBELLE_ETAT` (128) — chemin existant, inchangé. |
| Robot hors ligne / lecture d'état en échec | Démon, `lire_etat` | `etatLu = False` ⇒ `appliquerCapacites`/`appliquerValeurs` non appelées ⇒ **valeurs de station conservées**, comme l'état robot. Comportement UC07 inchangé. |
| Compte non lié / ré-authentification requise | PHP, avant appel démon | Codes `NOT_AUTHENTICATED` / `AUTH_EXPIRED` existants. Aucun chemin nouveau. |

**Typage des exceptions** : **aucune exception nouvelle, aucun code d'erreur nouveau.** UC13 est en lecture
seule et se greffe sur des chemins qui ont déjà leur classement d'erreurs. `jeeroborockException` et
`estErreurCanal()` ne sont pas touchées.

**Secrets** : aucune donnée sensible nouvelle. `dock_type`, `dock_error_status` et les statuts d'entretien
ne sont ni des identifiants ni des jetons. Le `DeviceManager` (porteur des `local_key`) n'est ni sérialisé
ni journalisé. **Aucun `exc_info=True` ajouté** sur un chemin qui enveloppe une exception de la librairie.
Les nouveaux logs ne portent que des **identifiants d'enum de la librairie** et des `duid`, jamais une
chaîne venue du cloud.

**Méta-séquences fatales** : aucun fichier **rendu** (`desktop/`, `plugin_info/configuration.*`,
`core/template/`) n'est touché ⇒ le piège de la double accolade ouvrante ne s'applique pas. Les nouvelles
littérales PHP et Python ne contiennent ni séquence de fermeture de commentaire bloc, ni balise fermante
PHP. Lancer `python .claude/scripts/verif-plugin.py` (colonne `meta=`) **avant chaque commit**.

**Fins de ligne** : `resources/demond/*.py` en **LF** ; `core/class/*.php` en **CRLF**, 2 espaces.

## i18n (FR uniquement)

Six littérales `__('…', __FILE__)`, **toutes dans la table `definitionsCommandes()`** de
`core/class/jeeroborock.class.php`, jamais au point d'usage :

1. `État vidage poussière` · 2. `État lavage serpillière` · 3. `État séchage serpillière` ·
4. `Erreur station` · 5. `Code d'erreur station` · 6. `Manque d'eau`

**Aucune autre chaîne.** Rien dans `jeeroborockDaemon::tableMessages()`, rien dans un fichier rendu, rien
en JS.

> ⚠️ **Écart assumé avec la section « Impact i18n » de la spec fonctionnelle.** La spec anticipe aussi les
> *libellés d'état* (« en cours », « terminé », « au repos ») comme chaînes UI. **Ce n'en sont pas** :
> conformément au précédent UC07 (`etat`, `erreur`), ces libellés sont des **données françaises produites
> par le démon Python** et écrites telles quelles dans la valeur de la commande. Ils ne passent **jamais**
> par `__()` ni `{{…}}`, ne figurent pas dans `core/i18n/*.json` et échappent par construction au
> sous-agent `translator` (scan statique du PHP/JS). **Conséquence assumée : sur une Jeedom en anglais, les
> valeurs de `station_vidage`/`station_lavage`/`station_sechage`/`station_erreur` resteront en français** —
> exactement comme `etat` et `erreur` depuis UC07. Traiter cela serait une UC transverse (traduction des
> libellés du démon), pas UC13.

## Risques

1. **`RoborockDockErrorCode` n'a pas de membre `unknown`** (vérifié : `RoborockDockErrorCode(999)` → `ok`,
   `0`). **Une erreur de station inconnue de la 7.8.0 se présente comme « aucune erreur »** — donc un
   incident réel invisible, y compris pour un scénario. Non corrigeable sans réimplémenter la
   désérialisation. Identique au piège d'UC07 sur `RoborockErrorCode`. → recette n° 6.
2. **Corollaire de journalisation** : la seule trace (`Missing RoborockDockErrorCode code: N`) est émise en
   `WARNING` par `roborock.data.code_mappings`, et `brider_loggers_tiers()` pose
   `setLevel(max(niveau_racine, INFO))` sur le logger `roborock`. **Au niveau de log Jeedom par défaut
   (`error`), `max(ERROR, INFO) = ERROR` ⇒ la trace est filtrée** et un code inconnu ne laisse **aucune
   trace nulle part**. → recette n° 6.
3. **Un `dock_type` plus récent que la 7.8.0 → `unknown` (-9999) → `has_dock = False` → aucune commande de
   station** (vérifié). Se présente à l'utilisateur **exactement** comme une station basique. Depuis
   D-13-2b, le niveau de repli de `_dock()` est journalisé, ce qui rend le cas **diagnosticable** —
   toujours sous réserve du niveau de log (R2). Contrepartie assumée du critère de D-13-2 ; l'alternative
   (« dock inconnu ⇒ on suppose tout supporté ») créerait des tuiles vides sur des robots sans station, ce
   qu'AC3 interdit. → recette n° 1.
4. **Sémantique des 4 entiers non documentés** (`dust_collection_status`, `wash_status`, `wash_phase`,
   `wash_ready`) : **aucune source ne l'atteste** — ni la librairie, ni Home Assistant, ni la documentation
   amont. Les branches « Terminé » reposent sur une **inférence explicitement marquée**. Les branches « En
   cours » et « Au repos » sont sourcées et restent correctes même si l'inférence est fausse. → recette
   n° 2 et 3.
5. **Le `dock_type` du Qrevo Curv n'est pas déterminable statiquement** : la librairie ne mappe pas `a135`
   vers un `dock_type` (c'est le robot qui l'annonce dans `get_status`). Hypothèse : n'étant ni 0, ni
   -9999, ni 1/5, ni 2, ni 3, il tombe dans `is_collect_wash_dry` ⇒ les **trois** commandes d'entretien
   sont créées. → recette n° 1.
6. **`merge_trait_values()` peut remettre un champ à `None`** : parade en D-13-6. Résiduel : si le robot
   cesse **durablement** de reporter `dock_error_status` alors qu'une erreur est réellement présente, le
   plugin affichera « aucune erreur ». Compromis assumé — l'inverse (figer la dernière erreur pour
   toujours) casse AC5, qui est un critère d'acceptation.
7. **Famine du sondage par le push** (hérité d'UC10, **non introduit ici**) : `_sonde()` ne fait un
   `status.refresh()` que lorsque `evenement.wait()` **expire**. Des pushes répétés à un rythme plus serré
   que la cadence retarderaient la relecture des champs de station (`dry_status` excepté, qui est poussé).
   Risque borné en pratique, et déjà vrai pour `clean_area`/`clean_time`/`clean_percent`. **Pas corrigé
   ici** (hors périmètre, correction structurelle qui toucherait la boucle d'UC10).
8. **Encombrement du dashboard** : +5 tuiles visibles, soit jusqu'à ~22 par robot avec UC07+UC12.
   `isVisible = 1` est retenu parce qu'AC1/AC2/AC4 exigent que l'information soit *visible*, et parce
   qu'AC3 se vérifie par l'**absence** de tuile. **UC15 (widget tuile) regroupera** — c'est sa raison
   d'être.
9. **`roborock.devices` est déclaré « experimental and subject to breaking changes without notice »**
   (`devices/device.py` l.2-5), et `device_features.dock_features` en fait partie. Parade : niveaux 2 et 3
   de `_dock()`, **journalisés** (D-13-2b) ⇒ ce n'est plus un repli silencieux mais une **limite connue et
   observable**. Version épinglée 7.8.0.
10. **`RoborockDockFeatures` n'est pas réexporté** : un import depuis la racine lèverait un `ImportError`
    **au chargement de `robots.py`**, donc **un démon qui ne démarre plus du tout**, pour une feature
    d'affichage — et aucune vérification statique du dépôt ne le verrait. Chemin complet obligatoire.
11. **Changement de signature de `valeurs_etat()`** : 3 sites d'appel, dont un dans `supervision.py`. Un
    site oublié lèverait un `TypeError` **dans la boucle de la sonde**, avalé par le `try/except`
    d'itération ⇒ sonde qui tourne à vide toutes les 120 s en journalisant une erreur interne. À vérifier
    en relecture (3 occurrences de `valeurs_etat(`).
12. **Plage d'ordre** : la « réserve UC12-15 » annoncée par UC12 (12-16) a été entièrement consommée par
    UC12. Base 110 retenue, et le commentaire l.99 rectifié pour qu'UC14 ne retombe pas dans le piège.
    Conséquence cosmétique : les tuiles de station s'affichent après les boutons de réinitialisation tant
    que l'utilisateur ne réordonne pas.
13. **Double signalement d'une même cause physique** : sur une station de lavage, un réservoir d'eau propre
    vide peut allumer **à la fois** `station_manque_eau` (via `water_shortage_status`) **et**
    `station_erreur` (via `dock_error_status = water_empty(38)`). Non gênant — les deux disent la même
    chose et se résorbent ensemble — mais à mentionner en recette pour ne pas le prendre pour un bug.
    → recette n° 5.

## Recette (à confirmer contre le matériel réel — ne se lève pas en review)

1. **AC3 / capacités & `dock_type` réel** (couvre R3, R5) — relever au journal le `dock_type` du Qrevo Curv
   et **le niveau de repli de `_dock()`** (aucun log = niveau 1 nominal ; un `info` = niveau 2 ; un
   `warning` = niveau 3). Vérifier que les 3 commandes d'entretien + l'erreur de station + le manque d'eau
   apparaissent bien. **Consigner le code** : il n'est pas déductible de ce dépôt.
2. **AC1 / sémantique du vidage** (couvre R4) — lancer un vidage automatique : `station_vidage` doit passer
   à « En cours » pendant que `etat` affiche « Vidage du bac à poussière », puis retomber. **Noter la
   valeur de `dust_collection_status` avant / pendant / après** — c'est ce qui valide ou infirme la branche
   « Terminé ».
3. **AC2 / sémantique du lavage et du séchage** (couvre R4) — déclencher un lavage puis un séchage depuis
   l'application mobile. **Noter `wash_status`, `wash_phase`, `wash_ready`, `dry_status` à chaque phase.**
4. **AC4 / distinction station vs robot** — provoquer une erreur de station réelle (retirer le bac de la
   station) : `station_erreur` doit porter un libellé FR **mentionnant explicitement la station**,
   `station_erreur_code` un entier non nul, et `erreur`/`erreur_code` (robot) doivent rester inchangées.
   Vérifier qu'une condition de scénario sur `station_erreur_code != 0` ne se déclenche **pas** sur une
   erreur robot, et réciproquement.
5. **AC5 / retour automatique** (couvre R13) — remettre le bac : sans aucune action dans Jeedom,
   `station_erreur` doit repasser à vide et `station_erreur_code` à 0 **dans un délai ≤ 60 s** (cadence de
   la sonde au repos). Idem pour le réservoir d'eau et `station_manque_eau`. Observer au passage si un
   réservoir vide allume **les deux** informations (comportement attendu, pas un bug).
6. **Codes inconnus** (couvre R1, R2) — **passer le niveau de log du plugin à `warning`** et guetter
   `Missing RoborockDockErrorCode code:` / `Missing RoborockDockTypeCode code:` dans le log du démon
   pendant toute la recette. Sans ce réglage, un code inconnu ne laisse aucune trace.
7. **Non-régression UC07/UC10/UC12** — bouton « Rafraîchir » toujours sous 35 s ; **une seule** construction
   de `DeviceManager` dans le log ; les 5 usures et les 12 commandes d'état toujours écrites ; aucune tuile
   vide.

## Dépendances

**Aucune.** `python-roborock` reste épinglé à **7.8.0** et porte déjà `RoborockDockFeatures`,
`RoborockDockErrorCode` et `RoborockDockTypeCode`. Aucune dépendance pip, npm ou front ajoutée,
`plugin_info/packages.json` inchangé.

## Questions ouvertes

**Aucune.** Les quatre arbitrages qui auraient pu remonter ont été tranchés et validés :

- **Historisation** (laissée ouverte par la spec fonctionnelle) → **non** (D-13-4), réversible sans
  migration.
- **Livrer la nuance « Terminé » malgré une inférence non sourcée** → **oui** : la spec nomme explicitement
  les trois valeurs, et la composition retenue garde « En cours » et « Au repos » corrects même si
  l'inférence est fausse. Marqué « à confirmer », recette n° 2.
- **`station_manque_eau` conditionné à la présence d'une station** → **oui** : AC3 le dit à la lettre et la
  spec range le manque d'eau parmi les incidents de station.
- **Exposer aussi `dust_bag_status` / `clear_water_box_status` / `dirty_water_box_status`** → **non**
  (D-13-5), aucun AC ne les exige et `dock_error_status` couvre déjà les incidents correspondants.

## Hypothèses non vérifiables depuis ce dépôt

- Le **`dock_type` réel du Qrevo Curv** (R5) — le robot l'annonce à l'exécution. Recette n° 1.
- La **sémantique des 4 entiers non documentés** (R4) — aucune source n'existe. Recette n° 2 et 3.
- Les affirmations à numéro de ligne portent sur le wheel `python_roborock-7.8.0` téléchargé, extrait et
  exécuté hors du dépôt (pas de venv local, conforme à « pas de build local » de `CLAUDE.md`) —
  l'implémenteur les reconfirme au moment du codage, comme sur UC12.

## Dette

Les deux reviews du cycle (sécurité et qualité) sont passées **sans aucun finding** — ni
`critical`/`high`, ni `blocker`/`major`, ni même `minor`. Un seul point résiduel, signalé hors mandat par
la review de sécurité et **non corrigé dans ce cycle** :

- **Jeux de journalisation non bornés.** `_REPLIS_DOCK_JOURNALISES` et `_RECOURS_DOCK_JOURNALISES`
  (`robots.py`, niveau module) croissent d'une entrée par `duid`, comme les trois jeux équivalents
  pré-existants de `libelles.py` croissent d'une entrée par clé inconnue. C'est le **prix assumé** du
  « journaliser une seule fois » : sans mémoire, un repli permanent inonderait le log à chaque sondage.
  **Borne réelle** : le nombre de robots du compte pour les deux jeux d'UC13, le nombre de codes distincts
  de la librairie pour ceux de `libelles.py` — soit quelques unités dans les deux cas. Ce n'est pas une
  fuite (pas de croissance liée au temps ni au trafic) et ce n'est pas un enjeu de sécurité. À ne
  reconsidérer que si un futur jeu du même patron venait à être indexé sur une donnée **non bornée**
  (un identifiant de session, un horodatage, un message d'erreur) — là, le patron ne tiendrait plus.
