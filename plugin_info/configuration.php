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

require_once dirname(__FILE__) . '/../../../core/php/core.inc.php';
include_file('core', 'authentification', 'php');
if (!isConnect('admin')) {
  include_file('desktop', '404', 'php');
  die();
}
?>
<form class="form-horizontal">
  <fieldset>
    <legend>{{Compte Roborock}}</legend>
    <div class="form-group">
      <label class="col-md-4 control-label">{{E-mail du compte Roborock}}
        <sup><i class="fas fa-question-circle tooltips" title="{{Adresse e-mail du compte Roborock. Le mot de passe n'est jamais demandé : l'authentification se fait par un code reçu par e-mail.}}"></i></sup>
      </label>
      <div class="col-md-4">
        <input type="email" class="configKey form-control" data-l1key="email"/>
      </div>
    </div>
  </fieldset>
  <fieldset>
    <legend>{{Canal local avec le démon}}</legend>
    <div class="form-group">
      <label class="col-md-4 control-label">{{Port du canal local}}
        <sup><i class="fas fa-question-circle tooltips" title="{{Port TCP utilisé sur 127.0.0.1 pour dialoguer avec le démon. À ne changer qu'en cas de conflit avec un autre service.}}"></i></sup>
      </label>
      <div class="col-md-4">
        <input type="number" min="1024" max="65535" step="1" class="configKey form-control" data-l1key="portDemonHttp"/>
      </div>
    </div>
    <div class="form-group">
      <div class="col-md-8 col-md-offset-4">
        <div class="alert alert-info">{{Le niveau de journalisation se règle dans le bloc Log de cette page.}}</div>
      </div>
    </div>
  </fieldset>
</form>
