# Format d'une décision journalisée

Ce fichier est le **format de référence** d'une entrée de décision. Il est utilisé par :

- `/auto-dev` (agent `auto-dev-runner`) → écrit `.memory/auto-dev/<run>/<UC>/decisions.md` ;
- `/change` → écrit `.memory/auto-dev/revisions/<date>-<ID>.md`.

`auto-dev.py recap` assemble ces fichiers dans `recap.md` à la racine et en construit l'index en
lisant **exactement** ces marqueurs — ne les change pas sans mettre le script à jour :

| Marqueur | Rôle pour le script |
|---|---|
| `### D-<UC><NN> — <titre>` | ouvre une décision, alimente l'ID et le sujet de l'index |
| `- **Statut** : …` | colonne « Statut » de l'index |
| `- **Révise** : D-…` | (révisions seulement) marque la décision d'origine comme révisée |

**Convention d'ID** : `D-` + clé d'UC sans séparateur + `-` + numéro séquentiel dans l'UC —
`D-MVP04-01`, `D-MVP04-02`, `D-POSTMVP0102-01`. Une révision reprend l'ID d'origine suffixé `R<n>` :
`D-MVP04-02R1`. Les ID sont **définitifs** : ils sont cités dans les commits et par `/change`.

**Le lecteur cible n'a AUCUN contexte.** Ni le code, ni la spec, ni la conversation. Chaque entrée
doit donc se comprendre seule : nommer les fichiers, les méthodes, les clés, les valeurs concrètes.
Pas de « comme discuté », pas de « le champ habituel », pas de pronom sans antécédent.

---

## Gabarit à recopier

```markdown
### D-MVP04-01 — Titre court de l'arbitrage (5 à 10 mots)

- **Statut** : appliqué        <!-- appliqué | dette | bloqué | abandonné -->
- **Date** : 2026-08-25
- **Gate** : étape 4 de `/feature` (validation du plan)
- **Principes** : P3, P7

**Question**
Ce qui devait être tranché, en 1 à 3 phrases, formulé de façon compréhensible sans le code sous
les yeux : quelle alternative s'opposait à quelle autre, et pourquoi le choix n'allait pas de soi.

**Décision**
Ce qui a été retenu, avec les **valeurs concrètes** : noms de classes, de méthodes, de clés de
configuration, de `logicalId`, bornes, formats, unités. Assez précis pour qu'on puisse vérifier
dans le code que c'est bien ce qui a été fait.

**Pourquoi**
2 à 4 lignes. Le raisonnement, pas la paraphrase de la décision. Cite le principe qui a départagé.

**Alternatives écartées**
1. *Nom de l'alternative* — écartée parce que … ; redeviendrait le meilleur choix si … .
2. *Autre alternative* — écartée parce que … ; redeviendrait le meilleur choix si … .

**Portée dans le code**
- `core/class/<id>.class.php` → `nomDeMethode()`
- `core/php/<id>.inc.php` → ligne de `require_once`
- (tout ce qu'un revirement devrait rouvrir, fichier par fichier, symbole par symbole)

**Coût d'un revirement**
- Fichiers à modifier : …
- Specs à corriger : `.memory/specs/MVP/04-…-tech.md` § …
- Migration de l'existant : clé de configuration à renommer / `logicalId` de commande déjà créé
  chez l'utilisateur / cache à purger / **aucune**
- i18n : clés françaises impactées, ou **aucune**
- Réversibilité : facile | moyenne | coûteuse — pourquoi (une valeur dans une table = facile ;
  un `logicalId` déjà posé sur des commandes existantes = coûteux, il faut migrer)

**Traçabilité**
- Spec fonctionnelle : `.memory/specs/MVP/04-….md` § critère n°…
- Spec technique : `.memory/specs/MVP/04-…-tech.md` § …
- Commit : `<sha court>`
```

---

## Gabarit d'une révision (`/change`)

Même structure, plus deux champs de liaison, et le **statut du prédécesseur** rappelé pour qu'on
comprenne l'historique sans remonter le fil :

```markdown
### D-MVP04-02R1 — Nouveau titre de l'arbitrage

- **Statut** : appliqué
- **Révise** : D-MVP04-02
- **Motif du revirement** : demande utilisateur du 2026-08-27 — « … » (citation de la demande)
- **Date** : 2026-08-27
- **Principes** : arbitrage utilisateur (prime sur P1..P8)

**Ce qui change par rapport à D-MVP04-02**
Avant : … / Maintenant : … . En une ligne chacun, sans ambiguïté.

[puis les mêmes rubriques que le gabarit standard : Décision, Pourquoi, Alternatives écartées,
Portée dans le code, Coût d'un revirement, Traçabilité]

**Migration réellement effectuée**
Ce qui a été fait sur l'existant (renommage, purge de cache, conversion en base), ou « aucune
migration nécessaire » — et si une action manuelle reste à la charge de l'utilisateur, la dire ici.
```
