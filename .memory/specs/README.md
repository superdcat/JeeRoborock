# Specs — Feuille de route de JeeRoborock

> Roadmap du plugin `jeeroborock`, produite au cadrage (`/init-plugin`, 2026-09-17) : **31 cas d'usage**,
> un socle MVP de 9 UC puis 5 domaines post-MVP. L'orchestrateur **`/feature <spec>`** consomme une spec
> fonctionnelle, écrit la spec technique à côté, puis fait implémenter et reviewer.

## Convention

Une feature = deux fichiers dans le **même dossier** :

- **Spec fonctionnelle** `NN-nom.md` — le **quoi** et le **pourquoi** : contexte, comportement attendu et
  surtout les **critères d'acceptation** (la *definition of done*, vérifiables). C'est l'entrée du
  workflow ; elle est rédigée/validée avec l'utilisateur.
- **Spec technique** `NN-nom-tech.md` — le **comment** : architecture, fichiers à créer/modifier, décision
  server/client, signatures d'actions AJAX, validation, dépendances. Elle est **produite par
  l'orchestrateur `/feature`**, après validation du plan par l'utilisateur, puis consommée par l'agent
  `php-jeedom-dev`.

La numérotation des UC est **continue de 01 à 31** à travers tous les dossiers : un numéro identifie une
UC sans ambiguïté, quel que soit son domaine.

---

## Socle MVP — `MVP/`

L'objectif du MVP est une chaîne démontrable de bout en bout sur un vrai robot : **lier le compte →
découvrir les robots → les piloter → lancer les routines**. Il s'arrête volontairement à un
**rafraîchissement à la demande** ; le push temps réel arrive juste après, en UC 10.

| # | Titre | Dépend de |
|---|---|---|
| 01 | Configuration du plugin | — |
| 02 | Dépendances Python et démon | 01 |
| 03 | Pont PHP↔démon (canal synchrone + callback) | 02 |
| 04 | Authentification au cloud Roborock | 03 |
| 05 | Test de connexion et état du compte | 04 |
| 06 | Découverte et création des équipements | 05 |
| 07 | Commandes info : état, batterie, erreurs | 06 |
| 08 | Commandes de pilotage de base | 07 |
| 09 | **Routines (« usages ») : synchronisation et exécution** | 06 |

> **UC 09 est l'exigence explicite de l'utilisateur.** Elle est aussi la plus robuste du lot : l'exécution
> d'une routine est du pur HTTPS relayé par le cloud, elle fonctionne donc **même si le canal MQTT du
> robot est indisponible**. Elle ne dépend que de l'UC 06, pas de la chaîne de pilotage : elle peut être
> traitée en parallèle des UC 07-08.

---

## Post-MVP

### `post-mvp/05-temps-reel-et-robustesse/` — à traiter en premier

Complète le MVP là où il s'arrête volontairement.

| # | Titre | Dépend de |
|---|---|---|
| 10 | Rafraîchissement temps réel et fraîcheur | 07 |
| 11 | Robustesse, quotas et ré-authentification | 08, 09, 10 |

### `post-mvp/10-etats-detailles/`

| # | Titre | Dépend de |
|---|---|---|
| 12 | Consommables et usure | 07 |
| 13 | État de la station d'accueil | 07 |
| 14 | Erreurs détaillées et notifications | 07 |
| 15 | Widget tuile du robot | 08 |

### `post-mvp/20-carte-et-pieces/` — domaine le plus coûteux

| # | Titre | Dépend de |
|---|---|---|
| 16 | Pièces nommées et segments | 06 |
| 17 | Cartes multiples (étages) | 16 |
| 18 | Image de la carte | 17 |
| 19 | Vue carte (widget ou page panneau) | 18 |

> L'UC 16 est le **socle du nettoyage par pièce** (UC 24) : elle a de la valeur bien avant que la carte
> soit affichable.

### `post-mvp/30-pilotage-fin/`

| # | Titre | Dépend de |
|---|---|---|
| 20 | Puissance d'aspiration | 08 |
| 21 | Débit d'eau | 20 |
| 22 | Itinéraire de serpillère et mode de nettoyage | 21 |
| 23 | Lavage, séchage et vidage à la station | 13 |
| 24 | **Nettoyage par pièce** | 16, 22 |
| 25 | Nettoyage de zone et déplacement vers un point | 18, 24 |

### `post-mvp/40-journal-et-planification/`

| # | Titre | Dépend de |
|---|---|---|
| 26 | Journal des nettoyages | 07 |
| 27 | Statistiques cumulées | 26 |
| 28 | Lecture des programmations de l'application | 09 |

### `post-mvp/50-transport-local-et-diagnostic/`

| # | Titre | Dépend de |
|---|---|---|
| 29 | Transport local (LAN) : diagnostic et maîtrise | 11 |
| 30 | Diagnostic et support | 11 |
| 31 | Suivi et mise à jour du firmware | 11 |

> L'UC 29 n'ajoute **pas** une fonctionnalité locale : elle rend visible et maîtrisable une connexion TCP
> locale que la librairie tente de toute façon, alors que le plugin est spécifié « cloud uniquement ».

---

## Conventions transverses (rappel)

- **Langue FR** ; i18n enveloppée en HTML/JS et via `__('...', __FILE__)` en PHP, clé = texte français.
- **Autoload 1 classe ↔ 1 fichier** ; **tout** échange avec le démon passe par `jeeroborockDaemon`, aucun
  appel épars — et **aucun appel au cloud Roborock en PHP**.
- Logs via `log::add('jeeroborock', …)` ; **jamais** de secret/token en clair. `local_key`, `rriot` et
  jetons **ne quittent pas le démon**.
- **Robustesse** : try/catch par équipement dans les crons ; **aucun retry automatique** sur le login
  (quotas durs partagés avec l'application mobile).
- Commandes créées **d'après les capacités détectées**, jamais d'après une table codée par modèle.
- Une feature = un commit, vérifications vertes entre chaque
  (`python .claude/scripts/verif-plugin.py`).

> Détail des conventions et de l'architecture : `CLAUDE.md` (racine).
> Connaissance réutilisable (cloud Roborock, protocole MQTT, mapping Jeedom, pièges vérifiés) :
> `.memory/analyse/` — via son `INDEX.md`.
