---
name: spec
description: Rédige une spec FONCTIONNELLE de feature (use case) pour un plugin Jeedom, au format attendu par le workflow /feature. Active-toi quand il faut écrire/mettre à jour une ou plusieurs specs fonctionnelles NN-nom.md (bootstrap via /init-plugin, ou ajout d'une UC). Tu écris le « quoi » et les critères d'acceptation, pas le « comment » (ça, c'est la spec technique produite par /feature).
---

# Skill — Rédaction de spec fonctionnelle (use case Jeedom)

Objectif : produire une **spec fonctionnelle** `NN-nom.md` claire, testable, cohérente avec la roadmap et
les analyses internes. Une spec fonctionnelle décrit **le quoi et le pourquoi** ; **jamais** le comment
(architecture, signatures, fichiers → c'est la spec technique `NN-nom-tech.md`, produite plus tard par
`/feature`, pas ici).

## Quand t'activer
- L'agent **`spec-writer`** t'invoque pour écrire les specs d'un domaine (cas nominal, via `/init-plugin`).
- L'utilisateur demande d'écrire/mettre à jour une spec fonctionnelle d'UC.

## Entrées

On te fournit, pour chaque UC à écrire : le **numéro** (`NN`), le **slug** (`nom`), le **domaine** (dossier
cible), le **titre**, l'**objectif**, les **dépendances** (« Dépend de »), des **pistes de critères
d'acceptation**, et les **pointeurs d'analyse** pertinents (`.memory/analyse/*.md`). Charge `CLAUDE.md`
(conventions) et les analyses pointées avant d'écrire ; ne réinvente pas un contrat déjà tranché.

## Format d'une spec fonctionnelle (gabarit)

```markdown
# UC<NN> — <Titre>

> **Domaine** : <domaine> · **Statut** : à implémenter · **Dépend de** : <UCxx, UCyy | —>

## Objectif
<La valeur pour l'utilisateur : quel besoin cette UC couvre, pourquoi elle existe. 2-4 phrases.>

## Comportement attendu
<Le « quoi », du point de vue utilisateur/système : ce qui se passe, quand, ce que voit l'utilisateur.
Décris les cas nominaux ET les cas dégradés attendus (config vide, service injoignable, données absentes).
Pas de détail d'implémentation.>

## Critères d'acceptation
- [ ] **AC1** — <énoncé OBSERVABLE et vérifiable en recette (pas « le code fait X » mais « après Y,
      l'utilisateur/le système constate Z »)>
- [ ] **AC2** — …
<Chaque AC est une condition binaire de succès. C'est la *definition of done* lue par /feature.>

## Impact i18n
- Nouvelles chaînes UI (français) anticipées : « … », « … ». (Liste indicative ; enveloppage/traduction
  gérés à l'implémentation.)

## À confirmer
- <Points de contrat externe (endpoint, champ, code d'erreur, quota) NON tranchés, à valider contre le
  code de référence / la doc au moment de coder. Renvoie au fichier d'analyse concerné.>

## Hors périmètre
- <Ce que cette UC ne traite PAS (et vers quelle autre UC ça renvoie), pour cadrer nettement.>
```

## Règles de rédaction

- **Critères d'acceptation observables** : formulés en résultat constatable (« après login + collage du
  code, le test de connexion affiche N équipements », « 2ᵉ synchro → 0 doublon »), pas en termes de code.
  Ce sont eux que `/feature` transforme en checklist et que la recette manuelle vérifie.
- **Une UC = un fichier** = un incrément livrable/reviewable. Si un « use case » est trop gros (plusieurs
  livrables indépendants), le découper en plusieurs UC numérotées.
- **Dépendances explicites** : renseigner « Dépend de » (UC amont indispensables). Le MVP a un ordre
  interne strict ; le post-MVP dépend du socle MVP.
- **Pas de comment** : aucune signature, aucun nom de fichier/méthode, aucune décision d'archi dans une
  spec fonctionnelle. Si une contrainte technique est structurante, la mentionner en « À confirmer » ou
  renvoyer à l'analyse — pas la trancher ici.
- **Fidélité aux analyses** : t'appuyer sur `.memory/analyse/*.md` (contrat API, modèle de données,
  décisions). En cas d'incertitude sur un contrat externe, l'écrire en « À confirmer » plutôt que
  d'inventer.
- **Langue FR** ; ton concis. Marquer d'un `⚠️` un écart/risque important (limite d'API, ToS, quota…).
- **Numérotation** : dossiers par dizaines, fichiers par unités (`NN-nom.md`). MVP = `01`→`NN`. Post-MVP
  regroupé par domaine (`10-<domaine>/`, `20-<domaine>/`…).

## Sortie (rapport)

Rends la liste des fichiers écrits (chemin + titre) et signale toute UC dont l'objectif/les dépendances
te semblent ambigus (pour arbitrage par l'orchestrateur) — mais **n'écris pas** de spec technique ni de
code.
