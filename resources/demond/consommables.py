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
# Consommables et usure (UC12) : module de PURES DONNEES et de calcul, sur le modele de
# libelles.py. N'ENREGISTRE AUCUNE OPERATION et N'IMPORTE JAMAIS robots.py (evite le
# cycle d'import : c'est robots.py qui importe ce module, pas l'inverse).
#
# D-12-5 - DEROGATION EXPLICITE a la regle D-h d'UC03 ("aucun import de roborock.*" pour
# un module de pures donnees, cf. libelles.py). Ce module y deroge DELIBEREMENT et
# importe roborock.const, PARCE QUE les durees de reference avant remplacement sont un
# contrat tiers EPINGLE (version exacte 7.8.0) : les recopier en dur ferait de ce module
# une 2e source de verite qui se desynchroniserait SILENCIEUSEMENT a la prochaine montee
# de version - exactement le defaut que D-h ne cherche pas a prevenir ici. Cette
# derogation ne se generalise PAS : un futur module de pures donnees de libelle reste
# soumis a D-h.
#
# Contrepartie OBLIGATOIRE (pour ne pas perdre ce que D-h protegeait vraiment : un demon
# qui demarre meme sans la librairie) : import GARDE. Si roborock.const est introuvable,
# les references valent None et REFERENCES_OK vaut False (journalise UNE fois), mais
# TABLE reste peuplee de ses 5 cles dans tous les cas - sinon la liste blanche de
# robots.py (RESET_CONSOMMABLES) se viderait et tout reset deviendrait un
# INTERNAL_ERROR trompeur.

import collections
import logging

REFERENCES_OK = True
try:
    from roborock.const import (
        FILTER_REPLACE_TIME,
        MAIN_BRUSH_REPLACE_TIME,
        MOP_ROLLER_REPLACE_TIME,
        SENSOR_DIRTY_REPLACE_TIME,
        SIDE_BRUSH_REPLACE_TIME,
    )
except ImportError:
    REFERENCES_OK = False
    MAIN_BRUSH_REPLACE_TIME = None
    SIDE_BRUSH_REPLACE_TIME = None
    FILTER_REPLACE_TIME = None
    SENSOR_DIRTY_REPLACE_TIME = None
    MOP_ROLLER_REPLACE_TIME = None
    logging.error("consommables : roborock.const introuvable, durees de reference indisponibles")

# TABLE reste peuplee de ses 5 cles MEME si REFERENCES_OK est False (cf. D-12-5) : c'est
# elle qui alimente RESET_CONSOMMABLES (liste blanche fermee de robots.py).
TABLE = collections.OrderedDict([
    ("brossePrincipale",  {"champ": "main_brush_work_time",  "reference": MAIN_BRUSH_REPLACE_TIME}),
    ("brosseLaterale",    {"champ": "side_brush_work_time",  "reference": SIDE_BRUSH_REPLACE_TIME}),
    ("filtre",            {"champ": "filter_work_time",      "reference": FILTER_REPLACE_TIME}),
    ("capteurs",          {"champ": "sensor_dirty_time",     "reference": SENSOR_DIRTY_REPLACE_TIME}),
    ("rouleauSerpillere", {"champ": "moproller_work_time",   "reference": MOP_ROLLER_REPLACE_TIME}),
])


def _pourcentage(travail_s, reference_s):
    """Pourcentage d'usure RESTANT, borne [0,100] (int). reference_s <= 0 ou non
    numerique -> 100 (aucune usure calculable, on ne penalise pas l'affichage). Jamais
    negatif meme si travail_s > reference_s (AC5 : Consumable.*_time_left de la
    librairie devient negatif dans ce cas - volontairement NON utilise ici)."""
    try:
        travail = float(travail_s)
        reference = float(reference_s)
    except (TypeError, ValueError):
        return 100
    if reference <= 0:
        return 100
    restant = 100.0 * (1.0 - (travail / reference))
    if restant < 0:
        return 0
    if restant > 100:
        return 100
    return int(restant)


def capacites(conso):
    """5 booleens. Critere UNIQUE et STRICT (AC2, documente par la librairie elle-meme) :
    getattr(conso, champ, None) is not None, APRES un premier consumables.refresh().
    Ne prend PAS device_features (D-12-2, divergence assumee avec robots.capacites_etat) :
    ConsumableField n'expose que 3 des 5 champs, et un True de schema avec une valeur
    absente creerait une tuile vide, ce qu'AC2 interdit explicitement."""
    resultat = {}
    for cle, infos in TABLE.items():
        valeur = getattr(conso, infos["champ"], None) if conso is not None else None
        resultat[cle] = valeur is not None
    return resultat


def valeurs(conso):
    """Cle ABSENTE quand la valeur n'est pas calculable (champ None, ou reference
    indisponible) : jamais 0 par defaut. Chaque valeur est un entier [0,100]."""
    resultat = {}
    if conso is None:
        return resultat
    for cle, infos in TABLE.items():
        travail = getattr(conso, infos["champ"], None)
        if travail is None:
            continue
        reference = infos["reference"]
        if reference is None:
            continue
        resultat[cle] = _pourcentage(travail, reference)
    return resultat


def bloc(conso):
    """{"capacites":..., "valeurs":...} ; None si aucun champ n'est peuple (rien a
    publier, coute nul cote appelant - cf. supervision._lot)."""
    if conso is None:
        return None
    caps = capacites(conso)
    vals = valeurs(conso)
    if not any(caps.values()) and not vals:
        return None
    return {"capacites": caps, "valeurs": vals}
