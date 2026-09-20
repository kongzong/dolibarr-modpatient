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
 * \file    htdocs/custom/patient/ajax/search.php
 * \ingroup patient
 * \brief   AJAX data source of patient_select_html(): jQuery autocomplete
 *          JSON [{key, value, label}] for card no / name / phone / exact ID
 *          hash. Session-authenticated, requires 'patient read'. Never
 *          returns the ID ciphertext (search() does not select it).
 */

if (!defined('NOTOKENRENEWAL')) {
	define('NOTOKENRENEWAL', '1');
}
if (!defined('NOREQUIREMENU')) {
	define('NOREQUIREMENU', '1');
}
if (!defined('NOREQUIREHTML')) {
	define('NOREQUIREHTML', '1');
}
if (!defined('NOREQUIREAJAX')) {
	define('NOREQUIREAJAX', '1');
}

$res = 0;
if (!$res && file_exists("../../../main.inc.php")) {
	$res = @include "../../../main.inc.php";
}
if (!$res && file_exists("../../../../main.inc.php")) {
	$res = @include "../../../../main.inc.php";
}
if (!$res) {
	die("Include of main fails");
}

dol_include_once('/patient/class/patientprofile.class.php');
dol_include_once('/patient/lib/patient.lib.php');

/**
 * @var DoliDB $db
 * @var User $user
 */

if (empty($user->id) || !$user->hasRight('patient', 'read')) {
	header('HTTP/1.1 403 Forbidden');
	exit;
}

header('Content-Type: application/json; charset=utf-8');

// ajax_autocompleter sends the term as GET[<htmlname>]
$htmlname = GETPOST('htmlname', 'aZ09');
$term = $htmlname !== '' ? trim(GETPOST($htmlname, 'alphanohtml')) : '';
if ($term === '') {
	echo json_encode(array());
	$db->close();
	exit;
}

$dao = new PatientProfile($db);
$result = $dao->search($term, 20, 0, 1);
$out = array();
foreach (($result ? $result['rows'] : array()) as $row) {
	$label = $row->card_no.' - '.$row->name;
	if (!empty($row->phone)) {
		$label .= ' ('.$row->phone.')';
	}
	$out[] = array(
		'key' => (int) $row->rowid,
		'value' => $label,
		'label' => dol_escape_htmltag($label),
		'card_no' => $row->card_no,
		'name' => $row->name,
	);
}
echo json_encode($out);
$db->close();
