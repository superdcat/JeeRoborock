# Jeedom — page de plugin au menu (panel) & toggle d'affichage natif

> Connaissance **générique Jeedom** (indépendante du domaine). Sujet : comment ajouter une **page** de
> plugin au menu d'accueil Jeedom (≠ widget de commande, ≠ page de gestion admin), et comment son
> affichage est **conditionné nativement** par le core. Distinct de `jeedom-widgets-commandes.md` (widgets
> de commande sur dashboard). Les exemples utilisent l'id `<id>` (= `template` tant qu'il n'est pas
> renommé).

## 1. Trois choses différentes à ne pas confondre

| Élément | Fichier | Où ça apparaît |
|---|---|---|
| **Page de gestion** du plugin | `desktop/php/<id>.php` (ex. `template.php`) | menu **Plugins** (admin), via `gotoPluginConf` / liste des plugins |
| **Page-panneau** (vue utilisateur) | `desktop/php/<fichier>.php` déclaré par `info.json "display"` | menu **d'accueil** Jeedom |
| **Widget** de commande | `core/template/.../cmd.<type>.<subType>.<nom>.html` | sur un **dashboard**, posé sur une commande |

## 2. Enregistrer une page-panneau au menu

- `info.json` → `"display": "<fichier>"` (**sans extension**, fichier sous `desktop/php/`). Le core ajoute
  une entrée au **menu d'accueil**, routée par `index.php?v=d&p=<fichier>&m=<plugin>`.
- `"mobile": "<fichier>"` → panneau de l'**app mobile** (fichier `mobile/php/` distinct ; chantier séparé
  du desktop).
- Plugin de référence officiel : **`jeedom/plugin-gsl`** (`desktop/php/panel.php`, `info.json
  "display":"panel"`, `"mobile":"panel"`).

## 3. Toggle d'affichage = NATIF (rien à coder côté plugin)

Dès que `display` est posé, le core génère automatiquement, dans la **zone de gestion du plugin**
(`#div_plugin_panel`, à côté des réglages cron/dépendances), les cases **« Afficher le panneau
desktop / mobile »** :

- liées aux clés de config **`displayDesktopPanel`** / **`displayMobilePanel`**
  (`config::save/byKey(..., '<id>')`) ;
- **décochées par défaut** (config absente ⇒ valeur falsy) ⇒ l'entrée de menu est **masquée par défaut** ;
  cocher l'affiche, décocher la retire — **le core lit ces clés** pour construire/masquer l'entrée ;
- source vérifiée : core `desktop/js/plugin.js` (construction de `#div_plugin_panel`).

⇒ **Ne pas** inventer un interrupteur maison (`panelEnabled`) ni réécrire `info.json` au runtime pour
masquer la page. `plugin::getDisplay()` (core `core/class/plugin.class.php`) renvoie la **valeur brute
statique** d'`info.json`, **sans** condition : le conditionnel vient **uniquement** des clés
`displayDesktopPanel/Mobile`. (Écart à signaler si une doc laisse croire l'inverse : la doc
`structure_info_json` ne décrit que `display`/`mobile`, pas le mécanisme de toggle — il est dans le code du
core.)

## 4. Contrat de la page panel & sélection par équipement

- En-tête : `require_once .../core/php/core.inc.php` + `include_file('core','authentification','php')` ;
  `isConnect()` (utilisateur connecté, **pas** admin → usage quotidien). Refus = `throw new Exception`.
- Contrôle d'accès **par eqLogic** : n'afficher une cellule/un équipement que si `hasRight('r')`
  (+ `getIsEnable()`).
- **Sélection** d'un équipement dans le panel = case **par équipement**
  `getConfiguration('isVisiblePanel')` (pattern GSL). Défaut posé **par le plugin** à la création (jamais
  par le core) ; backfill une-fois au re-sync pour les équipements antérieurs.
- Contenu : la page **réutilise** les commandes existantes (`jeedom.cmd.execute`) et, pour afficher une
  ressource externe (image/carte), un endpoint same-origin (cf. `jeedom-widgets-commandes.md` § 7 — la CSP
  interdit une image externe directe).
- **Image externe dans un panel : `data:` URI plutôt que proxy.** La page panel étant **rendue côté
  serveur**, elle peut appeler directement le code PHP qui récupère la ressource externe et **l'embarquer
  inline** en `data:image/…;base64,…` — autorisé par la CSP (`default-src` inclut `data:`), **sans**
  aller-retour HTTP vers un endpoint proxy. Le **proxy same-origin** (`core/ajax/*.ajax.php`) reste
  nécessaire **uniquement** pour un **widget de dashboard** (HTML rendu côté client, qui ne peut pas
  exécuter de PHP). Dans les deux cas, mutualiser la même méthode PHP (fetch + validation + cache),
  consommée par le panel en `data:` et par le proxy en relai binaire. ⚠️ Un panel rendant N ressources
  synchrones = N fetch au chargement à froid → cache serveur (fichier) indispensable.

## Sources
- Core : `core/class/plugin.class.php` (`getDisplay()`), `desktop/js/plugin.js` (cases panel).
- Doc : `structure_info_json` (champs `display`/`mobile`).
- Réf. : `jeedom/plugin-gsl`.
