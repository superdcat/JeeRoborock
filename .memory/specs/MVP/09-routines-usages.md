# UC09 — Routines (« usages ») : synchronisation et exécution

> **Domaine** : MVP · **Statut** : à implémenter · **Dépend de** : UC06

> ⚠️ **UC la plus importante du MVP** — c'est l'exigence explicite à l'origine du projet.

## Objectif

Rendre exécutables depuis Jeedom les routines de nettoyage (« usages ») que l'utilisateur a déjà
paramétrées dans l'application mobile Roborock (ex. « nettoyer le salon en mode silencieux »), sans
avoir à les reconstruire dans Jeedom.

## Comportement attendu

Pour chaque robot, une synchronisation des routines liste les usages définis dans l'application mobile
pour ce robot et crée, pour chacun, une commande d'action Jeedom dédiée portant le nom de la routine.
Déclencher cette commande exécute la routine correspondante côté Roborock.

Cette exécution passe par le cloud Roborock en HTTPS pur : elle **fonctionne même si le canal robot
habituel (MQTT) est momentanément indisponible**, puisque c'est le cloud qui relaie l'ordre au robot.

Le cycle de vie des routines est géré ainsi :
- une routine **renommée** dans l'application mobile, puis resynchronisée, met à jour le nom de la
  commande Jeedom correspondante (même commande, nouveau nom) ;
- une routine **supprimée** dans l'application mobile n'entraîne **pas** la suppression automatique et
  silencieuse de la commande Jeedom (elle peut être référencée dans un scénario) : la commande est
  marquée comme obsolète et toute tentative de l'exécuter échoue avec un message clair invitant à la
  supprimer manuellement ou à resynchroniser ;
- si aucune routine n'est définie pour un robot, l'absence de commande de routine n'est pas traitée
  comme une erreur : un message pédagogique invite l'utilisateur à créer d'abord un usage dans
  l'application Roborock.

## Critères d'acceptation

- [ ] **AC1** — Sur un robot ayant au moins une routine définie dans l'application mobile, une
      synchronisation crée une commande d'action Jeedom par routine, portant le nom exact de la routine.
- [ ] **AC2** — Déclencher cette commande exécute effectivement la routine sur le robot physique
      (constaté par le changement d'état du robot après exécution).
- [ ] **AC3** — Une routine renommée dans l'application mobile, après resynchronisation, met à jour le
      **nom** de la commande Jeedom existante sans en créer une nouvelle.
- [ ] **AC4** — Une routine supprimée dans l'application mobile, après resynchronisation, laisse la
      commande Jeedom en place mais marquée obsolète ; l'exécuter renvoie un message d'erreur explicite
      plutôt qu'un échec muet ou une exécution fantôme.
- [ ] **AC5** — Une deuxième synchronisation sans changement côté application mobile ne duplique
      **aucune** commande de routine.
- [ ] **AC6** — Sur un robot sans aucune routine définie, la synchronisation affiche un message
      pédagogique invitant à créer un usage dans l'application Roborock (pas un message d'erreur).
- [ ] **AC7** — L'exécution d'une routine reste possible même quand une action de pilotage direct
      (UC08) échoue faute de canal robot disponible, illustrant l'indépendance de ce chemin HTTPS.

## Impact i18n

- Nouvelles chaînes UI anticipées : « Synchroniser les usages », « Aucun usage défini pour ce robot,
  créez-en un dans l'application Roborock », « Cet usage a été supprimé côté application, veuillez le
  supprimer ou resynchroniser », « Usage introuvable ou obsolète ».

## À confirmer

- Contenu réel d'une routine au-delà de `{id, name}` (paramètres, pièces ciblées, mode) — la librairie
  n'expose que ces deux champs (cf. `jeeroborock-cloud-api.md` § 5) ; à vérifier sur le compte de test
  si un enrichissement du libellé est possible sans passer par la réponse brute non garantie.
- Fréquence raisonnable de resynchronisation des routines (pas de limiteur dédié documenté côté cloud) :
  à cadrer en spec technique pour éviter une rafale d'appels.
- Comportement exact en cas d'exécution d'une routine obsolète : message d'erreur retourné par le cloud
  (probable "scene not found") vs détection préalable côté plugin — à vérifier en recette.

## Hors périmètre

- La commande générique paramétrée `routine_executer` (subType `message`) : alternative post-MVP.
- La modification du contenu d'une routine depuis Jeedom (non exposée par l'API) : hors périmètre du
  plugin, quelle que soit la version.
