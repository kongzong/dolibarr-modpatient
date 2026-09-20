<?php
/* Copyright (C) 2026 modPatient contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Concurrent card-number reservation against a real MySQL/MariaDB.
 * Run: php tests/integration/numbering.php
 * Uses only randomly prefixed fixture tables (patient_test_<hex>_*); all are
 * dropped in finally. Credentials come from PATIENT_TEST_DB_* env vars or, on
 * the DoliWamp box, from htdocs/conf/conf.php; they are never printed.
 *
 * Scenarios (spec §6.B):
 *  - 20 parallel registrations of the same month: unique, consecutive numbers
 *  - a rolled-back registration frees its number for the next one
 *  - numbers assigned while another transaction holds the lock wait for it
 *  - preview outside a transaction reserves nothing
 */

if (PHP_SAPI !== 'cli') {
	die('CLI only');
}
$workerMode = isset($argv[1]) && $argv[1] === '--worker';
$prefix = $workerMode ? $argv[2] : 'patient_test_'.bin2hex(random_bytes(6)).'_';
if (!preg_match('/^patient_test_[a-f0-9]{12}_$/D', $prefix)) {
	die('Invalid isolated table prefix');
}
define('MAIN_DB_PREFIX', $prefix);
define('DOL_DOCUMENT_ROOT', getenv('DOLIBARR_DOCUMENT_ROOT') ?: dirname(__DIR__, 4));
$testBaseDir = getenv('PATIENT_TEST_TMPDIR') ?: dirname(__DIR__, 2).'/temp';
$testDir = $workerMode ? $argv[3] : $testBaseDir.'/patient_numbering_'.bin2hex(random_bytes(6));
date_default_timezone_set('UTC');

$conf = (object) array(
	'entity' => 1,
	'db' => (object) array('character_set' => 'utf8mb4', 'dolibarr_main_db_collation' => 'utf8mb4_unicode_ci'),
);
function dol_syslog($message, $level = 0, $indent = 0) {}
function getDolGlobalString($key, $default = '') { return $default; }

require_once DOL_DOCUMENT_ROOT.'/core/db/mysqli.class.php';
require_once dirname(__DIR__, 2).'/class/patientcardnumbering.class.php';

/** Read credentials in process; never put them in command arguments or test output. */
function patientTestConnect()
{
	if (getenv('PATIENT_TEST_DB_NAME') !== false) {
		$host = getenv('PATIENT_TEST_DB_HOST') ?: '127.0.0.1';
		$name = getenv('PATIENT_TEST_DB_NAME');
		$login = getenv('PATIENT_TEST_DB_USER');
		$password = getenv('PATIENT_TEST_DB_PASSWORD');
		$port = (int) (getenv('PATIENT_TEST_DB_PORT') ?: 3306);
	} else {
		require DOL_DOCUMENT_ROOT.'/conf/conf.php';
		$host = $dolibarr_main_db_host;
		$name = $dolibarr_main_db_name;
		$login = $dolibarr_main_db_user;
		$password = $dolibarr_main_db_pass;
		$port = (int) ($dolibarr_main_db_port ?: 3306);
	}
	try {
		$connection = new DoliDBMysqli('mysqli', $host, $login, $password, $name, $port);
	} catch (Throwable $error) {
		throw new RuntimeException('Cannot connect to the integration test database');
	}
	if (!$connection->connected || !$connection->database_selected) {
		throw new RuntimeException('Cannot connect to the integration test database');
	}
	patientTestQuery($connection, 'SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
	patientTestQuery($connection, 'SET SESSION innodb_lock_wait_timeout = 10');
	return $connection;
}
function patientTestQuery($connection, $sql)
{
	$result = $connection->query($sql);
	if (!$result) {
		throw new RuntimeException('Integration fixture SQL failed: '.$connection->lasterrno());
	}
	return $result;
}
function patientTestScalar($connection, $sql)
{
	$result = patientTestQuery($connection, $sql);
	$row = $connection->fetch_row($result);
	$connection->free($result);
	return $row ? $row[0] : null;
}
function patientTestEvent($id, $event, $value = array())
{
	global $testDir;
	$file = $testDir.'/'.$id.'.'.$event;
	file_put_contents($file.'.tmp', json_encode($value));
	rename($file.'.tmp', $file);
}
function patientTestWaitFile($id, $event, $seconds = 20)
{
	global $testDir;
	$deadline = microtime(true) + $seconds;
	$file = $testDir.'/'.$id.'.'.$event;
	do {
		clearstatcache(true, $file);
		if (is_file($file)) {
			return json_decode(file_get_contents($file), true);
		}
		usleep(20000);
	} while (microtime(true) < $deadline);
	throw new RuntimeException('Timed out waiting for worker '.$id.' '.$event);
}

// ---------------------------------------------------------------- worker
// Mirrors PatientProfile::create(): begin, reserve, insert the profile row, commit.
if ($workerMode) {
	$id = (int) $argv[4];
	$prefixMonth = $argv[5];
	$mode = $argv[6]; // normal | rollback | pause
	$db = null;
	try {
		$db = patientTestConnect();
		patientTestEvent($id, 'ready', array('connection' => (int) patientTestScalar($db, 'SELECT CONNECTION_ID()')));
		if ($mode === 'pause') {
			patientTestWaitFile($id, 'go');
		}
		$db->begin();
		$number = (new PatientCardNumbering($db))->nextReference($prefixMonth);
		patientTestEvent($id, 'allocated', array('ref' => $number));
		if ($mode === 'pause') {
			patientTestWaitFile($id, 'release');
		}
		patientTestQuery($db, 'INSERT INTO '.MAIN_DB_PREFIX."patient_profile (entity, fk_soc, card_no, gender, date_creation) VALUES (1, ".$id.", '".$db->escape($number)."', 'U', NOW())");
		if ($mode === 'rollback') {
			$db->rollback();
		} else {
			$db->commit();
		}
		patientTestEvent($id, 'done', array('ref' => $number, 'mode' => $mode, 'depth' => $db->transaction_opened));
	} catch (Throwable $error) {
		patientTestEvent($id, 'done', array('error' => $error->getMessage(), 'depth' => $db ? $db->transaction_opened : null));
	} finally {
		if ($db) {
			$db->close();
		}
	}
	exit;
}

// ---------------------------------------------------------------- driver
$workers = array();
$db = null;
$passed = 0;
$failed = 0;
$createdTables = array();

function check($condition, $message)
{
	if (!$condition) {
		throw new RuntimeException($message);
	}
}
function testCase($name, $callback)
{
	global $passed;
	$callback();
	$passed++;
	echo 'PASS  '.$name."\n";
}
function startWorker($id, $month, $mode = 'normal')
{
	global $workers, $testDir;
	$command = array(PHP_BINARY, __FILE__, '--worker', MAIN_DB_PREFIX, $testDir, (string) $id, $month, $mode);
	$process = proc_open($command, array(
		0 => array('file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'r'),
		1 => array('file', $testDir.'/'.$id.'.stdout', 'w'),
		2 => array('file', $testDir.'/'.$id.'.stderr', 'w'),
	), $pipes, null, null, array('bypass_shell' => true, 'create_new_console' => false));
	check(is_resource($process), 'Could not start a PHP worker');
	$workers[$id] = $process;
	return patientTestWaitFile($id, 'ready');
}
function waitForLock($id, $connection)
{
	global $db, $testDir;
	$deadline = microtime(true) + 8;
	$observations = 0;
	do {
		clearstatcache();
		check(!is_file($testDir.'/'.$id.'.allocated'), 'Second registration got a number before the first committed');
		check(!is_file($testDir.'/'.$id.'.done'), 'Second registration failed before the expected lock wait');
		$statement = patientTestScalar($db, 'SELECT INFO FROM information_schema.PROCESSLIST WHERE ID = '.((int) $connection));
		if (is_string($statement) && strpos($statement, 'INSERT INTO '.MAIN_DB_PREFIX.'patient_card_sequence') === 0) {
			if (++$observations >= 3) {
				return;
			}
		} else {
			$observations = 0;
		}
		usleep(50000);
	} while (microtime(true) < $deadline);
	throw new RuntimeException('Second registration did not enter an InnoDB lock wait');
}

try {
	if (!is_dir($testBaseDir)) {
		check(mkdir($testBaseDir, 0700, true), 'Could not create the test temp directory');
	}
	check(mkdir($testDir, 0700), 'Could not create an isolated worker directory');
	$db = patientTestConnect();

	// Fixture tables: the real sequence SQL + a minimal profile table with the real unique key
	$sequenceSql = str_replace('llx_', MAIN_DB_PREFIX, file_get_contents(dirname(__DIR__, 2).'/sql/llx_patient_card_sequence.sql'));
	patientTestQuery($db, $sequenceSql);
	$createdTables[] = MAIN_DB_PREFIX.'patient_card_sequence';
	patientTestQuery($db, 'CREATE TABLE '.MAIN_DB_PREFIX.'patient_profile (rowid INTEGER AUTO_INCREMENT PRIMARY KEY, entity INTEGER NOT NULL DEFAULT 1, fk_soc INTEGER NOT NULL, card_no VARCHAR(32) NOT NULL, gender VARCHAR(1), date_creation DATETIME, UNIQUE KEY uk_card (card_no)) ENGINE=InnoDB');
	$createdTables[] = MAIN_DB_PREFIX.'patient_profile';

	testCase('Preview outside a transaction reserves nothing', function () use ($db) {
		$numbering = new PatientCardNumbering($db);
		check($numbering->nextReference('HZ-209901-') === 'HZ-209901-0001', 'First preview wrong');
		check($numbering->nextReference('HZ-209901-') === 'HZ-209901-0001', 'Preview consumed a number');
		check((int) patientTestScalar($db, 'SELECT COUNT(*) FROM '.MAIN_DB_PREFIX."patient_card_sequence WHERE ref_prefix = 'HZ-209901-'") === 0, 'Preview inserted a counter');
	});

	testCase('20 parallel registrations of one month are unique and consecutive', function () use ($db) {
		$ids = range(1, 20);
		foreach ($ids as $id) {
			startWorker($id, 'HZ-202609-', 'pause');
		}
		foreach ($ids as $id) {
			patientTestEvent($id, 'go');
		}
		// Release each worker as soon as it has a number so the lock hand-off is exercised 20 times
		$pending = $ids;
		$deadline = microtime(true) + 60;
		while ($pending && microtime(true) < $deadline) {
			foreach ($pending as $k => $id) {
				clearstatcache();
				if (is_file($GLOBALS['testDir'].'/'.$id.'.allocated')) {
					patientTestEvent($id, 'release');
					unset($pending[$k]);
				}
			}
			usleep(20000);
		}
		check(!$pending, 'Some registrations never obtained a number');
		$refs = array();
		foreach ($ids as $id) {
			$done = patientTestWaitFile($id, 'done');
			check(!isset($done['error']), 'Worker '.$id.' failed: '.(isset($done['error']) ? $done['error'] : ''));
			check($done['depth'] === 0, 'Worker '.$id.' left a transaction open');
			$refs[] = $done['ref'];
		}
		sort($refs);
		$expected = array();
		for ($i = 1; $i <= 20; $i++) {
			$expected[] = sprintf('HZ-202609-%04d', $i);
		}
		check($refs === $expected, 'Concurrent card numbers are not unique and consecutive: '.implode(',', $refs));
		check((int) patientTestScalar($db, 'SELECT COUNT(DISTINCT card_no) FROM '.MAIN_DB_PREFIX."patient_profile WHERE card_no LIKE 'HZ-202609-%'") === 20, 'Committed rows do not match');
		check((int) patientTestScalar($db, 'SELECT last_value FROM '.MAIN_DB_PREFIX."patient_card_sequence WHERE ref_prefix = 'HZ-202609-'") === 20, 'Counter not advanced to 20');
	});

	testCase('Second registration waits for the first lock and gets the next number', function () use ($db) {
		startWorker(31, 'HZ-202610-', 'pause');
		patientTestEvent(31, 'go');
		$first = patientTestWaitFile(31, 'allocated');
		$second = startWorker(32, 'HZ-202610-');
		waitForLock(32, $second['connection']);
		patientTestEvent(31, 'release');
		patientTestWaitFile(31, 'done');
		$next = patientTestWaitFile(32, 'done');
		check($first['ref'] === 'HZ-202610-0001' && $next['ref'] === 'HZ-202610-0002', 'Lock hand-off produced wrong numbers');
	});

	testCase('Rolled-back registration frees its number; committed ones are never reused', function () use ($db) {
		startWorker(41, 'HZ-202611-', 'rollback');
		$rolled = patientTestWaitFile(41, 'done');
		check(!isset($rolled['error']) && $rolled['ref'] === 'HZ-202611-0001', 'Rollback worker failed');
		check((int) patientTestScalar($db, 'SELECT COUNT(*) FROM '.MAIN_DB_PREFIX."patient_profile WHERE card_no = 'HZ-202611-0001'") === 0, 'Rolled back row persisted');
		startWorker(42, 'HZ-202611-');
		$next = patientTestWaitFile(42, 'done');
		check($next['ref'] === 'HZ-202611-0001', 'Number of a rolled-back registration was not reused');
		// Delete the committed row: the counter still prevents reuse
		patientTestQuery($db, 'DELETE FROM '.MAIN_DB_PREFIX."patient_profile WHERE card_no = 'HZ-202611-0001'");
		startWorker(43, 'HZ-202611-');
		$after = patientTestWaitFile(43, 'done');
		check($after['ref'] === 'HZ-202611-0002', 'Deleted committed number was reused');
	});

	testCase('Missing sequence table aborts the transaction instead of returning a number', function () use ($db) {
		patientTestQuery($db, 'DROP TABLE '.MAIN_DB_PREFIX.'patient_card_sequence');
		$db->begin();
		$thrown = false;
		try {
			(new PatientCardNumbering($db))->nextReference('HZ-202612-');
		} catch (RuntimeException $error) {
			$thrown = true;
		}
		check($thrown && $db->transaction_opened === 0, 'Missing table did not abort the whole transaction');
		patientTestQuery($db, str_replace('llx_', MAIN_DB_PREFIX, file_get_contents(dirname(__DIR__, 2).'/sql/llx_patient_card_sequence.sql')));
	});

	testCase('Every committed profile has a valid unique card number', function () use ($db) {
		check((int) patientTestScalar($db, 'SELECT COUNT(*) FROM '.MAIN_DB_PREFIX."patient_profile WHERE card_no NOT REGEXP '^HZ-[0-9]{6}-[0-9]{4,}$'") === 0, 'Invalid card number committed');
		check((int) patientTestScalar($db, 'SELECT COUNT(*) - COUNT(DISTINCT card_no) FROM '.MAIN_DB_PREFIX.'patient_profile') === 0, 'Duplicate card numbers committed');
	});
} catch (Throwable $error) {
	$failed++;
	echo 'FAIL  '.$error->getMessage()."\n";
	foreach (glob($testDir.'/*.stderr') ?: array() as $log) {
		$content = trim(file_get_contents($log));
		if ($content !== '') {
			echo basename($log).': '.$content."\n";
		}
	}
} finally {
	foreach ($workers as $process) {
		$status = proc_get_status($process);
		if ($status['running']) {
			proc_terminate($process);
		}
		proc_close($process);
	}
	if ($db) {
		while ($db->transaction_opened > 0) {
			$db->rollback();
		}
		foreach (array_reverse($createdTables) as $table) {
			patientTestQuery($db, 'DROP TABLE IF EXISTS '.$table);
		}
		$db->close();
	}
	if (is_dir($testDir) && realpath(dirname($testDir)) === realpath($testBaseDir)
		&& preg_match('/^patient_numbering_[a-f0-9]{12}$/D', basename($testDir))) {
		foreach (glob($testDir.'/*') ?: array() as $file) {
			if (is_file($file)) {
				unlink($file);
			}
		}
		rmdir($testDir);
	}
}
echo "\nResult: $passed passed, $failed failed\n";
exit($failed ? 1 : 0);
