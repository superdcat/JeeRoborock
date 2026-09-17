# UC22 — Itinéraire de serpillère et mode de nettoyage

> **Domaine** : post-mvp/30-pilotage-fin · **Statut** : à implémenter · **Dépend de** : UC21

## Objectif

Au-delà des réglages fins pris isolément (UC20, UC21), l'utilisateur veut souvent raisonner en termes
simples : « aspirer seulement », « laver seulement », « aspirer puis laver ». Cette UC introduit un
réglage d'**itinéraire de serpillère** (passages standard / en profondeur) et un **mode de nettoyage**
haut niveau qui applique en une seule action une combinaison cohérente de réglages moteur, sans que
l'utilisateur ait à connaître le détail technique sous-jacent.

## Comportement attendu

- Une information et une action permettent de consulter et régler l'**itinéraire de serpillère** (par
  exemple standard, profond, profond+), parmi les valeurs réellement supportées par le robot connecté —
  options dynamiques, jamais de liste en dur. Cette commande n'apparaît pas sur un robot sans fonction de
  lavage.
- Une information et une action permettent de consulter et régler le **mode de nettoyage haut niveau**
  (aspiration seule, lavage seul, aspiration et lavage), également limité aux modes réellement supportés
  par le robot connecté.
- Choisir un mode de nettoyage haut niveau applique automatiquement un jeu cohérent de réglages fins
  (puissance d'aspiration, débit d'eau, itinéraire) : l'utilisateur ne peut pas se retrouver, via cette
  action, dans une combinaison incohérente ou refusée par le robot (ex. « lavage seul » avec un débit
  d'eau à zéro).
- Un mode de nettoyage non supporté par le modèle connecté (ex. absence de bac à eau → pas de « lavage
  seul ») n'est jamais proposé à l'utilisateur.
- Après application d'un mode de nettoyage ou d'un itinéraire, les informations de puissance d'aspiration,
  débit d'eau et itinéraire (UC20, UC21, et l'itinéraire de cette UC) sont relues pour refléter ce que le
  robot a réellement adopté, y compris si le robot a lui-même ajusté un détail du réglage.
- Les deux réglages (itinéraire, mode de nettoyage) sont utilisables en scénario.

## Critères d'acceptation

- [ ] **AC1** — Sur le robot testé (fonction de lavage disponible), une information affiche l'itinéraire
      de serpillère courant, et une action permet de le changer parmi les valeurs supportées.
- [ ] **AC2** — Une information affiche le mode de nettoyage haut niveau courant (aspiration seule /
      lavage seul / aspiration + lavage, ou sous-ensemble supporté), et une action permet de le changer.
- [ ] **AC3** — Après sélection du mode « aspiration + lavage », les informations de puissance
      d'aspiration et de débit d'eau (UC20, UC21) affichent des valeurs non nulles et cohérentes entre
      elles (pas de débit d'eau à zéro combiné avec un mode incluant le lavage).
- [ ] **AC4** — Sur un robot sans fonction de lavage, seul le mode « aspiration seule » est disponible (ou
      la commande de mode de nettoyage n'apparaît simplement pas si elle n'a pas de sens sans choix
      possible) — aucun mode incluant le lavage n'est proposé.
- [ ] **AC5** — Les réglages d'itinéraire et de mode de nettoyage sont déclenchables depuis un scénario
      Jeedom.
- [ ] **AC6** — Un mode de nettoyage refusé ou ajusté par le robot se traduit par des informations
      (puissance, débit, itinéraire) reflétant la réalité constatée après application, pas la valeur
      demandée si elle diffère.

## Impact i18n

- Nouvelles chaînes UI anticipées : « Itinéraire de serpillère », libellés (« Standard », « Profond »,
  « Profond+ »), « Mode de nettoyage », libellés (« Aspiration seule », « Lavage seul », « Aspiration et
  lavage »).

## À confirmer

- Présets de mode de nettoyage effectivement exposés par la librairie pour l'`a135` (cf.
  `.memory/analyse/jeeroborock-mqtt-protocole.md` § 4 : présets Vacuum/Mop/Vac & Mop) et leur
  correspondance exacte en puissance/débit/itinéraire sur le matériel de test.
- Comportement en cas de mode de nettoyage sélectionné pendant un cycle déjà en cours (application
  immédiate ou seulement au prochain démarrage) : à vérifier en recette.

## Hors périmètre

- Le réglage indépendant de la puissance d'aspiration et du débit d'eau : UC20, UC21 (cette UC les combine
  mais ne remplace pas leur réglage individuel).
- Le nettoyage ciblé par pièce ou par zone, qui peut lui-même préciser un mode de nettoyage au lancement :
  UC24, UC25.
