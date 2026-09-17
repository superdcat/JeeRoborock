# UC13 — État de la station d'accueil

> **Domaine** : post-mvp/10-etats-detailles · **Statut** : à implémenter · **Dépend de** : UC07

## Objectif

Les stations d'accueil évoluées (vidage automatique de poussière, lavage et séchage de la serpillère)
ont leur propre cycle de vie et leurs propres incidents, indépendants de l'état du robot lui-même. Cette
UC rend cet état visible dans Jeedom, pour que l'utilisateur sache quand la station a besoin d'une
intervention (bac plein, manque d'eau) sans avoir à ouvrir l'application mobile.

## Comportement attendu

Pour une station dont les capacités sont détectées (type de station remonté par le robot), le plugin
expose l'état des opérations d'entretien qu'elle réalise : vidage de poussière en cours/terminé, lavage
de la serpillère en cours, séchage en cours, ainsi que les incidents propres à la station (erreur de
station, manque d'eau dans le réservoir). Une station basique (sans ces fonctions), ou un robot sans
station détectée, ne fait apparaître **aucune** de ces informations.

Les incidents de la station sont présentés séparément des erreurs du robot (UC14) : ce ne sont pas la
même chose pour l'utilisateur — une erreur de station appelle une action sur la base (vider, remplir
d'eau, débloquer un tiroir), une erreur robot appelle une action sur l'appareil (le débloquer, le
nettoyer). Un utilisateur consultant l'équipement doit pouvoir distinguer les deux sans ambiguïté.

## Critères d'acceptation

- [ ] **AC1** — Pour un robot dont la station supporte le vidage automatique, une information reflète
      l'état du vidage (en cours / terminé / au repos), visible séparément de l'état général du robot.
- [ ] **AC2** — Pour un robot dont la station supporte le lavage/séchage de la serpillère, des
      informations distinctes existent pour l'état de lavage et l'état de séchage.
- [ ] **AC3** — Pour un robot dont la station ne supporte aucune de ces fonctions (station basique),
      aucune commande d'entretien de station n'apparaît.
- [ ] **AC4** — Un incident propre à la station (ex. manque d'eau, erreur de station) est visible via une
      information dédiée, distincte de l'information d'erreur du robot (UC14) — un scénario peut réagir
      spécifiquement à l'un sans se déclencher sur l'autre.
- [ ] **AC5** — Après résolution d'un incident de station (ex. réservoir rempli), l'information repasse à
      l'état normal sans intervention manuelle sur le plugin.

## Impact i18n

- Nouvelles chaînes UI anticipées : « État vidage poussière », « État lavage serpillière », « État
  séchage serpillière », « Erreur station », « Manque d'eau », libellés d'état (« en cours », « terminé »,
  « au repos »).

## À confirmer

- Champs réellement alimentés sur le Qrevo Curv (`roborock.vacuum.a135`) parmi
  `dock_type`, `dock_error_status`, `dust_collection_status`, `wash_status`, `wash_phase`, `wash_ready`,
  `dry_status`, `water_shortage_status` — cf. `.memory/analyse/jeeroborock-mqtt-protocole.md` § 4 ; seul
  ce matériel est testable, les autres types de station se marquent non vérifiés.
- Table des codes d'erreur/état de station et leur libellé exact (dépend de la version de
  `python-roborock` embarquée, cf. § 4 même fichier — ne pas figer une liste en dur).
- Faut-il historiser ces états ou seulement les exposer en instantané (a priori non historisés par
  défaut, à trancher comme pour les erreurs en UC14).

## Hors périmètre

- Les consommables et leur usure (brosse, filtre…) : UC12.
- Les erreurs et notifications côté robot : UC14.
- Le déclenchement manuel des actions de vidage/lavage/séchage (pilotage) : couvert par le pilotage fin
  du domaine correspondant, pas par cette UC qui se limite à l'état constaté.
