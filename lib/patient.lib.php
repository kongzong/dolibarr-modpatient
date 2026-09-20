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
 * \file    htdocs/custom/patient/lib/patient.lib.php
 * \ingroup patient
 * \brief   Shared helpers: ID-number hashing/masking, audit writer, admin tabs.
 *          Later healthcare modules reuse these instead of re-implementing.
 *          The ID number plaintext must never be logged or returned by any
 *          function here (spec §4.1).
 */

/**
 * Normalize an ID number before hashing: trim, uppercase (X check digit).
 *
 * @param	string	$number		Raw input
 * @return	string				Normalized value ('' if empty)
 */
function patient_normalize_id($number)
{
	return strtoupper(trim((string) $number));
}

/**
 * Deterministic lookup hash for exact ID-number search. Salted with the
 * instance key so a leaked table cannot be brute-forced against known numbers
 * without also owning conf.php.
 *
 * @param	string	$number		ID number (plaintext, in memory only)
 * @return	string				64-char hex, or '' for empty input
 */
function patient_hash_id($number)
{
	global $conf;

	$normalized = patient_normalize_id($number);
	if ($normalized === '') {
		return '';
	}
	$salt = '';
	if (!empty($conf->file->instance_unique_id)) {
		$salt = $conf->file->instance_unique_id;
	}
	return hash('sha256', $salt.'|patient|'.$normalized);
}

/**
 * Last 4 characters kept in clear for masked display.
 *
 * @param	string	$number		ID number
 * @return	string				Up to 4 trailing characters
 */
function patient_id_tail($number)
{
	$normalized = patient_normalize_id($number);
	return $normalized === '' ? '' : substr($normalized, -4);
}

/**
 * Masked display: asterisks + tail. Never receives plaintext on list pages.
 *
 * @param	string	$tail		Stored tail (0-4 chars)
 * @param	int		$stars		Number of mask characters
 * @return	string				Masked string, '' if no tail
 */
function patient_mask_id($tail, $stars = 6)
{
	$tail = (string) $tail;
	if ($tail === '') {
		return '';
	}
	return str_repeat('*', $stars).$tail;
}

/**
 * Check digit validation for the 18-digit PRC resident ID (GB 11643-1999).
 * Only a format hint for the registration form; not a proof of authenticity.
 *
 * @param	string	$number		Candidate number
 * @return	bool				True when 18 chars and the check digit matches
 */
function patient_validate_prc_id($number)
{
	$n = patient_normalize_id($number);
	if (!preg_match('/^[0-9]{17}[0-9X]$/', $n)) {
		return false;
	}
	$weights = array(7, 9, 10, 5, 8, 4, 2, 1, 6, 3, 7, 9, 10, 5, 8, 4, 2);
	$map = array('1', '0', 'X', '9', '8', '7', '6', '5', '4', '3', '2');
	$sum = 0;
	for ($i = 0; $i < 17; $i++) {
		$sum += ((int) $n[$i]) * $weights[$i];
	}
	return $map[$sum % 11] === $n[17];
}

/**
 * Birth date (YYYY-MM-DD) embedded in an 18-digit PRC ID, for form prefill.
 *
 * @param	string	$number		ID number
 * @return	string				'YYYY-MM-DD' or '' when not derivable
 */
function patient_birth_from_prc_id($number)
{
	$n = patient_normalize_id($number);
	if (!preg_match('/^[0-9]{6}([0-9]{4})([0-9]{2})([0-9]{2})/', $n, $m)) {
		return '';
	}
	if (!checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
		return '';
	}
	return $m[1].'-'.$m[2].'-'.$m[3];
}

/**
 * Append one audit row. Never throws; returns <0 on failure so callers log.
 * Detail must not contain the ID-number plaintext: callers pass field names
 * and non-sensitive values only.
 *
 * @param	DoliDB	$db			Database handler
 * @param	int		$fkPatient	llx_patient_profile.rowid
 * @param	string	$action		Audit action code (uppercase, <=32 chars)
 * @param	User	$user		Acting user
 * @param	array	$detail		Free-form context, JSON encoded
 * @return	int					>0 rowid, <0 on error
 */
function patient_audit($db, $fkPatient, $action, $user, $detail = array())
{
	global $conf;

	$fkPatient = (int) $fkPatient;
	$action = substr(preg_replace('/[^A-Z_]/', '', strtoupper((string) $action)), 0, 32);
	if ($fkPatient <= 0 || $action === '') {
		return -1;
	}

	$ip = '';
	if (!empty($_SERVER['REMOTE_ADDR'])) {
		$ip = substr((string) $_SERVER['REMOTE_ADDR'], 0, 64);
	}
	$json = json_encode($detail, JSON_UNESCAPED_UNICODE);
	if ($json === false) {
		$json = '{}';
	}
	$entity = !empty($conf->entity) ? (int) $conf->entity : 1;
	$fkUser = (is_object($user) && !empty($user->id)) ? (int) $user->id : 'NULL';

	$sql = "INSERT INTO ".$db->prefix()."patient_audit (entity, fk_patient, action, fk_user, ip, detail, date_creation)";
	$sql .= " VALUES (".$entity.", ".$fkPatient.", '".$db->escape($action)."', ".$fkUser.", '".$db->escape($ip)."', '".$db->escape($json)."', '".$db->idate(dol_now())."')";
	$res = $db->query($sql);
	if (!$res) {
		return -2;
	}
	return (int) $db->last_insert_id($db->prefix().'patient_audit');
}

/**
 * Active rows of a module dictionary as code => label, ordered by pos.
 *
 * @param	DoliDB	$db		Database handler
 * @param	string	$table	Dictionary table without prefix (c_patient_*)
 * @return	array<string,string>
 */
function patient_dict_options($db, $table)
{
	if (!preg_match('/^c_patient_[a-z_]+$/', $table)) {
		return array();
	}
	$options = array();
	$resql = $db->query("SELECT code, label FROM ".$db->prefix().$table." WHERE active = 1 ORDER BY pos ASC, label ASC");
	if ($resql) {
		while ($obj = $db->fetch_object($resql)) {
			$options[$obj->code] = $obj->label;
		}
		$db->free($resql);
	}
	return $options;
}

/**
 * Active rows of a module dictionary as rowid => label (for FK columns).
 *
 * @param	DoliDB	$db		Database handler
 * @param	string	$table	Dictionary table without prefix (c_patient_*)
 * @return	array<int,string>
 */
function patient_dict_rows($db, $table)
{
	if (!preg_match('/^c_patient_[a-z_]+$/', $table)) {
		return array();
	}
	$rows = array();
	$resql = $db->query("SELECT rowid, label FROM ".$db->prefix().$table." WHERE active = 1 ORDER BY pos ASC, label ASC");
	if ($resql) {
		while ($obj = $db->fetch_object($resql)) {
			$rows[(int) $obj->rowid] = $obj->label;
		}
		$db->free($resql);
	}
	return $rows;
}

/**
 * Active doctors as fk_user => "Lastname Firstname (department)" for selectors
 * in later modules (visits, prescriptions, bookings).
 *
 * @param	DoliDB	$db		Database handler
 * @return	array<int,string>
 */
function patient_doctor_options($db)
{
	global $conf;

	$out = array();
	$departments = patient_dict_rows($db, 'c_patient_department');
	$sql = "SELECT d.fk_user, d.fk_department, u.lastname, u.firstname FROM ".$db->prefix()."patient_doctor as d";
	$sql .= " INNER JOIN ".$db->prefix()."user as u ON u.rowid = d.fk_user AND u.statut = 1";
	$sql .= " WHERE d.status = 1 AND d.entity = ".((int) $conf->entity)." ORDER BY u.lastname ASC, u.firstname ASC";
	$resql = $db->query($sql);
	if ($resql) {
		while ($obj = $db->fetch_object($resql)) {
			$label = trim($obj->lastname.' '.$obj->firstname);
			if (!empty($departments[$obj->fk_department])) {
				$label .= ' ('.$departments[$obj->fk_department].')';
			}
			$out[(int) $obj->fk_user] = $label;
		}
		$db->free($resql);
	}
	return $out;
}

/**
 * Label of a dictionary code, or the code itself when unknown/inactive.
 *
 * @param	DoliDB	$db		Database handler
 * @param	string	$table	Dictionary table without prefix
 * @param	string	$code	Code
 * @return	string
 */
function patient_dict_label($db, $table, $code)
{
	$code = (string) $code;
	if ($code === '') {
		return '';
	}
	$options = patient_dict_options($db, $table);
	return isset($options[$code]) ? $options[$code] : $code;
}

/**
 * Tabs of the patient card.
 *
 * @param	PatientProfile	$object	Loaded patient
 * @return	array<int,array{0:string,1:string,2:string}>
 */
function patient_prepare_head($object)
{
	global $langs, $user;

	$langs->load('patient@patient');
	$h = 0;
	$head = array();

	$head[$h][0] = dol_buildpath('/patient/card.php', 1).'?id='.((int) $object->id);
	$head[$h][1] = $langs->trans('PatientTab');
	$head[$h][2] = 'card';
	$h++;

	// Allergies: medical data, only offered to 'profile' holders (spec §2.4)
	if ($user->hasRight('patient', 'profile')) {
		$head[$h][0] = dol_buildpath('/patient/allergies.php', 1).'?id='.((int) $object->id);
		$head[$h][1] = $langs->trans('PatientAllergies');
		$head[$h][2] = 'allergies';
		$h++;
	}

	return $head;
}

/**
 * Tabs for the module admin pages.
 *
 * @return	array<int,array{0:string,1:string,2:string}>	Head array for dol_fiche_head()
 */
function patient_admin_prepare_head()
{
	global $langs;

	$langs->load('patient@patient');
	$h = 0;
	$head = array();

	$head[$h][0] = dol_buildpath('/patient/admin/setup.php', 1);
	$head[$h][1] = $langs->trans('Settings');
	$head[$h][2] = 'settings';
	$h++;

	$head[$h][0] = dol_buildpath('/patient/admin/doctors.php', 1);
	$head[$h][1] = $langs->trans('PatientDoctors');
	$head[$h][2] = 'doctors';
	$h++;

	$head[$h][0] = dol_buildpath('/patient/admin/audit.php', 1);
	$head[$h][1] = $langs->trans('PatientAudit');
	$head[$h][2] = 'audit';
	$h++;

	return $head;
}
