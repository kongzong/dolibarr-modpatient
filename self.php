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
 * \file    htdocs/custom/patient/self.php
 * \ingroup patient
 * \brief   患者自助查询公共页（免登录）。凭 token 验签只读展示本人数据。
 *          后端只以验签解出的 pid 为查询条件，天然隔离跨人查看。
 */

define('NOLOGIN', 1);
define('NOTOKENRENEWAL', 1);
define('NOREQUIREMENU', 1);

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

dol_include_once('/patient/class/patientprofile.class.php');
dol_include_once('/patient/lib/patient.lib.php');
dol_include_once('/patient/lib/patient_self.lib.php');

$langs->loadLangs(array('patient@patient'));

$pid = GETPOSTINT('pid');
$exp = GETPOSTINT('exp');
$sig = GETPOST('sig', 'alpha');

// ---- 简易限流（公网防 token 爆破）：状态存 llx_patient_audit，不建新表 ----
// 同 IP 15 分钟内 SELF_DENIED > 20 或 1 小时内 SELF_VIEW > 60（店内共享出口 IP，放宽）直接拒绝。
$ip = isset($_SERVER['REMOTE_ADDR']) ? substr($_SERVER['REMOTE_ADDR'], 0, 64) : '';
$rateBlocked = false;
if ($ip !== '') {
	$aprefix = $db->prefix();
	$nowTs = time();
	$r = $db->query("SELECT COUNT(*) AS n FROM ".$aprefix."patient_audit WHERE ip = '".$db->escape($ip)."' AND action = 'SELF_DENIED' AND date_creation >= '".$db->idate($nowTs - 900)."'");
	if ($r) {
		$o = $db->fetch_object($r);
		if ((int) $o->n > 20) {
			$rateBlocked = true;
		}
	}
	if (!$rateBlocked) {
		$r = $db->query("SELECT COUNT(*) AS n FROM ".$aprefix."patient_audit WHERE ip = '".$db->escape($ip)."' AND action = 'SELF_VIEW' AND date_creation >= '".$db->idate($nowTs - 3600)."'");
		if ($r) {
			$o = $db->fetch_object($r);
			if ((int) $o->n > 60) {
				$rateBlocked = true;
			}
		}
	}
}

$valid = !$rateBlocked && patientSelfVerify($pid, $exp, $sig);

// 失败尝试落审计（fk_patient 用尝试的 pid；pid 非法的请求验签必败、不记录也不放行）
if (!$valid && $pid > 0) {
	$detail = array(
		'ip' => $ip,
		'ua' => isset($_SERVER['HTTP_USER_AGENT']) ? substr($_SERVER['HTTP_USER_AGENT'], 0, 200) : '',
		'reason' => $rateBlocked ? 'rate' : 'sig',
	);
	patient_audit($db, $pid, 'SELF_DENIED', null, $detail);
	if (is_object($db) && method_exists($db, 'commit')) {
		$db->commit();
	}
}

llxHeader('', $langs->trans('PatientSelfTitle'));

print '<div style="max-width:520px;margin:24px auto;">';

if ($rateBlocked) {
	print '<div style="text-align:center;padding:24px;border:1px solid #f0c0c0;background:#fff5f5;border-radius:8px;">';
	print '<h2>'.$langs->trans('PatientSelfRateLimited').'</h2>';
	print '<p class="opacitymedium">'.dol_escape_htmltag($langs->trans('PatientSelfInvalidHint')).'</p>';
	print '</div>';
	print '</div>';
	llxFooter();
	exit;
}

if (!$valid) {
	print '<div style="text-align:center;padding:24px;border:1px solid #f0c0c0;background:#fff5f5;border-radius:8px;">';
	print '<h2>'.$langs->trans('PatientSelfInvalid').'</h2>';
	print '<p class="opacitymedium">'.dol_escape_htmltag($langs->trans('PatientSelfInvalidHint')).'</p>';
	print '</div>';
	print '</div>';
	llxFooter();
	exit;
}

$object = new PatientProfile($db);
if ($object->fetch($pid) <= 0) {
	print '<div style="text-align:center;padding:24px;">'.dol_escape_htmltag($langs->trans('PatientSelfInvalid')).'</div>';
	print '</div>';
	llxFooter();
	exit;
}

// 审计：匿名访问，fk_user 传 null（patient_audit 内部处理为 NULL）
$detail = array(
	'ip' => isset($_SERVER['REMOTE_ADDR']) ? substr($_SERVER['REMOTE_ADDR'], 0, 64) : '',
	'ua' => isset($_SERVER['HTTP_USER_AGENT']) ? substr($_SERVER['HTTP_USER_AGENT'], 0, 200) : '',
);
patient_audit($db, $pid, 'SELF_VIEW', null, $detail);
// 公共页框架不一定在结束时提交事务，显式提交以免审计写入被回滚
if (is_object($db) && method_exists($db, 'commit')) {
	$db->commit();
}

$genders = array('U' => $langs->trans('PatientGenderU'), 'M' => $langs->trans('PatientGenderM'), 'F' => $langs->trans('PatientGenderF'));
$name = $object->thirdparty ? $object->thirdparty->name : '';
$age = $object->getAge();
$idMasked = $object->getIdNumberMasked();

print '<h2 style="margin:0 0 4px;">'.dol_escape_htmltag($langs->trans('PatientSelfTitle')).'</h2>';
print '<p class="opacitymedium" style="font-size:12px;margin:0 0 12px;">'.dol_escape_htmltag($langs->trans('PatientSelfNotice')).'</p>';
print '<div style="padding:16px;border:1px solid #ddd;border-radius:8px;background:#fff;">';
print '<p style="margin:0 0 10px;"><strong>'.dol_escape_htmltag($name).'</strong> <span class="opacitymedium">'.dol_escape_htmltag($object->card_no).'</span></p>';
print '<table class="border" style="width:100%;border-collapse:collapse;">';
print '<tr><td class="opacitymedium" style="padding:6px 8px;width:38%;">'.dol_escape_htmltag($langs->trans('PatientSelfGender')).'</td><td style="padding:6px 8px;">'.dol_escape_htmltag(isset($genders[$object->gender]) ? $genders[$object->gender] : '').'</td></tr>';
print '<tr><td class="opacitymedium" style="padding:6px 8px;">'.dol_escape_htmltag($langs->trans('PatientSelfAge')).'</td><td style="padding:6px 8px;">'.($age !== null ? dol_escape_htmltag($langs->trans('PatientAgeYears', $age)) : '').'</td></tr>';
print '<tr><td class="opacitymedium" style="padding:6px 8px;">'.dol_escape_htmltag($langs->trans('PatientSelfPhone')).'</td><td style="padding:6px 8px;">'.dol_escape_htmltag($object->thirdparty ? $object->thirdparty->phone : '').'</td></tr>';
print '<tr><td class="opacitymedium" style="padding:6px 8px;">'.dol_escape_htmltag($langs->trans('PatientIdNumber')).'</td><td style="padding:6px 8px;">'.dol_escape_htmltag($idMasked).'</td></tr>';
print '</table>';
print '</div>';

// 状态/严重度徽标（过敏史与三类明细共用，须先于首个使用点定义）
$selfBadge = function($kind, $status) {
	$b = patientSelfStatusBadge($kind, $status);
	$color = $b['cls'] === 'ok' ? '#1a7f37' : ($b['cls'] === 'bad' ? '#c0392b' : ($b['cls'] === 'warn' ? '#b45309' : '#888'));
	return '<span style="font-size:11px;color:'.$color.';border:1px solid '.$color.';border-radius:10px;padding:1px 8px;">'.dol_escape_htmltag($b['label']).'</span>';
};

// ---- 过敏史（患者级，仅有效记录，重度优先）----
$allergies = patientSelfAllergies($db, $pid);
$allergyTypes = patient_dict_options($db, 'c_patient_allergy_type');

print '<h3 style="margin:20px 0 8px;font-size:15px;">'.dol_escape_htmltag($langs->trans('PatientSelfAllergies')).'</h3>';
if (empty($allergies)) {
	print '<p class="opacitymedium" style="font-size:12px;">'.dol_escape_htmltag($langs->trans('PatientAllergyNone')).'</p>';
} else {
	foreach ($allergies as $a) {
		print '<div style="padding:10px 12px;border:1px solid #eee;border-radius:6px;margin-bottom:8px;background:#fafafa;">';
		print '<div style="display:flex;justify-content:space-between;align-items:center;font-size:13px;"><strong>'.dol_escape_htmltag($a['name']).'</strong>'.$selfBadge('allergy', $a['severity']).'</div>';
		$typeLabel = isset($allergyTypes[$a['type']]) ? $allergyTypes[$a['type']] : $a['type'];
		$meta = $typeLabel;
		if ($a['product_label'] !== '') {
			$meta .= ' · '.dol_escape_htmltag($a['product_label']);
		}
		print '<div class="opacitymedium" style="font-size:12px;margin-top:4px;">'.dol_escape_htmltag($meta).'</div>';
		if ($a['reaction'] !== '') {
			print '<div class="opacitymedium" style="font-size:12px;">'.dol_escape_htmltag($langs->trans('PatientReaction')).'：'.dol_escape_htmltag($a['reaction']).'</div>';
		}
		print '</div>';
	}
}

// ---- 三类明细（只读，按 pid 隔离）----
$visits = patientSelfVisits($db, $pid, 10);
$prescs = patientSelfPrescriptions($db, $pid, 10);
$bills  = patientSelfBills($db, $pid, 10);

print '<h3 style="margin:20px 0 8px;font-size:15px;">'.dol_escape_htmltag($langs->trans('PatientSelfVisits')).'</h3>';
if (empty($visits)) {
	print '<p class="opacitymedium" style="font-size:12px;">'.dol_escape_htmltag($langs->trans('PatientSelfEmpty')).'</p>';
} else {
	foreach ($visits as $v) {
		print '<div style="padding:10px 12px;border:1px solid #eee;border-radius:6px;margin-bottom:8px;background:#fafafa;">';
		print '<div style="display:flex;justify-content:space-between;align-items:center;font-size:13px;"><strong>'.dol_escape_htmltag($v['date']).' · '.dol_escape_htmltag($langs->trans($v['type'])).'</strong>'.$selfBadge('medrecord', $v['status']).'</div>';
		if ($v['complaint'] !== '') {
			print '<div class="opacitymedium" style="font-size:12px;margin-top:4px;">'.dol_escape_htmltag($langs->trans('PatientSelfChiefComplaint')).'：'.dol_escape_htmltag($v['complaint']).'</div>';
		}
		if ($v['dx'] !== '') {
			print '<div class="opacitymedium" style="font-size:12px;">'.dol_escape_htmltag($langs->trans('PatientSelfDiagnosis')).'：'.dol_escape_htmltag($v['dx']).'</div>';
		}
		print '</div>';
	}
}

print '<h3 style="margin:20px 0 8px;font-size:15px;">'.dol_escape_htmltag($langs->trans('PatientSelfPrescriptions')).'</h3>';
if (empty($prescs)) {
	print '<p class="opacitymedium" style="font-size:12px;">'.dol_escape_htmltag($langs->trans('PatientSelfEmpty')).'</p>';
} else {
	foreach ($prescs as $p) {
		print '<div style="padding:10px 12px;border:1px solid #eee;border-radius:6px;margin-bottom:8px;background:#fafafa;">';
		print '<div style="display:flex;justify-content:space-between;align-items:center;font-size:13px;"><strong>'.dol_escape_htmltag($p['date']).' · '.dol_escape_htmltag($langs->trans($p['type'])).'</strong>'.$selfBadge('prescription', $p['status']).'</div>';
		if ($p['dx'] !== '') {
			print '<div class="opacitymedium" style="font-size:12px;margin-top:4px;">'.dol_escape_htmltag($langs->trans('PatientSelfPrescDx')).'：'.dol_escape_htmltag($p['dx']).'</div>';
		}
		if (!empty($p['lines'])) {
			print '<ul style="margin:6px 0 0 16px;font-size:12px;padding:0;">';
			foreach ($p['lines'] as $ln) {
				print '<li>'.dol_escape_htmltag($ln['label']);
				if ($ln['qty'] != 0 || $ln['unit'] !== '') {
					print ' '.htmlspecialchars((float) $ln['qty'], ENT_NOQUOTES).dol_escape_htmltag($ln['unit']);
				}
				if ($ln['sig'] !== '') {
					print ' <span class="opacitymedium">'.dol_escape_htmltag($ln['sig']).'</span>';
				}
				print '</li>';
			}
			print '</ul>';
		}
		print '</div>';
	}
}

print '<h3 style="margin:20px 0 8px;font-size:15px;">'.dol_escape_htmltag($langs->trans('PatientSelfBills')).'</h3>';
if (empty($bills)) {
	print '<p class="opacitymedium" style="font-size:12px;">'.dol_escape_htmltag($langs->trans('PatientSelfEmpty')).'</p>';
} else {
	foreach ($bills as $bl) {
		print '<div style="padding:10px 12px;border:1px solid #eee;border-radius:6px;margin-bottom:8px;background:#fafafa;">';
		print '<div style="display:flex;justify-content:space-between;align-items:center;font-size:13px;"><strong>'.dol_escape_htmltag($bl['ref']).' · '.dol_escape_htmltag($bl['date']).'</strong>'.$selfBadge('bill', $bl['status']).'</div>';
		print '<div class="opacitymedium" style="font-size:12px;margin-top:4px;">'.dol_escape_htmltag($langs->trans('PatientSelfBillAmount')).'：'.price($bl['amount']).' · '.dol_escape_htmltag($langs->trans($bl['channel'])).'</div>';
		if (!empty($bl['lines'])) {
			print '<ul style="margin:6px 0 0 16px;font-size:12px;padding:0;">';
			foreach ($bl['lines'] as $ln) {
				print '<li>'.dol_escape_htmltag($ln['label']).' ×'.htmlspecialchars((float) $ln['qty'], ENT_NOQUOTES).' — '.price($ln['amount']).'</li>';
			}
			print '</ul>';
		}
		print '</div>';
	}
}

// ---- 次卡（患者级资产，退卡不展示）----
$cards = patientSelfCards($db, $pid);

print '<h3 style="margin:20px 0 8px;font-size:15px;">'.dol_escape_htmltag($langs->trans('PatientSelfCards')).'</h3>';
if (empty($cards)) {
	print '<p class="opacitymedium" style="font-size:12px;">'.dol_escape_htmltag($langs->trans('PatientSelfEmpty')).'</p>';
} else {
	foreach ($cards as $c) {
		print '<div style="padding:10px 12px;border:1px solid #eee;border-radius:6px;margin-bottom:8px;background:#fafafa;">';
		print '<div style="display:flex;justify-content:space-between;align-items:center;font-size:13px;"><strong>'.dol_escape_htmltag($c['name'] !== '' ? $c['name'] : $c['ref']).'</strong>'.$selfBadge('card', $c['status']).'</div>';
		if ($c['type'] === 'COUNT') {
			print '<div class="opacitymedium" style="font-size:12px;margin-top:4px;">'.dol_escape_htmltag($langs->trans('PatientSelfCardTimes')).'：'.((int) $c['remain']).'</div>';
		} else {
			print '<div class="opacitymedium" style="font-size:12px;margin-top:4px;">'.dol_escape_htmltag($langs->trans('PatientSelfCardValue')).'：'.price($c['remain']).'</div>';
		}
		if ($c['date_end'] !== '') {
			print '<div class="opacitymedium" style="font-size:12px;">'.dol_escape_htmltag($langs->trans('PatientSelfCardValidUntil')).'：'.dol_escape_htmltag($c['date_end']).'</div>';
		}
		print '</div>';
	}
}

print '</div>';

llxFooter();
