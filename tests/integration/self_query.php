<?php
/* Copyright (C) 2026 modPatient contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Self-query read helpers against the real database (read-only except one
 * refunded-card fixture row that is deleted in a finally block).
 * Run with the stack php:  php tests/integration/self_query.php
 *
 * Covers: secret stability, URL sign/verify roundtrip, tamper + expiry
 * rejection, per-patient isolation of the five read helpers, and the
 * refunded-card exclusion on the self page.
 */

if (PHP_SAPI !== 'cli') {
	die('CLI only');
}

define('NOLOGIN', 1);
define('NOTOKENRENEWAL', 1);
define('NOREQUIREMENU', 1);
$ok = @include dirname(__DIR__, 4).'/main.inc.php';
if (!$ok) {
	die('main.inc.php include failed');
}

dol_include_once('/patient/lib/patient_self.lib.php');

$pass = 0;
$fail = 0;
function check($name, $cond)
{
	global $pass, $fail;
	if ($cond) {
		$pass++;
		echo "PASS  $name\n";
	} else {
		$fail++;
		echo "FAIL  $name\n";
	}
}

function countSql($db, $sql)
{
	$r = $db->query($sql);
	if (!$r) {
		return -1;
	}
	$o = $db->fetch_object($r);
	return (int) $o->n;
}

$prefix = $db->prefix();

try {
	// --- secret: stable across calls, long enough to be unguessable ---
	$s1 = patientSelfSecret();
	$s2 = patientSelfSecret();
	check('secret stable across calls', $s1 === $s2 && strlen($s1) >= 32);

	// --- URL roundtrip: verify accepts what MakeUrl emits ---
	$url = patientSelfMakeUrl(1, 900);
	$q = array();
	parse_str(parse_url($url, PHP_URL_QUERY), $q);
	check('url carries pid/exp/sig', isset($q['pid'], $q['exp'], $q['sig']));
	check('url roundtrip verifies', patientSelfVerify((int) $q['pid'], (int) $q['exp'], $q['sig']) === true);

	// --- tamper: any single-char change must fail ---
	$bad = $q['sig'];
	$bad[0] = ($bad[0] === '0') ? '1' : '0';
	check('tampered sig rejected', patientSelfVerify((int) $q['pid'], (int) $q['exp'], $bad) === false);

	// --- expiry: signature over a past exp must fail ---
	$past = time() - 10;
	check('expired link rejected', patientSelfVerify(1, $past, patientSelfMakeToken(1, $past, patientSelfSecret())) === false);

	// --- per-patient isolation: helper counts must equal direct SQL counts ---
	foreach (array(1, 4) as $pid) {
		check("allergies isolated pid=$pid", count(patientSelfAllergies($db, $pid)) === countSql($db, "SELECT COUNT(*) AS n FROM ".$prefix."patient_allergy WHERE fk_patient = ".$pid." AND status = 1"));
		check("cards isolated pid=$pid", count(patientSelfCards($db, $pid)) === countSql($db, "SELECT COUNT(*) AS n FROM ".$prefix."clinicpay_card WHERE fk_patient = ".$pid." AND status <> 3"));
		check("visits isolated pid=$pid", count(patientSelfVisits($db, $pid, 10)) === min(10, countSql($db, "SELECT COUNT(*) AS n FROM ".$prefix."medrecord WHERE fk_patient = ".$pid)));
		check("prescriptions isolated pid=$pid", count(patientSelfPrescriptions($db, $pid, 10)) === min(10, countSql($db, "SELECT COUNT(*) AS n FROM ".$prefix."prescription WHERE fk_patient = ".$pid)));
		check("bills isolated pid=$pid", count(patientSelfBills($db, $pid, 10)) === min(10, countSql($db, "SELECT COUNT(*) AS n FROM ".$prefix."clinicpay_bill WHERE fk_patient = ".$pid)));
	}

	// --- refunded card fixture: status=3 must never appear on the self page ---
	$testRef = 'SELFTEST'.strtoupper(bin2hex(random_bytes(4)));
	$db->query("INSERT INTO ".$prefix."clinicpay_card (entity, ref, fk_patient, card_type, total_count, used_count, total_value, used_value, status, date_creation) VALUES (1, '".$db->escape($testRef)."', 1, 'COUNT', 10, 0, 0, 0, 3, '".$db->idate(dol_now())."')");
	try {
		$cards = patientSelfCards($db, 1);
		$found = false;
		foreach ($cards as $c) {
			if ($c['ref'] === $testRef) {
				$found = true;
			}
		}
		check('refunded card excluded', !$found);
	} finally {
		$db->query("DELETE FROM ".$prefix."clinicpay_card WHERE ref = '".$db->escape($testRef)."'");
	}

	// --- audit write path (same helper the rate limiter depends on) ---
	$auditId = patient_audit($db, 1, 'SELF_TEST', null, array('integration' => true));
	check('audit insert works', $auditId > 0);
	if ($auditId > 0) {
		$db->query("DELETE FROM ".$prefix."patient_audit WHERE rowid = ".((int) $auditId));
	}
} catch (Exception $e) {
	$fail++;
	echo 'EXCEPTION: '.$e->getMessage()."\n";
}

echo "Result: $pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
