# UC12 — Consommables et usure

> **Domaine** : post-mvp/10-etats-detailles · **Statut** : à implémenter · **Dépend de** : UC07

## Objectif

Le robot use progressivement ses consommables (brosse principale, brosse latérale, filtre, capteurs,
rouleau de serpillière). Cette UC expose leur usure sous une forme directement compréhensible par
l'utilisateur — un **pourcentage restant** — et permet de réinitialiser le compteur après remplacement,
sans obliger à retenir des durées en heures ou à consulter l'application mobile.

## Comportement attendu

Pour chaque consommable **réellement supporté par le robot connecté**, le plugin affiche un pourcentage
d'usure restant (100 % = neuf, 0 % = à remplacer), calculé à partir du temps d'usage cumulé remonté par
le robot et d'une durée de référence avant remplacement. Un consommable non supporté par le modèle du
robot (absent des données remontées après une première lecture) **ne fait apparaître aucune commande**
correspondante — pas de valeur figée à 0 % ou vide qui laisserait croire à une panne.

L'utilisateur peut déclencher, pour chaque consommable supporté, une action de réinitialisation
(« consommable remplacé »). Cette action est **sensible** : elle demande une confirmation avant
exécution, pour éviter qu'un clic accidentel remette à zéro un compteur d'usure encore valide. Après
confirmation, le compteur repasse à 100 % et cette nouvelle valeur est visible sans délai anormal.

Un pourcentage d'usure qui atteint 0 % reste une donnée exploitable telle quelle (pas d'erreur, pas de
valeur négative) : elle doit pouvoir déclencher une alerte via un scénario Jeedom (« la brosse principale
est usée, il faut la remplacer »).

## Critères d'acceptation

- [ ] **AC1** — Pour un robot dont la brosse principale, la brosse latérale et le filtre sont détectés
      comme supportés, trois informations de pourcentage d'usure restant apparaissent, chacune comprise
      entre 0 et 100.
- [ ] **AC2** — Pour un consommable non supporté par le robot (ex. rouleau de serpillière sur un modèle
      qui n'en a pas), aucune commande d'usure ni de réinitialisation n'est créée pour ce consommable.
- [ ] **AC3** — Déclencher l'action de réinitialisation d'un consommable déclenche une demande de
      confirmation ; sans confirmation, l'usure reste inchangée.
- [ ] **AC4** — Après confirmation de la réinitialisation, le pourcentage d'usure du consommable
      concerné repasse à 100 %, et les autres consommables ne sont pas affectés.
- [ ] **AC5** — Un consommable dont l'usure atteint 0 % conserve une valeur numérique exploitable
      (0, pas vide ni erreur) et peut servir de condition dans un scénario Jeedom.
- [ ] **AC6** — La réinitialisation d'un consommable est suivie d'un rafraîchissement visible de sa
      valeur, sans nécessiter d'action manuelle supplémentaire de l'utilisateur (ex. forcer une lecture).

## Impact i18n

- Nouvelles chaînes UI anticipées : « Usure brosse principale », « Usure brosse latérale »,
  « Usure filtre », « Usure capteurs », « Usure rouleau de serpillière », « Réinitialiser (brosse
  principale/latérale, filtre, capteurs, rouleau) », message de confirmation, message pédagogique en cas
  d'action indisponible.

## À confirmer

- Durées de référence exactes utilisées pour convertir les secondes d'usage en pourcentage restant, et
  l'endroit où cette conversion est faite (démon ou plugin) — cf.
  `.memory/analyse/jeeroborock-mqtt-protocole.md` § 5 (valeurs `python-roborock` : brosse principale
  300 h, brosse latérale 200 h, filtre 150 h, capteurs 30 h, rouleau de serpillière 300 h — à vérifier
  qu'elles s'appliquent bien au Qrevo Curv).
- Consommables effectivement supportés/non nuls sur le Qrevo Curv après un premier refresh (le contrat
  générique liste jusqu'à 8 attributs, tous ne sont pas garantis peuplés).
- Délai de rafraîchissement acceptable après réinitialisation (dépend du cycle pull/push du démon,
  cf. `jeeroborock-mqtt-protocole.md` § 3).

## Hors périmètre

- La lecture de l'état général du robot (batterie, état de nettoyage) : UC07.
- L'état d'entretien de la station d'accueil (vidage, lavage/séchage serpillière) : UC13.
- Les notifications proactives (ex. alerte automatique à seuil bas) : hors périmètre de cette UC, qui se
  limite à exposer une donnée exploitable en scénario par l'utilisateur.
