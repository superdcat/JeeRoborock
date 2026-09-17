# JeeRoborock — aspirateurs Roborock pour Jeedom

Plugin [Jeedom](https://jeedom.com) qui pilote les **aspirateurs robots Roborock** via le **cloud
Roborock** : remontée des états, commandes de nettoyage, et exécution des **routines** — ces scénarios de
nettoyage que vous paramétrez dans l'application mobile Roborock et que ce plugin rend déclenchables
depuis un scénario Jeedom.

> ⚠️ **Statut : en développement.** Le cadrage et les specs sont écrits (31 cas d'usage), le code est en
> cours d'implémentation. Ce plugin n'est pas encore utilisable en production.

---

## Ce que fait le plugin

**Socle (MVP, en cours)**

- Liaison à votre compte Roborock depuis la page de configuration.
- Découverte automatique des robots du compte et création d'un équipement Jeedom par robot.
- Commandes d'information : état, batterie, erreur, surface et durée nettoyées, disponibilité.
- Commandes de pilotage : démarrer, pause, arrêter, retour à la base, localiser, rafraîchir.
- **Exécution des routines Roborock** : chaque routine définie dans l'application mobile devient une
  commande Jeedom, utilisable dans un scénario.

**Prévu ensuite**

- États en temps réel (push), usure des consommables, état de la station d'accueil, widget de tableau de
  bord.
- Réglages fins : puissance d'aspiration, débit d'eau, mode de nettoyage, **nettoyage d'une pièce**.
- Carte et pièces nommées, jusqu'à l'affichage de la carte.
- Journal des nettoyages et statistiques cumulées.

Le détail, cas d'usage par cas d'usage, vit dans [`.memory/specs/`](.memory/specs/README.md).

---

## Prérequis

- Une instance **Jeedom** sur **Debian 12 ou plus récent**. Cette contrainte n'est pas négociable : la
  librairie utilisée par le démon exige **Python ≥ 3.11**, absent de Debian 11.
- Un **compte Roborock** avec au moins un robot déjà configuré dans l'application mobile.
- Un accès Internet depuis la Jeedom : le plugin passe par le cloud Roborock, il ne fonctionne pas en
  réseau local isolé.

**Matériel de référence** : le développement et la recette se font sur un **Roborock Qrevo Curv**
(`roborock.vacuum.a135`). Les autres modèles à protocole V1 devraient fonctionner, mais rien n'est vérifié
ailleurs que sur ce robot — les specs signalent explicitement ce qui reste à confirmer.

---

## Comment ça marche

Le pilotage d'un robot Roborock **n'est pas du REST**. Les commandes sont des appels encapsulés dans un
protocole binaire propriétaire, chiffré par une clé propre au robot et transporté sur une connexion MQTT
persistante, qui pousse aussi des mises à jour d'état non sollicitées.

Le plugin s'appuie donc sur un **démon Python** adossé à la librairie
[`python-roborock`](https://github.com/humbertogontijo/python-roborock) — celle qui fait tourner
l'intégration Roborock de Home Assistant. **Le PHP ne parle jamais au cloud Roborock** : il ne parle qu'au
démon, qui porte tout le contrat externe.

```
Jeedom (PHP)                       démon Python
 equipements, commandes  ──HTTP──▶  python-roborock  ──MQTT/TLS──▶  robot (via le cloud)
 crons, page de config   ◀─push───                   ──HTTPS─────▶  compte, routines
```

Deux points qui surprennent, et qui sont assumés :

- **L'authentification passe par un code reçu par e-mail**, pas par un mot de passe. C'est le seul chemin
  fiable côté Roborock. Conséquence : quand les identifiants expirent, le plugin vous demande de refaire
  l'opération — **il ne peut pas se ré-authentifier tout seul**, personne ne peut lire votre boîte mail à
  votre place.
- **Les quotas Roborock sont durs et partagés avec votre application mobile** (une vingtaine de connexions
  par jour). Le plugin ne réessaie donc jamais en boucle : il vous dit d'attendre. C'est une protection de
  votre compte, pas une limitation du plugin.

---

## Installation

Le plugin n'est pas encore publié sur le Market Jeedom. En attendant, il s'installe comme n'importe quel
plugin Jeedom depuis une source Git, sous `<jeedom>/plugins/jeeroborock/`.

Après installation : installer les dépendances depuis la page du plugin, démarrer le démon, puis lier le
compte Roborock depuis la page de configuration.

---

## Structure du dépôt

```
core/            Cœur PHP (classes eqLogic/cmd, pont démon, ajax, includes, widgets)
desktop/         UI desktop (page de config PHP, JS, modales)
plugin_info/     Manifeste (info.json), install, configuration, packages.json
resources/       Démon Python (canal MQTT, contrat Roborock)
docs/            Documentation utilisateur (par langue)
.claude/         Outillage Claude Code (commandes, agents, skills, mémoire)
.memory/         Specs, analyses et index de doc (connaissance interne, versionnée)
CLAUDE.md        Guide projet lu par Claude Code
```

> ⚠️ **`plugin_info/configuration.php`** est édité via son miroir **`configuration.txt`** (source de
> vérité éditable), resynchronisé par `cp plugin_info/configuration.txt plugin_info/configuration.php`.
> Voir `CLAUDE.md`.

---

## Développement

Le dépôt embarque un **outillage [Claude Code](https://claude.com/claude-code)** qui structure le
développement : une commande par cas d'usage, des sous-agents spécialisés, et une mémoire projet
versionnée.

| Type | Nom | Rôle |
|---|---|---|
| **Commande** | `/feature <spec>` | Implémente un cas d'usage de bout en bout : plan technique → code → reviews → traduction. |
| **Commande** | `/auto-dev "<UC>"` | Enchaîne plusieurs cycles `/feature` sans intervention, en journalisant chaque arbitrage. |
| **Commande** | `/change <décision>` | Revient sur une décision prise automatiquement par `/auto-dev`. |
| **Agent** | `jeedom-tech-planner` | Produit le plan technique d'un cas d'usage à partir de sa spec fonctionnelle. |
| **Agent** | `php-jeedom-dev` | Implémente une spec technique validée. |
| **Agent** | `code-reviewer` / `security-reviewer` | Reviews croisées : qualité et sécurité. |
| **Agent** | `translator` | Traduit les chaînes UI (`fr_FR` → `en_US`/`de_DE`/`es_ES`). |
| **Agent** | `jeedom-plugin-architect` / `spec-writer` | Analyse et rédaction des specs, pour ajouter un domaine. |

La connaissance du projet vit dans **`.memory/`** : `specs/` (les 31 cas d'usage), `analyse/` (contrat du
cloud Roborock, protocole MQTT, mapping Jeedom, pièges vérifiés — via son `INDEX.md`). Les conventions et
les pièges Jeedom sont dans **`CLAUDE.md`**, lu par chaque session.

Avant chaque commit : `python .claude/scripts/verif-plugin.py` (fins de ligne, octets de contrôle,
équilibrage structurel, miroir de configuration, JSON i18n, motifs sensibles).

**Documentation développeur Jeedom** :
[info.json](https://doc.jeedom.com/fr_FR/dev/structure_info_json) ·
[widgets](https://doc.jeedom.com/fr_FR/dev/widget_plugin) ·
[documentation](https://doc.jeedom.com/fr_FR/dev/documentation_plugin) ·
[publication](https://doc.jeedom.com/fr_FR/dev/publication_plugin)

---

## Intégration continue & formatage

- La CI s'appuie sur les workflows réutilisables de Jeedom (`.github/workflows/work.yml`) : check du
  plugin sur push/PR vers `beta` et PR vers `master`.
- Pousser sur une branche nommée **`prettier`** déclenche un bot qui reformate le code et commite.
- Un hook `pre-commit` incrémente la version du plugin dès qu'un commit touche du code livré. À activer
  dans chaque clone : `git config core.hooksPath .githooks`.

## Internationalisation

Le plugin est **nativement multilingue** : langue source **français** (la clé de traduction *est* le texte
français), chaînes UI enveloppées dans le code, traductions dans `core/i18n/<langue>.json`. Langues
cibles : anglais, allemand, espagnol.

## Licence

AGPL. Ce plugin est un projet indépendant, **sans affiliation avec Roborock**.
