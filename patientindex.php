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
 * \file    htdocs/custom/patient/patientindex.php
 * \ingroup patient
 * \brief   Clinic workbench: today's counters, shortcuts, alerts and the
 *          latest visits. Replaces the phase-1 placeholder text.
 *
 * Design notes:
 * - Every figure and shortcut is permission gated, so a cashier sees billing
 *   numbers only and a doctor sees visits only.
 * - Expiry counters reuse PharmacyExpiryAlert::fetchBatches() on purpose: the
 *   alert page and this dashboard must never disagree on what "expired" means.
 * - The page only reads; all writes stay in the owning modules.
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
dol_include_once('/patient/lib/patient.lib.php');

/**
 * @var Conf $conf
 * @var DoliDB $db
 * @var Translate $langs
 * @var User $user
 */

$langs->loadLangs(array("patient@patient"));

if (!$user->hasRight('patient', 'read')) {
	accessforbidden();
}

$P = $db->prefix();
$today = date('Y-m-d');
$entity = (int) $conf->entity;

/**
 * Run a count/sum scalar. Returns null when the table is not available (a
 * module disabled or not yet installed) so one missing module cannot break the
 * whole workbench.
 *
 * @param	DoliDB	$db		Database handler
 * @param	string	$sql		Scalar query
 * @return	float|null		Null on SQL error
 */
function clinic_dash_scalar($db, $sql)
{
	$resql = $db->query($sql);
	if (!$resql) {
		return null;
	}
	$obj = $db->fetch_object($resql);
	$db->free($resql);
	return $obj ? (float) $obj->n : 0.0;
}

// ------------------------------------------------------------------ counters
$canVisit = $user->hasRight('medrecord', 'read');
$canPrescribe = $user->hasRight('prescription', 'read');
$canPharmacy = $user->hasRight('pharmacy', 'read');
$canBill = $user->hasRight('clinicpay', 'read');

$stats = array();

if ($canVisit) {
	$stats['visits'] = clinic_dash_scalar($db, "SELECT COUNT(*) n FROM ".$P."medrecord WHERE entity = ".$entity." AND visit_date >= '".$today." 00:00:00' AND visit_date <= '".$today." 23:59:59'");
}
if ($canPrescribe) {
	$stats['prescriptions'] = clinic_dash_scalar($db, "SELECT COUNT(*) n FROM ".$P."prescription WHERE entity = ".$entity." AND date_creation >= '".$today." 00:00:00' AND date_creation <= '".$today." 23:59:59'");
}
if ($canPharmacy) {
	$stats['dispenses'] = clinic_dash_scalar($db, "SELECT COUNT(*) n FROM ".$P."pharmacy_dispense WHERE entity = ".$entity." AND date_dispense >= '".$today." 00:00:00' AND date_dispense <= '".$today." 23:59:59'");
}
if ($canBill) {
	$stats['revenue'] = clinic_dash_scalar($db, "SELECT COALESCE(SUM(amount_total),0) n FROM ".$P."clinicpay_bill WHERE entity = ".$entity." AND status = 1 AND date_pay >= '".$today." 00:00:00' AND date_pay <= '".$today." 23:59:59'");
	$stats['drafts'] = clinic_dash_scalar($db, "SELECT COUNT(*) n FROM ".$P."clinicpay_bill WHERE entity = ".$entity." AND status = 0");
}

// Expiry: same source of truth as the alert page (its own query, not a copy)
$expiryExpired = 0;
$expirySoon = 0;
if ($canPharmacy && isModEnabled('pharmacy')) {
	dol_include_once('/pharmacy/class/pharmacyexpiryalert.class.php');
	$alert = new PharmacyExpiryAlert($db);
	$rows = $alert->collect(90, (int) getDolGlobalString('PHARMACY_WAREHOUSE_ID'));
	if (is_array($rows)) {
		$endOfToday = strtotime($today.' 23:59:59');
		foreach ($rows as $row) {
			// collect() returns unix timestamps; sell-by wins over eat-by
			$limit = (int) $row['sellby'] > 0 ? (int) $row['sellby'] : (int) $row['eatby'];
			if ($limit > 0 && $limit <= $endOfToday) {
				$expiryExpired++;
			} else {
				$expirySoon++;
			}
		}
	}
}

// ------------------------------------------------------------------ latest visits
$latestVisits = array();
if ($canVisit) {
	$sql = "SELECT mr.rowid, mr.ref, mr.visit_date, mr.fk_patient, mr.fk_doctor";
	$sql .= ", pp.card_no, s.nom as patient_name, d.title_code";
	$sql .= " FROM ".$P."medrecord as mr";
	$sql .= " LEFT JOIN ".$P."patient_profile as pp ON pp.rowid = mr.fk_patient";
	$sql .= " LEFT JOIN ".$P."societe as s ON s.rowid = pp.fk_soc";
	$sql .= " LEFT JOIN ".$P."patient_doctor as d ON d.rowid = mr.fk_doctor";
	$sql .= " WHERE mr.entity = ".$entity;
	$sql .= $db->order('mr.rowid', 'DESC');
	$sql .= $db->plimit(5);
	$resql = $db->query($sql);
	if ($resql) {
		while ($o = $db->fetch_object($resql)) {
			$latestVisits[] = $o;
		}
		$db->free($resql);
	}
}

// ------------------------------------------------------------------ charts (right column)
// The left menu already lists every page, so the dashboard shows trends
// instead of a second copy of the navigation. Which charts appear depends on
// what the operator may see: billing figures need clinicpay read, visit
// figures need medrecord read.
$charts = array();
$chartWindowDays = 30;
$fromDate = date('Y-m-d', strtotime('-' . ($chartWindowDays - 1) . ' day'));

/**
 * Build a per-day series over the last N days, zero-filled so the chart has no
 * gaps (the DB only returns days that have rows).
 *
 * @param	DoliDB	$db			Database handler
 * @param	string	$table		Table name without prefix
 * @param	string	$dateField	Datetime column
 * @param	string	$extraWhere	Extra WHERE conditions
 * @param	string	$sumField	Column to sum (empty = count rows)
 * @return	array<int,array{0:string,1:float}>	label => value, oldest first
 */
function clinic_dash_series($db, $table, $dateField, $extraWhere, $sumField = '')
{
	global $conf, $chartWindowDays, $clinic_dash_from;

	$sql = "SELECT DATE(".$dateField.") as d, ".($sumField !== '' ? "COALESCE(SUM(".$sumField."),0)" : "COUNT(*)")." as v";
	$sql .= " FROM ".$db->prefix().$table;
	$sql .= " WHERE entity = ".((int) $conf->entity)." AND ".$dateField." >= '".$clinic_dash_from." 00:00:00'";
	if ($extraWhere !== '') {
		$sql .= " AND ".$extraWhere;
	}
	$sql .= " GROUP BY DATE(".$dateField.")";
	$resql = $db->query($sql);
	$map = array();
	if ($resql) {
		while ($o = $db->fetch_object($resql)) {
			$map[$o->d] = (float) $o->v;
		}
		$db->free($resql);
	}

	$series = array();
	for ($i = $chartWindowDays - 1; $i >= 0; $i--) {
		$day = date('Y-m-d', strtotime('-'.$i.' day'));
		$series[] = array($day, isset($map[$day]) ? $map[$day] : 0.0);
	}
	return $series;
}

/**
 * Query string that lands a list page on one single day. List pages read
 * Dolibarr's triplet date filter, so a bar drill-down reuses it with the same
 * day on both ends.
 *
 * @param	string	$day		Day as YYYY-MM-DD
 * @param	string	$extra		Extra query pairs already urlencoded
 * @return	string				Query string without the leading "?"
 */
function clinic_dash_dayparams($day, $extra = '')
{
	$ts = strtotime($day);
	$q = 'search_fromyear='.date('Y', $ts).'&search_frommonth='.date('m', $ts).'&search_fromday='.date('d', $ts);
	$q .= '&search_toyear='.date('Y', $ts).'&search_tomonth='.date('m', $ts).'&search_today='.date('d', $ts);
	return $extra === '' ? $q : $q.'&'.$extra;
}

/**
 * Same as clinic_dash_dayparams() but for a whole range, which is what a
 * doughnut slice (a 30-day mix) must drill into.
 *
 * @param	string	$from		First day as YYYY-MM-DD
 * @param	string	$to		Last day as YYYY-MM-DD
 * @param	string	$extra		Extra query pairs already urlencoded
 * @return	string				Query string without the leading "?"
 */
function clinic_dash_rangeparams($from, $to, $extra = '')
{
	$f = strtotime($from);
	$t = strtotime($to);
	$q = 'search_fromyear='.date('Y', $f).'&search_frommonth='.date('m', $f).'&search_fromday='.date('d', $f);
	$q .= '&search_toyear='.date('Y', $t).'&search_tomonth='.date('m', $t).'&search_today='.date('d', $t);
	return $extra === '' ? $q : $q.'&'.$extra;
}

$clinic_dash_from = $fromDate;

// Revenue per day (cashier / admin)
if ($canBill) {
	if (isModEnabled('clinicpay')) {
		dol_include_once('/clinicpay/lib/clinicpay.lib.php');
	}
	$series = clinic_dash_series($db, 'clinicpay_bill', 'date_pay', "status = 1", 'amount_total');
	$labels = array();
	$data = array();
	$dayLinks = array();
	foreach ($series as $point) {
		$labels[] = date('n/j', strtotime($point[0]));
		$data[] = $point[1];
		// The chart counts paid bills by date_pay, so the list must filter the
		// same way or the drill-down totals would not match the bar.
		$dayLinks[] = clinic_dash_dayparams($point[0], 'date_field=date_pay&search_status=1');
	}
	$charts[] = array(
		'id' => 'clinic-dash-revenue',
		'title' => $langs->trans("ClinicDashRevenue30d", $chartWindowDays),
		'type' => 'bar',
		'labels' => $labels,
		'data' => $data,
		'colors' => '#1e88e5',
		'height' => 120,
		'money' => true,
		'url' => '/clinicpay/report.php',
		'drill' => array('kind' => 'bar', 'url' => '/clinicpay/bill_list.php', 'params' => $dayLinks),
	);

	// Channel mix over the same window
	$sql = "SELECT channel, COALESCE(SUM(amount_total),0) as v FROM ".$P."clinicpay_bill";
	$sql .= " WHERE entity = ".$entity." AND status = 1 AND date_pay >= '".$fromDate." 00:00:00'";
	$sql .= " GROUP BY channel";
	$resql = $db->query($sql);
	$mixLabels = array();
	$mixData = array();
	$mixKeys = array();
	$mixColors = array('#00897b', '#fb8c00', '#5e35b1', '#c62828', '#546e7a');
	$ci = 0;
	if ($resql) {
		while ($o = $db->fetch_object($resql)) {
			if ((float) $o->v <= 0) {
				continue;
			}
			$mixLabels[] = clinicpay_channel_label((string) $o->channel);
			$mixData[] = (float) $o->v;
			// A slice covers the whole window, so it drills into the range and
			// keeps the channel filter instead of a single day.
			$mixKeys[] = clinic_dash_rangeparams($fromDate, $today, 'search_channel='.urlencode((string) $o->channel).'&date_field=date_pay&search_status=1');
			$ci++;
		}
		$db->free($resql);
	}
	if ($mixLabels) {
		$mixColors = array_slice($mixColors, 0, max(1, count($mixLabels)));
		$charts[] = array(
			'id' => 'clinic-dash-channels',
			'title' => $langs->trans("ClinicDashChannelMix", $chartWindowDays),
			'type' => 'doughnut',
			'labels' => $mixLabels,
			'data' => $mixData,
			'colors' => $mixColors,
			'height' => 150,
			'legend' => true,
			'money' => true,
			'url' => '/clinicpay/report.php',
			'drill' => array('kind' => 'slice', 'url' => '/clinicpay/bill_list.php', 'params' => $mixKeys),
		);
	}
}

if ($canVisit) {
	// Visit volume (doctor / any operator with medrecord read)
	$series = clinic_dash_series($db, 'medrecord', 'visit_date', '');
	$labels = array();
	$data = array();
	$dayLinks = array();
	foreach ($series as $point) {
		$labels[] = date('n/j', strtotime($point[0]));
		$data[] = $point[1];
		$dayLinks[] = clinic_dash_dayparams($point[0]);
	}
	$charts[] = array(
		'id' => 'clinic-dash-visits',
		'title' => $langs->trans("ClinicDashVisits30d", $chartWindowDays),
		'type' => 'bar',
		'labels' => $labels,
		'data' => $data,
		'colors' => '#00897b',
		'height' => 150,
		'url' => '/medrecord/report_visits.php',
		// The visit list already filters on visit_date, the chart's own column.
		'drill' => array('kind' => 'bar', 'url' => '/medrecord/list.php', 'params' => $dayLinks),
	);
}

// Pharmacy view: dispensing volume and stock structure. A pharmacist has no
// billing rights, so without these the right column would be empty for them.
if ($canPharmacy) {
	$series = clinic_dash_series($db, 'pharmacy_dispense', 'date_dispense', '');
	$labels = array();
	$data = array();
	$dayLinks = array();
	foreach ($series as $point) {
		$labels[] = date('n/j', strtotime($point[0]));
		$data[] = $point[1];
		$dayLinks[] = clinic_dash_dayparams($point[0], 'date_field=date_dispense');
	}
	$charts[] = array(
		'id' => 'clinic-dash-dispenses',
		'title' => $langs->trans("ClinicDashDispenses30d", $chartWindowDays),
		'type' => 'bar',
		'labels' => $labels,
		'data' => $data,
		'colors' => '#fb8c00',
		'height' => 120,
		'url' => '/pharmacy/report.php',
		'drill' => array('kind' => 'bar', 'url' => '/pharmacy/list.php', 'params' => $dayLinks),
	);

	// Stock structure: top 6 products by quantity on hand
	$sql = "SELECT p.rowid as pid, p.ref, p.label, SUM(ps.reel) as q";
	$sql .= " FROM ".$P."product_stock as ps";
	$sql .= " INNER JOIN ".$P."product as p ON p.rowid = ps.fk_product";
	$sql .= " WHERE p.entity IN (".getEntity('product').")";
	$sql .= " GROUP BY p.rowid, p.ref, p.label";
	$sql .= " HAVING q > 0";
	$sql .= $db->order('q', 'DESC');
	$sql .= $db->plimit(6);
	$resql = $db->query($sql);
	$stockLabels = array();
	$stockData = array();
	$stockKeys = array();
	$stockColors = array('#00897b', '#fb8c00', '#5e35b1', '#1e88e5', '#c62828', '#546e7a');
	if ($resql) {
		while ($o = $db->fetch_object($resql)) {
			$stockLabels[] = (string) $o->ref;
			$stockData[] = (float) $o->q;
			// A product slice opens every dispensing sheet that contained it.
			$stockKeys[] = 'search_fk_product='.(int) $o->pid;
		}
		$db->free($resql);
	}
	if ($stockLabels) {
		$charts[] = array(
			'id' => 'clinic-dash-stock',
			'title' => $langs->trans("ClinicDashStockTop"),
			'type' => 'doughnut',
			'labels' => $stockLabels,
			'data' => $stockData,
			'colors' => array_slice($stockColors, 0, count($stockLabels)),
			'height' => 150,
			'legend' => true,
			'url' => '/pharmacy/report_stock.php',
			'drill' => array('kind' => 'slice', 'url' => '/pharmacy/list.php', 'params' => $stockKeys),
		);
	}
}

llxHeader('', $langs->trans("ClinicWorkbench"));

print load_fiche_titre($langs->trans("ClinicWorkbench"), '', 'stethoscope');

/**
 * Render one dashboard card: grey title bar + compact body rows.
 *
 * @param	string	$title		Card title
 * @param	string	$body		Already-built rows
 * @param	string	$url		Optional "see all" link
 * @param	int		$colspan	Column count of the body, so the title bar spans it
 * @return	void
 */
function clinic_dash_card($title, $body, $url = '', $colspan = 2)
{
	print '<div class="div-table-responsive-no-min">';
	print '<table class="noborder centpercent">';
	print '<tr class="liste_titre"><th colspan="'.(int) $colspan.'">'.dol_escape_htmltag($title);
	if ($url !== '') {
		print '<a href="'.dol_escape_htmltag($url).'" title="'.dol_escape_htmltag($title).'"><span class="badge marginleftonlyshort">...</span></a>';
	}
	print '</th></tr>';
	print $body;
	print '</table>';
	print '</div>';
}

print '<div class="fichecenter fichecenterbis">';
print '<div class="twocolumns">';

// ================= left column: today's figures, alerts, latest visits
print '<div class="firstcolumn fichehalfleft boxhalfleft" id="boxhalfleft">';

// ---- today's figures (compact rows, not a flat grid of empty cells)
$rows = '';
if (isset($stats['visits'])) {
	$rows .= '<tr><td>'.img_picto('', 'fa-stethoscope_fas_#00897b', 'class="pictofixedwidth valignmiddle"').$langs->trans("ClinicDashVisits").'</td><td class="right"><strong>'.(int) $stats['visits'].'</strong></td></tr>';
}
if (isset($stats['prescriptions'])) {
	$rows .= '<tr><td>'.img_picto('', 'fa-file-medical_fas_#5e35b1', 'class="pictofixedwidth valignmiddle"').$langs->trans("ClinicDashPrescriptions").'</td><td class="right"><strong>'.(int) $stats['prescriptions'].'</strong></td></tr>';
}
if (isset($stats['dispenses'])) {
	$rows .= '<tr><td>'.img_picto('', 'fa-pills_fas_#fb8c00', 'class="pictofixedwidth valignmiddle"').$langs->trans("ClinicDashDispenses").'</td><td class="right"><strong>'.(int) $stats['dispenses'].'</strong></td></tr>';
}
if (isset($stats['revenue'])) {
	$rows .= '<tr><td>'.img_picto('', 'fa-cash-register_fas_#fb8c00', 'class="pictofixedwidth valignmiddle"').$langs->trans("ClinicDashRevenue").'</td><td class="right"><strong>'.price($stats['revenue']).'</strong></td></tr>';
}
if (isset($stats['drafts'])) {
	$rows .= '<tr><td>'.img_picto('', 'fa-file-invoice-dollar_fas_#1e88e5', 'class="pictofixedwidth valignmiddle"').$langs->trans("ClinicDashDrafts").'</td><td class="right"><strong>'.(int) $stats['drafts'].'</strong></td></tr>';
}
if ($canPharmacy) {
	$flag = $expiryExpired > 0 ? ' class="error"' : '';
	$rows .= '<tr><td>'.img_picto('', 'fa-clock_fas_#c62828', 'class="pictofixedwidth valignmiddle"').$langs->trans("ClinicDashExpiry").'</td>';
	$rows .= '<td class="right"'.$flag.'><strong>'.$expiryExpired.'</strong> / '.$expirySoon.'</td></tr>';
}
if ($rows) {
	clinic_dash_card($langs->trans("ClinicDashToday"), $rows);
	print '<div class="opacitymedium paddingbottom">'.$langs->trans("ClinicDashExpiryHint").'</div>';
}

// ---- alerts
$alertRows = '';
if ($expiryExpired > 0) {
	$alertRows .= '<tr><td class="error"><a href="'.dol_buildpath('/pharmacy/expiry.php', 1).'">'.dol_escape_htmltag($langs->trans("ClinicDashAlertExpired", $expiryExpired)).'</a></td></tr>';
}
if ($expirySoon > 0) {
	$alertRows .= '<tr><td class="opacitymedium"><a href="'.dol_buildpath('/pharmacy/expiry.php', 1).'">'.dol_escape_htmltag($langs->trans("ClinicDashAlertSoon", $expirySoon)).'</a></td></tr>';
}
if (!empty($stats['drafts'])) {
	$alertRows .= '<tr><td class="opacitymedium"><a href="'.dol_buildpath('/clinicpay/bill_list.php', 1).'">'.dol_escape_htmltag($langs->trans("ClinicDashAlertDrafts", (int) $stats['drafts'])).'</a></td></tr>';
}
if ($alertRows) {
	clinic_dash_card($langs->trans("ClinicDashTodo"), $alertRows);
}

// ---- latest visits
if (!empty($latestVisits)) {
	$rows = '';
	foreach ($latestVisits as $visit) {
		$rows .= '<tr><td>'.dol_print_date($db->jdate($visit->visit_date), 'dayhour').'</td>';
		$rows .= '<td><a href="'.dol_buildpath('/patient/card.php', 1).'?id='.(int) $visit->fk_patient.'">'.dol_escape_htmltag((string) $visit->patient_name).'</a></td>';
		$rows .= '<td class="right"><a href="'.dol_buildpath('/medrecord/card.php', 1).'?id='.(int) $visit->rowid.'">'.dol_escape_htmltag((string) $visit->ref).'</a></td></tr>';
	}
	clinic_dash_card($langs->trans("ClinicDashLatestVisits"), $rows, '/medrecord/list.php', 3);
}
print '</div>';

// ================= right column: charts
print '<div class="secondcolumn fichehalfright boxhalfright" id="boxhalfright">';
foreach ($charts as $chart) {
	print '<div class="div-table-responsive-no-min">';
	print '<table class="noborder centpercent">';
	print '<tr class="liste_titre"><th colspan="2">'.dol_escape_htmltag($chart['title']);
	if (!empty($chart['drill'])) {
		print '<span class="opacitymedium fontsmall paddingleft">'.dol_escape_htmltag($langs->trans("ClinicDashDrillHint")).'</span>';
	}
	if (!empty($chart['url'])) {
		print '<a href="'.dol_buildpath($chart['url'], 1).'"><span class="badge marginleftonlyshort">...</span></a>';
	}
	print '</th></tr>';
	if (array_sum($chart['data']) > 0) {
		print '<tr><td colspan="2"><canvas id="'.$chart['id'].'" height="'.(int) $chart['height'].'"></canvas></td></tr>';
	} else {
		print '<tr><td colspan="2" class="opacitymedium">'.$langs->trans("ClinicDashNoData").'</td></tr>';
	}
	print '</table>';
	print '</div>';
}
print '</div>';
print '</div>';

// ------------------------------------------------------------------ chart bootstrap
// Chart.js is loaded globally by llxHeader (MAIN_JS_GRAPH). Data goes through a
// JSON script tag with hex-escaped flags so no value can break out of <script>.
if ($charts) {
	$payload = array();
	foreach ($charts as $chart) {
		if (array_sum($chart['data']) <= 0) {
			continue;
		}
		$payload[] = array(
			'id' => $chart['id'],
			'type' => $chart['type'],
			'labels' => $chart['labels'],
			'data' => $chart['data'],
			'colors' => $chart['colors'],
			'legend' => !empty($chart['legend']),
			'money' => !empty($chart['money']),
			// drill.url must go through dol_buildpath(): the click handler feeds
			// it to location.href, and a bare /clinicpay/... drops the
			// /dolibarr prefix and 404s.
			'drill' => isset($chart['drill']) ? array(
				'kind' => $chart['drill']['kind'],
				'url' => dol_buildpath($chart['drill']['url'], 1),
				'params' => $chart['drill']['params'],
			) : null,
		);
	}
	if ($payload) {
		print '<script nonce="'.getNonce().'" type="application/json" id="clinic-dash-charts">';
		print json_encode($payload, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
		print '</script>';
		print '<script nonce="'.getNonce().'">';
		print 'jQuery(document).ready(function(){';
		print 'var cfg=JSON.parse(jQuery("#clinic-dash-charts").text());';
		print 'cfg.forEach(function(c){var el=document.getElementById(c.id);if(!el)return;';
		print 'var isBar=c.type==="bar";';
		print 'var ds={labels:c.labels,datasets:[{data:c.data,backgroundColor:c.colors,borderWidth:0,borderRadius:isBar?3:0}]};';
		// Axes only exist for cartesian charts; passing them to a doughnut
		// leaves an empty axis area on the left of the canvas.
		print 'var opt={responsive:true,maintainAspectRatio:false,plugins:{legend:{display:c.legend,position:"right",labels:{boxWidth:10,font:{size:10}}},';
		// label callback must return a string: a raw number makes Chart.js
		// print "[object Object]"; doughnut values live in x.parsed (not .y)
		// and read better with their label in front.
		print 'tooltip:{callbacks:{label:function(x){var p=x.parsed;';
		print 'var v=(p&&typeof p==="object")?(p.y!==undefined?p.y:p):p;';
		print 'var s=c.money?Number(v).toFixed(2):String(v);return (c.type==="doughnut")?(x.label+": "+s):s;}}}},cutout:"55%"};';
		print 'if(isBar){opt.scales={x:{ticks:{font:{size:9},maxRotation:0,autoSkipPadding:8},grid:{display:false}},';
		print 'y:{beginAtZero:true,ticks:{font:{size:9},precision:0}}};}';
		// Drill-down: the server precomputes one query string per data point so
		// the JS never has to know the list pages' filter names.
		print 'if(c.drill&&c.drill.url){';
		print 'opt.onClick=function(e,els){if(!els.length)return;var q=c.drill.params[els[0].index];if(q){window.location.href=c.drill.url+"?"+q;}};';
		print 'opt.onHover=function(e,els){var t=e.native&&e.native.target;if(t){t.style.cursor=els.length?"pointer":"default";}};';
		print '}';
		print 'new Chart(el.getContext("2d"),{type:c.type,data:ds,options:opt});});';
		print '});';
		print '</script>';
	}
}
print '</div>';

llxFooter();
$db->close();
