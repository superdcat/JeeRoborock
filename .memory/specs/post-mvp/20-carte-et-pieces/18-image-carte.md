# UC18 — Image de la carte

> **Domaine** : post-MVP / carte et pièces · **Statut** : à implémenter · **Dépend de** : UC17 (cartes
> multiples)

## Objectif

Avant de pouvoir afficher une carte à l'utilisateur (UC19), le plugin doit être capable de récupérer
l'image de la carte active d'un robot et de la rendre disponible à Jeedom, à un rythme raisonnable et sans
faire grossir indéfiniment l'espace occupé sur le serveur Jeedom.

## Comportement attendu

- Pour un robot ayant une carte, le plugin récupère une image représentant cette carte (rendu déjà produit
  par la chaîne technique — le plugin ne dessine rien lui-même).
- Cette image est stockée localement côté plugin de façon **bornée** : la donnée la plus récente remplace
  la précédente pour un même robot, sans accumulation illimitée de fichiers/historique dans le temps.
- Le rafraîchissement de l'image est **périodique et plafonné** : de l'ordre de 30 secondes au maximum
  entre deux récupérations pour un même robot — jamais une récupération systématique à chaque tick de la
  boucle de rafraîchissement temps réel (coût élevé côté robot/cloud), et jamais figé indéfiniment sur une
  image obsolète tant que le robot communique.
- Si le robot n'a **jamais cartographié** (aucune carte n'existe encore), le plugin le signale distinctement
  d'une erreur technique : c'est un état normal et temporaire, pas un dysfonctionnement.
- Si la récupération de l'image échoue ponctuellement (robot indisponible, canal coupé), la dernière image
  connue reste disponible plutôt que d'être effacée, et l'ancienneté de cette image reste identifiable.
- L'image est rendue accessible à Jeedom d'une façon compatible avec les contraintes de sécurité du
  navigateur (pas de dépendance à une ressource externe chargée directement par le navigateur) — le détail
  du mécanisme de mise à disposition (calque serveur ou proxy) revient à la spec technique et à UC19.

## Critères d'acceptation

- [ ] **AC1** — Sur le robot de test ayant déjà cartographié, une image de carte est récupérable côté
      plugin et correspond visuellement à la carte visible dans l'app Roborock (même agencement général des
      pièces).
- [ ] **AC2** — Deux récupérations rapprochées (moins de 30 secondes d'écart) ne déclenchent pas deux
      appels au robot : l'image servie la seconde fois est celle déjà en cache, pas un nouvel appel.
- [ ] **AC3** — Laissée en fonctionnement plusieurs minutes, l'image se met à jour à un rythme régulier
      (de l'ordre de la trentaine de secondes), sans dérive de croissance de l'espace de stockage utilisé
      par le plugin pour cette donnée (une image par robot, pas un historique qui s'accumule).
- [ ] **AC4** — Robot n'ayant jamais cartographié : l'état constaté côté plugin indique explicitement
      « pas encore de carte », sans remonter d'erreur technique.
- [ ] **AC5** — Robot rendu temporairement injoignable (démon arrêté ou robot hors ligne) : la dernière
      image connue reste consultable, sans être supprimée par l'absence de nouvelle donnée.

## Impact i18n

- Chaînes anticipées : « Aucune carte disponible pour le moment », « Carte indisponible », « Dernière
  carte connue du <date/heure> » (ou équivalent d'ancienneté).

## À confirmer

- Le format exact de transfert démon → PHP (base64 inline vs fichier) et l'emplacement de stockage borné
  sont du ressort de la spec technique ; cette UC pose la contrainte fonctionnelle (borné, pas d'historique
  illimité), pas le mécanisme.
- La valeur exacte du plafond de rafraîchissement (30 s de référence Home Assistant,
  cf. `.memory/analyse/jeeroborock-mqtt-protocole.md` § 7) est à confirmer/ajuster à l'implémentation selon
  le coût réellement constaté sur le canal robot.
- Le canal de récupération de la carte (`get_map_v1`, canal dédié d'après l'analyse) et son comportement en
  cas d'indisponibilité MQTT : à valider techniquement, sans impact sur les AC fonctionnels ci-dessus.

## Hors périmètre

- L'affichage de la carte à l'utilisateur (widget ou page) : UC19.
- La gestion des cartes multiples et du changement de carte active : UC17.
- Le tracé du parcours du robot ou d'une zone de nettoyage sur l'image : non prévu à ce stade (à évaluer
  dans une UC ultérieure si le besoin est confirmé).
