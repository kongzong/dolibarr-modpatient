<?php
/* Copyright (C) 2026  modPatient contributors
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

/**
 * \file    htdocs/custom/patient/tab.php
 * \ingroup patient
 * \brief   "Patient record" tab on the thirdparty card: shows the record when
 *          it exists, offers registration for individual thirdparties.
 */

$res = 0;
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) {
	$res = @include $_SERVER["CONTEXT_DOCUMENT_ROOT"]."/main.inc.php";
}
if (!$res && file_exists("../../main.inc.php")) {
	$res = @include "../../main.inc.php";
}
if (!$res && file_exists("../../../main.inc.php")) {
	$res = @include "../../../main.inc.php";
}
if (!$res) {
	die("Include of main fails");
}

require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/company.lib.php';
dol_include_once('/patient/class/patientprofile.class.php');
dol_include_once('/patient/lib/patient.lib.php');

/**
 * @var DoliDB $db
 * @var Translate $langs
 * @var User $user
 */

$langs->loadLangs(array("companies", "patient@patient"));

$socid = GETPOSTINT('id');
$action = GETPOST('action', 'aZ09');
if ($socid <= 0 || !$user->hasRight('societe', 'lire') || !$user->hasRight('patient', 'read')) {
	accessforbidden();
}

$object = new Societe($db);
if ($object->fetch($socid) <= 0) {
	dol_print_error($db, $object->error);
	exit;
}
$result = restrictedArea($user, 'societe', $socid, '&societe');

$patient = new PatientProfile($db);
$hasPatient = $patient->fetch(0, $socid) > 0;

// Register this thirdparty as a patient (write permission, POST + token)
if ($action == 'createpatient' && !$hasPatient && $user->hasRight('patient', 'write')) {
	$patient = new PatientProfile($db);
	$result = $patient->createForThirdparty($user, $socid);
	if ($result > 0) {
		setEventMessages($langs->trans("PatientCreated", $patient->card_no), null, 'mesgs');
		header("Location: ".dol_buildpath('/patient/card.php', 1).'?id='.$patient->id);
		exit;
	}
	setEventMessages($langs->trans($patient->error), $patient->errors, 'errors');
}

llxHeader('', $langs->trans("PatientTab"));

$head = societe_prepare_head($object);
print dol_get_fiche_head($head, 'patient', $langs->trans("ThirdParty"), -1, 'company');

$linkback = '<a href="'.DOL_URL_ROOT.'/societe/list.php?restore_lastsearch_values=1">'.$langs->trans("BackToList").'</a>';
dol_banner_tab($object, 'id', $linkback, ($user->socid ? 0 : 1), 'rowid', 'nom');

print '<div class="fichecenter">';
print '<div class="underbanner clearboth"></div>';

if ($hasPatient) {
	$genders = array('U' => $langs->trans("PatientGenderU"), 'M' => $langs->trans("PatientGenderM"), 'F' => $langs->trans("PatientGenderF"));
	print '<table class="border tableforfield centpercent">';
	print '<tr><td class="titlefield">'.$langs->trans("PatientCardNo").'</td><td>'.$patient->getNomUrl(1).'</td></tr>';
	print '<tr><td>'.$langs->trans("PatientGender").'</td><td>'.$genders[$patient->gender].'</td></tr>';
	print '<tr><td>'.$langs->trans("PatientBirthDate").'</td><td>'.dol_escape_htmltag($patient->birth_date).'</td></tr>';
	print '<tr><td>'.$langs->trans("PatientIdNumberMasked").'</td><td>'.dol_escape_htmltag($patient->getIdNumberMasked()).'</td></tr>';
	print '<tr><td>'.$langs->trans("Status").'</td><td>'.$patient->getLibStatut().'</td></tr>';
	print '</table>';
	print '<div class="tabsAction">';
	print dolGetButtonAction($langs->trans("PatientTab"), '', 'default', dol_buildpath('/patient/card.php', 1).'?id='.$patient->id, '', 1);
	print '</div>';
} elseif ((int) $object->typent_id !== PatientProfile::TYPENT_INDIVIDUAL) {
	print '<div class="opacitymedium">'.$langs->trans("PatientNotIndividual").'</div>';
} else {
	print '<div class="opacitymedium">'.$langs->trans("PatientNotYet").'</div>';
	if ($user->hasRight('patient', 'write')) {
		print '<form method="POST" action="'.$_SERVER["PHP_SELF"].'?id='.$socid.'">';
		print '<input type="hidden" name="token" value="'.newToken().'">';
		print '<input type="hidden" name="action" value="createpatient">';
		print '<div class="tabsAction"><input type="submit" class="butAction" value="'.$langs->trans("PatientCreateProfile").'"></div>';
		print '</form>';
	}
}
print '</div>';

print dol_get_fiche_end();

llxFooter();
$db->close();
