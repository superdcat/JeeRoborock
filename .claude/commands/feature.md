---
description: Fait produire le plan technique d'une feature par le sous-agent jeedom-tech-planner, le fait valider par l'utilisateur, puis délègue l'implémentation à l'agent php-jeedom-dev, exécute les reviews croisées et la traduction.
argument-hint: [nom-de-la-spec]
model: opus
effort: high
---

# Workflow agentic complet — orchestrateur

Tu vas dérouler le workflow complet pour la feature `$ARGUMENTS`.

Tu es l'**orchestrateur** (Opus, `effort: high`). Ton travail : **faire produire puis faire valider le
plan technique**, **déléguer l'implémentation** à l'agent `php-jeedom-dev` (Sonnet), et enfin piloter
reviews croisées, traduction et capitalisation. **Tu ne codes pas toi-même** la feature (étape 6), et
**tu ne rédiges pas le plan toi-même** (étape 2) : l'arbitrage d'architecture est porté par le sous-agent
`jeedom-tech-planner` (Opus, `effort: xhigh`) en contexte isolé. Tu restes responsable du **jugement** sur
son plan, de la validation utilisateur, des gates de review et de la synthèse finale.

**Répartition de l'effort — délibérée, ne l'inverse pas.** La réflexion coûteuse est concentrée là où une
erreur se paie cher : le **plan** (`jeedom-tech-planner`, `xhigh`) et les **reviews** (`code-reviewer` et
`security-reviewer`, `high`). Elle est volontairement basse là où le travail est mécanique et déjà cadré
(`php-jeedom-dev` en `medium`, `translator` en `low`). Corollaire : **ne re-fais pas en `high` le travail
d'un agent en `xhigh`** — si son livrable ne te convient pas, relance-le, ne le remplace pas.

## Consultation doc & connaissance — À LA DEMANDE seulement (lazy)

Tu pilotes à partir de la spec et de `CLAUDE.md` (déjà en contexte). **Ne charge RIEN « par sécurité ».**
Ne consulte une source externe/interne **que si une incertitude concrète te bloque** — typiquement à
l'**étape 7** (vérification du livrable). L'étude des contrats externes du plan est portée par
`jeedom-tech-planner` (étape 2) et la consultation en cours de code par `php-jeedom-dev` via la skill
`dev` : c'est **exprès** que ces lectures ne remontent pas dans ton contexte. Sinon, avance.

Quand c'est le cas, dans cet ordre et en t'arrêtant dès que tu as la réponse — chaque INDEX porte son
propre mode d'emploi en en-tête, ne le redécris pas ici :

1. **Connaissance interne d'abord** (local, gratuit, propre au projet) : `.memory/analyse/INDEX.md`
   (§ 0 = incertitude → fichier), puis ouvre **uniquement** le fichier pointé. `.memory/specs/README.md`
   décrit la convention des specs.
2. **Doc de l'API / service tiers** que pilote le plugin (le cas échéant) : contrat des endpoints,
   payloads, codes d'erreur. Si le plugin cible une API sans doc officielle propre, la source de vérité
   est le **code de référence** (implémentation existante, SDK) — fais **un seul `WebFetch`** ciblé.
3. **Doc Jeedom** (contrat du core) : `.memory/external/doc/jeedom/INDEX.md`. Pour une signature de classe
   core (`cache::`, `config::`, hooks `eqLogic`/`cmd`…), lis la **source du core**, pas le wiki.

Astuce tokens : **`grep` l'INDEX** pour la ligne utile plutôt que de le `Read` en entier. **Cite** l'info
retenue (endpoint, champ, code d'erreur) et sa source. Si une source **contredit** une spec/analyse
interne, **signale l'écart** — ne tranche pas en silence (la doc officielle fait foi sur le contrat,
l'analyse interne sur les décisions projet).

## Étape 1 — Charger la spec fonctionnelle

Lis la spec fonctionnelle `$ARGUMENTS` sous `.memory/specs/` (ex. `.memory/specs/**/$ARGUMENTS.md`).
Confirme en 1-2 phrases ce que tu as chargé. Si aucune spec fonctionnelle n'existe encore pour cette
feature, **demande à l'utilisateur** de la fournir/valider avant de continuer (le plan technique dérive
de ses critères d'acceptation).

## Étape 2 — Plan technique (sous-agent `jeedom-tech-planner`)

**Tu ne rédiges pas le plan.** Invoque le sous-agent **`jeedom-tech-planner`** (Opus, `effort: xhigh`) :
c'est lui qui porte l'arbitrage d'architecture **et** la recherche des contrats externes, en contexte
isolé — ce qui garde ton contexte d'orchestrateur propre sur toute la durée du cycle.

Passe-lui **explicitement** dans son prompt de lancement :

- le **nom de la feature** `$ARGUMENTS` ;
- le **chemin de la spec fonctionnelle** chargée à l'étape 1 ;
- toute **contrainte** exprimée par l'utilisateur dans sa demande (choix déjà arrêté, périmètre à ne pas
  dépasser, décision de recette à respecter).

Il te rend un plan structuré : critères couverts, contrats externes (avec sources citées), architecture
fichier par fichier, signatures, validation/erreurs, impact i18n FR, risques, questions ouvertes.

Relis-le **de façon critique** avant de l'exposer :

- une **question ouverte** qu'il remonte se tranche avec l'utilisateur à l'étape 4, **jamais en silence** ;
- un critère d'acceptation non couvert est un **blocage**, pas un détail ;
- si le plan est hors spec ou incomplet, **relance le même agent** via `SendMessage` (son contexte est
  conservé) plutôt que d'écrire le plan à sa place — c'est lui qui est en `xhigh`.

**Conserve son `agentId`** : tu peux avoir à le rappeler à l'étape 3 (contradiction avec l'advisor) ou à
l'étape 4 (ajustement demandé par l'utilisateur).

## Étape 3 — Challenge par advisor

Invoque le sous-agent `code-reviewer` en mode advisor (revue critique du **plan**, pas du code) : risques
d'architecture, points de convention, suggestions. Présente la synthèse.

Si l'advisor **contredit** le planner sur un point d'architecture, **ne tranche pas seul** : renvoie la
contradiction au planner via `SendMessage`, ou porte-la explicitement à l'utilisateur à l'étape 4. Deux
avis divergents laissés non arbitrés dans la spec technique se paient en dette d'implémentation.

## Étape 4 — Validation utilisateur du plan

**ARRÊTE-TOI ICI** et demande :

> "Le plan technique te convient-il ? Veux-tu ajuster avant l'implémentation ? (oui / propose des ajustements)"

Attends sa réponse.

## Étape 5 — Écriture de la spec technique

Plan validé, écris la spec technique dans `.memory/specs/[même dossier]/$ARGUMENTS-tech.md` :

```markdown
# Spec technique — $ARGUMENTS

## Architecture
[composants, fichiers, structure]

## Server vs Client
[décision et justification]

## Validation
[stratégie côté client et serveur]

## Server Actions / API
[signatures et logique]

## Dépendances
[paquets à installer si nécessaires]
```

## Étape 6 — Délégation de l'implémentation à l'agent `php-jeedom-dev`

**Tu ne codes pas.** Invoque le sous-agent **`php-jeedom-dev`** (Sonnet, `effort: medium`) pour écrire le
code à partir de la spec technique. Son effort est volontairement modéré : le plan est **déjà arbitré**,
son travail est de l'exécuter et de boucler jusqu'au vert. Corollaire à surveiller à l'étape 7 — s'il
remonte une **décision d'architecture** au lieu de l'avoir prise, c'est le comportement attendu : c'est à
toi (ou au planner) de la trancher, pas à lui. Il tourne en contexte neuf : passe-lui **explicitement** dans son
prompt de lancement :

- le **nom de la feature** `$ARGUMENTS` ;
- le **chemin de la spec fonctionnelle** (celle de l'étape 1) et le **chemin de la spec technique**
  `…-tech.md` (celle de l'étape 5) — ce sont ses entrées de travail ;
- la consigne : *« Lis `CLAUDE.md`, la spec technique (plan) et la spec fonctionnelle (critères
  d'acceptation), puis implémente via la skill `dev` en bouclant jusqu'à convergence. i18n : français
  uniquement, ne touche pas aux `core/i18n/*.json`. Ne commite pas. Rends le rapport structuré. »*

L'agent boucle en interne (skill `dev` : cadrer → implémenter → vérifier → auto-revue → itérer) jusqu'à
ce que les critères d'acceptation et sa checklist qualité soient verts, puis **te rend un rapport
structuré** (fichiers modifiés, état des critères, chaînes UI FR introduites, points « à confirmer »).

**Conserve l'`agentId` renvoyé** : tu réutiliseras le **même agent** (via `SendMessage`) pour lui faire
corriger les findings de review à l'étape 9, ce qui préserve son contexte d'implémentation.

**i18n : le code est écrit en français, point.** L'agent enveloppe chaque chaîne UI
(`{{Texte français}}` / `__('Texte français', __FILE__)`) mais **ne touche PAS** aux
`core/i18n/{en_US,de_DE,es_ES}.json` : la traduction est déléguée au sous-agent `translator` en étape 10,
sur le code figé.

## Étape 7 — Réception du livrable & vérification

**Commence par lancer le script de vérifications mécaniques** — il fait en un passage tout ce qui était
jusqu'ici réinventé en `grep`/`sed` ad hoc à chaque tour :

```bash
python .claude/scripts/verif-plugin.py          # les fichiers modifiés selon git
```

Il contrôle : fins de ligne (par **comptage d'octets** — un `grep -c $'\r'` mal échappé a déjà produit un
faux « tout est en CRLF »), octets de contrôle bruts (texte à échappements corrompu), équilibrage
structurel `{}`/`()`/`[]` **en ignorant chaînes et commentaires** (un compte naïf sur du HTML français
produit un faux déséquilibre), synchronisation du miroir `configuration.txt`/`.php` (via
`git diff --numstat`, sans jamais ouvrir le `.php`), validité et couverture des JSON i18n, chaînes JS
délimitées par des apostrophes simples, et motifs sensibles (`CURLOPT_VERBOSE`, `getTraceAsString`,
`SSL_VERIFYPEER false`, `var_dump`…).
Code de retour `1` s'il reste un **PROBLEME**. Les **AVIS** ne bloquent pas : lis-les, ils sont
volontairement inclusifs (un `displayException()` légitime dans un `catch (Exception)` générique y
apparaît, par exemple).

Puis, à partir du **rapport de l'agent** (étape 6), fais la **vérification de fond** (l'agent a déjà
auto-vérifié ; toi tu contrôles, tu ne re-codes pas) :

- **Couverture** : chaque critère d'acceptation est couvert ou explicitement « à valider en recette ».
- **Fidélité spec technique** : les fichiers touchés et le chemin d'appel correspondent au plan de
  l'étape 5 ; tout écart signalé par l'agent est acceptable/justifié.
- **Contrats externes** : endpoints/topics, paramètres/payloads et parsing correspondent au contrat réel
  (recoupe interne + doc/code de référence au moindre doute résiduel ; signale tout écart
  code/spec/analyse/doc). Applique *Consultation à la demande* si un doute concret subsiste.
- **i18n** : vérifie **uniquement l'enveloppage français** (aucune chaîne en dur). La couverture des
  langues cibles n'est **pas** attendue ici (étape 10) — ne la compte pas comme un défaut.

Si le livrable est manifestement incomplet ou hors plan, **renvoie-le à l'agent** (`SendMessage`) avant
de lancer les reviews.

## Étape 8 — Reviews croisées

C'est **ta gate de review indépendante** (l'auto-revue de l'agent ne la remplace pas). Sur les fichiers
créés/modifiés **listés dans le rapport de l'agent** (étape 6), lance **en parallèle** :
- sous-agent `security-reviewer` ;
- sous-agent `code-reviewer` (passe-lui la spec technique en contexte pour la review « cohérence spec »).

Les invariants permanents du projet (miroir `configuration.txt`/`.php`, traduction différée, carve-out
`configKey`, indentation par fichier) sont **portés par les définitions des deux agents** : ne les
répète pas dans ton prompt. Passe-leur seulement le **nom de la feature**, la **liste des fichiers**, le
**chemin de la spec technique**, et ce que tu veux voir vérifié en priorité.

### ⚠️ Plafond : DEUX tours de review, pas plus

| Tour | Périmètre |
|---|---|
| **1** | review **complète** des fichiers du livrable |
| **2** | **delta uniquement** — vérifier que les correctifs tiennent et **chasser les régressions**. Interdiction explicite de re-auditer ce qui a déjà été validé au tour 1 |

Au tour 2, dis-leur de **conclure explicitement** par « reste-t-il un `critical`/`high` ? » (sécurité) et
« reste-t-il un `blocker`/`major` ? » (qualité) — c'est cette réponse qui pilote la gate, pas la liste
des findings.

**Au tour 2, passe-leur un DIFF plutôt que la liste des fichiers.** Les reviewers n'ont ni `Bash` ni `git`
— écris le diff dans le scratchpad et donne-leur le chemin :

```bash
git diff -- <fichiers du delta> > "$SCRATCHPAD/delta-<feature>-tour2.diff"
```

Donne-leur **les deux** : le chemin du diff **et** la liste des fichiers, en précisant qu'ils partent du
diff et n'ouvrent un fichier que là où le diff ne suffit pas à juger (un diff sans contexte produit des
faux positifs). Cela leur évite de relire intégralement 5 fichiers plus les documents de référence pour
juger une vingtaine de lignes changées.

**Après le tour 2, la boucle s'arrête.** Tout finding qui n'atteint pas la gate
(`critical`/`high`/`blocker`/`major`) part dans une section **« Dette »** de la spec technique
`…-tech.md` — il n'est **pas** corrigé dans ce cycle et ne déclenche **pas** de tour supplémentaire.
S'il reste un finding **au-dessus** de la gate après le tour 2, ne relance pas de ton propre chef :
**signale-le à l'utilisateur** et laisse-le trancher entre un 3ᵉ tour et la dette.

> **Pourquoi ce plafond** : mesuré sur les cycles UC01 et UC02 du MVP, le 3ᵉ tour n'a produit **aucun**
> finding atteignant la gate (uniquement des `minor`/`low` cosmétiques ou de diagnostic), pour ~130k
> tokens de review plus la reprise de l'agent développeur. Les tours 1 et 2, eux, ont trouvé des `major`
> réels — dont un bug de perte de données. Le plafond coupe le tour improductif, pas les tours utiles.

Présente une synthèse **brève** : tableau des findings (sévérité / fichier / enjeu en une ligne) et
décision. Ne reformule pas le contenu des reviews.

## Étape 9 — Décision finale utilisateur

Si findings `critical`/`high` (ou `blocker`/`major`), demande :

> "Reviews terminées. [N findings critiques/high]. Je propose des fix maintenant ou je continue ? (fix / continue)"

Attends la réponse. Si fix demandés : **ne corrige pas toi-même** — renvoie les findings à l'agent
`php-jeedom-dev` via `SendMessage` (même `agentId` qu'à l'étape 6, pour réutiliser son contexte
d'implémentation), en lui listant précisément les findings à traiter.

### ⚠️ UN SEUL lot de correctifs par tour

**Attends les DEUX reviews, fusionne-les, et envoie un lot unique et consolidé.** Jamais deux
`SendMessage` de correctifs pour un même tour.

> **Pourquoi** : le contexte accumulé de l'agent développeur est **re-facturé intégralement à chaque
> reprise**. Mesuré sur le cycle UC02 : 234k → 342k → 419k → 495k tokens pour 4 lots successifs, alors
> que le dernier ne portait que 8 corrections mécaniques. Un lot unique par tour supprime ces reprises.

Si tu découvres un point oublié **après** avoir envoyé le lot, ne l'envoie pas séparément : ajoute-le au
lot du tour suivant, ou traite-le dans la passe de finition (ci-dessous).

### Quel agent pour le lot : reprise ou agent neuf ?

| Nature du lot | Agent |
|---|---|
| Findings qui touchent à la **conception** (contrat, chemin d'appel, classement d'erreurs, architecture) | **Reprends** l'agent d'implémentation (`SendMessage`, même `agentId`) : son contexte est nécessaire |
| Lot **purement mécanique et localisé** — moins de ~10 points, aucune re-conception (un cast, une ancre de regex, un filtre, un `timeout`, des point-virgules, un commentaire faux) | **Agent NEUF** |

Pour un agent neuf, donne-lui : le chemin de la **spec technique**, un **diff** dans le scratchpad, la
**liste numérotée des points** avec pour chacun le fichier, la ligne et la correction attendue, et les
rappels de procédure. Les invariants du projet sont déjà dans sa définition d'agent.

> **Pourquoi** : le contexte accumulé de l'agent d'implémentation est re-facturé **intégralement** à
> chaque reprise. Mesuré sur UC02 : la dernière reprise, pour 8 corrections mécaniques, a coûté ~76k
> tokens là où un agent neuf en aurait consommé la moitié — et sans faire croître le suivant.
> ⚠️ **Contrepartie réelle** : un agent neuf n'a pas le contexte d'implémentation. Ne l'utilise pas pour
> un finding qui demande de comprendre *pourquoi* le code est écrit ainsi.

### Fin de boucle

Après le **tour 2** de reviews (cf. plafond, étape 8) :
- s'il ne reste plus de finding au-dessus de la gate → **passe à l'étape 10** ;
- s'il reste des `minor`/`low` que tu juges utiles et **ponctuels**, tu peux les regrouper en **une seule
  passe de finition** avant la traduction — un lot, sans review de contrôle derrière ;
- tout le reste part en **section « Dette »** de la spec technique.

⚠️ **Corrige aussi la spec technique** quand une review a mis en évidence non pas un défaut du code mais
une **erreur de raisonnement de ta spec** (contrat contradictoire, justification fausse, contrat de
signature mal posé). C'est le cas le plus rentable de tout le cycle : la spec sert de référence aux UC
suivantes, une erreur qu'on y laisse est recopiée.

## Étape 10 — Traduction (sous-agent `translator`) — **une fois tout validé**

Le code FR est figé et validé. Invoque le sous-agent `translator` sur les fichiers créés/modifiés. Il doit :
- extraire toutes les clés UI françaises (`{{...}}` / `__()`) introduites/modifiées ;
- remplir/mettre à jour `core/i18n/{en_US,de_DE,es_ES}.json` sous `plugins/template/<fichier>` ;
- garantir la **couverture complète des langues cibles**, signaler toute **clé orpheline** ;
- **valider lui-même que les JSON parsent** avant de rendre la main (il dispose de `Bash` et lance la
  validation Python ; il ne rend `pass` que si les fichiers parsent). **Tu n'as donc PAS à re-valider les
  JSON au retour** — c'est garanti par le sous-agent ;
- si la `description` du plugin a changé : mettre à jour `info.json` (toutes langues) et
  `docs/{fr_FR,en_US,de_DE,es_ES}/`.

Si verdict `needs_changes` (clés manquantes / JSON invalide non corrigé), relance jusqu'à couverture
complète et JSON valides. Présente la synthèse (clés ajoutées par langue, orphelines éventuelles).

## Étape 11 — Présentation finale

```
✅ Feature : $ARGUMENTS

📋 Spec fonctionnelle : .memory/specs/*/$ARGUMENTS.md
📐 Spec technique : .memory/specs/*/$ARGUMENTS-tech.md
💻 Feature : [fichiers créés/modifiés]
🔒 Review sécurité : [verdict]
🎯 Review qualité : [verdict]
🌍 Traduction (en/de/es) : [verdict translator — clés ajoutées par langue]
```

## Étape 12 — Capitalisation mémoire (apprentissages durables)

**Avant de clore**, capture ce que ce cycle a révélé et qui **servira aux features suivantes** —
**uniquement si c'est réellement nouveau**. Si tout est déjà couvert (specs, `CLAUDE.md`, code, doc
locale, git), **n'écris rien** (ni fichier, ni ligne, ni note « rien de neuf ») et clôture.

**Que retenir** (typique) : contrat d'une API tierce non évident confirmé contre le code de référence
(nom/type réel d'un champ, payload exact d'une commande, schéma d'auth) ; code d'erreur et son sens réel ;
comportement empirique d'un quota/limite (rate-limit, expiration token) ; piège du core Jeedom (hook,
récursion `save`, autoload, démon/socket) ; décision d'archi prise pendant le dev.

**Où écrire** (selon la nature) :
1. **Mémoire persistante inter-sessions** (`MEMORY.md` + fichier sous le dossier mémoire de l'agent,
   chargée auto chaque session) : pour un apprentissage transverse utile dès le prochain démarrage.
   Format : 1 fichier = 1 fait (frontmatter `name`/`description`/`metadata.type`, corps FR, liens
   `[[autre]]`), **puis** une ligne de pointeur dans `MEMORY.md`.
2. **`.memory/analyse/`** (**versionné, partagé équipe**) : pour une analyse/décision/limite **propre au
   projet ou générique Jeedom**. ⚠️ N'est utile que s'il reste **découvrable** → écris dans le fichier
   thématique existant (ou crée-en un) **ET mets `.memory/analyse/INDEX.md` à jour** (ligne + déclencheurs
   § 0 + date) — sinon un futur `/feature` ne le relira jamais. Alternative : la spec technique `…-tech.md`
   si l'info est strictement locale à la feature.

3. **La section « Décisions arbitrées » des deux reviewers** (`.claude/agents/code-reviewer.md` et
   `.claude/agents/security-reviewer.md`, marqueur `<!-- init-plugin:invariants -->`) : **uniquement**
   quand l'utilisateur a **arbitré un compromis** pendant ce cycle — un point qu'un reviewer signalerait
   sinon à **chaque** feature, alors qu'il est tranché. Formule le compromis **ET sa raison**, et précise
   ce qui reste actionnable malgré l'arbitrage (typiquement : « signale uniquement un chemin qui
   *aggraverait* ce résidu »).
   ⚠️ N'y mets **pas** un défaut à corriger, ni une dette : ces sections servent à **supprimer des faux
   positifs récurrents**, pas à excuser du code. Un arbitrage qui s'y ajoute doit aussi être visible dans
   `CLAUDE.md` — c'est là que l'humain le relira.

> Un apprentissage transverse important mérite souvent **(1) + (2)** : en (1) le fait condensé, en (2)
> l'analyse détaillée — qui se référencent, sans doublon littéral.

**Règles** : avant d'écrire, **vérifie l'existant** (`MEMORY.md`, `.memory/analyse/INDEX.md` + fichier) et
mets à jour plutôt que dupliquer ; supprime une note devenue fausse. N'enregistre **pas** ce que
code/git/`CLAUDE.md`/specs disent déjà. Dates absolues ; **jamais** de secret/token.

Si tu as mémorisé : présente-le en 1-3 lignes. Sinon, ne dis rien de spécial et clôture.

## Étape 13 — Mise à jour de `CLAUDE.md` (fin de cycle)

`CLAUDE.md` est **lu par toute future session** : une affirmation qui devient fausse après cette feature
(architecture non actualisée, mention « à créer »/« reste à faire » visant un fichier/classe qui existe
désormais) transmet une fausse information à chaque `/feature` suivant. Avant de clore, vérifie et corrige
**uniquement ce que cette feature a rendu faux** :

- **Section Architecture** : si un composant décrit comme futur (« à créer », « prévu ») vient d'être
  implémenté, reformule au présent — ou l'inverse si une feature retire/déplace un composant documenté.
- **Autres sections** (Configuration & secrets, Conventions, i18n…) : seulement si cette feature a
  introduit une clé de config, un fichier, ou une convention qui n'y figure pas encore.

**Ne réécris pas** ce qui reste vrai. Pas de refonte ni de reformulation cosmétique — uniquement les
phrases concrètement rendues obsolètes ou incomplètes par `$ARGUMENTS`. Si rien n'a changé dans
`CLAUDE.md`, ne dis rien de spécial et clôture.
