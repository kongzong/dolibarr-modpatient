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
 * \file    htdocs/custom/patient/allergies.php
 * \ingroup patient
 * \brief   Allergy tab of the patient card. Requires 'profile' for everything:
 *          without it the allergy table is not even queried (spec §2.4).
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
dol_include_once('/patient/class/patientallergy.class.php');
dol_include_once('/patient/lib/patient.lib.php');

/**
 * @var Conf $conf
 * @var DoliDB $db
 * @var Translate $langs
 * @var User $user
 */

$langs->loadLangs(array("companies", "products", "patient@patient"));

$id = GETPOSTINT('id');
$action = GETPOST('action', 'aZ09');
$confirm = GETPOST('confirm', 'alpha');

if (!$user->hasRight('patient', 'read') || !$user->hasRight('patient', 'profile')) {
	accessforbidden($langs->trans("PatientNoProfileRight"));
}

$object = new PatientProfile($db);
if ($id <= 0 || $object->fetch($id) <= 0) {
	accessforbidden($langs->trans("PatientNotYet"));
}

$form = new Form($db);
$allergyTypes = patient_dict_options($db, 'c_patient_allergy_type');
$severities = array(1 => PatientAllergy::severityLabel(1), 2 => PatientAllergy::severityLabel(2), 3 => PatientAllergy::severityLabel(3));


/*
 * Actions
 */

if ($action == 'add') {
	$allergy = new PatientAllergy($db);
	$allergy->fk_patient = $object->id;
	$allergy->allergy_type = GETPOST('allergy_type', 'aZ09');
	$allergy->fk_product = GETPOSTINT('fk_product');
	$allergy->name = GETPOST('name', 'alphanohtml');
	$allergy->severity = GETPOSTINT('severity');
	$allergy->reaction = GETPOST('reaction', 'alphanohtml');
	if (trim($allergy->name) === '' && $allergy->fk_product > 0) {
		// Default the name to the product label so name matching works later
		require_once DOL_DOCUMENT_ROOT.'/product/class/product.class.php';
		$product = new Product($db);
		if ($product->fetch($allergy->fk_product) > 0) {
			$allergy->name = $product->label;
		}
	}
	if (trim((string) $allergy->name) === '') {
		setEventMessages($langs->trans("ErrorFieldRequired", $langs->transnoentitiesnoconv("Name")), null, 'errors');
	} elseif ($allergy->create($user) > 0) {
		setEventMessages($langs->trans("RecordSaved"), null, 'mesgs');
		header("Location: ".$_SERVER["PHP_SELF"]."?id=".$object->id);
		exit;
	} else {
		setEventMessages($langs->trans($allergy->error), $allergy->errors, 'errors');
	}
	$action = '';
}

if ($action == 'confirm_remove' && $confirm == 'yes') {
	$allergy = new PatientAllergy($db);
	$aid = GETPOSTINT('aid');
	if ($aid > 0 && $allergy->fetch($aid) > 0 && $allergy->fk_patient == $object->id && $allergy->status == 1) {
		if ($allergy->remove($user) > 0) {
			setEventMessages($langs->trans("RecordSaved"), null, 'mesgs');
		} else {
			setEventMessages($langs->trans($allergy->error), $allergy->errors, 'errors');
		}
	}
	header("Location: ".$_SERVER["PHP_SELF"]."?id=".$object->id);
	exit;
}

// Opening the allergy tab is a medical-data read (spec §2.5)
patient_audit($db, $object->id, 'READ_PROFILE', $user, array('via' => 'allergies'));


/*
 * View
 */

llxHeader('', $langs->trans("PatientAllergies"));

$head = patient_prepare_head($object);
print dol_get_fiche_head($head, 'allergies', $langs->trans("PatientTab"), -1, 'user');

$linkback = '<a href="'.dol_buildpath('/patient/list.php', 1).'?restore_lastsearch_values=1">'.$langs->trans("BackToList").'</a>';
print '<div class="arearef heightref valignmiddle centpercent">';
print '<div class="inline-block floatleft refid refidpadding">'.img_picto('', 'user', 'class="pictofixedwidth"').'<strong>'.dol_escape_htmltag($object->card_no).'</strong>';
print ($object->thirdparty ? ' - '.dol_escape_htmltag($object->thirdparty->name) : '').'</div>';
print '<div class="inline-block floatright">'.$linkback.'</div>';
print '<div class="clearboth"></div></div>';
print '<div class="underbanner clearboth"></div>';

if ($action == 'remove') {
	$aid = GETPOSTINT('aid');
	print $form->formconfirm($_SERVER["PHP_SELF"].'?id='.$object->id.'&aid='.$aid, $langs->trans("Remove"), $langs->trans("PatientAllergyConfirmRemove"), 'confirm_remove', '', 0, 1);
}

$dao = new PatientAllergy($db);
$rows = $dao->fetchAllByPatient($object->id, true);
if ($rows === null) {
	dol_print_error($db, $dao->error);
	exit;
}

print '<div class="div-table-responsive-no-min">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre">';
print '<th>'.$langs->trans("Type").'</th>';
print '<th>'.$langs->trans("Name").'</th>';
print '<th>'.$langs->trans("Product").'</th>';
print '<th>'.$langs->trans("PatientSeverity").'</th>';
print '<th>'.$langs->trans("PatientReaction").'</th>';
print '<th>'.$langs->trans("DateCreation").'</th>';
print '<th class="center">'.$langs->trans("Status").'</th>';
print '<th></th>';
print '</tr>';

if (empty($rows)) {
	print '<tr><td colspan="8"><span class="opacitymedium">'.$langs->trans("NoRecordFound").'</span></td></tr>';
}
foreach ($rows as $row) {
	$rowClass = 'oddeven';
	if ($row->status && $row->severity >= 3) {
		$rowClass .= ' error';
	}
	print '<tr class="'.$rowClass.'"'.($row->status ? '' : ' style="opacity:.55"').'>';
	print '<td>'.dol_escape_htmltag(isset($allergyTypes[$row->allergy_type]) ? $allergyTypes[$row->allergy_type] : $row->allergy_type).'</td>';
	print '<td>'.($row->severity >= 3 && $row->status ? '<strong>' : '').dol_escape_htmltag($row->name).($row->severity >= 3 && $row->status ? '</strong>' : '').'</td>';
	print '<td>'.($row->fk_product ? dol_escape_htmltag(trim($row->product_ref.' '.$row->product_label)) : '').'</td>';
	print '<td>'.PatientAllergy::severityLabel($row->severity).'</td>';
	print '<td>'.dol_escape_htmltag((string) $row->reaction).'</td>';
	print '<td>'.dol_print_date($row->date_creation, 'day').'</td>';
	print '<td class="center">'.($row->status ? $langs->trans("Enabled") : $langs->trans("PatientAllergyRemoved")).'</td>';
	print '<td class="right">';
	if ($row->status) {
		print '<a class="reposition" href="'.$_SERVER["PHP_SELF"].'?id='.$object->id.'&aid='.$row->id.'&action=remove&token='.newToken().'">'.img_delete($langs->trans("Remove")).'</a>';
	}
	print '</td></tr>';
}
print '</table></div>';

// Add form
print '<br>';
print '<form method="POST" action="'.$_SERVER["PHP_SELF"].'?id='.$object->id.'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="add">';
print '<table class="border centpercent">';
print '<tr class="liste_titre"><td colspan="2">'.$langs->trans("PatientAllergyAdd").'</td></tr>';
print '<tr><td class="titlefieldcreate">'.$langs->trans("Type").'</td><td>';
print $form->selectarray('allergy_type', $allergyTypes, GETPOST('allergy_type', 'aZ09') ?: 'DRUG', 0, 0, 0, '', 0, 0, 0, '', 'minwidth150');
print '</td></tr>';
print '<tr><td>'.$langs->trans("Product").'</td><td>';
if (isModEnabled('product')) {
	print $form->select_produits(GETPOSTINT('fk_product'), 'fk_product', '', 0, 0, 1, 2, '', 1, array(), 0, '1', 0, 'minwidth300');
	print '<br><span class="opacitymedium">'.$langs->trans("PatientAllergyProductHint").'</span>';
} else {
	print '<span class="opacitymedium">'.$langs->trans("PatientAllergyNoProductModule").'</span>';
}
print '</td></tr>';
print '<tr><td class="fieldrequired">'.$langs->trans("Name").'</td>';
print '<td><input type="text" name="name" class="minwidth300" maxlength="128" value="'.dol_escape_htmltag(GETPOST('name', 'alphanohtml')).'"></td></tr>';
print '<tr><td>'.$langs->trans("PatientSeverity").'</td><td>';
print $form->selectarray('severity', $severities, GETPOSTINT('severity') ?: 1, 0, 0, 0, '', 0, 0, 0, '', 'minwidth100');
print '</td></tr>';
print '<tr><td>'.$langs->trans("PatientReaction").'</td>';
print '<td><input type="text" name="reaction" class="minwidth300" maxlength="255" value="'.dol_escape_htmltag(GETPOST('reaction', 'alphanohtml')).'"></td></tr>';
print '</table>';
print '<div class="center"><input type="submit" class="button button-add" value="'.$langs->trans("Add").'"></div>';
print '</form>';

print dol_get_fiche_end();

llxFooter();
$db->close();
