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
 * \file       test/phpunit/Camt053ArchivedMonthTest.php
 * \ingroup    camt053readerandlink
 * \brief      PHPUnit tests for the month reconciliation screen
 */

use PHPUnit\Framework\TestCase;

require_once dirname(__FILE__) . '/../../class/Camt053ArchivedMonth.class.php';

/**
 * Class Camt053ArchivedMonthTest
 */
class Camt053ArchivedMonthTest extends TestCase
{
	/**
	 * A parsed file carrying the given statements.
	 *
	 * @param array<int, Camt053Statement> $statements Statements keyed by account id
	 * @return object
	 */
	private function file(array $statements): object
	{
		return new class ($statements) {
			/** @var array<int, Camt053Statement> */
			private $statements;

			/**
			 * @param array<int, Camt053Statement> $statements Statements keyed by account id
			 */
			public function __construct(array $statements)
			{
				$this->statements = $statements;
			}

			/**
			 * @return array<int, Camt053Statement>
			 */
			public function getStatementsByAccountId(): array
			{
				return $this->statements;
			}
		};
	}

	/**
	 * A statement of account 7 holding the given entries.
	 *
	 * @param Camt053Entry[] $entries Entries
	 * @return Camt053Statement
	 */
	private function statement(array $entries): Camt053Statement
	{
		$statement = new Camt053Statement('CH9300762011623852957', 7);
		foreach ($entries as $entry) {
			$statement->addEntry($entry);
		}

		return $statement;
	}

	/**
	 * Every daily file of the month ends up on the one screen, a movement
	 * reported again by another file is shown once, and another account's
	 * entries stay out.
	 *
	 * @return void
	 */
	public function testMergesTheEntriesOfEveryFileOfTheAccount(): void
	{
		$files = array(
			$this->file(array(7 => $this->statement(array(
				new Camt053Entry(100.0, '2026-10-01', 'Alpha', '', 'REF-1'),
			)))),
			$this->file(array(
				7 => $this->statement(array(
					new Camt053Entry(100.0, '2026-10-01', 'Alpha', '', 'REF-1'),
					new Camt053Entry(-50.0, '2026-10-02', 'Beta', '', 'REF-2'),
				)),
				8 => new Camt053Statement('CH5604835012345678009', 8),
			)),
			$this->file(array()),
		);

		$merged = Camt053ArchivedMonth::merge($files, 7);

		$this->assertSame(7, $merged->getAccountId());
		$this->assertSame('CH9300762011623852957', $merged->getIban());
		$this->assertSame(
			array('REF-1', 'REF-2'),
			array_map(function ($entry) {
				return $entry->getBankReference();
			}, $merged->getEntries())
		);
		foreach ($merged->getEntries() as $entry) {
			$this->assertTrue($entry->isFromFile());
		}
	}

	/**
	 * The monthly report sends the finance user to the screen where the month
	 * is reconciled, not to the plain list of bank lines.
	 *
	 * @return void
	 */
	public function testMonthlyReportLinksToTheMonthReconciliationScreen(): void
	{
		$source = (string) file_get_contents(dirname(__FILE__) . '/../../class/Camt053MonthlyCheck.class.php');

		$this->assertStringContainsString("/camt053readerandlink/month.php", $source);
		$this->assertStringNotContainsString('bankentries_list.php', $source);
	}
}
