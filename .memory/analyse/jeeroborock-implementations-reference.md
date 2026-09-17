# JeeRoborock — Implémentations de référence (où lire le contrat quand on doute)

> **Roborock ne publie aucune documentation d'API.** La source de vérité est donc du **code
> d'implémentation**. Ce fichier dit **où** chercher quoi, pour éviter de re-parcourir trois dépôts à
> chaque incertitude. **Date de vérification** : 2026-09-17.

---

## 1. Hiérarchie des sources

| Rang | Source | Rôle |
|---|---|---|
| 1 | **`python-roborock`** (dépendance du plugin) | **Source de vérité opérationnelle** : c'est le code qui s'exécutera. En cas de doute, lire la version **exactement épinglée dans `packages.json`**, pas `main`. |
| 2 | **Intégration Home Assistant `roborock`** | Montre comment consommer la lib « en production » : flux de login, persistance des credentials, ré-authentification, cadences de polling, gestion des indisponibilités. |
| 3 | **`copystring/ioBroker.roborock`** (MIT, actif) | Réimplémentation **indépendante** en TypeScript : utile pour **corroborer** un comportement, et surtout pour les **tables par modèle** (dont `a135`) que python-roborock calcule dynamiquement. |
| 4 | `Python-roborock/Roborockmitmproxy` | Notes de rétro-ingénierie du protocole (cité par la lib pour l'appairage). Dernier recours. |

## 2. Récupérer la version exacte de la lib (reproductible)

```bash
pip download python-roborock==<version> --no-deps -d /tmp/pr   # ou
curl -s https://pypi.org/pypi/python-roborock/json              # métadonnées + liens de wheels
```
Le wheel est une archive zip : `python_roborock-<v>-py3-none-any.whl` → dézipper et lire `roborock/`.
Le code source complet (tests, `examples/`, `docs/DEVICES.md`) n'est que sur GitHub.

## 3. Table « incertitude → fichier à lire »

### `python-roborock` (arborescence du paquet `roborock/`)

| Question | Fichier |
|---|---|
| Login (code e-mail v4, mot de passe), `getUrlByEmail`, signature Hawk, homedata, **scènes/routines**, jobs, OTA, produits, **quotas** | `web_api.py` |
| Modèles de données cloud : `UserData`/`RRiot`, `HomeData*`, `HomeDataScene`, `HomeDataSchedule`, `RoomMapping` | `data/containers.py` |
| Exceptions typées (à mapper en messages utilisateur) | `exceptions.py` |
| Paramètres MQTT (host/port/user/pass dérivés de `rriot`), encodeurs/décodeurs | `protocol.py` |
| Numéros de protocole (101/102/301) et **codes dps de push** (120-135) | `roborock_message.py` |
| Session MQTT partagée, reconnexion, `unauthorized_hook` | `mqtt/roborock_session.py`, `mqtt/session.py` |
| Topics MQTT d'un robot | `devices/transport/mqtt_channel.py` |
| Choix local/cloud, backoff, souscription V1 | `devices/rpc/v1_channel.py` |
| Cycle de vie d'un robot (`start_connect`, `close`, `is_connected`, callbacks) | `devices/device.py` |
| Découverte des robots, choix V1/A01/B01, création des traits, cache | `devices/device_manager.py` |
| Liste des traits V1 et **logique de capacités optionnelles** | `devices/traits/v1/__init__.py` |
| États du robot (`StatusV2`), consommables, journal, cartes, pièces | `data/v1/v1_containers.py` + `devices/traits/v1/{status,consumeable,clean_summary,maps,map_content,rooms}.py` |
| **Routines** (get/execute) côté trait | `devices/traits/v1/routines.py` |
| Codes d'état / d'erreur / de station | `data/v1/v1_code_mappings.py` |
| Modes aspiration/eau/itinéraire + modes de nettoyage haut niveau | `data/v1/v1_clean_modes.py` |
| Noms de commandes RPC (`app_start`, `app_segment_clean`, …) | `roborock_typing.py` |
| Modèles connus, durées de vie des consommables | `const.py` |
| Exemple complet de bout en bout (login → device manager → statut) | `examples/example.py` (GitHub) |
| Simulateurs (utiles pour tester sans robot) | `testing/` (`v1_simulator.py`, `cloud.py`) |

### Home Assistant (`homeassistant/components/roborock/`, branche `dev`)

| Question | Fichier |
|---|---|
| Quel flux de login est réellement viable, gestion des CGU/région, **ré-authentification** | `config_flow.py` |
| Que persister (`user_data`, `base_url`), quand déclencher une reauth | `__init__.py` |
| Cadences de polling, bascule nettoyage/repos, indisponibilité | `coordinator.py` |
| Constantes d'intervalles, paramètres carte (échelle, format PNG) | `const.py` |

### ioBroker.roborock (`src/`, branche `main`)

| Question | Fichier |
|---|---|
| **Capacités et tables de valeurs du Qrevo Curv** (`fan_power` jusqu'à 108 « Max+ », `water_box_mode`, `mop_mode`, présets de mode) | `lib/features/vacuum/a135_features.ts` |
| Tables de base partagées `BASE_FAN` / `BASE_WATER` / `BASE_MOP` et socle V1 | `lib/features/vacuum/v1VacuumFeatures.ts` |
| Endpoints HTTP cloud vus par une autre implémentation | `lib/httpApi.ts` |
| Trames MQTT / chiffrement | `lib/mqttApi.ts`, `lib/cryptoEngine.ts`, `lib/messageParser.ts` |
| Rendu de carte V1 (approche alternative au parser Python) | `lib/map/v1/*` |
| Codes d'erreur et libellés (multilingues) | `lib/protocols/roborock_error_codes.json`, `roborock_strings.json` |

## 4. Divergences repérées entre sources (à ne pas arbitrer en silence)

1. **Login par mot de passe** : présent et fonctionnel *en théorie* dans `python-roborock`
   (`pass_login`), **absent** de Home Assistant (code e-mail uniquement), et `pass_login_v3` est
   `NotImplementedError`. → considérer le mot de passe comme non fiable.
2. **Transport local** : `python-roborock` le tente **toujours** pour le V1 ; la demande utilisateur est
   « cloud uniquement ». Aucun commutateur public pour le désactiver en 7.8.0 (cf.
   `jeeroborock-architecture.md` D9).
3. **Tables de modes** : ioBroker les code **par modèle** ; python-roborock les **calcule** depuis les
   capacités. En cas d'écart sur l'`a135`, la vérité d'exécution est celle de python-roborock — ioBroker
   sert de contrôle de vraisemblance.
4. **`HomeDataScene`** n'expose que `{id, name}` côté lib alors que la réponse brute contient plus de
   champs (ignorés au parsing). Ne pas conclure que le cloud ne renvoie rien d'autre.

## 5. Ce qui n'est confirmé par aucune source et devra l'être en recette (Qrevo Curv `a135`)

- Contenu réel d'une routine (`/user/scene/device/<duid>`) : champs au-delà de `{id, name}`.
- Lecture des **programmations** (`/user/devices/<duid>/jobs`) sur un V1.
- Continuité du `header_clientid` entre `request_code` et `code_login` (deux processus ⇒ deux clientid).
- Durée de vie de `rriot` / fréquence réelle des ré-authentifications.
- Le robot est-il joignable en TCP local depuis Jeedom, et coût de la tentative au démarrage.
- Capacités exactes remontées par `discover_features()` sur l'`a135` (quels traits optionnels existent).

## Sources

- https://github.com/Python-roborock/python-roborock — version analysée : **7.8.0** (PyPI, 2026-09-13)
- https://pypi.org/pypi/python-roborock/json
- https://github.com/home-assistant/core/tree/dev/homeassistant/components/roborock
- https://github.com/copystring/ioBroker.roborock (MIT)
- https://github.com/Python-roborock/Roborockmitmproxy
