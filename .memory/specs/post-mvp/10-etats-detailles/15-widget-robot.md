# UC15 — Widget tuile du robot

> **Domaine** : post-mvp/10-etats-detailles · **Statut** : à implémenter · **Dépend de** : UC08

## Objectif

Par défaut, un équipement Jeedom affiche ses commandes sous forme de liste brute (une ligne par info,
un bouton par action). Pour un robot aspirateur, l'usage courant est de tout voir d'un coup d'œil : état,
batterie, actions principales. Cette UC introduit une **tuile de dashboard** dédiée qui regroupe ces
éléments, pour un usage quotidien plus lisible qu'une liste de commandes.

## Comportement attendu

Sur le tableau de bord Jeedom (desktop et mobile), l'équipement robot se présente sous une forme
compacte réunissant : l'état courant du robot (libellé lisible), le niveau de batterie, et les actions
principales de pilotage (démarrer, mettre en pause, arrêter, retour à la base, localiser — celles
existant sur l'équipement). Une action de la tuile déclenche la même exécution que le bouton de commande
correspondant, avec un retour visible en cas de succès ou d'échec.

Si une commande normalement attendue est absente de l'équipement (parce qu'elle n'a pas été créée, le
robot ne supportant pas cette capacité — cf. UC06), la tuile ne plante pas et ne montre pas un bouton
inopérant : l'élément correspondant est simplement absent ou visiblement désactivé.

La tuile ne s'impose pas à un équipement dont l'utilisateur a déjà choisi manuellement un autre widget de
commande : elle ne s'applique que si aucun choix explicite n'a été fait auparavant, y compris lors d'une
resynchronisation ultérieure de l'équipement.

Le comportement est identique entre l'affichage desktop et l'affichage mobile de Jeedom (mêmes
informations, mêmes actions disponibles), même si la présentation visuelle diffère selon le support.

## Critères d'acceptation

- [ ] **AC1** — Sur le dashboard desktop, un équipement robot nouvellement créé affiche une tuile
      regroupant état, batterie et boutons d'action (démarrer, pause, arrêt, retour base, localiser),
      sans configuration manuelle.
- [ ] **AC2** — Le même équipement, consulté depuis l'application/le mode mobile de Jeedom, présente les
      mêmes informations et les mêmes actions déclenchables.
- [ ] **AC3** — Déclencher une action depuis la tuile produit le même effet que déclencher la commande
      d'action correspondante depuis la liste de commandes standard, avec un retour visible (succès/échec).
- [ ] **AC4** — Pour un robot dont une capacité de pilotage (ex. localiser) n'a pas été créée en commande
      (UC06/UC08), la tuile ne provoque ni erreur d'affichage ni bouton actif mais inopérant : l'élément
      correspondant est absent ou désactivé de façon visible.
- [ ] **AC5** — Un équipement sur lequel l'utilisateur a déjà choisi manuellement un autre widget de
      commande ne voit pas ce choix écrasé par une resynchronisation ultérieure de l'équipement.
- [ ] **AC6** — Une resynchronisation d'un équipement existant qui n'avait encore aucun widget personnalisé
      lui applique la tuile sans double affichage ni doublon de commandes.

## Impact i18n

- Nouvelles chaînes UI anticipées : libellés des actions déjà couverts par UC08 (réutilisés, pas
  redéfinis) ; éventuel texte d'état de secours si une information attendue est absente (« état
  inconnu »).

## À confirmer

- Liste exacte des actions à inclure dans la tuile compacte (le pilotage fin — aspiration, eau — reste
  hors périmètre ou intégré ultérieurement ?) — à trancher en fonction des commandes réellement présentes
  sur le Qrevo Curv après UC08.
- Mécanisme de résolution des commandes sœurs par AJAX `byEqLogic` et gestion du cas « commande masquée
  mais existante » (cf. `.memory/analyse/jeedom-widgets-commandes.md` § 3) : implémentation renvoyée à la
  spec technique.

## Hors périmètre

- La création/détection des commandes elles-mêmes (état, batterie, actions) : UC06/UC07/UC08.
- L'affichage de la carte du robot ou d'une image quelconque : hors périmètre de cette UC (nécessiterait
  un proxy same-origin dédié, cf. `jeedom-widgets-commandes.md` § 7 — sujet distinct).
- Le pilotage fin (aspiration, eau, itinéraire) et les erreurs détaillées : autres UC du présent domaine.
