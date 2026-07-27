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
 * \file       test/phpunit/EntityScopeSqlTest.php
 * \ingroup    camt053readerandlink
 * \brief      PHPUnit tests verifying that IBAN and bank-line lookups are scoped
 *             to the current entity, so a statement imported for the wrong entity
 *             cannot reconcile foreign entries.
 */

use PHPUnit\Framework\TestCase;

require_once dirname(__FILE__) . '/../../class/Camt053Entry.class.php';
require_once dirname(__FILE__) . '/../../class/Camt053Statement.class.php';
require_once dirname(__FILE__) . '/../../class/Camt053FileProcessor.class.php';
require_once dirname(__FILE__) . '/../../class/DatabaseBankStatementLoader.class.php';
require_once dirname(__FILE__) . '/../../class/BankRelationshipLookup.class.php';

if (!class_exists('Account')) {
	/**
	 * Stand-in for Dolibarr's Account: the loader instantiates it before walking
	 * the rows, and these tests return none.
	 */
	class Account
	{
		public function __construct($db = null)
		{
		}
	}
}

/**
 * Database mock that records every SQL query it receives.
 */
class RecordingDb
{
	/** @var array<int,string> Every SQL string passed to query() */
	public $queries = array();

	/** @var mixed Value returned by query() */
	private $queryResult;

	/** @var mixed Value returned by fetch_object() */
	private $fetchResult;

	public function __construct($queryResult = true, $fetchResult = false)
	{
		$this->queryResult = $queryResult;
		$this->fetchResult = $fetchResult;
	}

	public function query($sql)
	{
		$this->queries[] = $sql;
		return $this->queryResult;
	}

	public function fetch_object($resql)
	{
		return $this->fetchResult;
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
 * Class EntityScopeSqlTest
 *
 * These tests define MAIN_DB_PREFIX and a getEntity() stub, so each runs in an
 * isolated process to avoid leaking that global state into the rest of the suite.
 */
class EntityScopeSqlTest extends TestCase
{
	/**
	 * Define the Dolibarr globals the lookups rely on (isolated per process).
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
				return $shared ? '1,2' : '1';
			}
		}
	}

	/**
	 * The file processor's IBAN lookup must be scoped to the current entity.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 * @return void
	 */
	public function testFileProcessorIbanLookupIsEntityScoped(): void
	{
		$this->defineDolibarrStubs();

		$db = new RecordingDb(true, false);
		$processor = new Camt053FileProcessor($db);

		$method = new ReflectionMethod(Camt053FileProcessor::class, 'findAccountByIban');
		$method->setAccessible(true);
		$result = $method->invoke($processor, 'CH0509000000100123456');

		$this->assertNull($result, 'No account row -> null');
		$this->assertNotEmpty($db->queries, 'The lookup must reach the database');
		$this->assertStringContainsString('entity IN (1)', $db->lastSql());
	}

	/**
	 * getAccountIdByIban must be scoped to the current entity.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 * @return void
	 */
	public function testLoaderAccountIdByIbanIsEntityScoped(): void
	{
		$this->defineDolibarrStubs();

		$db = new RecordingDb(true, false);
		$loader = new DatabaseBankStatementLoader($db);

		$result = $loader->getAccountIdByIban('CH0509000000100123456');

		$this->assertNull($result);
		$this->assertStringContainsString('entity IN (1)', $db->lastSql());
	}

	/**
	 * The bank-line load itself must be scoped: it is the query that decides
	 * which lines can be reconciled at all.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 * @return void
	 */
	public function testLoaderStatementQueryIsEntityScoped(): void
	{
		$this->defineDolibarrStubs();

		$db = new RecordingDb(true, false);
		$loader = new DatabaseBankStatementLoader($db);

		$loader->loadStatements('01/06/2024', '30/06/2024');

		$this->assertNotEmpty($db->queries);
		$this->assertStringContainsString('bank_account', $db->queries[0]);
		$this->assertStringContainsString('entity IN (1)', $db->queries[0]);
	}

	/**
	 * The bank line of a relationship lookup is restricted to the current entity,
	 * while the invoices keep the multicompany sharing configured for them.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 * @return void
	 */
	public function testRelationshipLookupsAreEntityScoped(): void
	{
		$this->defineDolibarrStubs();

		$db = new RecordingDb(true, false);
		$lookup = new BankRelationshipLookup($db);

		$this->assertNull($lookup->getRelation(100));
		$this->assertCount(3, $db->queries, 'customer invoice, supplier invoice, bank line');
		$this->assertStringContainsString('f.entity IN (1,2)', $db->queries[0]);
		$this->assertStringContainsString('f.entity IN (1,2)', $db->queries[1]);
		$this->assertStringContainsString('ba.entity IN (1)', $db->queries[2]);
	}

	/**
	 * getDbBank must be scoped to the current entity.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 * @return void
	 */
	public function testLoaderGetDbBankIsEntityScoped(): void
	{
		$this->defineDolibarrStubs();

		$account = (object) array('rowid' => 5, 'iban_prefix' => 'CH0509000000100123456');
		$db = new RecordingDb(true, $account);
		$loader = new DatabaseBankStatementLoader($db);

		$bank = $loader->getDbBank(5);

		$this->assertSame(5, (int) $bank->rowid);
		$this->assertStringContainsString('entity IN (1)', $db->lastSql());
	}
}
