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

use Luracast\Restler\RestException;

/**
 * \file    htdocs/custom/patient/class/api_patient.class.php
 * \ingroup patient
 * \brief   REST API for patient records. The ID number plaintext is NEVER
 *          returned by any endpoint (spec §2.6); only the masked tail is.
 *          Allergies/history require the 'profile' permission.
 */

dol_include_once('/patient/class/patientprofile.class.php');
dol_include_once('/patient/class/patientallergy.class.php');
dol_include_once('/patient/lib/patient.lib.php');

/**
 * API class for Patient module
 *
 * @access protected
 * @class  DolibarrApiAccess {@requires user,external}
 */
class Patient extends DolibarrApi
{
	/**
	 * @var DoliDB $db Database object
	 */
	protected $db;

	/**
	 * Constructor
	 *
	 * @url GET /
	 */
	public function __construct()
	{
		global $db;
		$this->db = $db;
	}

	/**
	 * Search patients by card number, name, phone or exact ID number.
	 *
	 * @url	GET patients
	 *
	 * @param	string	$q		Free text (an ID-looking value is matched exactly by hash)
	 * @param	int		$limit	Page size (max 100)
	 * @param	int		$page	Page (0-based)
	 * @param	int		$status	-1 all, 1 active, 0 disabled
	 * @return	array{total:int,rows:array<int,array<string,mixed>>}
	 * @throws RestException 403 Not allowed
	 */
	public function index($q = '', $limit = 25, $page = 0, $status = 1)
	{
		if (!DolibarrApiAccess::$user->hasRight('patient', 'read')) {
			throw new RestException(403);
		}
		$limit = max(1, min(100, (int) $limit));
		$page = max(0, (int) $page);
		$dao = new PatientProfile($this->db);
		$result = $dao->search((string) $q, $limit, $limit * $page, (int) $status);
		if ($result === null) {
			throw new RestException(500, 'Search failed');
		}
		$rows = array();
		foreach ($result['rows'] as $row) {
			$rows[] = array(
				'id' => (int) $row->rowid,
				'fk_soc' => (int) $row->fk_soc,
				'card_no' => $row->card_no,
				'name' => $row->name,
				'gender' => $row->gender,
				'birth_date' => $row->birth_date ? substr($row->birth_date, 0, 10) : null,
				'phone' => $row->phone,
				'town' => $row->town,
				'id_number_masked' => patient_mask_id($row->id_number_tail),
				'status' => (int) $row->status,
			);
		}
		return array('total' => (int) $result['total'], 'rows' => $rows);
	}

	/**
	 * Get one patient. Allergies and history are included only for 'profile'
	 * holders (and that read is audited).
	 *
	 * @url	GET patients/{id}
	 *
	 * @param	int		$id		Patient rowid
	 * @return	array<string,mixed>
	 * @throws RestException 403 Not allowed
	 * @throws RestException 404 Not found
	 */
	public function get($id)
	{
		if (!DolibarrApiAccess::$user->hasRight('patient', 'read')) {
			throw new RestException(403);
		}
		$patient = new PatientProfile($this->db);
		if ($patient->fetch((int) $id) <= 0) {
			throw new RestException(404, 'Patient not found');
		}
		$out = $this->publicFields($patient);
		if (DolibarrApiAccess::$user->hasRight('patient', 'profile')) {
			$out['history_note'] = $patient->history_note;
			$out['allergies'] = $this->allergyRows($patient->id);
			patient_audit($this->db, $patient->id, 'READ_PROFILE', DolibarrApiAccess::$user, array('via' => 'api'));
		}
		return $out;
	}

	/**
	 * Register a patient (thirdparty + card number + record, one transaction).
	 *
	 * Body: { "name": "...", "gender": "M|F|U", "birth_date": "YYYY-MM-DD", "phone": "...",
	 *         "email": "...", "address": "...", "zip": "...", "town": "...", "state_id": 0, "country_id": 0,
	 *         "id_type": "IDCARD", "id_number": "...", "blood_type": "...", "phone_alt": "...",
	 *         "emergency_name": "...", "emergency_phone": "...", "history_note": "...", "note_private": "..." }
	 * id_number is consumed in memory and stored encrypted; it is not echoed back.
	 *
	 * @url	POST patients
	 *
	 * @param	array	$request_data	Body
	 * @return	array<string,mixed>
	 * @throws RestException 403 Not allowed
	 * @throws RestException 400 Bad parameters
	 * @throws RestException 500 Creation failed
	 */
	public function post($request_data = null)
	{
		if (!DolibarrApiAccess::$user->hasRight('patient', 'write')) {
			throw new RestException(403);
		}
		$data = is_array($request_data) ? $request_data : array();
		$name = isset($data['name']) ? trim((string) $data['name']) : '';
		if ($name === '') {
			throw new RestException(400, 'name is required');
		}

		$patient = new PatientProfile($this->db);
		$patient->gender = isset($data['gender']) ? strtoupper((string) $data['gender']) : 'U';
		$patient->birth_date = isset($data['birth_date']) ? (string) $data['birth_date'] : '';
		if ($patient->birth_date !== '' && !preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/', $patient->birth_date)) {
			throw new RestException(400, 'birth_date must be YYYY-MM-DD');
		}
		$patient->id_type = isset($data['id_type']) ? (string) $data['id_type'] : null;
		foreach (array('blood_type', 'phone_alt', 'emergency_name', 'emergency_phone', 'note_private') as $field) {
			$patient->$field = isset($data[$field]) ? (string) $data[$field] : null;
		}
		// History is medical data: only 'profile' may set it
		if (isset($data['history_note']) && DolibarrApiAccess::$user->hasRight('patient', 'profile')) {
			$patient->history_note = (string) $data['history_note'];
		}
		if (isset($data['id_number'])) {
			$raw = (string) $data['id_number'];
			if ($raw !== '' && $patient->id_type === 'IDCARD' && !patient_validate_prc_id($raw)) {
				throw new RestException(400, 'invalid resident ID number');
			}
			$patient->setIdNumber($raw);
			if ($patient->birth_date === '' && $patient->id_type === 'IDCARD') {
				$patient->birth_date = patient_birth_from_prc_id($raw);
			}
			unset($raw);
		}

		$soc = array('name' => $name);
		foreach (array('phone', 'email', 'address', 'zip', 'town', 'state_id', 'country_id') as $field) {
			if (isset($data[$field])) {
				$soc[$field] = $data[$field];
			}
		}

		$result = $patient->create(DolibarrApiAccess::$user, $soc);
		if ($result <= 0) {
			throw new RestException(500, 'Creation failed: '.$patient->error);
		}
		return $this->publicFields($patient);
	}

	/**
	 * Active allergies of a patient.
	 *
	 * @url	GET patients/{id}/allergies
	 *
	 * @param	int		$id		Patient rowid
	 * @return	array<int,array<string,mixed>>
	 * @throws RestException 403 Not allowed
	 * @throws RestException 404 Not found
	 */
	public function getAllergies($id)
	{
		if (!DolibarrApiAccess::$user->hasRight('patient', 'profile')) {
			throw new RestException(403);
		}
		$patient = new PatientProfile($this->db);
		if ($patient->fetch((int) $id) <= 0) {
			throw new RestException(404, 'Patient not found');
		}
		patient_audit($this->db, $patient->id, 'READ_PROFILE', DolibarrApiAccess::$user, array('via' => 'api_allergies'));
		return $this->allergyRows($patient->id);
	}

	/**
	 * Add an allergy.
	 *
	 * Body: { "name": "...", "allergy_type": "DRUG|FOOD|OTHER", "fk_product": 0, "severity": 1-3, "reaction": "..." }
	 *
	 * @url	POST patients/{id}/allergies
	 *
	 * @param	int		$id				Patient rowid
	 * @param	array	$request_data	Body
	 * @return	array<string,mixed>
	 * @throws RestException 403 Not allowed
	 * @throws RestException 404 Not found
	 * @throws RestException 400 Bad parameters
	 */
	public function postAllergy($id, $request_data = null)
	{
		if (!DolibarrApiAccess::$user->hasRight('patient', 'profile')) {
			throw new RestException(403);
		}
		$patient = new PatientProfile($this->db);
		if ($patient->fetch((int) $id) <= 0) {
			throw new RestException(404, 'Patient not found');
		}
		$data = is_array($request_data) ? $request_data : array();
		$allergy = new PatientAllergy($this->db);
		$allergy->fk_patient = $patient->id;
		$allergy->name = isset($data['name']) ? (string) $data['name'] : '';
		$allergy->allergy_type = isset($data['allergy_type']) ? (string) $data['allergy_type'] : 'OTHER';
		$allergy->fk_product = isset($data['fk_product']) ? (int) $data['fk_product'] : 0;
		$allergy->severity = isset($data['severity']) ? (int) $data['severity'] : 1;
		$allergy->reaction = isset($data['reaction']) ? (string) $data['reaction'] : null;
		if (trim($allergy->name) === '') {
			throw new RestException(400, 'name is required');
		}
		if ($allergy->create(DolibarrApiAccess::$user) <= 0) {
			throw new RestException(500, 'Creation failed: '.$allergy->error);
		}
		return $this->allergyRow($allergy);
	}

	/**
	 * Soft-remove an allergy (kept in the database, flagged removed).
	 *
	 * @url	DELETE patients/{id}/allergies/{aid}
	 *
	 * @param	int		$id		Patient rowid
	 * @param	int		$aid	Allergy rowid
	 * @return	array{success:bool}
	 * @throws RestException 403 Not allowed
	 * @throws RestException 404 Not found
	 */
	public function deleteAllergy($id, $aid)
	{
		if (!DolibarrApiAccess::$user->hasRight('patient', 'profile')) {
			throw new RestException(403);
		}
		$allergy = new PatientAllergy($this->db);
		if ($allergy->fetch((int) $aid) <= 0 || $allergy->fk_patient !== (int) $id) {
			throw new RestException(404, 'Allergy not found');
		}
		if ($allergy->status !== 1) {
			return array('success' => true);
		}
		if ($allergy->remove(DolibarrApiAccess::$user) <= 0) {
			throw new RestException(500, 'Removal failed: '.$allergy->error);
		}
		return array('success' => true);
	}

	/**
	 * Active doctors (user id => label) for selectors in client apps.
	 *
	 * @url	GET doctors
	 *
	 * @return	array<int,array{fk_user:int,label:string}>
	 * @throws RestException 403 Not allowed
	 */
	public function getDoctors()
	{
		if (!DolibarrApiAccess::$user->hasRight('patient', 'read')) {
			throw new RestException(403);
		}
		$out = array();
		foreach (patient_doctor_options($this->db) as $fkUser => $label) {
			$out[] = array('fk_user' => (int) $fkUser, 'label' => $label);
		}
		return $out;
	}

	// ------------------------------------------------------------ helpers

	/**
	 * Fields safe for any 'read' holder. No ciphertext, no hash, no history.
	 *
	 * @param	PatientProfile	$p	Loaded patient
	 * @return	array<string,mixed>
	 */
	private function publicFields(PatientProfile $p)
	{
		$soc = $p->thirdparty;
		return array(
			'id' => (int) $p->id,
			'fk_soc' => (int) $p->fk_soc,
			'card_no' => $p->card_no,
			'name' => $soc ? $soc->name : null,
			'gender' => $p->gender,
			'birth_date' => $p->birth_date !== '' ? $p->birth_date : null,
			'age' => $p->getAge(),
			'phone' => $soc ? $soc->phone : null,
			'phone_alt' => $p->phone_alt,
			'email' => $soc ? $soc->email : null,
			'address' => $soc ? $soc->address : null,
			'zip' => $soc ? $soc->zip : null,
			'town' => $soc ? $soc->town : null,
			'id_type' => $p->id_type,
			'id_number_masked' => $p->getIdNumberMasked(),
			'blood_type' => $p->blood_type,
			'emergency_name' => $p->emergency_name,
			'emergency_phone' => $p->emergency_phone,
			'status' => (int) $p->status,
			'date_creation' => $p->date_creation ? dol_print_date($p->date_creation, 'dayhourrfc') : null,
		);
	}

	/**
	 * @param	int		$fkPatient	Patient rowid
	 * @return	array<int,array<string,mixed>>
	 */
	private function allergyRows($fkPatient)
	{
		$dao = new PatientAllergy($this->db);
		$rows = $dao->fetchAllByPatient($fkPatient);
		$out = array();
		foreach ($rows ?: array() as $row) {
			$out[] = $this->allergyRow($row);
		}
		return $out;
	}

	/**
	 * @param	PatientAllergy	$a	Allergy
	 * @return	array<string,mixed>
	 */
	private function allergyRow(PatientAllergy $a)
	{
		return array(
			'id' => (int) $a->id,
			'allergy_type' => $a->allergy_type,
			'fk_product' => $a->fk_product,
			'name' => $a->name,
			'severity' => (int) $a->severity,
			'reaction' => $a->reaction,
			'status' => (int) $a->status,
		);
	}
}
