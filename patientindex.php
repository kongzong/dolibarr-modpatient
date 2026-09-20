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
 * \file    htdocs/custom/patient/patientindex.php
 * \ingroup patient
 * \brief   Home page of the Clinic top menu (phase 1: overview placeholder).
 */

// Load Dolibarr environment (custom/<m>/xxx.php = 3 levels, DEV.md §二.9)
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

/**
 * @var DoliDB $db
 * @var Translate $langs
 * @var User $user
 */

$langs->loadLangs(array("patient@patient"));

if (!$user->hasRight('patient', 'read')) {
	accessforbidden();
}

llxHeader('', $langs->trans("ModulePatientName"));

print load_fiche_titre($langs->trans("ModulePatientName"), '', 'user');

print '<div class="fichecenter">';
print $langs->trans("PatientIndexIntro");
print '</div>';

llxFooter();
$db->close();
