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
 * \file       test/phpunit/EntryDetailsShapeTest.php
 * \ingroup    camt053readerandlink
 * \brief      PHPUnit tests reading the detail of an entry whatever shape the
 *             bank writes it in
 */

use PHPUnit\Framework\TestCase;

require_once dirname(__FILE__) . '/../../class/Camt053Entry.class.php';
require_once dirname(__FILE__) . '/../../class/Camt053Statement.class.php';
require_once dirname(__FILE__) . '/../../class/Camt053FileProcessor.class.php';

/**
 * Database mock resolving the statement IBAN to one bank account.
 */
class DetailsMockDb
{
	/**
	 * @param string $sql Query
	 * @return bool
	 */
	public function query($sql)
	{
		return true;
	}

	/**
	 * @param mixed $result Query result
	 * @return stdClass
	 */
	public function fetch_object($result)
	{
		$row = new stdClass();
		$row->rowid = 9;
		return $row;
	}

	/**
	 * @param string $value Value to escape
	 * @return string
	 */
	public function escape($value)
	{
		return addslashes($value);
	}
}

/**
 * Class EntryDetailsShapeTest
 *
 * The counterparty, the remittance info and the name all live inside <TxDtls>.
 * Both <NtryDtls> and <TxDtls> repeat in real files, and a repeated tag becomes
 * a numerically indexed list: reading straight through the keys then finds
 * nothing, and the entry arrives with no name and no suggestion.
 */
class EntryDetailsShapeTest extends TestCase
{
	/**
	 * Bring in what the IBAN lookup needs.
	 *
	 * @return void
	 */
	private function stubDolibarrContext(): void
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
	 * Parse a fixture and return the entries of the resolved account.
	 *
	 * @param string $fixture File name under fixtures/
	 * @return Camt053Entry[]
	 */
	private function entriesOf(string $fixture): array
	{
		$processor = new Camt053FileProcessor(new DetailsMockDb());
		$this->assertTrue($processor->parseFile(dirname(__FILE__) . '/fixtures/' . $fixture));

		$byAccount = $processor->getStatementsByAccountId();
		$this->assertArrayHasKey(9, $byAccount);

		return $byAccount[9]->getEntries();
	}

	/**
	 * A batch whose detailed amounts do not reconstruct the group total is kept
	 * whole on purpose. It still carries a detail, and that detail is the only
	 * place the counterparty is written: without it the entry showed up bare,
	 * with no internal transfer and no payment suggestion.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 * @return void
	 */
	public function testAnEntryKeptWholeStillReadsItsDetail(): void
	{
		$this->stubDolibarrContext();

		$entries = $this->entriesOf('camt053_batch_kept_whole.xml');

		$this->assertCount(1, $entries, 'The partial detail must not be split');
		$entry = $entries[0];

		$this->assertSame(-15000.0, $entry->getAmount(), 'The group total is what was booked');
		$this->assertSame('CH5604835012345678009', $entry->getCounterpartyIban());
		$this->assertStringContainsString('Transfer to savings', $entry->getName());
		$this->assertStringContainsString('Own Savings Account', $entry->getName());
		$this->assertStringContainsString('First line of the batch', $entry->getInfo());
	}

	/**
	 * The same two transactions written as two <NtryDtls> blocks instead of two
	 * <TxDtls> in one block. The split has to see both, otherwise the entry is
	 * kept whole and matches no single Dolibarr line.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 * @return void
	 */
	public function testTransactionsSpreadOverSeveralDetailBlocksAreAllRead(): void
	{
		$this->stubDolibarrContext();

		$entries = $this->entriesOf('camt053_repeated_ntrydtls.xml');

		$this->assertCount(2, $entries, 'Each transaction becomes its own entry');

		$byIban = array();
		foreach ($entries as $entry) {
			$byIban[$entry->getCounterpartyIban()] = $entry;
		}

		$this->assertArrayHasKey('CH5604835012345678010', $byIban);
		$this->assertArrayHasKey('CH5604835012345678011', $byIban);
		$this->assertSame(-1800.0, $byIban['CH5604835012345678010']->getAmount());
		$this->assertSame(-1200.0, $byIban['CH5604835012345678011']->getAmount());
		$this->assertSame('E2E-A', $byIban['CH5604835012345678010']->getBankReference());
		$this->assertSame('E2E-B', $byIban['CH5604835012345678011']->getBankReference());
	}

	/**
	 * The entry-level counterparty of a single-transaction entry, which is the
	 * shape every fixture used before this one, must keep working.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 * @return void
	 */
	public function testASingleTransactionEntryIsUnchanged(): void
	{
		$this->stubDolibarrContext();

		$entries = $this->entriesOf('camt053_payment_suggestions.xml');
		$this->assertNotEmpty($entries);

		$withCounterparty = 0;
		foreach ($entries as $entry) {
			if ($entry->getCounterpartyIban() !== '') {
				$withCounterparty++;
			}
		}

		$this->assertGreaterThan(0, $withCounterparty, 'No counterparty read at all');
	}
}
