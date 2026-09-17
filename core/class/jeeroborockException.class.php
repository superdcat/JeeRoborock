<?php
/* This file is part of Jeedom.
*
* Jeedom is free software: you can redistribute it and/or modify
* it under the terms of the GNU General Public License as published by
* the Free Software Foundation, either version 3 of the License, or
* (at your option) any later version.
*
* Jeedom is distributed in the hope that it will be useful,
* but WITHOUT ANY WARRANTY; without even the implied warranty of
* MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
* GNU General Public License for more details.
*
* You should have received a copy of the GNU General Public License
* along with Jeedom. If not, see <http://www.gnu.org/licenses/>.
*/

// Exception typee du plugin JeeRoborock (UC03). Porte un code stable (chaine, ex.
// 'RATE_LIMIT') distinct de Exception::getCode() : le constructeur natif declare
// int $code (TypeError en PHP 7+), incompatible avec des codes stables non numeriques.
//
// getMessage() porte le message francais DEJA traduit, pret a etre affiche : les
// appelants UC04+ n'ont aucune traduction a faire.
class jeeroborockException extends Exception {
  private $codeErreur;

  public function __construct($_codeErreur, $_message, $_precedente = null) {
    parent::__construct($_message, 0, $_precedente);
    $this->codeErreur = (string) $_codeErreur;
  }

  // Code stable du canal (ex. 'RATE_LIMIT', 'DAEMON_UNREACHABLE'). A utiliser pour du
  // routage applicatif, jamais pour construire un message affiche (getMessage() suffit).
  public function getCodeErreur() {
    return $this->codeErreur;
  }

  // ATTENTION : true signifie "le probleme est entre le plugin et le demon", PAS "le
  // demon est arrete". Deux familles de nature differente renvoient toutes les deux
  // true : les codes de transport (DAEMON_*, demon reellement injoignable) ET les codes
  // de protocole emis par un demon qui repond bien (UNAUTHORIZED, BAD_REQUEST,
  // UNKNOWN_OPERATION, INTERNAL_ERROR). Un UNAUTHORIZED provient d'un demon parfaitement
  // vivant : ne jamais deriver de cette methode un message du type "demarrez le demon",
  // utiliser le message porte par l'exception, qui est deja le bon.
  public function estErreurCanal() {
    $codesCanal = array(
      'DAEMON_UNREACHABLE',
      'DAEMON_TIMEOUT',
      'DAEMON_INVALID_RESPONSE',
      'UNAUTHORIZED',
      'BAD_REQUEST',
      'UNKNOWN_OPERATION',
      'INTERNAL_ERROR',
    );
    return in_array($this->codeErreur, $codesCanal, true);
  }
}
