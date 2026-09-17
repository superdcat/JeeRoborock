---
description: Revient sur une décision prise automatiquement par /auto-dev — retrouve l'arbitrage dans recap.md, applique le choix inverse (code, specs, migration de l'existant, i18n), puis journalise la révision. Conçu pour démarrer en contexte vide.
argument-hint: '<explication de ce qui aurait dû être décidé>  |  D-MVP04-02 <explication>  |  --liste'
model: opus
effort: high
---

# `/change` — défaire un choix de `/auto-dev`

Demande : **`$ARGUMENTS`**

`/auto-dev` a tranché seul des dizaines d'arbitrages. Certains seront les bons, d'autres non. Cette
commande prend **un** arbitrage, applique **l'autre** option, et laisse le dépôt cohérent :
code, specs, migration de l'existant, traductions, et trace de la révision.

Tu démarres possiblement en **contexte vide** : `recap.md` est ta seule porte d'entrée. Tout ce que
tu as besoin de savoir y est — c'est justement pour ça qu'il a été écrit.

## Étape 0 — `--liste`

Si `$ARGUMENTS` vaut `--liste` (ou est vide) : affiche l'index des décisions et arrête-toi.

```bash
sed -n '/^## Index des decisions/,/^## Cycles executes/p' recap.md
```

Rien à faire de plus : pas de plan, pas de question. L'utilisateur revient avec un ID.

## Étape 1 — Retrouver la décision visée

```bash
test -f recap.md || echo "ABSENT"
grep -n '^### D-' recap.md
```

Si `recap.md` est absent : dis-le et arrête-toi. Il n'y a pas de décision journalisée à réviser —
soit `/auto-dev` n'a jamais tourné, soit le fichier a été supprimé (il se régénère avec
`python .claude/scripts/auto-dev.py recap`). **N'improvise pas** un changement « au feeling » : sans
la décision d'origine, tu ne connais ni les alternatives déjà écartées, ni pourquoi.

Deux cas :

- **Un ID est cité** dans `$ARGUMENTS` (`D-MVP04-02`) → cible directe.
- **Sinon**, rapproche l'explication de l'index (`sujet`, `statut`). Ne charge pas tout le fichier :
  travaille sur les titres, puis extrais **la seule section retenue** :

```bash
awk '/^### D-MVP04-02 /{f=1;print;next} f&&(/^### /||/^## /||/^---$/){exit} f' recap.md
```

(l'espace final dans le motif est **volontaire** : sans lui, `D-MVP04-02` attrape aussi
`D-MVP04-02R1`, sa propre révision.)

Si **une seule** décision correspond nettement, poursuis sans demander. Si **plusieurs** sont
plausibles, ou si la demande pourrait vouloir dire deux choses très différentes, pose **une**
`AskUserQuestion` avec les ID candidats (en-tête = l'ID, description = le sujet + le statut). Se
tromper de décision coûte un revirement inutile **et** masque le vrai problème.

Si **aucune** ne correspond : dis-le clairement, propose les 2-3 ID les plus proches, et arrête-toi.
Une demande qui ne cible aucune décision journalisée n'est pas un `/change` — c'est probablement une
feature (`/feature`) ou une correction directe.

Si la décision est déjà marquée **révisée**, dis-le et travaille sur la **dernière** révision en
date : c'est elle qui décrit l'état réel du code.

## Étape 2 — Charger le contexte, et lui seul

La section de décision te donne exactement quoi ouvrir : ses rubriques **Portée dans le code**,
**Coût d'un revirement** et **Traçabilité**. Charge **ça**, et rien d'autre :

- les fichiers cités dans « Portée dans le code » ;
- la spec technique citée, **la section concernée** ;
- la spec fonctionnelle **seulement si** le revirement touche un critère d'acceptation.

Puis **vérifie que la décision décrit encore la réalité** — elle peut avoir des mois. Si le code a
divergé de ce qui est écrit, dis-le explicitement avant de continuer : le plan de revirement doit
partir du code réel, pas de la décision périmée.

⚠️ Ne fais pas confiance à une rubrique **Portée dans le code** incomplète. Avant de planifier,
un `grep` de contrôle sur les symboles cités : un `logicalId`, une clé de configuration ou une
constante ont souvent des usages que le runner n'avait pas listés — et ce sont eux qui cassent.

## Étape 3 — Plan de revirement

**Si le revirement touche plus d'un fichier, un contrat externe, une structure de données ou un
identifiant** : délègue le plan au sous-agent **`jeedom-tech-planner`** (c'est lui qui est en
`xhigh`), en lui passant la section de décision, la nouvelle intention de l'utilisateur, et les
chemins des specs. Sinon (une valeur, une borne, un libellé, une ligne de table), planifie
directement — un sous-agent pour changer une constante coûte plus qu'il ne rapporte.

Le plan doit couvrir **cinq** points. Les quatre premiers sont mécaniques ; le cinquième est celui
qu'on oublie et qui casse une installation en production :

1. **Nouvelle décision**, avec ses valeurs concrètes (le pendant exact de la rubrique « Décision »).
2. **Code** : fichiers et symboles à modifier.
3. **Specs** : la spec technique **doit** être corrigée (elle sert de référence aux UC suivantes —
   une erreur qu'on y laisse est recopiée) ; la spec fonctionnelle seulement si un critère change.
4. **i18n** : clés françaises ajoutées, modifiées ou devenues orphelines.
5. **Migration de l'existant** — le plugin est **déjà installé** chez l'utilisateur. Une décision
   changée peut laisser derrière elle : une clé de configuration renommée (l'ancienne valeur reste
   en base), un `logicalId` de commande déjà posé sur des équipements (les scénarios de
   l'utilisateur le référencent), un cache au format précédent, des équipements créés avec l'ancien
   schéma. Pour chacun : convertir, purger, ou **assumer et le dire**. « Aucune migration » est une
   réponse valable — mais elle doit être un constat, pas un oubli.

## Étape 4 — Validation utilisateur

**ARRÊTE-TOI** et présente le plan (une vingtaine de lignes maximum), en faisant ressortir la
migration et les régressions possibles :

> « Voilà ce que ce revirement implique. Je l'applique ? (oui / ajuste) »

Exception : si `$ARGUMENTS` contient `--auto`, applique sans demander. Réservé à un revirement dont
la portée est manifestement locale et sans migration.

## Étape 5 — Appliquer

1. **Corrige d'abord la spec technique** : c'est l'entrée de travail du développeur. Écrire le code
   avant la spec, c'est garantir que l'un des deux restera faux.
2. Délègue au sous-agent **`php-jeedom-dev`** : chemins des specs, section de décision d'origine,
   plan de revirement, liste numérotée des changements attendus. Précise **explicitement** ce qui
   ne doit **pas** bouger — un revirement mal borné devient une refonte.
3. `python .claude/scripts/verif-plugin.py` — code de retour **0** exigé.
4. **Reviews croisées** sur le diff (`code-reviewer` + `security-reviewer`, en parallèle) :

```bash
git diff -- <fichiers> > "$SCRATCHPAD/change-<ID>.diff"
```

   **Un seul tour** par défaut. Un second **uniquement** s'il reste un `critical`/`high`/`blocker`/
   `major` — et pas au-delà (cf. le plafond de `/feature`, mesuré : le 3ᵉ tour ne produit rien).
   Demande-leur en priorité de chasser la **régression** : un revirement casse plus souvent le code
   *autour* du changement que le changement lui-même.
5. **`translator`** si et seulement si des chaînes UI ont bougé.

## Étape 6 — Journaliser la révision

Écris `.memory/auto-dev/revisions/<AAAA-MM-JJ>-<ID original>-R<n>.md`, au gabarit **« révision »**
de `.claude/templates/recap-section.md` — dont les deux champs de liaison, sans lesquels le lien
avec la décision d'origine est perdu :

- `- **Statut** : appliqué`
- `- **Révise** : <ID original>` ← c'est **cette ligne** que le script lit pour marquer l'ancienne
  décision comme révisée dans l'index.

Cite la demande de l'utilisateur (`Motif du revirement`) : dans six mois, c'est la seule trace du
*pourquoi*. Et remplis **Migration réellement effectuée** — y compris ce qui reste à la charge de
l'utilisateur (« purger le cache », « recréer l'équipement »), qu'il faudra lui redire à l'étape 7.

Écris cette entrée avec le même soin que l'originale : elle deviendra la décision de référence, et
un futur `/change` n'aura qu'elle.

```bash
python .claude/scripts/auto-dev.py recap
```

Le script régénère `recap.md` : l'ancienne décision reste visible, marquée révisée. **N'édite jamais
`recap.md` à la main.**

## Étape 7 — Commit et restitution

```bash
git add -- <fichiers> .memory/specs .memory/auto-dev recap.md
```

```text
change(<CLE-UC>): <résumé du revirement> [<ID> → R<n>]

Décision révisée : <ID> — <titre>
Nouvelle décision : <ID>R<n>
Motif : <demande de l'utilisateur, une ligne>
Migration : <ce qui a été fait, ou « aucune »>

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>
```

Sur `master`, **jamais de push**. Ne touche pas à `pluginVersion` (le hook `pre-commit` s'en charge).

Restitution en cinq lignes : décision révisée → nouvelle décision, fichiers touchés, verdicts de
review, **action manuelle attendue de l'utilisateur s'il y en a une** (c'est la ligne la plus utile
du lot), commit.

Enfin, si le revirement a rendu fausse une phrase de `CLAUDE.md` (architecture, clé de
configuration, convention), corrige-la — **uniquement** cette phrase. `CLAUDE.md` est lu par toutes
les sessions suivantes : une affirmation périmée s'y propage à chaque `/feature`.
