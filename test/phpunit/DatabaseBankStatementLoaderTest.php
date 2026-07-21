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
	 * get_url() per row; the tests below return no row, so it stays empty.
	 * Declared at file scope because PHP forbids nesting a class in a method.
	 */
	class Account
	{
		public function __construct($db = null)
		{
		}
	}
}

/**
 * Database mock recording the SQL it receives and returning no row.
 */
class LoaderRecordingDb
{
	/** @var array<int,string> Every SQL string passed to query() */
	public $queries = array();

	public function query($sql)
	{
		$this->queries[] = $sql;
		return true;
	}

	public function fetch_object($resql)
	{
		return false;
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
}
