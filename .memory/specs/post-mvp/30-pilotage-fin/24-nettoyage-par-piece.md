# UC24 — Nettoyage par pièce

> **Domaine** : post-mvp/30-pilotage-fin · **Statut** : à implémenter · **Dépend de** : UC16 (pièces
> nommées et segments), UC22 (mode de nettoyage)

⚠️ UC à forte valeur d'usage : c'est le cas d'usage domotique le plus demandé après les routines.

## Objectif

L'utilisateur veut pouvoir demander « nettoie la cuisine » ou « nettoie le salon et l'entrée » sans
ouvrir l'application mobile, typiquement depuis un scénario Jeedom (ex. déclenché après un capteur de
présence, ou à heure fixe pour une pièce donnée). Cette UC apporte ce nettoyage ciblé, en s'appuyant sur
les pièces nommées connues du plugin (UC16).

## Comportement attendu

- Une action permet de lancer un nettoyage portant sur **une ou plusieurs pièces** choisies parmi celles
  connues du plugin pour ce robot (issues de UC16). L'utilisateur raisonne en **noms de pièces** ; la
  correspondance vers l'identifiant technique attendu par le robot (le segment) est faite par le plugin,
  invisible pour l'utilisateur.
- Pour un usage dashboard simple, une action dédiée par pièce peut également exister (« Nettoyer la
  cuisine » comme bouton direct), en complément de l'action paramétrée multi-pièces.
- L'action est utilisable en scénario, avec passage d'une ou plusieurs pièces en paramètre.
- Si l'utilisateur désigne une pièce dont le **segment a disparu** depuis la dernière cartographie connue
  du plugin (pièce supprimée ou carte redécoupée côté robot), le plugin ne lance pas un nettoyage sur un
  identifiant obsolète : il signale l'incohérence de façon compréhensible (« pièce inconnue de la carte
  actuelle, une resynchronisation est peut-être nécessaire ») plutôt que de transmettre une commande vouée
  à l'échec ou à cibler la mauvaise pièce.
- Le nettoyage d'une ou plusieurs pièces se comporte, du point de vue de l'état du robot, comme un
  nettoyage normal : les informations d'état, avancement, surface et durée (MVP) reflètent ce cycle ciblé
  pendant son déroulement.
- Le nombre de répétitions de passage et le mode de nettoyage appliqués à ce lancement sont **cohérents
  avec les réglages en cours** du robot (UC20-UC22) sauf si la spec technique en décide autrement — ce
  point est renvoyé à l'implémentation, cf. « À confirmer ».

## Critères d'acceptation

- [ ] **AC1** — Après resynchronisation des pièces (UC16), une action de nettoyage ciblé propose bien les
      pièces nommées connues du robot testé.
- [ ] **AC2** — Déclencher l'action sur une seule pièce fait effectivement démarrer le robot et nettoyer
      cette pièce (constaté visuellement et via l'évolution de l'état/avancement côté Jeedom), sans
      nettoyer le reste du logement.
- [ ] **AC3** — Déclencher l'action sur plusieurs pièces à la fois fait nettoyer l'ensemble des pièces
      désignées au cours du même cycle.
- [ ] **AC4** — Une action dédiée à une pièce précise (bouton direct) est disponible sur l'équipement pour
      un usage dashboard, en plus de l'action paramétrée.
- [ ] **AC5** — L'action est déclenchable depuis un scénario Jeedom avec une ou plusieurs pièces en
      paramètre.
- [ ] **AC6** — Désigner une pièce dont le segment n'existe plus dans la cartographie actuelle du plugin
      produit un message d'erreur explicite, sans déclencher de nettoyage.

## Impact i18n

- Nouvelles chaînes UI anticipées : « Nettoyer une pièce », « Nettoyer les pièces sélectionnées »,
  « Nettoyer <nom de pièce> » (par pièce), message « Pièce inconnue de la carte actuelle ».

## À confirmer

- Nombre de répétitions et mode de nettoyage effectivement appliqués au lancement (réglages courants du
  robot vs valeurs par défaut imposées par la commande) : à trancher en spec technique, cf.
  `.memory/analyse/jeeroborock-mqtt-protocole.md` § 6 (`app_segment_clean`, `set_clean_repeat_times`).
- Mécanisme exact de détection d'un segment disparu (comparaison à la dernière liste de pièces connue,
  ou tentative + interprétation d'un refus du robot) : dépend du contrat exposé par UC16, à vérifier à
  l'implémentation.
- Comportement si l'action est déclenchée alors qu'un nettoyage est déjà en cours (mise en file, refus,
  remplacement du cycle en cours) : à vérifier en recette sur le Qrevo Curv.

## Hors périmètre

- La détection et la synchronisation des pièces nommées elles-mêmes : UC16.
- Le nettoyage d'une zone rectangulaire ou le déplacement vers un point précis (hors notion de pièce) :
  UC25.
- Le réglage indépendant de puissance/eau/itinéraire/mode : UC20-UC22 (cette UC les utilise tels quels au
  moment du lancement).
