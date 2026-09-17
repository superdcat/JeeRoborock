# UC11 — Robustesse, quotas et ré-authentification

> **Domaine** : post-MVP / temps réel et robustesse · **Statut** : à implémenter · **Dépend de** : UC08,
> UC09 (MVP — commandes de pilotage et routines), UC10

## Objectif

Le cloud Roborock impose des quotas durs (connexions, découvertes) et son authentification ne peut pas
se renouveler automatiquement (elle exige un code reçu par e-mail, donc une action humaine). Cette UC
garantit qu'en cas de panne — identifiants expirés, quota atteint, robot injoignable, démon arrêté —
l'utilisateur comprend ce qui se passe, le plugin ne dégrade pas silencieusement l'expérience, et
n'aggrave jamais la situation en multipliant les tentatives.

## Comportement attendu

- Quand les identifiants du compte deviennent invalides (expiration, révocation côté Roborock), le
  plugin le détecte et affiche un état explicite de « ré-authentification requise », visible depuis la
  configuration du plugin et/ou l'état des équipements concernés.
- Une fois cet état atteint, le plugin **cesse toute tentative automatique de reconnexion au compte**. Il
  n'y a pas de nouvelle tentative de connexion tant que l'utilisateur n'a pas lui-même relancé le
  processus d'authentification (nouveau code e-mail, cf. UC04) — se reconnecter automatiquement n'est de
  toute façon pas possible : le code arrive par e-mail et ne peut pas être lu par une machine, et chaque
  tentative de connexion consomme le quota quotidien partagé avec l'application mobile.
- En cas d'erreur de connexion transitoire (robot ou cloud temporairement injoignable, timeout réseau),
  le plugin espace ses tentatives progressivement au lieu de réessayer en boucle rapprochée. Le démon ne
  redémarre jamais en boucle serrée à la suite d'un échec.
- Tant qu'un robot est dans un état dégradé (hors ligne, démon arrêté, compte en attente de
  ré-authentification), les commandes info de ce robot **conservent leur dernière valeur connue** — elles
  ne sont ni vidées, ni remises à une valeur par défaut trompeuse. Seuls les indicateurs de connexion et
  de fraîcheur (UC10) signalent la perte.
- Toute commande d'action (démarrage, pause, retour base, exécution d'une routine…) déclenchée sur un
  robot dans un état dégradé remonte un message d'erreur compréhensible expliquant la cause probable
  (démon arrêté, robot hors ligne, ré-authentification requise), plutôt que d'échouer silencieusement ou
  de renvoyer une erreur technique brute.
- Aucune information sensible (jeton d'authentification, clé de chiffrement du robot, ou tout autre
  secret manipulé en interne) n'apparaît jamais dans les journaux du plugin, quel que soit le niveau de
  journalisation configuré — y compris en niveau de détail maximal utilisé pour le diagnostic.

## Critères d'acceptation

- [ ] **AC1** — Identifiants invalidés côté compte (ex. révocation manuelle) : le plugin bascule dans un
      état « ré-authentification requise » visible par l'utilisateur, sans que le plugin ne déclenche de
      nouvelle tentative de connexion automatique après ce basculement.
- [ ] **AC2** — Depuis l'état « ré-authentification requise », relancer le parcours d'authentification
      (UC04) ramène le plugin à un fonctionnement normal (les robots redeviennent pilotables et suivis).
- [ ] **AC3** — Une coupure réseau ou cloud temporaire ne provoque pas de tentatives de reconnexion
      rapprochées et répétées observables sur une courte période ; les tentatives s'espacent avec le
      temps.
- [ ] **AC4** — Robot passé hors ligne : ses commandes info affichent toujours leur dernière valeur
      connue (pas de valeur vide/à zéro artificielle), pendant que l'indicateur de connexion et
      l'horodatage de fraîcheur (UC10) signalent la perte.
- [ ] **AC5** — Déclencher une action de pilotage (ex. démarrer) sur un robot hors ligne ou en attente de
      ré-authentification renvoie un message d'erreur en français et compréhensible, distinct pour au
      moins ces deux causes, et n'est pas silencieusement ignoré.
- [ ] **AC6** — Une inspection des journaux du plugin, à n'importe quel niveau de journalisation
      configurable, ne fait apparaître aucun jeton, aucune clé de chiffrement, ni aucun autre secret en
      clair.
- [ ] **AC7** — Un arrêt/redémarrage anormal répété du démon (simulé) ne produit pas un cycle de
      redémarrage en boucle serrée observable (délai croissant entre tentatives).

## Impact i18n

- Chaînes anticipées : « Ré-authentification requise, veuillez vous reconnecter dans la configuration du
  plugin », « Robot hors ligne, commande impossible », « Démon indisponible, commande impossible »,
  « Connexion perdue avec le robot ».

## À confirmer

- Durée de vie réelle des identifiants dérivés (`rriot`) : aucune source (ni la librairie, ni
  l'intégration Home Assistant de référence) ne la documente — cf.
  `.memory/analyse/jeeroborock-cloud-api.md` § 2.3 et `jeeroborock-architecture.md` D4. Impossible à ce
  stade de dire à quelle fréquence l'utilisateur devra réellement refaire un code e-mail ; à observer en
  usage réel une fois le plugin en production.
- Distinction exacte, du point de vue utilisateur, entre « ré-authentification requise » (compte) et
  « robot hors ligne » (appareil) quand les deux se produisent en même temps — à clarifier en spec
  technique pour éviter un message ambigu.

## Hors périmètre

- Le parcours d'authentification lui-même (envoi/validation du code e-mail) : UC04 (MVP).
- La mécanique fine de la boucle de rafraîchissement et de la fraîcheur : UC10.
- Le diagnostic/désactivation du canal local (D9 de `jeeroborock-architecture.md`) : hors périmètre de ce
  domaine, traité par un domaine post-MVP dédié.
