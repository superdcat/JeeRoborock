# UC16 — Pièces nommées et segments

> **Domaine** : post-MVP / carte et pièces · **Statut** : à implémenter · **Dépend de** : UC06 (MVP —
> découverte/création des équipements)

## Objectif

Le nettoyage ciblé par pièce (UC24, hors périmètre ici) suppose de savoir, pour chaque robot, quel
**segment interne** correspond à quelle **pièce nommée par l'utilisateur** dans l'app Roborock. Cette UC
construit et entretient cette correspondance : c'est le socle indispensable, sans valeur d'usage autonome
côté utilisateur final au-delà de la visibilité qu'elle apporte.

## Comportement attendu

- Pour chaque robot configuré et disposant d'au moins une carte, le plugin établit la correspondance entre
  les segments connus du robot et les noms de pièces définis par l'utilisateur dans son compte Roborock.
- Le nom de la pièce est celui du **compte** (défini dans l'app), pas un nom générique déduit du robot :
  si l'utilisateur a renommé une pièce dans l'app, c'est ce nom qui doit apparaître côté Jeedom après
  resynchronisation.
- Le cas d'un robot **partagé** par un autre compte est traité distinctement : ses pièces s'obtiennent par
  un chemin propre, différent de celui d'un robot possédé en direct. Le résultat perçu par l'utilisateur
  (liste de pièces nommées) doit être équivalent dans les deux cas ; en cas d'impossibilité de résoudre les
  noms pour un robot partagé (droits insuffisants côté cloud), le plugin se rabat sur une identification
  neutre du segment (numéro) plutôt que d'échouer entièrement.
- La correspondance est **resynchronisée** : automatiquement à un moment structurant (ex. découverte/mise à
  jour de l'équipement, changement de carte détecté — UC17), et via une action explicite déclenchable par
  l'utilisateur en cas de doute (pièce ajoutée/renommée dans l'app sans que Jeedom ne l'ait encore vue).
- Si de **nouveaux segments** apparaissent (nouvelle pièce cartographiée, remaniement de la carte), ils
  sont intégrés à la resynchronisation suivante sans qu'il soit nécessaire de recréer l'équipement.
- Si une pièce est **renommée** dans l'app, la resynchronisation met à jour le libellé affiché côté Jeedom
  **sans casser** les références déjà utilisées ailleurs dans le plugin (ex. une commande ou une routine
  qui cible cette pièce doit continuer à cibler le même segment physique après renommage, seul le libellé
  change).
- Si aucune carte n'a encore été construite par le robot (jamais cartographié), la correspondance est vide
  et signalée comme telle, sans erreur bloquante.

## Critères d'acceptation

- [ ] **AC1** — Sur le robot de test (Qrevo Curv, carte déjà construite), après synchronisation, la liste
      des pièces affichées côté Jeedom correspond nom pour nom aux pièces visibles dans l'app Roborock pour
      ce même robot.
- [ ] **AC2** — Une pièce renommée dans l'app Roborock, puis une resynchronisation déclenchée côté Jeedom :
      le nouveau nom apparaît côté Jeedom, sans doublon et sans perte de la pièce (toujours le même nombre
      de pièces qu'avant renommage).
- [ ] **AC3** — Robot n'ayant jamais cartographié (ou carte tout juste réinitialisée) : la synchronisation
      des pièces se termine sans erreur visible par l'utilisateur, avec une liste vide plutôt qu'un blocage.
- [ ] **AC4** — Une resynchronisation manuelle est disponible et déclenchable par l'utilisateur, et son
      exécution est constatable (la liste de pièces se met à jour si un changement a eu lieu côté compte).
- [ ] **AC5** — Sur un robot identifié comme partagé (si disponible en recette), la liste de pièces est
      obtenue et affichée selon le même contrat visible côté utilisateur qu'un robot possédé en direct, ou
      à défaut une identification neutre par segment sans échec de la synchronisation. *(Non vérifiable sur
      le matériel de test, cf. « À confirmer ».)*

## Impact i18n

- Chaînes anticipées : « Pièces », « Segment », « Resynchroniser les pièces », « Aucune pièce détectée »,
  « Pièce inconnue » (repli neutre par numéro de segment).

## À confirmer

- Le comportement exact pour un robot **partagé** (endpoint distinct, cf.
  `.memory/analyse/jeeroborock-cloud-api.md` § 7 — `/user/deviceshare/query/<duid>/rooms`) n'est pas
  vérifiable avec le seul matériel de test (Qrevo Curv en compte direct) : à valider en recette si un
  robot partagé devient disponible.
- Le moment exact et la fréquence de la resynchronisation automatique (à chaque connexion démon ? à
  intervalle ? uniquement sur détection de changement de carte ?) est à trancher en spec technique,
  cf. `.memory/analyse/jeeroborock-mqtt-protocole.md` § 7 (`get_room_mapping`).
- Représentation exacte d'une pièce dont le nom cloud est introuvable (segment orphelin) : repli par
  numéro proposé ici à titre d'hypothèse fonctionnelle, à confirmer à l'implémentation.

## Hors périmètre

- L'utilisation de cette correspondance pour lancer un nettoyage ciblé par pièce : UC24 (hors du domaine
  « carte et pièces », traité dans le domaine pilotage fin).
- La gestion des cartes multiples (une pièce peut exister sur plusieurs cartes) : UC17.
- L'affichage visuel de la carte avec les pièces : UC19.
