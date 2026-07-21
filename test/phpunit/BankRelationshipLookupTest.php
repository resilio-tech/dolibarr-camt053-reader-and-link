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
 * \file       test/phpunit/BankRelationshipLookupTest.php
 * \ingroup    camt053readerandlink
 * \brief      PHPUnit tests for BankRelationshipLookup::getRelation, the data the
 *             multi-match reconciliation dropdown uses to show the invoice ref.
 */

use PHPUnit\Framework\TestCase;

require_once dirname(__FILE__) . '/../../class/BankRelationshipLookup.class.php';

/**
 * Database mock returning a preset row for a given query, then nothing.
 */
class RelationMockDb
{
	/** @var array<int,object|false> Queue of rows returned by successive queries */
	private $rows;

	/** @var int Index of the next row to return */
	private $cursor = 0;

	/**
	 * @param array<int,object|false> $rows One entry per query() call
	 */
	public function __construct(array $rows)
	{
		$this->rows = $rows;
	}

	public function query($sql)
	{
		return true;
	}

	public function fetch_object($resql)
	{
		$row = $this->rows[$this->cursor] ?? false;
		$this->cursor++;
		return $row;
	}

	public function escape($value)
	{
		return addslashes($value);
	}
}

/**
 * Class BankRelationshipLookupTest
 *
 * Defines MAIN_DB_PREFIX, so it runs in an isolated process.
 */
class BankRelationshipLookupTest extends TestCase
{
	/**
	 * @return void
	 */
	private function definePrefix(): void
	{
		if (!defined('MAIN_DB_PREFIX')) {
			define('MAIN_DB_PREFIX', 'llx_');
		}
		// The lookups scope every query to the current entity.
		if (!function_exists('getEntity')) {
			function getEntity($element = '', $shared = 1, $currentobject = null)
			{
				return '1';
			}
		}
	}

	/**
	 * A bank line tied to a customer invoice exposes the invoice reference and
	 * third party, which the reconciliation dropdown shows to disambiguate.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 * @return void
	 */
	public function testGetRelationReturnsCustomerInvoiceReference(): void
	{
		$this->definePrefix();

		$invoice = (object) array('rowid' => 42, 'ref' => 'FA2026-0042', 'nom' => 'ACME SA');
		// First query (customer invoice) returns the invoice row.
		$db = new RelationMockDb(array($invoice));
		$lookup = new BankRelationshipLookup($db);

		$relation = $lookup->getRelation(100);

		$this->assertNotNull($relation);
		$this->assertSame('customer_invoice', $relation['type']);
		$this->assertSame('FA2026-0042', $relation['ref']);
		$this->assertSame('ACME SA', $relation['label']);
	}

	/**
	 * A bank line tied to a supplier invoice exposes the supplier invoice ref.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 * @return void
	 */
	public function testGetRelationReturnsSupplierInvoiceReference(): void
	{
		$this->definePrefix();

		$supplier = (object) array('rowid' => 7, 'ref' => 'FF2026-0007', 'nom' => 'Supplier GmbH');
		// First query (customer invoice) misses, second (supplier invoice) hits.
		$db = new RelationMockDb(array(false, $supplier));
		$lookup = new BankRelationshipLookup($db);

		$relation = $lookup->getRelation(101);

		$this->assertNotNull($relation);
		$this->assertSame('supplier_invoice', $relation['type']);
		$this->assertSame('FF2026-0007', $relation['ref']);
	}

	/**
	 * A bank line with no linked invoice falls back to the bank line label with
	 * an empty reference.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 * @return void
	 */
	public function testGetRelationFallsBackToBankLine(): void
	{
		$this->definePrefix();

		$line = (object) array('rowid' => 55, 'label' => 'SEPA credit');
		// Customer + supplier miss, bank line hits.
		$db = new RelationMockDb(array(false, false, $line));
		$lookup = new BankRelationshipLookup($db);

		$relation = $lookup->getRelation(102);

		$this->assertNotNull($relation);
		$this->assertSame('bank_line', $relation['type']);
		$this->assertSame('', $relation['ref']);
		$this->assertSame('SEPA credit', $relation['label']);
	}
}
