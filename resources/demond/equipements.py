# This file is part of Jeedom.
#
# Jeedom is free software: you can redistribute it and/or modify
# it under the terms of the GNU General Public License as published by
# the Free Software Foundation, either version 3 of the License, or
# (at your option) any later version.
#
# Jeedom is distributed in the hope that it will be useful,
# but WITHOUT ANY WARRANTY; without even the implied warranty of
# MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
# GNU General Public License for more details.
#
# You should have received a copy of the GNU General Public License
# along with Jeedom. If not, see <http://www.gnu.org/licenses/>.
#
#
# Decouverte des equipements du compte Roborock (UC06).
#
# Une seule operation exposee au canal : decouvrirEquipements. Fait TOUJOURS un appel
# homedata frais (D-06-3 de la spec technique) : aucun cache demon, la mise en cache de
# l'inventaire vit cote PHP (UC05). Un refus de quota (RoborockRateLimit) N'EST PAS
# rattrape ici (D-06-4) : il remonte tel quel jusqu'a handler_rpc, point de mapping
# unique des exceptions python-roborock.
#
# Le filtrage de compatibilite V1 vit ICI, pas cote PHP : compatible <=> pv == "1.0" ET
# categorie "robot.vacuum.cleaner" ET produit resolu (D-06-5, aligne sur
# roborock/devices/device_manager.py). 'home' (HomeData) n'est JAMAIS journalise ni
# retourne : il porte les local_key des robots (cf. jeeroborock-cloud-api.md).
#
# Boucle asyncio unique (R1 d'UC03) : les deux seuls appels lib sont async,
# try_acquire(..., blocking=False) est en temps constant, le post-traitement est une
# boucle bornee sur des objets deja en memoire. Aucun asyncio.to_thread necessaire.

import logging
import re

import session
from erreurs import ErreurDemon

import canal

PV_V1 = "1.0"
CATEGORIE_VACUUM = "robot.vacuum.cleaner"
LONGUEUR_MAX_TEXTE = 128
LIMITE_APPAREILS = 64  # borne dure sur la taille des deux listes renvoyees

_CARACTERES_CONTROLE = re.compile(r"[\x00-\x1f\x7f]")


def _categorie(produit):
    """Tolere un enum, une chaine ou None, SANS importer RoborockCategory (regle D-h
    d'UC03 : aucun symbole de la lib importe pour du mapping)."""
    return str(getattr(produit.category, "value", produit.category) or "")


def _texte(valeur, longueur_max=LONGUEUR_MAX_TEXTE):
    """'' si None ; str() ; neutralisation des caracteres de controle (\\x00-\\x1F,
    \\x7F) AVANT troncature, pour empecher une injection de fausse ligne dans les
    logging.* de ce fichier (device.duid/device.pv sont d'origine cloud). Le PHP
    re-neutralise en defense en profondeur (texteInventaire), ce n'est pas un doublon a
    supprimer."""
    if valeur is None:
        return ""
    return _CARACTERES_CONTROLE.sub("", str(valeur))[:longueur_max]


def _decrire_robot(device, produit, partage):
    return {
        "duid": _texte(device.duid),
        "nomRoborock": _texte(device.name),
        "model": _texte(produit.model),
        "productName": _texte(produit.name),
        "fv": _texte(device.fv),
        "pv": _texte(device.pv),
        "sn": _texte(device.sn),
        "shared": bool(partage),
    }


def _decrire_non_supporte(device, produit, motif):
    return {
        "nomRoborock": _texte(device.name),
        "model": _texte(produit.model) if produit is not None else "",
        "pv": _texte(device.pv),
        "motif": motif,
    }


def _ajouter_non_supporte(non_supportes, device, produit, motif):
    """Applique le garde de troncature LIMITE_APPAREILS puis journalise le rejet (D-06-5 :
    les trois motifs sont distingues dans le log, R-6 de la recette). Journalise a partir
    de nos PROPRES champs uniquement (jamais device.summary_info(), ni 'home') ; l'identifiant
    est tronque, jamais le duid complet. Retourne True si l'appareil a ete ajoute a la
    liste, False s'il a ete omis (liste deja pleine)."""
    if len(non_supportes) >= LIMITE_APPAREILS:
        return False
    non_supportes.append(_decrire_non_supporte(device, produit, motif))
    logging.info(
        "decouvrirEquipements : appareil rejete id=%s motif=%s pv=%s",
        _texte(device.duid, 16),
        motif,
        _texte(device.pv),
    )
    return True


async def decouvrir(parametres, contexte):
    if not session.IMPORT_OK:
        raise ErreurDemon("INTERNAL_ERROR")

    user_data_brut = parametres.get("userData") or ""
    if user_data_brut == "":
        raise ErreurDemon("NOT_AUTHENTICATED")

    base_url = parametres.get("baseUrl") or ""
    email = parametres.get("email") or ""

    user_data = session.decoder_user_data(user_data_brut)
    client = session.creer_client(email, base_url)

    # Aucun try/except autour de cet appel (D-06-4) : un refus de quota
    # (RoborockRateLimit) doit remonter tel quel, sans retentative ni repli.
    home = await client.get_home_data_v3(user_data)

    duids_partages = {d.duid for d in (home.received_devices or [])}
    produits = home.product_map

    robots = []
    non_supportes = []
    vus = set()
    omis_robots = 0
    omis_non_supportes = 0

    for device in home.get_all_devices():
        duid = device.duid
        if duid in vus:
            continue
        vus.add(duid)

        produit = produits.get(device.product_id)
        if produit is None:
            if not _ajouter_non_supporte(non_supportes, device, None, "PRODUIT_INCONNU"):
                omis_non_supportes += 1
            continue

        if device.pv != PV_V1:
            if not _ajouter_non_supporte(non_supportes, device, produit, "PROTOCOLE"):
                omis_non_supportes += 1
            continue

        if _categorie(produit) != CATEGORIE_VACUUM:
            if not _ajouter_non_supporte(non_supportes, device, produit, "CATEGORIE"):
                omis_non_supportes += 1
            continue

        if len(robots) >= LIMITE_APPAREILS:
            omis_robots += 1
            continue
        robots.append(_decrire_robot(device, produit, duid in duids_partages))
        # Niveau info (pas debug) : c'est la SEULE source qui rend R-1 de la recette
        # concluant (confirmer le pv reellement renvoye par le Qrevo Curv, hypothese non
        # verifiee de D-06-5) ; ne se produit qu'a la decouverte, operation manuelle et
        # rare, donc pas un souci de volume au niveau de log par defaut.
        logging.info(
            "decouvrirEquipements : appareil accepte id=%s pv=%s",
            _texte(device.duid, 16),
            _texte(device.pv),
        )

    if omis_robots or omis_non_supportes:
        logging.warning(
            "decouvrirEquipements : liste(s) tronquee(s) a %s (robots omis=%s, non supportes omis=%s)",
            LIMITE_APPAREILS,
            omis_robots,
            omis_non_supportes,
        )

    # Dict inline, forme strictement identique aux trois occurrences d'authentification.py
    # (D-06-2 : la factorisation memoriser_session() est explicitement ecartee pour ce cycle).
    contexte["session"] = {
        "userData": session.encoder_user_data(user_data),
        "baseUrl": str(base_url),
        "email": email,
    }

    return {
        "nbTotal": len(home.get_all_devices()),
        "robots": robots,
        "nonSupportes": non_supportes,
    }


def enregistrer_operations():
    canal.enregistrer("decouvrirEquipements", decouvrir)
