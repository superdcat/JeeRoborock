# UC31 — Suivi et mise à jour du firmware

> **Domaine** : post-mvp/50-transport-local-et-diagnostic · **Statut** : à implémenter · **Dépend de** :
> UC11 (post-MVP — robustesse, quotas et ré-authentification)

## Objectif

Le firmware d'un robot Roborock évolue via le cloud, en dehors de Jeedom. Cette UC permet à l'utilisateur
de savoir, depuis Jeedom, quelle version tourne sur son robot et si une mise à jour est disponible — une
information utile à elle seule pour comprendre un changement de comportement — et, en option, de déclencher
la mise à jour sans repasser par l'application mobile, avec les garde-fous qu'exige une action irréversible
sur un appareil physique en mouvement.

## Comportement attendu

- Le plugin affiche, pour chaque robot, la version de firmware actuellement installée.
- Le plugin indique si une mise à jour est disponible et, si l'information est fournie par le cloud,
  affiche des éléments descriptifs de cette mise à jour (ex. notes de version) dans un format lisible.
- L'utilisateur peut consulter et modifier le réglage de mise à jour automatique silencieuse du robot
  (activé/désactivé), reflétant l'état réellement configuré côté cloud, pas une valeur locale non
  synchronisée.
- Une action distincte permet de **déclencher** la mise à jour du firmware. Cette action :
  - exige une **confirmation explicite** de l'utilisateur avant exécution, la mise à jour étant
    irréversible et pouvant immobiliser le robot le temps de l'opération ;
  - est **bloquée** (refusée, avec explication) si le robot n'est pas à sa base de charge ou s'il est en
    cours de nettoyage — deux conditions où déclencher une mise à jour serait risqué ou inapplicable ;
  - remonte un message clair en cas d'échec (mise à jour refusée par le robot, aucune mise à jour
    disponible, robot non éligible).
- Les informations de firmware (version, disponibilité) sont rafraîchies à une cadence raisonnable, sans
  solliciter le cloud à chaque affichage de la page de configuration, en cohérence avec la gestion des
  quotas déjà en place pour les autres appels cloud (cf. UC11).

## Critères d'acceptation

- [ ] **AC1** — L'équipement d'un robot connecté affiche sa version de firmware installée.
- [ ] **AC2** — Quand une mise à jour est disponible côté cloud, le plugin l'indique clairement (au
      minimum : disponibilité oui/non ; si fourni par le cloud, les notes de version).
- [ ] **AC3** — Le réglage de mise à jour automatique silencieuse est visible et modifiable depuis Jeedom,
      et la valeur affichée après modification correspond à celle effectivement acceptée par le cloud (pas
      une valeur optimiste non confirmée).
- [ ] **AC4** — Déclencher la mise à jour du firmware exige une confirmation explicite distincte de la
      simple sélection de l'action ; sans cette confirmation, la mise à jour n'est pas lancée.
- [ ] **AC5** — Une tentative de déclenchement de mise à jour alors que le robot n'est pas à sa base ou est
      en cours de nettoyage est refusée par le plugin, avec un message expliquant la raison du refus, sans
      transmettre la commande au robot.
- [ ] **AC6** — Une tentative de déclenchement alors qu'aucune mise à jour n'est disponible est refusée
      avec un message clair plutôt que silencieusement ignorée.
- [ ] **AC7** — La consultation répétée de l'information de firmware sur une courte période ne multiplie
      pas les appels au cloud à chaque affichage (respect de la cadence retenue pour les autres données
      cloud, cf. UC11/D6 de `jeeroborock-architecture.md`).

## Impact i18n

- Chaînes anticipées : « Version du firmware », « Mise à jour disponible », « Aucune mise à jour
  disponible », « Mise à jour automatique silencieuse », « Lancer la mise à jour du firmware »,
  « Confirmer la mise à jour du firmware ? Cette action est irréversible et immobilisera le robot »,
  « Mise à jour impossible : le robot doit être à sa base et non en cours de nettoyage ».

## À confirmer

- Comportement réel de l'endpoint de mise à jour firmware (`start_firmware_update`, cf.
  `.memory/analyse/jeeroborock-cloud-api.md` § 5) sur un robot V1 comme le Qrevo Curv : délai de prise en
  compte, retour immédiat ou asynchrone, code d'erreur en cas de robot non éligible — à vérifier en
  recette, faute de documentation officielle.
- Contenu exact renvoyé par `get_firmware_info` (`FirmwareInfo{version, current_version, updatable, desc,
  release_time, force_update}`) sur le matériel de test, notamment si `desc` (notes de version) est
  effectivement renseigné pour ce modèle.
- Signification et comportement du champ `force_update` : si le cloud signale une mise à jour forcée, faut-il
  l'exposer différemment (ex. sans possibilité de la reporter) ? À trancher en spec technique une fois le
  cas observé.
- Fiabilité de la détection des conditions de blocage (« à la base », « en cours de nettoyage ») à partir
  des seules données d'état déjà disponibles (MVP + UC10/UC11), sans appel supplémentaire dédié.

## Hors périmètre

- La planification automatique de mises à jour (ex. programmer une mise à jour à une heure donnée) :
  non couvert, l'action reste manuelle ou pilotable via un scénario Jeedom déclenché par l'utilisateur.
- Le diagnostic général du plugin (rapport de support) : UC30.
- Le diagnostic et la maîtrise du transport local : UC29.
