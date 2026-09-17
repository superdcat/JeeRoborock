# Dépendances et cycle de vie d'un démon — contrats du core Jeedom

> **Générique Jeedom** : rien ici n'est propre à Roborock. Vérifié contre la **source du core**
> (branche `alpha`) pendant l'UC02 de `jeeroborock`, le 2026-09-17. Les numéros de ligne sont des
> repères de lecture, pas des garanties de stabilité entre versions du core.
>
> Complète `jeedom-config-plugin-defauts.md` (cycle de vie d'une config plugin) et, pour ce plugin,
> `jeeroborock-architecture.md` D1/D2/D7 (pourquoi un démon ici).

---

## 1. `packages.json` — les pièges qui bloquent l'indicateur à NOK

Trois pièges distincts, tous silencieux. Les deux premiers sont déjà dans `CLAUDE.md` ; **le troisième
a coûté un blocage complet** et n'y était pas.

| Piège | Effet |
|---|---|
| Version écrite dans la **clé** (`"paquet==1.2.3": {}`) | Le core apparie la **clé nue** minusculée contre `pip list --format=json`. Une clé contenant `==` ne matche jamais → paquet vu « à installer » en permanence. |
| Opérateur de comparaison dans le champ `version` | `installPackage` concatène `$package .= $version` **sans quoter** → redirection shell, paquet jamais installé. |
| **Entrées `npm`/`yarn`/`composer` héritées du squelette** | ⚠️ **Le piège coûteux.** Une entrée contenant une barre oblique est résolue par `file_exists(<chemin>/package.json\|composer.json)`. Le squelette du template livre trois entrées pointant vers des chemins **inexistants** (dont une avec une faute de frappe, `ressources/` au lieu de `resources/`). Résultat : `status = 0`, non optionnel → `plugin::dependancy_info()` conclut **`state = nok` définitivement**, même dépendance pip correctement installée. Aucun message n'explique pourquoi. |

→ **Réflexe** : sur un plugin issu du template, **vider `packages.json` de tout ce qui n'est pas
réellement utilisé** avant de déclarer la moindre dépendance.

Autres constats utiles :

- La version déclarée est un **minimum** pour le core (`version_compare($installée, $déclarée) < 0`
  → à installer), alors que la commande d'installation **épingle** (`pip install paquet==x.y.z`).
  Conséquence : une version installée **plus récente** que celle déclarée est considérée conforme et
  n'est jamais ramenée en arrière. Un garde-fou applicatif (log de la version réellement chargée) est
  le seul moyen de voir la dérive.
- Sur Debian ≥ 12, le core crée lui-même le venv `plugins/<id>/resources/python_venv` et installe
  `python3`, `python3-pip`, `python3-dev`, `python3-venv` en apt → **aucune entrée `apt` à déclarer**
  pour un démon Python ordinaire.
- Fichier de progression d'installation : **`/tmp/jeedom_install_in_progress_<id>`** — chemin `/tmp`
  **en dur** dans le core, ce n'est **pas** `folder::tmp`. C'est le seul moyen fiable de savoir « une
  installation est en cours » depuis `deamon_info()`.
- `system::getCmdPython3($_plugin)` ne dépend **pas** de l'existence du venv : c'est une pure fonction
  de l'OS (`''` ou Debian < 12 → `'python3 '` ; sinon `getPython3VenvDir($_plugin) . '/bin/python3 '`,
  **espace finale incluse**). Pour savoir si les dépendances sont réellement installées, il faut un
  `file_exists()` sur `getPython3VenvDir($_plugin) . '/bin/python3'`, pas une inspection du retour.

## 2. ⚠️ Le garde-fou « dépendances » du core ne s'applique pas sans `dependancy_info()`

`plugin::deamon_info()` rétrograde automatiquement l'état en « Dépendances non installées »…
**uniquement si `method_exists($plugin_id, 'dependancy_info')`**.

Or, dès que `packages.json` existe, le core calcule l'état **exclusivement** depuis
`checkAndInstall(packages.json)` et n'appelle **jamais** `dependancy_info()` — la méthode est du code
mort, et la bonne pratique est de **ne pas la définir**.

**Les deux règles se contredisent en apparence, et le piège est là** : ne pas définir la méthode est
correct, mais cela **désactive silencieusement** le garde-fou du core. Sans contrôle porté par le
plugin lui-même, le core tente de lancer le démon **pendant** l'installation pip, échoue, recommence,
et finit par logger un « souci avec le démon » sans rapport avec la cause réelle.

→ **`deamon_info()` doit porter lui-même le contrôle de dépendance**, via le fichier de progression
(§ 1) puis l'existence de l'interpréteur du venv.

Le hook officiel `additionnalDependancyCheck()` n'est **pas** une solution : il n'est appelé que si
l'état `packages.json` est déjà `ok` — donc jamais pendant la fenêtre qui pose problème.

## 3. `requireOsVersion` — la clé qui bloque réellement l'activation

⚠️ **`os.min` / `os.max` de `info.json` ne sont lus nulle part dans `plugin::byId()`** : ce sont des
champs **market** (affichage, filtrage), **pas** un verrou d'activation.

La clé qui verrouille est **`requireOsVersion`** (chaîne) : lue par `plugin::byId()`, appliquée par
`plugin::setIsEnable()`, qui lève une exception explicite quand `system::getDistrib() == 'debian'` et
`version_compare(system::getOsVersion(), $osVersion) == -1`.

- `system::getOsVersion()` = `cat /etc/debian_version`.
- **Fail-open partout ailleurs** : distribution non-Debian → test sauté ; `/etc/debian_version` valant
  `trixie/sid` → comparaison de chaînes, donc pas de blocage.

⚠️ **Clé non documentée** (absente de `structure_info_json`) et **non utilisée par les plugins
officiels** inspectés (zigbee, zwavejs, mqtt2, blea). Le chemin de code est sans ambiguïté, mais la
tolérance du Market / de l'updater à une clé inconnue n'est **pas prouvée** → à valider en recette, et
à retirer au moindre refus (ce n'est qu'un durcissement).

**Pourquoi ça compte** : sans ce verrou, activer un plugin qui exige Python ≥ 3.11 sur Debian 11 crée
un venv Python 3.9 où pip refuse la dépendance → indicateur bloqué à NOK **sans cause lisible**.

## 4. Contrat des hooks de démon — ce que le core avale, et ce qu'il n'entoure pas

| Hook | Contrat réel | Conséquence pour le plugin |
|---|---|---|
| `deamon_info()` | Appelé **sans try/catch**, et **chaque minute** par `plugin::checkDeamon` + à chaque rafraîchissement de la modale | ⚠️ **Ne doit JAMAIS lever** (sinon modale démon et `checkDeamon` cassés) → `try`/`catch (Throwable)` interne **obligatoire**, retournant un tableau valide. Doit aussi être **peu coûteux** : aucun appel réseau ni shell en régime nominal. |
| `deamon_start()` | Appelé seulement si `launchable == 'ok' && state == 'nok'` ; **45 s minimum entre deux lancements** ; **enveloppé dans un `catch` qui se contente d'un `log::add(error)`** | ⚠️ **Une exception levée ici n'atteint JAMAIS l'utilisateur.** La cause d'un échec doit donc être portée par `launchable_message` (rendu dans la modale) **et/ou** `message::add`, jamais par le message d'exception seul. |
| `deamon_stop()` | Appelé seulement si `state == 'ok'` | Ne doit pas lever non plus. |

Autres points :

- Le core **complète** le tableau de `deamon_info()` (`auto`, `last_launch`) et force
  `launchable = 'nok'` si les crons sont désactivés. Mais si le plugin appelle sa propre
  `deamon_info()` **en statique direct** (typiquement depuis `deamon_start()`), ce complément ne
  s'applique pas → **poser explicitement les 4 clés** `log` / `state` / `launchable` /
  `launchable_message`.
- `launchable_message` est **injecté en HTML** dans la modale démon → n'y mettre que des littérales
  traduites et des entiers (cf. § 5).
- Le garde-fou **45 s** du core fait qu'un arrêt/relance rapproché depuis l'interface est **refusé** :
  c'est un comportement du core, pas une régression du plugin. À savoir avant de débugger.
- ⚠️ **L'exemple du wiki Jeedom contient un bug** : boucle d'attente bornée à 20 itérations, puis test
  de sortie `if ($i >= 30)` — **jamais vrai**, donc **l'échec de démarrage n'est jamais signalé**. Ne
  pas le recopier.

### Déduire l'état du démon : fichier PID, et ses deux pièges

Déduire `state` d'un fichier PID (plutôt que d'une sonde réseau) est le choix le moins coûteux, mais il
exige deux durcissements :

1. **PID recyclé** — vérifier que `/proc/<pid>/cmdline` contient le **chemin absolu** du script, et non
   une sous-chaîne de son nom. Une comparaison lâche, couplée à `system::kill($pid)`, peut faire tuer
   un processus non lié (le répertoire temporaire du plugin est prévisible). Repli `posix_getsid($pid)`
   quand `/proc/<pid>` est absent — ce qui couvre **aussi** le cas « process mort », pas seulement
   « système sans `/proc` ».
2. **Purge de `/tmp`** — `systemd-tmpfiles` supprime les fichiers non touchés depuis **10 jours**. Un
   démon vivant depuis 10 jours perdrait son fichier PID et serait déclaré mort (puis son port vu
   « occupé » : le plugin se bloque tout seul). → **faire réécrire périodiquement le fichier PID par le
   démon**.

`system::kill('<nom>')` est un `ps ax | grep -ie '<nom>'` : ⚠️ **ne jamais garder le nom de démon du
template** (`demond.py`), sous peine de **tuer les démons des autres plugins issus du même squelette**.
Nommer le démon d'après l'id du plugin.

## 5. ⚠️ `message::add()` rend son corps en HTML

Le contenu d'un message du centre de messages est **rendu en HTML brut** — c'est d'ailleurs ce qui
permet d'y placer un lien cliquable vers un log.

→ **Toute** valeur d'origine externe (réponse d'API, callback de démon, saisie utilisateur) doit être
neutralisée **avant** d'y entrer : `intval()` pour un entier, **`htmlspecialchars($v, ENT_QUOTES,
'UTF-8')` pour une chaîne libre**. Tronquer une chaîne **ne protège de rien** :
`<script>alert(1)</script>` tient dans 32 caractères. La cible est la session d'un **administrateur**
Jeedom authentifié.

Corollaire pour `log::add()` : les logs ne sont pas rendus en HTML, mais y concaténer une valeur externe
non filtrée permet de **forger de fausses lignes** via `\n`/`\r`. Neutraliser les caractères de contrôle
et garantir un UTF-8 valide (`mb_scrub()` — et non `mb_convert_encoding($v, 'UTF-8', 'UTF-8')`, dont le
résultat dépend du réglage `mbstring.substitute_character` de l'environnement).

## 6. ⚠️ `escapeshellarg()` + masquage d'un secret dans un log : le piège du couple

Convention du core : l'apikey du plugin est passée au démon **en argument de ligne de commande** (elle
est donc visible dans `ps` — limitation assumée, c'est sur elle que reposent `system::kill`/`system::ps`).
On masque donc le secret avant de logger la commande :

```php
log::add($id, 'debug', 'Démarrage : ' . str_replace($apikey, '********', $commande));
```

Si l'on durcit ensuite la construction de la commande avec `escapeshellarg($apikey)` — ce qui est une
bonne chose — **le masquage cesse silencieusement de fonctionner** dès que le secret contient une
apostrophe : `escapeshellarg()` la transforme en une séquence d'échappement qui **fragmente** la
sous-chaîne recherchée par `str_replace()`. Le secret part alors **en clair** dans le log.

→ Masquer la **forme réellement présente** dans la commande :

```php
str_replace(escapeshellarg($apikey), escapeshellarg('********'), $commande)
```

**Aucun des deux éléments n'est dangereux isolément** — c'est leur interaction qui l'est. À vérifier
chaque fois que l'on ajoute un échappement sur une valeur par ailleurs masquée dans un log.

## 7. Callback démon → Jeedom

- URL : `network::getNetworkAccess('internal', 'http:127.0.0.1:port:comp')` +
  `/plugins/<id>/core/php/jee<Id>.php`.
- Authentification : `jeedom::apiAccess(init('apikey'), '<id>')`, clé via `jeedom::getApiKey('<id>')`.
- ⚠️ **Le squelette du wiki répond `200` avec un message quand l'apikey est invalide**, ce qui rend
  inopérant tout test de disponibilité côté démon. Répondre **`401`**.
- ⚠️ **`core/php/.htaccess`** du template interdit l'accès web à tout le répertoire → le callback
  renverrait **403**. Deux options : une exception ciblée `<Files>` (syntaxe Apache 2.2, à ne pas
  mélanger avec la 2.4), ou supprimer le fichier — **ce que font tous les plugins officiels inspectés**
  (zigbee, zwavejs, mqtt2, blea), alors que le template le livre.
- Dans le fichier de callback : `$e->getMessage()`, **jamais `displayException()`** — la trace expose
  les **arguments** de chaque frame, et c'est par ce point d'entrée que transiteront des secrets.

## 8. La lib `jeedom/jeedom.py` du template n'est pas importable telle quelle

Elle importe `serial`, `pyudev` et `requests` **au niveau module**. Aucun des trois n'est une dépendance
d'un démon Python ordinaire → **`ImportError` immédiat, démon incapable de démarrer**.

→ La forker et l'alléger : garder les noms publics utiles (`jeedom_com.add_changes/test`,
`jeedom_utils.set_log_level/convert_log_level/write_pid`), supprimer le reste, et remplacer `requests`
par `urllib.request` (stdlib). Effet de bord bienvenu : **la désactivation de vérification TLS présente
dans le template disparaît par construction**.

## 9. Le niveau de log Jeedom vaut `error` par défaut

⚠️ Conséquence directe pour un démon : un message émis via `logging.info` est **muet sur une
installation neuve**. Si une information doit apparaître **à chaque démarrage quel que soit le réglage**
(typiquement la version de la librairie réellement chargée), il faut l'écrire **hors du système de
logs filtré** — un `print(flush=True)` dans la sortie redirigée vers le fichier de log du démon.
