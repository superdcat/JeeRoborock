# UC04 — Authentification au cloud Roborock

> **Domaine** : MVP · **Statut** : à implémenter · **Dépend de** : UC03

## Objectif

Pour que le plugin puisse agir sur les robots, l'utilisateur doit lier son compte Roborock. Le seul
chemin retenu est le **code reçu par e-mail** (décision de cadrage : pas de mot de passe, cf.
`jeeroborock-architecture.md` D4) — le compte Roborock n'a pas forcément de mot de passe et le flux
mot de passe est fragile côté librairie.

## Comportement attendu

Depuis la page de configuration du plugin (e-mail déjà renseigné en UC01), l'utilisateur déclenche
l'envoi d'un code de connexion. Un e-mail contenant un code lui parvient (côté Roborock). Il saisit ce
code dans le plugin et valide.

En cas de succès, le plugin mémorise que le compte est lié : les tentatives suivantes de test de
connexion ou de découverte (UC05, UC06) peuvent s'appuyer sur ce lien sans redemander de code. Les
informations d'accès obtenues sont conservées de façon chiffrée et ne sont jamais réaffichées ni
renvoyées en clair.

En cas d'échec, l'utilisateur voit une cause précise et exploitable :
- code invalide ou expiré → peut redemander un code ;
- trop de demandes de code sur une courte période → message invitant à patienter, sans nouvelle
  tentative automatique ;
- conditions d'utilisation à (ré)accepter → message explicite renvoyant à l'application mobile
  officielle ;
- compte inexistant pour cet e-mail → message explicite.

Les deux étapes (demande de code, validation du code) sont traitées par le même processus démon, de
manière à rester cohérentes vis-à-vis du serveur Roborock (cf. `jeeroborock-architecture.md` D3 —
l'identifiant de session client ne doit pas changer entre les deux étapes).

## Critères d'acceptation

- [ ] **AC1** — Après avoir cliqué « envoyer un code » avec un e-mail valide, l'utilisateur reçoit un
      accusé (« code envoyé ») côté plugin, cohérent avec la réception effective d'un e-mail Roborock.
- [ ] **AC2** — La saisie du bon code aboutit à un état « compte lié » visible dans la configuration du
      plugin, sans redemander l'e-mail.
- [ ] **AC3** — La saisie d'un code invalide affiche un message distinct (« code invalide ») et permet
      de redemander un code, sans nécessiter de recharger la page.
- [ ] **AC4** — Une succession rapide de demandes de code affiche un message de quota dépassé invitant à
      patienter ; le plugin ne relance **pas** de tentative automatique derrière ce message.
- [ ] **AC5** — Une fois le compte lié, aucune information sensible (code, jeton, secret de compte)
      n'apparaît dans la page de configuration, dans le DOM ou dans les logs.
- [ ] **AC6** — L'information d'accès obtenue est conservée de façon chiffrée en configuration plugin
      (vérifiable : la valeur brute en base n'est pas lisible en clair).

## Impact i18n

- Nouvelles chaînes UI anticipées : « Envoyer un code », « Code de connexion », « Valider le code »,
  « Code envoyé, vérifiez vos e-mails », « Code invalide ou expiré », « Trop de demandes, veuillez
  patienter avant de réessayer », « Conditions d'utilisation à accepter dans l'application Roborock »,
  « Aucun compte trouvé pour cet e-mail ».

## À confirmer

- Comportement exact si les CGU doivent être réacceptées (compte Mi Home) : le message renvoie à
  l'application mobile officielle faute d'endpoint dédié côté plugin (cf.
  `jeeroborock-cloud-api.md` § 2.1) — à valider en recette si un compte de test présente ce cas.

## Hors périmètre

- Le test de l'état du lien après authentification (nombre de robots détectés, etc.) : UC05.
- Le flux mot de passe (non retenu au MVP).
- Le rafraîchissement automatique d'une session expirée : impossible par construction (code e-mail
  requis) ; la ré-authentification reste une action manuelle de l'utilisateur.
