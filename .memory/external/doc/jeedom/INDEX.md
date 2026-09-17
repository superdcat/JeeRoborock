# Index de la doc développeur Jeedom

> **But** : trouver la bonne page de doc Jeedom sans re-fetcher le sommaire à chaque fois.
> L'agent lit cet index (gratuit), repère la page utile, puis fait **un seul `WebFetch`
> directement sur l'URL** indiquée.
>
> **Racines** :
> - Développement de plugin : `https://doc.jeedom.com/fr_FR/dev/`
> - Core (architecture) : `https://doc.jeedom.com/fr_FR/dev/core`
>
> La doc dev est un **wiki DokuWiki** : chaque page = `https://doc.jeedom.com/fr_FR/dev/<slug>`.
>
> **Important sur le core** : la page `core` décrit l'**architecture** (arborescence, front
> desktop/mobile, CSS, back-end « en cours »). Il n'existe **pas** de page de doc par classe/méthode :
> le détail des classes PHP du core (`cache`, `config`, `log`, `utils`, `eqLogic`, `cmd`, `cron`…)
> se lit **dans la source du core** (`<jeedom>/core/class/*.class.php`, ou sur GitHub
> `jeedom/core`). En cas de doute sur une signature (`cache::set`, `config::byKey`, `utils::encrypt`…),
> se référer à la source, pas à une page wiki inexistante.
>
> **Maintenance** : si une page renvoie 404 ou si le menu change, re-générer cet index depuis
> `https://doc.jeedom.com/fr_FR/dev/`. **Dernière synchro** : 2026-06-16.

---

## 0. Correspondance « besoin → page » (raccourci)

| Besoin | Page |
|---|---|
| Créer/structurer un plugin (squelette, MVC) | `tutorial_plugin` |
| Manifeste `info.json` (id, version, require, langues, compat) | `structure_info_json` |
| Démon **et dépendances** (`packages.json`, installation) | `daemon_plugin` |
| Widget d'un équipement/commande | `widget_plugin` |
| `plugin.template.js` (JS générique de page plugin fourni par le core) | `plugin_template` |
| Recommandation sur la **valeur des commandes** (type info/action) | `cmd_value` |
| Doc utilisateur du plugin (`docs/<langue>/`) | `documentation_plugin` |
| Publier / mettre sur le market | `publication_plugin` |
| Icône du plugin | `Icone_de_plugin` |
| JS côté front (Core 4.4+) | `corejs/` |
| Architecture du core (eqLogic, cmd, cron, config, DB, scenario) | `core` |
| API PHP d'une classe core précise (cache/config/log/utils…) | **source du core** (cf. note ci-dessus) |
| Migration / compat selon version de core | `core4.4` … `core4.0` |

---

## 1. Développement de plugin

URL = `https://doc.jeedom.com/fr_FR/dev/` + slug.

| Slug | Page |
|---|---|
| `tutorial_plugin` | Présentation / création d'un plugin (squelette, structure MVC) |
| `Icone_de_plugin` | Icône d'un plugin |
| `structure_info_json` | Structure de `info.json` (manifeste) |
| `documentation_plugin` | Documentation d'un plugin (docs utilisateur) |
| `publication_plugin` | Publication d'un plugin (market) |
| `widget_plugin` | Widget d'un plugin |
| `plugin_template` | Plugin template (`plugin.template.js`, JS générique de page plugin) |
| `daemon_plugin` | Démon et **dépendances** d'un plugin (`packages.json`, install) |
| `cmd_value` | Recommandation sur la valeur des commandes |
| `transfert` | Transfert de plugin (changement de propriétaire) |
| `corejs/` | Développement JS et Core 4.4+ |

## 2. Core — architecture & classes

| URL | Sujet |
|---|---|
| `https://doc.jeedom.com/fr_FR/dev/core` | **Architecture du core** : arborescence, front (desktop/mobile), CSS, back-end (en cours) |

Classes PHP référencées par la page `core` (détail = source du core, pas de page wiki dédiée) :
`jeeObject.class.php`, `eqLogic.class.php`, `cmd.class.php`, `cron.class.php`,
`config.class.php`, `scenario.class.php`, `DB.class.php`.

Classes core typiquement utilisées par un plugin (à lire dans la source si doute sur une signature) :

| Classe | Usage dans un plugin |
|---|---|
| `eqLogic` | Classe mère de `template` (cycle de vie `preSave`/`postSave`/…, hooks cron, configuration ; 1 eqLogic = 1 équipement, clé `logicalId`) |
| `cmd` | Classe mère de `templateCmd` (`execute()` pour les commandes action) |
| `config` | `config::save/byKey(..., '<id>')` — config plugin |
| `cache` | `cache::set/byKey/delete` — états volatils (tokens, jetons courts) |
| `utils` | `utils::encrypt/decrypt` — secrets au repos |
| `log` | `log::add('<id>', niveau, msg)` — journalisation |
| `ajax` | `ajax::init()` + `isConnect('admin')` dans `core/ajax/<id>.ajax.php` |
| `cron` | crons `template::cron`/`cron5`/… — tâches périodiques (polling, refresh) |

## 3. Évolutions du core (migration / compat)

URL = `https://doc.jeedom.com/fr_FR/dev/` + slug.

| Slug | Page |
|---|---|
| `core4.4` | Core v4.4 — adaptations plugins |
| `core4.3` | Core v4.3 — adaptations plugins |
| `core4.2` | Core v4.2 — adaptations plugins |
| `core4.1` | Core v4.1 — adaptations plugins |
| `core4.0` | Core v4.0 — adaptations plugins |
