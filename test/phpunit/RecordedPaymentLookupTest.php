<?php
/* Copyright (C) 2026 Resilio SA
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
 * \file       test/phpunit/RecordedPaymentLookupTest.php
 * \ingroup    camt053readerandlink
 * \brief      PHPUnit tests for the lookup of a payment already recorded
 */

use PHPUnit\Framework\TestCase;

if (!defined('MAIN_DB_PREFIX')) {
	define('MAIN_DB_PREFIX', 'llx_');
}
if (!function_exists('getEntity')) {
	/**
	 * Stand-in for Dolibarr's entity filter.
	 *
	 * @param string $element       Element name
	 * @param int    $shared        Whether shared entities are included
	 * @param mixed  $currentobject Current object
	 * @return string
	 */
	function getEntity($element = '', $shared = 1, $currentobject = null)
	{
		return '2';
	}
}
if (!function_exists('dol_syslog')) {
	/**
	 * Stand-in for Dolibarr's logger.
	 *
	 * @param string $message Message
	 * @param int    $level   Level
	 * @return void
	 */
	function dol_syslog($message, $level = 0)
	{
	}
}

require_once dirname(__FILE__) . '/../../class/RecordedPaymentLookup.class.php';

/**
 * Database mock recording every query and answering with fixed rows.
 */
class LookupRecordingDb
{
	/** @var array<int, string> Queries received */
	public $queries = array();

	/** @var array<int, array<int, object>> Rows to answer, one list per query */
	private $rowsByQuery;

	/**
	 * @param array<int, array<int, object>> $rowsByQuery Rows per query, in order
	 */
	public function __construct(array $rowsByQuery = array())
	{
		$this->rowsByQuery = $rowsByQuery;
	}

	/**
	 * @param string $sql Query
	 * @return int Index of the query, used as its result handle
	 */
	public function query($sql)
	{
		$this->queries[] = $sql;

		return count($this->queries);
	}

	/**
	 * @param int $result Result handle
	 * @return object|null
	 */
	public function fetch_object($result)
	{
		$index = ((int) $result) - 1;
		if (!isset($this->rowsByQuery[$index]) || empty($this->rowsByQuery[$index])) {
			return null;
		}

		return array_shift($this->rowsByQuery[$index]);
	}

	/**
	 * @param string $value Value
	 * @return string
	 */
	public function escape($value)
	{
		return str_replace("'", "\\'", (string) $value);
	}

	/**
	 * @return string
	 */
	public function lasterror()
	{
		return '';
	}
}

/**
 * Class RecordedPaymentLookupTest
 */
class RecordedPaymentLookupTest extends TestCase
{
	/**
	 * One bank line row as the queries return it.
	 *
	 * @param float  $amount     Signed amount of the line
	 * @param bool   $reconciled Whether it already carries a statement
	 * @return object
	 */
	private function row(float $amount, bool $reconciled = false): object
	{
		$row = new stdClass();
		$row->line_id = 55;
		$row->line_date = '2026-01-12 00:00:00';
		$row->line_amount = $amount;
		$row->rappro = $reconciled ? 1 : 0;
		$row->num_releve = $reconciled ? '202601' : '';
		$row->document_id = 7;
		$row->document_ref = 'FA2602-0001';

		return $row;
	}

	/**
	 * Every spelling of the reference is looked for, because banks drop the
	 * separator of the mask.
	 *
	 * @return void
	 */
	public function testEverySpellingOfTheReferenceIsLookedFor(): void
	{
		$db = new LookupRecordingDb();
		$lookup = new RecordedPaymentLookup($db);
		$lookup->find(array('FA26020001'), 150.0, 3);

		$this->assertCount(1, $db->queries, 'A credit can only be a customer invoice payment');
		$this->assertStringContainsString("'FA26020001'", $db->queries[0]);
		$this->assertStringContainsString("'FA2602-0001'", $db->queries[0]);
	}

	/**
	 * SPEC section 2. The document, the account the payment left from and the
	 * statement account all have to hold, or the lookup reaches another entity.
	 *
	 * @return void
	 */
	public function testTheLookupStaysInsideTheEntityAndTheAccount(): void
	{
		$db = new LookupRecordingDb();
		$lookup = new RecordedPaymentLookup($db);
		$lookup->find(array('SI26020042'), -150.0, 3);

		$this->assertNotEmpty($db->queries);
		foreach ($db->queries as $sql) {
			$this->assertStringContainsString('entity IN (2)', $sql);
			$this->assertStringContainsString('ba.entity IN (2)', $sql);
			$this->assertStringContainsString('b.fk_account = 3', $sql);
		}
	}

	/**
	 * A credit is money in and a debit money out: looking for the wrong side
	 * would report the payment of an invoice that has nothing to do with it.
	 *
	 * @return void
	 */
	public function testOnlyTheDocumentsOfTheRightDirectionAreLookedIn(): void
	{
		$credit = new LookupRecordingDb();
		$lookup = new RecordedPaymentLookup($credit);
		$lookup->find(array('FA26020001'), 150.0, 3);
		$this->assertStringContainsString('llx_facture AS f', $credit->queries[0]);

		$debit = new LookupRecordingDb();
		$lookup = new RecordedPaymentLookup($debit);
		$lookup->find(array('SI26020042'), -150.0, 3);
		$this->assertCount(2, $debit->queries);
		$this->assertStringContainsString('llx_facture_fourn AS f', $debit->queries[0]);
		$this->assertStringContainsString('llx_expensereport AS er', $debit->queries[1]);
	}

	/**
	 * The amount is what identifies the movement. A payment of another amount on
	 * the same invoice is not the entry being looked at.
	 *
	 * @return void
	 */
	public function testALineOfAnotherAmountIsNotAMatch(): void
	{
		$db = new LookupRecordingDb(array(array($this->row(120.0))));
		$lookup = new RecordedPaymentLookup($db);

		$this->assertNull($lookup->find(array('FA26020001'), 150.0, 3));
	}

	/**
	 * The line found comes back with what the screen needs to report it.
	 *
	 * @return void
	 */
	public function testTheMatchingLineIsReturned(): void
	{
		$db = new LookupRecordingDb(array(array($this->row(150.0))));
		$lookup = new RecordedPaymentLookup($db);

		$found = $lookup->find(array('FA26020001'), 150.0, 3);

		$this->assertNotNull($found);
		$this->assertSame('customer_invoice', $found['type']);
		$this->assertSame(55, $found['line_id']);
		$this->assertSame('FA2602-0001', $found['document_ref']);
		$this->assertFalse($found['reconciled']);
	}

	/**
	 * A line already reconciled with another statement is still reported, so
	 * nobody goes looking for it, but it must be flagged: the quick action may
	 * not move it.
	 *
	 * @return void
	 */
	public function testALineReconciledElsewhereIsReportedAsSuch(): void
	{
		$db = new LookupRecordingDb(array(array($this->row(150.0, true))));
		$lookup = new RecordedPaymentLookup($db);

		$found = $lookup->find(array('FA26020001'), 150.0, 3);

		$this->assertNotNull($found);
		$this->assertTrue($found['reconciled']);
		$this->assertSame('202601', $found['num_releve']);
	}

	/**
	 * Nothing to go on means no query at all.
	 *
	 * @return void
	 */
	public function testAnEntryWithoutAReferenceQueriesNothing(): void
	{
		$db = new LookupRecordingDb();
		$lookup = new RecordedPaymentLookup($db);

		$this->assertNull($lookup->find(array(), 150.0, 3));
		$this->assertNull($lookup->find(array('FA26020001'), 150.0, 0));
		$this->assertSame(array(), $db->queries);
	}
}
