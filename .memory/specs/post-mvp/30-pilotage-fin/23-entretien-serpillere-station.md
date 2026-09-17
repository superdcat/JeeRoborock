# UC23 — Lavage, séchage et vidage à la station

> **Domaine** : post-mvp/30-pilotage-fin · **Statut** : à implémenter · **Dépend de** : UC13 (état de la
> station d'accueil)

## Objectif

UC13 rend visible l'état des opérations d'entretien effectuées par la station (vidage, lavage, séchage).
Cette UC permet de **déclencher** ces opérations depuis Jeedom — par exemple lancer un séchage de
serpillère avant un long moment d'inutilisation, ou forcer un vidage — sans attendre le cycle automatique
ni ouvrir l'application mobile.

## Comportement attendu

- Pour une station qui supporte le **lavage de la serpillère**, une action permet de le déclencher.
- Pour une station qui supporte le **séchage**, une action permet de le déclencher (et, si le contrat le
  permet, de l'arrêter avant son terme naturel).
- Pour une station qui supporte le **vidage automatique du bac**, une action permet de le déclencher.
- Chacune de ces actions n'apparaît que si la station connectée supporte réellement la fonction
  correspondante (capacités détectées) — pas d'action de vidage sur une station basique, par exemple.
- Ces actions sont **conditionnées à la position du robot** : elles n'ont de sens que si le robot est à la
  base. Une tentative alors que le robot n'est pas à la base est refusée avec un message explicite
  (« le robot doit être à la base pour lancer cette opération »), plutôt que de partir en échec silencieux
  ou obscur côté robot.
- Ce sont des opérations **longues** (plusieurs minutes) : l'action ne bloque pas en attendant la fin de
  l'opération. L'évolution de l'opération se suit via les informations d'état de la station (UC13), qui se
  mettent à jour au fil du déroulement, pas via un retour synchrone de l'action elle-même.
- Ces actions sont utilisables en scénario (ex. lancer un séchage automatiquement après chaque
  nettoyage).

## Critères d'acceptation

- [ ] **AC1** — Sur une station supportant le lavage, une action « Laver la serpillère » démarre
      effectivement un cycle de lavage constatable via l'information d'état de station (UC13) qui passe à
      « en cours » puis revient à « au repos ».
- [ ] **AC2** — Sur une station supportant le séchage, une action « Sécher la serpillère » démarre un
      cycle observable de la même façon.
- [ ] **AC3** — Sur une station supportant le vidage automatique, une action « Vider le bac » démarre un
      cycle de vidage observable de la même façon.
- [ ] **AC4** — Sur une station basique (sans ces fonctions), aucune de ces actions n'apparaît sur
      l'équipement.
- [ ] **AC5** — Une tentative de déclenchement alors que le robot n'est pas à la base est rejetée par le
      plugin avec un message explicite, sans transmettre une commande vouée à échouer côté robot.
- [ ] **AC6** — Chacune de ces actions est déclenchable depuis un scénario Jeedom, et l'action revient
      (rend la main au scénario) sans attendre la fin réelle du cycle d'entretien.

## Impact i18n

- Nouvelles chaînes UI anticipées : « Laver la serpillère », « Sécher la serpillère », « Vider le bac »,
  message « Le robot doit être à la base pour lancer cette opération ».

## À confirmer

- Disponibilité et comportement exact de l'arrêt anticipé du séchage (`app_stop_wash`/équivalent) sur le
  matériel de test — cf. `.memory/analyse/jeeroborock-mqtt-protocole.md` § 6.
- Comportement du robot si une opération est demandée alors qu'une autre opération d'entretien est déjà en
  cours (mise en file, refus, remplacement) — à vérifier en recette faute de documentation.

## Hors périmètre

- L'affichage de l'état des opérations d'entretien : UC13 (déjà couvert, cette UC ne fait qu'ajouter le
  déclenchement).
- Les consommables et leur usure (brosse, filtre) : UC12.
- Le déclenchement d'un nettoyage lui-même (pièce, zone, global) : UC24, UC25, MVP UC08.
