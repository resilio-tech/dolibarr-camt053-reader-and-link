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
 * \file       test/phpunit/DatabaseBankStatementLoaderTest.php
 * \ingroup    camt053readerandlink
 * \brief      PHPUnit tests for the date window used when loading Dolibarr bank
 *             lines: it must be wide enough for the matcher's date tolerance.
 */

use PHPUnit\Framework\TestCase;

require_once dirname(__FILE__) . '/../../class/DatabaseBankStatementLoader.class.php';

if (!class_exists('Account')) {
	/**
	 * Stand-in for Dolibarr's Account. The loader instantiates it only to call
	 * get_url() per bank line, which the tests do not care about.
	 * Declared at file scope because PHP forbids nesting a class in a method.
	 */
	class Account
	{
		public function __construct($db = null)
		{
		}

		public function get_url($lineId)
		{
			return array();
		}
	}
}

if (!class_exists('AccountLine')) {
	/**
	 * Stand-in for Dolibarr's AccountLine. fetch() reads from the rows handed to
	 * LoaderRecordingDb, so the loader's row loop can be exercised.
	 */
	class AccountLine
	{
		/** @var array<int,array> Rows keyed by rowid, injected by the test */
		public static $rows = array();

		public $rowid;
		public $id;
		public $datev;
		public $amount;
		public $label;
		public $fk_account;
		public $rappro = 0;

		public function __construct($db = null)
		{
		}

		public function fetch($rowid, $ref = '', $num = '')
		{
			if (!isset(self::$rows[$rowid])) {
				return 0;
			}
			foreach (self::$rows[$rowid] as $property => $value) {
				$this->$property = $value;
			}
			$this->rowid = $rowid;
			$this->id = $rowid;
			return 1;
		}
	}
}

/**
 * Database mock recording the SQL it receives and returning the rows it is given.
 *
 * The loader runs two different queries while loading: the bank-line list and,
 * once per account, the bank account lookup. query() returns a distinct handle
 * for each so fetch_object() can answer the right rows.
 */
class LoaderRecordingDb
{
	/** @var array<int,string> Every SQL string passed to query() */
	public $queries = array();

	/** @var array<int,object> Bank-line rows left to return */
	private $lineRows;

	/**
	 * @param array<int,array> $rows Bank lines as [rowid => ['datev' => ..., ...]]
	 */
	public function __construct(array $rows = array())
	{
		AccountLine::$rows = $rows;
		$this->lineRows = array();
		foreach (array_keys($rows) as $rowid) {
			$this->lineRows[] = (object) array('rowid' => $rowid);
		}
	}

	public function query($sql)
	{
		$this->queries[] = $sql;

		return strpos($sql, 'bank AS b') !== false ? 'lines' : 'account';
	}

	public function fetch_object($resql)
	{
		if ($resql === 'lines') {
			return array_shift($this->lineRows) ?: false;
		}

		return (object) array('rowid' => 1, 'iban_prefix' => 'CH0509000000100123456');
	}

	public function escape($value)
	{
		return addslashes($value);
	}

	public function lasterror()
	{
		return '';
	}

	public function lastSql(): string
	{
		return end($this->queries) ?: '';
	}
}

/**
 * Class DatabaseBankStatementLoaderTest
 *
 * Each test defines Dolibarr globals, so they run in isolated processes.
 */
class DatabaseBankStatementLoaderTest extends TestCase
{
	/**
	 * Define the Dolibarr globals and stubs the loader relies on.
	 *
	 * @return void
	 */
	private function defineDolibarrStubs(): void
	{
		if (!defined('MAIN_DB_PREFIX')) {
			define('MAIN_DB_PREFIX', 'llx_');
		}
		if (!function_exists('getEntity')) {
			function getEntity($element = '', $shared = 1, $currentobject = null)
			{
				return '1';
			}
		}
		if (!function_exists('dol_escape_htmltag')) {
			function dol_escape_htmltag($stringtoescape, $keepb = 0, $keepn = 0, $noescapetags = '', $escapeonlyhtmltags = 0, $cleanalsojavascript = 0)
			{
				return htmlspecialchars((string) $stringtoescape, ENT_COMPAT);
			}
		}
	}

	/**
	 * Load one account's entries and return them.
	 *
	 * @param array  $rows      Bank lines as [rowid => ['datev' => ..., ...]]
	 * @param string $start     Period start (d/m/Y)
	 * @param string $end       Period end (d/m/Y)
	 * @param int    $dayMargin Day margin
	 * @return Camt053Entry[]
	 */
	private function loadEntries(array $rows, string $start, string $end, int $dayMargin): array
	{
		$loader = new DatabaseBankStatementLoader(new LoaderRecordingDb($rows));
		$statements = $loader->loadStatements($start, $end, null, $dayMargin);

		$this->assertArrayHasKey(1, $statements, 'Entries are grouped under their bank account');

		return $statements[1]->getEntries();
	}

	/**
	 * Without a margin, the query window is exactly the requested period.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 * @return void
	 */
	public function testWindowWithoutMarginIsThePeriod(): void
	{
		$this->defineDolibarrStubs();

		$db = new LoaderRecordingDb();
		$loader = new DatabaseBankStatementLoader($db);

		$loader->loadStatements('01/06/2024', '30/06/2024');

		$this->assertStringContainsString("b.datev >= DATE('2024-06-01')", $db->lastSql());
		$this->assertStringContainsString("b.datev <= DATE('2024-06-30')", $db->lastSql());
	}

	/**
	 * With a margin, the window is widened on both sides so a bank line dated
	 * just outside the period (salary paid on the 30th, booked on the 1st) is
	 * still reachable by the matcher's date tolerance.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 * @return void
	 */
	public function testWindowIsWidenedByMargin(): void
	{
		$this->defineDolibarrStubs();

		$db = new LoaderRecordingDb();
		$loader = new DatabaseBankStatementLoader($db);

		$loader->loadStatements('01/06/2024', '30/06/2024', null, 1);

		$this->assertStringContainsString("b.datev >= DATE('2024-05-31')", $db->lastSql());
		$this->assertStringContainsString("b.datev <= DATE('2024-07-01')", $db->lastSql());
	}

	/**
	 * The margin crosses year boundaries.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 * @return void
	 */
	public function testMarginCrossesYearBoundary(): void
	{
		$this->defineDolibarrStubs();

		$db = new LoaderRecordingDb();
		$loader = new DatabaseBankStatementLoader($db);

		$loader->loadStatements('01/01/2024', '31/12/2024', null, 2);

		$this->assertStringContainsString("b.datev >= DATE('2023-12-30')", $db->lastSql());
		$this->assertStringContainsString("b.datev <= DATE('2025-01-02')", $db->lastSql());
	}

	/**
	 * Lines inside the period are flagged in period, lines from the margin are
	 * not: that flag is what decides whether an unmatched entry is shown.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 * @return void
	 */
	public function testEntriesAreFlaggedInOrOutOfPeriod(): void
	{
		$this->defineDolibarrStubs();

		// datev is a timestamp, as AccountLine::fetch() leaves it in production.
		$rows = array(
			10 => array('datev' => strtotime('2024-05-31 12:00:00'), 'amount' => -100.0, 'label' => 'Before', 'fk_account' => 1),
			11 => array('datev' => strtotime('2024-06-01 12:00:00'), 'amount' => -200.0, 'label' => 'First day', 'fk_account' => 1),
			12 => array('datev' => strtotime('2024-06-15 12:00:00'), 'amount' => -300.0, 'label' => 'Middle', 'fk_account' => 1),
			13 => array('datev' => strtotime('2024-06-30 12:00:00'), 'amount' => -400.0, 'label' => 'Last day', 'fk_account' => 1),
			14 => array('datev' => strtotime('2024-07-01 12:00:00'), 'amount' => -500.0, 'label' => 'After', 'fk_account' => 1),
		);

		$entries = $this->loadEntries($rows, '01/06/2024', '30/06/2024', 1);
		$this->assertCount(5, $entries);

		$byDate = array();
		foreach ($entries as $entry) {
			$byDate[$entry->getValueDate()] = $entry->isInPeriod();
		}

		$this->assertFalse($byDate['2024-05-31'], 'The day before the period is margin');
		$this->assertTrue($byDate['2024-06-01'], 'The first day of the period is in period');
		$this->assertTrue($byDate['2024-06-15']);
		$this->assertTrue($byDate['2024-06-30'], 'The last day of the period is in period');
		$this->assertFalse($byDate['2024-07-01'], 'The day after the period is margin');
	}

	/**
	 * Without a margin, every loaded line is in period.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 * @return void
	 */
	public function testEveryEntryIsInPeriodWithoutMargin(): void
	{
		$this->defineDolibarrStubs();

		$rows = array(
			10 => array('datev' => strtotime('2024-06-01 12:00:00'), 'amount' => -100.0, 'label' => 'First day', 'fk_account' => 1),
			11 => array('datev' => strtotime('2024-06-30 12:00:00'), 'amount' => -200.0, 'label' => 'Last day', 'fk_account' => 1),
		);

		$entries = $this->loadEntries($rows, '01/06/2024', '30/06/2024', 0);

		$this->assertCount(2, $entries);
		foreach ($entries as $entry) {
			$this->assertTrue($entry->isInPeriod());
		}
	}
}
