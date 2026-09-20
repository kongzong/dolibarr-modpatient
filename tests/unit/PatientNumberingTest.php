<?php
/* Copyright (C) 2026 modPatient contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

use PHPUnit\Framework\TestCase;

require_once dirname(dirname(__DIR__)).'/class/patientcardnumbering.class.php';

/** Scripted database: no concurrency here (tests/integration/numbering.php does that). */
class PatientNumberingTestDb
{
	public $type = 'mysqli';
	public $transaction_opened;
	public $queries = array();
	public $responses;
	public $rollbacks = 0;
	public $rollbackFails = false;
	public $closed = false;

	public function __construct($depth, $responses)
	{
		$this->transaction_opened = $depth;
		$this->responses = $responses;
	}
	public function prefix() { return 'isolated_'; }
	public function escape($value) { return addslashes($value); }
	public function query($sql)
	{
		$this->queries[] = $sql;
		if (!$this->responses) {
			throw new LogicException('Unexpected extra query');
		}
		$response = array_shift($this->responses);
		if ($response instanceof Throwable) {
			throw $response;
		}
		return is_array($response) ? (object) array('rows' => $response) : $response;
	}
	public function fetch_object($result) { return $result->rows ? (object) $result->rows[0] : false; }
	public function num_rows($result) { return count($result->rows); }
	public function free($result) {}
	public function rollback()
	{
		$this->rollbacks++;
		$this->transaction_opened--;
		return !$this->rollbackFails;
	}
	public function close() { $this->closed = true; $this->transaction_opened = 0; }
}

class PatientNumberingTest extends TestCase
{
	private function database($depth, $counter, $history)
	{
		$responses = array(
			$counter === null ? array() : array(array('last_value' => $counter)),
			array(array('maxseq' => $history)),
		);
		if ($depth > 0) {
			array_unshift($responses, true);
			$responses[] = true;
		}
		return new PatientNumberingTestDb($depth, $responses);
	}

	private function mustFail($db, $prefix = 'HZ-202609-')
	{
		$thrown = null;
		try {
			(new PatientCardNumbering($db))->nextReference($prefix);
		} catch (RuntimeException $error) {
			$thrown = $error;
		}
		$this->assertTrue($thrown instanceof RuntimeException, 'failure must not return a card number');
		return $thrown;
	}

	public function testPrefixForMonth()
	{
		$this->assertSame('HZ-202609-', PatientCardNumbering::prefixFor(mktime(12, 0, 0, 9, 20, 2026)));
		$this->assertRegExp('/^HZ-[0-9]{6}-$/', PatientCardNumbering::prefixFor());
	}

	public function testFirstOfMonthPreviewDoesNotReserve()
	{
		$db = $this->database(0, null, null);
		$this->assertSame('HZ-202609-0001', (new PatientCardNumbering($db))->nextReference('HZ-202609-'));
		$this->assertCount(2, $db->queries);
		foreach ($db->queries as $sql) {
			$this->assertSame(0, strpos($sql, 'SELECT '));
			$this->assertStringNotContainsString('FOR UPDATE', $sql);
		}
		$this->assertSame(0, $db->rollbacks);
	}

	public function testExistingHistorySeedsSequence()
	{
		$db = $this->database(1, '0', '41');
		$this->assertSame('HZ-202609-0042', (new PatientCardNumbering($db))->nextReference('HZ-202609-'));
		$this->assertStringContainsString('last_value = 42', $db->queries[3]);
		$this->assertStringContainsString('patient_profile', $db->queries[2], 'history comes from the profile table');
	}

	public function testCommittedCounterPreventsReuseAfterDeletion()
	{
		$db = $this->database(1, '57', '41');
		$this->assertSame('HZ-202609-0058', (new PatientCardNumbering($db))->nextReference('HZ-202609-'));
	}

	public function testReservationKeepsOuterTransactionAndLocks()
	{
		$db = $this->database(3, '8', '8');
		$this->assertSame('HZ-202609-0009', (new PatientCardNumbering($db))->nextReference('HZ-202609-'));
		$this->assertSame(3, $db->transaction_opened);
		$this->assertSame(0, $db->rollbacks);
		$this->assertCount(4, $db->queries);
		$this->assertStringContainsString('ON DUPLICATE KEY UPDATE', $db->queries[0]);
		$this->assertStringContainsString('FOR UPDATE', $db->queries[1]);
		$this->assertStringContainsString('FOR UPDATE', $db->queries[2]);
	}

	public function testSequenceBeyondFourDigitsAndNewMonth()
	{
		$db = $this->database(1, '9999', '9999');
		$this->assertSame('HZ-202609-10000', (new PatientCardNumbering($db))->nextReference('HZ-202609-'));
		$db = $this->database(1, '0', null);
		$this->assertSame('HZ-202610-0001', (new PatientCardNumbering($db))->nextReference('HZ-202610-'));
	}

	public function testEverySqlFailureRollsBackAllLevels()
	{
		for ($stage = 0; $stage < 4; $stage++) {
			$db = $this->database(3, '0', null);
			$db->responses[$stage] = false;
			$this->mustFail($db);
			$this->assertSame(0, $db->transaction_opened);
			$this->assertSame(3, $db->rollbacks);
			$this->assertCount($stage + 1, $db->queries, 'no queries after failure');
		}
	}

	public function testDriverExceptionDoesNotLeakSql()
	{
		$db = $this->database(1, '0', null);
		$db->responses[0] = new RuntimeException('private driver diagnostic');
		$error = $this->mustFail($db);
		$this->assertStringNotContainsString('private driver diagnostic', $error->getMessage());
		$this->assertSame(0, $db->transaction_opened);
	}

	public function testInvalidSequenceFailsClosed()
	{
		foreach (array('-1', '1.5', 'invalid', (string) PHP_INT_MAX) as $value) {
			$db = $this->database(1, '0', $value);
			$this->mustFail($db);
			$this->assertSame(0, $db->transaction_opened);
		}
	}

	public function testRollbackFailureDisconnects()
	{
		$db = $this->database(2, '0', null);
		$db->responses[0] = false;
		$db->rollbackFails = true;
		$this->mustFail($db);
		$this->assertTrue($db->closed);
	}

	public function testUnsupportedContextFailsBeforeQuerying()
	{
		$db = $this->database(0, null, null);
		$db->type = 'pgsql';
		$this->mustFail($db);
		$this->assertCount(0, $db->queries);
		$db = $this->database(0, null, null);
		$this->mustFail($db, "HZ-202609-'x");
		$this->assertCount(0, $db->queries);
		$db = $this->database(0, null, null);
		$this->mustFail($db, 'SH-20260913-');
		$this->assertCount(0, $db->queries);
	}
}
