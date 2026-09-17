# UC28 — Lecture des programmations de l'application

> **Domaine** : post-mvp/40-journal-et-planification · **Statut** : à implémenter · **Dépend de** : UC09

## Objectif

Certains utilisateurs planifient déjà des nettoyages automatiques directement dans l'application
Roborock (jours/heures récurrents). Sans dupliquer cette fonction dans Jeedom — ce qui créerait un risque
de désynchronisation entre deux sources de vérité — le plugin permet de **consulter** ces programmations
depuis Jeedom, pour information et pour construire des scénarios qui en tiennent compte (ex. ne pas
déclencher un nettoyage manuel juste avant une programmation existante).

## Comportement attendu

Le plugin affiche, pour un robot donné, la liste des programmations définies dans l'application Roborock :
horaires prévus (récurrence) et état d'activation de chacune (active/désactivée). Cette lecture est
**seule** : aucune création, modification ou suppression de programmation n'est proposée depuis Jeedom à
ce stade — toute programmation continue de se gérer exclusivement dans l'application Roborock.

Cas dégradés attendus :
- **Aucune programmation définie** dans l'app : affichage neutre (« aucune programmation définie »), pas
  une erreur ni un état vide ambigu.
- **Le cloud ne renvoie aucune donnée exploitable pour ce modèle de robot** (⚠️ voir « À confirmer » — rien
  ne garantit que l'endpoint réponde utilement pour un robot V1 comme le Qrevo Curv) : ce cas doit être
  traité comme un **résultat normal et documenté** (« programmations non disponibles pour ce robot »), et
  non comme un échec technique remonté à l'utilisateur sous forme d'erreur.
- **Programmation modifiée dans l'app entre deux consultations** : un rafraîchissement fait apparaître
  l'état à jour ; le plugin ne conserve pas silencieusement un affichage périmé indéfiniment.

## Critères d'acceptation

- [ ] **AC1** — Si des programmations existent dans l'application Roborock pour le robot de test, elles
      apparaissent côté Jeedom avec, a minima, leur horaire/récurrence et leur état actif/inactif.
- [ ] **AC2** — Si aucune programmation n'est définie dans l'application, Jeedom affiche un état neutre
      « aucune programmation définie », distinct d'un état d'erreur.
- [ ] **AC3** — Si le cloud ne renvoie aucune donnée exploitable pour le robot de test (endpoint vide ou
      non supporté), le plugin l'indique comme « programmations non disponibles pour ce robot » et ne
      remonte **pas** d'erreur technique visible à l'utilisateur, ni de blocage du reste du fonctionnement
      du robot.
- [ ] **AC4** — Aucune action de création/modification/suppression de programmation n'est proposée depuis
      l'interface Jeedom du plugin.
- [ ] **AC5** — Après désactivation d'une programmation depuis l'application Roborock puis rafraîchissement
      côté Jeedom, l'état affiché reflète la désactivation.

## Impact i18n

- Nouvelles chaînes UI anticipées : « Programmations de l'application », « Aucune programmation définie »,
  « Programmations non disponibles pour ce robot », « Active », « Désactivée ».

## À confirmer

- ⚠️ **Point majeur** : l'endpoint des programmations (`jobs`) n'est documenté comme fiable que pour les
  modèles **B01** côté création dans `python-roborock` ; rien ne garantit qu'il renvoie une donnée
  exploitable en lecture pour un robot **V1** comme le Qrevo Curv (`pv = "1.0"`) — cf.
  `.memory/analyse/jeeroborock-cloud-api.md` § 6. Cette UC doit être vérifiée en recette sur le matériel de
  test avant de considérer le comportement « endpoint vide » comme définitivement acquis pour toute la
  gamme V1, ou comme spécifique au Qrevo Curv.
- Forme exacte de la récurrence renvoyée (cron, jours de semaine, heure) et son unité — à confirmer contre
  la réponse réelle si l'endpoint s'avère exploitable sur le robot de test.
- Fréquence de rafraîchissement raisonnable de cette lecture (pas de contrainte de quota connue et
  spécifique à cet endpoint, mais un garde-fou anti-rafale reste de mise, cf. principe déjà retenu pour les
  routines en UC09).

## Hors périmètre

- Toute création, modification ou suppression de programmation depuis Jeedom (réservée à l'application
  Roborock) — pourrait faire l'objet d'une UC ultérieure si le contrat cloud s'avère exploitable de façon
  fiable pour le parc de robots supportés.
- Les routines/« usages » exécutables à la demande : couvertes par UC09 (MVP), mécanisme distinct des
  programmations horaires de l'app.
