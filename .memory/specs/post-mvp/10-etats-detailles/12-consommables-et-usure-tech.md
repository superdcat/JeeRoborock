# Spec technique — UC12 : Consommables et usure

> **Spec fonctionnelle** : `12-consommables-et-usure.md` · **Dépend de** : UC03 (canal), UC07 (état du
> robot, `obtenir_appareil`), UC08 (classement des erreurs d'envoi, `session_write_close`),
> UC10 (superviseur), UC11 (état absorbant `reauthRequise`)
> **Contrats externes vérifiés** le 2026-09-19 sur le wheel `python_roborock-7.8.0` extrait et lu
> verbatim, et sur le code réel du dépôt. Plan relu et challengé le même jour : toutes les affirmations
> à numéro de ligne sur le code existant ont été vérifiées et tiennent. Cinq corrections issues de la
> revue sont intégrées ci-dessous et repérées par **« corrigé après revue »**.

## Résumé

Le calcul du pourcentage et la détection du « supporté » vivent **dans le démon** ; le PHP crée/converge
les commandes et écrit des entiers validés. **Une seule** nouvelle opération de canal
(`reinitialiserConsommable`) ; la lecture se greffe sur les deux chemins existants (`lireEtat` d'UC07 et
la sonde d'UC10), sans nouvelle tâche ni nouveau quota.

## Couverture des critères

- **AC1** — `consommables.valeurs()` (démon) calcule un entier borné ; publié dans le lot de
  `supervision._lot()` **et** dans la réponse `lireEtat` → `jeeroborock::appliquerConsommables()` crée les
  commandes info et écrit les valeurs.
- **AC2** — `consommables.capacites()` : critère **unique et strict** `valeur is not None` après un
  premier `consumables.refresh()`. `appliquerConsommables()` ne crée **ni** l'usure **ni** le reset quand
  la capacité est fausse. Rien n'est créé tant qu'aucune lecture n'a abouti.
- **AC3** — `$cmd->setConfiguration('actionConfirm', 1)` sur chaque commande `reset_*` (mécanisme natif
  du cœur). Zéro JS, zéro widget. Annuler le dialog = aucun appel AJAX émis ⇒ usure inchangée.
- **AC4** — `robots.reinitialiser_consommable()` cible **un** attribut puis relit **tous** les compteurs
  dans le même échange ; le PHP réécrit les 5 valeurs réelles (les autres inchangées,
  `checkAndUpdateCmd()` n'émet alors aucun événement).
- **AC5** — `_pourcentage()` borne dans `[0,100]` (`int`), jamais `None` ni chaîne vide, y compris quand
  `work_time > reference` (`Consumable.*_time_left` de la librairie **devient négatif**).
  `subType='numeric'`, `minValue=0`/`maxValue=100` ⇒ utilisable en condition de scénario.
- **AC6** — zéro aller-retour supplémentaire, zéro quota : `ConsumableTrait.reset_consumable()` enchaîne
  lui-même un `refresh()` (source l.60-63) ; l'opération démon renvoie les valeurs post-reset dans la
  **même** réponse RPC, appliquées avant de rendre la main. Le superviseur n'est ni redémarré ni touché.

## Contrats externes (wheel `python_roborock-7.8.0` extrait et lu verbatim)

### Lecture — `ConsumableTrait` (RPC `get_consumable`)

- Trait **toujours présent** en V1, jamais optionnel : `self.consumables = ConsumableTrait()` dans
  `__init__` (`devices/traits/v1/__init__.py` l.208), pas dans `discover_features()`.
- Canal **adaptatif** (local puis MQTT) : pas de décorateur `@mqtt_rpc_channel` → `_get_rpc_channel()`
  renvoie `self._rpc_channel` (`traits/v1/consumeable.py` l.45-53 ; `traits/v1/__init__.py` l.233-243).
- ⚠️ Le trait **n'est PAS rempli au démarrage**. `discover_features()` ne rafraîchit que
  `device_features` + dock ; les 9 champs restent `None` tant qu'aucun `consumables.refresh()` explicite
  n'a eu lieu (`traits/v1/__init__.py` l.268-295).
- Champs (9) dans `data/v1/v1_containers.py` l.343-353 ; les 5 retenus sont en **secondes** d'usage
  cumulé.
- Critère de support **documenté par la librairie elle-même** : « After the first refresh, you can tell
  what consumables are supported by checking which attributes are not None » (`consumeable.py` l.48-49).
- Push dps : seuls `MAIN_BRUSH_WORK_TIME=125`, `SIDE_BRUSH_WORK_TIME=126`, `FILTER_WORK_TIME=127` portent
  une métadonnée dps ; `_on_dps_update()` appelle `consumables.update_from_dps(dps)` ⇒ le trait se met à
  jour **en RAM sans RPC**.
- ⚠️ `ConsumableField` ne déclare que **3** membres ⇒ `is_field_supported(Consumable, …)` est
  **structurellement inappelable** pour `sensor_dirty_time` et `moproller_work_time`.
- ⚠️ `merge_trait_values()` (`traits/v1/common.py` l.91-100) peut **remettre un champ à `None`** : une
  réponse qui omet un champ l'efface du trait.
- Coût quota **nul** : `refresh()` passe par `RpcChannel` ; les seuls limiteurs sont dans `web_api.py`.

### Durées de référence (`roborock/const.py` l.1-10, verbatim)

`MAIN_BRUSH_REPLACE_TIME=1080000` (300 h) ; `SIDE_BRUSH_REPLACE_TIME=720000` (200 h) ;
`FILTER_REPLACE_TIME=540000` (150 h) ; `SENSOR_DIRTY_REPLACE_TIME=108000` (30 h) ;
`MOP_ROLLER_REPLACE_TIME=1080000` (300 h) ; `STRAINER=150` ; `CLEANING_BRUSH=300` ;
`DUST_COLLECTION=90`.

- Les 5 valeurs de `.memory/analyse/jeeroborock-mqtt-protocole.md` § 5 sont **confirmées à l'octet près**.
- ⚠️ **Écart 1** — ce sont des constantes **globales**, **non modulées par produit** : rien ne les indexe
  sur le `model`. Elles s'appliquent à l'`a135` **par défaut**, pas parce qu'elles y auraient été
  validées ⇒ **à confirmer en recette** contre l'application mobile.
- ⚠️ **Écart 2** — `STRAINER=150` / `CLEANING_BRUSH=300` / `DUST_COLLECTION=90` **contredisent** le
  commentaire « in seconds » (150 s = 2,5 min) ; les champs s'appellent `*_work_times` (pluriel =
  compteurs d'occurrences). ⇒ ces 3 consommables sont **exclus d'UC12** (unité ambiguë, et
  `dust_collection` n'est de toute façon pas réinitialisable). Les 5 consommables de la spec sont
  exactement ceux dont l'unité « secondes » est cohérente.
- **Conversion dans le démon** : (1) les durées sont un contrat tiers épinglé, les recopier en PHP
  créerait une 2ᵉ source de vérité désynchronisée à la prochaine montée de version ; (2) précédent UC07
  explicite dans `robots.valeurs_etat()` (« Conversions faites ICI, jamais en PHP ») ; (3) le PHP ne doit
  rien savoir du contrat Roborock (invariant D2). Le démon renvoie un **entier 0-100**.
- ⚠️ `Consumable.main_brush_time_left` & co. (`v1_containers.py` l.355-393) font la soustraction **sans
  borne** et deviennent **négatifs** : violeraient AC5. Volontairement **non utilisées** ; on calcule
  depuis le champ brut + la constante (2 tables au lieu de 3, clamp explicite).

### Réinitialisation — `reset_consumable`

- Référence : `send_command(RoborockCommand.RESET_CONSUMABLE, params=[consumable.value])` **puis**
  `await self.refresh()` (`consumeable.py` l.60-63). RPC `reset_consumable` (`roborock_typing.py` l.156).
- ⚠️ `ConsumableAttribute` ne compte que **6** membres : `sensor_dirty_time`, `filter_work_time`,
  `side_brush_work_time`, `main_brush_work_time`, `strainer_work_times`, `cleaning_brush_work_times`.
  **Pas** `moproller_work_time`, **pas** `dust_collection_work_times`.
- `ConsumableAttribute.from_str(value)` lève `ValueError` sur une valeur inconnue (l.36-42).
- Valeur envoyée = **exactement le nom du champ** du dataclass `Consumable` (vérifié sur les 6 membres).
- **D-12-4** : pour les 4 consommables couverts par l'enum → `from_str()` + `reset_consumable()` (API
  publique, refresh inclus). Pour le **rouleau de serpillière**, aucun chemin typé n'existe ; on reproduit
  **verbatim** ce que fait la librairie : `command.send(RoborockCommand.RESET_CONSUMABLE,
  params=["moproller_work_time"])` puis `consumables.refresh()`. L'inférence sur la chaîne repose sur la
  règle « valeur d'enum = nom de champ » vérifiée sur les 6 membres ⇒ **à confirmer**, non testable sur le
  matériel de référence. Branchement par `try/except ValueError` : si une version ultérieure ajoute le
  membre, le chemin typé reprend la main **sans modification du plugin**.

- ⚠️⚠️ **Portée du `try/except ValueError` — contrainte d'implémentation, pas un détail de style**
  (*corrigé après revue*). Le `try` entoure **exclusivement** l'appel `ConsumableAttribute.from_str(champ)`.
  `reset_consumable()` **doit être hors de ce `try`**. Motif : `reset_consumable()` fait lui-même
  `send_command()` **puis** `await self.refresh()` ; un `ValueError` authentique levé **à l'intérieur** du
  RPC ou du parsing de la réponse — et non par `from_str()` sur une clé absente de l'énum — serait sinon
  avalé et déclencherait le repli, qui **renverrait une seconde commande de reset au robot**. Double
  exécution physique sur le matériel, invisible dans les logs comme une anomalie. Forme imposée :

  ```python
  try:
      attribut = ConsumableAttribute.from_str(champ)
  except ValueError:
      attribut = None
  if attribut is not None:
      await appareil.v1_properties.consumables.reset_consumable(attribut)   # HORS du try
  else:
      await appareil.v1_properties.command.send(RoborockCommand.RESET_CONSUMABLE, params=[champ])
      await appareil.v1_properties.consumables.refresh()
  ```

  Les erreurs des deux chemins d'envoi remontent normalement à `_erreur_envoi()` ; aucune n'est reclassée
  en repli.

### Cœur Jeedom — confirmation d'action (AC3)

Contrat vérifié sur la source `jeedom/core`, consigné dans `.memory/analyse/jeedom-widgets-commandes.md`
§ 4 (l.81-92) :

- serveur : `$cmd->setConfiguration('actionConfirm', 1)` ;
- `core/ajax/cmd.ajax.php`, **avant** `cmd::execCmd()` : si type action + `actionConfirm==1` +
  `init('confirmAction')!=1` → Exception (« Cette action nécessite une confirmation »), code **-32006** ;
- `jeedom.cmd.execute` (JS du cœur) intercepte -32006, affiche `jeeDialog.confirm` (desktop) / `confirm`
  (mobile) et rejoue avec `confirmAction=1`.

⇒ **zéro JS, zéro widget, zéro double-commande**. La chaîne du dialog est traduite **par le cœur** : elle
ne va **pas** dans les i18n du plugin.

⚠️ Ce n'est **pas** une frontière d'autorisation. La garde vit dans le contrôleur AJAX de l'UI web
uniquement. Un scénario Jeedom, l'API JSON-RPC ou un autre plugin appelant `execCmd()` **contournent** le
dialog. AC3 est satisfait **pour le chemin utilisateur**, ce que la spec décrit (« un clic accidentel »).

Deux contrats d'UC08 respectés : **aucune `value` liée** sur les `reset_*` (piège
`isAlreadyInStateAllow()`), et `execute()` retourne un **scalaire**.

### Consommables réellement peuplés sur le Qrevo Curv

Non déterminable statiquement, assumé. `main`/`side`/`filter` portent une métadonnée dps et font partie
du socle V1 historique → **très probablement peuplés** (AC1 cible ces trois-là). `sensor_dirty_time` :
champ V1 hérité, sans métadonnée → probable, non garanti. `moproller_work_time` : le Qrevo Curv utilise
des **patins rotatifs**, pas un rouleau ; `DeviceFeatures.is_roller_mop_supported` (`device_features.py`
l.478, bit `NewFeatureStrBit.ROLLER_MOP`) vaudra très probablement `False` ⇒ **attendu absent**, ce qui
donne à AC2 un sujet de test naturel sur le matériel de référence. Le plan ne dépend d'aucune de ces
hypothèses.

## Architecture — fichiers

| Chemin | État | Contenu | Indentation |
|---|---|---|---|
| `resources/demond/consommables.py` | **créé** | Module de pures données + calcul, sur le modèle de `libelles.py` : `TABLE` (5 entrées), `_pourcentage()`, `capacites()`, `valeurs()`, `bloc()`. **N'enregistre aucune opération**, **n'importe jamais `robots.py`** (évite le cycle d'import). Importe `roborock.const` uniquement. | 4 espaces, LF |
| `resources/demond/robots.py` | modifié | Échéance globale dans `lire_etat` + bloc consommables best-effort ; opération `reinitialiser_consommable()` + liste blanche `RESET_CONSOMMABLES` ; `canal.enregistrer("reinitialiserConsommable", …)`. **Aucune** modification de `ACTIONS`, `envoyer_commande`, `obtenir_appareil`, `_gestionnaire`. | 4 espaces, LF |
| `resources/demond/supervision.py` | modifié | Cycle lent des consommables **dans la `_sonde` existante** (pas de nouvelle tâche) ; `_lot()` attache l'instantané. `demarrer()`, `arreter()`, `_reconcilier()` **strictement inchangées** (idempotence UC10 non touchée). | 4 espaces, LF |
| `resources/demond/jeeroborockd.py` | **inchangé** | `robots.enregistrer_operations()` porte déjà la nouvelle opération ; `consommables.py` est passif. | — |
| `core/class/jeeroborock.class.php` | modifié | 3 constantes ; `definitionsConsommables()` ; `appliquerConsommables()` ; `reinitialiserConsommable()` ; 1 branche dans `appliquerEtatPartiel()` ; 2 lignes dans `rafraichirEtat()` ; 1 test de préfixe dans `jeeroborockCmd::execute()`. | 2 espaces, CRLF |
| `core/class/jeeroborockDaemon.class.php` | modifié | `const TIMEOUT_CONSO_RESET = 35;` + 1 entrée `CONSOMMABLE_INCONNU` dans `tableMessages()` (famille C). | 2 espaces, CRLF |
| `core/class/jeeroborockException.class.php` | **inchangé** | `CONSOMMABLE_INCONNU` est de **famille C** (état métier), pas A ni B ⇒ ne doit **surtout pas** entrer dans `estErreurCanal()`, sinon `erreurLocale()` le refuserait et le dégraderait en `INTERNAL_ERROR`. |
| `core/php/jeeroborock.inc.php` | **inchangé** | Autoload sans objet : aucune classe nouvelle. |
| `.ini`, `packages.json`, `configuration.txt`/`.php`, `desktop/*`, `core/ajax/*`, `core/template/*`, `info.json`, `core/i18n/*` | **inchangés** | Aucune clé de config, aucune dépendance, aucune page, aucun endpoint, aucun widget. Traduction = étape ultérieure. |

## Signatures

### Démon — `consommables.py` (nouveau, passif)

```python
TABLE = collections.OrderedDict([
    ("brossePrincipale",  {"champ": "main_brush_work_time",  "reference": MAIN_BRUSH_REPLACE_TIME}),
    ("brosseLaterale",    {"champ": "side_brush_work_time",  "reference": SIDE_BRUSH_REPLACE_TIME}),
    ("filtre",            {"champ": "filter_work_time",      "reference": FILTER_REPLACE_TIME}),
    ("capteurs",          {"champ": "sensor_dirty_time",     "reference": SENSOR_DIRTY_REPLACE_TIME}),
    ("rouleauSerpillere", {"champ": "moproller_work_time",   "reference": MOP_ROLLER_REPLACE_TIME}),
])

def _pourcentage(travail_s, reference_s) -> int   # borne [0,100] ; reference<=0 -> 100 ; non entier -> 0
def capacites(conso) -> dict                      # 5 booleens ; critere UNIQUE : getattr(...) is not None
def valeurs(conso) -> dict                        # cle ABSENTE quand la valeur est None (jamais 0 par defaut)
def bloc(conso) -> dict | None                    # {"capacites":..., "valeurs":...} ; None si aucun champ peuple
```

⚠️ **D-12-5 — dérogation explicite à la règle D-h d'UC03** (*corrigé après revue*). `libelles.py`
documente la règle « **aucun import de `roborock.*`** : ce module reste chargeable même si
`python-roborock` est absent du venv » (`libelles.py` l.21-23). `consommables.py` **y déroge
délibérément** et importe `roborock.const`, **parce que** les durées de référence sont un contrat tiers
épinglé : les recopier en dur ferait de ce module une seconde source de vérité qui se désynchroniserait
**silencieusement** à la prochaine montée de version — exactement le défaut que D-h ne cherche pas à
prévenir. La règle générale reste valable pour tout module de **pures données de libellé** ; elle ne se
généralise pas en « pures données ⇒ jamais `roborock.*` ».

Contrepartie **obligatoire**, pour ne pas perdre ce que D-h protégeait vraiment (un démon qui démarre
même sans librairie, afin d'afficher une erreur propre plutôt que de mourir à l'import — cf. le garde
`IMPORT_OK` de `session.py`) : **l'import est gardé**.

- `try: from roborock.const import …` / `except ImportError:` → les références valent `None`, et un
  drapeau `REFERENCES_OK = False` est journalisé une fois au chargement ;
- **`TABLE` reste peuplée de ses 5 clés dans tous les cas** — sinon `RESET_CONSOMMABLES` se viderait et
  tout reset deviendrait un `INTERNAL_ERROR` trompeur ;
- référence `None` ⇒ `_pourcentage()` ne calcule pas, la clé est **absente** de `valeurs()`, et `bloc()`
  renvoie `None` si plus rien n'est calculable. Dégradation propre, cohérente avec le fait qu'aucune
  lecture ne peut de toute façon aboutir sans la librairie.

**D-12-2** : `capacites()` **ne prend pas** `device_features` — divergence assumée avec
`robots.capacites_etat()` d'UC07. `ConsumableField` n'expose que 3 des 5 champs (⇒ `is_field_supported()`
inappelable pour les 2 autres) ; et pour les 3 restants, un `True` de schéma avec une valeur absente
créerait une tuile vide, ce qu'AC2 interdit explicitement. Le critère `is not None` est celui que la
**librairie documente** et celui que la **spec formule**. Le piège UC07 « deux familles, ne jamais
unifier » ne s'applique pas : on n'unifie pas, on n'utilise volontairement qu'une famille.

Alternative écartée : `is_roller_mop_supported` pour le rouleau — décrit la capacité **matérielle**, pas
la présence d'un compteur d'usure ; un `True` sans compteur violerait AC2.

### Démon — `robots.py` (modifié)

```python
DELAI_TOTAL_ETAT_S = 30 ; DELAI_CONSO_S = 8 ; RESTE_MINIMAL_CONSO_S = 6
DELAI_TOTAL_RESET_S = 30 ; DELAI_RESET_TYPE_S = 25 ; DELAI_ENVOI_RESET_S = 22 ; DELAI_RELECTURE_CONSO_S = 8

RESET_CONSOMMABLES = frozenset(consommables.TABLE.keys())   # liste blanche FERMEE de 5 cles

async def reinitialiser_consommable(parametres, contexte) -> dict
```

⚠️ **Budget du reset — aligné sur le précédent d'UC08** (*corrigé après revue* : la première rédaction
allouait 12 s **au total** pour deux allers-retours, là où `envoyer_commande` alloue déjà
`DELAI_ENVOI_MAX_S = 22` pour **un seul** envoi plus `DELAI_RELECTURE_S = 8` pour la relecture —
`robots.py` l.76-77). Règle retenue :

- **chemin typé** (`reset_consumable()`, qui enchaîne envoi **et** refresh dans un appel indivisible, donc
  non timeoutable séparément) : un `wait_for` unique borné par `min(DELAI_RESET_TYPE_S, reste)` ;
- **repli non typé** (deux appels distincts) : `min(DELAI_ENVOI_RESET_S, reste)` sur l'envoi, puis
  `min(DELAI_RELECTURE_CONSO_S, reste)` sur le `refresh()` ;
- dans les deux cas `reste = echeance - time.monotonic()`, avec `echeance` posée **en tête** à
  `DELAI_TOTAL_RESET_S` — ce qui couvre aussi `obtenir_appareil()` et `attendre_connexion()` et garantit
  qu'on ne déborde jamais le `TIMEOUT_CONSO_RESET = 35 s` côté PHP.

- `lire_etat` : pose `echeance = time.monotonic() + DELAI_TOTAL_ETAT_S` **en tête**, n'ajoute le bloc
  consommables qu'après le succès du `status.refresh()`, si `echeance - now >= RESTE_MINIMAL_CONSO_S`,
  borné par `min(DELAI_CONSO_S, reste)`. **Best-effort intégral** : toute exception journalisée en
  `info`, clé `consommables` simplement absente. Les timeouts par étape existants sont **inchangés** ⇒
  aucune régression UC07. L'exigence PHP est exprimée en **total** (`TIMEOUT_ETAT = 35 s`) et le nouvel
  appel consomme **le reste**, pas un délai fixe supplémentaire.
- `reinitialiser_consommable` : entrées `userData`/`baseUrl`/`email`/`duid`/`consommable` ; clé hors liste
  blanche → `logging.error` + `ErreurDemon("INTERNAL_ERROR")` (calque d'`envoyer_commande`) ;
  `obtenir_appareil()` **obligatoire** (aucun `create_device_manager()`) ; `attendre_connexion()` sinon
  `DEVICE_OFFLINE` **avant toute RPC** ; envoi via `from_str` + `reset_consumable`, repli
  `command.send(...)` + `refresh()` sur `ValueError`, sous `asyncio.wait_for(min(DELAI_RESET_S, reste))` ;
  erreurs classées par **`_erreur_envoi()` réutilisée telle quelle** (ordre imposé `TimeoutError` →
  `DEVICE_OFFLINE` ; `args[0]` dict avec `code` int → `DEVICE_ACTION_REFUSED` ; sinon
  `code_pour_exception()`) ; retour `{"duid":…, "consommable":<clé>, "consommables": bloc(trait)}` — le
  payload brut `["ok"]` n'est **jamais** renvoyé au PHP.

**D-12-3 (nouvelle opération vs extension)** :

- **`envoyerCommande` n'est pas étendue** : sa liste blanche mappe une clé vers un `RoborockCommand`
  **sans paramètre** et sa relecture post-action est un `status.refresh()`. Un reset prend un
  **paramètre**, cible un **autre trait** et exige une relecture **consommables**. Y faire entrer 5 clés
  forcerait à paramétrer la liste blanche et brancher deux relectures — exactement ce que la liste fermée
  d'UC08 protège. La nouvelle opération a sa **propre** liste blanche fermée de 5 clés.
- **`lireEtat` est étendue** plutôt que doublée par un `lireConsommables` : une opération séparée
  obligerait `rafraichirEtat()` à **deux** appels démon séquentiels (2 × 35 s de budget PHP pour un
  bouton) pour un gain nul, la RPC empruntant le canal **déjà ouvert**. Coût réel ~0,5-1 s, zéro quota.

### Démon — `supervision.py` (modifié)

```python
CADENCE_CONSOMMABLES_S = 3600 ; CADENCE_CONSOMMABLES_ECHEC_S = 300 ; DELAI_CONSO_SONDE_S = 10
```

- Dans `_sonde()`, **en tête de chaque itération** (avant l'attente d'événement) : si
  `time.monotonic() >= echeance_conso` (initialisée à `0.0` ⇒ lecture dès la 1ʳᵉ itération), tenter
  `consumables.refresh()` sous `wait_for`, dans un `try/except` **propre** qui ne peut ni interrompre la
  sonde d'état ni celle d'un autre robot ; succès → `+CADENCE`, échec → `+CADENCE_ECHEC`.
- Dans `_lot()` : `if (bloc := consommables.bloc(appareil.v1_properties.consumables)) is not None:
  lot["consommables"] = bloc`. **Indépendant de `etat_lu`** (cycles distincts). Coût nul si trait vide.
- Bénéfice gratuit : le push dps 125/126/127 met le trait à jour en RAM ; tout lot publié ensuite
  transporte l'instantané à jour **sans RPC**.
- ⚠️ **Aucune publication de lot « consommables seuls »** : `appliquerEtatPartiel()` appelle
  `appliquerConnexion()` **inconditionnellement**, donc un lot sans `connecte` écrirait `connecte = 0`.
  L'instantané voyage toujours attaché à un lot complet.
- Délai avant 1ʳᵉ valeur en régime automatique : `DELAI_AMORCAGE_S` (20 s) + une cadence (30-60 s)
  ≈ **50-80 s** après l'armement. Le bouton « Rafraîchir » donne le chemin immédiat.

### PHP — `jeeroborock.class.php`

```php
const ORDRE_BASE_CONSOMMABLES       = 12;    // info 12-16 (plage reservee UC12-15)
const ORDRE_BASE_RESET_CONSOMMABLES = 100;   // actions 100-104 (routines occupent 30-93)
const PREFIXE_CMD_RESET_CONSO       = 'reset_';

private static function definitionsConsommables()
private function appliquerConsommables($_consommables)   // -> int (creees) ; NE LEVE JAMAIS
public  function reinitialiserConsommable($_cle)         // -> string (message FR scalaire)
```

`definitionsConsommables()` : **une seule** table, 5 lignes, portant **à la fois** l'info et l'action
(impossible de créer un reset orphelin) ; littérales `__()` **dans** la table, jamais `__($variable)`.

| suffixe | `cleDemon` | logicalId info | nom info | logicalId action | nom action |
|---|---|---|---|---|---|
| `brosse_principale` | `brossePrincipale` | `usure_brosse_principale` | Usure brosse principale | `reset_brosse_principale` | Réinitialiser la brosse principale |
| `brosse_laterale` | `brosseLaterale` | `usure_brosse_laterale` | Usure brosse latérale | `reset_brosse_laterale` | Réinitialiser la brosse latérale |
| `filtre` | `filtre` | `usure_filtre` | Usure filtre | `reset_filtre` | Réinitialiser le filtre |
| `capteurs` | `capteurs` | `usure_capteurs` | Usure capteurs | `reset_capteurs` | Réinitialiser les capteurs |
| `rouleau_serpillere` | `rouleauSerpillere` | `usure_rouleau_serpillere` | Usure rouleau de serpillière | `reset_rouleau_serpillere` | Réinitialiser le rouleau de serpillière |

Structure des commandes :

- **info** : `type=info`, `subType=numeric`, `unite='%'`, `generic=''` (aucun type générique du cœur ne
  correspond), `isVisible=1`, `isHistorized=0`, `order=12+rang`, `configuration.minValue=0`/`maxValue=100`.
  Pas de `setTemplate()` (le cœur pose `core::default`).
- **action** : `type=action`, `subType=other`, `isVisible=1`, `order=100+rang`,
  `setConfiguration('actionConfirm', 1)`, **aucun `setValue()`** (piège `isAlreadyInStateAllow()`), aucun
  `setIsHistorized` (forcé à 0 par le cœur).

`appliquerConsommables($_consommables)` : garde `is_array` sur le paramètre, `['capacites']`,
`['valeurs']` ; boucle sur la table avec `try/catch` **par consommable** (log tronqué à 256 car. +
`nettoyerPourLog()` — `cmd::save()` peut embarquer un `print_r($this, true)`) ; capacité fausse →
`continue` (rien créé, rien écrit) ; commande existante → on ne réécrit que le **structurel**
(`type`/`subType`/`unite`/`minValue`/`maxValue` ; `actionConfirm` pour l'action), **jamais**
`name`/`isVisible`/`order`/`isHistorized` (idempotence UC07/UC08) ; valeur : liste blanche **fermée**
(aucune boucle générique sur le payload), `is_numeric` → `intval` → acceptée seulement si `0<=v<=100`,
sinon **ignorée** (jamais de clamp silencieux d'une donnée venue du démon, même politique
qu'`appliquerValeurs()`) ; retourne le nombre de commandes **créées**, agrégé dans `cmdCreees`.

`reinitialiserConsommable($_cle)` — mêmes gardes locales qu'`executerAction()`, **dans cet ordre** :

1. `duidValide()` → `DEVICE_UNKNOWN` ;
2. `estCompteLie()` → `NOT_AUTHENTICATED` ;
3. `reauthRequise()` → `AUTH_EXPIRED` (refus **local** avant tout appel démon : coût réseau et quota
   nuls, UC11/AC5) ;
4. clé absente de la table → `CONSOMMABLE_INCONNU` + `log::add('warning', …)` ;
5. commande `usure_<cle>` inexistante → `CONSOMMABLE_INCONNU` (c'est le « message pédagogique en cas
   d'action indisponible » de la spec) : on ne réinitialise pas un compteur dont on n'a jamais constaté
   l'existence ;
6. `jeeroborockDaemon::appeler('reinitialiserConsommable', array(userData, baseUrl, email, duid,
   consommable), TIMEOUT_CONSO_RESET)` ;
7. `catch` : si `DEVICE_OFFLINE` → `checkAndUpdateCmd('connecte', 0)` puis `throw` (calque
   d'`executerAction()`) ;
8. `appliquerConsommables($r['consommables'])` sous `try/catch` — l'action a réussi, un incident
   d'écriture ne doit pas la faire apparaître en échec ;
9. retourne `sprintf(__('Compteur d\'usure de « %s » réinitialisé.', __FILE__), <nom info>)` —
   **scalaire**.

`appliquerEtatPartiel($_reponse)` — une branche ajoutée, **hors** du garde `etatLu` (cycle et échec
propres) : `if (isset($_reponse['consommables']) && is_array(...)) { $this->appliquerConsommables(...); }`.
Reste `private` ; `traiterPoussee()` est statique **dans la même classe**, la visibilité PHP est bornée à
la classe déclarante — rien à élargir.

`rafraichirEtat()` — après `appliquerValeurs()` : `$creees += $this->appliquerConsommables(...)`.

`jeeroborockCmd::execute()` — test de **préfixe avant** le `switch` (comme `routine_`), sinon un `reset_*`
tomberait dans le `default` → `executerAction()` → `UNSUPPORTED_COMMAND`, message faux. Le
`session_write_close()` sous garde déjà en tête d'`execute()` couvre ce chemin — rien à ajouter, rien à
déplacer.

`dontRemoveCmd()` — **inchangé** : règle générale (commandes protégées), comme UC07/UC08.

### PHP — `jeeroborockDaemon.class.php`

`const TIMEOUT_CONSO_RESET = 35;` (démon borné à 30 s via `DELAI_TOTAL_RESET_S`).

Table des messages, **famille C** : `'CONSOMMABLE_INCONNU' => __('Ce consommable n\'est pas suivi pour ce
robot : rafraîchissez son état avant de le réinitialiser.', __FILE__)`.

## Validation & erreurs

| Situation | Où | Code | Message |
|---|---|---|---|
| Clic accidentel | Client, dialog natif du cœur (`actionConfirm=1` → -32006) | — | chaîne du cœur, non traduite par le plugin |
| Clé hors table PHP | Serveur | `CONSOMMABLE_INCONNU` | « Ce consommable n'est pas suivi… » |
| Commande d'usure jamais créée | Serveur | `CONSOMMABLE_INCONNU` | idem |
| Clé hors liste blanche démon (bug plugin) | Démon | `INTERNAL_ERROR` | « Erreur interne du démon… » |
| Compte non lié / ré-auth requise | PHP, **avant** appel démon | `NOT_AUTHENTICATED` / `AUTH_EXPIRED` | existants |
| Robot injoignable | Démon, avant RPC | `DEVICE_OFFLINE` | existant + `connecte` forcé à 0 |
| Robot refuse le reset (dict `{code:n}`) | Démon, `_erreur_envoi()` | `DEVICE_ACTION_REFUSED` | existant |
| Budget dépassé | Démon / canal | `OPERATION_TIMEOUT` / `DAEMON_TIMEOUT` | existants |
| Valeur hors [0,100] reçue | Serveur | — | ignorée silencieusement (log `debug`), valeur précédente conservée |
| Lecture consommables en échec dans `lireEtat` | Démon, best-effort | — | aucun : clé absente |

**Secrets** : aucune donnée nouvelle sensible. `userData` continue de transiter **à l'intérieur du
tableau de paramètres** de `appeler()` (jamais en argument scalaire : `cmd.ajax.php` sort par
`displayException()` et une trace PHP imprime les arguments de frame). Le `DeviceManager` (porteur des
`local_key`) n'est ni sérialisé ni journalisé. Côté démon, aucun `exc_info=True` sur un chemin qui
enveloppe une exception de la librairie : `trace_sure()` et `textes.texte()` comme partout ailleurs.

## i18n (FR uniquement)

Dans `jeeroborock.class.php` (littérales **dans** les tables de définitions) : Usure brosse principale ;
Usure brosse latérale ; Usure filtre ; Usure capteurs ; Usure rouleau de serpillière ; Réinitialiser la
brosse principale ; Réinitialiser la brosse latérale ; Réinitialiser le filtre ; Réinitialiser les
capteurs ; Réinitialiser le rouleau de serpillière ; « Compteur d'usure de %s réinitialisé. » (sprintf,
un seul `%s`, aucun `%` littéral → pas de piège `%%`).

Dans `jeeroborockDaemon.class.php` : le message `CONSOMMABLE_INCONNU`.

**Non incluse** : la chaîne du dialog de confirmation, fournie **et traduite par le cœur**.

Aucune littérale ne contient de méta-séquence fatale ; aucun fichier **rendu** n'est touché.

## Risques

1. **Réinitialisation du rouleau non vérifiable** (inférence sur la chaîne + `a135` sans rouleau) — à
   confirmer, sans blocage ; `try/except ValueError` rend le futur membre transparent.
2. **Durées de référence non spécifiques au modèle** → écart possible avec l'app mobile. **Non
   paramétrables** en UC12 (aucune clé de config). Si la recette révèle un écart, c'est une UC à part
   entière.
3. **`actionConfirm` forcé aussi à la convergence** : un utilisateur qui décoche la confirmation la verra
   rétablie au rafraîchissement suivant. Assumé : AC3 est un critère d'acceptation, pas une préférence ;
   une propriété de sécurité ne doit pas dépendre d'un état non déterministe.
4. **`actionConfirm` n'est pas une frontière d'autorisation** (scénario / JSON-RPC / `execCmd`
   contournent).
5. **`merge_trait_values()` peut remettre un champ à `None`** : un consommable peut « disparaître ».
   Politique : on ne **supprime jamais** une commande d'usure (un scénario peut la référencer, son
   historique existe) ; sa valeur cesse d'être écrite. Conséquence : une commande créée à tort sur une
   lecture partielle reste, et `dontRemoveCmd()` la rend non supprimable par l'icône du cœur (seul
   chemin : `eqLogic.ajax.php`). Risque borné par le critère strict `is not None`.
6. **`roborock.devices` déclaré « experimental and subject to breaking changes without notice »**
   (`device.py` l.2-5). Version épinglée 7.8.0 ; une rupture ferait échouer l'opération avec un code
   stable et un message, pas planter le démon.
7. **Latence ajoutée au bouton « Rafraîchir »** (~0,5-1 s). Budget épuisé → bloc sauté silencieusement.
8. **Encombrement dashboard** : jusqu'à 10 tuiles (8 en pratique sur l'`a135`). `isVisible=1` retenu pour
   garder AC3/AC4 vérifiables au dashboard ; UC15 regroupera.
9. **Extension future UC13** : le cycle lent est **une** échéance dans `_sonde` ; une 2ᵉ famille lente
   demandera une table d'échéances. À prévoir, pas à implémenter.
10. **Cadence 1 h en dur** (cohérent avec UC10, cadences 30/60 s également en dur).
11. **Latence ajoutée à la sonde périodique** (*ajouté après revue*). Le cycle lent inséré en tête
    d'itération de `_sonde()` peut retarder le sondage d'état de cette itération-là de jusqu'à
    `DELAI_CONSO_SONDE_S` (10 s), **une fois par heure et par robot**. Impact réel faible, mais réel : le
    risque 7 ne couvrait que le bouton « Rafraîchir ».

**Écart signalé entre sources** : `.memory/analyse/jeeroborock-mqtt-protocole.md` § 5 annonce que le %
« se calcule côté plugin **ou** côté démon » et liste 8 attributs sans distinguer les unités. La source
(`roborock/const.py`) montre que 3 des 8 sont des compteurs d'occurrences malgré le commentaire « in
seconds ». Le § 5 écrit aussi « Réinitialisation : `reset_consumable(ConsumableAttribute.<X>)` » comme si
tous les attributs y étaient, alors que l'enum n'a que 6 membres et **pas** le rouleau. ⇒ correction à
porter en capitalisation mémoire.

## Recette

1. **Démarrage à froid** : démon relancé, compte lié, robot en ligne → commandes d'usure seules en
   50-80 s.
2. **AC2 par le rouleau** : aucune commande `usure_rouleau_serpillere` / `reset_rouleau_serpillere` sur
   l'`a135`.
3. **AC3** : clic → dialog ; Annuler → valeur inchangée ; Confirmer → 100 % et les 4 autres inchangées.
4. **Robot éteint** : « Le robot est hors ligne… » + `connecte` à 0 ; aucune RPC émise.
5. **Non-régression UC07/UC10** : bouton « Rafraîchir » sous 35 s ; **une seule** construction de
   `DeviceManager` dans le log ; sondes des autres robots non interrompues.
6. **Concordance mobile** : comparer les pourcentages avec l'application Roborock, consigner l'écart.
7. **AC5 — exploitabilité en scénario** (*ajouté après revue* : aucun point ne couvrait AC5).
   Créer une condition de scénario Jeedom sur une commande `usure_*` (ex. `< 10`) et vérifier qu'elle
   s'évalue **sans erreur**, y compris quand la valeur observée est `0`. À défaut d'un consommable
   réellement usé sur le matériel de recette, vérifier **structurellement** que la commande est bien
   `subType=numeric` avec `minValue=0`/`maxValue=100` et qu'elle est proposée telle quelle dans le
   sélecteur de condition.

## Dépendances

**Aucune.** `plugin_info/packages.json` est inchangé : `python-roborock 7.8.0` est déjà la dépendance
unique et porte tout ce qu'UC12 utilise (`ConsumableTrait`, `ConsumableAttribute`, `roborock.const`).
Aucun paquet PHP, aucune bibliothèque front.

## Questions ouvertes

**Aucune.** Les trois points « À confirmer » de la spec fonctionnelle ont reçu leur réponse en
§ Contrats externes : durées de référence confirmées verbatim et conversion **dans le démon** ;
consommables peuplés (3 quasi certains, capteurs probables, rouleau attendu absent) ; délai de
rafraîchissement après reset **nul**, la librairie rafraîchissant dans le même échange.

## Hypothèses non vérifiables depuis ce dépôt

Elles se lèvent **en recette**, pas en review :

1. Présence réelle de `sensor_dirty_time` et de `moproller_work_time` sur l'`a135`.
2. Acceptation par le robot de `RESET_CONSUMABLE` avec le paramètre littéral `"moproller_work_time"`
   (inférence de la règle « valeur d'enum = nom de champ ») — non testable, le Qrevo Curv n'ayant très
   probablement pas de rouleau.
3. Concordance des pourcentages calculés avec ceux de l'application mobile, les durées de référence
   n'étant pas indexées sur le modèle.

## Dette

**Aucune.** Les deux tours de review n'ont produit qu'**un seul** finding (`blocker` côté qualité,
`high` côté sécurité), corrigé au tour 1 ; le tour 2 est revenu vert des deux côtés, sans régression et
sans finding résiduel sous la gate.

### Défaut trouvé en review, et ce qu'il apprend

`reinitialiser_consommable()` passait la **clé externe** (`brossePrincipale`…) à
`ConsumableAttribute.from_str()` **et** en `params=[…]` de la RPC, au lieu du **nom de champ Roborock**
(`main_brush_work_time`…) pourtant stocké dans `consommables.TABLE[cle]["champ"]` — jamais lu. Le
correctif est `nom_champ = consommables.TABLE[cle]["champ"]`, résolu juste après la garde de liste
blanche, plus le renommage de la variable `champ` en `cle`.

Deux enseignements réutilisables :

1. **Le nommage a causé le défaut.** Une variable appelée `champ` qui contient une **clé** rend le bug
   invisible à la relecture : le code *se lit* correct. Partout où une table fait correspondre une clé
   externe à un nom de contrat tiers, les deux variables doivent porter des noms qui ne peuvent pas être
   confondus (`cle` / `nom_champ`).
2. **Un repli conçu pour un cas particulier devient universel sans le dire.** D-12-4 ne prévoyait le
   chemin non typé que pour le rouleau de serpillière ; le défaut le faisait emprunter par **les cinq**
   consommables, et rien ne le signalait — ni une exception, ni un log, ni le docstring, qui continuait
   d'affirmer le comportement prévu. Quand un repli est réservé à un cas minoritaire, il mérite un log
   `info` qui le dit, pour qu'un repli devenu systématique se voie dans le journal du démon.

### Vérification de contrat qui ne s'est pas faite toute seule

`ConsumableAttribute` n'est **pas** réexporté par `roborock/__init__.py` (vérifié sur la 7.8.0 : le
module ne réexporte que `data`, `exceptions` et `roborock_typing`). Il s'importe par son chemin complet,
`from roborock.devices.traits.v1.consumeable import ConsumableAttribute`, comme le fait la librairie
elle-même dans son `cli.py`. Un import depuis la racine aurait levé un `ImportError` au chargement de
`robots.py` — donc un **démon qui ne démarre plus du tout**, pour une feature d'affichage de
consommables, et sans qu'aucune vérification statique du dépôt puisse le voir.
