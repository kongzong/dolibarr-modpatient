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
 * \file    htdocs/custom/patient/list.php
 * \ingroup patient
 * \brief   Patient list: search by card no / name / phone / exact ID number
 *          (hashed). Never selects or shows the ID ciphertext.
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

require_once DOL_DOCUMENT_ROOT.'/core/class/html.form.class.php';
dol_include_once('/patient/class/patientprofile.class.php');
dol_include_once('/patient/lib/patient.lib.php');

/**
 * @var Conf $conf
 * @var DoliDB $db
 * @var Translate $langs
 * @var User $user
 */

$langs->loadLangs(array("companies", "patient@patient"));

if (!$user->hasRight('patient', 'read')) {
	accessforbidden();
}

$search = trim(GETPOST('search', 'alphanohtml'));
$searchStatus = GETPOST('search_status', 'alpha');
$status = ($searchStatus === '0' || $searchStatus === '1') ? (int) $searchStatus : -1;
if (GETPOST('button_removefilter_x', 'alpha') || GETPOST('button_removefilter', 'alpha')) {
	$search = '';
	$status = -1;
}

// Pagination (DEV.md §二.6/7): total count to print_barre_liste, page cast, limit carried
$limit = GETPOSTINT('limit') > 0 ? GETPOSTINT('limit') : $conf->liste_limit;
$page = (int) GETPOST('page', 'int');
if ($page < 0) {
	$page = 0;
}
$offset = $limit * $page;

$patient = new PatientProfile($db);
$result = $patient->search($search, $limit, $offset, $status);
if ($result === null) {
	dol_print_error($db, $patient->error);
	exit;
}
$total = $result['total'];
$rows = $result['rows'];

$genders = array('U' => $langs->trans("PatientGenderU"), 'M' => $langs->trans("PatientGenderM"), 'F' => $langs->trans("PatientGenderF"));

llxHeader('', $langs->trans("PatientList"));

$param = '&limit='.(int) $limit;
if ($search !== '') {
	$param .= '&search='.urlencode($search);
}
if ($status >= 0) {
	$param .= '&search_status='.$status;
}

$newcardbutton = '';
if ($user->hasRight('patient', 'write')) {
	$newcardbutton = dolGetButtonTitle($langs->trans("PatientNew"), '', 'fa fa-plus-circle', dol_buildpath('/patient/card.php', 1).'?action=create');
}

// Form opened before print_barre_liste so the per-page selector submits it
print '<form method="GET" action="'.$_SERVER["PHP_SELF"].'" name="formpatientlist">';
print '<input type="hidden" name="token" value="'.newToken().'">';

print_barre_liste($langs->trans("PatientList"), $page, $_SERVER["PHP_SELF"], $param, '', '', '', $total, $total, 'user', 0, $newcardbutton, '', $limit, 0, 0, 1);

print '<div class="div-table-responsive">';
print '<table class="tagtable liste centpercent">';

print '<tr class="liste_titre_filter">';
print '<td class="liste_titre" colspan="4"><input type="text" name="search" class="minwidth300" placeholder="'.dol_escape_htmltag($langs->trans("PatientSearchHint")).'" value="'.dol_escape_htmltag($search).'"></td>';
print '<td class="liste_titre"></td>';
print '<td class="liste_titre center">';
print Form::selectarray('search_status', array('1' => $langs->trans("Enabled"), '0' => $langs->trans("Disabled")), $status >= 0 ? (string) $status : '', 1, 0, 0, '', 0, 0, 0, '', 'maxwidth100');
print '</td>';
print '<td class="liste_titre center maxwidthsearch">';
print '<button type="submit" class="liste_titre button_search reposition" name="button_search" value="x"><span class="fa fa-search"></span></button>';
print '<button type="submit" class="liste_titre button_removefilter reposition" name="button_removefilter" value="x"><span class="fa fa-remove"></span></button>';
print '</td></tr>';

print '<tr class="liste_titre">';
print '<th>'.$langs->trans("PatientCardNo").'</th>';
print '<th>'.$langs->trans("Name").'</th>';
print '<th>'.$langs->trans("PatientGender").'</th>';
print '<th>'.$langs->trans("PatientBirthDate").'</th>';
print '<th>'.$langs->trans("Phone").'</th>';
print '<th class="center">'.$langs->trans("Status").'</th>';
print '<th class="center">'.$langs->trans("PatientIdNumberMasked").'</th>';
print '</tr>';

if (empty($rows)) {
	print '<tr><td colspan="7"><span class="opacitymedium">'.$langs->trans("NoRecordFound").'</span></td></tr>';
}
foreach ($rows as $row) {
	$url = dol_buildpath('/patient/card.php', 1).'?id='.((int) $row->rowid);
	print '<tr class="oddeven">';
	print '<td><a href="'.$url.'">'.img_picto('', 'user', 'class="pictofixedwidth"').dol_escape_htmltag($row->card_no).'</a></td>';
	print '<td><a href="'.$url.'">'.dol_escape_htmltag($row->name).'</a></td>';
	print '<td>'.(isset($genders[$row->gender]) ? $genders[$row->gender] : '').'</td>';
	print '<td>'.($row->birth_date ? dol_escape_htmltag(substr($row->birth_date, 0, 10)) : '').'</td>';
	print '<td>'.dol_escape_htmltag((string) $row->phone).'</td>';
	print '<td class="center">'.($row->status ? $langs->trans("Enabled") : $langs->trans("Disabled")).'</td>';
	print '<td class="center">'.dol_escape_htmltag(patient_mask_id($row->id_number_tail)).'</td>';
	print '</tr>';
}

print '</table>';
print '</div>';
print '</form>';

llxFooter();
$db->close();
