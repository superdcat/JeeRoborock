---
description: Initialise le plugin à partir de zéro : interroge l'utilisateur sur le but du plugin, fait analyser l'intégration (agent architecte), renomme le squelette, met à jour CLAUDE.md et README.md, écrit les fichiers d'analyse, puis génère toutes les specs fonctionnelles (MVP puis post-MVP).
argument-hint: (aucun — l'assistant pose les questions)
model: opus
effort: xhigh
---

# Initialisation d'un plugin Jeedom — orchestrateur de cadrage

Tu vas **bootstrapper un plugin** depuis ce template : cadrer le besoin, analyser l'intégration, **renommer
le squelette** à l'id choisi, et produire toute la connaissance amont (`CLAUDE.md`, `README.md`,
`.memory/analyse/`, `.memory/specs/`) que la commande `/feature` consommera ensuite pour implémenter
chaque UC.

Tu es l'**orchestrateur/architecte** (Opus, `effort: xhigh`). Tu mènes l'interview et les gates de
validation, tu **délègues l'analyse** au sous-agent `jeedom-plugin-architect` et **l'écriture des specs**
aux sous-agents `spec-writer`, puis tu **rédiges toi-même** les documents transverses (`CLAUDE.md`,
`README.md`, `specs/README.md`). Tu ne codes pas la logique métier du plugin.

> **Périmètre** : cette commande produit les **specs fonctionnelles** (le « quoi » + critères
> d'acceptation) de toutes les UC. Les **specs techniques** (`NN-nom-tech.md`, le « comment ») sont
> produites **par UC** plus tard, par `/feature`, après validation du plan avec l'utilisateur. Ne génère
> pas de spec technique ici.

## Étape 0 — Garde « template vierge »

Vérifie l'état : `Glob .memory/specs/**/*.md` et l'id courant dans `plugin_info/info.json`. Si des specs
d'UC existent déjà (au-delà du `README.md`) **ou** si l'id n'est plus `template`, **arrête-toi** et demande
à l'utilisateur s'il veut réinitialiser (écraser) ou annuler — ne détruis jamais un cadrage existant sans
accord explicite.

## Étape 1 — Interview (le besoin)

Le but : comprendre **ce que doit faire le plugin**. Procède en deux temps.

**1a — Description libre (texte, ARRÊTE-TOI et attends la réponse).** Demande :

> "Décris le plugin à créer :
> 1. **But** : que doit-il faire (en quelques phrases) ?
> 2. **Cible** : quel appareil / service / API il pilote ou lit (nom, et une URL de doc si tu en as une) ?
> 3. **Id & nom** souhaités pour le plugin (id en minuscules, sans espace ni tiret de préférence ; nom d'affichage) ?"

**1b — Précisions structurées.** Une fois la description reçue, pose ces questions via l'outil de questions
à choix (chaque question avec une option « Autre » libre) :

- **Type d'intégration** : API cloud (REST/HTTP) · Appareil/protocole local (MQTT, WebSocket, série, LAN) ·
  Service logiciel local · Autre.
- **Sens des échanges** : Lecture seule (télémétrie/états) · Lecture + commandes (pilotage) ·
  Principalement des commandes · Autre.
- **Besoin d'un démon** : À déterminer par l'analyse (recommandé) · Non (REST + polling cron suffit) ·
  Oui (canal persistant / push / temps réel) · Autre.
- **Authentification** : Aucune / clé API simple · OAuth2 · Login + mot de passe · Token / appairage · Autre.

Si la description reste **trop vague pour analyser** (cible non identifiable, but flou), pose **1 à 2
questions de clarification** supplémentaires — sans enchaîner d'allers-retours inutiles.

## Étape 2 — Analyse & roadmap (sous-agent `jeedom-plugin-architect`)

Invoque le sous-agent **`jeedom-plugin-architect`** (Opus) en lui passant **toutes** les réponses de
l'interview. Consigne : *« Analyse la cible et son intégration à Jeedom (recherche ciblée si API/appareil
externe), écris les fichiers `.memory/analyse/` propres au plugin + mets à jour `.memory/analyse/INDEX.md`,
et rends la ROADMAP structurée (JSON) : identité du plugin (dont **catégorie Jeedom** proposée + démon +
dépendances), socle MVP ordonné + domaines post-MVP. N'écris ni specs ni code, ne renomme rien. »*

Il te rend : la **roadmap JSON** (avec `plugin.category`, `plugin.hasDaemon`, `plugin.hasDependency`), la
liste des **fichiers d'analyse** écrits, et les **décisions structurantes** + `openQuestions`.

## Étape 3 — Validation de la roadmap (gate utilisateur)

**ARRÊTE-TOI ICI.** Présente une synthèse lisible : **identité** (id/nom/but, **catégorie Jeedom**, démon
oui/non, dépendances oui/non), la **liste des UC MVP ordonnée** (titres + dépendances), les **domaines
post-MVP** avec leurs UC, et les **questions ouvertes**. Demande :

> "Voici la roadmap proposée (dont id, catégorie, démon). On part là-dessus, ou tu veux ajuster
> (identité/catégorie/démon, ajouter/retirer/réordonner des UC, périmètre MVP, question ouverte) ?
> (oui / ajustements)"

Intègre les ajustements (au besoin en relançant l'architecte via `SendMessage`) avant de continuer. Ne
passe à l'étape 4 qu'avec une identité **et** une roadmap validées.

## Étape 4 — Renommage automatique du squelette (sans PHP)

Le template porte l'id `template`. Renomme-le à l'id validé **toi-même** — n'attends pas que l'utilisateur
lance `helperConfiguration.php` (souvent impossible : pas de `php` en local). Utilise le **port Python** du
helper, non interactif :

```bash
python plugin_info/helperConfiguration.py \
  --id <id> --name "<Nom>" --category <categorie_jeedom> \
  --daemon <yes|no> --dependency <yes|no>
```

- `<categorie_jeedom>` = la valeur Jeedom validée (ex. `programming`, `monitoring`, `devicecommunication`…)
  ou son numéro 1-16 ; `--daemon`/`--dependency` = décisions validées (démon `no` ⇒ le dossier
  `resources/` est supprimé ; dépendances `no` ⇒ `maxDependancyInstallTime` retiré).
- Fais **d'abord un `--dry-run`** (ajoute `--dry-run`) pour afficher les actions, puis relance sans
  `--dry-run`. Ce helper : remplace `template`→`<id>` dans le contenu (en **préservant** la ligne
  `plugin.template`), renomme les fichiers `template.*` → `<id>.*`, met à jour `info.json`, et **ne touche
  pas** `configuration.php` ni `core/template/` (dossier standard des widgets).
- **Vérifie** ensuite avec `git status --short` (fichiers renommés + `info.json`/`packages.json` modifiés,
  `resources/` supprimé le cas échéant) ; **ne relis pas** `plugin_info/configuration.php` (permission).
- Après ce point, **toutes** les références de code utilisent `<id>` : rédige la suite (docs, specs) avec
  `<id>`, plus `template`.

> ⚠️ Le renommage modifie des fichiers réels. Ne le lance qu'**après** validation de l'identité (étape 3).

## Étape 4bis — Personnalisation de l'outillage Claude

Le renommage de l'étape 4 fait un `os.walk` depuis la racine : il a donc **déjà** remplacé
`template` → `<id>` dans `.claude/` (agents, commandes, skills). Il reste **trois** choses que le helper
ne peut pas deviner.

**a) Vérifie ce que le remplacement mécanique a produit sur les noms de classes annexes.**

```bash
grep -rn "<id>Api\|<id>Exception\|<id>Cmd" .claude/
```

Le helper a transformé les exemples génériques `templateApi`/`templateException` en `<id>Api`/`<id>Exception`.
Si l'architecture validée à l'étape 3 retient **d'autres** noms (par exemple une brique par transport, ou
un client nommé d'après le service), **corrige ces exemples** dans `.claude/agents/code-reviewer.md`,
`.claude/agents/php-jeedom-dev.md` et `.claude/skills/dev/SKILL.md`. Ce sont les exemples de la règle
d'autoload : s'ils ne correspondent à aucune classe réelle, l'agent doit faire un effort de traduction
mentale à chaque invocation, et la règle perd de sa force.

**b) Renseigne les décisions arbitrées dans les deux reviewers.**

`.claude/agents/code-reviewer.md` et `.claude/agents/security-reviewer.md` portent chacun une section
« Décisions arbitrées propres à ce plugin », repérée par le marqueur `<!-- init-plugin:invariants -->`.
Remplace le paragraphe explicatif entre parenthèses par la **liste des compromis effectivement arbitrés**
pendant l'interview et la validation (étapes 1 à 3).

Y a sa place tout point qu'un reviewer signalerait **à chaque cycle** alors qu'il a déjà été tranché :

- un **comportement natif du core** assumé malgré une règle générale du projet (typiquement un secret que
  le core expose lui-même, et que le plugin ne peut pas corriger) ;
- une **constante tierce délibérément embarquée** dans le code (clé de protocole, jeton applicatif) avec
  la raison et l'obligation de citer sa source et sa licence ;
- une **limite fonctionnelle acceptée** (couverture géographique partielle, absence de temps réel…) ;
- un **contournement explicitement refusé** et sa raison (ex. ne jamais désactiver la vérification TLS).

Formule chaque point avec **le compromis ET sa raison** : sans la raison, un futur cycle le remettra en
question. Et précise ce qui reste **actionnable** malgré l'arbitrage — par exemple « signale uniquement un
chemin qui *aggraverait* ce résidu ».

S'il n'y a **aucun** arbitrage à ce stade, **laisse le marqueur et son paragraphe en place** : la section
se remplira au fil des cycles `/feature`, dont l'étape 12 prévoit d'y ajouter chaque nouveau compromis.

**c) Vérifie que le squelette renommé est mécaniquement propre.**

```bash
python .claude/scripts/verif-plugin.py --tout
```

Ce script lit l'id dans `plugin_info/info.json` : il **n'a pas** à être personnalisé. Il contrôle fins de
ligne, octets de contrôle, équilibrage structurel, miroir `configuration.txt`/`.php`, JSON i18n et motifs
sensibles. Un `PROBLEME` ici vient du squelette ou du renommage, pas de ton code : corrige-le maintenant,
avant que la première feature ne s'empile dessus. Les `AVIS` sont informatifs.

## Étape 5 — Génération des specs (fan-out `spec-writer`)

Crée les dossiers cibles (`.memory/specs/MVP/`, `.memory/specs/post-mvp/<NN-domaine>/`) au besoin. Lance
les sous-agents **`spec-writer`** **en parallèle, un par domaine** (le socle MVP = un domaine ; chaque
domaine post-MVP = un domaine) — pas un agent par UC. À chaque `spec-writer`, passe le **dossier cible** et
la **liste des UC de ce domaine** (numéro, slug, titre, objectif, `dependsOn`, `acHints`, `analysisRefs`)
extraite de la roadmap validée. Consigne : *« Écris les specs FONCTIONNELLES `NN-nom.md` de ce domaine via
la skill `spec` (critères d'acceptation observables), en t'appuyant sur les analyses pointées. Pas de spec
technique, pas de code. »* Récupère la liste des fichiers écrits par chaque agent.

## Étape 6 — `CLAUDE.md` (identité réelle du plugin)

**Tu** mets à jour `CLAUDE.md` pour qu'il décrive le **vrai plugin** (plus le template générique), en
utilisant l'id `<id>` (le squelette est déjà renommé) :

- **Présentation** : remplace la présentation « template » par le but réel (id, nom, cible,
  lecture/commandes, démon ou pas). Conserve la note « langue des réponses = français ».
- **Architecture** : reflète le modèle eqLogic décidé (ce qu'est un équipement, clé `logicalId`), les
  classes (`<id>`/`<id>Cmd`, la brique API `<id>Api` si prévue), le démon si retenu.
- **Configuration & secrets** : liste les clés de config plugin/équipement prévues et ce qui est chiffré.
- **Feuille de route, specs & mémoire** : pointe vers `.memory/specs/` (roadmap réelle) et les analyses.

**Conserve intactes** les sections de **connaissance générique Jeedom** qui restent vraies (pièges
`packages.json`, autoload « 1 classe ↔ 1 fichier », restriction `configuration.php` → `configuration.txt`,
i18n, CI, conventions). Ne réécris que ce que l'initialisation rend spécifique/obsolète.

## Étape 7 — `README.md` (dépôt / utilisateur)

Réécris `README.md` pour décrire le **plugin réel** (titre = nom du plugin, but, prérequis, grandes
fonctions issues de la roadmap, statut « en développement »). Tu peux garder un renvoi vers la doc Jeedom.
Ne laisse pas le texte « template de plugin ».

## Étape 8 — `specs/README.md` (index de la roadmap)

Réécris `.memory/specs/README.md` pour qu'il décrive **cette** roadmap : l'arborescence réelle (`MVP/` +
domaines post-MVP avec la liste des UC) et la **table d'ordre du MVP** (« # | Titre | Dépend de »). Conserve
la section « Convention » (format des specs) et « Conventions transverses ».

## Étape 9 — Vérifications finales

- `.memory/analyse/INDEX.md` référence bien les nouveaux fichiers d'analyse (l'architecte l'a mis à jour ;
  complète sinon).
- `git status` cohérent avec le renommage (id `<id>` partout dans le code) ; `info.json` a le bon id.
- Chaque UC de la roadmap validée a bien son fichier `NN-nom.md` (recoupe les rapports des `spec-writer`
  avec un `Glob`). Relance le `spec-writer` concerné si une UC manque.
- Aucun secret/donnée sensible dans les fichiers écrits.

## Étape 10 — Présentation finale & prochaine étape

```
✅ Plugin initialisé : <id> — <Nom>

🔤 Squelette renommé : template → <id> (info.json, fichiers, contenu) — sans PHP, via helperConfiguration.py
📄 CLAUDE.md / README.md : mis à jour (identité réelle du plugin)
🧠 Analyses : .memory/analyse/<id>-*.md (+ INDEX à jour)
🗺️  Roadmap : <N> UC MVP + <M> UC post-MVP (<X> domaines)
📋 Specs fonctionnelles : .memory/specs/MVP/* + .memory/specs/post-mvp/**
```

Puis indique la **prochaine étape** : implémenter la première UC avec `/feature 01-<slug>` (qui produit la
spec technique, le code, les reviews et la traduction).

> Rappels : tu n'écris **pas** de logique métier ni de spec technique ici. Toute incertitude de contrat
> externe est consignée en « À confirmer » dans les specs / analyses, à trancher au moment de coder.
