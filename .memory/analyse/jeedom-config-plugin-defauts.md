# Jeedom — valeurs par défaut et cycle de vie d'une configuration plugin

> **Générique Jeedom** (pas propre à JeeRoborock) · Vérifié dans la **source du core** (branche `alpha`),
> pas dans le wiki · Établi en UC01 le **2026-09-17**

Quatre comportements du core que l'on paie cher à redécouvrir. Ils concernent toute page
`plugin_info/configuration.php` (« configuration du plugin », `gotoPluginConf`) et les hooks
`preConfig_<clé>` / `postConfig_<clé>`.

## 1. Une valeur par défaut ne peut PAS être servie par le HTML

Le core charge le formulaire ainsi (`desktop/js/plugin.js`, l. 340-366) :

```
getJeeValues('.configKey')  →  jeedom.config.load  →  setJeeValues(data, '.configKey')
```

`setJeeValues` **écrase** la valeur de chaque champ avec ce que renvoie le serveur. Un
`<input … value="61350"/>` codé en dur dans le HTML est donc **systématiquement effacé** au chargement de
la page — le champ s'affiche vide.

Il n'existe pas non plus de champ `defaultConfiguration` dans `plugin_info/info.json` :
`config::getDefaultConfiguration()` (`core/class/config.class.php` l. 41) ne lit **que** le fichier

```
plugins/<id>/core/config/<id>.config.ini
```

**→ Le seul mécanisme qui pré-remplit réellement un champ de configuration plugin est ce `.ini`.** La
section doit être nommée **exactement comme l'id du plugin** (le lookup est
`$defaultConfiguration[$_plugin][$_key]`) :

```ini
[monplugin]
maCle = "valeur"
```

Penser à poser un `.htaccess` (`Order allow,deny` / `Deny from all`) dans le nouveau répertoire
`core/config/`, comme pour `core/class/` et `core/php/`.

## 2. ⚠️ Enregistrer la valeur par défaut SUPPRIME la ligne et court-circuite `preConfig_`

`config::save($_key, $_value, $_plugin)` (l. 59-110), tout en haut de la méthode (l. 67-75) :

> si `$_value == $defaultConfiguration[$_plugin][$_key]`, la ligne est **supprimée de la table `config`**
> et la méthode **retourne sans appeler `preConfig_<clé>`** (seul `postConfig_<clé>` est appelé).

Deux conséquences qui piègent :

- **Toute validation ou tout effet de bord placé dans `preConfig_` est sauté** quand l'utilisateur saisit
  exactement la valeur par défaut. Ne jamais compter sur `preConfig_` pour un contrôle qui doit s'exécuter
  dans **tous** les cas — prévoir un second point de contrôle (au démarrage du démon, au test de connexion).
- **En recette, tester la persistance d'un champ avec une valeur ≠ défaut.** Avec la valeur par défaut, la
  ligne disparaît de la base : le champ reste correctement affiché (via le `.ini`), mais un test naïf
  « la ligne existe-t-elle en base ? » conclut à tort à une régression.

L'ordre réel est : comparaison au défaut → `preConfig_<clé>` (l. 83-86) → **chiffrement** si la clé est
dans `$_encryptConfigKey` (l. 89-90). Le chiffrement arrive donc **après** le hook.

## 3. `config::byKey` et `config::byKeys` divergent sur la chaîne vide

| Situation | `byKey` (l. 149-181) | `byKeys` (l. 183-228) |
|---|---|---|
| Aucune ligne en base | défaut `.ini`, sinon `$_default` | défaut `.ini`, sinon `$_default` |
| Ligne existante contenant **la chaîne vide** | traitée comme absente → **défaut appliqué** | renvoyée **telle quelle** → défaut **non** appliqué |

`byKeys` est ce qui alimente le chargement du formulaire. Un champ vidé puis enregistré revient donc vide
à l'écran, alors que le code PHP qui lit la même clé par `byKey` verra le défaut. **Parade** : normaliser
« vide → défaut » **à l'écriture**, dans `preConfig_<clé>`.

Autre subtilité de `byKey` : il applique `is_json($v, $v)` (l. 178), et `is_json` (`core/php/utils.inc.php`
l. 261-273) ne convertit **que** si le décodage produit un **tableau**. Une valeur numérique comme
`'61350'` reste donc une chaîne, mais une valeur stockée en JSON objet est relue **en tableau PHP** — à
anticiper pour toute clé qui stocke une structure.

## 4. `getKey` n'est pas admin-only côté core

Dans `core/ajax/config.ajax.php`, l'action **`addKey` exige `isConnect('admin')`** (l. 68-78), mais
l'action **`getKey` ne demande que `isConnect()`** (l. 22-24 + l. 51-66) et renvoie les valeurs
**déchiffrées** (`config::byKeys` l. 200-201).

**Conséquence** : tout utilisateur Jeedom connecté, même non-admin, peut lire une clé de configuration
plugin — y compris une clé déclarée dans `$_encryptConfigKey` — s'il en devine le nom. C'est un
comportement **du core**, commun à tous les plugins. Il n'est pas contournable proprement depuis un plugin.

**Ce qu'on peut faire, et qui reste la bonne pratique** :
- chiffrer au repos via `$_encryptConfigKey` (protège la base et les sauvegardes, pas cet appel) ;
- **ne jamais poser de champ `.configKey` pour un secret** dans le formulaire : le core ne demande au
  serveur que les clés **présentes dans le DOM**, donc un secret sans champ ne transite jamais vers la page ;
- ne jamais logger le secret ;
- **ne jamais définir `preConfig_<clé secrète>` ni `postConfig_<clé secrète>`** : `displayException()`
  (`core/php/utils.inc.php` l. 252-259) ajoute `getTraceAsString()` quand `DEBUG !== 0`, et une trace PHP
  contient les **arguments de chaque frame**. Une exception levée depuis une frame qui reçoit le secret en
  paramètre l'expose dans le DOM.

## 5. Deux détails de la page de configuration plugin

- **Aucun contrôle admin côté core sur l'inclusion.** `index.php` (l. 79-81) inclut
  `plugin_info/configuration.php` via `?v=d&plugin=<id>&configure=1` avec un simple
  `include_file('core', 'authentification', 'php')`. **La garde écrite dans le fichier est la seule
  protection** → y mettre `isConnect('admin')`, pas `isConnect()`.
- **Le sélecteur « Niveau log » est fourni par le core**, systématiquement, sur cette page
  (`desktop/js/plugin.js` l. 299-311, alimenté par `core/ajax/plugin.ajax.php` l. 40-41). Il est lié à la
  clé **cœur** `log::level::<id>`, et `log::add()` (`core/class/log.class.php` l. 111-118) filtre
  **uniquement** sur `log::getLogLevel('<id>')`. **Ne pas créer de clé `logLevel` propre au plugin** :
  elle serait décorative et créerait deux réglages qui se contredisent en silence. Pour transmettre le
  niveau à un démon : `log::convertLogLevel(log::getLogLevel('<id>'))`.
- **Une exception dans un `preConfig_` interrompt la boucle d'enregistrement.** `addKey` boucle
  `config::save()` clé par clé dans l'ordre du DOM : les clés déjà traitées restent enregistrées, les
  suivantes sont perdues. Sauvegarde partielle possible — acceptable si les champs sont indépendants, à
  surveiller s'ils ne le sont pas.

## 6. ⚠️ `addKey` ne relâche JAMAIS le verrou de session : un hook qui appelle le réseau fige Jeedom

`core/ajax/config.ajax.php` (branche `alpha`) ne contient **aucun** `session_write_close()` — vérifié
`grep -c` sur la source du core : **0**. Son action `addKey` (l. 69-78) est une simple boucle :

```php
$values = json_decode(init('value'), true);
foreach ($values as $key => $value) {
    config::save($key, jeedom::fromHumanReadable($value), init('plugin', 'core'));
}
ajax::success();
```

**Conséquence** : tout hook `preConfig_<clé>` / `postConfig_<clé>` s'exécute **synchronement, verrou de
session PHP tenu**. Un hook qui fait un appel réseau (démon local, API tierce) **fige toute l'interface
Jeedom** pendant la durée de l'appel — l'utilisateur voit l'ensemble de l'UI se bloquer en enregistrant
une page de configuration, sans aucun message.

C'est le **pendant** de la règle déjà connue pour les endpoints AJAX propres au plugin (où l'on place
`session_write_close()` juste après `ajax::init()`), mais elle est plus facile à manquer : ici le point
d'entrée appartient au **core**, on ne l'écrit pas, et rien ne signale le problème.

**Parade** — le hook doit relâcher le verrou **lui-même**, sous garde, juste avant l'appel :

```php
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}
// ... puis seulement ici, l'appel reseau, borne par un timeout court
```

La garde rend l'opération **idempotente** : le même code appelé depuis un chemin où la session est déjà
fermée (cron, hook `deamon_*`) ne double pas l'appel.

**Innocuité vérifiée** sur la source : après les hooks, `addKey` n'exécute plus que la suite de la boucle
`config::save()` (base + cache de configuration, **aucun accès à `$_SESSION`**) puis `ajax::success()`
(`echo` + `die()`). `isConnect()`, `ajax::init()` et `unautorizedInDemo()` sont tous **antérieurs** à la
boucle. Fermer le verrou à ce point ne prive donc aucun code du core d'un `$_SESSION` en écriture.

**Corollaire de conception** : même protégé, un appel réseau depuis un hook de configuration reste du
temps ajouté à l'enregistrement. Le borner par un **timeout court** et le rendre **best-effort**
(`try/catch`, jamais d'exception qui remonte — cf. le point « une exception dans un `preConfig_`
interrompt la boucle » au § 5).

*Découvert en UC04 (2026-09-18) : `preConfig_email` doit notifier le démon quand le compte est délié.*

## Voir aussi

- `jeeroborock-architecture.md` D3 (port du canal local, clé `portDemonHttp`) et D4 (stockage des
  identifiants, `userData` chiffré).
- `.memory/specs/MVP/01-config-plugin-tech.md` — première application de ces règles.
- `.memory/specs/MVP/04-authentification-cloud-tech.md` § `oublierSession()` — application du § 6.
