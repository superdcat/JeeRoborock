# Principes d'arbitrage automatique

> ⚠️ **Template** : **P2** et **P5** citent les invariants d'un plugin concret. Les revoir après
> `/init-plugin`, une fois le cadrage fait et le squelette renommé — c'est cette grille qui remplace
> les réponses de l'utilisateur en mode `/auto-dev`, une grille fausse produit des décisions fausses.

Ces principes sont la **grille de décision** utilisée quand `/auto-dev` doit répondre seul à une
question que `/feature` aurait posée à l'utilisateur. Ils sont **ordonnés** : P1 l'emporte sur P2,
qui l'emporte sur P3, etc. Chaque décision journalisée cite les principes qui l'ont produite.

- **P1 — La spec fonctionnelle fait loi.** Les critères d'acceptation sont le contrat. On ne
  réduit pas le périmètre pour se simplifier la vie, on ne l'élargit pas parce que « ce serait
  mieux ». Un critère qu'on ne sait pas couvrir se journalise comme tel, il ne se réinterprète pas.
- **P2 — Les invariants de `CLAUDE.md` ne se négocient pas.** Autoload via
  `core/php/<id>.inc.php`, indentation par fichier, miroir `configuration.txt` → `.php`, aucun
  secret en log/DOM, aucune méta-séquence littérale, français langue source. Une décision qui les
  contredit est écartée d'office, quel que soit son avantage.
- **P3 — Cohérence avec ce qui a déjà été décidé.** Les specs techniques des UC précédentes et
  `.memory/analyse/` sont la mémoire du projet : on réutilise la convention existante (nommage,
  classement d'erreurs, clés de config, structure de retour) plutôt que d'en inventer une seconde.
  Deux conventions concurrentes coûtent plus cher que la moins jolie des deux.
- **P4 — Périmètre minimal.** On implémente l'UC courante, pas la suivante ni un domaine post-MVP.
  Pas de généralisation spéculative, pas de crochet « au cas où ». Ce qui n'est pas dans la spec
  n'est pas écrit.
- **P5 — Rien que le cadrage n'a pas prévu : ni dépendance, ni démon.** `hasDependency` et
  `hasOwnDeamon` d'`info.json` sont le contrat ; `packages.json` aussi. Si une décision semble
  exiger un paquet ou un processus qui n'y est pas, c'est la décision qu'il faut changer — pas le
  contrat, arbitré au cadrage et jamais au milieu d'une UC.
- **P6 — Prudence sur la sécurité et la robustesse.** En cas de doute, l'option la plus
  conservatrice : borner les entrées, assainir avant de journaliser, `try/catch` par équipement,
  timeout/budget explicite, échec bruyant plutôt que silencieux. Jamais de contournement (TLS,
  vérification, garde-fou) pour faire passer un cas.
- **P7 — Préférer le choix le plus facile à défaire.** Une table de données se corrige, une logique
  câblée en dur se réécrit. Entre deux options équivalentes, on retient celle dont un revirement
  ne coûte qu'une valeur — c'est ce qui rend `/change` utile plutôt que théorique.
- **P8 — À égalité, le plus simple, et on journalise l'alternative.** Pas d'arbitrage à pile ou
  face silencieux : l'option écartée et la condition qui la rendrait meilleure sont écrites dans
  `decisions.md`, pour que l'utilisateur puisse la réclamer d'un `/change`.

**Deux règles de procédure qui accompagnent ces principes :**

- **Jamais d'attente utilisateur.** En mode automatique, aucune question n'est posée : elle est
  tranchée puis journalisée. Une décision journalisée est réversible, une session bloquée sur une
  question ne l'est pas.
- **Jamais de décision silencieuse.** Toute question à laquelle `/feature` attendait une réponse
  humaine produit **une entrée** dans `decisions.md`, même si la réponse semblait évidente.
