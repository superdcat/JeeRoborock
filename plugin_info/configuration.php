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
    <div class="form-group">
      <label class="col-md-4 control-label">{{État du compte}}</label>
      <div class="col-md-8">
        <?php if (jeeroborock::estCompteLie()) { ?>
        <span id="jeeroborockEtatCompte" class="label label-success">{{Compte Roborock lié}}</span>
        <?php } else { ?>
        <span id="jeeroborockEtatCompte" class="label label-default">{{Compte Roborock non lié}}</span>
        <?php } ?>
        <a class="btn btn-default" id="bt_jeeroborockTesterConnexion">{{Tester la connexion}}</a>
        <br/>
        <span id="jeeroborockResultatTest"></span>
      </div>
    </div>
    <div class="form-group">
      <div class="col-md-8 col-md-offset-4">
        <div class="alert alert-info">{{La ré-authentification n'est jamais automatique : si la session expire, redemandez un code de connexion.}}</div>
      </div>
    </div>
    <div class="form-group">
      <label class="col-md-4 control-label">{{Code de connexion}}
        <sup><i class="fas fa-question-circle tooltips" title="{{Roborock envoie un code à usage unique à l'adresse e-mail enregistrée. Enregistrez la configuration avant de demander un code.}}"></i></sup>
      </label>
      <div class="col-md-8">
        <a class="btn btn-default" id="bt_jeeroborockDemanderCode">{{Envoyer un code}}</a>
        <input type="text" id="jeeroborockCodeConnexion" class="form-control" style="display:inline-block;width:auto;" autocomplete="off" maxlength="12" inputmode="numeric"/>
        <a class="btn btn-primary" id="bt_jeeroborockValiderCode">{{Valider le code}}</a>
        <br/>
        <span id="jeeroborockResultatAuth"></span>
      </div>
    </div>
  </fieldset>
  <fieldset>
    <legend>{{Équipements}}</legend>
    <div class="form-group">
      <label class="col-md-4 control-label">{{Synchronisation}}
        <sup><i class="fas fa-question-circle tooltips" title="{{La synchronisation interroge l'inventaire du compte Roborock, dont le quota est strictement limité et partagé avec l'application mobile : ne la lancez que lorsque vous ajoutez ou retirez un robot.}}"></i></sup>
      </label>
      <div class="col-md-8">
        <a class="btn btn-default" id="bt_jeeroborockSynchroniser">{{Synchroniser les équipements}}</a>
        <br/>
        <span id="jeeroborockResultatSynchro"></span>
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
    <div class="form-group">
      <label class="col-md-4 control-label">{{Diagnostic}}</label>
      <div class="col-md-8">
        <a class="btn btn-default" id="bt_jeeroborockVerifierCanal">{{Vérifier le canal}}</a>
        <span id="jeeroborockResultatCanal"></span>
      </div>
    </div>
  </fieldset>
</form>
<script>
  $('#bt_jeeroborockVerifierCanal').on('click', function () {
    var zoneResultat = $('#jeeroborockResultatCanal');
    zoneResultat.text("{{Vérification en cours…}}");
    $.ajax({
      type: 'POST',
      url: 'plugins/jeeroborock/core/ajax/jeeroborock.ajax.php',
      data: {action: 'santeCanal'},
      dataType: 'json',
      error: function (requete) {
        zoneResultat.text("{{Le démon ne répond pas.}}");
      },
      success: function (donnees) {
        if (donnees.state != 'ok') {
          zoneResultat.text(donnees.result);
          return;
        }
        var message = "{{Canal opérationnel}}";
        if (!donnees.result.callback) {
          message = "{{Le callback vers Jeedom n'est pas joignable : les mises à jour spontanées ne fonctionneront pas.}}";
        }
        zoneResultat.text(message);
      }
    });
  });

  var jeeroborockVerrouAuth = false;

  $('#bt_jeeroborockDemanderCode').on('click', function () {
    if (jeeroborockVerrouAuth) {
      return;
    }
    jeeroborockVerrouAuth = true;
    var zoneResultat = $('#jeeroborockResultatAuth');
    $('#bt_jeeroborockDemanderCode').addClass('disabled');
    $('#bt_jeeroborockValiderCode').addClass('disabled');
    zoneResultat.text("{{Envoi du code en cours…}}");
    $.ajax({
      type: 'POST',
      url: 'plugins/jeeroborock/core/ajax/jeeroborock.ajax.php',
      data: {action: 'demanderCode'},
      dataType: 'json',
      timeout: 30000,
      error: function (requete) {
        zoneResultat.text("{{Le démon ne répond pas.}}");
      },
      success: function (donnees) {
        if (donnees.state != 'ok') {
          zoneResultat.text(donnees.result);
          return;
        }
        zoneResultat.text("{{Code envoyé, vérifiez vos e-mails}}");
      },
      complete: function () {
        jeeroborockVerrouAuth = false;
        $('#bt_jeeroborockDemanderCode').removeClass('disabled');
        $('#bt_jeeroborockValiderCode').removeClass('disabled');
      }
    });
  });

  $('#bt_jeeroborockValiderCode').on('click', function () {
    if (jeeroborockVerrouAuth) {
      return;
    }
    var zoneResultat = $('#jeeroborockResultatAuth');
    var code = $.trim($('#jeeroborockCodeConnexion').val());
    if (code == '') {
      zoneResultat.text("{{Saisissez le code reçu par e-mail.}}");
      return;
    }
    jeeroborockVerrouAuth = true;
    $('#bt_jeeroborockDemanderCode').addClass('disabled');
    $('#bt_jeeroborockValiderCode').addClass('disabled');
    zoneResultat.text("{{Validation en cours…}}");
    $.ajax({
      type: 'POST',
      url: 'plugins/jeeroborock/core/ajax/jeeroborock.ajax.php',
      data: {action: 'validerCode', code: code},
      dataType: 'json',
      timeout: 30000,
      error: function (requete) {
        zoneResultat.text("{{Le démon ne répond pas.}}");
      },
      success: function (donnees) {
        if (donnees.state != 'ok') {
          zoneResultat.text(donnees.result);
          return;
        }
        zoneResultat.text("{{Authentification réussie, le compte est lié.}}");
        $('#jeeroborockEtatCompte').text("{{Compte Roborock lié}}").removeClass('label-default').addClass('label-success');
        $('#jeeroborockCodeConnexion').val('');
      },
      complete: function () {
        jeeroborockVerrouAuth = false;
        $('#bt_jeeroborockDemanderCode').removeClass('disabled');
        $('#bt_jeeroborockValiderCode').removeClass('disabled');
      }
    });
  });

  var jeeroborockVerrouTest = false;

  $('#bt_jeeroborockTesterConnexion').on('click', function () {
    if (jeeroborockVerrouTest) {
      return;
    }
    jeeroborockVerrouTest = true;
    var zoneResultat = $('#jeeroborockResultatTest');
    $('#bt_jeeroborockTesterConnexion').addClass('disabled');
    zoneResultat.text("{{Test en cours…}}");
    $.ajax({
      type: 'POST',
      url: 'plugins/jeeroborock/core/ajax/jeeroborock.ajax.php',
      data: {action: 'testerConnexion'},
      dataType: 'json',
      timeout: 30000,
      error: function (requete) {
        zoneResultat.text("{{Le démon ne répond pas.}}");
      },
      success: function (donnees) {
        if (donnees.state != 'ok') {
          zoneResultat.text(donnees.result);
          return;
        }
        zoneResultat.text(donnees.result.message);
        $('#jeeroborockEtatCompte').text(donnees.result.badge)
          .removeClass('label-success label-default label-warning')
          .addClass(donnees.result.badgeClasse);
      },
      complete: function () {
        jeeroborockVerrouTest = false;
        $('#bt_jeeroborockTesterConnexion').removeClass('disabled');
      }
    });
  });

  var jeeroborockVerrouSynchro = false;

  $('#bt_jeeroborockSynchroniser').on('click', function () {
    if (jeeroborockVerrouSynchro) {
      return;
    }
    jeeroborockVerrouSynchro = true;
    var zoneResultat = $('#jeeroborockResultatSynchro');
    $('#bt_jeeroborockSynchroniser').addClass('disabled');
    zoneResultat.text("{{Synchronisation en cours…}}");
    $.ajax({
      type: 'POST',
      url: 'plugins/jeeroborock/core/ajax/jeeroborock.ajax.php',
      data: {action: 'synchroniserEquipements'},
      dataType: 'json',
      timeout: 30000,
      error: function (requete) {
        zoneResultat.text("{{Le démon ne répond pas.}}");
      },
      success: function (donnees) {
        if (donnees.state != 'ok') {
          zoneResultat.text(donnees.result);
        } else {
          zoneResultat.text(donnees.result.message);
        }
      },
      complete: function () {
        jeeroborockVerrouSynchro = false;
        $('#bt_jeeroborockSynchroniser').removeClass('disabled');
      }
    });
  });
</script>
