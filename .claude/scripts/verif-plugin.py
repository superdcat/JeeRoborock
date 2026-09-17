#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Vérifications mécaniques du plugin — un seul passage, un seul rapport.

Regroupe les contrôles répétitifs qu'un agent réinvente sinon en `grep`/`sed` ad hoc à
chaque tour de correction. Deux pièges concrets qui ont motivé ce script :
  · `grep -c $'\\r'` mal échappé retourne le nombre TOTAL de lignes et fait croire qu'un
    fichier est en CRLF alors qu'il est en LF pur ;
  · un compteur d'accolades naïf produit un faux déséquilibre sur du HTML français
    (apostrophes typographiques, accolades dans les chaînes).

    python .claude/scripts/verif-plugin.py              # les fichiers modifiés selon git
    python .claude/scripts/verif-plugin.py f1 f2 ...    # des fichiers précis
    python .claude/scripts/verif-plugin.py --tout       # tout le code du plugin

Sortie : une ligne par fichier, puis les contrôles transverses (miroir, i18n, interdits).
Code de retour 1 s'il reste au moins un PROBLEME, 0 sinon (les AVIS n'échouent pas).

L'id du plugin est lu dans `plugin_info/info.json` : ce script n'a pas à être personnalisé
au renommage du squelette.

⚠️ Ne lit JAMAIS `plugin_info/configuration.php` (interdit par les permissions de session) :
la synchronisation du miroir est vérifiée via `git diff --numstat`, pas par comparaison.
"""

import io
import json
import os
import re
import subprocess
import sys

# La console Windows est en cp1252 : sans cela, le moindre accent dans un message fait
# planter le script AU MOMENT d'afficher le rapport.
for flux in (sys.stdout, sys.stderr):
    try:
        flux.reconfigure(encoding='utf-8', errors='replace')
    except Exception:
        pass

RACINE = os.path.abspath(os.path.join(os.path.dirname(__file__), '..', '..'))
CONFIG_PHP = 'plugin_info/configuration.php'
CONFIG_TXT = 'plugin_info/configuration.txt'


def _id_plugin():
    try:
        with io.open(os.path.join(RACINE, 'plugin_info/info.json'), encoding='utf-8') as f:
            return json.load(f).get('id') or 'template'
    except Exception:
        return 'template'


ID = _id_plugin()
PREFIXE_I18N = 'plugins/%s/' % ID


def _langues():
    """Langues cibles = celles déclarées dans info.json, moins la langue source fr_FR."""
    try:
        with io.open(os.path.join(RACINE, 'plugin_info/info.json'), encoding='utf-8') as f:
            langs = json.load(f).get('language') or []
        cibles = [l for l in langs if l != 'fr_FR']
        return tuple(cibles) if cibles else ('en_US', 'de_DE', 'es_ES')
    except Exception:
        return ('en_US', 'de_DE', 'es_ES')


LANGUES = _langues()

# Fichiers de code du plugin. Le markdown interne (.memory/, .claude/) est laissé libre —
# git le normalise et ce n'est pas du code exécuté.
EXT_CODE = ('.php', '.js', '.json', '.ini', '.txt', '.html')
EXT_STRUCT = ('.php', '.js', '.txt', '.html')
BLANCS = ' \t\r\n'

# CRLF exigé uniquement là où une divergence coûte quelque chose : le bot prettier et les
# éditeurs y produiraient un diff intégral. ⚠️ `.json` en est EXCLU : le JSON livré par le
# squelette Jeedom est en LF — l'exiger produirait des faux positifs à chaque exécution, et
# un script qui crie au loup se fait ignorer.
EXT_CRLF = ('.php', '.js', '.ini', '.txt', '.html')

problemes = []
avis = []


def sh(args):
    try:
        return subprocess.run(args, cwd=RACINE, capture_output=True, text=True).stdout
    except Exception:
        return ''


def lire_octets(rel):
    with io.open(os.path.join(RACINE, rel), 'rb') as f:
        return f.read()


# --------------------------------------------------------------------------- fichiers


def fichiers_git():
    out = sh(['git', 'status', '--short'])
    res = []
    for ligne in out.splitlines():
        chemin = ligne[3:].strip().strip('"')
        if ' -> ' in chemin:                     # renommage
            chemin = chemin.split(' -> ')[-1]
        if chemin.endswith('/') or chemin.startswith('.claude/agent-memory/'):
            continue
        if os.path.isfile(os.path.join(RACINE, chemin)):
            res.append(chemin)
    return sorted(set(res))


def fichiers_tout():
    res = []
    for base in ('core', 'desktop', 'plugin_info', 'resources'):
        racine_base = os.path.join(RACINE, base)
        if not os.path.isdir(racine_base):
            continue
        for rep, _, noms in os.walk(racine_base):
            for nom in noms:
                rel = os.path.relpath(os.path.join(rep, nom), RACINE).replace('\\', '/')
                if rel != CONFIG_PHP and nom.endswith(EXT_CODE):
                    res.append(rel)
    return sorted(res)


# ------------------------------------------------------------------- fins de ligne


def check_fins_de_ligne(rel, data):
    cr = data.count(b'\r')
    lf = data.count(b'\n')
    if lf == 0:
        return 'aucune ligne'
    if cr == 0:
        verdict = 'LF-pur'
    elif cr == lf:
        verdict = 'CRLF'
    else:
        verdict = 'MIXTE'
    if rel.endswith(EXT_CRLF) and verdict != 'CRLF':
        problemes.append('%s : fins de ligne %s — attendu CRLF pour ce type de fichier' % (rel, verdict))
    elif verdict == 'MIXTE':
        problemes.append('%s : fins de ligne MIXTES (%d CR pour %d LF)' % (rel, cr, lf))
    return verdict


# --------------------------------------------------------------- octets de contrôle


def check_octets_controle(rel, data):
    # \t est légitime (desktop/php/*.php est indenté en tabulations).
    suspects = [o for o in data if (o < 0x20 and o not in (0x09, 0x0A, 0x0D)) or o == 0x7F]
    if suspects:
        vus = sorted(set('0x%02X' % o for o in suspects))
        problemes.append('%s : %d octet(s) de contrôle BRUT(S) %s — texte à échappements corrompu ?'
                         % (rel, len(suspects), ', '.join(vus)))
    return len(suspects)


# ------------------------------------------------------- équilibrage structurel


def _sans_chaines_ni_commentaires(src):
    """Retire commentaires et littéraux de chaîne, pour ne compter que la structure."""
    out = []
    i, n = 0, len(src)
    while i < n:
        c = src[i]
        deux = src[i:i + 2]
        if deux == '/*':
            j = src.find('*/', i + 2)
            i = n if j < 0 else j + 2
            continue
        if deux == '//':
            j = src.find('\n', i)
            i = n if j < 0 else j
            continue
        if c == '#' and deux != '#[':
            j = src.find('\n', i)
            i = n if j < 0 else j
            continue
        if c in '"\'':
            quote = c
            i += 1
            while i < n:
                if src[i] == '\\':
                    i += 2
                    continue
                if src[i] == quote:
                    i += 1
                    break
                i += 1
            continue
        out.append(c)
        i += 1
    return ''.join(out)


def _segments_a_analyser(rel, texte):
    """Ne renvoie que ce qui est réellement du code : PHP entre balises, et blocs <script>."""
    segments = []
    if '<?php' in texte:
        for m in re.finditer(r'<\?php(.*?)(\?>|\Z)', texte, re.S):
            segments.append(('php', m.group(1)))
    for m in re.finditer(r'<script[^>]*>(.*?)</script>', texte, re.S | re.I):
        segments.append(('js', m.group(1)))
    if not segments and rel.endswith('.js'):
        segments.append(('js', texte))
    return segments


def check_structure(rel, texte):
    if not rel.endswith(EXT_STRUCT):
        return 'n/a'
    # Les segments d'un MÊME genre sont recollés avant comptage : en PHP, une accolade
    # ouverte avant une sortie HTML se referme dans le bloc `<?php` suivant
    # (`foreach (…) { ?> … <?php }`), motif tout à fait normal d'une vue Jeedom. Compter
    # segment par segment produit des « {} déséquilibré » permanents sur ce motif — et un
    # contrôle qui crie au loup finit par être ignoré.
    par_genre = {}
    for genre, src in _segments_a_analyser(rel, texte):
        par_genre.setdefault(genre, []).append(_sans_chaines_ni_commentaires(src))
    etats = []
    for genre, morceaux in par_genre.items():
        propre = ''.join(morceaux)
        for ouvre, ferme, nom in (('{', '}', '{}'), ('(', ')', '()'), ('[', ']', '[]')):
            a, b = propre.count(ouvre), propre.count(ferme)
            if a != b:
                problemes.append('%s : %s déséquilibré dans le code %s (%d vs %d)'
                                 % (rel, nom, genre, a, b))
                etats.append('%s!' % nom)
    return 'OK' if not etats else ' '.join(etats)


# ------------------------------------------------- méta-séquences prises pour du code


# Fichiers dont le HTML est RENDU par le core, donc balayé par son moteur i18n : un `{{`
# y est dangereux jusque dans un commentaire. Ailleurs (core/class, core/php) il n'est
# jamais interprété, et le signaler ne ferait que du bruit.
PREFIXES_RENDUS = ('desktop/', 'plugin_info/configuration.', 'core/template/')
RE_SCRIPT = re.compile(r'<script[^>]*>', re.I)


def check_meta_commentaires(rel, texte):
    """Traque les séquences qu'un délimiteur avale — ce que check_structure ne PEUT pas voir.

    Cas réel qui a motivé ce contrôle : `mb_*/intl` écrit dans un docblock. Le `*/` du
    milieu ferme le commentaire, `intl, …` est relu comme du code (« syntax error,
    unexpected 'intl' ») et la classe entière devient introuvable au runtime. Invisible à
    la relecture — une phrase française bien formée, dans un commentaire bien formé — et
    invisible au comptage de délimiteurs : le texte éjecté du commentaire ne contient ni
    accolade, ni parenthèse, ni crochet, donc l'équilibre reste parfait.

    Trois variantes, un seul mécanisme (un littéral pris pour de la méta-syntaxe) :
      · `*/` collé à du texte, dans un commentaire de bloc ;
      · `?>` dans un commentaire `//` ou `#` : PHP QUITTE le mode PHP à cet endroit, et la
        fin de la ligne part telle quelle au navigateur ;
      · `{{` dans un commentaire d'un fichier rendu : le moteur i18n du core y voit un
        début de clé de traduction et avale tout jusqu'à la fermeture suivante.
    """
    if not rel.endswith(EXT_STRUCT):
        return 'n/a'
    rendu = rel.startswith(PREFIXES_RENDUS)
    n = len(texte)
    i = 0
    en_code = rel.endswith('.js')          # un .js est du code de bout en bout
    fin_code = n                           # sortie forcée du mode code (fin de <script>)
    nb = 0

    def signale(pos, quoi):
        eol = texte.find('\n', pos)
        extrait = texte[texte.rfind('\n', 0, pos) + 1:n if eol < 0 else eol].strip()
        problemes.append('%s:%d : %s — l. %s'
                         % (rel, texte.count('\n', 0, pos) + 1, quoi,
                            repr(extrait[:70] + ('…' if len(extrait) > 70 else ''))))

    while i < n:
        if en_code and i >= fin_code:
            en_code = False
            continue
        if not en_code:                    # du HTML : seuls ses commentaires nous intéressent
            if texte[i:i + 5] == '<?php' or texte[i:i + 3] == '<?=':
                en_code, fin_code, i = True, n, i + 3
                continue
            m = RE_SCRIPT.match(texte, i)
            if m:
                ferme = texte.lower().find('</script>', m.end())
                en_code, fin_code, i = True, (n if ferme < 0 else ferme), m.end()
                continue
            if texte[i:i + 4] == '<!--':
                fin = texte.find('-->', i + 4)
                fin = n if fin < 0 else fin
                if rendu and '{{' in texte[i:fin]:
                    signale(texte.index('{{', i, fin), "'{{' dans un commentaire HTML : le moteur "
                            'i18n du core le lit dans le HTML rendu et avale la suite')
                    nb += 1
                i = fin + 3
                continue
            i += 1
            continue
        deux = texte[i:i + 2]
        if deux == '?>':
            en_code, i = False, i + 2
            continue
        if deux == '/*':
            fin = texte.find('*/', i + 2)
            if fin < 0 or fin >= fin_code:
                signale(i, 'commentaire de bloc jamais refermé')
                nb += 1
                break
            # Une fermeture saine est précédée d'un blanc ou d'une étoile (` */`, `**/`).
            # Collée à autre chose, elle vient du TEXTE : c'est une fermeture accidentelle.
            if texte[fin - 1] not in BLANCS and texte[fin - 1] != '*':
                signale(fin, "'*/' collé à du texte : le commentaire se FERME ici, la suite est "
                        'relue comme du code')
                nb += 1
            if rendu and '{{' in texte[i:fin]:
                signale(texte.index('{{', i, fin), "'{{' dans un commentaire de bloc : lu par le "
                        'moteur i18n du core')
                nb += 1
            i = fin + 2
            continue
        if deux == '//' or (texte[i] == '#' and deux != '#['):
            fin = texte.find('\n', i)
            fin = n if fin < 0 else fin
            corps = texte[i:fin]
            if '?>' in corps:
                signale(i, "'?>' dans un commentaire de ligne : PHP QUITTE le mode PHP ici, la "
                        'fin de la ligne part au navigateur')
                nb += 1
            if rendu and '{{' in corps:
                signale(i, "'{{' dans un commentaire de ligne : lu par le moteur i18n du core")
                nb += 1
            i = fin
            continue
        if texte[i] in '"\'':               # une chaîne n'est pas du commentaire : on saute
            quote = texte[i]
            i += 1
            while i < n:
                if texte[i] == '\\':
                    i += 2
                    continue
                if texte[i] == quote:
                    i += 1
                    break
                i += 1
            continue
        i += 1
    return 'OK' if not nb else '%d!' % nb


# ------------------------------------------------------------------ espaces de fin


def check_espaces_fin(rel, texte):
    fautives = [i + 1 for i, l in enumerate(texte.split('\n')) if l.rstrip('\r') != l.rstrip()]
    if fautives:
        apercu = ', '.join(str(x) for x in fautives[:5])
        avis.append('%s : espace(s) en fin de ligne (l. %s%s)'
                    % (rel, apercu, '…' if len(fautives) > 5 else ''))
    return len(fautives)


# ------------------------------------------------------------------ miroir txt/php


def check_miroir(fichiers):
    if CONFIG_TXT not in fichiers and CONFIG_PHP not in fichiers:
        return
    out = sh(['git', 'diff', '--numstat', '--', CONFIG_TXT, CONFIG_PHP])
    stats = {}
    for ligne in out.splitlines():
        cols = ligne.split('\t')
        if len(cols) == 3:
            stats[cols[2].replace('\\', '/')] = (cols[0], cols[1])
    a, b = stats.get(CONFIG_TXT), stats.get(CONFIG_PHP)
    if a is None and b is None:
        avis.append("miroir : aucun des deux fichiers n'est modifié par rapport à HEAD")
    elif a != b:
        problemes.append('miroir DÉSYNCHRONISÉ : configuration.txt %s vs configuration.php %s — '
                         'relancer « cp plugin_info/configuration.txt plugin_info/configuration.php »'
                         % (a, b))
    else:
        print('  miroir configuration.txt/.php : synchrone (%s ajouts, %s suppressions de chaque côté)' % a)


# ------------------------------------------------------------------------- i18n


def _cles_source():
    """Clés UI par fichier source, telles que les fichiers i18n doivent les indexer."""
    cles = {}
    for rel in fichiers_tout():
        # Le miroir .txt n'est jamais exécuté par Jeedom : ses clés s'indexent sous le .php.
        cible = PREFIXE_I18N + (CONFIG_PHP if rel == CONFIG_TXT else rel)
        try:
            texte = lire_octets(rel).decode('utf-8', 'replace')
        except Exception:
            continue
        trouvees = set(re.findall(r'\{\{(.+?)\}\}', texte, re.S))
        trouvees |= set(m.group(1) for m in
                        re.finditer(r"__\(\s*'((?:[^'\\]|\\.)*)'\s*,\s*__FILE__\s*\)", texte))
        trouvees |= set(m.group(1) for m in
                        re.finditer(r'__\(\s*"((?:[^"\\]|\\.)*)"\s*,\s*__FILE__\s*\)', texte))
        if trouvees:
            cles.setdefault(cible, set()).update(
                t.replace("\\'", "'").replace('\\"', '"') for t in trouvees)
    return cles


def check_i18n():
    presents = [lg for lg in LANGUES
                if os.path.isfile(os.path.join(RACINE, 'core/i18n/%s.json' % lg))]
    if not presents:
        avis.append("i18n : aucun fichier core/i18n/*.json (normal avant l'étape de traduction)")
        return
    source = _cles_source()
    print('  i18n : %d clé(s) UI dans le code, réparties sur %d fichier(s)'
          % (sum(len(v) for v in source.values()), len(source)))
    for lg in presents:
        chemin = 'core/i18n/%s.json' % lg
        try:
            data = json.loads(lire_octets(chemin).decode('utf-8'))
        except Exception as e:
            problemes.append('%s : JSON invalide (%s)' % (chemin, e))
            continue
        manquantes, orphelines, hors_perimetre = [], [], []
        for cible, attendues in source.items():
            traduites = set(data.get(cible, {}))
            absentes = sorted(attendues - traduites)
            if not absentes:
                continue
            # Heuristique de périmètre : un fichier dont AUCUNE clé n'est traduite n'est pas
            # « en retard », il est hors périmètre (chaînes héritées du squelette, dont le
            # balayage i18n complet est une UC à part). Un fichier déjà entamé et incomplet,
            # en revanche, est un vrai trou.
            if not traduites:
                hors_perimetre.append((cible, len(absentes)))
            else:
                manquantes += ['%s :: %s' % (cible, k) for k in absentes]
        for cible, trad in data.items():
            orphelines += ['%s :: %s' % (cible, k)
                           for k in sorted(set(trad) - source.get(cible, set()))]
        etat = '%-6s %3d clé(s), %d section(s)' % (lg, sum(len(v) for v in data.values()), len(data))
        if manquantes:
            etat += ' | %d MANQUANTE(S)' % len(manquantes)
            problemes.append('%s : %d clé(s) manquante(s) dans une section DÉJÀ traduite, dont %s'
                             % (chemin, len(manquantes), manquantes[0]))
        if hors_perimetre:
            etat += ' | %d fichier(s) hors périmètre' % len(hors_perimetre)
            for cible, cpt in hors_perimetre:
                avis.append("%s : %s n'a aucune traduction (%d clé(s)) — hors périmètre, ou "
                            'traduction pas encore lancée' % (chemin, cible, cpt))
        if orphelines:
            etat += ' | %d orpheline(s)' % len(orphelines)
            avis.append('%s : %d clé(s) traduite(s) sans source dans le code, dont %s'
                        % (chemin, len(orphelines), orphelines[0]))
        print('    ' + etat)

    # Une chaîne injectée dans du JS ne doit pas être délimitée par des apostrophes simples :
    # une traduction contenant une apostrophe casserait le script (panne silencieuse).
    try:
        txt = lire_octets(CONFIG_TXT).decode('utf-8', 'replace')
    except Exception:
        return
    for m in re.finditer(r'<script[^>]*>(.*?)</script>', txt, re.S | re.I):
        for mm in re.finditer(r"'(\{\{.+?\}\})'", m.group(1), re.S):
            problemes.append('%s : chaîne JS %s délimitée par des APOSTROPHES SIMPLES — une '
                             'traduction contenant une apostrophe casserait le script'
                             % (CONFIG_TXT, mm.group(1)[:40]))


# -------------------------------------------------------------------- interdits


INTERDITS = (
    (r'CURLOPT_VERBOSE|CURLOPT_STDERR|CURLOPT_DEBUGFUNCTION',
     "mode verbose cURL — écrirait l'en-tête Authorization complet dans le log du serveur web"),
    (r'getTraceAsString\s*\(', "trace d'exception — porte les arguments de chaque frame"),
    (r'CURLOPT_SSL_VERIFYPEER\s*,\s*(false|0)\b', 'vérification TLS désactivée'),
    (r'\bvar_dump\s*\(|\bprint_r\s*\(', 'débogage oublié'),
    (r'displayException\s*\(\s*\$e\s*\)',
     'displayException — jamais sur une exception typée du plugin ; réservé au catch générique'),
)


def check_interdits(fichiers):
    for rel in fichiers:
        if not rel.endswith(('.php', '.js')):
            continue
        try:
            texte = lire_octets(rel).decode('utf-8', 'replace')
        except Exception:
            continue
        for num, ligne in enumerate(texte.split('\n'), 1):
            nu = ligne.strip()
            if nu.startswith(('//', '*', '/*', '#')):     # un commentaire qui INTERDIT le motif
                continue
            for motif, pourquoi in INTERDITS:
                if re.search(motif, ligne):
                    avis.append('%s:%d — motif sensible (%s) : %s' % (rel, num, pourquoi, nu[:80]))


# ------------------------------------------------------------------------ main


def main():
    args = [a for a in sys.argv[1:] if not a.startswith('--')]
    if '--tout' in sys.argv:
        fichiers = fichiers_tout()
    elif args:
        fichiers = [a.replace('\\', '/') for a in args]
    else:
        fichiers = fichiers_git()

    fichiers = [f for f in fichiers if f != CONFIG_PHP]   # illisible : jamais ouvert

    print('=== Plugin « %s » — %d fichier(s) ===' % (ID, len(fichiers)))
    for rel in fichiers:
        if not os.path.isfile(os.path.join(RACINE, rel)):
            avis.append('%s : introuvable' % rel)
            continue
        data = lire_octets(rel)
        texte = data.decode('utf-8', 'replace')
        fdl = check_fins_de_ligne(rel, data)
        ctrl = check_octets_controle(rel, data)
        struct = check_structure(rel, texte)
        meta = check_meta_commentaires(rel, texte)
        check_espaces_fin(rel, texte)
        print('  %-52s %-8s ctrl=%d  struct=%-8s meta=%s' % (rel, fdl, ctrl, struct, meta))

    print('\n=== Transverse ===')
    check_miroir(fichiers)
    check_i18n()
    check_interdits(fichiers)

    if avis:
        print('\n=== AVIS (%d) — à regarder, non bloquant ===' % len(avis))
        for a in avis:
            print('  . ' + a)
    if problemes:
        print('\n=== PROBLEMES (%d) ===' % len(problemes))
        for p in problemes:
            print('  [X] ' + p)
        return 1
    print('\n[OK] Aucun problème mécanique détecté.')
    return 0


if __name__ == '__main__':
    sys.exit(main())
