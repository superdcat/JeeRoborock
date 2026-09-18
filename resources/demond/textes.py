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
# Helper de neutralisation de texte d'origine externe, partage par les modules de
# domaine du demon (UC09, D-09-solde de la dette R-15 d'UC07) : troisieme occurrence
# du meme corps (equipements.py, robots.py) -> factorisation prescrite par la spec
# technique. Corps REPRIS A L'IDENTIQUE des deux copies privees.

import re

LONGUEUR_MAX_TEXTE = 128

_CARACTERES_CONTROLE = re.compile(r"[\x00-\x1f\x7f]")


def texte(valeur, longueur_max=LONGUEUR_MAX_TEXTE):
    """'' si None ; str() ; neutralisation des caracteres de controle (\\x00-\\x1F,
    \\x7F) AVANT troncature, pour empecher une injection de fausse ligne dans les
    logging.* des modules appelants (donnees d'origine cloud). Le PHP re-neutralise en
    defense en profondeur (texteInventaire), ce n'est pas un doublon a supprimer."""
    if valeur is None:
        return ""
    return _CARACTERES_CONTROLE.sub("", str(valeur))[:longueur_max]
