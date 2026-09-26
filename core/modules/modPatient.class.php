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
 *  \defgroup   patient     Module Patient
 *  \brief      Patient records and clinic card numbers for Chinese clinics.
 *
 *  \file       htdocs/custom/patient/core/modules/modPatient.class.php
 *  \ingroup    patient
 *  \brief      Description and activation file for module Patient
 */
include_once DOL_DOCUMENT_ROOT.'/core/modules/DolibarrModules.class.php';

/**
 *  Description and activation class for module Patient
 */
class modPatient extends DolibarrModules
{
	/**
	 * Constructor. Define names, constants, directories, boxes, permissions
	 *
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		global $conf, $langs;

		$this->db = $db;

		// Healthcare module family: 501600-501660, +10 per module (spec §7)
		$this->numero = 501600;

		// Key text used to identify module (for permissions, menus, etc...)
		$this->rights_class = 'patient';

		$this->family = "crm";
		$this->module_position = '91';

		// Module label (no space allowed)
		$this->name = preg_replace('/^mod/i', '', get_class($this));

		$this->description = "ModulePatientDesc";
		$this->descriptionlong = "ModulePatientDescLong";

		$this->editor_name = 'modPatient';
		$this->editor_url = 'https://github.com/kongzong/dolibarr-modpatient';

		$this->version = '0.1.6';

		// Key used in llx_const table to save module status enabled/disabled
		$this->const_name = 'MAIN_MODULE_'.strtoupper($this->name);

		$this->picto = 'user';

		$this->module_parts = array(
			'triggers' => 1,
			'login' => 0,
			'substitutions' => 0,
			'menus' => 0,
			'tpl' => 0,
			'barcode' => 0,
			'models' => 0,
			'printing' => 0,
			'theme' => 0,
			'css' => array(),
			'js' => array(),
			'hooks' => array(),
			'moduleforexternal' => 0,
			'websitetemplates' => 0,
			'captcha' => 0,
		);

		$this->dirs = array("/patient/temp");

		$this->config_page_url = array("setup.php@patient");

		// chinadiv is optional and detected at runtime (spec §2.7)
		$this->hidden = getDolGlobalInt('MODULE_PATIENT_DISABLED');
		$this->depends = array('modSociete');
		$this->requiredby = array();
		$this->conflictwith = array();

		$this->langfiles = array("patient@patient");

		$this->phpmin = array(7, 4);
		$this->need_dolibarr_version = array(20, -3);
		$this->need_javascript_ajax = 0;

		$this->warnings_activation = array();
		$this->warnings_activation_ext = array();

		$this->const = array();

		if (!isModEnabled("patient")) {
			$conf->patient = new stdClass();
			$conf->patient->enabled = 0;
		}

		// "Patient record" tab on the thirdparty card (spec §2.3)
		$this->tabs = array();
		$this->tabs[] = array('data' => 'thirdparty:+patient:PatientTab:patient@patient:$user->hasRight(\'patient\', \'read\'):/patient/tab.php?id=__ID__');

		// Dictionaries: department, id type, allergy type, doctor title (spec §2.1)
		// dict.php instantiates the descriptor before loading langfiles, so load here
		$langs->load('patient@patient');
		$dictTables = array(
			'c_patient_department' => 'PatientDictDepartment',
			'c_patient_id_type' => 'PatientDictIdType',
			'c_patient_allergy_type' => 'PatientDictAllergyType',
			'c_patient_doctor_title' => 'PatientDictDoctorTitle',
		);
		$this->dictionaries = array(
			'langs' => 'patient@patient',
			'tabname' => array(),
			'tablib' => array(),
			'tabsql' => array(),
			'tabsqlsort' => array(),
			'tabfield' => array(),
			'tabfieldvalue' => array(),
			'tabfieldinsert' => array(),
			'tabrowid' => array(),
			'tabcond' => array(),
			'tabhelp' => array(),
		);
		foreach ($dictTables as $table => $label) {
			$this->dictionaries['tabname'][] = $table;
			$this->dictionaries['tablib'][] = $label;
			$this->dictionaries['tabsql'][] = 'SELECT f.rowid as rowid, f.code, f.label, f.pos, f.active FROM '.MAIN_DB_PREFIX.$table.' as f';
			$this->dictionaries['tabsqlsort'][] = 'pos ASC, label ASC';
			$this->dictionaries['tabfield'][] = 'code,label,pos';
			$this->dictionaries['tabfieldvalue'][] = 'code,label,pos';
			$this->dictionaries['tabfieldinsert'][] = 'code,label,pos';
			$this->dictionaries['tabrowid'][] = 'rowid';
			$this->dictionaries['tabcond'][] = isModEnabled('patient');
			$this->dictionaries['tabhelp'][] = array('code' => $langs->trans('PatientDictCodeHelp'));
		}

		$this->boxes = array();
		$this->cronjobs = array();

		// Permissions: one-level form (DEV.md §二.3), ids 50160011/21/31/41 (spec §7)
		$this->rights = array();
		$r = 0;

		$this->rights[$r][0] = $this->numero . 11;
		$this->rights[$r][1] = 'PatientPermRead';
		$this->rights[$r][4] = 'read';
		$r++;
		$this->rights[$r][0] = $this->numero . 21;
		$this->rights[$r][1] = 'PatientPermWrite';
		$this->rights[$r][4] = 'write';
		$r++;
		$this->rights[$r][0] = $this->numero . 31;
		$this->rights[$r][1] = 'PatientPermProfile';
		$this->rights[$r][4] = 'profile';
		$r++;
		$this->rights[$r][0] = $this->numero . 41;
		$this->rights[$r][1] = 'PatientPermAdmin';
		$this->rights[$r][4] = 'admin';
		$r++;

		// Top menu "Clinic": later healthcare modules hang their left entries under fk_mainmenu=clinic
		$this->menu = array();
		$r = 0;

		$this->menu[$r++] = array(
			'fk_menu' => '',
			'type' => 'top',
			'titre' => 'ClinicMenu',
			'prefix' => img_picto('', $this->picto, 'class="pictofixedwidth valignmiddle"'),
			'mainmenu' => 'clinic',
			'leftmenu' => '',
			'url' => '/patient/patientindex.php',
			'langs' => 'patient@patient',
			'position' => 1000 + $r,
			'enabled' => 'isModEnabled("patient")',
			'perms' => '$user->hasRight("patient", "read")',
			'target' => '',
			'user' => 2,
		);
		$this->menu[$r++] = array(
			'fk_menu' => 'fk_mainmenu=clinic',
			'type' => 'left',
			'titre' => 'PatientList',
			'mainmenu' => 'clinic',
			'leftmenu' => 'patient_list',
			'prefix' => img_picto('', 'fa-user_fas_#1e88e5', 'class="paddingright pictofixedwidth"'),
			'url' => '/patient/list.php',
			'langs' => 'patient@patient',
			'position' => 1000 + $r,
			'enabled' => 'isModEnabled("patient")',
			'perms' => '$user->hasRight("patient", "read")',
			'target' => '',
			'user' => 2,
		);
		$this->menu[$r++] = array(
			'fk_menu' => 'fk_mainmenu=clinic,fk_leftmenu=patient_list',
			'type' => 'left',
			'titre' => 'PatientNew',
			'mainmenu' => 'clinic',
			'leftmenu' => 'patient_new',
			'url' => '/patient/card.php?action=create',
			'langs' => 'patient@patient',
			'position' => 1000 + $r,
			'enabled' => 'isModEnabled("patient")',
			'perms' => '$user->hasRight("patient", "write")',
			'target' => '',
			'user' => 2,
		);
		$this->menu[$r++] = array(
			'fk_menu' => 'fk_mainmenu=clinic',
			'type' => 'left',
			'titre' => 'PatientDoctors',
			'mainmenu' => 'clinic',
			'leftmenu' => 'patient_doctors',
			'prefix' => img_picto('', 'fa-user-md_fas_#43a047', 'class="paddingright pictofixedwidth"'),
			'url' => '/patient/admin/doctors.php',
			'langs' => 'patient@patient',
			'position' => 1000 + $r,
			'enabled' => 'isModEnabled("patient")',
			'perms' => '$user->hasRight("patient", "admin")',
			'target' => '',
			'user' => 0,
		);
	}

	/**
	 *  Function called when module is enabled.
	 *  Creates tables, dictionaries and their seed rows (idempotent: run_sql
	 *  accepts "already exists" errors), then registers permissions/menus/tabs.
	 *
	 *  @param      string  $options    Options when enabling module ('', 'noboxes')
	 *  @return     int<-1,1>          1 if OK, <=0 if KO
	 */
	public function init($options = '')
	{
		$result = $this->_load_tables('/patient/sql/');
		if ($result < 0) {
			return -1;
		}

		$this->remove($options);

		$sql = array();

		return $this->_init($sql, $options);
	}

	/**
	 *	Function called when module is disabled.
	 *	Removes constants, permissions, menus and tabs only. Patient tables,
	 *	the audit trail and the card sequence are kept (spec §4.2).
	 *
	 *	@param	string		$options	Options when enabling module ('', 'noboxes')
	 *	@return	int<-1,1>				1 if OK, <=0 if KO
	 */
	public function remove($options = '')
	{
		$sql = array();
		return $this->_remove($sql, $options);
	}
}
