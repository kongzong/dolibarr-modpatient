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
 * \file    htdocs/custom/patient/tab.php
 * \ingroup patient
 * \brief   "Patient record" tab on the thirdparty card: patient overview
 *          dashboard (allergy alert, cross-module summary cards, quick
 *          actions, audit timeline). Registration for non-patients is kept.
 *
 *          The patient ID number plaintext is NEVER printed here (spec §4.1).
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

require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/company.lib.php';
dol_include_once('/patient/class/patientprofile.class.php');
dol_include_once('/patient/lib/patient.lib.php');

/**
 * @var DoliDB $db
 * @var Translate $langs
 * @var User $user
 */

$langs->loadLangs(array("companies", "patient@patient"));
if (isModEnabled('medrecord')) {
	$langs->load('medrecord@medrecord');
}
if (isModEnabled('prescription')) {
	$langs->load('prescription@prescription');
}
if (isModEnabled('pharmacy')) {
	$langs->load('pharmacy@pharmacy');
}
if (isModEnabled('clinicpay')) {
	$langs->load('clinicpay@clinicpay');
}

$socid = GETPOSTINT('id');
$action = GETPOST('action', 'aZ09');
if ($socid <= 0 || !$user->hasRight('societe', 'lire') || !$user->hasRight('patient', 'read')) {
	accessforbidden();
}

$object = new Societe($db);
if ($object->fetch($socid) <= 0) {
	dol_print_error($db, $object->error);
	exit;
}
$result = restrictedArea($user, 'societe', $socid, '&societe');

$patient = new PatientProfile($db);
$hasPatient = $patient->fetch(0, $socid) > 0;

// Register this thirdparty as a patient (write permission, POST + token)
if ($action == 'createpatient' && !$hasPatient && $user->hasRight('patient', 'write')) {
	$patient = new PatientProfile($db);
	$result = $patient->createForThirdparty($user, $socid);
	if ($result > 0) {
		setEventMessages($langs->trans("PatientCreated", $patient->card_no), null, 'mesgs');
		header("Location: ".dol_buildpath('/patient/card.php', 1).'?id='.$patient->id);
		exit;
	}
	setEventMessages($langs->trans($patient->error), $patient->errors, 'errors');
}

llxHeader('', $langs->trans("PatientTab"));

$head = societe_prepare_head($object);
print dol_get_fiche_head($head, 'patient', $langs->trans("ThirdParty"), -1, 'company');

$linkback = '<a href="'.DOL_URL_ROOT.'/societe/list.php?restore_lastsearch_values=1">'.$langs->trans("BackToList").'</a>';
dol_banner_tab($object, 'id', $linkback, ($user->socid ? 0 : 1), 'rowid', 'nom');

print '<div class="fichecenter">';
print '<div class="underbanner clearboth"></div>';

if (!$hasPatient) {
	// Not a patient yet: offer registration for individuals only
	if ((int) $object->typent_id !== PatientProfile::TYPENT_INDIVIDUAL) {
		print '<div class="opacitymedium">'.$langs->trans("PatientNotIndividual").'</div>';
	} else {
		print '<div class="opacitymedium">'.$langs->trans("PatientNotYet").'</div>';
		if ($user->hasRight('patient', 'write')) {
			print '<form method="POST" action="'.$_SERVER["PHP_SELF"].'?id='.$socid.'">';
			print '<input type="hidden" name="token" value="'.newToken().'">';
			print '<input type="hidden" name="action" value="createpatient">';
			print '<div class="tabsAction"><input type="submit" class="butAction" value="'.$langs->trans("PatientCreateProfile").'"></div>';
			print '</form>';
		}
	}
	print '</div>';
	print dol_get_fiche_end();
	llxFooter();
	$db->close();
	exit;
}

/*
 * Overview dashboard for an existing patient.
 * The patient profile rowid ($pid) is the key to every downstream module.
 */
$pid = (int) $patient->id;
$canProfile = $user->hasRight('patient', 'profile');

// Cross-module summary (guarded by enabled modules only)
$latestVisit = null;
if (isModEnabled('medrecord')) {
	$res = $db->query("SELECT rowid, ref, date_creation, status FROM ".$db->prefix()."medrecord WHERE fk_patient = ".$pid." AND status <> 9 ORDER BY date_creation DESC LIMIT 1");
	if ($res && $db->num_rows($res)) {
		$latestVisit = $db->fetch_object($res);
	}
}
$latestPresc = null;
if (isModEnabled('prescription')) {
	$res = $db->query("SELECT rowid, ref, date_creation, status FROM ".$db->prefix()."prescription WHERE fk_patient = ".$pid." AND status <> 9 ORDER BY date_creation DESC LIMIT 1");
	if ($res && $db->num_rows($res)) {
		$latestPresc = $db->fetch_object($res);
	}
}
$latestDispense = null;
if (isModEnabled('pharmacy')) {
	$res = $db->query("SELECT rowid, ref, date_creation FROM ".$db->prefix()."pharmacy_dispense WHERE fk_patient = ".$pid." ORDER BY rowid DESC LIMIT 1");
	if ($res && $db->num_rows($res)) {
		$latestDispense = $db->fetch_object($res);
	}
}
$billDraft = 0;
$cardActive = 0;
if (isModEnabled('clinicpay')) {
	dol_include_once('/clinicpay/lib/clinicpay.lib.php');
	foreach (clinicpay_bill_list_by_patient($db, $pid, 500) as $b) {
		if ((int) $b->status === 0) {
			$billDraft++;
		}
	}
	foreach (clinicpay_card_list_by_patient($db, $pid, 500) as $c) {
		if ((int) $c->status === 1) {
			$cardActive++;
		}
	}
}

// Allergy alert bar (medical data, profile holders only)
$summary = patient_get_summary($db, $pid);
if ($canProfile && is_array($summary['allergies']) && count($summary['allergies']) > 0) {
	print '<div class="patient-allergy-bar" style="padding:8px 12px;margin-bottom:12px;border:1px solid '.(($summary['has_severe_allergy']) ? '#e0573e' : '#e0c000').';border-radius:6px;background:'.(($summary['has_severe_allergy']) ? '#fdecea' : '#fff9e6').';">';
	if ($summary['has_severe_allergy']) {
		print img_warning('').' <strong>'.$langs->trans('PatientAllergySevereWarning').'</strong> ';
	} else {
		print '<strong>'.$langs->trans('PatientOverviewAllergyNote').'</strong> ';
	}
	foreach ($summary['allergies'] as $a) {
		$cls = $a['severity'] >= 3 ? 'badge-status8' : ($a['severity'] == 2 ? 'badge-status1' : 'badge-status0');
		print '<span class="badge '.$cls.'">'.dol_escape_htmltag($a['name']).'</span> ';
	}
	print ' <a class="reposition" href="'.dol_buildpath('/patient/allergies.php', 1).'?id='.$pid.'">'.$langs->trans('PatientAllergies').'</a>';
	print '</div>';
}

// Summary cards
$cardStyle = 'flex:1 1 180px;min-width:160px;border:1px solid #ddd;border-radius:6px;padding:10px 12px;background:#fff;';
$labelStyle = 'font-size:11px;color:#888;';
$valueStyle = 'font-size:20px;font-weight:bold;margin:4px 0;';
$subStyle = 'font-size:11px;color:#666;';

print '<div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:14px;">';

// 1) Recent visit
print '<div style="'.$cardStyle.'">';
print '<div style="'.$labelStyle.'">'.$langs->trans('PatientRecentVisit').'</div>';
if ($latestVisit) {
	print '<div style="'.$valueStyle.'">'.dol_print_date($db->jdate($latestVisit->date_creation), 'day').'</div>';
	print '<div style="'.$subStyle.'">'.dol_escape_htmltag($latestVisit->ref).'</div>';
} else {
	print '<div style="'.$valueStyle.' opacitymedium;">'.$langs->trans('PatientNoData').'</div>';
}
if (isModEnabled('medrecord') && $user->hasRight('medrecord', 'read')) {
	print '<a href="'.dol_buildpath('/medrecord/patient_tab.php', 1).'?id='.$pid.'">'.$langs->trans('PatientViewAll').'</a>';
}
print '</div>';

// 2) Active prescription
print '<div style="'.$cardStyle.'">';
print '<div style="'.$labelStyle.'">'.$langs->trans('PatientActivePrescription').'</div>';
if ($latestPresc) {
	print '<div style="'.$valueStyle.'">'.dol_escape_htmltag($latestPresc->ref).'</div>';
	print '<div style="'.$subStyle.'">'.dol_print_date($db->jdate($latestPresc->date_creation), 'day').'</div>';
} else {
	print '<div style="'.$valueStyle.' opacitymedium;">'.$langs->trans('PatientNoData').'</div>';
}
if (isModEnabled('prescription') && $user->hasRight('prescription', 'read')) {
	print '<a href="'.dol_buildpath('/prescription/patient_tab.php', 1).'?id='.$pid.'">'.$langs->trans('PatientViewAll').'</a>';
}
print '</div>';

// 3) Recent dispensing (order matches the patient card tabs:
// visit / prescription / dispensing / bills / cards)
print '<div style="'.$cardStyle.'">';
print '<div style="'.$labelStyle.'">'.$langs->trans('PatientRecentDispense').'</div>';
if ($latestDispense) {
	print '<div style="'.$valueStyle.'">'.dol_escape_htmltag($latestDispense->ref).'</div>';
	print '<div style="'.$subStyle.'">'.dol_print_date($db->jdate($latestDispense->date_creation), 'day').'</div>';
} else {
	print '<div style="'.$valueStyle.' opacitymedium;">'.$langs->trans('PatientNoData').'</div>';
}
if (isModEnabled('pharmacy') && $user->hasRight('pharmacy', 'read')) {
	print '<a href="'.dol_buildpath('/pharmacy/patient_tab.php', 1).'?id='.$pid.'">'.$langs->trans('PatientViewAll').'</a>';
}
print '</div>';

// 4) Pending bills
print '<div style="'.$cardStyle.'">';
print '<div style="'.$labelStyle.'">'.$langs->trans('PatientPendingBill').'</div>';
print '<div style="'.$valueStyle.'">'.((int) $billDraft).'</div>';
if (isModEnabled('clinicpay') && $user->hasRight('clinicpay', 'read')) {
	print '<a href="'.dol_buildpath('/clinicpay/patient_tab.php', 1).'?tab=bills&id='.$pid.'">'.$langs->trans('PatientViewAll').'</a>';
}
print '</div>';

// 5) Active cards
print '<div style="'.$cardStyle.'">';
print '<div style="'.$labelStyle.'">'.$langs->trans('PatientActiveCard').'</div>';
print '<div style="'.$valueStyle.'">'.((int) $cardActive).'</div>';
if (isModEnabled('clinicpay') && $user->hasRight('clinicpay', 'read')) {
	print '<a href="'.dol_buildpath('/clinicpay/patient_tab.php', 1).'?tab=cards&id='.$pid.'">'.$langs->trans('PatientViewAll').'</a>';
}
print '</div>';

print '</div>';

// Quick actions (write rights only)
$quick = array();
if (isModEnabled('prescription') && $user->hasRight('prescription', 'write')) {
	$quick[] = array('label' => $langs->trans('PrescriptionNew'), 'url' => dol_buildpath('/prescription/card.php', 1).'?action=create&fk_patient='.$pid);
}
if (isModEnabled('medrecord') && $user->hasRight('medrecord', 'write')) {
	$quick[] = array('label' => $langs->trans('MedRecordNew'), 'url' => dol_buildpath('/medrecord/card.php', 1).'?action=create&fk_patient='.$pid);
}
if (isModEnabled('clinicpay') && $user->hasRight('clinicpay', 'write')) {
	$quick[] = array('label' => $langs->trans('ClinicPayBillNew'), 'url' => dol_buildpath('/clinicpay/bill.php', 1).'?action=create&fk_patient='.$pid);
	$quick[] = array('label' => $langs->trans('ClinicPayCardNew'), 'url' => dol_buildpath('/clinicpay/card.php', 1).'?action=create&fk_patient='.$pid);
}
if (count($quick) > 0) {
	print '<div class="tabsAction" style="padding-top:0;">';
	print '<span class="opacitymedium" style="margin-right:6px;">'.$langs->trans('PatientQuickActions').':</span>';
	foreach ($quick as $q) {
		print dolGetButtonAction($q['label'], '', 'default', $q['url'], '', 1);
	}
	print '</div>';
}

// Audit timeline (profile / admin)
if ($canProfile || $user->hasRight('patient', 'admin')) {
	$audit = array();
	$res = $db->query("SELECT a.action, a.date_creation, a.fk_user, u.lastname, u.firstname FROM ".$db->prefix()."patient_audit as a LEFT JOIN ".$db->prefix()."user as u ON u.rowid = a.fk_user WHERE a.fk_patient = ".$pid." ORDER BY a.date_creation DESC LIMIT 12");
	if ($res) {
		while ($o = $db->fetch_object($res)) {
			$audit[] = $o;
		}
	}
	print '<div style="margin-top:6px;">';
	print '<div class="opacitymedium" style="margin-bottom:4px;">'.$langs->trans('PatientAuditTimeline').'</div>';
	if (empty($audit)) {
		print '<div class="opacitymedium">'.$langs->trans('PatientNoData').'</div>';
	} else {
		print '<ul style="margin:0;padding-left:18px;">';
		foreach ($audit as $a) {
			$who = trim($a->lastname.' '.$a->firstname);
			print '<li>'.dol_print_date($db->jdate($a->date_creation), 'dayhour').' &middot; <code>'.dol_escape_htmltag($a->action).'</code>'.($who ? ' &middot; '.dol_escape_htmltag($who) : '').'</li>';
		}
		print '</ul>';
	}
	print '</div>';
}

print '</div>';

print dol_get_fiche_end();

llxFooter();
$db->close();
