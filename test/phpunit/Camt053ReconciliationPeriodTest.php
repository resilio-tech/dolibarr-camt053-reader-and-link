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
 * \file       test/phpunit/Camt053ReconciliationPeriodTest.php
 * \ingroup    camt053readerandlink
 * \brief      PHPUnit tests for the period a file is reconciled and archived under
 */

use PHPUnit\Framework\TestCase;

require_once dirname(__FILE__) . '/../../class/Camt053Entry.class.php';
require_once dirname(__FILE__) . '/../../class/Camt053Statement.class.php';
require_once dirname(__FILE__) . '/../../class/Camt053FileProcessor.class.php';
require_once dirname(__FILE__) . '/../../class/Camt053ReconciliationPeriod.class.php';

/**
 * Database mock resolving every IBAN to one bank account.
 */
class PeriodMockDb
{
	/** @var int Account id every IBAN resolves to */
	private $accountId;

	/**
	 * @param int $accountId Account id to answer with
	 */
	public function __construct(int $accountId = 4)
	{
		$this->accountId = $accountId;
	}

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
		$row->rowid = $this->accountId;
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
 * Class Camt053ReconciliationPeriodTest
 */
class Camt053ReconciliationPeriodTest extends TestCase
{
	/**
	 * Bring in what the IBAN lookup needs, without which every statement
	 * resolves to no account and the period is read from nothing.
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
	 * One <Stmt> block, with the entries and the declared period given.
	 *
	 * @param array       $entries     <Ntry> structures
	 * @param string|null $periodStart FrDtTm, or null to declare no period
	 * @param string|null $periodEnd   ToDtTm, or null to declare no period
	 * @return array
	 */
	private function statementBlock(array $entries, ?string $periodStart = null, ?string $periodEnd = null): array
	{
		$block = array(
			'Acct' => array('Id' => array('IBAN' => 'BE71096123456769')),
		);
		if ($periodStart !== null && $periodEnd !== null) {
			$block['FrToDt'] = array('FrDtTm' => $periodStart, 'ToDtTm' => $periodEnd);
		}
		if (!empty($entries)) {
			$block['Ntry'] = $entries;
		}

		return $block;
	}

	/**
	 * One booked credit.
	 *
	 * @param string $valueDate Value date, as the file spells it
	 * @param string $reference Bank reference
	 * @return array
	 */
	private function entry(string $valueDate, string $reference): array
	{
		return array(
			'Amt' => '100.00',
			'CdtDbtInd' => 'CRDT',
			'ValDt' => array('Dt' => $valueDate),
			'AcctSvcrRef' => $reference,
		);
	}

	/**
	 * The entries decide the period whenever the file carries some, whatever it
	 * declares: they are what is being reconciled.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 * @return void
	 */
	public function testThePeriodComesFromTheEntries(): void
	{
		$this->stubDolibarrContext();

		$structure = array(
			'BkToCstmrStmt' => array(
				'GrpHdr' => array('MsgId' => 'TEST', 'CreDtTm' => '2026-03-03T04:00:00'),
				'Stmt' => $this->statementBlock(
					array($this->entry('2026-01-11', 'REF-A'), $this->entry('2026-01-13', 'REF-B')),
					'2026-01-01T00:00:00',
					'2026-01-31T23:59:59'
				),
			),
		);

		$processor = new Camt053FileProcessor(new PeriodMockDb());
		$this->assertTrue($processor->parseStructure($structure));

		$this->assertSame(
			array('11/01/2026', '13/01/2026'),
			Camt053ReconciliationPeriod::resolve($processor)
		);
	}

	/**
	 * A statement carrying no entry is a valid statement, and the period it
	 * declares is the only thing that dates it. Read from the XML on purpose:
	 * the period was not parsed at all before this case existed.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 * @return void
	 */
	public function testAnEmptyStatementIsDatedByThePeriodItDeclares(): void
	{
		$this->stubDolibarrContext();

		$processor = new Camt053FileProcessor(new PeriodMockDb());
		$this->assertTrue($processor->parseFile(dirname(__FILE__) . '/fixtures/camt053_empty_statement.xml'));

		$byAccount = $processor->getStatementsByAccountId();
		$this->assertArrayHasKey(4, $byAccount);
		$this->assertSame(0, $byAccount[4]->getEntryCount(), 'The fixture carries no entry');

		// The file is created in March for a January period: the previous month of
		// the creation date would file it under February, on neither side of the
		// window the statement actually covers.
		$this->assertSame(
			array('05/01/2026', '20/01/2026'),
			Camt053ReconciliationPeriod::resolve($processor)
		);
	}

	/**
	 * The previous month of the creation date is a guess, so it is only reached
	 * when the file says nothing else.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 * @return void
	 */
	public function testTheCreationMonthIsOnlyUsedWhenNoPeriodIsDeclared(): void
	{
		$this->stubDolibarrContext();

		$structure = array(
			'BkToCstmrStmt' => array(
				'GrpHdr' => array('MsgId' => 'TEST', 'CreDtTm' => '2026-03-03T04:00:00'),
				'Stmt' => $this->statementBlock(array()),
			),
		);

		$processor = new Camt053FileProcessor(new PeriodMockDb());
		$this->assertTrue($processor->parseStructure($structure));

		$this->assertSame(
			array('01/02/2026', '28/02/2026'),
			Camt053ReconciliationPeriod::resolve($processor)
		);
	}

	/**
	 * Several blocks of the same account are merged, and the period must cover
	 * all of them: keeping the first one would leave the rest out of the window.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 * @return void
	 */
	public function testThePeriodOfSeveralBlocksCoversThemAll(): void
	{
		$this->stubDolibarrContext();

		$structure = array(
			'BkToCstmrStmt' => array(
				'GrpHdr' => array('MsgId' => 'TEST', 'CreDtTm' => '2026-03-03T04:00:00'),
				'Stmt' => array(
					$this->statementBlock(array(), '2026-01-16T00:00:00', '2026-01-31T23:59:59'),
					$this->statementBlock(array(), '2026-01-01T00:00:00', '2026-01-15T23:59:59'),
				),
			),
		);

		$processor = new Camt053FileProcessor(new PeriodMockDb());
		$this->assertTrue($processor->parseStructure($structure));

		$this->assertSame(
			array('01/01/2026', '31/01/2026'),
			Camt053ReconciliationPeriod::resolve($processor)
		);
	}
}
