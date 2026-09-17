# UC19 — Vue carte (widget ou page panneau)

> **Domaine** : post-MVP / carte et pièces · **Statut** : à implémenter · **Dépend de** : UC18 (image de
> la carte)

## Objectif

Rendre la carte du robot **visible et utile** à l'utilisateur final, avec les pièces identifiables, plutôt
que réservée à une consultation technique. C'est le point de restitution visuel des UC16-18.

## Comportement attendu

- L'utilisateur peut consulter, depuis Jeedom, une représentation visuelle de la carte active d'un robot
  qu'il a le droit de voir.
- Les pièces connues (UC16) sont identifiables sur ou à côté de cette vue (a minima une légende associant
  nom de pièce et repère visuel, à défaut d'un contour dessiné directement sur l'image si la donnée ne le
  permet pas).
- La vue se rafraîchit automatiquement à un rythme cohérent avec celui de la récupération de l'image
  (UC18) — l'utilisateur n'a pas besoin de recharger la page pour voir une carte qui a évolué, sans que
  cela ne déclenche de récupération excessive côté robot.
- Si aucune carte n'est disponible pour le robot consulté (jamais cartographié, ou robot durablement
  injoignable sans image connue), la vue l'indique clairement plutôt que d'afficher un espace vide ou une
  image cassée.
- L'accès à la vue respecte les droits Jeedom : un utilisateur non-admin ne voit que les robots pour
  lesquels il a un droit de lecture ; un utilisateur sans droit sur le plugin ou sur l'équipement n'accède
  pas à sa carte.
- Le choix du support de présentation (widget de dashboard posé sur une commande, ou page dédiée au menu
  d'accueil) est laissé ouvert par cette spec : ce qui compte est le résultat perçu ci-dessus, pas le
  support technique retenu (arbitrage en spec technique).

## Critères d'acceptation

- [ ] **AC1** — Un utilisateur ayant les droits sur le robot de test consulte la carte de ce robot et voit
      une image reconnaissable, avec au moins les noms des pièces détectées visibles quelque part dans la
      vue (légende ou superposition).
- [ ] **AC2** — Laissée ouverte plusieurs minutes pendant un nettoyage, la vue se met à jour sans action de
      l'utilisateur (nouvelle carte visible après le délai de rafraîchissement propre à UC18), sans qu'un
      rechargement manuel de page soit nécessaire.
- [ ] **AC3** — Robot sans carte disponible : la vue affiche un message explicite (« pas de carte pour le
      moment » ou équivalent), jamais une image cassée ou un espace vide sans explication.
- [ ] **AC4** — Un utilisateur Jeedom sans droit de lecture sur l'équipement robot ne peut pas consulter sa
      carte (ni via une URL directe, ni via le panneau/dashboard).
- [ ] **AC5** — La vue est utilisable par un utilisateur non-administrateur (pas seulement par un admin
      Jeedom).

## Impact i18n

- Chaînes anticipées : « Carte », « Pièces détectées », « Aucune carte disponible », « Accès refusé »
  (ou équivalent du contrôle de droit s'il produit un message visible).

## À confirmer

- Le support retenu (widget dashboard vs page-panneau) conditionne le mécanisme technique de mise à
  disposition de l'image (proxy same-origin pour un widget rendu côté client, `data:` URI possible pour une
  page-panneau rendue côté serveur — cf. `.memory/analyse/jeedom-widgets-commandes.md` § 7 et
  `.memory/analyse/jeedom-panel-page-menu.md` § 4) : ce choix est renvoyé à la spec technique, sans impact
  sur les critères d'acceptation fonctionnels ci-dessus.
- Si le support choisi est une page-panneau, le contrôle d'accès par équipement (`hasRight('r')` en plus de
  la connexion utilisateur, cf. `.memory/analyse/jeedom-panel-page-menu.md` § 4) est un pré-requis
  mentionné ici pour mémoire ; sa mise en œuvre concrète est technique.
- La représentation exacte des pièces sur l'image (contour dessiné vs simple légende) dépend de ce que
  fournit effectivement la donnée de carte (UC18) : à confirmer une fois le rendu observé sur le matériel
  de test.

## Hors périmètre

- La récupération et la mise en cache de l'image elle-même : UC18.
- La correspondance segment/pièce : UC16. Le changement de carte active : UC17.
- Toute interaction de pilotage depuis la carte (cliquer une pièce pour lancer un nettoyage ciblé) : hors
  domaine, éventuel enrichissement futur du pilotage fin, non couvert ici.
