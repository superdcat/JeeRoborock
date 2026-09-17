# Index des analyses internes — connaissance Jeedom réutilisable

> **But** : rendre la connaissance interne (décisions d'architecture, limites/pièges, apprentissages
> durables) **découvrable et lazy-loadable** par le workflow de dev, sans tout charger. L'agent lit cet
> index (gratuit, local), repère le fichier d'analyse utile, puis ouvre **uniquement** ce fichier.
>
> `.memory/analyse/` complète `.memory/specs/` (intention des features) et la doc externe
> (`.memory/external/doc/`) : ici on consigne ce que **le projet a tranché** ou ce qu'on a **appris en
> codant** — ce que ni le code, ni git, ni `CLAUDE.md` ne disent déjà.
>
> **Maintenance** : à chaque enseignement durable (Étape 12 du workflow `/feature`), écrire dans le bon
> fichier thématique (ou en créer un) **et mettre à jour cet index** (ligne + déclencheurs § 0 + date).
>
> Le template est livré avec **deux analyses génériques Jeedom** (vérifiées contre la source du core),
> réutilisables par tout plugin. Les analyses **propres à un plugin concret** viendront s'ajouter ici au
> fil du développement.

---

## 0. Correspondance « incertitude » → fichier d'analyse (raccourci)

| Si l'incertitude porte sur… | Fichier |
|---|---|
| **Widget de commande** Jeedom (fichier `cmd.<type>.<subType>.<nom>.html`, `setTemplate`, tokens `#id#`…) | `jeedom-widgets-commandes.md` §§ 1-2 |
| Widget pilotant **plusieurs commandes** (tuile + actions) ; résoudre les sœurs par `byEqLogic` | `jeedom-widgets-commandes.md` § 3 |
| Exécuter une action depuis un widget + récupérer le retour PHP ; auth/CSRF AJAX ; AJAX plugin admin-only | `jeedom-widgets-commandes.md` §§ 4-5 |
| **Confirmation avant une action sensible** (dialog anti-fausse-manip) : comment l'activer côté serveur | `jeedom-widgets-commandes.md` § 4 (`actionConfirm=1` → -32006) |
| **Commande action PARAMÉTRÉE** (saisie utilisateur : subType `message`, valeur dans `$_options['message']`) | `jeedom-widgets-commandes.md` § 4 |
| Appliquer un **template de widget sans écraser** le choix utilisateur (« si vide ») | `jeedom-widgets-commandes.md` § 6 |
| **CSP Jeedom bloque tout média/image EXTERNE** → proxy same-origin (ex. tuile carte) | `jeedom-widgets-commandes.md` § 7 |
| Ajouter une **PAGE** au menu Jeedom (panel) ; toggle natif `displayDesktopPanel/Mobile` ; page non-admin | `jeedom-panel-page-menu.md` |
| **Afficher une image externe dans un panel** (carte…) : `data:` URI inline (panel serveur) vs proxy (widget client) | `jeedom-panel-page-menu.md` § 4 |

> Si aucun fichier ne couvre le sujet : ce n'est pas (encore) analysé en interne → passer à la doc externe
> (`.memory/external/doc/jeedom/INDEX.md` pour le core Jeedom, ou la doc de l'API tierce du plugin), et
> penser à capitaliser en Étape 12.

---

## 1. Catalogue des analyses

| Fichier | Sujet | Points clés indexés |
|---|---|---|
| `jeedom-widgets-commandes.md` | Widgets de commande Jeedom (templates dashboard/mobile), vérifié contre la source du core. | `cmd.<type>.<subType>.<nom>.html` + `setTemplate('<id>::<nom>')` ; tokens (`#id#`/`#logicalId#`/`#eqLogic_id#`/`#uid#`…) ; `#cmd_id[…]#` & `jeedom.cmd.byEqLogicId` **n'existent pas** → résoudre par AJAX **`byEqLogic`** ; **masqué ≠ non-exécutable** ; `jeedom.cmd.execute` (CSRF/droits, `success.result`=retour PHP) ; confirmation d'action `actionConfirm=1` → -32006 ; commande **paramétrée** subType `message` ; AJAX plugin admin-only inutilisable au dashboard ; **§ 7 CSP : média/image externe bloqué → proxy same-origin**. |
| `jeedom-panel-page-menu.md` | Page de plugin au **menu** Jeedom (panel) & toggle d'affichage natif. | `info.json "display"`/`"mobile"` enregistre une page-panneau ; le core ajoute nativement les cases « Afficher le panneau desktop/mobile » (`displayDesktopPanel`/`displayMobilePanel`, masqué par défaut) → aucun toggle custom ; `plugin::getDisplay()` statique ; page panel = `isConnect()` non-admin + accès par eqLogic `hasRight('r')` + sélection par équipement ; **image externe : `data:` URI inline en panel serveur vs proxy same-origin en widget client** ; réf. `jeedom/plugin-gsl`. |
