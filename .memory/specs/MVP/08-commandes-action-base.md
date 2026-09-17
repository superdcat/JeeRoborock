# UC08 — Commandes de pilotage de base

> **Domaine** : MVP · **Statut** : à implémenter · **Dépend de** : UC07

## Objectif

Permettre à l'utilisateur d'agir concrètement sur un robot depuis Jeedom : démarrer/mettre en pause/
arrêter un nettoyage, renvoyer le robot à sa base, le faire biper pour le localiser, et forcer la mise
à jour immédiate de son état. Cette dernière action est ce qui rend le MVP réellement utilisable en
l'absence de mise à jour temps réel (le push arrivant en post-MVP, UC10).

## Comportement attendu

Chaque robot créé (UC06) dispose des commandes d'action suivantes, sous réserve qu'elles soient
pertinentes pour le modèle détecté :
- démarrer un nettoyage ;
- mettre en pause un nettoyage en cours ;
- arrêter le nettoyage en cours ;
- renvoyer le robot à sa base de charge ;
- le faire localiser (signal sonore/visuel) ;
- forcer un rafraîchissement immédiat de son état (les commandes info de l'UC07 se mettent à jour dans
  la foulée).

Chaque action exécutée retourne un résultat exploitable par l'utilisateur ou un scénario : succès, ou
message d'erreur en français explicite (robot hors ligne, robot occupé par une autre action, action
refusée dans l'état courant du robot). Un robot injoignable (hors ligne) ne produit jamais un échec
silencieux : l'utilisateur voit clairement que l'action n'a pas pu être transmise.

## Critères d'acceptation

- [ ] **AC1** — Déclencher « démarrer » sur un robot à l'arrêt fait effectivement démarrer un
      nettoyage (constaté après rafraîchissement : état passé en « nettoyage »).
- [ ] **AC2** — Déclencher « pause » pendant un nettoyage en cours le met effectivement en pause.
- [ ] **AC3** — Déclencher « arrêter » stoppe le nettoyage en cours.
- [ ] **AC4** — Déclencher « retour à la base » fait effectivement rentrer le robot se recharger.
- [ ] **AC5** — Déclencher « localiser » déclenche un signal sur le robot physique.
- [ ] **AC6** — Déclencher « rafraîchir » met à jour les commandes info de l'UC07 sans attendre le
      prochain cycle automatique.
- [ ] **AC7** — Déclencher une action sur un robot hors ligne (constaté via l'UC07) renvoie un message
      d'erreur explicite (« robot hors ligne »), pas un succès trompeur ni un blocage silencieux.
- [ ] **AC8** — Déclencher une action refusée par le robot dans son état courant (ex. occupé) renvoie un
      message distinct (« action refusée », « robot occupé »).

## Impact i18n

- Nouvelles chaînes UI anticipées : « Démarrer », « Mettre en pause », « Arrêter », « Retour à la
  base », « Localiser », « Rafraîchir », « Robot hors ligne, action impossible », « Action refusée par
  le robot », « Robot occupé ».

## À confirmer

- Comportement exact de « pause » sur un robot n'étant pas en train de nettoyer (message d'erreur vs
  action neutre) : à vérifier en recette sur le Qrevo Curv.

## Hors périmètre

- Le pilotage fin (aspiration, eau, itinéraire, nettoyage de pièces/zones) : post-MVP.
- L'exécution des routines de l'application mobile : UC09.
