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
 * \file    htdocs/custom/patient/tests/unit/PatientSelfTest.php
 * \ingroup patient
 * \brief   Self-query tests that need no DB: pure HMAC helpers plus structural
 *          regression guards (the `token` -> `sig` rename and the rate limiter).
 */

use PHPUnit\Framework\TestCase;

require_once dirname(dirname(__DIR__)).'/lib/patient_self.lib.php';

/**
 * Class PatientSelfTest
 */
class PatientSelfTest extends TestCase
{
	/**
	 * Token must be exactly HMAC-SHA256 over "pid|exp", numeric inputs normalised.
	 */
	public function testTokenIsHmacOfPidPipeExp()
	{
		$this->assertSame(hash_hmac('sha256', '7|123', 'k'), patientSelfMakeToken(7, 123, 'k'));
		$this->assertSame(patientSelfMakeToken(7, 123, 'k'), patientSelfMakeToken('7', '123', 'k'), 'numeric strings normalise to the same token');
	}

	public function testTokenDivergesOnAnyInputChange()
	{
		$base = patientSelfMakeToken(1, 1000, 'secret');
		$this->assertNotEquals($base, patientSelfMakeToken(2, 1000, 'secret'), 'different pid');
		$this->assertNotEquals($base, patientSelfMakeToken(1, 1001, 'secret'), 'different exp');
		$this->assertNotEquals($base, patientSelfMakeToken(1, 1000, 'other'), 'different secret');
	}

	/**
	 * self.php regression guards: must stay a NOLOGIN page reading `sig`
	 * (Dolibarr silently unsets a non-session `token` GET param), and must
	 * keep the rate limiter with its SELF_DENIED audit action.
	 */
	public function testSelfPageStructure()
	{
		$c = file_get_contents(dirname(dirname(__DIR__)).'/self.php');
		$this->assertStringContainsString("define('NOLOGIN', 1)", $c);
		$this->assertStringContainsString("GETPOST('sig', 'alpha')", $c);
		$this->assertStringNotContainsString("GETPOST('token'", $c, 'Dolibarr reserves `token` and unsets it in main.inc.php');
		$this->assertStringContainsString('patientSelfVerify', $c);
		$this->assertStringContainsString("action = 'SELF_DENIED'", $c, 'rate limiter counts failed attempts from audit');
		$this->assertStringContainsString("action = 'SELF_VIEW'", $c, 'rate limiter caps successful views');
		$this->assertStringContainsString('patientSelfAllergies', $c);
		$this->assertStringContainsString('patientSelfCards', $c);
	}

	/**
	 * lib regression guards: URL carries `sig`, comparison is timing-safe,
	 * allergy/card read paths keep their status filters.
	 */
	public function testLibStructure()
	{
		$c = file_get_contents(dirname(dirname(__DIR__)).'/lib/patient_self.lib.php');
		$this->assertStringContainsString('hash_equals', $c, 'timing-safe comparison');
		$this->assertRegexp("/patientSelfMakeUrl.*?&sig='\\./s", $c, 'URL builds a sig parameter');
		$this->assertStringContainsString('hash_hmac', $c);
		$this->assertStringContainsString('function patientSelfAllergies', $c);
		$this->assertStringContainsString('function patientSelfCards', $c);
		$this->assertStringContainsString("c.status <> 3", $c, 'refunded cards never show on the self page');
	}
}
