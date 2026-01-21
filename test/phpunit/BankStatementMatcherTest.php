<?php
/* Copyright (C) 2024 Slordef
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
 * \file       test/phpunit/BankStatementMatcherTest.php
 * \ingroup    camt053readerandlink
 * \brief      PHPUnit tests for BankStatementMatcher class
 */

use PHPUnit\Framework\TestCase;

// Include required classes
require_once dirname(__FILE__) . '/../../class/Camt053Entry.class.php';
require_once dirname(__FILE__) . '/../../class/Camt053Statement.class.php';
require_once dirname(__FILE__) . '/../../class/BankStatementMatcher.class.php';

/**
 * Class BankStatementMatcherTest
 *
 * Unit tests for BankStatementMatcher class
 */
class BankStatementMatcherTest extends TestCase
{
	/**
	 * @var BankStatementMatcher
	 */
	private $matcher;

	/**
	 * Set up test environment
	 *
	 * @return void
	 */
	protected function setUp(): void
	{
		$this->matcher = new BankStatementMatcher(1); // 1 day tolerance
	}

	/**
	 * Create a mock bank line object
	 *
	 * @param int  $rowid  Row ID
	 * @param int  $rappro Reconciliation status (0 or 1)
	 * @return object
	 */
	private function createMockBankLine(int $rowid, int $rappro = 0): object
	{
		$bankLine = new stdClass();
		$bankLine->rowid = $rowid;
		$bankLine->id = $rowid;
		$bankLine->rappro = $rappro;
		return $bankLine;
	}

	/**
	 * Test exact match between file and database entry
	 *
	 * @return void
	 */
	public function testExactMatch(): void
	{
		// Create file statement with one entry
		$fileStatement = new Camt053Statement('BE71 0961 2345 6769', 1);
		$fileStatement->setIsFromFile(true);
		$fileEntry = $fileStatement->createEntry(1500.00, '2024-01-15', 'Invoice payment');

		// Create DB statement with matching entry
		$dbStatement = new Camt053Statement('BE71 0961 2345 6769', 1);
		$dbStatement->setIsFromFile(false);
		$dbEntry = $dbStatement->createEntry(1500.00, '2024-01-15', 'Invoice payment');
		$dbEntry->setBankLine($this->createMockBankLine(123));

		// Compare
		$results = $this->matcher->compare($fileStatement, $dbStatement);

		$this->assertCount(1, $results['linkeds']);
		$this->assertCount(0, $results['multiples']);
		$this->assertCount(0, $results['unlinkeds']);
		$this->assertCount(0, $results['already_linked']);
	}

	/**
	 * Test date tolerance match (1 day difference)
	 *
	 * @return void
	 */
	public function testDateToleranceMatch(): void
	{
		// Create file statement
		$fileStatement = new Camt053Statement('BE71 0961 2345 6769', 1);
		$fileStatement->setIsFromFile(true);
		$fileStatement->createEntry(1500.00, '2024-01-15', 'Invoice payment');

		// Create DB statement with 1 day difference
		$dbStatement = new Camt053Statement('BE71 0961 2345 6769', 1);
		$dbStatement->setIsFromFile(false);
		$dbEntry = $dbStatement->createEntry(1500.00, '2024-01-16', 'Invoice payment'); // +1 day
		$dbEntry->setBankLine($this->createMockBankLine(123));

		// Compare
		$results = $this->matcher->compare($fileStatement, $dbStatement);

		$this->assertCount(1, $results['linkeds']);
	}

	/**
	 * Test no match when amount differs
	 *
	 * @return void
	 */
	public function testNoMatchDifferentAmount(): void
	{
		// Create file statement
		$fileStatement = new Camt053Statement('BE71 0961 2345 6769', 1);
		$fileStatement->setIsFromFile(true);
		$fileStatement->createEntry(1500.00, '2024-01-15', 'Invoice payment');

		// Create DB statement with different amount
		$dbStatement = new Camt053Statement('BE71 0961 2345 6769', 1);
		$dbStatement->setIsFromFile(false);
		$dbEntry = $dbStatement->createEntry(1500.01, '2024-01-15', 'Invoice payment'); // Different amount
		$dbEntry->setBankLine($this->createMockBankLine(123));

		// Compare
		$results = $this->matcher->compare($fileStatement, $dbStatement);

		$this->assertCount(0, $results['linkeds']);
		$this->assertCount(2, $results['unlinkeds']); // Both unmatched
	}

	/**
	 * Test no match when date is outside tolerance
	 *
	 * @return void
	 */
	public function testNoMatchOutsideDateTolerance(): void
	{
		// Create file statement
		$fileStatement = new Camt053Statement('BE71 0961 2345 6769', 1);
		$fileStatement->setIsFromFile(true);
		$fileStatement->createEntry(1500.00, '2024-01-15', 'Invoice payment');

		// Create DB statement with 2 days difference (outside tolerance)
		$dbStatement = new Camt053Statement('BE71 0961 2345 6769', 1);
		$dbStatement->setIsFromFile(false);
		$dbEntry = $dbStatement->createEntry(1500.00, '2024-01-17', 'Invoice payment'); // +2 days
		$dbEntry->setBankLine($this->createMockBankLine(123));

		// Compare
		$results = $this->matcher->compare($fileStatement, $dbStatement);

		$this->assertCount(0, $results['linkeds']);
		$this->assertCount(2, $results['unlinkeds']);
	}

	/**
	 * Test multiple matches require user selection
	 *
	 * @return void
	 */
	public function testMultipleMatches(): void
	{
		// Create file statement with one entry
		$fileStatement = new Camt053Statement('BE71 0961 2345 6769', 1);
		$fileStatement->setIsFromFile(true);
		$fileStatement->createEntry(1500.00, '2024-01-15', 'Invoice payment');

		// Create DB statement with two matching entries
		$dbStatement = new Camt053Statement('BE71 0961 2345 6769', 1);
		$dbStatement->setIsFromFile(false);
		$dbEntry1 = $dbStatement->createEntry(1500.00, '2024-01-15', 'Invoice payment A');
		$dbEntry1->setBankLine($this->createMockBankLine(123));
		$dbEntry2 = $dbStatement->createEntry(1500.00, '2024-01-15', 'Invoice payment B');
		$dbEntry2->setBankLine($this->createMockBankLine(124));

		// Compare
		$results = $this->matcher->compare($fileStatement, $dbStatement);

		$this->assertCount(0, $results['linkeds']);
		$this->assertCount(1, $results['multiples']);
		$this->assertCount(2, $results['multiples'][0]['db']); // Two options
	}

	/**
	 * Test already reconciled entry
	 *
	 * @return void
	 */
	public function testAlreadyReconciled(): void
	{
		// Create file statement
		$fileStatement = new Camt053Statement('BE71 0961 2345 6769', 1);
		$fileStatement->setIsFromFile(true);
		$fileStatement->createEntry(1500.00, '2024-01-15', 'Invoice payment');

		// Create DB statement with already reconciled entry
		$dbStatement = new Camt053Statement('BE71 0961 2345 6769', 1);
		$dbStatement->setIsFromFile(false);
		$dbEntry = $dbStatement->createEntry(1500.00, '2024-01-15', 'Invoice payment');
		$dbEntry->setBankLine($this->createMockBankLine(123, 1)); // rappro = 1

		// Compare
		$results = $this->matcher->compare($fileStatement, $dbStatement);

		$this->assertCount(0, $results['linkeds']);
		$this->assertCount(1, $results['already_linked']);
	}

	/**
	 * Test unmatched file entry
	 *
	 * @return void
	 */
	public function testUnmatchedFileEntry(): void
	{
		// Create file statement with entry
		$fileStatement = new Camt053Statement('BE71 0961 2345 6769', 1);
		$fileStatement->setIsFromFile(true);
		$fileStatement->createEntry(1500.00, '2024-01-15', 'Invoice payment');

		// Create empty DB statement
		$dbStatement = new Camt053Statement('BE71 0961 2345 6769', 1);
		$dbStatement->setIsFromFile(false);

		// Compare
		$results = $this->matcher->compare($fileStatement, $dbStatement);

		$this->assertCount(0, $results['linkeds']);
		$this->assertCount(1, $results['unlinkeds']);
		$this->assertTrue($results['unlinkeds'][0]->isFromFile());
	}

	/**
	 * Test unmatched DB entry
	 *
	 * @return void
	 */
	public function testUnmatchedDbEntry(): void
	{
		// Create empty file statement
		$fileStatement = new Camt053Statement('BE71 0961 2345 6769', 1);
		$fileStatement->setIsFromFile(true);

		// Create DB statement with entry
		$dbStatement = new Camt053Statement('BE71 0961 2345 6769', 1);
		$dbStatement->setIsFromFile(false);
		$dbEntry = $dbStatement->createEntry(1500.00, '2024-01-15', 'Invoice payment');
		$dbEntry->setBankLine($this->createMockBankLine(123));

		// Compare
		$results = $this->matcher->compare($fileStatement, $dbStatement);

		$this->assertCount(0, $results['linkeds']);
		$this->assertCount(1, $results['unlinkeds']);
		$this->assertFalse($results['unlinkeds'][0]->isFromFile());
	}

	/**
	 * Test date tolerance setting
	 *
	 * @return void
	 */
	public function testDateToleranceSetting(): void
	{
		$matcher = new BankStatementMatcher(0); // No tolerance
		$this->assertEquals(0, $matcher->getDateTolerance());

		$matcher->setDateTolerance(5);
		$this->assertEquals(5, $matcher->getDateTolerance());

		$matcher->setDateTolerance(-1); // Should be clamped to 0
		$this->assertEquals(0, $matcher->getDateTolerance());
	}

	/**
	 * Test with zero date tolerance
	 *
	 * @return void
	 */
	public function testZeroDateTolerance(): void
	{
		$matcher = new BankStatementMatcher(0);

		// Create file statement
		$fileStatement = new Camt053Statement('BE71 0961 2345 6769', 1);
		$fileStatement->setIsFromFile(true);
		$fileStatement->createEntry(1500.00, '2024-01-15', 'Invoice payment');

		// Create DB statement with 1 day difference
		$dbStatement = new Camt053Statement('BE71 0961 2345 6769', 1);
		$dbStatement->setIsFromFile(false);
		$dbEntry = $dbStatement->createEntry(1500.00, '2024-01-16', 'Invoice payment');
		$dbEntry->setBankLine($this->createMockBankLine(123));

		// Compare
		$results = $matcher->compare($fileStatement, $dbStatement);

		// Should not match with 0 tolerance
		$this->assertCount(0, $results['linkeds']);
	}

	/**
	 * Test finding matches
	 *
	 * @return void
	 */
	public function testFindMatches(): void
	{
		$fileEntry = new Camt053Entry(1500.00, '2024-01-15', 'Test');

		$dbEntry1 = new Camt053Entry(1500.00, '2024-01-15', 'Match');
		$dbEntry1->setBankLine($this->createMockBankLine(1));

		$dbEntry2 = new Camt053Entry(1500.00, '2024-01-16', 'Match within tolerance');
		$dbEntry2->setBankLine($this->createMockBankLine(2));

		$dbEntry3 = new Camt053Entry(2000.00, '2024-01-15', 'No match - different amount');
		$dbEntry3->setBankLine($this->createMockBankLine(3));

		$dbEntries = array($dbEntry1, $dbEntry2, $dbEntry3);

		$matches = $this->matcher->findMatches($fileEntry, $dbEntries);

		$this->assertCount(2, $matches); // Two entries match
	}

	/**
	 * Test isReconciled method
	 *
	 * @return void
	 */
	public function testIsReconciled(): void
	{
		$entry1 = new Camt053Entry(100, '2024-01-01', 'Test');
		$entry1->setBankLine($this->createMockBankLine(1, 0));
		$this->assertFalse($this->matcher->isReconciled($entry1));

		$entry2 = new Camt053Entry(100, '2024-01-01', 'Test');
		$entry2->setBankLine($this->createMockBankLine(2, 1));
		$this->assertTrue($this->matcher->isReconciled($entry2));

		$entry3 = new Camt053Entry(100, '2024-01-01', 'Test');
		$this->assertFalse($this->matcher->isReconciled($entry3)); // No bank line
	}

	/**
	 * Test getStatistics method
	 *
	 * @return void
	 */
	public function testGetStatistics(): void
	{
		$results = array(
			'linkeds' => array(1, 2, 3),
			'multiples' => array(1),
			'unlinkeds' => array(1, 2),
			'already_linked' => array(1, 2, 3, 4)
		);

		$stats = $this->matcher->getStatistics($results);

		$this->assertEquals(3, $stats['linked_count']);
		$this->assertEquals(1, $stats['multiples_count']);
		$this->assertEquals(2, $stats['unlinked_count']);
		$this->assertEquals(4, $stats['already_linked_count']);
		$this->assertEquals(4, $stats['total_to_process']); // linked + multiples
	}

	/**
	 * Test complex scenario with mixed results
	 *
	 * @return void
	 */
	public function testComplexScenario(): void
	{
		// Create file statement with multiple entries
		$fileStatement = new Camt053Statement('BE71 0961 2345 6769', 1);
		$fileStatement->setIsFromFile(true);
		$fileStatement->createEntry(1500.00, '2024-01-15', 'Payment 1');
		$fileStatement->createEntry(2000.00, '2024-01-16', 'Payment 2');
		$fileStatement->createEntry(500.00, '2024-01-17', 'Payment 3');
		$fileStatement->createEntry(750.00, '2024-01-18', 'Payment 4');

		// Create DB statement with various scenarios
		$dbStatement = new Camt053Statement('BE71 0961 2345 6769', 1);
		$dbStatement->setIsFromFile(false);

		// Match for Payment 1
		$db1 = $dbStatement->createEntry(1500.00, '2024-01-15', 'DB Payment 1');
		$db1->setBankLine($this->createMockBankLine(1));

		// Multiple matches for Payment 2
		$db2a = $dbStatement->createEntry(2000.00, '2024-01-16', 'DB Payment 2A');
		$db2a->setBankLine($this->createMockBankLine(2));
		$db2b = $dbStatement->createEntry(2000.00, '2024-01-16', 'DB Payment 2B');
		$db2b->setBankLine($this->createMockBankLine(3));

		// Already reconciled match for Payment 3
		$db3 = $dbStatement->createEntry(500.00, '2024-01-17', 'DB Payment 3');
		$db3->setBankLine($this->createMockBankLine(4, 1));

		// No match for Payment 4
		// Extra unmatched DB entry
		$db5 = $dbStatement->createEntry(999.00, '2024-01-19', 'DB Extra');
		$db5->setBankLine($this->createMockBankLine(5));

		// Compare
		$results = $this->matcher->compare($fileStatement, $dbStatement);

		$this->assertCount(1, $results['linkeds']); // Payment 1
		$this->assertCount(1, $results['multiples']); // Payment 2
		$this->assertCount(1, $results['already_linked']); // Payment 3
		$this->assertCount(2, $results['unlinkeds']); // Payment 4 and DB Extra
	}

	/**
	 * Test negative amounts (debits) matching
	 *
	 * @return void
	 */
	public function testNegativeAmountMatch(): void
	{
		// Create file statement with debit
		$fileStatement = new Camt053Statement('BE71 0961 2345 6769', 1);
		$fileStatement->setIsFromFile(true);
		$fileStatement->createEntry(-500.00, '2024-01-15', 'Debit payment');

		// Create DB statement with matching debit
		$dbStatement = new Camt053Statement('BE71 0961 2345 6769', 1);
		$dbStatement->setIsFromFile(false);
		$dbEntry = $dbStatement->createEntry(-500.00, '2024-01-15', 'Debit payment');
		$dbEntry->setBankLine($this->createMockBankLine(123));

		// Compare
		$results = $this->matcher->compare($fileStatement, $dbStatement);

		$this->assertCount(1, $results['linkeds']);
	}

	/**
	 * Test multiple matches with some reconciled
	 *
	 * @return void
	 */
	public function testMultipleMatchesWithSomeReconciled(): void
	{
		// Create file statement
		$fileStatement = new Camt053Statement('BE71 0961 2345 6769', 1);
		$fileStatement->setIsFromFile(true);
		$fileStatement->createEntry(1500.00, '2024-01-15', 'Payment');

		// Create DB statement with multiple matches, one already reconciled
		$dbStatement = new Camt053Statement('BE71 0961 2345 6769', 1);
		$dbStatement->setIsFromFile(false);

		$db1 = $dbStatement->createEntry(1500.00, '2024-01-15', 'Payment A');
		$db1->setBankLine($this->createMockBankLine(1, 1)); // Reconciled

		$db2 = $dbStatement->createEntry(1500.00, '2024-01-15', 'Payment B');
		$db2->setBankLine($this->createMockBankLine(2, 0)); // Not reconciled

		// Compare
		$results = $this->matcher->compare($fileStatement, $dbStatement);

		// Should link to the one that's not reconciled
		$this->assertCount(1, $results['linkeds']);
		$this->assertEquals(2, $results['linkeds'][0]['db']->getBankLine()->rowid);
	}
}
