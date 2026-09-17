---
name: dev
description: Applique un workflow de Development. Active-toi quand l'utilisateur demande d'appliquer la skill dev, tu réalises l'implémentation jusqu'à ce qu'on soit au niveau attendu.
---

# Skill — DEV Workflow

Implémentation d'une feature de **plugin Jeedom** : coder une feature **à partir de sa spec technique
déjà validée**, **pilotée par les critères d'acceptation de sa spec fonctionnelle**, jusqu'à ce qu'ils
soient tous verts et que la checklist qualité passe.

## Quand t'activer
- L'agent **`php-jeedom-dev`** t'invoque pour implémenter une spec technique (cas nominal, via `/feature`).
- L'utilisateur demande explicitement la skill DEV sur une feature.

## Chargement de contexte (à faire — ne suppose rien d'« déjà chargé »)

Tu tournes en contexte neuf (sous-agent). Charge, dans l'ordre :

1. **`CLAUDE.md`** (racine) si pas déjà en contexte — conventions, architecture, i18n, secrets, pièges
   Jeedom. Tu l'appliques, tu ne le redécris pas.
2. **La spec technique `<nom>-tech.md`** (`.memory/specs/**/`) — ton **plan d'implémentation** :
   architecture, fichiers à créer/modifier, décision server/client, signatures/actions AJAX, dépendances.
   Tu la suis fidèlement ; tout écart est justifié et remonté.
3. **La spec fonctionnelle `<nom>.md`** — ses **Critères d'acceptation** = la *definition of done*.

**Doute sur un contrat API/core en cours de code → consultation à la demande** (pas « par sécurité »), en
t'arrêtant dès que tu as la réponse :
- **Interne d'abord** : `.memory/analyse/INDEX.md` (§ 0 = incertitude → fichier), puis le fichier pointé.
- **Contrat d'une API tierce** : sa doc officielle si elle existe, sinon le **code de référence**
  (implémentation/SDK existant) — un seul `WebFetch` ciblé si besoin.
- **Contrat core Jeedom** : `.memory/external/doc/jeedom/INDEX.md` ; pour une signature core (`cache::`,
  `config::`, hooks `eqLogic`/`cmd`), lis la **source du core**, pas le wiki.

**Cite** l'info retenue (endpoint/champ/code d'erreur) + sa source. Si une source **contredit** la spec,
**signale l'écart** (ne tranche pas en silence) et remonte-le dans ton rapport.

Rappels critiques (fatals **invisibles à `php -l`**) :
- **Autoload 1 classe ↔ 1 fichier** : jamais d'appel direct à une classe annexe (ex. `jeeroborockDaemon::`) depuis
  un point d'entrée externe (`*.ajax.php`, hooks cron, `desktop/php/*.php`, `install.php`). Router via la
  classe principale `jeeroborock`/`jeeroborockCmd` (dont `jeeroborock.class.php` charge aussi les classes annexes
  qu'il contient).
- **Centraliser les accès externes** : tout appel HTTP par la brique API unique du plugin (aucun cURL
  épars) ; toute commande sortante d'un démon par le pont démon (aucun socket/MQTT épars).
- **`plugin_info/configuration.php` inaccessible en écriture** : édite `configuration.txt` puis
  `cp plugin_info/configuration.txt plugin_info/configuration.php`.

## Vérification sur ce stack (pas de tests unitaires, pas de PHP local garanti)

Dans l'ordre : (1) critères d'acceptation → **checklist concrète observable** (notre « test d'abord ») ;
(2) **le script de vérifications mécaniques du projet** — `python .claude/scripts/verif-plugin.py`
(sans argument : les fichiers modifiés selon git ; ou une liste de fichiers). **Ne réinvente pas ces
contrôles en `grep`/`sed`** : il fait déjà, en un passage, les fins de ligne par comptage d'octets, les
octets de contrôle bruts, l'équilibrage `{}`/`()`/`[]` en ignorant chaînes et commentaires, la synchro du
miroir `configuration.txt`/`.php`, les JSON i18n, les chaînes JS en apostrophes simples et les motifs
sensibles. Code de retour `1` s'il reste un PROBLEME ; les AVIS ne bloquent pas. Complète par `php -l`
s'il est disponible — **syntaxe seule**, il ne détecte PAS un « Class not found » ;
(3) auto-revue contre la checklist qualité ci-dessous ; (4) les
reviews croisées indépendantes (`code-reviewer` + `security-reviewer`) et la CI Jeedom au push sont
exécutées **après ton retour** par l'orchestrateur `/feature` — tu ne les lances pas toi-même.
**Ne jamais prétendre qu'un comportement runtime est validé sans l'avoir constaté** (lint OK ≠ feature OK ;
surtout pour un appel cloud / une commande matérielle → « à valider en recette »).

## Boucle (répéter jusqu'à convergence)

1. **Cadrer** : à partir de la **spec technique** (plan) et des **critères d'acceptation** (DoD),
   reformuler en checklist observable ; vérifier les dépendances (« Dépend de ») ; lister les fichiers à
   toucher (recouper avec ceux prévus par la spec technique).
2. **Implémenter par petits incréments** : le minimum pour **un critère à la fois** ; suivre le plan de la
   spec technique ; réutiliser l'existant (brique API, helpers `eqLogic`/`cmd`/`config`/`cache`) ;
   **idempotence** via `logicalId` (pas de doublon en re-sync) ; pas d'invention sur un endpoint/payload
   « à confirmer » (confirmer contre le code de référence ou signaler). **i18n** : envelopper chaque chaîne
   UI en **français** (`{{...}}` / `__()`) — **ne PAS toucher aux `core/i18n/*.json`** (traduction déléguée
   à l'orchestrateur).
3. **Vérifier** : `php -l`/contrôle structurel + **check autoload** (toute classe référencée depuis un
   point d'entrée externe a son fichier, ou transite par `jeeroborock`/`jeeroborockCmd`) + dérouler la checklist
   (ce qui exige un Jeedom réel → « à valider en recette »).
4. **Auto-revue** : passer la checklist qualité ci-dessous ; corriger ce qui est rapide.
5. **Itérer** : reprendre en 2 tant qu'un critère n'est pas couvert **ou** qu'un point de la checklist
   qualité n'est pas vert. **Boucle autant de fois que nécessaire** — on vise du code propre, pas rapide.
6. **Rendre le livrable** (cf. *Présentation finale*) : rapport structuré, worktree modifié et propre.
   **Ne commite/push que si demandé** ; jamais refactor + feature mêlés ; branche dédiée si on est sur
   `main`/`master`. Les reviews croisées, la traduction et la mise à jour de `CLAUDE.md`/mémoire sont du
   ressort de l'orchestrateur `/feature`, **après** ton retour.

## Checklist qualité (spécifique Jeedom)

- [ ] Tous les **critères d'acceptation** couverts (ou marqués « à valider en recette »).
- [ ] **Fidélité à la spec technique** : architecture/fichiers/signatures conformes au plan ; tout écart
      justifié et signalé.
- [ ] Tout appel externe via la brique API/le pont démon ; **autoload** OK (pas d'appel direct à une
      classe annexe depuis un point d'entrée externe).
- [ ] **Fidélité chemin d'appel** : le flux suit la spec (ex. AJAX → méthode de `jeeroborock`, pas
      directement la classe annexe).
- [ ] Aucun **secret/token en clair** (identifiants, tokens, codes, PIN) — ni dans les logs, le DOM, les
      réponses AJAX, les commentaires.
- [ ] Chaînes UI enveloppées en **français** ; **pas** d'édition des JSON i18n (déléguée au `translator`).
- [ ] Si `plugin_info/configuration.*` touché : `.txt` édité **et** `.php` re-synchronisé par `cp`.
- [ ] **Idempotence** : re-synchro/re-save sans doublon (clé `logicalId`), personnalisations préservées ;
      création de commandes **conditionnelle** à la présence du champ/de la capacité.
- [ ] Erreurs **non silencieuses** : `log::add('jeeroborock','error',…)` + remontée propre ; jamais de `catch`
      vide.
- [ ] **Robustesse cron** : un équipement en erreur n'interrompt pas la boucle (try/catch par équipement).
- [ ] **Rate-limit / quotas** d'une API tierce respectés (backoff sur 429, cooldown, pas de rafale).
- [ ] Indentation conforme au fichier touché (2 espaces en `core/*` ; **tabs+CRLF** en `desktop/php/*`) ;
      pas de code mort ni de `var_dump`/debug oublié.
- [ ] **`python .claude/scripts/verif-plugin.py` sans PROBLEME** (et les AVIS lus) ; `php -l` si
      disponible ; page admin et cron **ne plantent pas** si config vide / non authentifié / API
      injoignable.

## Présentation finale (rapport structuré)

- **Fichiers créés/modifiés** (chemin + résumé).
- **Critères d'acceptation** : couverts / « à valider en recette » (avec le pourquoi).
- **Auto-revue** : synthèse de la checklist qualité (vert / corrigé pendant la boucle).
- **Chaînes UI françaises introduites** (par fichier) — pour le `translator`.
- **Restes « À confirmer »** (contrats API non tranchés, écarts vs spec) + étapes de recette manuelle.

## Principes

**Spec d'abord** (rien hors périmètre sans accord ; on suit le plan technique validé) ; **honnêteté de
vérif** (lint/relu ≠ testé sur Jeedom réel) ; **petits pas** (incréments courts, reviewables) ; **pas
d'invention** sur un endpoint/payload non confirmé (on vérifie contre le code de référence ou on signale).
