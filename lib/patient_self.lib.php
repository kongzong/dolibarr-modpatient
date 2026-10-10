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
 * \file    htdocs/custom/patient/lib/patient_self.lib.php
 * \ingroup patient
 * \brief   患者自助查询：扫码 token 身份鉴别（方案 A，店内场景）。
 *          患者无系统账号；医生在患者卡片页生成二维码，患者手机扫码打开
 *          self.php，凭 HMAC 签名 + 短时 exp 验签后只读展示本人数据。
 *          设计约束：token 内编码 pid，后端只以解出 pid 为 WHERE，天然隔离跨人。
 */

require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';

/**
 * HMAC 密钥。首次调用自动生成 32 字节随机值并持久化到 conf 表；之后复用。
 *
 * @return string
 */
function patientSelfSecret()
{
	global $conf, $db;

	$secret = getDolGlobalString('PATIENT_SELF_SECRET');
	if ($secret !== '') {
		return $secret;
	}
	$secret = bin2hex(random_bytes(32));
	dolibarr_set_const($db, 'PATIENT_SELF_SECRET', $secret, 'chaine', 0, 'patient self-query HMAC secret', (int) $conf->entity);
	return $secret;
}

/**
 * 外部可访问的站点基址（患者手机扫码后访问的地址）。
 * 优先配置 PATIENT_SELF_BASE_URL；否则用当前请求站点根，自动适配 LAN IP / 域名。
 *
 * @return string
 */
function patientSelfBaseUrl()
{
	$cfg = getDolGlobalString('PATIENT_SELF_BASE_URL');
	if ($cfg !== '') {
		return rtrim($cfg, '/');
	}
	// 自动适配：host 取当前请求（医生访问 self_qr 的地址即患者需访问的地址）+ Dolibarr 安装子目录。
	// 部署注意：医生须以患者手机可访问的地址（LAN IP / 域名）打开 self_qr，不能用 localhost。
	$root = defined('DOL_URL_ROOT') ? DOL_URL_ROOT : '';
	if (!empty($_SERVER['HTTP_HOST'])) {
		$proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
		return $proto.'://'.$_SERVER['HTTP_HOST'].rtrim($root, '/');
	}
	return rtrim(dol_buildpath('/', 1), '/');
}

/**
 * token = HMAC-SHA256(secret, "pid|exp")。
 *
 * @param int    $pid
 * @param int    $exp    过期时间戳
 * @param string $secret
 * @return string
 */
function patientSelfMakeToken($pid, $exp, $secret)
{
	return hash_hmac('sha256', ((int) $pid).'|'.((int) $exp), (string) $secret);
}

/**
 * 组装自助查询 URL（含 pid / exp / token）。
 *
 * @param int $pid 患者 rowid
 * @param int $ttl 有效期秒，默认 900（15 分钟）
 * @return string
 */
function patientSelfMakeUrl($pid, $ttl = 900)
{
	$pid = (int) $pid;
	$exp = time() + (int) $ttl;
	$sig = patientSelfMakeToken($pid, $exp, patientSelfSecret());
	// 注意：参数名绝不能用 token —— Dolibarr 在 main.inc.php 把 token 当作 CSRF 安全令牌，
	// 非会话 token 的 token 参数会被 unset 掉并置 errorcode=InvalidToken，导致签名永远读不到。
	return patientSelfBaseUrl().'/custom/patient/self.php?pid='.$pid.'&exp='.$exp.'&sig='.$sig;
}

/**
 * 校验签名与时间戳：签名匹配且未过期。
 *
 * @param int    $pid
 * @param int    $exp
 * @param string $sig HMAC 签名（URL 参数 sig，非 Dolibarr 保留的 token）
 * @return bool
 */
function patientSelfVerify($pid, $exp, $sig)
{
	$pid = (int) $pid;
	$exp = (int) $exp;
	if ($pid <= 0 || $exp <= 0 || $sig === '') {
		return false;
	}
	if ($exp < time()) {
		return false;
	}
	$expect = patientSelfMakeToken($pid, $exp, patientSelfSecret());
	return hash_equals($expect, (string) $sig);
}

/**
 * 患者自助页状态徽标：返回可翻译文案 + CSS 类（ok 绿 / muted 灰 / bad 红）。
 *
 * @param string $kind   'medrecord' | 'prescription' | 'bill'
 * @param int    $status 模块状态常量值
 * @return array{label:string,cls:string}
 */
function patientSelfStatusBadge($kind, $status)
{
	global $langs;
	$status = (int) $status;
	$map = array(
		'medrecord' => array(
			0 => array('PatientSelfStDraft', 'muted'),
			1 => array('PatientSelfStSigned', 'ok'),
			9 => array('PatientSelfStVoided', 'bad'),
		),
		'prescription' => array(
			0 => array('PatientSelfStDraft', 'muted'),
			1 => array('PatientSelfStSigned', 'ok'),
			2 => array('PatientSelfStDispensed', 'ok'),
			9 => array('PatientSelfStVoided', 'bad'),
		),
		'bill' => array(
			0 => array('PatientSelfStPending', 'muted'),
			1 => array('PatientSelfStPaid', 'ok'),
			9 => array('PatientSelfStRefunded', 'bad'),
		),
		'card' => array(
			0 => array('PatientSelfStCardValid', 'ok'),
			1 => array('PatientSelfStCardUsed', 'muted'),
			2 => array('PatientSelfStCardExpired', 'bad'),
		),
	);
	$def = isset($map[$kind][$status]) ? $map[$kind][$status] : array('PatientSelfStDraft', 'muted');
	return array('label' => $langs->trans($def[0]), 'cls' => $def[1]);
}

/**
 * 处方一行的用法用量摘要（WM/OTC 用 dose/route/freq/days/sig_note；TCM 仅 qty+unit）。
 *
 * @param array $l prescription_line 行
 * @return string
 */
function patientSelfLineSig($l)
{
	$parts = array();
	if (!empty($l['dose']) || !empty($l['dose_unit'])) {
		$parts[] = (float) $l['dose'].(isset($l['dose_unit']) ? (string) $l['dose_unit'] : '');
	}
	if (!empty($l['route_code'])) {
		$parts[] = (string) $l['route_code'];
	}
	if (!empty($l['freq_code'])) {
		$parts[] = (string) $l['freq_code'];
	}
	if (!empty($l['days'])) {
		$parts[] = ((int) $l['days']).'d';
	}
	if (!empty($l['sig_note'])) {
		$parts[] = (string) $l['sig_note'];
	}
	return implode(' ', $parts);
}

/**
 * 患者自助页：就诊记录（最近 $limit 条，不含已作废）。
 *
 * @param DoliDB $db
 * @param int    $pid
 * @param int    $limit
 * @return array<int,array{date:string,type:string,complaint:string,dx:string,status:int}>
 */
function patientSelfVisits($db, $pid, $limit = 10)
{
	dol_include_once('/medrecord/class/medicalrecord.class.php');
	$m = new MedicalRecord($db);
	$res = $m->search(array('patient' => (int) $pid), (int) $limit);
	if (empty($res) || empty($res['rows'])) {
		return array();
	}
	$out = array();
	foreach ($res['rows'] as $r) {
		$dx = !empty($r->wm_label) ? $r->wm_label : (!empty($r->tcm_disease_label) ? $r->tcm_disease_label : '');
		$out[] = array(
			'date' => !empty($r->visit_date) ? substr($r->visit_date, 0, 10) : '',
			'type' => ((int) $r->visit_type === 2) ? 'PatientSelfVisitFollow' : 'PatientSelfVisitFirst',
			'complaint' => $r->chief_complaint ? (string) $r->chief_complaint : '',
			'dx' => $dx ? (string) $dx : '',
			'status' => (int) $r->status,
		);
	}
	return $out;
}

/**
 * 患者自助页：处方记录（最近 $limit 条，不含已作废），展开药品行。
 *
 * @param DoliDB $db
 * @param int    $pid
 * @param int    $limit
 * @return array<int,array{date:string,type:string,dx:string,status:int,lines:array}>
 */
function patientSelfPrescriptions($db, $pid, $limit = 10)
{
	dol_include_once('/prescription/class/prescriptionsheet.class.php');
	$p = new PrescriptionSheet($db);
	$res = $p->search(array('patient' => (int) $pid), (int) $limit);
	if (empty($res) || empty($res['rows'])) {
		return array();
	}
	$typeMap = array('TCM' => 'PatientSelfTypeTCM', 'WM' => 'PatientSelfTypeWM', 'OTC' => 'PatientSelfTypeOTC');
	$out = array();
	foreach ($res['rows'] as $r) {
		$sheet = new PrescriptionSheet($db);
		if ($sheet->fetch((int) $r->rowid) <= 0) {
			continue;
		}
		$lines = array();
		foreach ($sheet->lines as $l) {
			$qty = (isset($l['qty']) && $l['qty'] !== null) ? (float) $l['qty'] : 0;
			$unit = isset($l['qty_unit']) ? (string) $l['qty_unit'] : '';
			$sig = patientSelfLineSig($l);
			$lines[] = array(
				'label' => isset($l['label']) ? (string) $l['label'] : '',
				'qty' => $qty,
				'unit' => $unit,
				'sig' => $sig,
			);
		}
		$out[] = array(
			'date' => !empty($r->date_presc) ? substr($r->date_presc, 0, 10) : '',
			'type' => isset($typeMap[$r->presc_type]) ? $typeMap[$r->presc_type] : (string) $r->presc_type,
			'dx' => $r->diagnosis_text ? (string) $r->diagnosis_text : '',
			'status' => (int) $r->status,
			'lines' => $lines,
		);
	}
	return $out;
}

/**
 * 患者自助页：收费记录（最近 $limit 条，不含已退款），展开收费项目。
 *
 * @param DoliDB $db
 * @param int    $pid
 * @param int    $limit
 * @return array<int,array{date:string,ref:string,amount:float,channel:string,status:int,lines:array}>
 */
function patientSelfBills($db, $pid, $limit = 10)
{
	dol_include_once('/clinicpay/class/paybill.class.php');
	$b = new Paybill($db);
	$res = $b->search(array('fk_patient' => (int) $pid), (int) $limit);
	if (empty($res) || empty($res['rows'])) {
		return array();
	}
	$channelMap = array('CASH' => 'PatientSelfChannelCash', 'SCAN' => 'PatientSelfChannelScan');
	$out = array();
	foreach ($res['rows'] as $r) {
		$bill = new Paybill($db);
		if ($bill->fetch((int) $r->rowid) <= 0) {
			continue;
		}
		$lines = array();
		foreach ($bill->lines as $l) {
			$lines[] = array(
				'label' => isset($l['label']) ? (string) $l['label'] : '',
				'qty' => (float) $l['qty'],
				'amount' => (float) $l['subprice_total'],
			);
		}
		$out[] = array(
			'date' => !empty($r->date_pay) ? substr($r->date_pay, 0, 10) : (!empty($r->date_creation) ? substr($r->date_creation, 0, 10) : ''),
			'ref' => (string) $r->ref,
			'amount' => (float) $r->amount_total,
			'channel' => isset($channelMap[$r->channel]) ? $channelMap[$r->channel] : (string) $r->channel,
			'status' => (int) $r->status,
			'lines' => $lines,
		);
	}
	return $out;
}

/**
 * 患者名下次卡（患者级资产）。退卡(status=3)不展示；有效/已用完/已过期均展示。
 *
 * @param DoliDB $db
 * @param int    $pid patient_profile.rowid
 * @return array[] 每张卡：ref/name/type/remain/remain_unit/date_end/status
 */
function patientSelfCards($db, $pid)
{
	$prefix = $db->prefix();
	$sql = "SELECT c.ref, c.card_type, c.total_count, c.used_count, c.total_value, c.used_value, c.date_end, c.status, p.label AS product_label";
	$sql .= " FROM ".$prefix."clinicpay_card c";
	$sql .= " LEFT JOIN ".$prefix."product p ON p.rowid = c.fk_product";
	$sql .= " WHERE c.fk_patient = ".((int) $pid)." AND c.status <> 3";
	$sql .= " ORDER BY c.status ASC, c.date_end ASC";
	$resq = $db->query($sql);
	if (!$resq) {
		return array();
	}
	$out = array();
	while ($r = $db->fetch_object($resq)) {
		if ($r->card_type === 'COUNT') {
			$remain = max(0, (int) $r->total_count - (int) $r->used_count);
		} else {
			$remain = max(0, (float) $r->total_value - (float) $r->used_value);
		}
		$out[] = array(
			'ref' => (string) $r->ref,
			'name' => (string) $r->product_label,
			'type' => ($r->card_type === 'COUNT') ? 'COUNT' : 'VALUE',
			'remain' => $remain,
			'date_end' => !empty($r->date_end) ? substr($r->date_end, 0, 10) : '',
			'status' => (int) $r->status,
		);
	}
	return $out;
}
