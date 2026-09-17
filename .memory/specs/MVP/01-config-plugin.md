# UC01 — Configuration du plugin

> **Domaine** : MVP · **Statut** : à implémenter · **Dépend de** : —

## Objectif

Avant de pouvoir lier un compte Roborock, l'utilisateur doit disposer d'une page de configuration
plugin où saisir son adresse e-mail, ajuster le canal technique local avec le démon et régler le niveau
de journalisation. Cette UC pose le socle de configuration ; elle ne déclenche **aucun** appel au cloud
Roborock (c'est l'UC04).

## Comportement attendu

La page de configuration du plugin JeeRoborock propose :
- un champ **e-mail** du compte Roborock (texte simple, non chiffré) ;
- un champ **port du canal HTTP local** utilisé pour dialoguer avec le démon, pré-rempli avec une
  valeur par défaut cohérente et non déjà utilisée par un autre plugin/service connu de Jeedom ;
- un sélecteur de **niveau de log** (aligné sur les niveaux `log::add` du plugin : debug / info /
  warning / error) ;
- **aucun champ mot de passe** n'est proposé sur cette page (décision de cadrage : seule
  l'authentification par code e-mail est supportée au MVP, cf. UC04).

Toute valeur saisie est enregistrée via le mécanisme standard de configuration plugin Jeedom. Les
champs sont immédiatement pris en compte par le démon au redémarrage suivant (le redémarrage lui-même
est traité en UC02).

Si le port choisi entre en conflit avec un port déjà occupé sur la machine, l'utilisateur doit pouvoir
s'en rendre compte de façon lisible (message d'erreur explicite lors du démarrage du démon ou du test
de connexion), plutôt que de rencontrer un échec silencieux ou une erreur technique brute.

## Critères d'acceptation

- [ ] **AC1** — La page de configuration du plugin affiche un champ e-mail, un champ port du canal
      local (avec une valeur par défaut renseignée) et un sélecteur de niveau de log ; aucun champ mot
      de passe n'est présent.
- [ ] **AC2** — Une valeur saisie dans chacun de ces champs est conservée après un rafraîchissement de
      la page (persistée en configuration plugin).
- [ ] **AC3** — La configuration expose une clé destinée à recevoir le `UserData` obtenu après
      authentification (UC04), déclarée parmi les clés **chiffrées** du plugin ; cette clé n'apparaît en
      clair dans aucune page, log ou réponse AJAX.
- [ ] **AC4** — Si le port configuré est déjà occupé, une tentative de démarrage/test affiche un message
      compréhensible mentionnant le conflit de port, pas une erreur technique brute ni un blocage
      silencieux.
- [ ] **AC5** — Toutes les chaînes visibles de la page sont en français et passibles d'un enveloppage
      i18n (`{{...}}` / `__()`).

## Impact i18n

- Nouvelles chaînes UI anticipées : « E-mail du compte Roborock », « Port du canal local », « Niveau de
  journalisation », « Ce port est déjà utilisé, veuillez en choisir un autre ».

## À confirmer

- Valeur par défaut exacte du port local (cf. `jeeroborock-architecture.md` D3) : à trancher en spec
  technique, hors plage des ports Jeedom/démons connus.

## Hors périmètre

- L'authentification proprement dite (envoi/validation du code e-mail) : UC04.
- Le démarrage effectif du démon et la déclaration des dépendances Python : UC02.
