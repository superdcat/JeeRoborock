#!/usr/bin/env python
# -*- coding: utf-8 -*-
"""
Assistant de renommage du squelette de plugin Jeedom — port Python de
``helperConfiguration.php`` (utile quand ``php`` n'est pas disponible en local).

Il remplace l'id ``template`` par le nouvel id dans le contenu des fichiers, renomme
les fichiers dont le nom contient ``template``, met à jour ``info.json`` (id, nom,
catégorie, démon, dépendances, URLs de doc) et supprime ``resources/`` si le plugin
n'a pas de démon.

Fidèle au helper PHP, avec deux garde-fous ajoutés :
- les fichiers **binaires** (ex. l'icône PNG) ne sont pas modifiés en contenu (seulement renommés) ;
- ``plugin_info/configuration.php`` n'est pas lu/écrit (miroir de ``configuration.txt``).

Usage (non interactif — recommandé, notamment piloté par ``/init-plugin``) :
    python plugin_info/helperConfiguration.py --id monplugin --name "Mon Plugin" \
        --category programming --daemon no --dependency no

Options :
    --id           (obligatoire) nouvel id du plugin (minuscules, sans espace)
    --name         nom d'affichage (info.json "name")
    --category     catégorie Jeedom : soit la valeur (ex. "programming"), soit un numéro 1-16
    --daemon       yes|no : yes => hasOwnDeamon=true ; no => supprime resources/ + hasOwnDeamon=false
    --dependency   yes|no : yes => hasDependency=true ; no => hasDependency=false + retire maxDependancyInstallTime
    --root         racine du plugin (défaut : dossier parent de ce script)
    --dry-run      affiche les actions sans rien modifier
    --yes          ne pas demander de confirmation (implicite en mode non interactif)

Sans ``--id`` et si l'entrée est interactive (humain), le script pose les questions au clavier.
"""

import argparse
import json
import os
import sys

OLD_ID = "template"

# Dossiers traités (relatifs à la racine du plugin) — identiques au helper PHP.
PROCESS_DIRS = ["core/class", "core/php", "core/ajax", "desktop", "plugin_info"]

# Fichiers dont le CONTENU ne doit jamais être modifié.
SKIP_CONTENT_FILES = {"helperConfiguration.php", "helperConfiguration.py", "configuration.php"}

# Ligne(s) contenant ceci : on ne remplace pas 'template' dessus (asset core 'plugin.template').
PRESERVE_MARKER = "plugin.template"

# Mapping numéro -> catégorie Jeedom (comme le helper PHP).
CATEGORY_MAP = {
    "1": "security", "2": "automation protocol", "3": "home automation protocol",
    "4": "programming", "5": "organization", "6": "weather", "7": "communication",
    "8": "devicecommunication", "9": "multimedia", "10": "wellness", "11": "monitoring",
    "12": "health", "13": "nature", "14": "automatisation", "15": "energy", "16": "other",
}


def is_probably_binary(path):
    """Détecte un fichier binaire (ne pas modifier son contenu)."""
    try:
        with open(path, "rb") as f:
            chunk = f.read(4096)
    except OSError:
        return True
    if b"\x00" in chunk:
        return True
    try:
        chunk.decode("utf-8")
    except UnicodeDecodeError:
        return True
    return False


def replace_content_in_dir(directory, new_id, dry_run, changed):
    for root, _dirs, files in os.walk(directory):
        for name in files:
            if name in SKIP_CONTENT_FILES:
                continue
            if name == "info.json" and os.path.basename(root) == "plugin_info":
                continue  # info.json est géré via JSON, pas par remplacement texte
            path = os.path.join(root, name)
            if is_probably_binary(path):
                continue
            with open(path, "r", encoding="utf-8", newline="") as f:
                content = f.read()
            if OLD_ID not in content:
                continue
            new_lines = []
            for line in content.splitlines(keepends=True):
                if PRESERVE_MARKER in line:
                    new_lines.append(line)
                else:
                    new_lines.append(line.replace(OLD_ID, new_id))
            new_content = "".join(new_lines)
            if new_content != content:
                changed.append(("contenu", path))
                if not dry_run:
                    with open(path, "w", encoding="utf-8", newline="") as f:
                        f.write(new_content)


def rename_files_in_dir(directory, new_id, dry_run, changed):
    # Collecte d'abord (éviter de muter pendant le walk).
    to_rename = []
    for root, _dirs, files in os.walk(directory):
        for name in files:
            if name in ("helperConfiguration.php", "helperConfiguration.py"):
                continue
            if OLD_ID in name:
                to_rename.append((root, name))
    for root, name in to_rename:
        new_name = name.replace(OLD_ID, new_id)
        src = os.path.join(root, name)
        dst = os.path.join(root, new_name)
        changed.append(("renommage", "%s -> %s" % (src, new_name)))
        if not dry_run:
            os.rename(src, dst)


def update_info_json(root, new_id, name, category, daemon, dependency, dry_run, changed):
    path = os.path.join(root, "plugin_info", "info.json")
    with open(path, "r", encoding="utf-8") as f:
        data = json.load(f)

    data["id"] = new_id
    if name:
        data["name"] = name
    if category:
        data["category"] = CATEGORY_MAP.get(category, category)

    for key in ("changelog_beta", "changelog", "documentation_beta", "documentation"):
        if isinstance(data.get(key), str):
            data[key] = data[key].replace(OLD_ID, new_id)

    if daemon is True:
        data["hasOwnDeamon"] = True
    elif daemon is False:
        data["hasOwnDeamon"] = False

    if dependency is True:
        data["hasDependency"] = True
    elif dependency is False:
        data["hasDependency"] = False
        data.pop("maxDependancyInstallTime", None)

    changed.append(("info.json", path))
    if not dry_run:
        with open(path, "w", encoding="utf-8") as f:
            json.dump(data, f, indent=4, ensure_ascii=False)
            f.write("\n")


def remove_resources(root, dry_run, changed):
    res = os.path.join(root, "resources")
    if os.path.isdir(res):
        changed.append(("suppression", res))
        if not dry_run:
            import shutil
            shutil.rmtree(res)


def parse_yes_no(value):
    if value is None:
        return None
    return str(value).strip().lower() in ("yes", "y", "oui", "o", "true", "1")


def interactive_fill(args):
    """Repli interactif pour un usage humain (si --id absent et stdin est un TTY)."""
    if not sys.stdin.isatty():
        return False
    if not args.id:
        args.name = input("Quel est le nom de votre plugin ? ").strip() or None
        print("Catégories : 1 security, 2 automation protocol, 3 home automation protocol,")
        print("4 programming, 5 organization, 6 weather, 7 communication, 8 devicecommunication,")
        print("9 multimedia, 10 wellness, 11 monitoring, 12 health, 13 nature, 14 automatisation,")
        print("15 energy, 16 other")
        args.category = input("Numéro de catégorie [4] : ").strip() or "4"
        args.daemon = input("Le plugin a-t-il un démon ? (oui/non) : ").strip()
        args.dependency = input("Le plugin a-t-il des dépendances ? (oui/non) : ").strip()
        args.id = input("Quel est l'ID du plugin : ").strip()
    return True


def main():
    parser = argparse.ArgumentParser(description="Renomme le squelette de plugin Jeedom (port Python).")
    parser.add_argument("--id")
    parser.add_argument("--name")
    parser.add_argument("--category")
    parser.add_argument("--daemon")
    parser.add_argument("--dependency")
    parser.add_argument("--root")
    parser.add_argument("--dry-run", action="store_true")
    parser.add_argument("--yes", action="store_true")
    args = parser.parse_args()

    if not args.id:
        interactive_fill(args)

    if not args.id:
        parser.error("--id est obligatoire (mode non interactif).")

    new_id = args.id.strip()
    if new_id == OLD_ID:
        parser.error("Le nouvel id est identique à 'template' : rien à faire.")
    if not new_id or any(c.isspace() for c in new_id):
        parser.error("Id invalide : minuscules, sans espace.")

    root = os.path.abspath(args.root) if args.root else os.path.dirname(os.path.dirname(os.path.abspath(__file__)))

    daemon = parse_yes_no(args.daemon)
    dependency = parse_yes_no(args.dependency)

    changed = []
    for rel in PROCESS_DIRS:
        d = os.path.join(root, rel)
        if os.path.isdir(d):
            replace_content_in_dir(d, new_id, args.dry_run, changed)
    for rel in PROCESS_DIRS:
        d = os.path.join(root, rel)
        if os.path.isdir(d):
            rename_files_in_dir(d, new_id, args.dry_run, changed)
    update_info_json(root, new_id, args.name, args.category, daemon, dependency, args.dry_run, changed)
    if daemon is False:
        remove_resources(root, args.dry_run, changed)

    prefix = "[dry-run] " if args.dry_run else ""
    for kind, detail in changed:
        print("%s%-11s %s" % (prefix, kind, detail))
    print("%s%d action(s) — plugin renommé en '%s'." % (prefix, len(changed), new_id))


if __name__ == "__main__":
    main()
