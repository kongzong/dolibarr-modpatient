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
 * \file    htdocs/custom/patient/self_qr.php
 * \ingroup patient
 * \brief   医生端：生成患者自助查询二维码。需 patient read 权限。
 *          扫码打开 self.php，患者手机查看本人数据，链接 15 分钟有效。
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
dol_include_once('/patient/class/patientprofile.class.php');
dol_include_once('/patient/lib/patient.lib.php');
dol_include_once('/patient/lib/patient_self.lib.php');

$langs->loadLangs(array('patient@patient'));

$id = GETPOSTINT('id');
$canRead = $user->hasRight('patient', 'read');
if (!$canRead) {
	accessforbidden();
}

$object = new PatientProfile($db);
if ($id > 0 && $object->fetch($id) <= 0) {
	accessforbidden($langs->trans('PatientNotYet'));
}

$svg = '';
$selfUrl = '';
if ($id > 0) {
	$selfUrl = patientSelfMakeUrl($object->id, 900);
	require_once DOL_DOCUMENT_ROOT.'/includes/tecnickcom/tcpdf/tcpdf_barcodes_2d.php';
	$b = new TCPDF2DBarcode($selfUrl, 'QRCODE,L');
	$svg = $b->getBarcodeSVGcode(5, 5, 'black');
}

llxHeader('', $langs->trans('PatientSelfQRTitle'));

print load_fiche_titre($langs->trans('PatientSelfQRTitle'), '', 'fa-qrcode_fas_#1a73e8');

if ($id <= 0 || $svg === '') {
	print '<div class="opacitymedium">'.$langs->trans('PatientSelfQRNoId').'</div>';
} else {
	print '<div class="patient-self-qr" style="max-width:420px;margin:12px 0;padding:16px;border:1px solid #ddd;border-radius:8px;background:#fff;">';
	print '<p><strong>'.dol_escape_htmltag($object->thirdparty ? $object->thirdparty->name : '').'</strong>';
	print ' <span class="opacitymedium">'.dol_escape_htmltag($object->card_no).'</span></p>';
	print '<div style="width:240px;height:240px;">'.$svg.'</div>';
	print '<p style="font-size:12px;margin-top:10px;word-break:break-all;">'.$langs->trans('PatientSelfQROrUrl').' ';
	print '<a href="'.dol_escape_htmltag($selfUrl).'" target="_blank" rel="noopener">'.dol_escape_htmltag($selfUrl).'</a></p>';
	print '<p class="opacitymedium" style="font-size:12px;margin-top:10px;line-height:1.5;">'.dol_escape_htmltag($langs->trans('PatientSelfQRHint')).'</p>';
	print '</div>';
	print '<a class="butAction" href="'.dol_buildpath('/patient/self_qr.php', 1).'?id='.$id.'">'.$langs->trans('PatientSelfRegenerate').'</a>';
}

llxFooter();
