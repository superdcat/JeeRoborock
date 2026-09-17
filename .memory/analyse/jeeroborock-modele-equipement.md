# JeeRoborock — Modèle Jeedom : eqLogic, commandes, routines

> Traduction du contrat Roborock (cf. `jeeroborock-cloud-api.md`, `jeeroborock-mqtt-protocole.md`) en
> objets Jeedom. **Date** : 2026-09-17. Les libellés sont en **français** (langue source du projet).

---

## 1. eqLogic

**1 eqLogic = 1 robot.** Pas d'eqLogic « compte » au MVP (le cas nominal est 1 à 2 robots ; l'état du
lien cloud est porté par chaque robot).

| Élément | Valeur |
|---|---|
| `logicalId` | **`duid`** (identifiant Roborock stable, invariant au renommage dans l'app) |
| `name` | nom du robot dans l'app à la création, **modifiable ensuite par l'utilisateur** (ne jamais l'écraser lors d'une resynchro) |
| `eqType_name` | `jeeroborock` |
| `isEnable` / `isVisible` | pilotés par l'utilisateur |

**Configuration d'équipement** (`getConfiguration`) — données d'inventaire, **aucun secret** :
`duid`, `model` (`roborock.vacuum.a135`), `productName`, `fv` (firmware), `pv` (`1.0`), `sn` (optionnel),
`shared` (robot partagé par un autre compte), et le **résultat de la découverte de capacités**
(liste des traits/options supportés) qui pilote la création conditionnelle des commandes.

⚠️ **`local_key`, `rriot`, `token` ne sont jamais stockés côté eqLogic** : ils vivent dans le démon
(cf. `jeeroborock-architecture.md` D4).

**Robots non V1** (`pv` = `A01`/`B01`) : ne pas créer d'équipement silencieusement incomplet — les
exclure de la création avec un message clair « modèle non supporté par cette version du plugin ».

## 2. Commandes du socle MVP

Convention : `logicalId` en minuscules sans accent ; libellés français ; `type`/`subType` Jeedom.

### 2.1 Informations

| `logicalId` | Type | SubType | Unité | Source | Notes |
|---|---|---|---|---|---|
| `etat` | info | string | — | `status.state` (libellé calculé par la lib) | valeur lisible (« En nettoyage », « À la base »…) ; traduction côté plugin |
| `etat_code` | info | numeric | — | `status.state` (code) | pour les scénarios ; **ne pas** figer la table de codes côté PHP |
| `batterie` | info | numeric | % | `status.battery` (push dps 122) | type générique Jeedom `BATTERY` |
| `en_nettoyage` | info | binary | — | dérivé (`in_cleaning` / `state`) | |
| `erreur` | info | string | — | `status.error_code` (libellé) | vide si aucune erreur |
| `erreur_code` | info | numeric | — | `status.error_code` | |
| `surface_nettoyee` | info | numeric | m² | `status.clean_area` (cm² → m²) | cycle courant |
| `duree_nettoyage` | info | numeric | min | `status.clean_time` (s → min) | cycle courant |
| `avancement` | info | numeric | % | `status.clean_percent` | **conditionnel** (`is_support_clean_estimate`) |
| `en_ligne` | info | binary | — | `HomeDataDevice.online` / dps `OFFLINE_STATUS` | vu du cloud |
| `connecte` | info | binary | — | `device.is_connected` | canal robot du démon |
| `derniere_maj` | info | string | — | horodatage de la dernière donnée reçue | **fraîcheur** : sert au mode dégradé |

### 2.2 Actions

| `logicalId` | SubType | Commande lib | Notes |
|---|---|---|---|
| `demarrer` | other | `app_start` | |
| `pause` | other | `app_pause` | |
| `arreter` | other | `app_stop` | |
| `retour_base` | other | `app_charge` | |
| `localiser` | other | `find_me` | |
| `rafraichir` | other | `status.refresh()` | force une lecture immédiate |
| `routine_<sceneId>` | other | `execute_routine(<sceneId>)` | **une commande par routine**, cf. § 3 |

Chaque action rend un retour exploitable (`success` / message d'erreur traduit) — cf.
`jeedom-widgets-commandes.md` § 4 pour la remontée du retour PHP côté widget.

Actions sensibles (post-MVP) : mise à jour firmware, réinitialisation de consommable → `actionConfirm=1`.

## 3. Routines / « usages » (exigence MVP)

Une **routine** (« scène » dans l'API, « usage » côté utilisateur) est un scénario de nettoyage paramétré
dans l'app mobile. Contrat : `GET /user/scene/device/<duid>` → `[{id, name}]`, exécution
`POST /user/scene/<id>/execute` (cf. `jeeroborock-cloud-api.md` § 5).

**Décision** : **une commande action Jeedom par routine**, `logicalId = routine_<sceneId>`,
`name = <nom de la routine>`.

- ✅ apparaît telle quelle au dashboard et dans les scénarios Jeedom (UX attendue par l'utilisateur) ;
- ✅ l'id de scène est stable, donc le `logicalId` l'est ;
- ⚠️ impose une **resynchronisation** : routine renommée → mettre à jour le `name` ; routine supprimée →
  **ne pas supprimer silencieusement** la commande (elle peut être utilisée dans un scénario) : la
  marquer comme obsolète (et la faire échouer avec un message explicite), la suppression restant une
  action utilisateur. **À trancher en spec.**

**Alternative complémentaire** (post-MVP, pas MVP) : une commande unique `routine_executer` en
subType **`message`** recevant l'identifiant ou le nom de la routine (cf. commande paramétrée,
`jeedom-widgets-commandes.md` § 4) — utile pour des scénarios génériques, mais moins découvrable.

Cas particulier à couvrir : **aucune routine définie** dans l'app → afficher un message pédagogique
(« créez d'abord un usage dans l'application Roborock »), pas une erreur.

## 4. Création conditionnelle

Le plugin **ne crée que les commandes réellement supportées** par le robot, d'après ce que le démon
rapporte (traits optionnels + `device_features` + schéma produit — cf.
`jeeroborock-mqtt-protocole.md` § 6). Une resynchronisation **ajoute** les commandes nouvellement
détectées sans dupliquer les existantes (clé = `logicalId`).

Pour les commandes à choix (post-MVP : aspiration, eau, itinéraire), les valeurs proposées viennent des
**options dynamiques** (`fan_speed_options`, `water_mode_options`, `mop_route_options`) : pas de liste
codée en dur, sinon un modèle différent du Qrevo Curv exposera des valeurs fantômes.

## 5. Fraîcheur, push et cron

- Le **démon** tient la boucle de rafraîchissement (30 s en nettoyage / 60 s au repos) et pousse les
  changements vers Jeedom via le callback (`jeedom_com`), avec `add_changes` → mise à jour par
  `logicalId`. Seules les **valeurs changées** sont poussées (éviter de saturer l'historique).
- Le **cron PHP** (`cron` ou `cron5`) est un **chien de garde** : démon vivant ? dernière donnée trop
  ancienne (→ basculer `connecte` à 0) ? ré-authentification requise ? Il ne fait **pas** de polling
  métier.
- Robustesse cron : `try/catch` **par équipement** (cf. `CLAUDE.md`).

## 6. Widgets

Le MVP se contente des widgets **par défaut** du core (aucun widget custom). Post-MVP :
- tuile robot (état + batterie + actions) → widget de commande multi-commandes
  (`jeedom-widgets-commandes.md` § 3) ;
- **carte** : image PNG fournie par le démon → servie **same-origin** (proxy ou `data:` URI selon
  widget client vs page panel) à cause de la CSP Jeedom (`jeedom-widgets-commandes.md` § 7,
  `jeedom-panel-page-menu.md` § 4).

## 7. Points à trancher en spec

1. Nommage exact des commandes (français) et types génériques Jeedom à poser (`BATTERY`, etc.).
2. Politique de suppression/obsolescence des commandes de routine disparues (§ 3).
3. Unité et arrondi de `surface_nettoyee` (m², 1 décimale ?) et `duree_nettoyage` (min vs s).
4. Historisation : quelles infos historiser par défaut (batterie oui ; état plutôt non).
5. Faut-il un eqLogic « compte » quand le foyer possède plusieurs robots (statut cloud unique) ?
