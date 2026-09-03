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
 * \file       test/phpunit/Camt053ReviewAlertTest.php
 * \ingroup    camt053readerandlink
 * \brief      PHPUnit tests for the alert about what a run could not settle
 */

use PHPUnit\Framework\TestCase;

require_once dirname(__FILE__) . '/../../class/Camt053ReviewAlert.class.php';

/**
 * Class Camt053ReviewAlertTest
 */
class Camt053ReviewAlertTest extends TestCase
{
	/**
	 * A summary carrying the given unmatched entries on one account.
	 *
	 * @param array<int, array> $unmatched Unmatched entries
	 * @return array
	 */
	private function summary(array $unmatched): array
	{
		return array(
			'accounts' => array(
				3 => array(
					'account_id' => 3,
					'iban' => 'CH9300762011623852957',
					'num_releve' => '202602',
					'auto' => array(),
					'recorded' => array(),
					'ambiguous' => array(),
					'unmatched' => $unmatched,
					'already' => 0,
					'errors' => array(),
				),
			),
			'totals' => array('unmatched' => count($unmatched)),
		);
	}

	/**
	 * One unmatched entry.
	 *
	 * @param string $reason Why it was not settled
	 * @param array  $extra  Fields to override
	 * @return array
	 */
	private function entry(string $reason, array $extra = array()): array
	{
		return array_merge(array(
			'amount' => -275.0,
			'date' => '2026-02-10',
			'name' => 'Paiement SI2602-0042',
			'info' => 'REFERENCES SI2602-0042',
			'currency' => 'CHF',
			'counterparty_iban' => 'CH5604835012345678009',
			'bank_line_id' => 0,
			'skip_reason' => $reason,
			'document_ref' => '',
			'document_remaining' => 0.0,
		), $extra);
	}

	/**
	 * A run that settled everything says nothing: an alert nobody needs is an
	 * alert nobody reads.
	 *
	 * @return void
	 */
	public function testARunWithNothingToDecideSendsNoMessage(): void
	{
		$files = array('camt.xml' => array('summary' => $this->summary(array()), 'url' => ''));

		$this->assertSame('', Camt053ReviewAlert::format('POSTE', $files));
	}

	/**
	 * The reader has to be able to decide without opening the file: direction,
	 * amount, currency, date, counterparty and the text of the entry.
	 *
	 * @return void
	 */
	public function testAnEntryCarriesWhatItTakesToDecide(): void
	{
		$files = array('camt.xml' => array(
			'summary' => $this->summary(array($this->entry('no_reference'))),
			'url' => 'https://dolibarr.example/statement.php?id=9',
		));

		$message = Camt053ReviewAlert::format('POSTE', $files);

		$this->assertStringContainsString('POSTE', $message);
		$this->assertStringContainsString('camt.xml', $message);
		$this->assertStringContainsString('CH9300762011623852957', $message);
		$this->assertStringContainsString('statement 202602', $message);
		$this->assertStringContainsString('https://dolibarr.example/statement.php?id=9', $message);
		$this->assertStringContainsString('debit 275.00 CHF on 2026-02-10', $message);
		$this->assertStringContainsString('CH5604835012345678009', $message);
		$this->assertStringContainsString('Paiement SI2602-0042', $message);
		$this->assertStringContainsString('No document reference in the message', $message);
	}

	/**
	 * A discrepancy is what finance needs told: the document, what it still owes
	 * and what was received.
	 *
	 * @return void
	 */
	public function testAnAmountThatDoesNotMatchNamesTheDocumentAndTheExpectedAmount(): void
	{
		$entry = $this->entry('amount_mismatch', array(
			'document_ref' => 'SI2602-0042',
			'document_remaining' => 300.0,
		));
		$files = array('camt.xml' => array('summary' => $this->summary(array($entry)), 'url' => ''));

		$message = Camt053ReviewAlert::format('POSTE', $files);

		$this->assertStringContainsString('Not what the document still owes', $message);
		$this->assertStringContainsString('SI2602-0042 owes 300.00', $message);
		$this->assertStringContainsString('debit 275.00', $message);
	}

	/**
	 * A second movement on a document that is already paid is worth its own
	 * heading: it reads as a typo otherwise.
	 *
	 * @return void
	 */
	public function testADoublePaymentIsCalledOne(): void
	{
		$entry = $this->entry('double_payment', array('document_ref' => 'SI2602-0042'));
		$files = array('camt.xml' => array('summary' => $this->summary(array($entry)), 'url' => ''));

		$message = Camt053ReviewAlert::format('POSTE', $files);

		$this->assertStringContainsString('Already settled, the document is paid', $message);
	}

	/**
	 * Entries are grouped by what has to be decided, so one heading is read once
	 * instead of once per entry.
	 *
	 * @return void
	 */
	public function testEntriesAreGroupedByReason(): void
	{
		$entries = array(
			$this->entry('no_reference'),
			$this->entry('no_reference'),
			$this->entry('foreign_currency'),
		);
		$files = array('camt.xml' => array('summary' => $this->summary($entries), 'url' => ''));

		$message = Camt053ReviewAlert::format('POSTE', $files);

		$this->assertSame(1, substr_count($message, 'No document reference in the message'));
		$this->assertStringContainsString('No document reference in the message (2)', $message);
		$this->assertStringContainsString('Foreign currency', $message);
	}

	/**
	 * A file full of unmatched entries must not drown the message.
	 *
	 * @return void
	 */
	public function testALongListIsCapped(): void
	{
		$entries = array();
		for ($i = 0; $i < 25; $i++) {
			$entries[] = $this->entry('no_reference');
		}
		$files = array('camt.xml' => array('summary' => $this->summary($entries), 'url' => ''));

		$message = Camt053ReviewAlert::format('POSTE', $files);

		$this->assertStringContainsString('and 5 more', $message);
		$this->assertSame(Camt053ReviewAlert::MAX_PER_GROUP, substr_count($message, 'debit 275.00'));
	}

	/**
	 * An entry left unmatched while automatic recording is off still needs a
	 * human, and the alert must not depend on that setting.
	 *
	 * @return void
	 */
	public function testAnEntryLeftUnmatchedIsReportedWhateverTheSetting(): void
	{
		$files = array('camt.xml' => array('summary' => $this->summary(array($this->entry('disabled'))), 'url' => ''));

		$message = Camt053ReviewAlert::format('POSTE', $files);

		$this->assertStringContainsString('Matched no bank line', $message);
	}
}
