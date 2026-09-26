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
 * \file    htdocs/custom/patient/card.php
 * \ingroup patient
 * \brief   Patient record: register (thirdparty + card number in one
 *          transaction), view, edit, enable/disable.
 *          Medical history and the plain ID number require 'profile'.
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
require_once DOL_DOCUMENT_ROOT.'/core/class/html.formcompany.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/company.lib.php';
dol_include_once('/patient/class/patientprofile.class.php');
dol_include_once('/patient/lib/patient.lib.php');

/**
 * @var Conf $conf
 * @var DoliDB $db
 * @var Translate $langs
 * @var User $user
 * @var Societe $mysoc
 */

$langs->loadLangs(array("companies", "errors", "patient@patient"));

$id = GETPOSTINT('id');
$socid = GETPOSTINT('socid');
$action = GETPOST('action', 'aZ09');
$confirm = GETPOST('confirm', 'alpha');
$backtopage = GETPOST('backtopage', 'alpha');

$canRead = $user->hasRight('patient', 'read');
$canWrite = $user->hasRight('patient', 'write');
$canProfile = $user->hasRight('patient', 'profile');
$canAdmin = $user->hasRight('patient', 'admin');

if (!$canRead) {
	accessforbidden();
}

$form = new Form($db);
$formcompany = new FormCompany($db);
$object = new PatientProfile($db);

if ($id > 0 || $socid > 0) {
	$res = $object->fetch($id, $socid);
	if ($res < 0) {
		dol_print_error($db, $object->error);
		exit;
	}
	if ($res == 0 && $id > 0) {
		accessforbidden($langs->trans("PatientNotYet"));
	}
	if ($res > 0) {
		$id = $object->id;
	}
}

/**
 * Read the shared form fields into arrays for create()/update().
 *
 * @param	PatientProfile	$object	Target object (profile fields are set on it)
 * @return	array{soc:array<string,mixed>,idnumber:string|null,errors:string[]}
 */
function patient_read_form(PatientProfile $object)
{
	global $langs, $mysoc;

	$errors = array();
	$soc = array(
		'name' => GETPOST('name', 'alphanohtml'),
		'phone' => GETPOST('phone', 'alphanohtml'),
		'email' => GETPOST('email', 'alphanohtml'),
		'address' => GETPOST('address', 'alphanohtml'),
		'zip' => GETPOST('zipcode', 'alphanohtml'),
		'town' => GETPOST('town', 'alphanohtml'),
		'state_id' => GETPOSTINT('state_id'),
		'country_id' => GETPOSTINT('country_id') > 0 ? GETPOSTINT('country_id') : (int) $mysoc->country_id,
	);
	if (trim($soc['name']) === '') {
		$errors[] = $langs->trans("ErrorFieldRequired", $langs->transnoentitiesnoconv("Name"));
	}

	$object->gender = GETPOST('gender', 'aZ');
	$object->id_type = GETPOST('id_type', 'aZ09');
	$object->blood_type = GETPOST('blood_type', 'alphanohtml');
	$object->phone_alt = GETPOST('phone_alt', 'alphanohtml');
	$object->emergency_name = GETPOST('emergency_name', 'alphanohtml');
	$object->emergency_phone = GETPOST('emergency_phone', 'alphanohtml');
	$object->note_private = GETPOST('note_private', 'restricthtml');

	$year = GETPOSTINT('birthyear');
	$month = GETPOSTINT('birthmonth');
	$day = GETPOSTINT('birthday');
	if ($year > 0 && $month > 0 && $day > 0) {
		if (!checkdate($month, $day, $year)) {
			$errors[] = $langs->trans("ErrorBadDateFormat");
		}
		$object->birth_date = sprintf('%04d-%02d-%02d', $year, $month, $day);
	} else {
		$object->birth_date = '';
	}

	// ID number: plaintext only in this request; '' keeps the stored value on edit
	$idnumber = null;
	if (GETPOSTISSET('id_number')) {
		$raw = trim(GETPOST('id_number', 'alphanohtml'));
		if ($raw !== '' || GETPOSTINT('id_number_clear')) {
			$idnumber = $raw;
			if ($raw !== '' && $object->id_type === 'IDCARD' && !patient_validate_prc_id($raw)) {
				$errors[] = $langs->trans("PatientIdNumberInvalid");
			}
			if ($raw !== '' && $object->id_type === 'IDCARD' && $object->birth_date === '') {
				$object->birth_date = patient_birth_from_prc_id($raw);
			}
		}
	}

	return array('soc' => $soc, 'idnumber' => $idnumber, 'errors' => $errors);
}


/*
 * Actions
 */

if ($action == 'add' && $canWrite) {
	if (GETPOST('cancel', 'alpha')) {
		header("Location: ".dol_buildpath('/patient/list.php', 1));
		exit;
	}
	$read = patient_read_form($object);
	if ($canProfile) {
		$object->history_note = GETPOST('history_note', 'restricthtml');
	}
	if (!empty($read['errors'])) {
		setEventMessages('', $read['errors'], 'errors');
		$action = 'create';
	} else {
		if ($read['idnumber'] !== null) {
			$object->setIdNumber($read['idnumber']);
		}
		$result = $object->create($user, $read['soc']);
		if ($result > 0) {
			setEventMessages($langs->trans("PatientCreated", $object->card_no), null, 'mesgs');
			header("Location: ".$_SERVER["PHP_SELF"]."?id=".$object->id);
			exit;
		}
		setEventMessages($langs->trans($object->error), $object->errors, 'errors');
		$action = 'create';
	}
}

if ($action == 'update' && $canWrite && $object->id > 0) {
	if (GETPOST('cancel', 'alpha')) {
		header("Location: ".$_SERVER["PHP_SELF"]."?id=".$object->id);
		exit;
	}
	$previousHistory = $object->history_note;
	$read = patient_read_form($object);
	$object->history_note = $canProfile ? GETPOST('history_note', 'restricthtml') : $previousHistory;
	if (!empty($read['errors'])) {
		setEventMessages('', $read['errors'], 'errors');
		$action = 'edit';
	} else {
		// Only 'profile' may change the ID number
		$newId = $canProfile ? $read['idnumber'] : null;
		$result = $object->update($user, $read['soc'], $newId);
		if ($result > 0) {
			setEventMessages($langs->trans("RecordSaved"), null, 'mesgs');
			header("Location: ".$_SERVER["PHP_SELF"]."?id=".$object->id);
			exit;
		}
		setEventMessages($langs->trans($object->error), $object->errors, 'errors');
		$action = 'edit';
	}
}

if ($action == 'confirm_setstatus' && $confirm == 'yes' && $canAdmin && $object->id > 0) {
	$result = $object->setStatus($user, GETPOSTINT('status'));
	if ($result > 0) {
		setEventMessages($langs->trans("RecordSaved"), null, 'mesgs');
	} else {
		setEventMessages($langs->trans($object->error), $object->errors, 'errors');
	}
	header("Location: ".$_SERVER["PHP_SELF"]."?id=".$object->id);
	exit;
}

// Reveal the plain ID number once, with an audit row (spec §2.3 / §2.5)
$plainId = '';
if ($action == 'showid' && $canProfile && $object->id > 0) {
	$plainId = $object->getIdNumberPlain();
	patient_audit($db, $object->id, 'READ_IDNUMBER', $user, array('via' => 'card'));
	$action = '';
}

// Medical data (history + allergies) is only queried for 'profile' holders,
// and that read is itself audited (spec §2.4 / §2.5)
$allergies = array();
if ($canProfile && $object->id > 0 && $action != 'edit') {
	dol_include_once('/patient/class/patientallergy.class.php');
	$allergyDao = new PatientAllergy($db);
	$allergies = $allergyDao->fetchAllByPatient($object->id);
	if ($allergies === null) {
		$allergies = array();
	}
	patient_audit($db, $object->id, 'READ_PROFILE', $user, array('via' => 'card'));
}


/*
 * View
 */

$title = $langs->trans("PatientTab");
if ($action == 'create') {
	$title = $langs->trans("PatientNew");
}
llxHeader('', $title);

$idTypes = patient_dict_options($db, 'c_patient_id_type');
$genders = array('U' => $langs->trans("PatientGenderU"), 'M' => $langs->trans("PatientGenderM"), 'F' => $langs->trans("PatientGenderF"));

/**
 * Shared editable rows for create/edit forms.
 *
 * @param	PatientProfile	$object		Object (empty on create)
 * @param	bool			$isCreate	Create mode
 * @return	void
 */
function patient_print_form_rows(PatientProfile $object, $isCreate)
{
	global $langs, $form, $formcompany, $mysoc, $idTypes, $genders, $canProfile;

	$soc = $object->thirdparty;
	$v = function ($field, $default = '') use ($soc) {
		if (GETPOSTISSET($field)) {
			return GETPOST($field, 'alphanohtml');
		}
		return $soc ? (string) $soc->$field : $default;
	};

	print '<tr><td class="titlefieldcreate fieldrequired">'.$langs->trans("Name").'</td>';
	print '<td><input type="text" name="name" class="minwidth300" maxlength="128" value="'.dol_escape_htmltag($v('name')).'" autofocus></td></tr>';

	print '<tr><td>'.$langs->trans("PatientGender").'</td><td>';
	print $form->selectarray('gender', $genders, GETPOSTISSET('gender') ? GETPOST('gender', 'aZ') : $object->gender, 0, 0, 0, '', 0, 0, 0, '', 'minwidth100');
	print '</td></tr>';

	print '<tr><td>'.$langs->trans("PatientBirthDate").'</td><td>';
	$birthTs = '';
	if (GETPOSTINT('birthyear') > 0) {
		$birthTs = dol_mktime(0, 0, 0, GETPOSTINT('birthmonth'), GETPOSTINT('birthday'), GETPOSTINT('birthyear'));
	} elseif ($object->birth_date !== '') {
		$birthTs = strtotime($object->birth_date.' 00:00:00');
	}
	print $form->selectDate($birthTs, 'birth', 0, 0, 1, 'formpatient', 1, 0);
	print '</td></tr>';

	print '<tr><td>'.$langs->trans("Phone").'</td>';
	print '<td><input type="text" name="phone" class="minwidth200" maxlength="32" value="'.dol_escape_htmltag($v('phone')).'"></td></tr>';
	print '<tr><td>'.$langs->trans("PatientPhoneAlt").'</td>';
	print '<td><input type="text" name="phone_alt" class="minwidth200" maxlength="32" value="'.dol_escape_htmltag(GETPOSTISSET('phone_alt') ? GETPOST('phone_alt', 'alphanohtml') : (string) $object->phone_alt).'"></td></tr>';
	print '<tr><td>'.$langs->trans("Email").'</td>';
	print '<td><input type="text" name="email" class="minwidth200" maxlength="128" value="'.dol_escape_htmltag($v('email')).'"></td></tr>';

	// ID document: type always editable; number on create, or on edit with 'profile'
	print '<tr><td>'.$langs->trans("PatientIdType").'</td><td>';
	print $form->selectarray('id_type', $idTypes, GETPOSTISSET('id_type') ? GETPOST('id_type', 'aZ09') : $object->id_type, 1, 0, 0, '', 0, 0, 0, '', 'minwidth150');
	print '</td></tr>';
	if ($isCreate || $canProfile) {
		print '<tr><td>'.$langs->trans("PatientIdNumber").'</td><td>';
		print '<input type="text" name="id_number" class="minwidth200" maxlength="32" autocomplete="off" value="'.dol_escape_htmltag(GETPOSTISSET('id_number') ? GETPOST('id_number', 'alphanohtml') : '').'"';
		if (!$isCreate && $object->id_number_tail !== '' && $object->id_number_tail !== null) {
			print ' placeholder="'.dol_escape_htmltag($object->getIdNumberMasked()).'"';
		}
		print '>';
		if (!$isCreate && $object->id_number_tail !== '' && $object->id_number_tail !== null) {
			print ' <label><input type="checkbox" name="id_number_clear" value="1"> '.$langs->trans("PatientIdNumberClear").'</label>';
			print '<br><span class="opacitymedium">'.$langs->trans("PatientIdNumberKeepHint").'</span>';
		}
		print '</td></tr>';
	}

	print '<tr><td class="tdtop">'.$langs->trans("Address").'</td>';
	print '<td><textarea name="address" class="quatrevingtpercent" rows="2">'.dol_escape_htmltag($v('address')).'</textarea></td></tr>';
	print '<tr><td>'.$langs->trans("Zip").' / '.$langs->trans("Town").'</td><td>';
	print '<input type="text" name="zipcode" class="maxwidth100" maxlength="10" value="'.dol_escape_htmltag(GETPOSTISSET('zipcode') ? GETPOST('zipcode', 'alphanohtml') : ($soc ? (string) $soc->zip : '')).'"> ';
	// input[name=town] + select[name=state_id]: chinadiv's cascade attaches here when enabled
	print '<input type="text" name="town" class="minwidth200" maxlength="64" value="'.dol_escape_htmltag($v('town')).'">';
	print '</td></tr>';
	print '<tr><td>'.$langs->trans("Country").'</td><td>';
	$countryId = GETPOSTISSET('country_id') ? GETPOSTINT('country_id') : ($soc && $soc->country_id ? (int) $soc->country_id : (int) $mysoc->country_id);
	print $form->select_country($countryId, 'country_id', '', 0, 'minwidth200 maxwidth300');
	print '</td></tr>';
	print '<tr><td>'.$langs->trans("State").'</td><td>';
	$countryCode = ($soc && $soc->country_code) ? $soc->country_code : $mysoc->country_code;
	print $formcompany->select_state(GETPOSTISSET('state_id') ? GETPOSTINT('state_id') : ($soc ? (int) $soc->state_id : 0), $countryCode, 'state_id', 'minwidth200 maxwidth300');
	print '</td></tr>';

	print '<tr><td>'.$langs->trans("PatientBloodType").'</td>';
	print '<td><input type="text" name="blood_type" class="maxwidth50" maxlength="4" value="'.dol_escape_htmltag(GETPOSTISSET('blood_type') ? GETPOST('blood_type', 'alphanohtml') : (string) $object->blood_type).'"></td></tr>';
	print '<tr><td>'.$langs->trans("PatientEmergencyContact").'</td><td>';
	print '<input type="text" name="emergency_name" class="minwidth100" maxlength="64" placeholder="'.$langs->trans("Name").'" value="'.dol_escape_htmltag(GETPOSTISSET('emergency_name') ? GETPOST('emergency_name', 'alphanohtml') : (string) $object->emergency_name).'"> ';
	print '<input type="text" name="emergency_phone" class="minwidth150" maxlength="32" placeholder="'.$langs->trans("Phone").'" value="'.dol_escape_htmltag(GETPOSTISSET('emergency_phone') ? GETPOST('emergency_phone', 'alphanohtml') : (string) $object->emergency_phone).'">';
	print '</td></tr>';

	if ($canProfile) {
		print '<tr><td class="tdtop">'.$langs->trans("PatientHistory").'</td>';
		print '<td><textarea name="history_note" class="quatrevingtpercent" rows="4">'.dol_escape_htmltag(GETPOSTISSET('history_note') ? GETPOST('history_note', 'restricthtml') : (string) $object->history_note).'</textarea></td></tr>';
	}
	print '<tr><td class="tdtop">'.$langs->trans("NotePrivate").'</td>';
	print '<td><textarea name="note_private" class="quatrevingtpercent" rows="2">'.dol_escape_htmltag(GETPOSTISSET('note_private') ? GETPOST('note_private', 'restricthtml') : (string) $object->note_private).'</textarea></td></tr>';
}

// ---------------------------------------------------------------- create
if ($action == 'create') {
	if (!$canWrite) {
		accessforbidden();
	}
	print load_fiche_titre($langs->trans("PatientNew"), '', 'user');

	print '<form method="POST" action="'.$_SERVER["PHP_SELF"].'" name="formpatient">';
	print '<input type="hidden" name="token" value="'.newToken().'">';
	print '<input type="hidden" name="action" value="add">';

	print dol_get_fiche_head(array(), '', '', -1);
	print '<table class="border centpercent tableforfieldcreate">';
	$empty = new PatientProfile($db);
	$empty->gender = 'U';
	patient_print_form_rows($empty, true);
	print '</table>';
	print dol_get_fiche_end();

	print $form->buttonsSaveCancel("Create");
	print '</form>';
} elseif ($object->id > 0) {
	$head = patient_prepare_head($object);
	print dol_get_fiche_head($head, 'card', $langs->trans("PatientTab"), -1, 'user');

	$linkback = '<a href="'.dol_buildpath('/patient/list.php', 1).'?restore_lastsearch_values=1">'.$langs->trans("BackToList").'</a>';
	$soc = $object->thirdparty;

	// ------------------------------------------------------------ edit
	if ($action == 'edit' && $canWrite) {
		print '<form method="POST" action="'.$_SERVER["PHP_SELF"].'?id='.$object->id.'" name="formpatient">';
		print '<input type="hidden" name="token" value="'.newToken().'">';
		print '<input type="hidden" name="action" value="update">';
		print '<input type="hidden" name="id" value="'.$object->id.'">';
		print '<table class="border centpercent tableforfieldedit">';
		print '<tr><td class="titlefieldcreate">'.$langs->trans("PatientCardNo").'</td><td><strong>'.dol_escape_htmltag($object->card_no).'</strong></td></tr>';
		patient_print_form_rows($object, false);
		print '</table>';
		print $form->buttonsSaveCancel();
		print '</form>';
	} else {
		// ------------------------------------------------------------ view
		if ($action == 'setstatus' && $canAdmin) {
			$newStatus = $object->status ? 0 : 1;
			print $form->formconfirm($_SERVER["PHP_SELF"].'?id='.$object->id.'&status='.$newStatus,
				$langs->trans($newStatus ? "Enable" : "Disable"),
				$langs->trans($newStatus ? "PatientConfirmEnable" : "PatientConfirmDisable"),
				'confirm_setstatus', '', 0, 1);
		}

		$morehtmlref = '<div class="refidno">'.($soc ? $soc->getNomUrl(1) : '').'</div>';
		print '<div class="arearef heightref valignmiddle centpercent">';
		print '<div class="inline-block floatleft refid refidpadding">'.img_picto('', 'user', 'class="pictofixedwidth"').'<strong>'.dol_escape_htmltag($object->card_no).'</strong>';
		print ' <span class="badge '.($object->status ? 'badge-status4' : 'badge-status5').'">'.$object->getLibStatut().'</span></div>';
		print '<div class="inline-block floatright">'.$linkback.'</div>';
		print '<div class="clearboth"></div>'.$morehtmlref.'</div>';
		print '<div class="underbanner clearboth"></div>';

		print '<div class="fichecenter"><div class="fichehalfleft">';
		print '<table class="border tableforfield centpercent">';
		print '<tr><td class="titlefield">'.$langs->trans("Name").'</td><td>'.($soc ? dol_escape_htmltag($soc->name) : '').'</td></tr>';
		print '<tr><td>'.$langs->trans("PatientGender").'</td><td>'.$genders[$object->gender].'</td></tr>';
		print '<tr><td>'.$langs->trans("PatientBirthDate").'</td><td>';
		if ($object->birth_date !== '') {
			print dol_escape_htmltag($object->birth_date);
			$age = $object->getAge();
			if ($age !== null) {
				print ' <span class="opacitymedium">('.$langs->trans("PatientAgeYears", $age).')</span>';
			}
		}
		print '</td></tr>';
		print '<tr><td>'.$langs->trans("Phone").'</td><td>'.($soc ? dol_print_phone($soc->phone, $soc->country_code, 0, $soc->id, 'AC_TEL') : '').'</td></tr>';
		print '<tr><td>'.$langs->trans("PatientPhoneAlt").'</td><td>'.dol_escape_htmltag((string) $object->phone_alt).'</td></tr>';
		print '<tr><td>'.$langs->trans("Email").'</td><td>'.($soc ? dol_print_email($soc->email, 0, $soc->id, 1) : '').'</td></tr>';
		print '<tr><td>'.$langs->trans("PatientIdType").'</td><td>'.dol_escape_htmltag(patient_dict_label($db, 'c_patient_id_type', $object->id_type)).'</td></tr>';
		print '<tr><td>'.$langs->trans("PatientIdNumberMasked").'</td><td>';
		if ($plainId !== '') {
			print '<span class="opacitymedium">'.$langs->trans("PatientIdNumber").':</span> <strong>'.dol_escape_htmltag($plainId).'</strong>';
		} else {
			print dol_escape_htmltag($object->getIdNumberMasked());
			if ($canProfile && !empty($object->id_number_tail)) {
				print ' <a class="button buttongen small reposition" href="'.$_SERVER["PHP_SELF"].'?id='.$object->id.'&action=showid&token='.newToken().'">'.$langs->trans("PatientShowIdNumber").'</a>';
			}
		}
		print '</td></tr>';
		print '</table></div>';

		print '<div class="fichehalfright"><table class="border tableforfield centpercent">';
		print '<tr><td class="titlefield tdtop">'.$langs->trans("Address").'</td><td>';
		if ($soc) {
			print dol_nl2br(dol_escape_htmltag($soc->address, 0, 1));
			$line = trim($soc->zip.' '.$soc->town);
			if ($soc->state) {
				$line = trim($soc->state.' '.$line);
			}
			if ($line !== '') {
				print '<br>'.dol_escape_htmltag($line);
			}
			if (isModEnabled('chinadiv')) {
				dol_include_once('/chinadiv/lib/chinadiv.lib.php');
				if (function_exists('chinadiv_get_soc_codes')) {
					$codes = chinadiv_get_soc_codes($soc->id);
					if (!empty($codes['province_code'])) {
						print '<br><span class="opacitymedium">'.$langs->trans("PatientDivisionCodes").': '.dol_escape_htmltag(implode(' / ', array_filter(array($codes['province_code'], $codes['city_code'], $codes['district_code'])))).'</span>';
					}
				}
			}
		}
		print '</td></tr>';
		print '<tr><td>'.$langs->trans("PatientBloodType").'</td><td>'.dol_escape_htmltag((string) $object->blood_type).'</td></tr>';
		print '<tr><td>'.$langs->trans("PatientEmergencyContact").'</td><td>'.dol_escape_htmltag(trim($object->emergency_name.' '.$object->emergency_phone)).'</td></tr>';
		if ($canProfile) {
			print '<tr><td class="tdtop">'.$langs->trans("PatientAllergies").'</td><td>';
			if (empty($allergies)) {
				print '<span class="opacitymedium">'.$langs->trans("PatientAllergyNone").'</span>';
			} else {
				$hasSevere = false;
				$chips = array();
				foreach ($allergies as $a) {
					$chip = dol_escape_htmltag($a->name);
					if ($a->severity >= 3) {
						$hasSevere = true;
						$chip = '<span class="badge badge-status8">'.$chip.'</span>';
					} elseif ($a->severity == 2) {
						$chip = '<span class="badge badge-status1">'.$chip.'</span>';
					} else {
						$chip = '<span class="badge badge-status0">'.$chip.'</span>';
					}
					$chips[] = $chip;
				}
				if ($hasSevere) {
					print '<div class="error">'.img_warning('').' '.$langs->trans("PatientAllergySevereWarning").'</div>';
				}
				print implode(' ', $chips);
			}
			print ' <a href="'.dol_buildpath('/patient/allergies.php', 1).'?id='.$object->id.'">'.img_edit($langs->trans("Modify")).'</a>';
			print '</td></tr>';
			print '<tr><td class="tdtop">'.$langs->trans("PatientHistory").'</td><td>'.dol_nl2br(dol_escape_htmltag((string) $object->history_note, 0, 1)).'</td></tr>';
		}
		print '<tr><td class="tdtop">'.$langs->trans("NotePrivate").'</td><td>'.dol_nl2br(dol_escape_htmltag((string) $object->note_private, 0, 1)).'</td></tr>';
		print '<tr><td>'.$langs->trans("DateCreation").'</td><td>'.dol_print_date($object->date_creation, 'dayhour').'</td></tr>';
		print '</table></div></div>';
		print '<div class="clearboth"></div>';

		print dol_get_fiche_end();

		print '<div class="tabsAction">';
		if ($canWrite) {
			print dolGetButtonAction($langs->trans("Modify"), '', 'default', $_SERVER["PHP_SELF"].'?id='.$object->id.'&action=edit&token='.newToken(), '', 1);
		}
		if ($canAdmin) {
			print dolGetButtonAction($langs->trans($object->status ? "Disable" : "Enable"), '', $object->status ? 'delete' : 'default', $_SERVER["PHP_SELF"].'?id='.$object->id.'&action=setstatus&token='.newToken(), '', 1);
		}
		print '</div>';
	}
} else {
	print load_fiche_titre($langs->trans("PatientTab"), '', 'user');
	print '<div class="opacitymedium">'.$langs->trans("PatientNotYet").'</div>';
}

llxFooter();
$db->close();
