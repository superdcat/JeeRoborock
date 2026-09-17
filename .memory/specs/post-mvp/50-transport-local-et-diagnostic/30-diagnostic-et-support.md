# UC30 — Diagnostic et support

> **Domaine** : post-mvp/50-transport-local-et-diagnostic · **Statut** : à implémenter · **Dépend de** :
> UC11 (post-MVP — robustesse, quotas et ré-authentification)

## Objectif

Quand quelque chose ne fonctionne pas (robot introuvable, commande refusée, comportement inattendu),
l'utilisateur — ou la personne qui l'assiste — a besoin d'un état des lieux exploitable sans devoir
recopier manuellement des journaux épars ni exposer ses identifiants. Cette UC fournit un rapport de
diagnostic **prêt à partager**, dont l'absence de toute donnée sensible est une garantie du plugin, pas
une simple bonne intention.

## Comportement attendu

- Depuis la configuration du plugin, un administrateur peut déclencher la génération d'un rapport de
  diagnostic couvrant l'état du démon, l'état de connexion (compte et robots), les capacités détectées, et
  les éventuelles erreurs récentes.
- Le rapport s'appuie sur les informations de diagnostic déjà produites et **expurgées** par la
  bibliothèque sous-jacente plutôt que de reconstituer un état parallèle susceptible de réintroduire des
  données sensibles par erreur.
- Le rapport est **systématiquement vérifié avant d'être présenté** : aucun jeton d'authentification,
  aucun identifiant de session, aucune clé de chiffrement de robot, aucun numéro de série, aucune adresse
  e-mail du compte n'y figure, quelle que soit la profondeur de détail demandée.
- Le rapport est accessible **uniquement à un administrateur** connecté à Jeedom (pas d'exposition depuis
  une page ou un widget non-admin).
- Le rapport peut être obtenu (copié ou téléchargé) en une seule action, dans un format directement
  transmissible à un tiers (ex. pour un ticket de support ou une remontée de bug) sans mise en forme
  supplémentaire de la part de l'utilisateur.
- Si le démon est injoignable au moment de la demande, le rapport le signale explicitement plutôt que
  d'échouer sans explication ; les informations encore disponibles côté PHP (dernier état connu, horodatage
  de dernière communication) sont incluses malgré tout.

## Critères d'acceptation

- [ ] **AC1** — Un administrateur déclenche depuis la page de configuration la génération d'un rapport de
      diagnostic et obtient un résultat en un seul geste (bouton), sans étape intermédiaire.
- [ ] **AC2** — Une inspection manuelle du rapport généré, quel que soit le niveau de détail demandé, ne
      révèle aucun jeton, aucune clé de chiffrement, aucun `local_key`, aucun identifiant `rriot`, aucun
      numéro de série et aucune adresse e-mail — ce point est vérifié explicitement en recette, pas
      supposé.
- [ ] **AC3** — Le rapport peut être copié ou exporté (fichier ou presse-papiers) en une seule action, sans
      édition manuelle préalable pour en retirer des informations.
- [ ] **AC4** — Une tentative d'accès au rapport par un compte non-administrateur échoue (accès refusé),
      qu'elle passe par la page ou par un appel direct.
- [ ] **AC5** — Le démon étant arrêté au moment de la génération, le rapport le signale clairement et
      contient malgré tout les informations encore connues côté Jeedom (dernier état, horodatage de
      dernière communication), plutôt que d'échouer intégralement.
- [ ] **AC6** — Le rapport identifie la version de la bibliothèque `python-roborock` effectivement utilisée
      par le démon (utile pour corréler un incident à une version connue).

## Impact i18n

- Chaînes anticipées : « Générer un rapport de diagnostic », « Copier le rapport », « Télécharger le
  rapport », « Démon injoignable : rapport partiel », « Rapport généré le … ».

## À confirmer

- Contenu exact et exhaustivité de l'objet de diagnostic déjà exposé par `python-roborock` (s'il existe une
  méthode dédiée dans la version 7.8.0) : à vérifier dans le code de la bibliothèque au moment de
  l'implémentation (cf. `.memory/analyse/jeeroborock-implementations-reference.md` § 3, table
  `python-roborock`) ; si aucune méthode de diagnostic native n'existe, le rapport sera composé à la main
  à partir des structures déjà exposées côté démon (état, capacités, dernière erreur), avec la même
  exigence d'expurgation.
- Périmètre exact des « erreurs récentes » à inclure (nombre, fenêtre de temps) : à trancher en spec
  technique selon ce que le démon conserve déjà en mémoire ou en journal.

## Hors périmètre

- La consultation courante des journaux du plugin (niveau de détail configurable, rotation) : ressort du
  cœur Jeedom, pas de cette UC.
- Le diagnostic du transport local (LAN vs cloud) : UC29.
- L'envoi automatique du rapport à un service tiers : hors périmètre — le rapport est produit pour être
  transmis manuellement par l'utilisateur.
