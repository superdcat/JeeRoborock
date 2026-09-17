#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""État, résolution d'UC et récapitulatif pour les commandes /auto-dev et /change.

Tout ce qui est mécanique et coûteux à refaire à la main (donc à re-facturer en tokens à
chaque tour de boucle) vit ici : parsing de la demande, résolution des specs, journal de
reprise, assemblage de `recap.md`. L'orchestrateur ne lit que des sorties courtes.

    python .claude/scripts/auto-dev.py resolve "MVP 04 .. MVP 08"
    python .claude/scripts/auto-dev.py init    "MVP 04, MVP 06, MVP 08" [--nouveau]
    python .claude/scripts/auto-dev.py status  [--run run-...] [--json]
    python .claude/scripts/auto-dev.py event   --uc MVP/04 --phase impl --etat ok
    python .claude/scripts/auto-dev.py recap

Codes de retour : 0 = ok, 1 = erreur d'usage ou demande non résoluble, 2 = rien à faire.

Ce script n'ecrit JAMAIS dans `core/`, `desktop/`, `plugin_info/` : il ne touche que
`.memory/auto-dev/` et le `recap.md` de la racine (entierement regenere, jamais edite a la
main - toute prose durable se met dans les fichiers sources qu'il assemble).
"""

import argparse
import datetime
import glob
import io
import json
import os
import re
import subprocess
import sys

# La console Windows est en cp1252 : sans cela, le moindre accent dans un message fait
# planter le script AU MOMENT d'afficher le rapport (meme piege que verif-plugin.py).
for flux in (sys.stdout, sys.stderr):
    try:
        flux.reconfigure(encoding='utf-8', errors='replace')
    except Exception:
        pass

RACINE = os.path.abspath(os.path.join(os.path.dirname(__file__), '..', '..'))
SPECS = '.memory/specs'
BASE = '.memory/auto-dev'
REVISIONS = BASE + '/revisions'
RECAP = 'recap.md'
TPL_PRINCIPES = '.claude/templates/principes-arbitrage.md'

# Ordre des phases d'un cycle /feature, tel que journalise. Situe une reprise apres coupure.
PHASES = ['plan', 'spec-tech', 'impl', 'verif', 'review', 'correctifs',
          'traduction', 'memoire', 'commit']
ETATS_EVENT = ('debut', 'ok', 'echec', 'bloque', 'avertissement', 'reprise')


# --------------------------------------------------------------------------- utilitaires


def abs_(rel):
    return os.path.join(RACINE, rel)


def lire(rel, defaut=''):
    try:
        with io.open(abs_(rel), 'r', encoding='utf-8') as f:
            return f.read()
    except Exception:
        return defaut


def ecrire(rel, contenu):
    chemin = abs_(rel)
    dossier = os.path.dirname(chemin)
    if dossier:
        os.makedirs(dossier, exist_ok=True)
    tmp = chemin + '.tmp'
    with io.open(tmp, 'w', encoding='utf-8', newline='\n') as f:
        f.write(contenu)
    os.replace(tmp, chemin)


def maintenant():
    return datetime.datetime.now().strftime('%Y-%m-%dT%H:%M:%S')


def aujourdhui():
    return datetime.datetime.now().strftime('%Y-%m-%d')


def sh(args):
    try:
        p = subprocess.run(args, cwd=RACINE, capture_output=True, text=True)
        return p.stdout.strip()
    except Exception:
        return ''


def nom_plugin():
    """Nom du plugin, lu dans info.json - le recap suit donc le renommage du squelette."""
    try:
        info = json.loads(lire('plugin_info/info.json'))
    except Exception:
        return ''
    nom = info.get('name') or info.get('id') or ''
    if isinstance(nom, dict):                          # `name` multilingue
        nom = nom.get('fr_FR') or next(iter(nom.values()), '')
    return str(nom).strip()


# --------------------------------------------------------------------- resolution des UC


def dossiers_specs():
    """Tous les dossiers de specs existants, en chemin relatif a .memory/specs/."""
    res = []
    racine = abs_(SPECS)
    for rep, _sous, _f in os.walk(racine):
        rel = os.path.relpath(rep, racine).replace('\\', '/')
        if rel == '.':
            continue
        res.append(rel)
    return sorted(res)


def normaliser_dossier(jeton):
    """'mvp' -> 'MVP' ; 'post-mvp/1' -> 'post-mvp/01-transport-broadlink-lan'."""
    brut = jeton.strip().strip('/').replace('\\', '/')
    if not brut:
        return None
    bas = brut.lower()
    connus = dossiers_specs()
    for d in connus:                                   # correspondance exacte, insensible
        if d.lower() == bas:
            return d
    m = re.match(r'^post[-_]?mvp[/ ]?(\d{1,2})(?:-.*)?$', bas)
    if m:
        num = m.group(1).zfill(2)
        for d in connus:
            if d.lower().startswith('post-mvp/' + num + '-'):
                return d
    return None


def specs_du_dossier(dossier):
    """{'04': '04-modele-generique-et-capacites', ...} - specs FONCTIONNELLES seulement."""
    res = {}
    rep = abs_(os.path.join(SPECS, dossier))
    if not os.path.isdir(rep):
        return res
    for nom in sorted(os.listdir(rep)):
        if not nom.endswith('.md') or nom.endswith('-tech.md'):
            continue
        m = re.match(r'^(\d{2})-(.+)\.md$', nom)
        if m:
            res[m.group(1)] = nom[:-3]
    return res


def decouper(demande):
    """Tokenise la demande. Gere '04..08', '04 .. 08', les virgules et les sauts de ligne."""
    txt = demande.replace('…', '..')
    txt = re.sub(r'\.\.+', ' .. ', txt)
    txt = txt.replace(',', ' , ').replace(';', ' , ')
    return [t for t in txt.split() if t]


def resoudre(demande):
    """Retourne (liste d'UC, avertissements). Une UC = dict pret a journaliser."""
    avert = []
    paires = []                                        # [(dossier, numero)]
    dossier_courant = None
    dernier = None                                     # (dossier, numero) : borne basse
    attend_intervalle = False

    for jeton in decouper(demande):
        if jeton == ',':
            attend_intervalle = False
            continue
        if jeton == '..':
            attend_intervalle = True
            # La borne basse a deja ete poussee comme UC seule : elle sera reinjectee par
            # l'expansion de l'intervalle. Sans ce retrait, « MVP 04 .. MVP 08 » signale un
            # faux doublon sur 04.
            if paires and dernier is not None and paires[-1] == dernier:
                paires.pop()
            continue
        d = normaliser_dossier(jeton)
        if d:
            dossier_courant = d
            continue
        m = re.match(r'^#?(\d{1,2})$', jeton)
        if not m:
            avert.append("jeton ignore (ni dossier ni numero d'UC) : %s" % jeton)
            continue
        num = m.group(1).zfill(2)
        if dossier_courant is None:
            avert.append("numero %s sans dossier : ignore (ecrire par ex. MVP %s)"
                         % (jeton, num))
            continue
        if attend_intervalle and dernier is not None:
            d0, n0 = dernier
            if d0 != dossier_courant:
                avert.append("intervalle entre deux dossiers differents (%s -> %s) : borne "
                             "au dossier d'arrivee" % (d0, dossier_courant))
                d0 = dossier_courant
            bornes = sorted([n0, num])
            dispo = sorted(specs_du_dossier(d0).keys())
            trouve = [n for n in dispo if bornes[0] <= n <= bornes[1]]
            if not trouve:
                avert.append('intervalle %s %s..%s : aucune spec fonctionnelle trouvee'
                             % (d0, bornes[0], bornes[1]))
            for n in trouve:
                paires.append((d0, n))
            attend_intervalle = False
            dernier = (d0, bornes[1])
            continue
        paires.append((dossier_courant, num))
        dernier = (dossier_courant, num)
        attend_intervalle = False

    # Dossier cite sans aucun numero -> tout le dossier.
    if not paires and dossier_courant:
        for n in sorted(specs_du_dossier(dossier_courant).keys()):
            paires.append((dossier_courant, n))

    vues, uc = set(), []
    precedent = None
    for dossier, num in paires:
        cle = '%s/%s' % (dossier, num)
        if cle in vues:
            avert.append('UC en double dans la demande, ignoree : %s' % cle)
            continue
        vues.add(cle)
        specs = specs_du_dossier(dossier)
        nom = specs.get(num)
        if precedent and precedent[0] == dossier and precedent[1] > num:
            avert.append('ordre non croissant (%s apres %s/%s) : les UC ont des dependances '
                         'strictes, verifier que c est voulu'
                         % (cle, precedent[0], precedent[1]))
        precedent = (dossier, num)
        if nom is None:
            avert.append('AUCUNE spec fonctionnelle pour %s : UC non implementable' % cle)
            uc.append({'cle': cle, 'dossier': dossier, 'numero': num, 'spec_nom': None,
                       'spec_path': None, 'tech_path': None, 'tech_existe': False,
                       'predecesseur': None, 'predecesseur_implemente': None,
                       'uc_dir': None, 'existe': False, 'etat': 'bloque', 'phase': None,
                       'commit': None, 'detail': 'spec fonctionnelle absente',
                       'maj_le': None})
            continue
        spec_path = '%s/%s/%s.md' % (SPECS, dossier, nom)
        tech_path = '%s/%s/%s-tech.md' % (SPECS, dossier, nom)
        pred = [n for n in sorted(specs.keys()) if n < num]
        pred_ok = None
        if pred:
            pnom = specs[pred[-1]]
            pred_ok = os.path.isfile(abs_('%s/%s/%s-tech.md' % (SPECS, dossier, pnom)))
        uc.append({
            'cle': cle, 'dossier': dossier, 'numero': num, 'spec_nom': nom,
            'spec_path': spec_path, 'tech_path': tech_path,
            'tech_existe': os.path.isfile(abs_(tech_path)),
            'predecesseur': ('%s/%s' % (dossier, pred[-1])) if pred else None,
            'predecesseur_implemente': pred_ok,
            'uc_dir': None, 'existe': True, 'etat': 'a_faire', 'phase': None,
            'commit': None, 'detail': None, 'maj_le': None,
        })
    for u in uc:
        if u['existe'] and u['predecesseur'] and u['predecesseur_implemente'] is False \
                and u['predecesseur'] not in vues:
            avert.append('%s depend de %s, qui n a pas de spec technique (donc probablement '
                         'pas implementee) et n est pas dans la demande'
                         % (u['cle'], u['predecesseur']))
    return uc, avert


# ------------------------------------------------------------------------------- runs


def runs():
    return sorted(os.path.basename(p) for p in glob.glob(abs_(BASE) + '/run-*')
                  if os.path.isdir(p))


def chemin_etat(run):
    return '%s/%s/etat.json' % (BASE, run)


def charger(run):
    txt = lire(chemin_etat(run))
    if not txt:
        return None
    try:
        return json.loads(txt)
    except Exception:
        return None


def sauver(etat):
    etat['maj_le'] = maintenant()
    ecrire(chemin_etat(etat['run_id']), json.dumps(etat, ensure_ascii=False, indent=2) + '\n')


def dernier_run(demande=None):
    for run in reversed(runs()):
        etat = charger(run)
        if not etat:
            continue
        if demande is None or etat.get('demande', '').strip() == demande.strip():
            return etat
    return None


def uc_de(etat, cle):
    for u in etat['uc']:
        if u['cle'] == cle:
            return u
    return None


def restants(etat):
    return [u for u in etat['uc'] if u['etat'] not in ('termine', 'bloque')]


def slug(cle):
    return cle.replace('/', '-')


# ------------------------------------------------------------------------- sous-commandes


def cmd_resolve(a):
    uc, avert = resoudre(a.demande)
    print(json.dumps({'ok': bool([u for u in uc if u['existe']]), 'demande': a.demande,
                      'uc': uc, 'avertissements': avert}, ensure_ascii=False, indent=2))
    return 0 if [u for u in uc if u['existe']] else 1


def cmd_init(a):
    uc, avert = resoudre(a.demande)
    if not [u for u in uc if u['existe']]:
        print('ERREUR: aucune UC resoluble dans : %s' % a.demande)
        for m in avert:
            print('  AVERTISSEMENT: ' + m)
        return 1

    etat = None if a.nouveau else dernier_run(a.demande)
    mode = 'NOUVEAU'
    if etat is not None:
        mode = 'REPRISE'
        # Re-resolution : on rafraichit les chemins mais on preserve l'avancement acquis.
        anciens = {u['cle']: u for u in etat['uc']}
        for u in uc:
            vieux = anciens.get(u['cle'])
            if vieux:
                for champ in ('etat', 'phase', 'commit', 'detail', 'maj_le'):
                    u[champ] = vieux.get(champ)
                u['uc_dir'] = vieux.get('uc_dir')
        etat['uc'] = uc
    else:
        base = datetime.datetime.now().strftime('%Y%m%d-%H%M')
        run, n = 'run-' + base, 2
        while run in runs():
            run = 'run-%s-%d' % (base, n)
            n += 1
        etat = {'version': 1, 'run_id': run, 'cree_le': maintenant(),
                'demande': a.demande.strip(), 'uc': uc}
    for u in etat['uc']:
        if u['existe']:
            u['uc_dir'] = '%s/%s/%s' % (BASE, etat['run_id'], slug(u['cle']))
            os.makedirs(abs_(u['uc_dir']), exist_ok=True)
    sauver(etat)

    print('%s run_id=%s dir=%s/%s' % (mode, etat['run_id'], BASE, etat['run_id']))
    for m in avert:
        print('AVERTISSEMENT: ' + m)
    afficher_table(etat)
    if not restants(etat):
        print('RIEN_A_FAIRE (toutes les UC de cette demande sont terminees ou bloquees)')
        return 2
    return 0


def afficher_table(etat):
    print('')
    print('%-14s %-10s %-12s %-9s %s' % ('UC', 'ETAT', 'PHASE', 'COMMIT', 'SPEC'))
    for u in etat['uc']:
        print('%-14s %-10s %-12s %-9s %s' % (
            u['cle'], u['etat'], u.get('phase') or '-',
            (u.get('commit') or '-')[:8], u.get('spec_nom') or '(absente)'))
    reste = restants(etat)
    print('')
    print('RESTE: %d / %d' % (len(reste), len(etat['uc'])))
    if reste:
        u = reste[0]
        print('SUIVANT: cle=%s spec_nom=%s spec=%s tech=%s uc_dir=%s etat=%s phase=%s'
              % (u['cle'], u['spec_nom'], u['spec_path'], u['tech_path'], u['uc_dir'],
                 u['etat'], u.get('phase') or '-'))


def cmd_status(a):
    etat = charger(a.run) if a.run else dernier_run()
    if etat is None:
        print('AUCUN_RUN (lancer init au prealable)')
        return 2
    if a.json:
        print(json.dumps(etat, ensure_ascii=False, indent=2))
        return 0
    print('run_id=%s cree_le=%s demande=%s'
          % (etat['run_id'], etat['cree_le'], etat['demande']))
    afficher_table(etat)
    return 0 if restants(etat) else 2


def cmd_event(a):
    etat = charger(a.run) if a.run else dernier_run()
    if etat is None:
        print('ERREUR: aucun run - lancer init au prealable')
        return 1
    u = uc_de(etat, a.uc)
    if u is None:
        print('ERREUR: UC %s absente du run %s' % (a.uc, etat['run_id']))
        return 1

    evt = {'ts': maintenant(), 'uc': a.uc, 'phase': a.phase, 'etat': a.etat,
           'detail': a.detail or '', 'commit': a.commit or ''}
    chemin = abs_('%s/%s/journal.jsonl' % (BASE, etat['run_id']))
    os.makedirs(os.path.dirname(chemin), exist_ok=True)
    with io.open(chemin, 'a', encoding='utf-8', newline='\n') as f:
        f.write(json.dumps(evt, ensure_ascii=False) + '\n')

    u['maj_le'] = evt['ts']
    if a.detail:
        u['detail'] = a.detail
    if a.commit:
        u['commit'] = a.commit
    if a.etat == 'echec':
        u['etat'], u['phase'] = 'echec', a.phase
    elif a.etat == 'bloque':
        u['etat'], u['phase'] = 'bloque', a.phase
    elif a.etat == 'ok' and a.phase == 'commit':
        u['etat'], u['phase'] = 'termine', 'commit'
    elif a.etat in ('ok', 'debut', 'reprise', 'avertissement'):
        u['etat'], u['phase'] = 'en_cours', a.phase
    sauver(etat)
    print('OK %s %s/%s -> etat=%s' % (etat['run_id'], a.uc, a.phase, u['etat']))
    return 0


# ------------------------------------------------------------------------------ recap


RE_DEC = re.compile(r'^###\s+(D-[A-Za-z0-9]+-\d+[A-Za-z0-9]*)\s*[—-]\s*(.+?)\s*$', re.M)
RE_STATUT = re.compile(r'^-\s+\*\*Statut\*\*\s*:\s*(.+?)\s*$', re.M)
RE_REVISE = re.compile(r'^-\s+\*\*Révise\*\*\s*:\s*(D-[A-Za-z0-9]+-\d+[A-Za-z0-9]*)', re.M)


def sections_decisions():
    """Une entree par decisions.md non vide, dans l'ordre des runs puis des UC."""
    res = []
    for run in runs():
        etat = charger(run)
        if not etat:
            continue
        for u in etat['uc']:
            if not u.get('uc_dir'):
                continue
            rel = u['uc_dir'] + '/decisions.md'
            txt = lire(rel)
            if txt.strip():
                res.append({'run': run, 'uc': u, 'chemin': rel, 'texte': txt.rstrip()})
    return res


def fichiers_revisions():
    rep = abs_(REVISIONS)
    if not os.path.isdir(rep):
        return []
    res = []
    for nom in sorted(os.listdir(rep)):
        if not nom.endswith('.md'):
            continue
        rel = REVISIONS + '/' + nom
        txt = lire(rel)
        if txt.strip():
            res.append({'chemin': rel, 'texte': txt.rstrip()})
    return res


def index_des_decisions(sources, revisees):
    index = []
    for s in sources:
        texte = s['texte']
        for m in RE_DEC.finditer(texte):
            did, titre = m.group(1), m.group(2)
            bloc = texte[m.end():]
            suivant = RE_DEC.search(bloc)
            if suivant:
                bloc = bloc[:suivant.start()]
            st = RE_STATUT.search(bloc)
            statut = st.group(1) if st else '?'
            if did in revisees:
                statut = '%s (revisee par %s)' % (statut, ', '.join(revisees[did]))
            index.append((did, titre, statut, s['chemin']))
    return index


def cmd_recap(_a):
    sections = sections_decisions()
    revs = fichiers_revisions()

    revisees = {}
    for r in revs:
        ids = RE_DEC.findall(r['texte'])
        etiquette = ids[0][0] if ids else os.path.basename(r['chemin'])
        for m in RE_REVISE.finditer(r['texte']):
            revisees.setdefault(m.group(1), []).append(etiquette)

    index = index_des_decisions(sections + revs, revisees)

    o = []
    nom = nom_plugin()
    o.append('# Recapitulatif des decisions%s'
             % ((' - plugin ' + nom) if nom else ''))
    o.append('')
    o.append('> **Fichier GENERE - ne pas editer a la main.** Il est reassemble par')
    o.append('> `python .claude/scripts/auto-dev.py recap` a partir de')
    o.append('> `.memory/auto-dev/<run>/<UC>/decisions.md` et `.memory/auto-dev/revisions/*.md`.')
    o.append('> Toute correction se fait dans ces fichiers sources, puis on regenere.')
    o.append('')
    o.append('Genere le %s.' % aujourdhui())
    o.append('')
    o.append('## A quoi sert ce fichier')
    o.append('')
    o.append('`/auto-dev` enchaine des cycles `/feature` **sans intervention humaine** : a chaque')
    o.append("point ou le workflow aurait pose une question, l'orchestrateur a tranche seul. Ce")
    o.append('fichier est la **trace complete et autoportante** de ces arbitrages : question posee,')
    o.append("decision retenue, alternatives ecartees, portee dans le code, cout d'un revirement.")
    o.append('')
    o.append('Il est concu pour etre lu **a froid, en contexte vide**, par la commande `/change` :')
    o.append('')
    o.append('```')
    o.append('/change <explication de ce qui aurait du etre decide>')
    o.append('/change D-MVP04-02 <explication>       # cible une decision precise')
    o.append('/change --liste                        # affiche l index ci-dessous')
    o.append('```')
    o.append('')
    o.append('`/change` retrouve la decision, charge **uniquement** les fichiers qu elle cite,')
    o.append('produit un plan de revirement (code + specs + migration de l existant + i18n),')
    o.append("l implemente, puis ajoute une **revision** ici : l'ancienne decision reste visible,")
    o.append('marquee comme revisee.')
    o.append('')

    principes = lire(TPL_PRINCIPES).strip()
    if principes:
        o.append("## Principes d'arbitrage appliques")
        o.append('')
        o.append('Ordre de priorite utilise par `/auto-dev` pour trancher. Un revirement demande a')
        o.append("`/change` **ecrase** ces principes : c'est l'utilisateur qui arbitre en dernier.")
        o.append('')
        o.append(re.sub(r'^#\s+.*$', '', principes, count=1, flags=re.M).strip())
        o.append('')

    o.append('## Index des decisions')
    o.append('')
    if index:
        o.append('| ID | Sujet | Statut | Source |')
        o.append('|---|---|---|---|')
        for did, titre, statut, chemin in index:
            o.append('| `%s` | %s | %s | `%s` |' % (did, titre, statut, chemin))
    else:
        o.append('_Aucune decision journalisee pour l instant._')
    o.append('')

    o.append('## Cycles executes')
    o.append('')
    for run in runs():
        etat = charger(run)
        if not etat:
            continue
        o.append('### %s' % run)
        o.append('')
        o.append('- Demande : `%s`' % etat.get('demande', '?'))
        o.append('- Cree le : %s' % etat.get('cree_le', '?'))
        o.append('')
        o.append('| UC | Etat | Phase atteinte | Commit | Spec fonctionnelle | Spec technique |')
        o.append('|---|---|---|---|---|---|')
        for u in etat['uc']:
            o.append('| %s | %s | %s | %s | %s | %s |' % (
                u['cle'], u['etat'], u.get('phase') or '-',
                ('`%s`' % u['commit'][:8]) if u.get('commit') else '-',
                ('`%s`' % u['spec_path']) if u.get('spec_path') else '(absente)',
                ('`%s`' % u['tech_path']) if u.get('tech_path') else '-'))
        o.append('')

    o.append('## Decisions, par UC')
    o.append('')
    if sections:
        for s in sections:
            u = s['uc']
            o.append('---')
            o.append('')
            o.append('## UC %s - %s' % (u['cle'], u.get('spec_nom') or '?'))
            o.append('')
            o.append('- Cycle : `%s`' % s['run'])
            o.append('- Spec fonctionnelle : `%s`' % (u.get('spec_path') or '-'))
            o.append('- Spec technique : `%s`' % (u.get('tech_path') or '-'))
            o.append('- Commit : %s'
                     % (('`%s`' % u['commit']) if u.get('commit') else '_non commite_'))
            o.append('- Source de cette section : `%s`' % s['chemin'])
            o.append('')
            o.append(s['texte'])
            o.append('')
    else:
        o.append('_Aucune section de decisions trouvee._')
        o.append('')

    if revs:
        o.append('---')
        o.append('')
        o.append('## Revisions (`/change`)')
        o.append('')
        for r in revs:
            o.append('<!-- source : %s -->' % r['chemin'])
            o.append(r['texte'])
            o.append('')

    ecrire(RECAP, '\n'.join(o).rstrip() + '\n')
    print('recap.md regenere : %d decision(s), %d section(s) UC, %d revision(s)'
          % (len(index), len(sections), len(revs)))
    return 0


# ------------------------------------------------------------------------------- main


def main():
    p = argparse.ArgumentParser(prog='auto-dev.py', description=__doc__,
                                formatter_class=argparse.RawDescriptionHelpFormatter)
    sp = p.add_subparsers(dest='cmd', required=True)

    q = sp.add_parser('resolve', help='resout une demande en liste d UC (JSON), sans rien ecrire')
    q.add_argument('demande')
    q.set_defaults(func=cmd_resolve)

    q = sp.add_parser('init', help='cree ou reprend un run pour une demande')
    q.add_argument('demande')
    q.add_argument('--nouveau', action='store_true', help='force un nouveau run')
    q.set_defaults(func=cmd_init)

    q = sp.add_parser('status', help='etat du run (par defaut le plus recent)')
    q.add_argument('--run')
    q.add_argument('--json', action='store_true')
    q.set_defaults(func=cmd_status)

    q = sp.add_parser('event', help='journalise une transition de phase')
    q.add_argument('--run')
    q.add_argument('--uc', required=True)
    q.add_argument('--phase', required=True, choices=PHASES)
    q.add_argument('--etat', required=True, choices=ETATS_EVENT)
    q.add_argument('--detail')
    q.add_argument('--commit')
    q.set_defaults(func=cmd_event)

    q = sp.add_parser('recap', help='regenere recap.md a la racine')
    q.set_defaults(func=cmd_recap)

    a = p.parse_args()
    return a.func(a)


if __name__ == '__main__':
    sys.exit(main())
