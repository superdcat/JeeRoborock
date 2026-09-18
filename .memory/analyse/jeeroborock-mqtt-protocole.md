# JeeRoborock — Canal robot : MQTT, protocole V1, commandes et états

> **Source unique** : lecture du code de `python-roborock` **7.8.0** (aucune doc officielle Roborock).
> **Date** : 2026-09-17. Cible testable : Qrevo Curv `roborock.vacuum.a135` (`pv = "1.0"` → **V1**).
>
> Le plugin n'implémente **pas** ce protocole : il consomme la lib depuis le démon. Ce fichier sert à
> savoir **ce qui est disponible**, **comment ça arrive** (requête vs push) et **ce qui dépend du modèle**.

---

## 1. Connexion MQTT

Paramètres dérivés de `rriot` (`roborock/protocol.py`, `create_mqtt_params`) :

```
url      = parse(rriot.r.m)          # ex. ssl://mqtt-eu-xxx.roborock.com:8883
host/port= url.hostname / url.port   # TLS si scheme == "ssl"
username = md5hex(rriot.u + ":" + rriot.k)[2:10]
password = md5hex(rriot.s + ":" + rriot.k)[16:]
```

- **Une seule session MQTT pour tout le compte**, partagée par les robots
  (`create_lazy_mqtt_session`, `roborock/mqtt/roborock_session.py`) : connexion **paresseuse** (ouverte
  au premier abonnement), keepalive, ré-abonnement automatique après reconnexion, désabonnement après
  inactivité d'un topic.
- Topics (`roborock/devices/transport/mqtt_channel.py`) : publication
  `rr/m/i/<rriot.u>/<username_hashé>/<duid>` ; abonnement sur le topic symétrique `rr/m/o/...`.
- Charge utile **chiffrée avec le `local_key`** du robot (issu de `homedata`) ; encodage/décodage par
  `MessageParser` (`roborock/protocol.py`).
- Un refus d'autorisation MQTT (`RC_ERROR_UNAUTHORIZED`) déclenche `unauthorized_hook` → **signal de
  ré-authentification / de rate-limit côté Roborock** : le plugin doit le traiter, pas le boucler.

## 2. Framing et versions de protocole

`roborock/roborock_message.py` :

| Enum | Valeurs |
|---|---|
| `RoborockMessageProtocol` | `HELLO_REQUEST=0`, `HELLO_RESPONSE=1`, `PING_REQUEST=2`, `PING_RESPONSE=3`, `GENERAL_REQUEST=4`, `GENERAL_RESPONSE=5`, **`RPC_REQUEST=101`**, **`RPC_RESPONSE=102`**, `MAP_RESPONSE=301` |
| `RoborockDataProtocol` (push « dps ») | `ERROR_CODE=120`, `STATE=121`, `BATTERY=122`, `FAN_POWER=123`, `WATER_BOX_MODE=124`, `MAIN_BRUSH_WORK_TIME=125`, `SIDE_BRUSH_WORK_TIME=126`, `FILTER_WORK_TIME=127`, `ADDITIONAL_PROPS=128`, `TASK_COMPLETE=130`, `TASK_CANCEL_LOW_POWER=131`, `TASK_CANCEL_IN_MOTION=132`, `CHARGE_STATUS=133`, `DRYING_STATUS=134`, `OFFLINE_STATUS=135` |

Versions d'appareil (champ `pv` de `homedata`, `DeviceVersion` dans `device_manager.py`) :
`1.0` → **V1** (aspirateurs classiques, dont `a135`) ; `A01` → Dyad/Zeo ; `B01` → Q7 (`sc*`) / Q10
(`ss*`). **Le plugin cible V1** ; les autres catégories lèvent `UnsupportedDeviceError` ou exposent
d'autres traits — à traiter comme « non supporté » proprement, pas à ignorer silencieusement.

## 3. Deux modes d'obtention de la donnée

1. **Requête/réponse (pull)** : `trait.refresh()` envoie une RPC (`get_status`, `get_consumable`,
   `get_clean_summary`, `get_room_mapping`, `get_multi_maps_list`, `get_map_v1`…) et attend la réponse.
2. **Push non sollicité** : le robot publie des `dps` (§ 2) que la lib décode et injecte dans les traits
   via `add_dps_listener` → `status.update_from_dps()` et `consumables.update_from_dps()`
   (`devices/traits/v1/__init__.py`, `_on_dps_update`).

→ Côté Jeedom : le **push alimente les commandes info en temps réel**, le **pull périodique** (dans le
démon) complète les champs non poussés. Cadence de référence (Home Assistant `const.py`) : **30 s en
nettoyage / 60 s au repos** en cloud (15/30 s en local).

Le canal V1 est **adaptatif** : il tente le TCP local et retombe sur MQTT — non désactivable en 7.8.0
(cf. `jeeroborock-architecture.md` D9).

## 4. États disponibles — `StatusTrait` (`get_status`)

Champs de `StatusV2` (`roborock/data/v1/v1_containers.py`) les plus exploitables :

| Champ | Sens | Poussé en dps ? |
|---|---|---|
| `state` (`RoborockStateCode`) | état du robot | oui (121) |
| `battery` | % batterie | oui (122) |
| `error_code` (`RoborockErrorCode`) | erreur robot | oui (120) |
| `fan_power` | puissance d'aspiration (code) | oui (123) |
| `water_box_mode` | débit d'eau (code) | oui (124) |
| `mop_mode` | itinéraire serpillière (code) | non |
| `clean_time` / `clean_area` | durée (s) / surface (cm²) du cycle courant | non |
| `clean_percent` | avancement estimé (si `is_support_clean_estimate`) | non |
| `charge_status`, `in_cleaning`, `in_returning`, `in_fresh_state`, `is_locating` | sous-états | `charge_status` (133) |
| `dock_type` (`RoborockDockTypeCode`), `dock_error_status`, `dust_collection_status`, `wash_status`, `wash_phase`, `wash_ready`, `dry_status` (134), `rdt` | station d'accueil | `dry_status` oui |
| `water_box_status`, `water_box_carriage_status`, `water_shortage_status` | réservoir/serpillière (si `is_support_water_mode`) | non |
| `map_present`, `map_status`, `last_clean_t`, `repeat`, `corner_clean_mode`… | divers | non |

`RoborockStateCode` (extraits) : `1 starting`, `2 charger_disconnected`, `3 idle`, `4 remote_control_active`,
`5 cleaning`, `6 returning_home`, `8 charging`, `9 charging_problem`, `10 paused`, `11 spot_cleaning`,
`12 error`, `13 shutting_down`, `14 updating`, `15 docking`, `16 going_to_target`, `17 zoned_cleaning`,
`18 segment_cleaning`, `22 emptying_the_bin`, `23/25 washing_the_mop`, `26 going_to_wash_the_mop`,
`29 mapping`, `100 charging_complete`, `101 device_offline`, `103 locked`, `202 air_drying_stopping`,
+ une série ≥ 6301 (mopping/segment/zoned variants).

⚠️ **Ne pas figer la liste des codes en dur côté PHP** : la lib la fait évoluer. Exposer le **libellé**
calculé par la lib (le démon renvoie code **et** libellé).

**Modes dynamiques** : `fan_speed_options` / `water_mode_options` / `mop_route_options` sont **calculés
par la lib à partir des capacités découvertes** (`DeviceFeaturesTrait`, schéma produit), pas d'une table
par modèle. Le démon doit donc **exposer les options réellement supportées** pour construire les listes
de choix Jeedom, plutôt que d'inventer des valeurs.

Valeurs de référence pour l'`a135` (source ioBroker, `src/lib/features/vacuum/a135_features.ts`) :
- `fan_power` : 101 Quiet, 102 Balanced, 103 Turbo, 104 Max, **108 Max+** (`maxSuctionValue: 108`) ;
- `water_box_mode` : 200 Off, 201 Mild, 202 Moderate, 203 Intense ;
- `mop_mode` : 300 Standard, 301 Deep, 303 Deep+ ;
- présets « mode de nettoyage » (via `set_clean_motor_mode`) : Vacuum `{102,300,200}`, Mop `{105,300,202}`,
  Vac & Mop `{102,300,202}` ; `hasSmartPlan: true` ;
- capacités déclarées : lavage/séchage serpillière, station à vidage auto, `CleanPercent`, `CleanRepeat`,
  `ShakeMopStrength`, `AvoidCarpet`, `MopForbidden`, `LiveVideo`, `SmartModeCommand`, `DockStatus`…

Côté `python-roborock`, `a135` est reconnu (`const.ROBOROCK_QREVO_CURV`) et rattaché au profil
`RoborockProductNickname.VIVIAN` (`a134/a135/a155/a156`).

### ⚠️ 4.bis Un code inconnu est écrasé SILENCIEUSEMENT par la librairie (vérifié 7.8.0, UC07)

`RoborockEnum._missing_` (`roborock/data/code_mappings.py` l. 9-40) ne lève jamais sur un code non
répertorié : il retombe sur le membre `unknown` **s'il existe**, et sinon sur le **premier membre déclaré**.

| Enum | Membre `unknown` ? | Conséquence d'un code inconnu |
|---|---|---|
| `RoborockStateCode` | **oui** (`unknown = 0`, `v1_code_mappings.py` l. 403) | l'état devient **0** ; le code brut est **perdu** |
| `RoborockErrorCode` | **NON** (l. 190-245) | retombe sur `none = 0` ⇒ **une erreur inconnue se présente comme « aucune erreur »** |

Seule trace du code réel : la librairie journalise `Missing Roborock<X>Code code: N`. Non corrigeable sans
réimplémenter la désérialisation. **À vérifier en recette** en provoquant une erreur réelle.

Deux autres pièges de ces enums :
- `display_name` rend un **identifiant anglais snake_case** (`"charging_complete"`), **pas** un libellé
  présentable — un libellé utilisateur doit être composé par le plugin.
- Des codes distincts partagent un `display_name` (`washing_the_mop_2 = (25, "washing_the_mop")`,
  `mopping_roller_2 = (45, "mopping_roller_1")`) ⇒ **indexer toute table de libellés sur `display_name`,
  jamais sur `.name`**.

## 5. Consommables — `ConsumableTrait` (`get_consumable`)

`Consumable` : `main_brush_work_time` (125), `side_brush_work_time` (126), `filter_work_time` (127),
`filter_element_work_time`, `sensor_dirty_time`, `strainer_work_times`, `dust_collection_work_times`,
`cleaning_brush_work_times`, `moproller_work_time` — **en secondes d'usage**.

Durées de référence avant remplacement (`roborock/const.py`) : brosse principale 1 080 000 s (300 h),
brosse latérale 720 000 s (200 h), filtre 540 000 s (150 h), capteurs 108 000 s (30 h), rouleau
serpillière 1 080 000 s. → le **% d'usure restant** se calcule côté plugin ou côté démon à partir de ces
constantes.
Réinitialisation : `reset_consumable(ConsumableAttribute.<X>)` (`reset_consumable`), suivie d'un refresh.
Les attributs `None` après un premier refresh = **non supportés** par le modèle.

## 6. Commandes (RPC `RoborockCommand`, `roborock/roborock_typing.py`)

Accès générique : `device.v1_properties.command.send(<commande>, params=…)` (trait `CommandTrait`).

**Pilotage de base (MVP)** : `app_start`, `app_pause`, `app_stop`, `app_charge` (retour base),
`find_me` (localiser), `app_resume_build_map`/`app_wakeup_robot` (annexes).

**Pilotage fin (post-MVP)** : `set_custom_mode` (aspiration), `set_water_box_custom_mode` (eau),
`set_mop_mode` (itinéraire), `set_clean_motor_mode` (mode de nettoyage haut niveau, via
`status.set_cleaning_mode()`), `set_clean_repeat_times`, `app_segment_clean` (pièces),
`app_zoned_clean` (zone), `app_goto_target`, `app_spot`, `resume_segment_clean`, `resume_zoned_clean`,
`app_start_wash` / `app_stop_wash` (serpillière), `app_set_dryer_status` (séchage),
`app_start_collect_dust` / `app_stop_collect_dust` (vidage), `set_sound_volume`, `set_dnd_timer`,
`set_child_lock_status`, `set_led_status`, `load_multi_map`.

**Lecture (post-MVP)** : `get_clean_summary` / `get_clean_record` (journal), `get_room_mapping` (pièces),
`get_multi_maps_list` (cartes), `get_map_v1` (carte), `get_network_info`, `get_dnd_timer`,
`get_sound_volume`, `get_dust_collection_mode`, `get_wash_towel_mode`, `get_smart_wash_params`.

⚠️ **Toutes ces commandes ne sont pas supportées par tous les modèles.** La lib expose des traits
**optionnels** créés seulement si la capacité est détectée (`requires_feature`, `requires_dock_features`,
`DeviceFeaturesTrait.is_field_supported`) : `child_lock`, `led_status`, `flow_led_status`,
`valley_electricity_timer`, `dust_collection_mode`, `wash_towel_mode`, `smart_wash_params`,
`obstacle_photos`. → **création conditionnelle des commandes Jeedom** à partir de ce que le démon
rapporte, jamais d'une liste figée.

Traits toujours présents en V1 : `status`, `command`, `dnd`, `clean_summary`, `sound_volume`, `rooms`,
`maps`, `map_content`, `consumables`, `home`, `device_features`, `network_info`, **`routines`**.

## 7. Cartes et pièces (post-MVP — le plus coûteux)

- `RoomsTrait` (`get_room_mapping`) → `NamedRoomMapping{segment_id, iot_id, name}` : le **segment_id** est
  ce qu'attend `app_segment_clean`, le **nom** vient du cloud (`/user/homes/<id>/rooms`), recroisé
  automatiquement par la lib.
- `MapsTrait` (`get_multi_maps_list`, **MQTT obligatoire** — décoré `@common.mqtt_rpc_channel`) →
  `MultiMapsList{map_info[]{map_flag, name, rooms[]}}` : multi-étages.
- `MapContentTrait` (`get_map_v1`, canal carte dédié) → `MapContent{image_content: bytes (**PNG déjà
  rendu**), map_data, raw_api_response}` grâce à `vacuum-map-parser-roborock`.
  → Côté Jeedom : PNG à faire transiter du démon vers PHP (base64) puis à servir **same-origin**
  (la CSP Jeedom bloque les médias externes — cf. `jeedom-widgets-commandes.md` § 7 et
  `jeedom-panel-page-menu.md` § 4). Rafraîchissement raisonnable : ~30 s (référence HA
  `IMAGE_CACHE_INTERVAL`), jamais à chaque tick.

## 8. Journal de nettoyage (post-MVP)

`CleanSummaryTrait` (`get_clean_summary`) → `CleanSummary{clean_time, clean_area, clean_count,
dust_collection_count, records[] (ids), last_clean_t}` ; chaque id se détaille via `get_clean_record` →
`CleanRecord{begin, end, duration, area, error, complete, start_type, clean_type, finish_reason,
dust_collection_status, avoid_count, wash_count, map_flag}`.

## 9. Cycle de vie côté démon

```
UserParams(username, user_data, base_url)
  → create_device_manager(user_params, cache=…, prefer_cache=True)
      → homedata → pour chaque device : canal (V1/A01/B01) + traits + start_connect()
  → device.v1_properties.status.refresh() / .command.send(...) / .routines.execute_routine(id)
  → device_manager.close()
```
- `start_connect()` ne échoue **jamais** franchement : reconnexion en tâche de fond avec backoff
  exponentiel (10 s → 30 min, ×1,5) ; première tentative bornée à 15 s.
- `device.is_connected` / `is_local_connected` → à remonter en info Jeedom.
- `add_ready_callback()` → moment idéal pour publier l'état initial vers Jeedom.
- Un **cache** (interface `Cache`, impl. `FileCache`) évite de refaire `homedata` et la découverte des
  capacités à chaque démarrage : à placer dans un répertoire du plugin, **contenant des secrets**
  (`local_key`) → droits restreints, hors web (`.htaccess`).

## Sources

- `python-roborock` 7.8.0 : `roborock/protocol.py`, `roborock/roborock_message.py`,
  `roborock/mqtt/roborock_session.py`, `roborock/devices/transport/mqtt_channel.py`,
  `roborock/devices/rpc/v1_channel.py`, `roborock/devices/device.py`, `roborock/devices/device_manager.py`,
  `roborock/devices/traits/v1/*` (`status`, `consumeable`, `command`, `rooms`, `maps`, `map_content`,
  `clean_summary`, `routines`), `roborock/data/v1/v1_containers.py`, `roborock/data/v1/v1_code_mappings.py`,
  `roborock/data/v1/v1_clean_modes.py`, `roborock/roborock_typing.py`, `roborock/const.py`.
- ioBroker.roborock : `src/lib/features/vacuum/a135_features.ts`, `src/lib/features/vacuum/v1VacuumFeatures.ts`
  (valeurs `BASE_FAN` / `BASE_WATER` / `BASE_MOP`).
- Home Assistant `dev` : `homeassistant/components/roborock/const.py` (intervalles), `coordinator.py`.
