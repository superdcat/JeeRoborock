# UC29 — Transport local (LAN) : diagnostic et maîtrise

> **Domaine** : post-mvp/50-transport-local-et-diagnostic · **Statut** : à implémenter · **Dépend de** :
> UC11 (post-MVP — robustesse, quotas et ré-authentification)

## Objectif

Le plugin a été demandé « cloud uniquement », mais la librairie sur laquelle il s'appuie tente
**systématiquement**, pour tout robot au protocole V1, une connexion réseau locale (LAN) vers le robot en
parallèle du canal cloud (MQTT) — sans qu'aucun réglage de la librairie ne permette de désactiver cette
tentative (cf. `.memory/analyse/jeeroborock-architecture.md` D9). Cette UC ne rajoute pas de fonctionnalité
locale : elle rend ce comportement **visible et compréhensible** pour l'utilisateur, afin qu'il ne découvre
pas après coup un canal réseau supplémentaire qu'il n'a pas choisi, et lui donne les moyens d'en connaître
le coût plutôt que de subir une promesse « cloud only » qui ne peut pas être tenue littéralement.

## Comportement attendu

- Pour chaque robot, l'utilisateur peut consulter **par quel canal transite actuellement** le pilotage :
  connexion locale (LAN, robot joignable directement sur le réseau de Jeedom) ou connexion cloud (MQTT)
  uniquement. Cette information reflète l'état réel observé par le démon, pas une supposition.
- Cette information est présentée comme un **constat**, pas comme un réglage que l'utilisateur aurait
  choisi : le plugin n'affirme jamais que le transport est « cloud uniquement » si une tentative ou une
  connexion locale a effectivement lieu.
- Le plugin **documente clairement**, dans la configuration ou la documentation associée, que la
  tentative de connexion locale est un comportement de la bibliothèque logicielle sous-jacente et non un
  choix du plugin, et qu'elle ne peut pas être désactivée à ce jour.
- Un impact potentiel de cette tentative locale est mesuré et communiqué à l'utilisateur : notamment le
  délai supplémentaire subi au démarrage du démon (ou lors d'une reconnexion) quand le robot n'est pas
  joignable en local — plutôt que de laisser ce délai inexpliqué dans les journaux ou l'expérience
  utilisateur.
- Si un moyen de neutraliser ou limiter cette tentative locale est identifié (contournement réseau, réglage
  applicable en pratique), il est documenté comme tel — **sans jamais promettre** un mode « cloud only »
  strict tant que ce n'est pas techniquement garanti.
- Le comportement du canal robot (bascule local ↔ cloud, présence ou non d'un délai constatable) est
  **vérifié sur le matériel de test** (Qrevo Curv) avant d'être présenté à l'utilisateur comme acquis ;
  tant que ce n'est pas vérifié, l'information reste prudente (« peut tenter une connexion locale »)
  plutôt qu'affirmative.

## Critères d'acceptation

- [ ] **AC1** — Chaque équipement robot expose une information consultable indiquant si le canal
      actuellement utilisé pour le piloter est local (LAN) ou cloud, reflétant l'état réel constaté par le
      démon (pas une valeur figée ou supposée).
- [ ] **AC2** — La documentation du plugin (page de configuration et/ou documentation utilisateur)
      explique, en français et en des termes compréhensibles par un non-développeur, pourquoi une
      connexion locale peut être tentée alors que le plugin ne l'a pas demandée, et que ce comportement ne
      peut pas être désactivé par le plugin à ce jour.
- [ ] **AC3** — Le délai éventuellement introduit par une tentative de connexion locale infructueuse au
      démarrage (ou à la reconnexion) est mesuré sur le matériel de test et consigné ; s'il est
      significatif (perceptible par l'utilisateur), il est mentionné dans la documentation plutôt que
      découvert en usage.
- [ ] **AC4** — Aucune chaîne, aucun écran, aucune documentation du plugin n'affirme un fonctionnement
      « cloud uniquement » strict et garanti tant que ce point n'a pas été vérifié sur le matériel de test.
- [ ] **AC5** — Si un moyen de neutraliser effectivement la tentative locale est trouvé et validé sur le
      matériel de test, il est proposé comme réglage explicite à l'utilisateur ; sinon, son absence est
      documentée comme une limite connue et assumée du plugin.

## Impact i18n

- Chaînes anticipées : « Canal actuel : connexion locale », « Canal actuel : cloud (MQTT) », « Le robot
  peut tenter une connexion locale sur votre réseau ; ce comportement vient de la librairie utilisée et ne
  peut pas être désactivé à ce jour », « Ce plugin ne peut pas garantir un fonctionnement 100 % cloud ».

## À confirmer

- Le Qrevo Curv est-il effectivement joignable en TCP local depuis la machine Jeedom de test, et quel est
  le coût réel (de l'ordre de 15 secondes évoqué dans l'analyse) d'une tentative infructueuse au démarrage
  — cf. `.memory/analyse/jeeroborock-architecture.md` D9 et `jeeroborock-implementations-reference.md` § 5.
- Existence ou non d'un contournement pratique (règle réseau, isolation du robot sur un VLAN séparé…)
  permettant d'empêcher de fait la connexion locale sans dépendre d'un réglage de la librairie.
- Le niveau de détail exposé à l'utilisateur (IP locale du robot, etc.) sans jamais révéler de secret
  (`local_key` : reste toujours côté démon, jamais affiché — cf. `jeeroborock-cloud-api.md`).

## Hors périmètre

- La robustesse du canal cloud (quotas, ré-authentification, mode dégradé) : UC11.
- Toute fonctionnalité de pilotage supplémentaire tirant parti du canal local (cette UC est un diagnostic,
  pas une optimisation de performance) : hors périmètre de ce domaine.
