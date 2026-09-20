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
 * \file    htdocs/custom/patient/tests/unit/PatientTest.php
 * \ingroup patient
 * \brief   modPatient structural tests, phase 1 (no DB needed).
 */

use PHPUnit\Framework\TestCase;

require_once dirname(dirname(__DIR__)).'/lib/patient.lib.php';

/**
 * Class PatientTest
 */
class PatientTest extends TestCase
{
	/**
	 * Descriptor: healthcare ID range, one-level permissions, clinic top menu,
	 * thirdparty tab, four dictionaries, triggers enabled, tables kept on remove.
	 */
	public function testDescriptor()
	{
		$content = file_get_contents(__DIR__.'/../../core/modules/modPatient.class.php');
		$this->assertStringContainsString('$this->numero = 501600;', $content, 'healthcare range starts at 501600 (spec §7)');
		$this->assertStringContainsString("rights_class = 'patient'", $content);
		$this->assertStringContainsString("_load_tables('/patient/sql/')", $content);
		foreach (array("'read'", "'write'", "'profile'", "'admin'") as $perm) {
			$this->assertStringContainsString('[4] = '.$perm, $content, 'one-level permission '.$perm.' (DEV.md §二.3)');
		}
		$this->assertStringNotContainsString('[5] = ', $content, 'two-level permissions break hasRight()');
		$this->assertStringContainsString("'mainmenu' => 'clinic'", $content, 'shared top menu for the healthcare suite');
		$this->assertStringContainsString("'type' => 'top'", $content);
		$this->assertStringContainsString("thirdparty:+patient:", $content, 'patient tab on thirdparty card');
		$this->assertStringContainsString("'triggers' => 1", $content);
		foreach (array('c_patient_department', 'c_patient_id_type', 'c_patient_allergy_type', 'c_patient_doctor_title') as $dict) {
			$this->assertStringContainsString("'".$dict."'", $content, 'dictionary '.$dict);
		}
		$this->assertStringContainsString("'tabhelp'", $content, 'tabhelp must exist (empty array breaks dict.php)');
		// remove() must not drop patient data (spec §4.2)
		$this->assertStringNotContainsString('DROP TABLE', $content);
	}

	/**
	 * SQL: every table has entity, unique keys for idempotency, sequence table
	 * is CREATE IF NOT EXISTS, audit has no updatable columns beyond insert.
	 */
	public function testSqlSchema()
	{
		$sqlDir = __DIR__.'/../../sql/';
		$profile = file_get_contents($sqlDir.'llx_patient_profile.sql');
		$this->assertStringContainsString('fk_soc', $profile);
		$this->assertStringContainsString('id_number_enc', $profile);
		$this->assertStringContainsString('id_number_hash', $profile);
		$this->assertStringContainsString('id_number_tail', $profile);
		$this->assertStringNotContainsString('id_number ', $profile, 'no plaintext ID column');
		$this->assertStringContainsString('entity', $profile);

		$profileKey = file_get_contents($sqlDir.'llx_patient_profile.key.sql');
		$this->assertStringContainsString('uk_patient_profile_soc', $profileKey);
		$this->assertStringContainsString('uk_patient_profile_card', $profileKey);
		$this->assertStringContainsString('idx_patient_profile_idhash', $profileKey);

		$seq = file_get_contents($sqlDir.'llx_patient_card_sequence.sql');
		$this->assertStringContainsString('CREATE TABLE IF NOT EXISTS', $seq);
		$this->assertStringContainsString('ENGINE=innodb', $seq, 'row locks need InnoDB');

		$audit = file_get_contents($sqlDir.'llx_patient_audit.sql');
		$this->assertStringContainsString('fk_patient', $audit);
		$this->assertStringContainsString('date_creation', $audit);
		$this->assertStringNotContainsString('ON UPDATE', $audit, 'audit rows are append-only');

		$this->assertTrue(is_file($sqlDir.'llx_patient_doctor.sql'));
		$this->assertTrue(is_file($sqlDir.'llx_patient_allergy.sql'));

		// Dictionaries: plain integer PK, unique code, seed data with fixed rowids
		$dict = file_get_contents($sqlDir.'llx_c_patient_dictionaries.sql');
		$this->assertStringNotContainsString('AUTO_INCREMENT', $dict, 'dict.php computes MAX(rowid)+1');
		$this->assertStringContainsString('uk_c_patient_department_code', file_get_contents($sqlDir.'llx_c_patient_dictionaries.key.sql'));
		$data = file_get_contents($sqlDir.'data_patient_dictionaries.sql');
		$this->assertStringContainsString("'IDCARD'", $data);
		$this->assertStringContainsString("'DRUG'", $data);
		$this->assertStringContainsString("'TCM_INT'", $data);
		$this->assertStringContainsString("'CHIEF'", $data);
		// _load_tables only runs data*.sql and llx_*.sql: check naming
		foreach (glob($sqlDir.'*.sql') as $file) {
			$base = basename($file);
			$this->assertTrue(strpos($base, 'llx_') === 0 || strpos($base, 'data') === 0, $base.' would be ignored by _load_tables');
		}
	}

	/**
	 * Trigger: class name convention, audit actions, never blocks business flow.
	 */
	public function testTrigger()
	{
		$file = __DIR__.'/../../core/triggers/interface_99_modPatient_PatientTriggers.class.php';
		$this->assertFileExists($file);
		$content = file_get_contents($file);
		$this->assertStringContainsString('class InterfacePatientTriggers extends DolibarrTriggers', $content);
		foreach (array('PATIENT_CREATE', 'PATIENT_MODIFY', 'PATIENT_ALLERGY_ADD', 'PATIENT_ALLERGY_DELETE') as $action) {
			$this->assertStringContainsString("'".$action."'", $content);
		}
		$this->assertStringContainsString('patient_audit(', $content);
		$this->assertStringNotContainsString('return -1', $content, 'audit failure must not block the business flow');
	}

	/**
	 * Every page checks a patient permission; admin pages require admin.
	 */
	public function testPagePermissions()
	{
		$root = __DIR__.'/../../';
		$pages = array(
			'patientindex.php' => "hasRight('patient', 'read')",
			'list.php' => "hasRight('patient', 'read')",
			'card.php' => "hasRight('patient', 'read')",
			'tab.php' => "hasRight('patient', 'read')",
			'admin/setup.php' => "hasRight('patient', 'admin')",
			'admin/doctors.php' => "hasRight('patient', 'admin')",
		);
		foreach ($pages as $page => $needle) {
			$content = file_get_contents($root.$page);
			$this->assertStringContainsString($needle, $content, $page.' must check '.$needle);
			$this->assertStringContainsString('accessforbidden()', $content, $page);
		}
		// tab.php also needs the thirdparty read permission
		$this->assertStringContainsString("hasRight('societe', 'lire')", file_get_contents($root.'tab.php'));
	}

	/**
	 * Phase 2: PatientProfile transaction shape, no plaintext column, search
	 * never selects the ciphertext, pages carry permission checks.
	 */
	public function testProfileClassShape()
	{
		$file = __DIR__.'/../../class/patientprofile.class.php';
		$this->assertFileExists($file);
		$content = file_get_contents($file);
		$this->assertStringContainsString('class PatientProfile extends CommonObject', $content);
		$this->assertStringContainsString("const TRIGGER_PREFIX = 'PATIENT'", $content);
		$this->assertStringContainsString('TYPENT_INDIVIDUAL = 8', $content, 'patient = individual thirdparty');
		$this->assertStringContainsString('$this->db->begin();', $content);
		$this->assertStringContainsString('new PatientCardNumbering($this->db)', $content, 'card number reserved on the same connection/transaction');
		$this->assertStringContainsString('dolEncrypt(', $content);
		$this->assertStringContainsString('dolDecrypt(', $content);
		foreach (array("'PATIENT_CREATE'", "'PATIENT_MODIFY'", "'PATIENT_DISABLE'") as $trigger) {
			$this->assertStringContainsString($trigger, $content);
		}
		$this->assertStringNotContainsString('DELETE FROM', $content, 'records are never deleted');
		// search(): the SELECT list must never include the ciphertext
		$searchPos = strpos($content, 'public function search(');
		$searchBody = substr($content, $searchPos, strpos($content, 'public function getNomUrl(') - $searchPos);
		$this->assertStringNotContainsString('id_number_enc', $searchBody, 'list query must not read the ciphertext');
		$this->assertStringContainsString('id_number_hash = ', $searchBody, 'exact hash lookup');
		// ID plaintext never logged
		$this->assertStringNotContainsString('dol_syslog(__METHOD__.\' \'.$plain', $content);
	}

	/**
	 * Phase 2 pages: card requires write to create/update, admin to disable,
	 * profile to reveal the ID; list never touches the ciphertext.
	 */
	public function testCardAndListPages()
	{
		$card = file_get_contents(__DIR__.'/../../card.php');
		$this->assertStringContainsString("\$action == 'add' && \$canWrite", $card);
		$this->assertStringContainsString("\$action == 'update' && \$canWrite", $card);
		$this->assertStringContainsString("\$canAdmin && \$object->id > 0", $card);
		$this->assertStringContainsString("\$action == 'showid' && \$canProfile", $card, 'plain ID only for profile');
		$this->assertStringContainsString("'READ_IDNUMBER'", $card, 'revealing the ID is audited');
		$this->assertStringContainsString('newToken()', $card, 'CSRF token on forms');
		$this->assertStringContainsString('input[name=town]', $card, 'chinadiv cascade anchor documented');
		$this->assertStringContainsString("isModEnabled('chinadiv')", $card, 'chinadiv is optional');

		$list = file_get_contents(__DIR__.'/../../list.php');
		$this->assertStringNotContainsString('id_number_enc', $list);
		$this->assertStringContainsString('<form method="GET"', $list);
		$this->assertTrue(strpos($list, '<form method="GET"') < strpos($list, 'print_barre_liste('), 'form must open before print_barre_liste (DEV.md §二.6)');
		$this->assertStringContainsString("'&limit='.(int) \$limit", $list, 'page links must carry limit');
		$this->assertStringContainsString("(int) GETPOST('page', 'int')", $list, 'page must be cast (DEV.md §二.7)');
	}

	/**
	 * Lib: hashing is deterministic and normalized, masking never leaks more
	 * than the tail, and nothing here echoes the plaintext.
	 */
	public function testIdHelpers()
	{
		$this->assertSame(patient_hash_id('11010519491231002x'), patient_hash_id(' 11010519491231002X '), 'normalized before hashing');
		$this->assertSame(64, strlen(patient_hash_id('123')));
		$this->assertSame('', patient_hash_id('   '));
		$this->assertNotEquals(patient_hash_id('A'), patient_hash_id('B'));

		$this->assertSame('002X', patient_id_tail('11010519491231002x'));
		$this->assertSame('******002X', patient_mask_id('002X'));
		$this->assertSame('', patient_mask_id(''));

		// GB 11643 check digit: known-valid sample, then break the check digit
		$this->assertTrue(patient_validate_prc_id('11010519491231002X'));
		$this->assertFalse(patient_validate_prc_id('110105194912310021'));
		$this->assertFalse(patient_validate_prc_id('123'));
		$this->assertSame('1949-12-31', patient_birth_from_prc_id('11010519491231002X'));
		$this->assertSame('', patient_birth_from_prc_id('11010519491399002X'), 'invalid date rejected');

		$lib = file_get_contents(__DIR__.'/../../lib/patient.lib.php');
		$this->assertStringNotContainsString('dol_syslog($number', $lib, 'never log the ID number');
	}

	/**
	 * Phase 3: allergies never deleted, only readable/writable with 'profile',
	 * reads are audited, audit page is admin-only and has no write path.
	 */
	public function testAllergiesAndAudit()
	{
		$cls = file_get_contents(__DIR__.'/../../class/patientallergy.class.php');
		$this->assertStringContainsString('class PatientAllergy extends CommonObject', $cls);
		$this->assertStringContainsString("const TRIGGER_PREFIX = 'PATIENT'", $cls);
		$this->assertStringContainsString("'PATIENT_ALLERGY_ADD'", $cls);
		$this->assertStringContainsString("'PATIENT_ALLERGY_DELETE'", $cls);
		$this->assertStringNotContainsString('DELETE FROM', $cls, 'allergies are soft-removed');
		$this->assertStringContainsString('SET status = 0', $cls);
		$this->assertStringContainsString('public function findConflicts(', $cls, 'prescription check entry point');

		$page = file_get_contents(__DIR__.'/../../allergies.php');
		$this->assertStringContainsString("hasRight('patient', 'profile')", $page);
		$this->assertStringContainsString('accessforbidden(', $page);
		$this->assertStringContainsString("'READ_PROFILE'", $page, 'opening the allergy tab is audited');
		$this->assertStringContainsString('newToken()', $page);
		$this->assertStringContainsString("isModEnabled('product')", $page, 'product module optional');

		$card = file_get_contents(__DIR__.'/../../card.php');
		$this->assertStringContainsString("'READ_PROFILE'", $card, 'viewing medical data on the card is audited');
		$this->assertStringContainsString('if ($canProfile && $object->id > 0', $card, 'allergies only queried with profile');

		$audit = file_get_contents(__DIR__.'/../../admin/audit.php');
		$this->assertStringContainsString("hasRight('patient', 'admin')", $audit);
		$this->assertStringContainsString('accessforbidden()', $audit);
		foreach (array('DELETE', 'UPDATE', 'INSERT') as $verb) {
			$this->assertStringNotContainsString($verb.' ', $audit, 'audit page must be read-only');
		}
		$this->assertTrue(strpos($audit, '<form method="GET"') < strpos($audit, 'print_barre_liste('), 'form before print_barre_liste');

		$lib = file_get_contents(__DIR__.'/../../lib/patient.lib.php');
		$this->assertStringContainsString("hasRight('patient', 'profile')", $lib, 'allergy tab hidden without profile');
		$this->assertStringContainsString("'audit'", $lib);
	}

	/**
	 * Phase 4: REST API. Every endpoint checks a permission, no endpoint can
	 * return the ciphertext/hash/plaintext, thirdparty tab registers only
	 * individuals, doctors page is admin-only with an idempotent upsert.
	 */
	public function testApiAndIntegration()
	{
		$api = file_get_contents(__DIR__.'/../../class/api_patient.class.php');
		$this->assertStringContainsString('class Patient extends DolibarrApi', $api, 'api_patient.class.php => class Patient (DEV.md §二.19)');
		$this->assertStringContainsString('DolibarrApiAccess {@requires user,external}', $api);
		// Each @url endpoint (except the constructor's "GET /") is gated by hasRight before any data access
		preg_match_all('/@url\s+(GET|POST|DELETE|PUT)\s+(\S+)/', $api, $urls);
		$endpoints = count($urls[0]) - 1;
		$this->assertGreaterThan(5, $endpoints, 'expected endpoints');
		$this->assertSame($endpoints, substr_count($api, "DolibarrApiAccess::\$user->hasRight('patient'") - 2, 'one permission gate per endpoint (+2 inner profile gates)');
		foreach (array('id_number_enc', 'id_number_hash', 'getIdNumberPlain', 'dolDecrypt') as $forbidden) {
			$this->assertStringNotContainsString($forbidden, $api, 'API must never expose '.$forbidden);
		}
		$this->assertStringContainsString("'READ_PROFILE'", $api, 'API medical reads are audited');
		$this->assertStringContainsString("hasRight('patient', 'profile')", $api);
		$this->assertStringContainsString('@url	GET patients/{id}/allergies', $api);
		$this->assertStringContainsString('@url	DELETE patients/{id}/allergies/{aid}', $api);

		$tab = file_get_contents(__DIR__.'/../../tab.php');
		$this->assertStringContainsString('createForThirdparty(', $tab);
		$this->assertStringContainsString("hasRight('patient', 'write')", $tab);
		$this->assertStringContainsString('TYPENT_INDIVIDUAL', $tab, 'only individuals can become patients');
		$this->assertStringContainsString('<form method="POST"', $tab, 'registration is a POST with token');

		$profile = file_get_contents(__DIR__.'/../../class/patientprofile.class.php');
		$this->assertStringContainsString('public function createForThirdparty(', $profile);
		$this->assertStringContainsString("'PatientAlreadyExists'", $profile);
		$this->assertStringContainsString("'PatientNotIndividual'", $profile);

		$doctors = file_get_contents(__DIR__.'/../../admin/doctors.php');
		$this->assertStringContainsString("hasRight('patient', 'admin')", $doctors);
		$this->assertStringContainsString('ON DUPLICATE KEY UPDATE', $doctors, 'idempotent upsert on fk_user (DEV.md §二.14)');
		$this->assertStringNotContainsString('DELETE FROM', $doctors);

		$lib = file_get_contents(__DIR__.'/../../lib/patient.lib.php');
		$this->assertStringContainsString('function patient_doctor_options', $lib, 'selector for later modules');
	}

	/**
	 * 0.1.1: integration surface for later healthcare modules: patient card
	 * tabs open to external modules, AJAX patient picker gated by read and
	 * ciphertext-free, summary helper without ID number.
	 */
	public function testIntegrationSurface011()
	{
		$lib = file_get_contents(__DIR__.'/../../lib/patient.lib.php');
		$this->assertStringContainsString("complete_head_from_modules(\$conf, \$langs, \$object, \$head, \$h, 'patient'", $lib, 'external modules can add patient tabs');
		foreach (array('function patient_select_html', 'function patient_get_summary', 'function patient_summary_banner') as $fn) {
			$this->assertStringContainsString($fn, $lib);
		}
		$this->assertStringContainsString('ajax_autocompleter(', $lib);
		$summaryPos = strpos($lib, 'function patient_get_summary');
		$summaryBody = substr($lib, $summaryPos, strpos($lib, 'function patient_summary_banner') - $summaryPos);
		foreach (array('id_number', 'getIdNumberPlain', 'getIdNumberMasked') as $forbidden) {
			$this->assertStringNotContainsString($forbidden, $summaryBody, 'summary never carries the ID number');
		}
		$this->assertStringContainsString("hasRight('patient', 'profile')", $summaryBody, 'allergies only for profile');

		$ajax = file_get_contents(__DIR__.'/../../ajax/search.php');
		$this->assertStringContainsString("hasRight('patient', 'read')", $ajax);
		$this->assertStringContainsString('403 Forbidden', $ajax);
		$this->assertStringNotContainsString('id_number_enc', $ajax);
		$this->assertStringContainsString("'key' =>", $ajax, 'jQuery autocomplete contract');
		$this->assertStringContainsString("'value' =>", $ajax);

		$desc = file_get_contents(__DIR__.'/../../core/modules/modPatient.class.php');
		$this->assertStringContainsString("version = '0.1.1'", $desc);
	}

	/**
	 * Language files: both locales define the same keys, no hard-coded text
	 * keys missing on either side.
	 */
	public function testLangFilesInSync()
	{
		$zh = $this->langKeys(__DIR__.'/../../langs/zh_CN/patient.lang');
		$en = $this->langKeys(__DIR__.'/../../langs/en_US/patient.lang');
		$this->assertSame(array(), array_values(array_diff($zh, $en)), 'keys missing in en_US');
		$this->assertSame(array(), array_values(array_diff($en, $zh)), 'keys missing in zh_CN');
		foreach (array('ModulePatientName', 'ClinicMenu', 'PatientTab', 'PatientDictDepartment', 'PatientPermProfile') as $key) {
			$this->assertTrue(in_array($key, $zh, true), 'lang key '.$key);
		}
	}

	/**
	 * @param string $file lang file path
	 * @return string[] keys
	 */
	private function langKeys($file)
	{
		$keys = array();
		foreach (file($file) as $line) {
			$line = trim($line);
			if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) {
				continue;
			}
			$keys[] = trim(substr($line, 0, strpos($line, '=')));
		}
		return $keys;
	}
}
