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
 * \file    htdocs/custom/patient/admin/doctors.php
 * \ingroup patient
 * \brief   Doctor <-> department/title maintenance (admin). A doctor is a
 *          Dolibarr user; scheduling belongs to modClinicBook.
 */

$res = 0;
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) {
	$res = @include $_SERVER["CONTEXT_DOCUMENT_ROOT"]."/main.inc.php";
}
if (!$res && file_exists("../../../main.inc.php")) {
	$res = @include "../../../main.inc.php";
}
if (!$res && file_exists("../../../../main.inc.php")) {
	$res = @include "../../../../main.inc.php";
}
if (!$res) {
	die("Include of main fails");
}

require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/html.form.class.php';
dol_include_once('/patient/lib/patient.lib.php');

/**
 * @var Conf $conf
 * @var DoliDB $db
 * @var Translate $langs
 * @var User $user
 */

$langs->loadLangs(array("admin", "users", "patient@patient"));

if (!$user->admin && !$user->hasRight('patient', 'admin')) {
	accessforbidden();
}

$action = GETPOST('action', 'aZ09');
$form = new Form($db);
$entity = (int) $conf->entity;

$departments = patient_dict_rows($db, 'c_patient_department'); // rowid => label
$titles = patient_dict_options($db, 'c_patient_doctor_title');  // code => label


/*
 * Actions
 */

if ($action == 'save') {
	$fkUser = GETPOSTINT('fk_user');
	$fkDepartment = GETPOSTINT('fk_department');
	$titleCode = GETPOST('title_code', 'aZ09');
	if ($fkUser <= 0) {
		setEventMessages($langs->trans("ErrorFieldRequired", $langs->transnoentitiesnoconv("User")), null, 'errors');
	} else {
		// Upsert on the unique fk_user key (DEV.md §二.14)
		$sql = "INSERT INTO ".$db->prefix()."patient_doctor (entity, fk_user, fk_department, title_code, status, date_creation)";
		$sql .= " VALUES (".$entity.", ".$fkUser.", ".($fkDepartment > 0 ? $fkDepartment : 'NULL').",";
		$sql .= " ".($titleCode !== '' ? "'".$db->escape($titleCode)."'" : 'NULL').", 1, '".$db->idate(dol_now())."')";
		$sql .= " ON DUPLICATE KEY UPDATE fk_department = VALUES(fk_department), title_code = VALUES(title_code), status = 1";
		if ($db->query($sql)) {
			setEventMessages($langs->trans("RecordSaved"), null, 'mesgs');
		} else {
			setEventMessages($db->lasterror(), null, 'errors');
		}
	}
	$action = '';
}

if ($action == 'disable' || $action == 'enable') {
	$rowid = GETPOSTINT('rowid');
	if ($rowid > 0) {
		$sql = "UPDATE ".$db->prefix()."patient_doctor SET status = ".($action == 'enable' ? 1 : 0)." WHERE rowid = ".$rowid." AND entity = ".$entity;
		if ($db->query($sql)) {
			setEventMessages($langs->trans("RecordSaved"), null, 'mesgs');
		} else {
			setEventMessages($db->lasterror(), null, 'errors');
		}
	}
	$action = '';
}


/*
 * View
 */

llxHeader('', $langs->trans("PatientDoctors"));

$linkback = '<a href="'.DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1">'.$langs->trans("BackToModuleList").'</a>';
print load_fiche_titre($langs->trans("PatientDoctors"), $linkback, 'title_setup');

$head = patient_admin_prepare_head();
print dol_get_fiche_head($head, 'doctors', $langs->trans("ModulePatientName"), -1, 'user');

print '<p class="opacitymedium">'.$langs->trans("PatientDoctorsIntro").'</p>';

// Add / update form
print '<form method="POST" action="'.$_SERVER["PHP_SELF"].'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="save">';
print '<table class="border centpercent">';
print '<tr class="liste_titre"><td colspan="2">'.$langs->trans("PatientDoctorAdd").'</td></tr>';
print '<tr><td class="titlefieldcreate fieldrequired">'.$langs->trans("User").'</td><td>';
print $form->select_dolusers(GETPOSTINT('fk_user'), 'fk_user', 1, null, 0, '', '', 0, 0, 0, '', 0, '', 'minwidth300');
print '</td></tr>';
print '<tr><td>'.$langs->trans("PatientDictDepartment").'</td><td>';
print $form->selectarray('fk_department', $departments, GETPOSTINT('fk_department'), 1, 0, 0, '', 0, 0, 0, '', 'minwidth200');
print '</td></tr>';
print '<tr><td>'.$langs->trans("PatientDictDoctorTitle").'</td><td>';
print $form->selectarray('title_code', $titles, GETPOST('title_code', 'aZ09'), 1, 0, 0, '', 0, 0, 0, '', 'minwidth200');
print '</td></tr>';
print '</table>';
print '<div class="center"><input type="submit" class="button button-save" value="'.$langs->trans("Save").'"></div>';
print '</form><br>';

// List
$sql = "SELECT d.rowid, d.fk_user, d.fk_department, d.title_code, d.status, u.login, u.lastname, u.firstname, u.statut as user_status";
$sql .= " FROM ".$db->prefix()."patient_doctor as d";
$sql .= " INNER JOIN ".$db->prefix()."user as u ON u.rowid = d.fk_user";
$sql .= " WHERE d.entity = ".$entity;
$sql .= " ORDER BY d.status DESC, d.fk_department ASC, u.lastname ASC";
$resql = $db->query($sql);
if (!$resql) {
	dol_print_error($db);
	exit;
}

print '<div class="div-table-responsive-no-min">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre">';
print '<th>'.$langs->trans("User").'</th>';
print '<th>'.$langs->trans("Login").'</th>';
print '<th>'.$langs->trans("PatientDictDepartment").'</th>';
print '<th>'.$langs->trans("PatientDictDoctorTitle").'</th>';
print '<th class="center">'.$langs->trans("Status").'</th>';
print '<th></th>';
print '</tr>';
if ($db->num_rows($resql) == 0) {
	print '<tr><td colspan="6"><span class="opacitymedium">'.$langs->trans("NoRecordFound").'</span></td></tr>';
}
while ($obj = $db->fetch_object($resql)) {
	print '<tr class="oddeven"'.($obj->status ? '' : ' style="opacity:.55"').'>';
	print '<td>'.dol_escape_htmltag(trim($obj->lastname.' '.$obj->firstname)).($obj->user_status ? '' : ' <span class="opacitymedium">('.$langs->trans("Disabled").')</span>').'</td>';
	print '<td>'.dol_escape_htmltag($obj->login).'</td>';
	print '<td>'.dol_escape_htmltag(isset($departments[$obj->fk_department]) ? $departments[$obj->fk_department] : '').'</td>';
	print '<td>'.dol_escape_htmltag(isset($titles[$obj->title_code]) ? $titles[$obj->title_code] : (string) $obj->title_code).'</td>';
	print '<td class="center">'.($obj->status ? $langs->trans("Enabled") : $langs->trans("Disabled")).'</td>';
	print '<td class="right">';
	$toggle = $obj->status ? 'disable' : 'enable';
	print '<a class="reposition" href="'.$_SERVER["PHP_SELF"].'?action='.$toggle.'&rowid='.$obj->rowid.'&token='.newToken().'">'.$langs->trans($obj->status ? "Disable" : "Enable").'</a>';
	print '</td></tr>';
}
$db->free($resql);
print '</table></div>';

print dol_get_fiche_end();

llxFooter();
$db->close();
