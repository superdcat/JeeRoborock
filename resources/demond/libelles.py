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
# Libelles francais d'etat et d'erreur (UC07, D-07-3).
#
# Module de PURES DONNEES, separe de robots.py : ~100 lignes de table au milieu de la
# logique de lecture la rendraient illisible, et UC08/UC12 reutiliseront ces tables
# telles quelles. AUCUN import de roborock.* (regle D-h d'UC03) : les fonctions ne
# lisent que getattr(valeur, "display_name", ...), jamais un symbole de la librairie -
# ce module reste chargeable meme si python-roborock est absent du venv.
#
# Les tables sont indexees sur le display_name des enums de la librairie (pas .name) :
# la librairie associe plusieurs codes bruts a un meme display_name (ex. les etats 23 et
# 25 partagent "washing_the_mop", les erreurs 36 et 45 partagent "mopping_roller_1") -
# indexer sur .name dupliquerait ces entrees pour rien.

import logging

# display_name (RoborockStateCode) -> libelle francais.
ETATS = {
    "unknown": "Inconnu",
    "starting": "Démarrage",
    "charger_disconnected": "Déconnecté du chargeur",
    "idle": "En veille",
    "remote_control_active": "Contrôle à distance actif",
    "cleaning": "En nettoyage",
    "returning_home": "Retour à la base",
    "manual_mode": "Mode manuel",
    "charging": "En charge",
    "charging_problem": "Problème de charge",
    "paused": "En pause",
    "spot_cleaning": "Nettoyage ponctuel",
    "error": "En erreur",
    "shutting_down": "Extinction",
    "updating": "Mise à jour",
    "docking": "Retour à la base en cours",
    "going_to_target": "Déplacement vers un point",
    "zoned_cleaning": "Nettoyage de zone",
    "segment_cleaning": "Nettoyage de pièce",
    "emptying_the_bin": "Vidage du bac à poussière",
    "washing_the_mop": "Lavage de la serpillière",
    "going_to_wash_the_mop": "Retour pour laver la serpillière",
    "in_call": "Appel vidéo en cours",
    "mapping": "Cartographie en cours",
    "egg_attack": "Action spéciale en cours",
    "patrol": "Ronde de surveillance",
    "attaching_the_mop": "Fixation de la serpillière",
    "detaching_the_mop": "Retrait de la serpillière",
    "charging_complete": "Charge terminée",
    "device_offline": "Robot hors ligne",
    "locked": "Verrouillé",
    "air_drying_stopping": "Arrêt du séchage à l'air",
    "robot_status_mopping": "Lavage en cours",
    "clean_mop_cleaning": "Aspiration et lavage : aspiration",
    "clean_mop_mopping": "Aspiration et lavage : lavage",
    "segment_mopping": "Lavage de pièce",
    "segment_clean_mop_cleaning": "Nettoyage de pièce : aspiration",
    "segment_clean_mop_mopping": "Nettoyage de pièce : lavage",
    "zoned_mopping": "Lavage de zone",
    "zoned_clean_mop_cleaning": "Nettoyage de zone : aspiration",
    "zoned_clean_mop_mopping": "Nettoyage de zone : lavage",
    "back_to_dock_washing_duster": "Retour à la base pour laver la serpillière",
}

# display_name considere comme "en nettoyage" (D-07-4) : une session de nettoyage
# inachevee, y compris en pause et pendant le retour a la base. Hypothese a confirmer
# sur le materiel reel (R-9 de la spec technique).
ETATS_NETTOYAGE = frozenset({
    "cleaning",
    "spot_cleaning",
    "zoned_cleaning",
    "segment_cleaning",
    "paused",
    "returning_home",
    "docking",
    "going_to_target",
    "washing_the_mop",
    "going_to_wash_the_mop",
    "attaching_the_mop",
    "detaching_the_mop",
    "emptying_the_bin",
    "mapping",
    "patrol",
    "robot_status_mopping",
    "clean_mop_cleaning",
    "clean_mop_mopping",
    "segment_mopping",
    "segment_clean_mop_cleaning",
    "segment_clean_mop_mopping",
    "zoned_mopping",
    "zoned_clean_mop_cleaning",
    "zoned_clean_mop_mopping",
    "back_to_dock_washing_duster",
})

# display_name (RoborockErrorCode) -> libelle francais. "none" (code 0) n'a pas besoin
# d'entree : libelle_erreur() court-circuite ce cas avant toute recherche.
ERREURS = {
    "lidar_blocked": "Capteur lidar bloqué",
    "bumper_stuck": "Pare-chocs coincé",
    "wheels_suspended": "Roues suspendues dans le vide",
    "cliff_sensor_error": "Erreur du capteur anti-chute",
    "main_brush_jammed": "Brosse principale bloquée",
    "side_brush_jammed": "Brosse latérale bloquée",
    "wheels_jammed": "Roues bloquées",
    "robot_trapped": "Robot piégé",
    "no_dustbin": "Bac à poussière absent",
    "strainer_error": "Filtre humide ou bouché",
    "compass_error": "Champ magnétique important détecté",
    "low_battery": "Batterie faible",
    "charging_error": "Erreur de charge",
    "battery_error": "Erreur de batterie",
    "wall_sensor_dirty": "Capteur de mur sale",
    "robot_tilted": "Robot incliné",
    "side_brush_error": "Erreur de la brosse latérale",
    "fan_error": "Erreur du ventilateur",
    "dock": "Base non alimentée",
    "optical_flow_sensor_dirt": "Capteur de flux optique sale",
    "vertical_bumper_pressed": "Pare-chocs vertical enfoncé",
    "dock_locator_error": "Erreur de repérage de la base",
    "return_to_dock_fail": "Échec du retour à la base",
    "nogo_zone_detected": "Zone interdite détectée",
    "visual_sensor": "Erreur de la caméra",
    "light_touch": "Erreur du capteur de mur",
    "vibrarise_jammed": "Système de vibration bloqué",
    "robot_on_carpet": "Robot sur un tapis",
    "filter_blocked": "Filtre bouché",
    "invisible_wall_detected": "Mur virtuel détecté",
    "cannot_cross_carpet": "Impossible de franchir le tapis",
    "internal_error": "Erreur interne",
    "collect_dust_error_3": "Erreur de vidage automatique",
    "collect_dust_error_4": "Erreur de tension de la base de vidage",
    "mopping_roller_1": "Rouleau de lavage bloqué",
    "mopping_roller_error_2": "Rouleau de lavage mal abaissé",
    "clear_water_box_hoare": "Vérifiez le réservoir d'eau propre",
    "dirty_water_box_hoare": "Vérifiez le réservoir d'eau sale",
    "sink_strainer_hoare": "Réinstallez le filtre à eau",
    "clear_water_box_exception": "Réservoir d'eau propre vide",
    "clear_brush_exception": "Vérifiez l'installation du filtre à eau",
    "clear_brush_exception_2": "Erreur du bouton de positionnement",
    "filter_screen_exception": "Nettoyez le filtre à eau de la base",
    "up_water_exception": "Erreur de montée d'eau",
    "drain_water_exception": "Erreur de vidange d'eau",
    "temperature_protection": "Protection thermique activée",
    "clean_carousel_exception": "Erreur du carrousel de nettoyage",
    "clean_carousel_water_full": "Carrousel de nettoyage plein",
    "water_carriage_drop": "Chute du chariot à eau",
    "check_clean_carouse": "Vérifiez le carrousel de nettoyage",
    "audio_error": "Erreur audio",
}

# Cles deja journalisees en "cle d'etat/erreur inconnue" : n'avertit qu'une fois par
# cle (et par table) plutot qu'a chaque rafraichissement, sans quoi le log serait noye
# des le premier robot dote d'un code non repertorie.
_ETATS_INCONNUS_JOURNALISES = set()
_ERREURS_INCONNUES_JOURNALISEES = set()


def _cle(valeur):
    cle = getattr(valeur, "display_name", None)
    if cle is None:
        cle = str(valeur)
    return cle


def libelle_etat(etat):
    """'' si etat est None ; sinon ETATS[display_name] ; sinon l'identifiant anglais de
    la librairie avec les underscores remplaces par des espaces (jamais un vide ni un
    code brut, meme pour un code non repertorie - cf. D-07-3)."""
    if etat is None:
        return ""
    cle = _cle(etat)
    if cle in ETATS:
        return ETATS[cle]
    if cle not in _ETATS_INCONNUS_JOURNALISES:
        _ETATS_INCONNUS_JOURNALISES.add(cle)
        logging.info("libelle_etat : identifiant d'etat inconnu de la table de libelles : %s", cle)
    return cle.replace("_", " ")


def libelle_erreur(erreur):
    """'' si erreur est None OU vaut 0 (RoborockErrorCode.none) - AC3, ne JAMAIS
    renvoyer la chaine "none" que porte error_code_name."""
    if erreur is None:
        return ""
    try:
        if int(erreur) == 0:
            return ""
    except (TypeError, ValueError):
        pass
    cle = _cle(erreur)
    if cle in ERREURS:
        return ERREURS[cle]
    if cle not in _ERREURS_INCONNUES_JOURNALISEES:
        _ERREURS_INCONNUES_JOURNALISEES.add(cle)
        logging.info("libelle_erreur : identifiant d'erreur inconnu de la table de libelles : %s", cle)
    return cle.replace("_", " ")


def est_en_nettoyage(etat):
    """False si etat est None ; sinon display_name in ETATS_NETTOYAGE (D-07-4 : derive
    de l'etat, jamais de in_cleaning)."""
    if etat is None:
        return False
    return _cle(etat) in ETATS_NETTOYAGE
