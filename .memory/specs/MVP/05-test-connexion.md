# UC05 — Test de connexion et état du compte

> **Domaine** : MVP · **Statut** : à implémenter · **Dépend de** : UC04

## Objectif

Une fois le compte lié, l'utilisateur a besoin d'un retour rapide et fiable pour savoir si le lien
fonctionne réellement, sans attendre la découverte complète des équipements et **sans consommer** le
quota, très limité, des appels au cloud (cf. `jeeroborock-architecture.md` D6).

## Comportement attendu

Depuis la configuration du plugin, un bouton « tester la connexion » (ou équivalent) permet de vérifier
l'état du lien avec le compte Roborock. Le résultat affiché distingue clairement plusieurs états :
- **non configuré** : aucun e-mail renseigné ou aucune tentative de liaison effectuée ;
- **non authentifié** : liaison jamais faite ou explicitement rompue ;
- **authentifié** : le lien fonctionne, avec des informations minimales utiles (région/serveur du
  compte, nombre de robots détectés sur le compte) ;
- **ré-authentification requise** : le lien a existé mais n'est plus valide (session expirée côté
  Roborock) — l'utilisateur est invité à relancer l'UC04 (nouveau code par e-mail), sans tentative
  automatique.

Le test ne doit pas systématiquement déclencher un nouvel appel coûteux à l'inventaire des équipements
(`homedata`, quota 40/jour) : il s'appuie sur l'état déjà connu du démon (session active ou non) et, si
besoin d'aller plus loin, réutilise les données déjà en cache plutôt que de forcer un nouvel appel.

Si un quota (login ou inventaire) est déjà atteint au moment du test, un message explicite l'indique et
invite à patienter ; le plugin ne tente **jamais** automatiquement une nouvelle opération derrière ce
message.

## Critères d'acceptation

- [ ] **AC1** — Sur un plugin fraîchement installé (aucun e-mail renseigné), le test affiche l'état
      « non configuré ».
- [ ] **AC2** — Après un e-mail renseigné mais sans authentification réussie, le test affiche l'état
      « non authentifié ».
- [ ] **AC3** — Après une authentification réussie (UC04), le test affiche l'état « authentifié » avec
      au moins le nombre de robots détectés sur le compte.
- [ ] **AC4** — Le test de connexion, exécuté seul, n'entraîne pas de nouvel appel `homedata` si une
      donnée en cache suffit à répondre (vérifiable par le compteur d'appels visible côté démon/logs).
- [ ] **AC5** — Si la session n'est plus valide côté Roborock, le test affiche « ré-authentification
      requise » et n'enchaîne **pas** automatiquement une nouvelle tentative de login.
- [ ] **AC6** — Si un quota est déjà atteint, le message affiché le mentionne explicitement (pas un
      message d'erreur générique) et n'entraîne aucune nouvelle tentative automatique.

## Impact i18n

- Nouvelles chaînes UI anticipées : « Tester la connexion », « Non configuré », « Non authentifié »,
  « Authentifié — N robot(s) détecté(s) », « Ré-authentification requise », « Quota d'appels atteint,
  veuillez patienter ».

## À confirmer

- Durée de validité réelle d'une session Roborock (`rriot`), non documentée : le test se fie à l'échec
  effectif remonté par le démon plutôt qu'à une durée anticipée (cf. `jeeroborock-cloud-api.md` § 2.3).

## Hors périmètre

- La (re)création effective des équipements à partir de l'inventaire détecté : UC06.
- Toute action de pilotage sur un robot : UC07/UC08.
