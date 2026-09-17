# UC10 — Rafraîchissement temps réel et fraîcheur

> **Domaine** : post-MVP / temps réel et robustesse · **Statut** : à implémenter · **Dépend de** : UC07
> (MVP — commandes info)

## Objectif

Le MVP expose l'état du robot uniquement via une action de rafraîchissement à la demande. Cette UC fait
passer le plugin à un suivi **temps réel** : les commandes info (état, batterie, erreur, consommables…)
se tiennent à jour toutes seules, portées par le canal robot (push) et une boucle de fond, sans que
l'utilisateur ait à cliquer. Elle introduit aussi une notion de **fraîcheur** de la donnée, condition
préalable à un mode dégradé fiable (UC11).

## Comportement attendu

- Une fois le démon démarré et le robot connecté, les commandes info se mettent à jour **sans action de
  l'utilisateur**, au fil des événements reçus du robot (changement d'état, de batterie, apparition/
  disparition d'une erreur, avancement d'un consommable...).
- En complément du push, une boucle de fond interroge périodiquement le robot pour compléter les
  informations qui ne sont pas poussées spontanément. La cadence est plus rapprochée pendant un cycle de
  nettoyage en cours, et plus espacée quand le robot est au repos — l'objectif perçu par l'utilisateur
  est qu'un dashboard ouvert pendant un nettoyage reflète une progression fluide, pas un instantané figé
  toutes les minutes.
- Cette boucle de fond est portée par le démon (le processus qui tourne en continu), **pas** par une
  tâche planifiée PHP : Jeedom n'exécute pas de tâche périodique à une cadence inférieure à la minute, et
  le canal robot doit rester ouvert en continu pour recevoir le push, ce qu'un cron PHP ne permet pas.
- Le plugin expose une commande info donnant l'**horodatage de la dernière donnée reçue** pour le robot
  (dernier push traité ou dernier rafraîchissement périodique réussi). Cet horodatage progresse tant que
  le robot communique, et s'arrête de progresser si la communication est coupée.
- La tâche planifiée PHP existante change de rôle : elle ne va plus chercher l'état du robot elle-même ;
  elle vérifie que le démon est vivant et que la donnée reçue pour chaque robot n'est pas trop ancienne
  (au-delà d'un délai raisonnable au regard de la cadence de rafraîchissement attendue). Si la donnée est
  trop ancienne, l'indicateur de connexion du robot concerné bascule pour le signaler. Un robot en défaut
  ne doit jamais empêcher la vérification des autres robots configurés.
- L'action de rafraîchissement immédiat livrée au MVP (UC08) continue de fonctionner à l'identique après
  cette UC : elle force une lecture immédiate en plus du flux automatique, elle n'est pas remplacée.
- Seules les valeurs qui ont réellement changé donnent lieu à une mise à jour de commande — on évite de
  réécrire en continu une valeur inchangée (impact sur l'historique et la charge Jeedom).

## Critères d'acceptation

- [ ] **AC1** — Robot en cours de nettoyage, sans qu'aucune action ne soit déclenchée manuellement : la
      commande d'état et la commande de batterie évoluent au fil du temps (constaté sur au moins deux
      valeurs différentes en l'espace de quelques minutes).
- [ ] **AC2** — Une commande info « dernière mise à jour » (ou équivalent) affiche un horodatage qui
      avance tant que le robot est joignable, visible depuis le dashboard/l'historique de la commande.
- [ ] **AC3** — Le démon arrêté puis relancé : le suivi automatique reprend sans intervention
      supplémentaire de l'utilisateur (pas besoin de rouvrir la config ou de recréer l'équipement).
- [ ] **AC4** — L'horodatage de fraîcheur cesse d'avancer quand la communication avec un robot est
      coupée (robot éteint/hors-ligne, démon arrêté), et l'indicateur de connexion de ce robot bascule à
      « déconnecté » après le délai de garde, sans attendre une action utilisateur.
- [ ] **AC5** — Avec deux robots configurés dont un en défaut de communication, le suivi du second robot
      continue de fonctionner normalement (aucune interruption croisée).
- [ ] **AC6** — L'action de rafraîchissement immédiate (UC08) déclenche toujours une mise à jour visible
      après cette UC.
- [ ] **AC7** — Une valeur qui ne change pas entre deux cycles ne génère pas de nouvelle entrée dans
      l'historique de la commande correspondante.

## Impact i18n

- Chaînes anticipées : « Dernière mise à jour », « Connecté » / « Déconnecté » (déjà couvertes par le
  MVP le cas échéant — pas de nouveau libellé majeur attendu ici, cette UC est surtout comportementale).

## À confirmer

- Valeurs exactes des cadences (nettoyage vs repos) et du délai de garde avant bascule « déconnecté » :
  à trancher en spec technique, en s'appuyant sur les ordres de grandeur de
  `.memory/analyse/jeeroborock-mqtt-protocole.md` § 3 et `jeeroborock-architecture.md` D6 (30 s / 60 s
  côté rafraîchissement périodique).
- Comportement exact si le push et la boucle périodique désaccordent temporairement une même valeur
  (ordre d'arrivée réseau) : à vérifier à l'implémentation, pas un enjeu fonctionnel bloquant.

## Hors périmètre

- Le comportement en cas d'identifiants expirés, de quota atteint ou de robot durablement hors ligne :
  UC11.
- La création des commandes info elles-mêmes : déjà couverte par le MVP (UC06/UC07).
