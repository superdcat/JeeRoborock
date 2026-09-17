# Widgets de commande Jeedom — mécanisme, tokens, multi-commandes (vérifié source du core)

> Connaissance **générique Jeedom** (vérifiée directement dans la source du core : `cmd.class.php`,
> `cmd.class.js`, `cmd.ajax.php`). **Corrige deux hypothèses fausses** souvent admises :
> `#cmd_id[logicalId]#` et `jeedom.cmd.byEqLogicId` **n'existent pas**. À relire avant tout nouveau widget
> de commande. Les exemples utilisent l'id de plugin `<id>` (= `template` tant qu'il n'est pas renommé).

## 1. Déclaration & fichiers

- Un widget de commande = un fichier `core/template/<version>/cmd.<type>.<subType>.<nom>.html`,
  `<version>` ∈ `{dashboard, mobile}`. Ex. : `cmd.info.string.<nom>.html`,
  `cmd.action.other.<nom>.html`, `cmd.info.numeric.<nom>.html`.
- Assignation côté PHP : `$cmd->setTemplate('dashboard'|'mobile', '<id>::<nom>')` (préfixe = id plugin,
  `<nom>` = suffixe du fichier). Le core résout le fichier d'après `type`/`subType` de la commande.
- **Dashboard et mobile sont deux fichiers séparés** (souvent copies identiques → l'en-tête HTML rappelle
  de les synchroniser). **i18n : une entrée de chemin par fichier** (`plugins/<id>/core/template/
  dashboard/<f>` ET `.../mobile/<f>`), même pour des chaînes identiques.

## 2. Tokens disponibles dans le HTML (remplacés par `cmd::toHtml()`)

`#id#`, `#logicalId#`, `#eqLogic_id#`, `#name#`, `#name_display#`, `#uid#`, `#version#`.
Pour une **action liée** à une info (via `setValue`) : `#value_id#`, `#state#`.

- `#uid#` = id DOM unique de l'instance → **scoper le script** : `document.querySelector('.cmd[data-cmd_uid=#uid#]')`.
- ⚠️ **Aucun token ne référence une AUTRE commande par logicalId.** `#cmd_id[<autre>]#` & co
  **n'existent pas**. Le widget ne « voit » nativement que sa propre commande + sa commande liée.

## 3. Résoudre des commandes sœurs (widgets multi-commandes)

Besoin typique : un pavé qui pilote plusieurs commandes action d'un même équipement, ou une tuile qui lit
plusieurs commandes info ensemble.

- ⚠️ **`jeedom.cmd.byEqLogicId` n'existe pas** en JS (seul `refreshByEqLogic`, déprécié, pour le
  rafraîchissement d'affichage).
- Voie réelle : `fetch('core/ajax/cmd.ajax.php', { method:'POST', credentials:'same-origin',
  headers:{'Content-Type':'application/x-www-form-urlencoded'},
  body: new URLSearchParams({action:'byEqLogic', eqLogic_id:'#eqLogic_id#'}) })`.
  Réponse `{state:'ok', result:[ … ]}` : **toutes** les commandes de l'eqLogic
  (`utils::o2a(cmd::byEqLogicId(...))`), incluant `logicalId`, `id`, `isVisible` — **même les masquées**.
  Action `byEqLogic` = **utilisateur connecté** suffit (pas admin).
- Pattern robuste : résoudre la map `logicalId→id` **puis** câbler les boutons ; bouton **désactivé** si sa
  commande est absente (ex. équipement ne disposant pas de telle capacité). L'ancre connaît son propre id
  sans réseau (`#id#`).
- **Corollaire clé : masqué ≠ non-exécutable.** `isVisible=0` retire la tuile du dashboard mais la commande
  reste dans `byEqLogic`, exécutable, et listée dans la table admin → on peut masquer les boutons unitaires
  et laisser un pavé les piloter.

## 4. Exécuter une action depuis un widget

- `jeedom.cmd.execute({ id, value?, notify?, success?, error? })` — gère **token CSRF + droits +
  prompts** (code PIN -32005 / confirmation -32006).
- `success(data)` reçoit `{state, result}` où **`result` = la valeur de retour PHP de `cmd::execute()`**.
  ⇒ Faire `return $payload;` dans l'action PHP livre la donnée au widget en **un seul aller-retour**.
  `notify:false` supprime le toast (utile pour un refresh périodique d'une tuile).
- ⚠️ **Activer la confirmation d'une action (anti-fausse-manip)** — vérifié source `jeedom/core` : côté
  **serveur**, poser `$cmd->setConfiguration('actionConfirm', 1)` (à la création, avant `save()`).
  `core/ajax/cmd.ajax.php` fait alors, **avant** `cmd::execCmd()` :
  `if ($cmd->getType()=='action' && $cmd->getConfiguration('actionConfirm')==1 && init('confirmAction')!=1)
  throw new Exception(__('Cette action nécessite une confirmation', __FILE__), -32006);`. Le **-32006** est
  intercepté par `jeedom.cmd.execute` (JS core) qui affiche un `jeeDialog.confirm` (desktop) / `confirm`
  (mobile) natif et rejoue avec `confirmAction=1`. **Zéro code JS/HTML custom** ; la chaîne « Cette action
  nécessite une confirmation » est **traduite par le core** (ne pas la mettre dans les i18n du plugin).
  ⚠️ **Ce n'est PAS une frontière d'autorisation** : la garde vit uniquement dans le contrôleur AJAX de
  l'UI web. Un scénario Jeedom, l'API JSON-RPC (apikey) ou un autre plugin appelant `execCmd()` directement
  **contournent** le dialog. La vraie protection repose sur la maîtrise des droits Jeedom.
- **Commande action PARAMÉTRÉE (saisie utilisateur)** — pour recueillir **une valeur libre**,
  `subType='message'` : le widget natif rend un champ texte + bouton d'envoi, et `cmd::execute($_options)`
  reçoit la valeur dans **`$_options['message']`** (le widget expose **aussi** un champ « Titre » →
  `$_options['title']`, à lire en repli défensif). Bonnes pratiques : lire
  `is_string($_options['message'] ?? null)` avant tout cast (un scénario/API peut passer un tableau → sinon
  warning « Array to string conversion ») ; **valider/parser côté serveur** (rejet net, jamais de clamp
  d'une saisie utilisateur) car le widget natif n'impose aucune contrainte de format ; faire porter le
  **format attendu par le nom** de la commande (ex. `Régler l'heure (HHMM)`). Alternative écartée :
  `subType='slider'` (`$_options['slider']`) — inadapté à une saisie précise. ⚠️ Contrat
  `$_options['message']` = **convention core** ; sans interpréteur PHP local, le repli `title` + rejet
  serveur bornent le risque → à confirmer en recette.

## 5. Auth AJAX (core 4.4+)

- Authentification par **session** (cookie) ; protection CSRF des actions **mutantes** = **forçage du
  POST** (pas de token par requête pour une simple lecture). D'où : `byEqLogic` en `fetch` brut
  fonctionne ; les **mutations** passent par `jeedom.cmd.execute` (qui ajoute le token via `getParamsAJAX`).
- NB : `core/ajax/<id>.ajax.php` (l'AJAX de la page de config plugin) est typiquement **admin-only**
  (`isConnect('admin')` global) → **inutilisable** depuis un widget de dashboard (session utilisateur). Tout
  pilotage widget passe donc par le modèle de commandes + l'AJAX **core** (`byEqLogic`, `execCmd`), ou par
  un **endpoint AJAX plugin dédié non-admin** (`isConnect()` + `hasRight('r')`).

## 6. Appliquer un template sans écraser le choix utilisateur

- Poser **« si vide »** : `if ($cmd->getTemplate($version,'')==='') $cmd->setTemplate($version, ...)` à
  chaque sync. Couvre les **installs existantes** (template absent → posé au prochain re-sync) **sans**
  réécrire un widget choisi à la main. Même philosophie idempotente que `visibleOnCreate`/
  `configurationOnCreate`.
- Bord assumé : si l'utilisateur repasse explicitement au widget « par défaut » du core (template ''), le
  nôtre est re-posé au prochain sync. Toléré (cosmétique, rare).

## 7. ⚠️ CSP Jeedom : tout média/image EXTERNE est bloqué côté navigateur → proxy same-origin obligatoire

**Constat clé (Jeedom réel)** : la CSP des pages Jeedom est `default-src 'self' file: data: blob:
filesystem:` **sans `img-src`/`media-src`/`connect-src` explicites**. Tout ce qui n'est pas listé retombe
sur `default-src 'self'` → **le navigateur bloque le chargement de TOUTE ressource externe** : `<img src>`
vers une image/tuile distante (carte OSM/Mapbox, image de modèle…), `fetch` cross-origin, etc.
Symptôme : image cassée + texte `alt`, ou erreur console « violates Content Security Policy directive ».
La CSP est posée hors `core.inc.php`/`index.php` (front/reverse-proxy), sans réglage admin évident.

**Conséquence d'architecture** : tout widget affichant du **contenu externe** doit le faire **servir par
Jeedom lui-même** (origine `'self'`) via un endpoint du plugin. Le **serveur** n'a pas de CSP (règle
navigateur) → il peut récupérer la ressource externe et la relayer.

**Recette générique (ex. mini-carte / image distante dans un widget dashboard)** :
- Widget : une `<img>` pointant `core/ajax/<endpoint>.ajax.php?eqLogic_id=#eqLogic_id#` (même-origine → CSP
  OK) ; l'endpoint récupère côté serveur la ressource externe et la relaie en binaire.
- Endpoint `core/ajax/<endpoint>.ajax.php` (**séparé** de l'AJAX admin-only du plugin) : `isConnect()` **+
  `$eqLogic->hasRight('r')`** (admin/user OK) ; fetch durci (HTTPS, timeout, taille bornée, content-type en
  allow-list, jamais de header d'auth du plugin) ; **cache court** de la ressource.
- **Alternative sans réseau externe** : afficher les données en texte + un lien cliquable (pas de média) →
  aucun proxy, aucune dépendance, mais moins visuel. Décider selon le besoin.
- Voir aussi `jeedom-panel-page-menu.md` § 4 : dans une **page-panneau** (rendue serveur), on peut embarquer
  l'image externe en **`data:` URI inline** (autorisé par la CSP), sans endpoint proxy.
