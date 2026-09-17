# UC06 — Découverte et création des équipements

> **Domaine** : MVP · **Statut** : à implémenter · **Dépend de** : UC05

## Objectif

Transformer l'inventaire du compte Roborock (robots possédés et robots partagés) en équipements Jeedom
utilisables, de façon stable dans le temps (une resynchronisation ne doit ni dupliquer, ni écraser les
choix de l'utilisateur).

## Comportement attendu

Depuis la configuration du plugin, un bouton « synchroniser les équipements » déclenche l'inventaire du
compte. Pour chaque robot détecté et compatible :
- un équipement Jeedom est créé s'il n'existe pas déjà, identifié de façon stable (il ne doit pas être
  recréé en double lors d'une resynchronisation ultérieure, même si le robot a été renommé entre-temps
  dans l'application mobile) ;
- s'il existe déjà, ses informations techniques (modèle, version de micrologiciel) sont mises à jour,
  mais **le nom donné par l'utilisateur dans Jeedom n'est jamais écrasé** ;
- l'équipement porte les informations d'inventaire utiles (modèle, micrologiciel, indicateur « robot
  partagé ») mais **aucun secret** du robot.

Les robots **partagés** par un autre compte sont créés au même titre que les robots possédés en propre,
mais clairement identifiés comme partagés (droits potentiellement réduits).

Un robot dont la version de protocole n'est pas prise en charge par le plugin (protocoles autres que
« V1 ») n'est **pas** créé à moitié : l'utilisateur voit un message clair indiquant que ce modèle n'est
pas supporté par cette version du plugin, sans équipement fantôme ni incomplet.

Cette opération est déclenchée **à la demande** (bouton) et ses résultats sont mis en cache : ce n'est
pas une opération de rafraîchissement périodique (le compte a un quota strict d'appels à l'inventaire,
cf. `jeeroborock-architecture.md` D6), et elle ne doit pas être appelée automatiquement en boucle.

## Critères d'acceptation

- [ ] **AC1** — Sur un compte avec un robot compatible, une synchronisation crée un équipement Jeedom
      correspondant, avec au moins le modèle et le micrologiciel renseignés.
- [ ] **AC2** — Une deuxième synchronisation, sans changement côté Roborock, ne crée **aucun** doublon
      (même nombre d'équipements avant/après).
- [ ] **AC3** — Renommer l'équipement dans Jeedom puis relancer une synchronisation conserve le nom
      choisi par l'utilisateur (il n'est pas remplacé par le nom Roborock).
- [ ] **AC4** — Un robot renommé côté application Roborock, puis resynchronisé, ne crée pas de doublon :
      c'est le même équipement Jeedom qui est mis à jour.
- [ ] **AC5** — Un robot partagé détecté sur le compte est créé comme équipement, avec une information
      visible indiquant qu'il est partagé.
- [ ] **AC6** — Un robot dont le protocole n'est pas « V1 » n'entraîne la création d'**aucun**
      équipement, et un message explicite (« modèle non supporté par cette version du plugin ») est
      affiché à l'utilisateur.
- [ ] **AC7** — Aucun champ de configuration de l'équipement créé ne contient une donnée secrète du
      robot (clé locale, jeton).
- [ ] **AC8** — La synchronisation reste une action déclenchée explicitement (bouton) : elle n'est pas
      invoquée par le cron périodique du plugin.

## Impact i18n

- Nouvelles chaînes UI anticipées : « Synchroniser les équipements », « N équipement(s)
  créé(s)/mis à jour », « Modèle non supporté par cette version du plugin », « Robot partagé ».

## À confirmer

- Contenu exact et disponibilité de champs additionnels d'inventaire (numéro de série, salle) sur le
  compte de test réel (cf. `jeeroborock-cloud-api.md` § 4).

## Hors périmètre

- La création des commandes info/action détaillées d'un équipement : UC07/UC08/UC09.
- La gestion des cartes/pièces (post-MVP).
