# UC14 — Erreurs détaillées et notifications

> **Domaine** : post-mvp/10-etats-detailles · **Statut** : à implémenter · **Dépend de** : UC07

## Objectif

L'UC07 (MVP) expose déjà un code et un libellé d'erreur robot bruts. Cette UC va plus loin : elle rend
l'erreur **exploitable en scénario Jeedom** (détection du passage en erreur sans polling manuel de
l'utilisateur) et distingue une erreur **active** d'une erreur **acquittée**, pour que l'historique de
l'équipement ne reste pas bloqué en apparence sur un incident déjà résolu.

## Comportement attendu

Quand le robot signale une erreur, l'utilisateur dispose d'un libellé compréhensible (« robot coincé »,
« bac à poussière plein »…) sans avoir à retenir un code numérique. Ce libellé est celui que produit la
librairie sous-jacente au moment considéré : il n'est pas rédigé ou traduit en dur pour un sous-ensemble
figé de codes qui deviendrait obsolète à la moindre mise à jour.

Le passage du robot en état d'erreur est détectable par un scénario Jeedom sans que l'utilisateur ait à
consulter l'équipement à intervalle régulier (ex. via un changement d'une information dédiée). Une fois
l'incident résolu (robot relancé, obstacle retiré), l'état d'erreur redevient « aucune erreur » de façon
autonome, sans action de l'utilisateur sur le plugin.

L'historique de l'équipement ne s'encombre pas d'un enregistrement à chaque cycle où l'état brut est
simplement recopié : seul un **changement réel** d'état d'erreur (apparition, disparition) laisse une
trace, pas chaque lecture périodique.

## Critères d'acceptation

- [ ] **AC1** — Quand le robot signale une erreur, une information libellée en clair est mise à jour avec
      le message correspondant (pas seulement un code numérique).
- [ ] **AC2** — Un scénario Jeedom déclenché sur « changement » de l'information d'erreur se déclenche au
      moment où l'erreur apparaît, sans polling actif côté utilisateur.
- [ ] **AC3** — Une fois l'incident résolu côté robot, l'information d'erreur revient d'elle-même à l'état
      « aucune erreur », sans manipulation dans Jeedom.
- [ ] **AC4** — Consulter l'historique de l'information d'erreur sur une période où le robot n'a eu aucun
      incident ne montre aucune succession d'enregistrements identiques (pas de bruit à chaque tick).
- [ ] **AC5** — Un code d'erreur inconnu du plugin (nouveauté de la librairie non encore vue) n'interrompt
      pas la remontée d'état : un libellé — au minimum le code brut — reste affiché plutôt qu'une valeur
      vide ou une erreur PHP.

## Impact i18n

- Nouvelles chaînes UI anticipées : libellés d'erreur traduits (liste dépendante de la librairie, à
  compléter au fil des erreurs rencontrées), « Aucune erreur », état « acquittée » si retenu à
  l'implémentation.

## À confirmer

- Mécanisme exact de distinction erreur active / acquittée : le protocole ne semble exposer qu'un état
  courant (`error_code` remis à 0 côté robot) — à confirmer si un « acquittement » applicatif a un sens
  ici ou si l'UC se limite à « erreur en cours » vs « aucune erreur », cf.
  `.memory/analyse/jeeroborock-mqtt-protocole.md` § 4 (pas de notion d'acquittement identifiée côté
  librairie à ce stade).
- Table de correspondance code → libellé : fournie par le démon (librairie `python-roborock`), traduite
  côté plugin — le mécanisme précis de propagation code+libellé du démon vers Jeedom est à trancher en
  spec technique, pas ici.
- Politique d'historisation exacte de l'information d'erreur (changement uniquement, cf. comportement
  attendu) à confirmer contre les capacités du cron/push du démon (`jeeroborock-mqtt-protocole.md` § 3).

## Hors périmètre

- Le code/libellé d'erreur basique déjà exposé au MVP (UC07) : cette UC l'enrichit, ne le remplace pas.
- Les erreurs propres à la station d'accueil (bac plein, manque d'eau) : UC13, notion distincte.
- L'envoi de notifications Jeedom (push, email) : relève des scénarios de l'utilisateur, pas du plugin.
