# UC07 — Commandes info : état, batterie, erreurs

> **Domaine** : MVP · **Statut** : à implémenter · **Dépend de** : UC06

## Objectif

Rendre visible dans Jeedom l'état courant d'un robot déjà créé (UC06) : état de fonctionnement,
batterie, erreurs éventuelles, avancement du nettoyage en cours — la base indispensable avant de pouvoir
piloter quoi que ce soit (UC08) ou construire des scénarios.

## Comportement attendu

Pour chaque robot, le plugin expose un socle de commandes d'information reflétant son état :
- un état lisible en français (ex. « En nettoyage », « À la base », « En erreur »…) et un code d'état
  numérique associé (pour les scénarios) ;
- le niveau de batterie ;
- un indicateur « en cours de nettoyage » ;
- une erreur en cours, avec son libellé et son code (vide/neutre si aucune erreur) ;
- la surface nettoyée et la durée du cycle en cours ;
- l'état de connexion : le robot est-il vu en ligne par le cloud, le canal du démon est-il connecté,
  et à quand remonte la dernière donnée reçue.

Seules les commandes correspondant à des capacités **réellement détectées** sur le robot sont créées :
un robot qui ne supporte pas une information donnée (ex. avancement estimé) n'affiche pas de commande
vide ou figée pour cette information.

Les libellés d'état et d'erreur affichés à l'utilisateur ne sont **pas** rédigés en dur dans le plugin :
ils proviennent des informations transmises par le démon, de manière à rester corrects même si Roborock
fait évoluer sa liste de codes.

Au MVP, ces valeurs sont mises à jour **à la demande** (déclenchement d'un rafraîchissement, cf. UC08) ;
elles ne se mettent pas encore à jour en temps réel toutes seules (le push arrive en post-MVP, UC10).
Une resynchronisation ultérieure du robot ajoute les commandes nouvellement détectées, sans dupliquer
celles déjà présentes.

## Critères d'acceptation

- [ ] **AC1** — Après création d'un équipement (UC06) puis un premier rafraîchissement, les commandes
      d'état, de batterie, de nettoyage en cours et d'erreur sont visibles avec des valeurs cohérentes
      avec l'état réel du robot.
- [ ] **AC2** — L'état affiché est un texte français compréhensible (pas un code brut) accompagné d'un
      code numérique séparé exploitable en scénario.
- [ ] **AC3** — Lorsqu'aucune erreur n'est en cours, la commande d'erreur affiche une valeur neutre
      (vide/absence d'erreur), pas un code ou texte parasite.
- [ ] **AC4** — Une capacité non supportée par le robot testé ne génère **aucune** commande info vide ou
      figée à une valeur par défaut.
- [ ] **AC5** — Deux synchronisations successives ne dupliquent aucune commande (même liste avant/après
      si aucune nouvelle capacité n'a été détectée).
- [ ] **AC6** — Les informations de connexion (en ligne / connecté / date de dernière mise à jour)
      reflètent un changement observable : robot éteint ou déconnecté du réseau → l'indicateur « en
      ligne » ou « connecté » bascule après le rafraîchissement suivant.

## Impact i18n

- Nouvelles chaînes UI anticipées : noms des commandes (« État », « Batterie », « En nettoyage »,
  « Erreur », « Surface nettoyée », « Durée de nettoyage », « En ligne », « Connecté », « Dernière mise
  à jour »). Les **libellés d'état/d'erreur eux-mêmes** viennent du démon et ne sont pas des clés i18n
  statiques du plugin.

## À confirmer

- Unité et arrondi exacts de la surface nettoyée (m², décimales) et de la durée (minutes vs secondes) :
  à trancher au plan technique, cf. `jeeroborock-modele-equipement.md` § 7 point 3.
- Disponibilité de l'avancement estimé (`clean_percent`) sur le Qrevo Curv de test.

## Hors périmètre

- Les commandes d'action (démarrer, arrêter, rafraîchir…) : UC08.
- Les routines : UC09.
- Le rafraîchissement automatique en temps réel (push) : post-MVP UC10.
