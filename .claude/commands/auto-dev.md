---
description: Enchaîne plusieurs cycles /feature en autonomie complète sur une liste d'UC — décisions prises et journalisées par l'orchestrateur, un commit par UC sur master, reprise après coupure, récapitulatif final dans recap.md.
argument-hint: 'MVP 04 .. MVP 08  |  MVP 04, MVP 06, MVP 08  |  (vide = reprendre le run interrompu)'
model: opus
effort: high
---

# `/auto-dev` — développement en série, sans humain dans la boucle

Demande : **`$ARGUMENTS`**

Tu enchaînes des cycles `/feature` complets sur une liste d'UC, **sans jamais rendre la main entre
deux**. À chaque point où `/feature` aurait posé une question, la réponse est **décidée puis
journalisée**. Chaque UC terminée est **commitée sur `master`**. À la fin, `recap.md` à la racine
rassemble tous les arbitrages, dans un format que `/change` sait relire **en contexte vide**.

## Ton rôle : chef d'orchestre, et rien d'autre

Tu ne planifies pas, tu ne codes pas, tu ne review pas, tu ne décides pas des arbitrages
techniques. Une UC = **un sous-agent `auto-dev-runner`** lancé en contexte neuf, qui déroule le
cycle entier et écrit tout sur le disque.

**Ton contexte est la ressource critique du run.** Il doit rester quasi plat de la première UC à la
dernière, sinon la dernière coûte cinq fois la première. Donc :

- ❌ **N'ouvre aucun fichier de spec, aucun fichier de code, aucun `decisions.md`.** Jamais, même
  « juste pour vérifier ». Ces contenus vivent dans les contextes jetables des runners.
- ❌ **Ne relis pas `recap.md`** : tu le fais régénérer par le script, tu n'en lis pas la sortie.
- ✅ Tu ne connais du monde que : la sortie de `auto-dev.py` (courte et tabulaire) et le rapport de
  25 lignes de chaque runner.
- ✅ Entre deux UC, **n'accumule rien** : une ligne de synthèse par UC dans ton fil, c'est tout. Ne
  reformule pas les rapports, ne les compile pas au fil de l'eau — le récapitulatif est produit par
  le script à la fin, à partir du disque.

## Étape 1 — Pré-vol (une seule passe, tout en bash)

```bash
git rev-parse --abbrev-ref HEAD
git config --get core.hooksPath
git ls-files -s .githooks/pre-commit
git status --short
python .claude/scripts/auto-dev.py resolve "$ARGUMENTS"     # si $ARGUMENTS est vide, voir plus bas
```

Traite les anomalies **maintenant** — pas dans deux heures, au milieu du run :

| Anomalie | Ce que tu fais |
|---|---|
| `core.hooksPath` ≠ `.githooks` | `git config core.hooksPath .githooks`. Sans le hook, `pluginVersion` ne bouge pas et **aucune UC n'atteindra jamais l'installation Jeedom** de l'utilisateur (cf. `CLAUDE.md`). |
| `.githooks/pre-commit` n'est pas en `100755` dans l'index | `git update-index --chmod=+x .githooks/pre-commit`. |
| Branche ≠ `master` | Question bloquante (ci-dessous). L'utilisateur a demandé des commits sur `master`, mais pas de bascule de branche à l'aveugle. |
| Arbre de travail sale | Question bloquante. Le premier runner commiterait par-dessus du travail qui n'est pas le sien. |
| Une UC sans spec fonctionnelle | Elle est marquée `bloque` par le script. Signale-la dans le plan, **ne la retire pas** : elle apparaîtra comme telle dans le récapitulatif. |
| Ordre non croissant, ou prédécesseur non implémenté | Signale l'avertissement du script dans le plan et **continue** : c'est peut-être voulu. Les runners le constateront de toute façon. |

### La seule question que tu es autorisé à poser

`AskUserQuestion`, **une seule fois, au pré-vol**, et **uniquement** si l'arbre est sale ou si tu
n'es pas sur `master`. Passé le lancement, plus aucune question : le run va durer, et une question
posée à la troisième UC bloque tout le reste pour rien.

Options à proposer pour un arbre sale : *commiter l'existant d'abord* (recommandé, tu proposes un
message), *laisser tel quel — les runners n'indexeront que leurs propres fichiers*, *arrêter*.

Si le pré-vol est propre, **ne demande rien et démarre**. « Mode automatique » veut dire ça.

### Sans argument : reprise

`$ARGUMENTS` vide = **reprise du run interrompu le plus récent**. Fais
`python .claude/scripts/auto-dev.py status` et repars de sa ligne `SUIVANT:`. S'il affiche
`AUCUN_RUN` ou `RESTE: 0`, dis-le en une ligne et arrête-toi — n'invente pas une liste d'UC.

## Étape 2 — Ouvrir (ou reprendre) le run

```bash
python .claude/scripts/auto-dev.py init "$ARGUMENTS"
```

`REPRISE` s'affiche si un run de la **même demande** existe déjà : les UC `termine` sont conservées
et **sautées**. C'est ce qui rend une coupure (crédit épuisé, réseau, session fermée) sans
conséquence : relancer `/auto-dev` avec la même demande reprend exactement là où ça s'est arrêté.
`RIEN_A_FAIRE` (code 2) = tout est déjà fait : régénère le récap (étape 4) et clôture.

Annonce alors le plan en **un tableau court** : UC, état, spec. Puis démarre — sans demander
confirmation.

## Étape 3 — La boucle : une UC, un runner

**Séquentiellement, jamais en parallèle.** Les UC ont des dépendances strictes (UC05 lit le modèle
créé par UC04) et elles écrivent dans les mêmes fichiers : deux runners simultanés se marcheraient
dessus et produiraient un conflit d'index git au commit.

Pour chaque UC restante, dans l'ordre du tableau :

1. Relève la ligne `SUIVANT:` de `auto-dev.py status` (elle porte tous les chemins).
2. Lance **un** sous-agent `auto-dev-runner` avec, dans son prompt, **uniquement** :
   - `run_id`, `cle`, `spec_nom`, `spec_path`, `tech_path`, `uc_dir` ;
   - l'état de reprise : `etat` et `phase` atteinte tels que le script les donne ;
   - la position dans le run (« UC 2 sur 5 ») et, si le runner précédent a signalé un point
     d'attention concernant celle-ci, **cette phrase-là** — rien d'autre.

   Sa définition d'agent porte déjà tout le reste (autonomie, grille d'arbitrage, journalisation,
   protocole de reprise, format de commit). **Ne la répète pas dans le prompt** : c'est du contexte
   payé deux fois.

3. Au retour, `python .claude/scripts/auto-dev.py status` pour lire l'état réel — le disque fait
   foi, pas le rapport de l'agent.

### Un runner échoue ou se bloque : continuer ou arrêter ?

| Situation | Décision |
|---|---|
| L'UC en échec est le **prédécesseur** d'une UC restante du **même dossier** | **Arrête le run.** Construire UC06 sur une UC05 en échec produit du code à jeter, et une cascade d'échecs illisible. |
| L'UC en échec n'a **aucune** UC restante en aval dans son dossier | **Continue** avec la suivante. Une UC indépendante n'a pas à payer pour l'autre. |
| Deux échecs consécutifs | **Arrête le run**, quelle que soit la topologie des dépendances : le problème n'est pas dans l'UC, il est dans l'environnement ou dans une hypothèse commune. |

Dans tous les cas d'arrêt : régénère le récapitulatif (étape 4), puis explique en 3 lignes ce qui a
été livré, ce qui a échoué, et la commande exacte pour reprendre une fois le blocage levé.

## Étape 4 — Récapitulatif et clôture

```bash
python .claude/scripts/auto-dev.py recap
git add -- recap.md .memory/auto-dev
git commit -m "docs(auto-dev): récapitulatif des décisions <run_id>" ...
```

Le script régénère `recap.md` **intégralement** depuis les `decisions.md` des runners : tu n'écris
pas une ligne de ce fichier, et tu n'as pas besoin de le lire. Si le compte de décisions qu'il
affiche est à `0` alors que des UC sont passées, c'est un vrai défaut : les runners n'ont pas
journalisé. Dis-le explicitement, `/change` serait sans matière.

Message du commit final : titre ci-dessus, corps avec la demande, les UC terminées et leurs SHA,
et la ligne `Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>`. **Pas de `git
push`** : la diffusion reste une décision de l'utilisateur.

## Étape 5 — Restitution finale

Un tableau, puis trois lignes. Rien de plus : le détail est dans `recap.md`, et le répéter ici
n'aide personne.

```text
✅ /auto-dev — <demande>

| UC | État | Commit | Décisions | Dette |
|----|------|--------|-----------|-------|
| …  | …    | …      | …         | …     |

📄 Décisions : recap.md (<n> arbitrages journalisés)
⚠️ Dette / décisions à revoir : <n> — <ID>
↩️ Revenir sur un choix : /change <explication>   (ou /change --liste)
```

Si une UC est en `echec`/`bloque`, ajoute **une** ligne : la cause, et `/auto-dev` (sans argument)
pour reprendre.

## Garde-fous du run

- **Aucune question après le pré-vol.** Une décision journalisée se défait d'un `/change` ; un run
  arrêté sur une question ne se rattrape pas.
- **Un commit par UC**, sur `master`, jamais de push.
- **Pas de retouche manuelle** de `recap.md`, de `etat.json` ou de `journal.jsonl` : ils sont
  produits par le script. Si l'un est incohérent, corrige la **cause**, pas le fichier.
- **Tu ne codes pas, tu ne review pas, tu ne tranches aucun arbitrage technique.** Si tu te
  surprends à ouvrir un fichier du plugin, c'est que tu es en train de refaire le travail d'un
  runner — dans le contexte le plus coûteux du run, et sans son isolation.
