# UC02 — Dépendances Python et démon

> **Domaine** : MVP · **Statut** : à implémenter · **Dépend de** : UC01

## Objectif

Le plugin s'appuie sur un démon Python adossé à la librairie `python-roborock` pour tout dialogue avec
le cloud Roborock (cf. `.memory/analyse/jeeroborock-architecture.md` D1/D2/D7). Cette UC rend ce démon
installable et démarrable de façon fiable, **avant même** toute authentification : un démon « non
authentifié » est un état normal, pas une panne.

## Comportement attendu

À l'installation ou l'activation du plugin, Jeedom installe la dépendance Python déclarée
(`python-roborock`) dans l'environnement dédié du plugin. Le démon démarre ensuite automatiquement
(cycle de vie standard Jeedom : activation du plugin, redémarrage de Jeedom, changement de
configuration impactant le démon) et reste opérationnel même si aucun compte Roborock n'est encore lié.

L'utilisateur dispose, dans l'interface standard Jeedom de gestion des dépendances/démons, d'un état
clair : dépendance installée ou non, démon lancé ou non. Un démon qui ne peut pas démarrer (dépendance
manquante, port local indisponible) l'indique de façon lisible plutôt que de rester silencieusement
inactif.

Au démarrage, le démon consigne dans les logs du plugin la version de `python-roborock` réellement
détectée, et signale un avertissement explicite si sa version majeure dépasse celle validée par le
plugin (le contrat de la librairie n'étant pas garanti stable au fil de ses évolutions).

## Critères d'acceptation

- [ ] **AC1** — Après activation du plugin sur une machine sans la dépendance installée, l'indicateur
      Jeedom de dépendance passe à « installée » sans intervention manuelle, dans le délai annoncé par
      le plugin.
- [ ] **AC2** — Après installation de la dépendance, le démon démarre et son état apparaît « actif »
      dans l'interface Jeedom, **sans qu'aucun compte Roborock ne soit configuré**.
- [ ] **AC3** — Un arrêt/redémarrage du démon depuis Jeedom (bouton standard) fonctionne et restaure
      l'état « actif ».
- [ ] **AC4** — Les logs du plugin contiennent, à chaque démarrage du démon, la version de
      `python-roborock` effectivement chargée.
- [ ] **AC5** — Si cette version a une majeure supérieure à celle validée par le plugin, un message
      d'avertissement explicite apparaît dans les logs (pas une erreur bloquante).
- [ ] **AC6** — Si le démon ne peut pas démarrer (ex. port local déjà occupé), l'état « inactif »/« en
      erreur » est visible dans Jeedom avec une cause lisible, pas un silence.

## Impact i18n

- Nouvelles chaînes UI anticipées : messages d'état du démon standard Jeedom (peu de chaînes propres au
  plugin sur cette UC, hors messages de log qui ne sont pas traduits).

## À confirmer

- Borne `os.max` / matrice de compatibilité exacte selon la version de Jeedom courante au moment du
  développement (cf. `jeeroborock-architecture.md` D7/D8).

## Hors périmètre

- Le dialogue applicatif avec le démon (requêtes/réponses) : UC03.
- L'authentification au compte Roborock : UC04.
