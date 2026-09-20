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
 * \file    htdocs/custom/patient/admin/audit.php
 * \ingroup patient
 * \brief   Read-only audit trail (admin). Filter by patient card, user, action,
 *          date range. No delete/edit path exists anywhere in the module.
 */

$res = 0;
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) {
	$res = @include $_SERVER["CONTEXT_DOCUMENT_ROOT"]."/main.inc.php";
}
if (!$res && file_exists("../../../main.inc.php")) {
	$res = @include "../../../main.inc.php";
}
if (!$res && file_exists("../../../../main.inc.php")) {
	$res = @include "../../../../main.inc.php";
}
if (!$res) {
	die("Include of main fails");
}

require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/html.form.class.php';
dol_include_once('/patient/lib/patient.lib.php');

/**
 * @var Conf $conf
 * @var DoliDB $db
 * @var Translate $langs
 * @var User $user
 */

$langs->loadLangs(array("admin", "users", "patient@patient"));

if (!$user->admin && !$user->hasRight('patient', 'admin')) {
	accessforbidden();
}

$form = new Form($db);

$searchCard = trim(GETPOST('search_card', 'alphanohtml'));
$searchUser = GETPOSTINT('search_user');
$searchAction = GETPOST('search_action', 'aZ09');
$searchDateStart = dol_mktime(0, 0, 0, GETPOSTINT('search_date_startmonth'), GETPOSTINT('search_date_startday'), GETPOSTINT('search_date_startyear'));
$searchDateEnd = dol_mktime(23, 59, 59, GETPOSTINT('search_date_endmonth'), GETPOSTINT('search_date_endday'), GETPOSTINT('search_date_endyear'));
if (GETPOST('button_removefilter_x', 'alpha') || GETPOST('button_removefilter', 'alpha')) {
	$searchCard = '';
	$searchUser = 0;
	$searchAction = '';
	$searchDateStart = '';
	$searchDateEnd = '';
}

$limit = GETPOSTINT('limit') > 0 ? GETPOSTINT('limit') : $conf->liste_limit;
$page = (int) GETPOST('page', 'int');
if ($page < 0) {
	$page = 0;
}
$offset = $limit * $page;

$actions = array('CREATE' => 'PATIENT_CREATE', 'MODIFY' => 'PATIENT_MODIFY', 'DISABLE' => 'PATIENT_DISABLE',
	'ALLERGY_ADD' => 'PATIENT_ALLERGY_ADD', 'ALLERGY_DELETE' => 'PATIENT_ALLERGY_DELETE',
	'READ_PROFILE' => 'READ_PROFILE', 'READ_IDNUMBER' => 'READ_IDNUMBER');
$actionOptions = array();
foreach ($actions as $code) {
	$actionOptions[$code] = $langs->trans('PatientAudit_'.$code);
}

$from = " FROM ".$db->prefix()."patient_audit as a";
$from .= " LEFT JOIN ".$db->prefix()."patient_profile as p ON p.rowid = a.fk_patient";
$from .= " LEFT JOIN ".$db->prefix()."user as u ON u.rowid = a.fk_user";
$where = " WHERE a.entity = ".((int) $conf->entity);
if ($searchCard !== '') {
	$where .= " AND p.card_no LIKE '%".$db->escape($searchCard)."%'";
}
if ($searchUser > 0) {
	$where .= " AND a.fk_user = ".$searchUser;
}
if ($searchAction !== '' && in_array($searchAction, $actions, true)) {
	$where .= " AND a.action = '".$db->escape($searchAction)."'";
}
if ($searchDateStart) {
	$where .= " AND a.date_creation >= '".$db->idate($searchDateStart)."'";
}
if ($searchDateEnd) {
	$where .= " AND a.date_creation <= '".$db->idate($searchDateEnd)."'";
}

$resql = $db->query("SELECT COUNT(a.rowid) as total".$from.$where);
if (!$resql) {
	dol_print_error($db);
	exit;
}
$total = (int) $db->fetch_object($resql)->total;
$db->free($resql);

$sql = "SELECT a.rowid, a.fk_patient, a.action, a.fk_user, a.ip, a.detail, a.date_creation, p.card_no, u.login, u.lastname, u.firstname";
$sql .= $from.$where." ORDER BY a.rowid DESC".$db->plimit($limit, $offset);
$resql = $db->query($sql);
if (!$resql) {
	dol_print_error($db);
	exit;
}

llxHeader('', $langs->trans("PatientAudit"));

$linkback = '<a href="'.DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1">'.$langs->trans("BackToModuleList").'</a>';
print load_fiche_titre($langs->trans("PatientAudit"), $linkback, 'title_setup');

$head = patient_admin_prepare_head();
print dol_get_fiche_head($head, 'audit', $langs->trans("ModulePatientName"), -1, 'user');

$param = '&limit='.(int) $limit;
if ($searchCard !== '') {
	$param .= '&search_card='.urlencode($searchCard);
}
if ($searchUser > 0) {
	$param .= '&search_user='.$searchUser;
}
if ($searchAction !== '') {
	$param .= '&search_action='.urlencode($searchAction);
}
foreach (array('start', 'end') as $bound) {
	foreach (array('day', 'month', 'year') as $part) {
		$v = GETPOSTINT('search_date_'.$bound.$part);
		if ($v > 0) {
			$param .= '&search_date_'.$bound.$part.'='.$v;
		}
	}
}

print '<form method="GET" action="'.$_SERVER["PHP_SELF"].'" name="formaudit">';
print '<input type="hidden" name="token" value="'.newToken().'">';

print_barre_liste('', $page, $_SERVER["PHP_SELF"], $param, '', '', '', $total, $total, '', 0, '', '', $limit, 0, 0, 1);

print '<p class="opacitymedium">'.$langs->trans("PatientAuditIntro").'</p>';

print '<div class="div-table-responsive">';
print '<table class="tagtable liste centpercent">';
print '<tr class="liste_titre_filter">';
print '<td class="liste_titre"><input type="text" name="search_card" class="maxwidth150" value="'.dol_escape_htmltag($searchCard).'"></td>';
print '<td class="liste_titre">'.$form->selectarray('search_action', $actionOptions, $searchAction, 1, 0, 0, '', 0, 0, 0, '', 'maxwidth150').'</td>';
print '<td class="liste_titre">'.$form->select_dolusers($searchUser, 'search_user', 1, null, 0, '', '', 0, 0, 0, '', 0, '', 'maxwidth150').'</td>';
print '<td class="liste_titre">'.$form->selectDate($searchDateStart, 'search_date_start', 0, 0, 1, '', 1, 0).' - '.$form->selectDate($searchDateEnd, 'search_date_end', 0, 0, 1, '', 1, 0).'</td>';
print '<td class="liste_titre"></td><td class="liste_titre"></td>';
print '<td class="liste_titre center maxwidthsearch">';
print '<button type="submit" class="liste_titre button_search reposition" name="button_search" value="x"><span class="fa fa-search"></span></button>';
print '<button type="submit" class="liste_titre button_removefilter reposition" name="button_removefilter" value="x"><span class="fa fa-remove"></span></button>';
print '</td></tr>';

print '<tr class="liste_titre">';
print '<th>'.$langs->trans("PatientCardNo").'</th>';
print '<th>'.$langs->trans("Action").'</th>';
print '<th>'.$langs->trans("User").'</th>';
print '<th>'.$langs->trans("Date").'</th>';
print '<th>IP</th>';
print '<th>'.$langs->trans("PatientAuditDetail").'</th>';
print '<th></th>';
print '</tr>';

$num = $db->num_rows($resql);
if ($num == 0) {
	print '<tr><td colspan="7"><span class="opacitymedium">'.$langs->trans("NoRecordFound").'</span></td></tr>';
}
while ($obj = $db->fetch_object($resql)) {
	print '<tr class="oddeven">';
	print '<td>';
	if ($obj->card_no) {
		print '<a href="'.dol_buildpath('/patient/card.php', 1).'?id='.((int) $obj->fk_patient).'">'.dol_escape_htmltag($obj->card_no).'</a>';
	} else {
		print '<span class="opacitymedium">#'.((int) $obj->fk_patient).'</span>';
	}
	print '</td>';
	print '<td>'.dol_escape_htmltag(isset($actionOptions[$obj->action]) ? $actionOptions[$obj->action] : $obj->action).'</td>';
	print '<td>'.($obj->login ? dol_escape_htmltag(trim($obj->lastname.' '.$obj->firstname).' ('.$obj->login.')') : '').'</td>';
	print '<td>'.dol_print_date($db->jdate($obj->date_creation), 'dayhoursec').'</td>';
	print '<td>'.dol_escape_htmltag((string) $obj->ip).'</td>';
	print '<td class="small">'.dol_escape_htmltag((string) $obj->detail).'</td>';
	print '<td></td>';
	print '</tr>';
}
$db->free($resql);

print '</table></div>';
print '</form>';

print dol_get_fiche_end();

llxFooter();
$db->close();
