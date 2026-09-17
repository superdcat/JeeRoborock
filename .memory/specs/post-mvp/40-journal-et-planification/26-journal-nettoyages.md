# UC26 — Journal des nettoyages

> **Domaine** : post-mvp/40-journal-et-planification · **Statut** : à implémenter · **Dépend de** : UC07

## Objectif

L'utilisateur veut savoir, sans ouvrir l'application Roborock, **quand le robot a fait son dernier
nettoyage**, combien de temps ça a pris, quelle surface a été couverte et si tout s'est bien passé. Cette
information est la plus consultée au quotidien (dashboard, scénario « le robot n'est pas passé depuis
trois jours ») et doit rester disponible **sans dérouler tout l'historique**.

## Comportement attendu

Le plugin expose les informations du **dernier nettoyage terminé** d'un robot : date/heure de début, date
/heure de fin (ou durée), surface nettoyée, motif de fin (terminé normalement, interrompu, en erreur) et,
le cas échéant, le libellé de l'erreur associée.

En complément, un historique récent (un nombre limité d'enregistrements, pas l'intégralité de la vie du
robot) reste consultable, avec les mêmes informations par enregistrement.

La récupération de ce journal auprès du cloud se fait en respectant le coût de l'opération : un premier
appel donne un résumé (liste des identifiants d'enregistrements récents), un second appel, **espacé dans
le temps**, détaille un enregistrement particulier. Le plugin ne redemande **pas** l'historique complet à
chaque démarrage du démon — seules les données nécessaires à couvrir ce qui a pu se passer pendant une
coupure sont récupérées, et seulement la première fois ou lors d'un rafraîchissement explicite.

Cas dégradés attendus :
- **Aucun nettoyage jamais effectué** : le dernier nettoyage et l'historique s'affichent vides, sans
  erreur ni valeur incohérente (pas de « 0 » trompeur affiché comme une vraie donnée).
- **Nettoyage interrompu ou en erreur** : le motif de fin et l'erreur associée doivent être visibles
  distinctement d'un nettoyage terminé normalement.
- **Cloud indisponible / quota atteint au moment de la consultation** : la dernière valeur connue reste
  affichée (pas de remise à vide), avec une indication de fraîcheur, plutôt qu'un blocage de l'interface.

## Critères d'acceptation

- [ ] **AC1** — Après un nettoyage réel effectué avec le robot de test, les informations du « dernier
      nettoyage » (début, fin, durée, surface) reflètent ce qui a été observé physiquement (± marge
      d'arrondi documentée).
- [ ] **AC2** — Un nettoyage interrompu manuellement (arrêt avant la fin) fait apparaître un motif de fin
      distinct d'un nettoyage mené à son terme, visible sans avoir à consulter l'application Roborock.
- [ ] **AC3** — Sur un robot n'ayant jamais nettoyé (ou juste après une (re)création d'équipement), le
      dernier nettoyage s'affiche à l'état neutre « aucun nettoyage connu », pas en erreur.
- [ ] **AC4** — Un redémarrage du démon (arrêt/relance) ne déclenche pas de récupération complète de
      l'historique : seul ce qui est nécessaire pour rattraper une éventuelle coupure est demandé
      (vérifiable via le compteur/journal d'appels du démon).
- [ ] **AC5** — L'historique récent affiche au maximum un nombre borné d'enregistrements (pas la totalité
      de la vie du robot), chacun avec au minimum date, durée, surface et motif de fin.
- [ ] **AC6** — Si le cloud est injoignable au moment de la consultation, la dernière valeur connue du
      « dernier nettoyage » reste affichée (pas de remise à vide ni de message d'erreur bloquant), avec une
      indication que la donnée peut ne plus être à jour.

## Impact i18n

- Nouvelles chaînes UI anticipées : « Dernier nettoyage », « Début », « Fin », « Durée », « Surface
  nettoyée », « Motif de fin », « Terminé », « Interrompu », « En erreur », « Aucun nettoyage connu »,
  « Historique des nettoyages », « Donnée non à jour (cloud indisponible) ».

## À confirmer

- Nom exact et forme du résumé/détail d'enregistrement de nettoyage renvoyés par le cloud pour un robot
  V1 (`a135`) : la lib `python-roborock` n'est pas détaillée à ce sujet dans les analyses internes
  actuelles ; à vérifier en recette sur le Qrevo Curv avant l'implémentation technique
  (cf. `.memory/analyse/jeeroborock-implementations-reference.md` pour la méthode de vérification).
- Nombre exact d'enregistrements d'historique raisonnable à conserver/afficher (borne à fixer en fonction
  du coût réel constaté par appel).
- Liste exhaustive des motifs de fin possibles (terminé, interrompu manuellement, erreur, robot bloqué…)
  à confirmer contre les valeurs réellement observées sur le robot de test.

## Hors périmètre

- Les commandes d'information de nettoyage en cours (état, surface/durée du cycle courant) : couvertes par
  UC07 (MVP).
- Les statistiques cumulées (totaux tous nettoyages confondus) : UC27.
- Toute action de suppression/purge de l'historique côté cloud : non traitée (l'historique reste géré par
  l'application Roborock).
