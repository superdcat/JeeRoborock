# UC27 — Statistiques cumulées

> **Domaine** : post-mvp/40-journal-et-planification · **Statut** : à implémenter · **Dépend de** : UC26

## Objectif

Au-delà du dernier nettoyage, l'utilisateur veut suivre l'usage global de son robot dans la durée : combien
de temps il a nettoyé au total, quelle surface totale a été couverte, combien de nettoyages et combien de
vidages du bac ont eu lieu depuis la mise en service. C'est une donnée d'**historisation** avant tout : sa
valeur vient du suivi de la tendance dans le temps (scénarios/graphiques Jeedom), pas de la valeur
instantanée.

## Comportement attendu

Le plugin expose quatre compteurs cumulés par robot : durée totale de nettoyage, surface totale nettoyée,
nombre total de nettoyages, nombre total de vidages du bac. Ces valeurs sont exprimées dans des unités
directement lisibles (heures, m²) — les valeurs brutes remontées par le robot ne sont pas nécessairement
dans ces unités et doivent être converties de façon documentée et cohérente avec le reste du plugin (cf.
`surface_nettoyee`/`duree_nettoyage` du socle MVP).

Ces commandes sont rafraîchies à **fréquence faible** (un rafraîchissement horaire est largement
suffisant compte tenu de la nature cumulative de la donnée) et sont **historisables** dans Jeedom, pour
permettre à l'utilisateur de suivre leur évolution (ex. courbe de surface totale nettoyée par mois).

Cas dégradés attendus :
- **Robot neuf / jamais nettoyé** : les compteurs affichent zéro, pas une valeur absente ou une erreur.
- **Cloud indisponible au moment du rafraîchissement prévu** : la dernière valeur connue est conservée
  (les compteurs cumulés ne peuvent pas régresser), le prochain rafraîchissement réussi les met à jour.

## Critères d'acceptation

- [ ] **AC1** — Les quatre compteurs (durée totale, surface totale, nombre de nettoyages, nombre de
      vidages) sont visibles sur l'équipement du robot de test, avec une unité affichée explicite (heures,
      m²).
- [ ] **AC2** — Après un nettoyage réel effectué avec le robot de test, le nombre total de nettoyages et
      la durée/surface totale augmentent d'une valeur cohérente avec ce nettoyage (constatable en
      comparant avant/après).
- [ ] **AC3** — Les commandes de statistiques cumulées sont historisables (activables dans l'historisation
      Jeedom comme n'importe quelle commande info numérique) et une courbe peut être affichée sur au moins
      l'une d'entre elles après plusieurs relevés.
- [ ] **AC4** — Le rafraîchissement de ces commandes ne se produit pas plus souvent qu'une fois par heure
      en fonctionnement normal (vérifiable via l'historique ou les logs du démon), sauf rafraîchissement
      manuel explicite s'il est proposé.
- [ ] **AC5** — Si le cloud est indisponible au moment d'un rafraîchissement prévu, les compteurs affichés
      restent à leur dernière valeur connue (ils ne repassent jamais à zéro ni ne diminuent).

## Impact i18n

- Nouvelles chaînes UI anticipées : « Durée totale de nettoyage », « Surface totale nettoyée », « Nombre
  de nettoyages », « Nombre de vidages du bac », unités « h », « m² ».

## À confirmer

- Nom exact des champs cumulés renvoyés par le cloud/protocole pour un robot V1 (`a135`) et leur unité
  brute (à convertir) : non détaillé dans les analyses internes actuelles, à vérifier en recette sur le
  Qrevo Curv (cf. `.memory/analyse/jeeroborock-mqtt-protocole.md`).
- Disponibilité du compteur « nombre de vidages du bac » sur un robot sans station de vidage automatique
  (le Qrevo Curv en possède une, mais ce compteur pourrait ne pas exister ou toujours valoir zéro sur un
  autre modèle) — à traiter comme une commande **conditionnelle** si l'information n'est pas disponible.

## Hors périmètre

- Le détail nettoyage par nettoyage (dates, durées individuelles) : couvert par UC26 (journal).
- Toute remise à zéro des compteurs (réservée à l'application Roborock, non exposée en écriture).
