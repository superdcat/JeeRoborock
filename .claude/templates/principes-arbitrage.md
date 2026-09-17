# Principes d'arbitrage automatique

> ✅ **Spécialisée pour `jeeroborock` (JeeRoborock)** au cadrage du 2026-09-17. **P2** et **P5**
> portent les décisions d'architecture du plugin. C'est cette grille qui remplace les réponses de
> l'utilisateur en mode `/auto-dev` : si une décision de cadrage évolue, la mettre à jour **en même
> temps** que `CLAUDE.md`, sinon `/auto-dev` tranchera d'après l'ancien cadrage.

Ces principes sont la **grille de décision** utilisée quand `/auto-dev` doit répondre seul à une
question que `/feature` aurait posée à l'utilisateur. Ils sont **ordonnés** : P1 l'emporte sur P2,
qui l'emporte sur P3, etc. Chaque décision journalisée cite les principes qui l'ont produite.

- **P1 — La spec fonctionnelle fait loi.** Les critères d'acceptation sont le contrat. On ne
  réduit pas le périmètre pour se simplifier la vie, on ne l'élargit pas parce que « ce serait
  mieux ». Un critère qu'on ne sait pas couvrir se journalise comme tel, il ne se réinterprète pas.
- **P2 — Les invariants de `CLAUDE.md` ne se négocient pas.** Deux familles, également fermées.
  - *Génériques Jeedom* : autoload 1 classe ↔ 1 fichier, indentation par fichier (`desktop/php/*.php`
    en tabulations + CRLF), miroir `configuration.txt` → `.php`, aucun secret en log/DOM, aucune
    méta-séquence littérale, français langue source.
  - *Propres à ce plugin, arbitrés au cadrage* : **aucun appel au cloud Roborock en PHP** (tout passe
    par `jeeroborockDaemon` → démon) ; **authentification par code e-mail uniquement**, pas de mot de
    passe stocké et **pas de ré-authentification automatique** ; `local_key`, `rriot` et jetons
    **ne quittent jamais le démon** ; serveur HTTP du démon **bindé sur `127.0.0.1` + apikey** ;
    `logicalId` d'un équipement = `duid` ; commandes créées **d'après les capacités détectées**, jamais
    d'après une table par modèle ; libellés d'état et d'erreur fournis par le démon ; **vérification TLS
    jamais désactivée** ; **quotas Roborock durs** (login 20/jour, home data 40/jour, partagés avec
    l'app mobile) donc **aucun retry automatique** et la découverte n'est pas un rafraîchissement.
  Une décision qui contredit l'un de ces points est écartée d'office, quel que soit son avantage.
- **P3 — Cohérence avec ce qui a déjà été décidé.** Les specs techniques des UC précédentes et
  `.memory/analyse/` sont la mémoire du projet : on réutilise la convention existante (nommage,
  classement d'erreurs, clés de config, structure de retour) plutôt que d'en inventer une seconde.
  Deux conventions concurrentes coûtent plus cher que la moins jolie des deux.
- **P4 — Périmètre minimal.** On implémente l'UC courante, pas la suivante ni un domaine post-MVP.
  Pas de généralisation spéculative, pas de crochet « au cas où ». Ce qui n'est pas dans la spec
  n'est pas écrit.
- **P5 — Rien que le cadrage n'a pas prévu : ni dépendance, ni démon.** Le contrat arrêté est :
  `hasOwnDeamon: true`, `hasDependency: true`, `os.min: 12`, et **une seule dépendance pip —
  `python-roborock`, version exacte `7.8.0`**. Ajouter un paquet (client MQTT tiers, lib de rendu
  d'image, framework HTTP) n'est **pas** un arbitrage de milieu d'UC : si une décision semble l'exiger,
  c'est la décision qu'il faut changer. Rappels de format, qui coûtent cher à redécouvrir : la version
  va dans la **valeur** (`{"version": "7.8.0"}`), **jamais** dans la clé, et **aucun opérateur**
  `<`/`>` n'est permis — donc la version ne peut pas être plafonnée, et une montée de majeure est une
  décision explicite, pas une dérive. `aiohttp` est déjà transitif : le canal synchrone du démon
  n'ajoute aucune dépendance.
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
