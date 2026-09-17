# UC17 — Cartes multiples (étages)

> **Domaine** : post-MVP / carte et pièces · **Statut** : à implémenter · **Dépend de** : UC16 (pièces et
> segments)

## Objectif

Un robot capable de mémoriser plusieurs cartes (typiquement un logement à étages) peut être configuré pour
nettoyer un étage précis. Cette UC permet à l'utilisateur de voir les cartes enregistrées par un robot et
de choisir laquelle est active, préalable à toute action de nettoyage localisée sur cet étage.

## Comportement attendu

- Pour un robot qui le supporte, le plugin liste les cartes enregistrées (au minimum un nom ou repère par
  carte), avec une indication de la carte actuellement active.
- L'utilisateur peut déclencher un changement de carte active depuis Jeedom. C'est une opération **lente et
  perturbante** pour le robot (le robot doit physiquement recharger/valider la carte) : elle est traitée
  comme une action distincte, pas comme un réglage anodin — l'utilisateur est informé qu'elle prend du
  temps et ne doit pas être répétée sans discernement (garde-fou anti-répétition rapprochée).
- Un changement de carte peut invalider la correspondance de pièces établie par l'UC16 (les segments d'une
  autre carte n'ont a priori rien à voir) : le changement de carte déclenche une resynchronisation des
  pièces pour la carte nouvellement active.
- Si le robot ne dispose que d'une seule carte (cas du matériel de test), la fonctionnalité se dégrade
  proprement : la carte unique est listée, aucune action de changement n'est nécessaire ni proposée comme
  pertinente, et rien ne doit sembler cassé pour autant.
- Si une tentative de changement de carte échoue côté robot (robot occupé, en erreur, hors ligne), l'échec
  est signalé clairement à l'utilisateur, sans laisser croire que le changement a eu lieu.

## Critères d'acceptation

- [ ] **AC1** — Sur le robot de test (une seule carte), la carte unique apparaît listée et marquée comme
      active ; aucune erreur ni action non pertinente n'est présentée à l'utilisateur.
- [ ] **AC2** — *(Multi-carte, non vérifiable sur le matériel de test — à valider si un robot multi-étages
      devient disponible)* : la liste des cartes enregistrées est affichée avec leurs noms, et la carte
      active est identifiable sans ambiguïté.
- [ ] **AC3** — *(Multi-carte)* Déclencher un changement de carte active aboutit, après le délai propre à
      l'opération, à ce que la nouvelle carte soit désignée comme active côté Jeedom, cohérente avec ce que
      montre l'app Roborock.
- [ ] **AC4** — *(Multi-carte)* Après un changement de carte réussi, la liste des pièces (UC16) reflète les
      pièces de la nouvelle carte active, sans mélange avec celles de l'ancienne.
- [ ] **AC5** — Deux déclenchements rapprochés d'un changement de carte sont empêchés ou avertis (pas
      d'enchaînement silencieux de deux opérations lourdes coup sur coup).
- [ ] **AC6** — Un changement de carte demandé alors que le robot est indisponible (occupé/hors ligne) se
      solde par un message d'échec explicite, sans que Jeedom n'affiche une carte active erronée.

## Impact i18n

- Chaînes anticipées : « Cartes », « Carte active », « Changer de carte », « Ce changement peut prendre du
  temps », « Impossible de changer de carte actuellement », « Une seule carte enregistrée ».

## À confirmer

- ⚠️ **Tout le comportement multi-carte est non vérifiable en recette avec le matériel de test** (Qrevo
  Curv, un seul étage). AC2 à AC4 sont à valider dès qu'un robot multi-cartes est disponible, ou à défaut
  documentées comme non recettées.
- Le contrat exact de récupération de la liste des cartes (`get_multi_maps_list`, **MQTT obligatoire**
  d'après `.memory/analyse/jeeroborock-mqtt-protocole.md` § 7) et son comportement si le canal MQTT est
  indisponible au moment de la demande : à confirmer à l'implémentation.
- Le délai raisonnable et le seuil du garde-fou anti-répétition (AC5) sont à trancher en spec technique.

## Hors périmètre

- La correspondance segment ↔ pièce elle-même : UC16.
- L'affichage visuel de la carte : UC18/UC19.
- Le lancement d'un nettoyage sur une pièce ou une zone : hors domaine, traité dans le pilotage fin.
