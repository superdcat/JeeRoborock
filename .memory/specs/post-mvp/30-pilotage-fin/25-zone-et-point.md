# UC25 — Nettoyage de zone et déplacement vers un point

> **Domaine** : post-mvp/30-pilotage-fin · **Statut** : à implémenter · **Dépend de** : UC18 (image de la
> carte), UC24 (nettoyage par pièce)

## Objectif

Au-delà du découpage en pièces (UC24), l'utilisateur veut parfois cibler une zone plus précise qu'une
pièce entière (ex. seulement autour de la table de la salle à manger) ou envoyer le robot se positionner
à un endroit donné sans nettoyer (ex. avant de prendre une photo, ou pour vérifier visuellement un
recoin). Cette UC apporte ces deux capacités, en s'appuyant sur la carte déjà exposée par UC18 pour que
l'utilisateur sache de quoi il parle en saisissant des coordonnées.

## Comportement attendu

- Une action permet de lancer un nettoyage sur une **zone rectangulaire**, définie par des coordonnées
  saisies par l'utilisateur. Le référentiel de ces coordonnées (origine, échelle, orientation) est
  **documenté** de façon à ce que l'utilisateur comprenne d'où viennent les nombres qu'il doit saisir —
  typiquement en le rapprochant de la carte fournie par UC18, plutôt que de lui demander de deviner un
  système de coordonnées opaque.
- Une action permet d'envoyer le robot se **déplacer vers un point précis** de la carte, sans déclencher
  de nettoyage.
- Avant tout envoi au robot, les coordonnées saisies sont **validées** : une zone incohérente (ex.
  coordonnées inversées, zone hors des limites connues de la carte, zone de largeur ou hauteur nulle) est
  rejetée avec un message explicite, sans transmettre de commande au robot.
- Les deux actions sont utilisables en scénario (paramétrées par les coordonnées).
- Comme pour le nettoyage par pièce (UC24), le déroulement du nettoyage de zone se traduit par l'évolution
  normale des informations d'état/avancement du robot pendant le cycle.

## Critères d'acceptation

- [ ] **AC1** — Une action de nettoyage de zone acceptant des coordonnées rectangulaires est disponible
      sur l'équipement, avec une documentation du référentiel de coordonnées attendu accessible à
      l'utilisateur (aide contextuelle ou lien vers la carte de UC18).
- [ ] **AC2** — Déclencher l'action avec des coordonnées valides sur le robot testé fait effectivement
      nettoyer la zone désignée (constaté visuellement).
- [ ] **AC3** — Une action de déplacement vers un point précis est disponible, et son déclenchement fait
      effectivement partir le robot vers ce point sans démarrer de nettoyage.
- [ ] **AC4** — Une saisie de zone manifestement invalide (ex. coordonnées identiques pour les deux coins,
      ou hors des bornes connues de la carte) est rejetée avant envoi, avec un message explicite, et ne
      déclenche aucune commande vers le robot.
- [ ] **AC5** — Les deux actions (zone, point) sont déclenchables depuis un scénario Jeedom avec les
      coordonnées en paramètre.

## Impact i18n

- Nouvelles chaînes UI anticipées : « Nettoyer une zone », « Se déplacer vers un point », message de
  validation (« Coordonnées de zone invalides »), texte d'aide sur le référentiel de coordonnées.

## À confirmer

- ⚠️ Référentiel exact des coordonnées attendu par `app_zoned_clean`/`app_goto_target` (origine, unité,
  échelle par rapport au PNG produit pour UC18) : non documenté officiellement, à établir par
  expérimentation sur le Qrevo Curv au moment de l'implémentation — cf.
  `.memory/analyse/jeeroborock-mqtt-protocole.md` § 6-7.
- Bornes exactes de validation (limites de la carte connue) : dépend de ce que UC18 expose comme
  dimensions de carte.
- Comportement du robot si le point ou la zone demandés tombent sur un obstacle ou une zone non
  cartographiée : à vérifier en recette, aucune garantie a priori.

## Hors périmètre

- Le nettoyage par pièce nommée : UC24.
- L'affichage de la carte elle-même (image, dimensions) : UC18 (cette UC consomme ce référentiel mais ne
  le produit pas).
- L'édition de zones interdites/virtuelles permanentes (murs virtuels, zones à éviter durables) : hors
  périmètre de cette UC, qui ne couvre que le déclenchement ponctuel d'un nettoyage/déplacement.
