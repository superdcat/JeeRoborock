# Specs — Features du plugin

> Ce dossier contient les **specs des features** à développer. Il est **livré vide** dans le template.
> La commande **`/init-plugin`** le bootstrappe : après cadrage du besoin, elle génère **toutes les specs
> fonctionnelles** de la roadmap (socle `MVP/` + domaines `post-mvp/`) et **réécrit ce README** avec
> l'arborescence réelle du plugin. Ensuite, l'orchestrateur **`/feature`** s'appuie sur cette convention
> (il lit la spec fonctionnelle et écrit la spec technique, une UC à la fois).

## Convention

Une feature = deux fichiers dans le **même dossier** :

- **Spec fonctionnelle** `NN-nom.md` — le **quoi** et le **pourquoi** : contexte, comportement attendu et
  surtout les **critères d'acceptation** (la *definition of done*, vérifiables). C'est l'entrée du
  workflow ; elle est rédigée/validée avec l'utilisateur.
- **Spec technique** `NN-nom-tech.md` — le **comment** : architecture, fichiers à créer/modifier, décision
  server/client, signatures d'actions AJAX, validation, dépendances. Elle est **produite par
  l'orchestrateur `/feature`** (étape 5), après validation du plan par l'utilisateur, puis consommée par
  l'agent `php-jeedom-dev`.

## Organisation suggérée

- Regrouper les features par **domaine** dans des sous-dossiers (ex. `01-socle/`, `10-<domaine>/`,
  `20-<domaine>/`…). Numérotation conseillée : dossiers par dizaines, fichiers par unités
  (`NN-nom.md`). Adapte librement à ton plugin.
- Si un ordre de développement est imposé par des dépendances, le documenter en tête de ce README (petite
  table « # | Titre | Dépend de »).

## Conventions transverses (rappel)

- **Langue FR** ; i18n `{{...}}` (HTML/JS) et `__('...', __FILE__)` (PHP), clé = texte français.
- **Autoload 1 classe ↔ 1 fichier** ; tout appel externe centralisé (brique API / pont démon).
- Logs via `log::add('<id>', …)` ; **jamais** de secret/token en clair.
- **Robustesse** : try/catch par équipement dans les crons ; backoff/cooldown sur rate-limit d'API tierce.
- Une feature = un commit/PR, vérifications vertes entre chaque.

> Détail des conventions et de l'architecture : `CLAUDE.md` (racine).
> Connaissance Jeedom réutilisable : `.memory/analyse/` (via son `INDEX.md`).
