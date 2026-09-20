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
 * \file    htdocs/custom/patient/class/patientallergy.class.php
 * \ingroup patient
 * \brief   Structured allergy history. Rows are never deleted: remove() sets
 *          status 0 so the prescribing check in later modules can still see
 *          what was once recorded. Add/remove are audited via triggers.
 */

require_once DOL_DOCUMENT_ROOT.'/core/class/commonobject.class.php';

/**
 * Class PatientAllergy
 */
class PatientAllergy extends CommonObject
{
	const TRIGGER_PREFIX = 'PATIENT';

	public $element = 'patientallergy';
	public $table_element = 'patient_allergy';

	public $id;
	public $entity;
	public $fk_patient;
	public $allergy_type = 'OTHER';
	public $fk_product;
	public $name;
	public $severity = 1;
	public $reaction;
	public $status = 1;
	public $fk_user_creat;
	public $date_creation;

	/** @var array<string,mixed> Context for the audit trigger */
	public $audit_detail = array();

	/**
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * @param	User	$user		Acting user
	 * @param	int		$notrigger	1 to skip audit
	 * @return	int					>0 rowid, <0 error
	 */
	public function create(User $user, $notrigger = 0)
	{
		global $conf;

		$this->fk_patient = (int) $this->fk_patient;
		$this->name = trim((string) $this->name);
		if ($this->fk_patient <= 0 || $this->name === '') {
			$this->error = 'ErrorFieldRequired';
			return -1;
		}
		$this->allergy_type = preg_replace('/[^A-Z_]/', '', strtoupper((string) $this->allergy_type));
		if ($this->allergy_type === '') {
			$this->allergy_type = 'OTHER';
		}
		$this->severity = max(1, min(3, (int) $this->severity));
		$this->fk_product = (int) $this->fk_product > 0 ? (int) $this->fk_product : null;
		$this->entity = !empty($conf->entity) ? (int) $conf->entity : 1;
		$this->date_creation = dol_now();

		$this->db->begin();

		$sql = "INSERT INTO ".$this->db->prefix()."patient_allergy (entity, fk_patient, allergy_type, fk_product, name, severity, reaction, status, fk_user_creat, date_creation)";
		$sql .= " VALUES (".$this->entity.", ".$this->fk_patient.", '".$this->db->escape($this->allergy_type)."',";
		$sql .= " ".($this->fk_product ? $this->fk_product : 'NULL').", '".$this->db->escape($this->name)."', ".$this->severity.",";
		$sql .= " ".($this->reaction !== null && $this->reaction !== '' ? "'".$this->db->escape($this->reaction)."'" : 'NULL').",";
		$sql .= " 1, ".((int) $user->id).", '".$this->db->idate($this->date_creation)."')";

		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			$this->db->rollback();
			return -1;
		}
		$this->id = (int) $this->db->last_insert_id($this->db->prefix().'patient_allergy');

		if (!$notrigger) {
			$this->audit_detail = array('allergy_id' => $this->id, 'type' => $this->allergy_type, 'name' => $this->name, 'severity' => $this->severity);
			if ($this->call_trigger('PATIENT_ALLERGY_ADD', $user) < 0) {
				$this->db->rollback();
				return -1;
			}
		}
		$this->db->commit();
		return $this->id;
	}

	/**
	 * @param	int		$id		Rowid
	 * @return	int				1 found, 0 not found, <0 error
	 */
	public function fetch($id)
	{
		$sql = "SELECT rowid, entity, fk_patient, allergy_type, fk_product, name, severity, reaction, status, fk_user_creat, date_creation";
		$sql .= " FROM ".$this->db->prefix()."patient_allergy WHERE rowid = ".((int) $id);
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		$obj = $this->db->fetch_object($resql);
		$this->db->free($resql);
		if (!$obj) {
			return 0;
		}
		$this->id = (int) $obj->rowid;
		$this->entity = (int) $obj->entity;
		$this->fk_patient = (int) $obj->fk_patient;
		$this->allergy_type = $obj->allergy_type;
		$this->fk_product = $obj->fk_product ? (int) $obj->fk_product : null;
		$this->name = $obj->name;
		$this->severity = (int) $obj->severity;
		$this->reaction = $obj->reaction;
		$this->status = (int) $obj->status;
		$this->fk_user_creat = $obj->fk_user_creat;
		$this->date_creation = $this->db->jdate($obj->date_creation);
		return 1;
	}

	/**
	 * Active allergies of a patient, most severe first.
	 * Callers must hold the 'profile' permission (spec §2.4): this is the
	 * only read path and pages must not call it otherwise.
	 *
	 * @param	int		$fkPatient		Patient rowid
	 * @param	bool	$includeRemoved	Also return status 0 rows
	 * @return	PatientAllergy[]|null	null on SQL error
	 */
	public function fetchAllByPatient($fkPatient, $includeRemoved = false)
	{
		$sql = "SELECT a.rowid, a.entity, a.fk_patient, a.allergy_type, a.fk_product, a.name, a.severity, a.reaction, a.status, a.fk_user_creat, a.date_creation,";
		$sql .= " p.ref as product_ref, p.label as product_label";
		$sql .= " FROM ".$this->db->prefix()."patient_allergy as a";
		$sql .= " LEFT JOIN ".$this->db->prefix()."product as p ON p.rowid = a.fk_product";
		$sql .= " WHERE a.fk_patient = ".((int) $fkPatient);
		if (!$includeRemoved) {
			$sql .= " AND a.status = 1";
		}
		$sql .= " ORDER BY a.status DESC, a.severity DESC, a.name ASC";
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return null;
		}
		$list = array();
		while ($obj = $this->db->fetch_object($resql)) {
			$row = new PatientAllergy($this->db);
			$row->id = (int) $obj->rowid;
			$row->entity = (int) $obj->entity;
			$row->fk_patient = (int) $obj->fk_patient;
			$row->allergy_type = $obj->allergy_type;
			$row->fk_product = $obj->fk_product ? (int) $obj->fk_product : null;
			$row->name = $obj->name;
			$row->severity = (int) $obj->severity;
			$row->reaction = $obj->reaction;
			$row->status = (int) $obj->status;
			$row->fk_user_creat = $obj->fk_user_creat;
			$row->date_creation = $this->db->jdate($obj->date_creation);
			$row->product_ref = $obj->product_ref;
			$row->product_label = $obj->product_label;
			$list[] = $row;
		}
		$this->db->free($resql);
		return $list;
	}

	/** @var string|null Joined product ref (fetchAllByPatient only) */
	public $product_ref;
	/** @var string|null Joined product label (fetchAllByPatient only) */
	public $product_label;

	/**
	 * Soft-remove: status 0, audited. Never a DELETE (spec §4.2).
	 *
	 * @param	User	$user		Acting user
	 * @param	int		$notrigger	1 to skip audit
	 * @return	int					1 ok, <0 error
	 */
	public function remove(User $user, $notrigger = 0)
	{
		if ($this->id <= 0) {
			return -1;
		}
		$this->db->begin();
		$sql = "UPDATE ".$this->db->prefix()."patient_allergy SET status = 0 WHERE rowid = ".((int) $this->id)." AND status = 1";
		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			$this->db->rollback();
			return -1;
		}
		$this->status = 0;
		if (!$notrigger) {
			$this->audit_detail = array('allergy_id' => $this->id, 'name' => $this->name);
			if ($this->call_trigger('PATIENT_ALLERGY_DELETE', $user) < 0) {
				$this->db->rollback();
				return -1;
			}
		}
		$this->db->commit();
		return 1;
	}

	/**
	 * Exact product match, then case-insensitive name containment. Meant for
	 * modPrescription's blocking check; returns matching active allergies.
	 *
	 * @param	int			$fkPatient	Patient rowid
	 * @param	int			$fkProduct	Product to check (0 to skip)
	 * @param	string		$label		Product/ingredient label to check against names
	 * @return	PatientAllergy[]|null
	 */
	public function findConflicts($fkPatient, $fkProduct = 0, $label = '')
	{
		$all = $this->fetchAllByPatient($fkPatient);
		if ($all === null) {
			return null;
		}
		$hits = array();
		$label = mb_strtolower(trim((string) $label));
		foreach ($all as $row) {
			if ($fkProduct > 0 && $row->fk_product === (int) $fkProduct) {
				$hits[] = $row;
				continue;
			}
			$name = mb_strtolower((string) $row->name);
			if ($label !== '' && $name !== '' && (mb_strpos($label, $name) !== false || mb_strpos($name, $label) !== false)) {
				$hits[] = $row;
			}
		}
		return $hits;
	}

	/**
	 * @param	int		$severity	1/2/3
	 * @return	string				Translated label
	 */
	public static function severityLabel($severity)
	{
		global $langs;
		$map = array(1 => 'PatientSeverityMild', 2 => 'PatientSeverityModerate', 3 => 'PatientSeveritySevere');
		return $langs->trans(isset($map[(int) $severity]) ? $map[(int) $severity] : 'PatientSeverityMild');
	}
}
