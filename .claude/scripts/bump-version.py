#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Incrémente `pluginVersion` dans plugin_info/info.json — appelé par le hook git pre-commit.

Pourquoi ce script existe : le plugin est déployé via le **Market GitHub** de Jeedom.
Tant que `pluginVersion` ne change pas, Jeedom peut ne proposer AUCUNE mise à jour et ne
rejoue pas `template_update()` : le code poussé n'atteint jamais l'installation, et
l'utilisateur continue de voir l'ancienne page de configuration.

Règles :
- N'incrémente QUE si le commit touche du code réellement livré au plugin (`PERIMETRE`) :
  un commit qui ne touche que `.memory/`, `.claude/`, `.github/` ou `docs/` ne change rien
  pour l'installation Jeedom, donc ne consomme pas de version.
- Incrémente la DERNIÈRE composante numérique du format en place, sans imposer de format :
  "0.1" -> "0.2", "1.4.2" -> "1.4.3". Monter le major/minor reste une décision humaine
  (il suffit de l'écrire à la main dans info.json : le hook repart de cette valeur).
- Édition par REGEX en binaire, JAMAIS json.load/json.dump : `info.json` est indenté en
  tabulations et porte la `description` multilingue (≥ 80 caractères par langue, règle du
  market) ; un dump réécrirait indentation, ordre des clés et échappements.
- Ne bloque JAMAIS un commit : le script sort toujours 0, il avertit au pire.

Usage : automatique via .githooks/pre-commit (cf. `git config core.hooksPath .githooks`).
"""

import os
import re
import subprocess
import sys

# La console Windows est en cp1252 : sans cela un accent dans un message fait planter
# l'affichage du rapport (même correctif que .claude/scripts/verif-plugin.py).
for flux in (sys.stdout, sys.stderr):
    try:
        flux.reconfigure(encoding='utf-8', errors='replace')
    except Exception:
        pass

PREFIXE = 'template/version :'
INFO = 'plugin_info/info.json'
# Dossiers dont une modification justifie une nouvelle version côté Jeedom. `resources/`
# y figure par avance : le démon Python n'arrive qu'au domaine post-MVP, mais il sera
# livré à l'installation comme le reste.
PERIMETRE = ('core/', 'desktop/', 'plugin_info/', 'resources/')
MOTIF = re.compile(rb'("pluginVersion"\s*:\s*")([^"]*)(")')


def git(*args):
    return subprocess.run(('git',) + args, capture_output=True, text=True,
                          encoding='utf-8', errors='replace')


def main():
    racine = git('rev-parse', '--show-toplevel')
    if racine.returncode != 0:
        return 0
    os.chdir(racine.stdout.strip())

    # -z : les chemins non-ASCII sortent sinon échappés et entre guillemets
    # ("core/cl\303\251.php"), ce qui ferait échouer le startswith() ci-dessous.
    indexes = git('diff', '--cached', '--name-only', '-z')
    fichiers = [f for f in indexes.stdout.split('\0') if f]
    concernes = [f for f in fichiers if f.startswith(PERIMETRE)]
    if not concernes:
        return 0

    # info.json modifié mais NON indexé : le `git add` final embarquerait ces
    # modifications non préparées dans le commit. On s'abstient plutôt que de commiter
    # quelque chose que l'auteur n'a pas choisi de mettre dedans.
    if [f for f in git('diff', '--name-only', '-z', '--', INFO).stdout.split('\0') if f]:
        print(PREFIXE, INFO, 'a des modifications non indexées : version NON incrémentée')
        return 0

    if not os.path.exists(INFO):
        print(PREFIXE, INFO, 'introuvable : version NON incrémentée')
        return 0

    brut = open(INFO, 'rb').read()
    trouve = MOTIF.search(brut)
    if trouve is None:
        print(PREFIXE, 'clé "pluginVersion" introuvable dans', INFO)
        return 0

    ancienne = trouve.group(2).decode('utf-8', 'replace')
    morceaux = ancienne.split('.')
    if not morceaux[-1].isdigit():
        print(PREFIXE, 'version "%s" non incrémentable automatiquement' % ancienne)
        return 0
    morceaux[-1] = str(int(morceaux[-1]) + 1)
    nouvelle = '.'.join(morceaux)

    brut = (brut[:trouve.start()] + trouve.group(1) + nouvelle.encode('utf-8')
            + trouve.group(3) + brut[trouve.end():])
    open(INFO, 'wb').write(brut)

    ajout = git('add', '--', INFO)
    if ajout.returncode != 0:
        print(PREFIXE, 'git add a échoué :', ajout.stderr.strip())
        return 0

    print('%s %s -> %s (%d fichier(s) du plugin dans ce commit)'
          % (PREFIXE, ancienne, nouvelle, len(concernes)))
    return 0


sys.exit(main())
