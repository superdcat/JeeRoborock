# UC03 — Pont PHP↔démon

> **Domaine** : MVP · **Statut** : à implémenter · **Dépend de** : UC02

## Objectif

Toutes les UC suivantes (authentification, découverte, commandes, routines) ont besoin d'un dialogue
fiable et sécurisé entre le PHP de Jeedom et le démon Python. Cette UC met en place ce canal une fois
pour toutes, avec une gestion d'erreur homogène et compréhensible pour l'utilisateur final.

## Comportement attendu

Toute opération demandant une réponse immédiate au démon (tester la connexion, s'authentifier,
découvrir les équipements, lister ou exécuter une routine, piloter un robot) passe par un même canal
technique, protégé et limité dans le temps. Ce canal n'est accessible que depuis Jeedom lui-même (pas
depuis l'extérieur de la machine).

Quand le démon répond une erreur connue (identifiants expirés, quota atteint, robot injoignable,
action refusée…), l'utilisateur voit un message **en français, compréhensible**, et non un message
technique anglais brut ou un code opaque. Quand le démon ne répond pas du tout dans un délai raisonnable
(injoignable, surchargé), l'utilisateur voit un message distinct indiquant que le démon n'a pas répondu,
différent d'un refus explicite du cloud Roborock.

Aucune donnée sensible (identifiants, jetons d'accès, clés de robot) ne doit jamais apparaître dans une
réponse affichée à l'utilisateur, dans les logs du plugin, ou être accessible autrement que par le
canal protégé.

## Critères d'acceptation

- [ ] **AC1** — Une opération synchrone envoyée au démon reçoit une réponse (succès ou erreur typée)
      dans un délai borné et visible côté utilisateur (ni blocage indéfini de la page, ni timeout muet).
- [ ] **AC2** — Une requête vers le canal local sans le jeton d'accès attendu (ou avec un jeton invalide)
      est rejetée, même émise depuis la machine locale.
- [ ] **AC3** — Une tentative d'accès au canal local depuis une autre machine du réseau échoue (le canal
      n'écoute que localement).
- [ ] **AC4** — Chaque catégorie d'erreur connue du cloud Roborock (identifiants expirés, quota atteint,
      robot hors ligne, action refusée, appareil occupé) produit, côté Jeedom, un message français
      distinct et compréhensible — jamais un message anglais recopié tel quel.
- [ ] **AC5** — Un démon injoignable (arrêté, ne répond pas) produit un message distinct de ceux des
      erreurs Roborock (« le démon ne répond pas » vs « le cloud refuse »).
- [ ] **AC6** — Aucune valeur sensible (jeton, clé de robot, mot de passe) n'apparaît dans les logs du
      plugin ni dans une réponse visible à l'utilisateur, y compris en cas d'erreur.

## Impact i18n

- Nouvelles chaînes UI anticipées : « Le démon ne répond pas », « Session expirée, veuillez vous
  reconnecter », « Trop de tentatives, veuillez patienter », « Robot hors ligne », « Action refusée par
  le robot », « Appareil occupé ».

## À confirmer

- Liste exhaustive des codes d'erreur stables exposés par le démon (cf.
  `jeeroborock-cloud-api.md` § 8) : à consolider au moment du plan technique, en s'assurant qu'aucune
  catégorie n'est oubliée ni ne « tombe » sur un message générique injustifié.

## Hors périmètre

- Le contenu métier des opérations elles-mêmes (authentification, découverte, pilotage) : UC04 et
  suivantes, cette UC ne traite que le **canal** et la traduction générique des erreurs.
- Le push asynchrone démon → Jeedom (callback) : hors MVP, cf. post-MVP `05-temps-reel-et-robustesse`.
