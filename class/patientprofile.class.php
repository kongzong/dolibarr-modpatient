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
 * \file    htdocs/custom/patient/class/patientprofile.class.php
 * \ingroup patient
 * \brief   Patient = individual thirdparty (fk_typent 8) + this 1:1 profile.
 *          create() builds both in one transaction and reserves the card
 *          number inside it (spec §2.2). ID number plaintext lives only in
 *          memory: encrypted, hashed and tailed before any SQL (spec §4.1).
 */

require_once DOL_DOCUMENT_ROOT.'/core/class/commonobject.class.php';
require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
dol_include_once('/patient/class/patientcardnumbering.class.php');
dol_include_once('/patient/lib/patient.lib.php');

/**
 * Class PatientProfile
 */
class PatientProfile extends CommonObject
{
	/** Individual thirdparty type (llx_c_typent id 8, code TE_PRIVATE) */
	const TYPENT_INDIVIDUAL = 8;

	const TRIGGER_PREFIX = 'PATIENT';

	public $element = 'patientprofile';
	public $table_element = 'patient_profile';
	public $picto = 'user';

	public $id;
	public $entity;
	public $fk_soc;
	public $card_no;
	public $id_type;
	/** @var string dolcrypt ciphertext, never the plaintext */
	public $id_number_enc;
	public $id_number_hash;
	public $id_number_tail;
	public $gender = 'U';
	/** @var string YYYY-MM-DD or '' */
	public $birth_date = '';
	public $blood_type;
	public $phone_alt;
	public $emergency_name;
	public $emergency_phone;
	public $history_note;
	public $note_private;
	public $status = 1;
	public $fk_user_creat;
	public $fk_user_modif;
	public $date_creation;
	public $tms;

	/** @var Societe|null Loaded thirdparty (name, phone, address...) */
	public $thirdparty;

	/** @var array<string,mixed> Non-sensitive context handed to the audit trigger */
	public $audit_detail = array();

	/**
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	// ------------------------------------------------------------ sensitive field

	/**
	 * Set the ID number from plaintext: encrypt, hash, keep the tail. The
	 * plaintext is not stored on the object.
	 *
	 * @param	string	$plain	ID number or '' to clear
	 * @return	void
	 */
	public function setIdNumber($plain)
	{
		$normalized = patient_normalize_id($plain);
		if ($normalized === '') {
			$this->id_number_enc = '';
			$this->id_number_hash = '';
			$this->id_number_tail = '';
			return;
		}
		$this->id_number_enc = dolEncrypt($normalized);
		$this->id_number_hash = patient_hash_id($normalized);
		$this->id_number_tail = patient_id_tail($normalized);
	}

	/**
	 * Decrypt the ID number. Callers must hold the 'profile' permission and
	 * write a READ_IDNUMBER audit row before displaying it.
	 *
	 * @return	string	Plaintext or '' when none / undecryptable
	 */
	public function getIdNumberPlain()
	{
		if (empty($this->id_number_enc)) {
			return '';
		}
		$plain = dolDecrypt($this->id_number_enc);
		if ($plain === $this->id_number_enc) {
			// Key missing or ciphertext from another instance: never expose the blob
			return '';
		}
		return $plain;
	}

	/**
	 * @return	string	Masked ID for lists and the default card view
	 */
	public function getIdNumberMasked()
	{
		return patient_mask_id($this->id_number_tail);
	}

	// ------------------------------------------------------------ create

	/**
	 * Register a patient: thirdparty + card number + profile in one transaction.
	 * On any failure everything is rolled back; no orphan thirdparty remains.
	 *
	 * @param	User	$user		Acting user
	 * @param	array	$soc		Thirdparty fields: name (required), phone, email, address, zip, town, state_id, country_id
	 * @param	int		$notrigger	1 to skip PATIENT_CREATE (audit)
	 * @return	int					>0 profile rowid, <0 on error (this->error set)
	 */
	public function create(User $user, array $soc, $notrigger = 0)
	{
		$this->error = '';
		$this->errors = array();

		$name = trim((string) (isset($soc['name']) ? $soc['name'] : ''));
		if ($name === '') {
			$this->error = 'ErrorFieldRequired';
			return -1;
		}

		$this->db->begin();

		try {
			// 1. Individual thirdparty, customer so invoices can be issued later
			$thirdparty = new Societe($this->db);
			$thirdparty->name = $name;
			$thirdparty->typent_id = self::TYPENT_INDIVIDUAL;
			$thirdparty->client = 1;
			$thirdparty->code_client = -1; // let the configured customer-code module decide
			$thirdparty->status = 1;
			foreach (array('phone', 'email', 'address', 'zip', 'town') as $field) {
				if (isset($soc[$field])) {
					$thirdparty->$field = trim((string) $soc[$field]);
				}
			}
			$thirdparty->state_id = !empty($soc['state_id']) ? (int) $soc['state_id'] : 0;
			$thirdparty->country_id = !empty($soc['country_id']) ? (int) $soc['country_id'] : 0;

			$socId = $thirdparty->create($user);
			if ($socId <= 0) {
				$this->setErrorsFromObject($thirdparty);
				if (empty($this->error)) {
					$this->error = 'ErrorThirdPartyCreation';
				}
				throw new RuntimeException($this->error);
			}

			// 2+3. Card number and profile row, same transaction
			$this->insertProfile($user, (int) $socId, $notrigger);
			$this->thirdparty = $thirdparty;

			$this->db->commit();
			return $this->id;
		} catch (Throwable $e) {
			return $this->abortCreate($e);
		}
	}

	/**
	 * Register an EXISTING individual thirdparty as a patient (thirdparty tab).
	 * Refuses companies and thirdparties that already have a profile.
	 *
	 * @param	User	$user		Acting user
	 * @param	int		$fkSoc		Thirdparty id
	 * @param	int		$notrigger	1 to skip PATIENT_CREATE
	 * @return	int					>0 profile rowid, <0 on error
	 */
	public function createForThirdparty(User $user, $fkSoc, $notrigger = 0)
	{
		$this->error = '';
		$this->errors = array();

		$thirdparty = new Societe($this->db);
		if ((int) $fkSoc <= 0 || $thirdparty->fetch((int) $fkSoc) <= 0) {
			$this->error = 'ErrorRecordNotFound';
			return -1;
		}
		if ((int) $thirdparty->typent_id !== self::TYPENT_INDIVIDUAL) {
			$this->error = 'PatientNotIndividual';
			return -2;
		}
		$existing = new PatientProfile($this->db);
		if ($existing->fetch(0, (int) $fkSoc) > 0) {
			$this->error = 'PatientAlreadyExists';
			return -3;
		}

		$this->db->begin();
		try {
			$this->insertProfile($user, (int) $fkSoc, $notrigger);
			$this->thirdparty = $thirdparty;
			$this->db->commit();
			return $this->id;
		} catch (Throwable $e) {
			return $this->abortCreate($e);
		}
	}

	/**
	 * Reserve the card number and insert the profile row. Must run inside an
	 * open transaction; throws on any failure.
	 *
	 * @param	User	$user		Acting user
	 * @param	int		$socId		Thirdparty id
	 * @param	int		$notrigger	1 to skip PATIENT_CREATE
	 * @return	void
	 * @throws	RuntimeException
	 */
	private function insertProfile(User $user, $socId, $notrigger)
	{
		global $conf;

		if (!in_array($this->gender, array('M', 'F', 'U'), true)) {
			$this->gender = 'U';
		}
		if ($this->birth_date !== '' && !preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/', (string) $this->birth_date)) {
			$this->error = 'ErrorBadDateFormat';
			throw new RuntimeException($this->error);
		}

		$numbering = new PatientCardNumbering($this->db);
		$this->card_no = $numbering->nextReference(PatientCardNumbering::prefixFor(dol_now()));

		$this->fk_soc = (int) $socId;
		$this->entity = !empty($conf->entity) ? (int) $conf->entity : 1;
		$this->fk_user_creat = (int) $user->id;
		$this->date_creation = dol_now();
		$this->status = 1;

		$sql = "INSERT INTO ".$this->db->prefix()."patient_profile (";
		$sql .= "entity, fk_soc, card_no, id_type, id_number_enc, id_number_hash, id_number_tail, gender, birth_date,";
		$sql .= " blood_type, phone_alt, emergency_name, emergency_phone, history_note, note_private, status, fk_user_creat, date_creation";
		$sql .= ") VALUES (";
		$sql .= ((int) $this->entity).", ".((int) $this->fk_soc).", '".$this->db->escape($this->card_no)."',";
		$sql .= " ".$this->nullOrString($this->id_type).", ".$this->nullOrString($this->id_number_enc).",";
		$sql .= " ".$this->nullOrString($this->id_number_hash).", ".$this->nullOrString($this->id_number_tail).",";
		$sql .= " '".$this->db->escape($this->gender)."', ".$this->nullOrString($this->birth_date).",";
		$sql .= " ".$this->nullOrString($this->blood_type).", ".$this->nullOrString($this->phone_alt).",";
		$sql .= " ".$this->nullOrString($this->emergency_name).", ".$this->nullOrString($this->emergency_phone).",";
		$sql .= " ".$this->nullOrString($this->history_note).", ".$this->nullOrString($this->note_private).",";
		$sql .= " 1, ".((int) $this->fk_user_creat).", '".$this->db->idate($this->date_creation)."'";
		$sql .= ")";

		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			throw new RuntimeException('profile insert failed');
		}
		$this->id = (int) $this->db->last_insert_id($this->db->prefix().'patient_profile');

		if (!$notrigger) {
			$this->audit_detail = array('card_no' => $this->card_no, 'fk_soc' => $this->fk_soc, 'has_id' => ($this->id_number_hash !== '' && $this->id_number_hash !== null));
			if ($this->call_trigger('PATIENT_CREATE', $user) < 0) {
				throw new RuntimeException('trigger failed');
			}
		}
	}

	/**
	 * Roll back every transaction level after a failed registration.
	 *
	 * @param	Throwable	$e	Cause
	 * @return	int				-1
	 */
	private function abortCreate($e)
	{
		if (empty($this->error)) {
			$this->error = $e->getMessage();
		}
		dol_syslog(__METHOD__.' failed: '.$this->error, LOG_ERR);
		// Numbering failures already unwound the transaction; otherwise roll back all levels
		while ($this->db->transaction_opened > 0) {
			if (!$this->db->rollback()) {
				break;
			}
		}
		$this->id = 0;
		$this->card_no = '';
		return -1;
	}

	// ------------------------------------------------------------ fetch

	/**
	 * @param	int		$id		Profile rowid
	 * @param	int		$fkSoc	Or thirdparty id
	 * @param	string	$cardNo	Or card number
	 * @return	int				1 found, 0 not found, <0 error
	 */
	public function fetch($id = 0, $fkSoc = 0, $cardNo = '')
	{
		global $conf;

		$sql = "SELECT p.rowid, p.entity, p.fk_soc, p.card_no, p.id_type, p.id_number_enc, p.id_number_hash, p.id_number_tail,";
		$sql .= " p.gender, p.birth_date, p.blood_type, p.phone_alt, p.emergency_name, p.emergency_phone,";
		$sql .= " p.history_note, p.note_private, p.status, p.fk_user_creat, p.fk_user_modif, p.date_creation, p.tms";
		$sql .= " FROM ".$this->db->prefix()."patient_profile as p";
		$sql .= " WHERE p.entity = ".(!empty($conf->entity) ? (int) $conf->entity : 1);
		if ((int) $id > 0) {
			$sql .= " AND p.rowid = ".((int) $id);
		} elseif ((int) $fkSoc > 0) {
			$sql .= " AND p.fk_soc = ".((int) $fkSoc);
		} elseif ($cardNo !== '') {
			$sql .= " AND p.card_no = '".$this->db->escape($cardNo)."'";
		} else {
			return -1;
		}

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
		$this->fk_soc = (int) $obj->fk_soc;
		$this->card_no = $obj->card_no;
		$this->id_type = $obj->id_type;
		$this->id_number_enc = $obj->id_number_enc;
		$this->id_number_hash = $obj->id_number_hash;
		$this->id_number_tail = $obj->id_number_tail;
		$this->gender = $obj->gender;
		$this->birth_date = $obj->birth_date ? substr($obj->birth_date, 0, 10) : '';
		$this->blood_type = $obj->blood_type;
		$this->phone_alt = $obj->phone_alt;
		$this->emergency_name = $obj->emergency_name;
		$this->emergency_phone = $obj->emergency_phone;
		$this->history_note = $obj->history_note;
		$this->note_private = $obj->note_private;
		$this->status = (int) $obj->status;
		$this->fk_user_creat = $obj->fk_user_creat;
		$this->fk_user_modif = $obj->fk_user_modif;
		$this->date_creation = $this->db->jdate($obj->date_creation);
		$this->tms = $this->db->jdate($obj->tms);

		$this->thirdparty = new Societe($this->db);
		if ($this->thirdparty->fetch($this->fk_soc) <= 0) {
			$this->thirdparty = null;
		}
		return 1;
	}

	// ------------------------------------------------------------ update

	/**
	 * Update profile fields and the thirdparty's contact fields.
	 * Card number and fk_soc are immutable. ID number changes only when
	 * $newIdNumber is not null (empty string clears it).
	 *
	 * @param	User		$user			Acting user
	 * @param	array		$soc			Thirdparty fields to update (subset of create())
	 * @param	string|null	$newIdNumber	Plaintext ID number, '' to clear, null to keep
	 * @param	int			$notrigger		1 to skip PATIENT_MODIFY
	 * @return	int							1 ok, <0 error
	 */
	public function update(User $user, array $soc = array(), $newIdNumber = null, $notrigger = 0)
	{
		if ($this->id <= 0) {
			return -1;
		}
		if (!in_array($this->gender, array('M', 'F', 'U'), true)) {
			$this->gender = 'U';
		}
		if ($this->birth_date !== '' && !preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/', (string) $this->birth_date)) {
			$this->error = 'ErrorBadDateFormat';
			return -1;
		}

		$changed = array();
		if ($newIdNumber !== null) {
			$oldHash = $this->id_number_hash;
			$this->setIdNumber($newIdNumber);
			if ($oldHash !== $this->id_number_hash) {
				$changed[] = 'id_number';
			}
		}

		$this->db->begin();

		if (!empty($soc) && $this->fk_soc > 0) {
			$thirdparty = new Societe($this->db);
			if ($thirdparty->fetch($this->fk_soc) > 0) {
				foreach (array('name', 'phone', 'email', 'address', 'zip', 'town') as $field) {
					if (isset($soc[$field]) && trim((string) $soc[$field]) !== (string) $thirdparty->$field) {
						$thirdparty->$field = trim((string) $soc[$field]);
						$changed[] = 'soc.'.$field;
					}
				}
				if (isset($soc['state_id'])) {
					$thirdparty->state_id = (int) $soc['state_id'];
				}
				if (isset($soc['country_id'])) {
					$thirdparty->country_id = (int) $soc['country_id'];
				}
				if ($thirdparty->name === '') {
					$this->db->rollback();
					$this->error = 'ErrorFieldRequired';
					return -1;
				}
				if ($thirdparty->update($thirdparty->id, $user) < 0) {
					$this->setErrorsFromObject($thirdparty);
					$this->db->rollback();
					return -1;
				}
				$this->thirdparty = $thirdparty;
			}
		}

		$sql = "UPDATE ".$this->db->prefix()."patient_profile SET";
		$sql .= " id_type = ".$this->nullOrString($this->id_type);
		$sql .= ", id_number_enc = ".$this->nullOrString($this->id_number_enc);
		$sql .= ", id_number_hash = ".$this->nullOrString($this->id_number_hash);
		$sql .= ", id_number_tail = ".$this->nullOrString($this->id_number_tail);
		$sql .= ", gender = '".$this->db->escape($this->gender)."'";
		$sql .= ", birth_date = ".$this->nullOrString($this->birth_date);
		$sql .= ", blood_type = ".$this->nullOrString($this->blood_type);
		$sql .= ", phone_alt = ".$this->nullOrString($this->phone_alt);
		$sql .= ", emergency_name = ".$this->nullOrString($this->emergency_name);
		$sql .= ", emergency_phone = ".$this->nullOrString($this->emergency_phone);
		$sql .= ", history_note = ".$this->nullOrString($this->history_note);
		$sql .= ", note_private = ".$this->nullOrString($this->note_private);
		$sql .= ", fk_user_modif = ".((int) $user->id);
		$sql .= " WHERE rowid = ".((int) $this->id);

		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			$this->db->rollback();
			return -1;
		}

		if (!$notrigger) {
			$this->audit_detail = array('changed' => array_values(array_unique($changed)));
			if ($this->call_trigger('PATIENT_MODIFY', $user) < 0) {
				$this->db->rollback();
				return -1;
			}
		}

		$this->db->commit();
		return 1;
	}

	/**
	 * Soft-disable (status 0). Records are never deleted (spec §4.2).
	 *
	 * @param	User	$user	Acting user
	 * @param	int		$status	1 active / 0 disabled
	 * @return	int				1 ok, <0 error
	 */
	public function setStatus(User $user, $status)
	{
		if ($this->id <= 0) {
			return -1;
		}
		$status = $status ? 1 : 0;
		$this->db->begin();
		$sql = "UPDATE ".$this->db->prefix()."patient_profile SET status = ".$status.", fk_user_modif = ".((int) $user->id)." WHERE rowid = ".((int) $this->id);
		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			$this->db->rollback();
			return -1;
		}
		$this->status = $status;
		$this->audit_detail = array('status' => $status);
		if ($this->call_trigger($status ? 'PATIENT_MODIFY' : 'PATIENT_DISABLE', $user) < 0) {
			$this->db->rollback();
			return -1;
		}
		$this->db->commit();
		return 1;
	}

	// ------------------------------------------------------------ list / search

	/**
	 * Search patients by card number, name, phone, or exact ID number (hashed).
	 * Never selects id_number_enc.
	 *
	 * @param	string	$q			Free text
	 * @param	int		$limit		Page size
	 * @param	int		$offset		Offset
	 * @param	int		$status		-1 all, 0/1 filter
	 * @return	array{total:int,rows:array<int,object>}|null	null on SQL error
	 */
	public function search($q = '', $limit = 25, $offset = 0, $status = -1)
	{
		global $conf;

		$q = trim((string) $q);
		$entity = !empty($conf->entity) ? (int) $conf->entity : 1;

		$where = " WHERE p.entity = ".$entity;
		if ($status === 0 || $status === 1) {
			$where .= " AND p.status = ".$status;
		}
		if ($q !== '') {
			$like = "'%".$this->db->escape($q)."%'";
			$where .= " AND (p.card_no LIKE ".$like." OR s.nom LIKE ".$like." OR s.phone LIKE ".$like;
			// An ID-looking query also matches the hash (exact only, spec §3)
			if (preg_match('/^[0-9A-Za-z]{6,20}$/', $q)) {
				$where .= " OR p.id_number_hash = '".$this->db->escape(patient_hash_id($q))."'";
			}
			$where .= ")";
		}

		$from = " FROM ".$this->db->prefix()."patient_profile as p";
		$from .= " INNER JOIN ".$this->db->prefix()."societe as s ON s.rowid = p.fk_soc";

		$resql = $this->db->query("SELECT COUNT(p.rowid) as total".$from.$where);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return null;
		}
		$total = (int) $this->db->fetch_object($resql)->total;
		$this->db->free($resql);

		$sql = "SELECT p.rowid, p.fk_soc, p.card_no, p.gender, p.birth_date, p.id_number_tail, p.status, p.date_creation,";
		$sql .= " s.nom as name, s.phone, s.town";
		$sql .= $from.$where." ORDER BY p.rowid DESC";
		$sql .= $this->db->plimit((int) $limit, (int) $offset);
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return null;
		}
		$rows = array();
		while ($obj = $this->db->fetch_object($resql)) {
			$rows[] = $obj;
		}
		$this->db->free($resql);

		return array('total' => $total, 'rows' => $rows);
	}

	// ------------------------------------------------------------ helpers

	/**
	 * @param	int		$withpicto	0/1
	 * @return	string				Link to the patient card
	 */
	public function getNomUrl($withpicto = 0)
	{
		global $langs;
		$label = $this->card_no;
		if ($this->thirdparty) {
			$label .= ' - '.$this->thirdparty->name;
		}
		$url = dol_buildpath('/patient/card.php', 1).'?id='.((int) $this->id);
		$out = '<a href="'.$url.'" title="'.dol_escape_htmltag($langs->trans('PatientTab')).'">';
		if ($withpicto) {
			$out .= img_picto('', $this->picto, 'class="pictofixedwidth"');
		}
		$out .= dol_escape_htmltag($label).'</a>';
		return $out;
	}

	/**
	 * @param	int		$mode	Unused (CommonObject signature)
	 * @return	string			Translated status
	 */
	public function getLibStatut($mode = 0)
	{
		global $langs;
		return $this->status ? $langs->trans('Enabled') : $langs->trans('Disabled');
	}

	/**
	 * Age in full years from birth_date, or null.
	 *
	 * @return	int|null
	 */
	public function getAge()
	{
		if (!preg_match('/^([0-9]{4})-([0-9]{2})-([0-9]{2})$/', (string) $this->birth_date, $m)) {
			return null;
		}
		$birth = mktime(0, 0, 0, (int) $m[2], (int) $m[3], (int) $m[1]);
		$now = dol_now();
		$age = (int) date('Y', $now) - (int) $m[1];
		if (date('md', $now) < $m[2].$m[3]) {
			$age--;
		}
		return max(0, $age);
	}

	/**
	 * @param	mixed	$value	Field value
	 * @return	string			SQL literal or NULL
	 */
	private function nullOrString($value)
	{
		if ($value === null || $value === '') {
			return 'NULL';
		}
		return "'".$this->db->escape((string) $value)."'";
	}
}
