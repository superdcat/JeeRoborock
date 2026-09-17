# Template de plugin Jeedom (outillé pour Claude Code)

Ce dépôt est une **base réutilisable pour créer un plugin [Jeedom](https://jeedom.com)**, augmentée d'un
**outillage [Claude Code](https://claude.com/claude-code)** qui automatise le cadrage, la rédaction des
specs et l'implémentation des fonctionnalités.

Vous pouvez l'utiliser de **deux façons** :

- **Avec Claude Code** (recommandé) : deux commandes couvrent tout le cycle — `/init-plugin` cadre le
  plugin et génère les specs, `/feature` implémente chaque fonctionnalité (spec technique → code → reviews
  → traduction).
- **À la main** (classique) : renommer le squelette avec l'assistant `helperConfiguration.php` puis coder
  en suivant la [doc développeur Jeedom](https://doc.jeedom.com/fr_FR/dev/).

> L'id du plugin dans ce template est `template` (classes `template` / `templateCmd`). C'est un
> **placeholder à renommer** au début d'un vrai projet (voir « Renommer le squelette »).

---

## Prérequis

- Une instance **Jeedom** pour tester (le plugin s'installe sous `<jeedom>/plugins/<id>/` et dépend du
  core Jeedom ; il ne fonctionne pas isolément).
- **Claude Code** si vous voulez utiliser l'outillage (`.claude/`).
- `php` en ligne de commande pour l'assistant de renommage `helperConfiguration.php`.

---

## Démarrage rapide avec Claude Code

### 1. `/init-plugin` — cadrer et générer les specs

Lancez la commande dans Claude Code :

```
/init-plugin
```

Elle **vous interroge** sur ce que doit faire le plugin (but, appareil/service cible, id/nom, type
d'intégration, sens des échanges, besoin d'un démon, authentification), puis :

1. **analyse** l'intégration (recherche du contrat de l'API/appareil + du fit Jeedom) et écrit des
   **fichiers d'analyse** dans `.memory/analyse/` ;
2. vous **présente une roadmap** (identité + socle MVP ordonné + fonctionnalités post-MVP par domaine) à
   valider ;
3. **renomme automatiquement le squelette** à votre id (`template` → `<id>` : contenu, noms de fichiers,
   `info.json`) — **sans PHP**, via le port Python `plugin_info/helperConfiguration.py` ;
4. **génère toutes les specs fonctionnelles** (`.memory/specs/MVP/` puis `.memory/specs/post-mvp/`) ;
5. **met à jour `CLAUDE.md` et `README.md`** pour décrire le vrai plugin.

> `/init-plugin` ne produit **pas** de code ni de spec technique : c'est le rôle de `/feature`, par UC.

### 2. `/feature` — implémenter chaque fonctionnalité

Pour chaque spec (en commençant par le socle MVP dans l'ordre) :

```
/feature 01-config-plugin
```

`/feature` produit la **spec technique**, la fait valider, délègue l'écriture du code à un agent
développeur, lance des **reviews croisées** (qualité + sécurité), puis la **traduction** (i18n) et met à
jour la mémoire du projet.

---

## L'outillage Claude Code en détail

Tout vit dans deux dossiers **versionnés** :

### `.claude/` — commandes, agents, skills

| Type | Nom | Rôle |
|---|---|---|
| **Commande** | `/init-plugin` | Cadrage : interview → analyse → roadmap → specs + mise à jour docs. |
| **Commande** | `/feature <spec>` | Implémentation d'une fonctionnalité de bout en bout. |
| **Agent** | `jeedom-plugin-architect` | Analyse la cible + Jeedom, écrit `.memory/analyse/`, produit la roadmap. |
| **Agent** | `jeedom-tech-planner` | Produit le **plan technique** d'une UC à partir de sa spec fonctionnelle (n'écrit aucun fichier). |
| **Agent** | `spec-writer` | Écrit les specs fonctionnelles d'un domaine (fan-out par `/init-plugin`). |
| **Agent** | `php-jeedom-dev` | Développeur PHP/Jeedom : implémente une spec technique. |
| **Agent** | `code-reviewer` | Review qualité (conventions, clarté, complexité, i18n, cohérence spec). |
| **Agent** | `security-reviewer` | Review sécurité (secrets, injections, auth, dépendances). |
| **Agent** | `translator` | Traduit les chaînes UI (`fr_FR` → `en_US`/`de_DE`/`es_ES`). |
| **Skill** | `spec` | Méthode/format d'écriture d'une spec fonctionnelle. |
| **Skill** | `dev` | Boucle d'implémentation (cadrer → coder → vérifier → auto-revue). |

Chaque agent **épingle son `effort`** dans son frontmatter (sans cette ligne il hériterait de celui de la
session) : `xhigh` sur l'arbitrage (`jeedom-plugin-architect`, `jeedom-tech-planner`), `high` sur les
reviews, `medium` sur l'implémentation, `low` sur la traduction. Les commandes s'élèvent elles-mêmes :
`/init-plugin` en `xhigh`, `/feature` en `high`.

`.claude/agent-memory/` contient des **apprentissages persistants** de l'agent développeur sur cet
environnement (ex. absence de `php -l` local, fichiers `desktop/php/*` en tabulations, restriction
d'écriture de `configuration.php`).

### `.memory/` — connaissance interne du projet

- **`specs/`** — les specs des fonctionnalités : une **spec fonctionnelle** `NN-nom.md` (le « quoi » +
  critères d'acceptation) et, produite par `/feature`, une **spec technique** `NN-nom-tech.md` (le
  « comment »). Voir `.memory/specs/README.md`.
- **`analyse/`** — connaissance Jeedom réutilisable, **découvrable via `.memory/analyse/INDEX.md`** (fournie
  avec le template : widgets de commande, page au menu ; enrichie à l'analyse de votre plugin).
- **`external/doc/jeedom/INDEX.md`** — index de la doc développeur Jeedom pour des consultations ciblées.

### `CLAUDE.md`

Fichier lu par **chaque session** Claude Code : conventions, architecture, i18n, pièges Jeedom
(`packages.json`, autoload « 1 classe ↔ 1 fichier », restriction `configuration.php`…). `/init-plugin` le
spécialise pour votre plugin ; tenez-le à jour ensuite.

---

## Structure du dépôt

```
core/            Cœur PHP (classes eqLogic/cmd, ajax, includes, widgets)
desktop/         UI desktop (page de config PHP, JS, modales)
plugin_info/     Manifeste (info.json), install, configuration, packages.json, helperConfiguration.php
resources/       Squelette de démon Python (optionnel, si canal persistant nécessaire)
docs/            Documentation utilisateur (par langue)
.claude/         Outillage Claude Code (commandes, agents, skills, mémoire)
.memory/         Specs, analyses et index de doc (connaissance interne, versionnée)
CLAUDE.md        Guide projet lu par Claude Code
```

> ⚠️ **`plugin_info/configuration.php`** est édité via son miroir **`configuration.txt`** (source de vérité
> éditable), resynchronisé par `cp plugin_info/configuration.txt plugin_info/configuration.php`. Voir
> `CLAUDE.md`.

---

## Développement à la main (sans Claude Code)

Le template reste un template Jeedom standard. Documentation :

- [Utilisation du template de plugin](https://doc.jeedom.com/fr_FR/dev/plugin_template)
- [Fichier info.json](https://doc.jeedom.com/fr_FR/dev/structure_info_json)
- [Icône du plugin](https://doc.jeedom.com/fr_FR/dev/Icone_de_plugin)
- [Widget du plugin](https://doc.jeedom.com/fr_FR/dev/widget_plugin)
- [Documentation du plugin](https://doc.jeedom.com/fr_FR/dev/documentation_plugin)
- [Publication du plugin](https://doc.jeedom.com/fr_FR/dev/publication_plugin)

Renommage du squelette (`template` → votre id), deux options équivalentes :

- **Avec PHP** (assistant interactif officiel) : `cd plugin_info && php helperConfiguration.php`.
- **Sans PHP** (port Python, non interactif) :
  `python plugin_info/helperConfiguration.py --id <id> --name "<Nom>" --category <cat> --daemon <yes|no> --dependency <yes|no>`
  (ajoutez `--dry-run` pour prévisualiser). C'est ce que `/init-plugin` fait automatiquement.

Votre plugin est alors prêt à coder.

---

## Intégration continue & formatage

- La CI s'appuie sur les workflows réutilisables de Jeedom (`.github/workflows/work.yml`) : check du plugin
  sur push/PR vers `beta` et PR vers `master`.
- Pousser sur une branche nommée **`prettier`** déclenche un bot qui reformate le code et commite
  (uniformisation automatique).

## Internationalisation

Le plugin est **nativement multilingue** : langue source **français** (la clé est le texte français),
chaînes UI enveloppées (`{{...}}` en HTML/JS, `__('...', __FILE__)` en PHP), traductions dans
`core/i18n/<langue>.json`. Avec Claude Code, la traduction est gérée en fin de `/feature` par l'agent
`translator`.
