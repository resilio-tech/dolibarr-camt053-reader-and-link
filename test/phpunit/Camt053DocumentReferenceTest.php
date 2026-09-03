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
 * \file       test/phpunit/Camt053DocumentReferenceTest.php
 * \ingroup    camt053readerandlink
 * \brief      PHPUnit tests for the document references carried by an entry
 */

use PHPUnit\Framework\TestCase;

require_once dirname(__FILE__) . '/../../class/Camt053DocumentReference.class.php';

/**
 * Class Camt053DocumentReferenceTest
 */
class Camt053DocumentReferenceTest extends TestCase
{
	/**
	 * The reference sits in a sentence written by whoever made the transfer.
	 *
	 * @return void
	 */
	public function testAReferenceIsReadOutOfSurroundingText(): void
	{
		$this->assertSame(
			array('FA26020001'),
			Camt053DocumentReference::extract('Paiement facture FA2602-0001 merci')
		);
	}

	/**
	 * Banks drop the separator of the numbering mask, and the same document must
	 * still be recognised.
	 *
	 * @return void
	 */
	public function testTheSeparatorOfTheMaskIsOptional(): void
	{
		$this->assertSame(
			array('FA26020001'),
			Camt053DocumentReference::extract('FA26020001')
		);
		$this->assertTrue(Camt053DocumentReference::isNamedIn('FA2602-0001', 'Virement FA26020001'));
	}

	/**
	 * One transfer settling several invoices names them all, separated by
	 * whatever the payer typed.
	 *
	 * @return void
	 */
	public function testSeveralReferencesAreAllRead(): void
	{
		$this->assertSame(
			array('FA26020001', 'FA26020002', 'SI26020042'),
			Camt053DocumentReference::extract('FA2602-0001,FA2602-0002; SI2602-0042')
		);
	}

	/**
	 * The name and the info of an entry are joined with <br />, and a tag glued
	 * to a reference used to hide it.
	 *
	 * @return void
	 */
	public function testMarkupBetweenFieldsDoesNotHideAReference(): void
	{
		$this->assertSame(
			array('FA26020001', 'SI26020042'),
			Camt053DocumentReference::extract('Invoice FA2602-0001<br />ACME Corporation', 'REFERENCES<br/>SI2602-0042')
		);
	}

	/**
	 * The same reference written in both fields is one document.
	 *
	 * @return void
	 */
	public function testTheSameReferenceIsListedOnce(): void
	{
		$this->assertSame(
			array('FA26020001'),
			Camt053DocumentReference::extract('FA2602-0001', 'Paiement de FA26020001')
		);
	}

	/**
	 * A long digit run is not a reference: an ESR or a structured creditor
	 * reference would otherwise be read as one and rank the wrong candidate.
	 *
	 * @return void
	 */
	public function testALongDigitRunIsNotAReference(): void
	{
		$this->assertSame(array(), Camt053DocumentReference::extract('RF18539007547034123456'));
		$this->assertSame(array(), Camt053DocumentReference::extract('000000000000000000000012345'));
		$this->assertSame(array(), Camt053DocumentReference::extract('Salary payment january'));
	}

	/**
	 * The reference ranks a candidate the amount already matched.
	 *
	 * @return void
	 */
	public function testTheNamedCandidateIsPicked(): void
	{
		$references = Camt053DocumentReference::extract('Paiement FA2602-0002');

		$this->assertSame(
			'82',
			Camt053DocumentReference::pickNamed($references, array(81 => 'FA2602-0001', 82 => 'FA2602-0002'))
		);
	}

	/**
	 * SPEC section 3: the reference never bypasses the manual choice. An entry
	 * naming two candidates, or naming none of them, stays ambiguous.
	 *
	 * @return void
	 */
	public function testAnEntryNamingSeveralCandidatesStaysAmbiguous(): void
	{
		$references = Camt053DocumentReference::extract('Paiement FA2602-0001 et FA2602-0002');

		$this->assertSame(
			'',
			Camt053DocumentReference::pickNamed($references, array(81 => 'FA2602-0001', 82 => 'FA2602-0002'))
		);

		$this->assertSame(
			'',
			Camt053DocumentReference::pickNamed($references, array(81 => 'FA2602-0009', 82 => 'FA2602-0010'))
		);

		$this->assertSame('', Camt053DocumentReference::pickNamed(array(), array(81 => 'FA2602-0001')));
	}

	/**
	 * A candidate with no document behind it cannot be named by anything.
	 *
	 * @return void
	 */
	public function testACandidateWithoutAReferenceIsNeverPicked(): void
	{
		$references = Camt053DocumentReference::extract('Paiement FA2602-0001');

		$this->assertSame('', Camt053DocumentReference::pickNamed($references, array(81 => '')));
	}

	/**
	 * The reading is only useful if the screen asks for it: the dropdown used to
	 * be rendered with no selection at all.
	 *
	 * @return void
	 */
	public function testTheDropdownIsRenderedWithThePreselection(): void
	{
		$source = file_get_contents(dirname(__FILE__) . '/../../lib/camt053readerandlink.results.lib.php');
		$this->assertNotFalse($source);

		$this->assertStringContainsString('camt053_candidate_named_by_entry(', (string) $source);
		$this->assertStringContainsString('selectMassAction($preselected', (string) $source);
	}
}
