# UC21 — Débit d'eau

> **Domaine** : post-mvp/30-pilotage-fin · **Statut** : à implémenter · **Dépend de** : UC20

## Objectif

Pour les robots équipés d'une fonction de lavage, l'utilisateur veut pouvoir régler la quantité d'eau
utilisée par la serpillère — un débit trop faible sèche mal, un débit trop élevé laisse le sol trop
humide selon le revêtement. Cette UC apporte ce réglage côté Jeedom, en miroir de UC20 pour l'aspiration.

## Comportement attendu

- Une information affiche le débit d'eau actuellement appliqué, sous une forme lisible.
- Une action permet de choisir un nouveau débit parmi les **paliers réellement supportés par le robot
  connecté**, à nouveau issus des options dynamiques rapportées par le démon — jamais d'une liste en dur.
- Sur les modèles qui exposent un **débit continu** (réglage fin, pas seulement des paliers discrets), le
  réglage proposé reflète ce mode de réglage plutôt que de le forcer artificiellement dans des paliers
  qui ne correspondent pas à la réalité du modèle.
- Sur un robot **sans fonction de lavage** (pas de bac à eau/serpillère), ni l'information ni l'action de
  débit d'eau n'apparaissent — il n'y a rien à régler.
- Après envoi du réglage, l'information de débit est relue pour refléter la valeur effectivement adoptée
  par le robot.
- Le réglage est utilisable en scénario.

## Critères d'acceptation

- [ ] **AC1** — Sur le robot testé (Qrevo Curv, équipé de lavage), une information affiche le débit d'eau
      courant avec un libellé lisible.
- [ ] **AC2** — La liste des débits proposés correspond aux paliers (ou au mode de réglage continu, selon
      ce qui est réellement supporté) rapportés par le démon pour ce modèle.
- [ ] **AC3** — Après un changement de débit pendant un nettoyage avec lavage, l'information de débit
      reflète la nouvelle valeur une fois la commande acquittée.
- [ ] **AC4** — Sur un robot simulé/configuré sans fonction de lavage, ni la commande d'information ni la
      commande de réglage du débit d'eau n'existent sur l'équipement.
- [ ] **AC5** — Le réglage de débit est déclenchable depuis un scénario Jeedom.

## Impact i18n

- Nouvelles chaînes UI anticipées : « Débit d'eau », libellés des paliers (« Coupé », « Léger »,
  « Modéré », « Intense » — à confirmer sur le matériel de test).

## À confirmer

- Paliers effectivement exposés par le Qrevo Curv (`water_box_mode`, cf.
  `.memory/analyse/jeeroborock-mqtt-protocole.md` § 4 : 200 Off / 201 Mild / 202 Moderate / 203 Intense) —
  libellés français exacts à trancher à l'implémentation.
- Existence effective d'un mode de débit continu sur le matériel de test, ou réservé à d'autres gammes non
  testables ici.

## Hors périmètre

- La puissance d'aspiration : UC20.
- Le mode de nettoyage haut niveau et l'itinéraire de serpillère : UC22.
- Le lavage/séchage/vidage déclenchés à la station (actions d'entretien, pas réglage du débit pendant un
  nettoyage) : UC23.
