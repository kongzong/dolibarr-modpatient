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
 * \file    htdocs/custom/patient/admin/setup.php
 * \ingroup patient
 * \brief   Module setup / about page (phase 1: version + dictionary links).
 */

// custom/<m>/<dir>/xxx.php = 4 levels (DEV.md §二.9)
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
dol_include_once('/patient/lib/patient.lib.php');
dol_include_once('/patient/core/modules/modPatient.class.php');

/**
 * @var DoliDB $db
 * @var Translate $langs
 * @var User $user
 */

$langs->loadLangs(array("admin", "patient@patient"));

if (!$user->admin && !$user->hasRight('patient', 'admin')) {
	accessforbidden();
}

$module = new modPatient($db);

llxHeader('', $langs->trans("PatientSetup"));

$linkback = '<a href="'.DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1">'.$langs->trans("BackToModuleList").'</a>';
print load_fiche_titre($langs->trans("PatientSetup"), $linkback, 'title_setup');

$head = patient_admin_prepare_head();
print dol_get_fiche_head($head, 'settings', $langs->trans("ModulePatientName"), -1, 'user');

print '<div class="fichecenter">';
print '<p>'.$langs->trans("PatientAboutText", $module->version).'</p>';
print '<p><a href="'.DOL_URL_ROOT.'/admin/dict.php">'.$langs->trans("Dictionaries").'</a>: ';
print $langs->trans("PatientDictDepartment").' / '.$langs->trans("PatientDictIdType").' / ';
print $langs->trans("PatientDictAllergyType").' / '.$langs->trans("PatientDictDoctorTitle").'</p>';
print '</div>';

print dol_get_fiche_end();

llxFooter();
$db->close();
