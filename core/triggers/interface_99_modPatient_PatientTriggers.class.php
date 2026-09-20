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
 * \file    htdocs/custom/patient/core/triggers/interface_99_modPatient_PatientTriggers.class.php
 * \ingroup patient
 * \brief   Append-only audit trail for patient write events (spec §2.5).
 *          Read events (opening a record, revealing the ID number) are logged
 *          explicitly by the pages through patient_audit(). Failures are
 *          logged, never fatal for the business flow.
 */

require_once DOL_DOCUMENT_ROOT.'/core/triggers/dolibarrtriggers.class.php';

/**
 * Class InterfacePatientTriggers
 */
class InterfacePatientTriggers extends DolibarrTriggers
{
	/**
	 * Write actions audited by this trigger (fired by the module's own classes).
	 */
	const AUDITED_ACTIONS = array(
		'PATIENT_CREATE',
		'PATIENT_MODIFY',
		'PATIENT_DISABLE',
		'PATIENT_ALLERGY_ADD',
		'PATIENT_ALLERGY_DELETE',
	);

	/**
	 * @var DoliDB Database handler
	 */
	protected $db;

	/**
	 * Constructor
	 *
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;

		$this->name = preg_replace('/^Interface/i', '', get_class($this));
		$this->family = "crm";
		$this->description = "Triggers of the Patient module (audit trail)";
		$this->version = '0.1.0';
		$this->picto = 'user';
	}

	/**
	 * Function called when a Dolibarr business event occurs.
	 *
	 * @param	string		$action		Event action label
	 * @param	CommonObject	$object		Object (PatientProfile or PatientAllergy)
	 * @param	User		$user		User
	 * @param	Translate	$langs		Lang object
	 * @param	Conf		$conf		Config
	 * @return	int						0 always: audit must never block the business flow
	 */
	public function runTrigger($action, $object, User $user, Translate $langs, Conf $conf)
	{
		if (empty($conf->patient->enabled) || !in_array($action, self::AUDITED_ACTIONS, true)) {
			return 0;
		}

		dol_include_once('/patient/lib/patient.lib.php');

		$fkPatient = 0;
		if (!empty($object->fk_patient)) {
			$fkPatient = (int) $object->fk_patient; // allergy rows
		} elseif (!empty($object->id)) {
			$fkPatient = (int) $object->id; // profile rows
		}
		if ($fkPatient <= 0) {
			dol_syslog(__METHOD__.' '.$action.' without patient id', LOG_WARNING);
			return 0;
		}

		$detail = array();
		if (property_exists($object, 'audit_detail') && is_array($object->audit_detail)) {
			$detail = $object->audit_detail;
		}

		$res = patient_audit($this->db, $fkPatient, $action, $user, $detail);
		if ($res < 0) {
			dol_syslog(__METHOD__.' audit insert failed for '.$action.' patient '.$fkPatient, LOG_ERR);
		}
		return 0;
	}
}
