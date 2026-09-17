# UC20 — Puissance d'aspiration

> **Domaine** : post-mvp/30-pilotage-fin · **Statut** : à implémenter · **Dépend de** : UC08 (MVP —
> commandes de pilotage de base)

## Objectif

Le MVP ne permet de démarrer/arrêter/mettre en pause un nettoyage qu'avec la puissance d'aspiration en
cours. Cette UC permet à l'utilisateur de **consulter et régler** la puissance d'aspiration du robot,
directement depuis Jeedom, sans passer par l'application mobile — utile en scénario (ex. « aspiration
Max » quand la maison est vide, « Silencieux » le soir) comme en usage manuel.

## Comportement attendu

- Une information affiche la puissance d'aspiration actuellement appliquée par le robot, sous une forme
  lisible (le libellé du palier, pas un code numérique brut).
- Une action permet de choisir une nouvelle puissance parmi les **paliers réellement supportés par le
  robot connecté** — cette liste de choix n'est **jamais** codée en dur dans le plugin : elle vient de ce
  que le démon rapporte comme capacités du modèle. Un modèle qui ne proposerait qu'un sous-ensemble des
  paliers habituels n'affiche que ce sous-ensemble.
- Après envoi du réglage, l'information de puissance est relue pour refléter la valeur **effectivement
  appliquée** par le robot — le robot peut refuser ou ajuster un réglage dans certains états (ex. en
  retour à la base), et l'utilisateur doit voir la réalité plutôt qu'une valeur optimiste.
- L'action de réglage est utilisable depuis un scénario Jeedom au même titre que les actions de pilotage
  de base (paramétrable, sans intervention manuelle).
- Si le robot n'est pas dans un état permettant le changement de puissance, l'échec est signalé de façon
  compréhensible plutôt que silencieusement ignoré.

## Critères d'acceptation

- [ ] **AC1** — L'équipement d'un robot connecté affiche une information de puissance d'aspiration avec un
      libellé lisible (pas un simple code numérique).
- [ ] **AC2** — La liste des puissances proposées à l'utilisateur correspond aux paliers réellement
      supportés par le robot testé (Qrevo Curv) — pas de palier fantôme non applicable, pas de palier
      manquant parmi ceux effectivement supportés.
- [ ] **AC3** — Après déclenchement du réglage sur un palier donné pendant un nettoyage, l'information de
      puissance affiche ce palier une fois la commande acquittée par le robot.
- [ ] **AC4** — Le réglage de puissance est déclenchable depuis un scénario Jeedom (action paramétrée par
      la valeur souhaitée), sans interaction manuelle.
- [ ] **AC5** — Une tentative de réglage refusée par le robot (ex. état incompatible) se traduit par un
      retour d'erreur exploitable côté Jeedom, et l'information de puissance n'affiche pas une valeur que
      le robot n'a pas réellement adoptée.

## Impact i18n

- Nouvelles chaînes UI anticipées : « Puissance d'aspiration », libellés des paliers (« Silencieux »,
  « Équilibré », « Turbo », « Max », « Max+ » — à confirmer sur le matériel de test), message d'échec de
  réglage.

## À confirmer

- Paliers effectivement exposés par le Qrevo Curv (`roborock.vacuum.a135`) : la table de référence
  ioBroker (cf. `.memory/analyse/jeeroborock-mqtt-protocole.md` § 4) indique Silencieux/Équilibré/Turbo/
  Max/Max+, mais seule la vérification en recette sur le robot réel fait foi.
- Comportement du robot (acceptation/refus) selon son état courant (en nettoyage, en pause, en retour
  base, à l'arrêt) : à vérifier en recette, faute de documentation officielle.

## Hors périmètre

- Le débit d'eau de la serpillère : UC21.
- Le mode de nettoyage haut niveau (aspiration seule / lavage seul / aspiration + lavage) et l'itinéraire
  de serpillère, qui combinent puissance d'aspiration et autres réglages : UC22.
