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
 * \file       test/phpunit/Camt053RemoteFileTest.php
 * \ingroup    camt053readerandlink
 * \brief      PHPUnit tests for the files the scheduled job takes out of the
 *             PostFinance directory.
 */

use PHPUnit\Framework\TestCase;

require_once dirname(__FILE__) . '/../../class/Camt053RemoteFile.class.php';

/**
 * Class Camt053RemoteFileTest
 */
class Camt053RemoteFileTest extends TestCase
{
	/**
	 * A directory as PostFinance fills it, in listing order.
	 *
	 * @return string[]
	 */
	private function listing(): array
	{
		return array(
			'REP_P_CH9300762011623852957_1149688958_0_2026091001312741.pdf',
			'REP_P_CH9300762011623852957_1149688958_0_2026090901532459.pdf',
			'camt.053_P_CH9300762011623852957_1120407549_0_2026091002212738.xml',
			'camt.053_P_CH9300762011623852957_1120407549_0_2026090902052603.xml',
			'camt.054-Credit_P_CH9300762011623852957_1149014137_0_2026090902101334.xml',
			'camt.054-Debit_P_CH9300762011623852957_1149014138_0_2026091514102934.xml',
			'camt.054_P_CH9300762011623852957_1120407572_0_2026090923451379.xml',
			'readme.txt',
		);
	}

	/**
	 * The name carries the account and the delivery day.
	 *
	 * @return void
	 */
	public function testAStatementNameCarriesItsAccountAndDay(): void
	{
		$file = Camt053RemoteFile::classify('camt.053_P_CH9300762011623852957_1120407549_0_2026090902052603.xml');

		$this->assertSame(Camt053RemoteFile::STATEMENT, $file['kind']);
		$this->assertSame('CH9300762011623852957', $file['iban']);
		$this->assertSame('2026-09-09', $file['day']);
	}

	/**
	 * The PDF is recognised as such, with the same account and day as its statement.
	 *
	 * @return void
	 */
	public function testAPdfNameCarriesItsAccountAndDay(): void
	{
		$file = Camt053RemoteFile::classify('REP_P_CH9300762011623852957_1149688958_0_2026090901532459.pdf');

		$this->assertSame(Camt053RemoteFile::PDF, $file['kind']);
		$this->assertSame('CH9300762011623852957', $file['iban']);
		$this->assertSame('2026-09-09', $file['day']);
	}

	/**
	 * The camt.054 notifications and anything else in the directory are left alone.
	 *
	 * @return void
	 */
	public function testEverythingElseIsLeftAlone(): void
	{
		$this->assertNull(Camt053RemoteFile::classify('camt.054-Credit_P_CH9300762011623852957_1149014137_0_2026090902101334.xml'));
		$this->assertNull(Camt053RemoteFile::classify('camt.054_P_CH9300762011623852957_1120407572_0_2026090923451379.xml'));
		$this->assertNull(Camt053RemoteFile::classify('camt.053_P_CH9300762011623852957_1120407549_0_2026090902052603.pdf'));
		$this->assertNull(Camt053RemoteFile::classify('REP_P_CH9300762011623852957_1149688958_0_2026090901532459.xml'));
		$this->assertNull(Camt053RemoteFile::classify('readme.txt'));
	}

	/**
	 * Statements are taken oldest first, so a missed day is caught up in order.
	 *
	 * @return void
	 */
	public function testSelectionKeepsTheDeliveryOrder(): void
	{
		$selected = Camt053RemoteFile::select($this->listing());

		$this->assertSame(array(
			'camt.053_P_CH9300762011623852957_1120407549_0_2026090902052603.xml',
			'camt.053_P_CH9300762011623852957_1120407549_0_2026091002212738.xml',
		), $selected['statements']);
		$this->assertSame(array(
			'REP_P_CH9300762011623852957_1149688958_0_2026090901532459.pdf',
			'REP_P_CH9300762011623852957_1149688958_0_2026091001312741.pdf',
		), $selected['pdfs']);
	}

	/**
	 * A PDF goes with the statement of the same account and day, and with
	 * nothing when that statement is not there yet.
	 *
	 * @return void
	 */
	public function testAPdfIsPairedWithTheStatementOfItsDay(): void
	{
		$statements = Camt053RemoteFile::select($this->listing())['statements'];

		$this->assertSame(
			'camt.053_P_CH9300762011623852957_1120407549_0_2026091002212738.xml',
			Camt053RemoteFile::statementOf('REP_P_CH9300762011623852957_1149688958_0_2026091001312741.pdf', $statements)
		);
		$this->assertNull(Camt053RemoteFile::statementOf('REP_P_CH9300762011623852957_1149688958_0_2026091101511209.pdf', $statements));
		$this->assertNull(Camt053RemoteFile::statementOf('REP_P_CH5604835012345678009_1149688958_0_2026091001312741.pdf', $statements));
	}
}
