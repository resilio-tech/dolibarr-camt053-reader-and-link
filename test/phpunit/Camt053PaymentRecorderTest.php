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
 * \file       test/phpunit/Camt053PaymentRecorderTest.php
 * \ingroup    camt053readerandlink
 * \brief      PHPUnit tests for what may and may not be recorded on its own
 */

use PHPUnit\Framework\TestCase;

if (!defined('MAIN_DB_PREFIX')) {
	define('MAIN_DB_PREFIX', 'llx_');
}
if (!function_exists('getEntity')) {
	/**
	 * Stand-in for Dolibarr's entity filter. Defined here because defining
	 * MAIN_DB_PREFIX without it leaves the IBAN lookup calling a function that
	 * does not exist, in every test file sharing the process.
	 *
	 * @param string $element       Element name
	 * @param int    $shared        Whether shared entities are included
	 * @param mixed  $currentobject Current object
	 * @return string
	 */
	function getEntity($element = '', $shared = 1, $currentobject = null)
	{
		return '1';
	}
}
if (!function_exists('dol_syslog')) {
	/**
	 * Stand-in for Dolibarr's logger.
	 *
	 * @param string $message Message
	 * @param int    $level   Level
	 * @return void
	 */
	function dol_syslog($message, $level = 0)
	{
	}
}
require_once dirname(__FILE__) . '/../../class/Camt053PaymentRecorder.class.php';

/**
 * Database mock answering each query with a fixed list of document rows.
 */
class RecorderMockDb
{
	/** @var array<int, array<int, object>> Rows per query, in order */
	private $rowsByQuery;

	/** @var array<int, string> Queries received */
	public $queries = array();

	/**
	 * @param array<int, array<int, object>> $rowsByQuery Rows per query
	 */
	public function __construct(array $rowsByQuery = array())
	{
		$this->rowsByQuery = $rowsByQuery;
	}

	/**
	 * @param string $sql Query
	 * @return int
	 */
	public function query($sql)
	{
		$this->queries[] = $sql;

		return count($this->queries);
	}

	/**
	 * @param int $result Result handle
	 * @return object|null
	 */
	public function fetch_object($result)
	{
		$index = ((int) $result) - 1;
		if (!isset($this->rowsByQuery[$index]) || empty($this->rowsByQuery[$index])) {
			return null;
		}

		return array_shift($this->rowsByQuery[$index]);
	}

	/**
	 * @param string $value Value
	 * @return string
	 */
	public function escape($value)
	{
		return str_replace("'", "\\'", (string) $value);
	}

	/**
	 * @return string
	 */
	public function lasterror()
	{
		return '';
	}
}

/**
 * Class Camt053PaymentRecorderTest
 *
 * Everything here is a case that must write nothing. Recording is only allowed
 * when there is nothing left to decide, and each of these is a decision.
 */
class Camt053PaymentRecorderTest extends TestCase
{
	/**
	 * One unpaid document row as the candidate query returns it.
	 *
	 * @param string $ref      Document reference
	 * @param float  $totalTtc Total of the document
	 * @param float  $paid     What was already paid on it
	 * @return object
	 */
	private function document(string $ref, float $totalTtc, float $paid = 0.0): object
	{
		$row = new stdClass();
		$row->rowid = 12;
		$row->ref = $ref;
		$row->label = 'ACME Corporation';
		$row->total_ttc = $totalTtc;
		$row->multicurrency_code = '';
		$row->multicurrency_total_ttc = 0;
		$row->paid = $paid;
		$row->paid_mc = 0;
		$row->payment_mode = 0;

		return $row;
	}

	/**
	 * A credit carrying the given text.
	 *
	 * @param string $name   Entry name
	 * @param float  $amount Signed amount
	 * @return Camt053Entry
	 */
	private function entry(string $name, float $amount = 150.0): Camt053Entry
	{
		$entry = new Camt053Entry($amount, '2026-02-10', $name, '');
		$entry->setCurrency('CHF');

		return $entry;
	}

	/**
	 * Run the recorder over one entry.
	 *
	 * @param Camt053Entry $entry Entry
	 * @param array        $rows  Rows the candidate query answers with
	 * @return array
	 */
	private function decide(Camt053Entry $entry, array $rows = array()): array
	{
		$db = new RecorderMockDb(empty($rows) ? array() : array($rows));
		$recorder = new Camt053PaymentRecorder($db, null, 'CHF');

		return $recorder->decide($entry, 3, 1);
	}

	/**
	 * Nothing names a document, so nothing can be settled.
	 *
	 * @return void
	 */
	public function testAnEntryWithoutAReferenceRecordsNothing(): void
	{
		$outcome = $this->decide($this->entry('Virement de ACME Corporation'));

		$this->assertSame(Camt053PaymentRecorder::SKIPPED, $outcome['status']);
		$this->assertSame('no_reference', $outcome['reason']);
	}

	/**
	 * One transfer settling several invoices has to be split across them, which
	 * is exactly the decision this is not allowed to take.
	 *
	 * @return void
	 */
	public function testSeveralReferencesRecordNothing(): void
	{
		$outcome = $this->decide($this->entry('Paiement FA2602-0001 et FA2602-0002'));

		$this->assertSame(Camt053PaymentRecorder::SKIPPED, $outcome['status']);
		$this->assertSame('several_references', $outcome['reason']);
	}

	/**
	 * A foreign currency payment carries a rate, which is one more thing to
	 * decide.
	 *
	 * @return void
	 */
	public function testAForeignCurrencyEntryRecordsNothing(): void
	{
		$entry = $this->entry('Paiement FA2602-0001');
		$entry->setCurrency('EUR');

		$outcome = $this->decide($entry);

		$this->assertSame(Camt053PaymentRecorder::SKIPPED, $outcome['status']);
		$this->assertSame('foreign_currency', $outcome['reason']);
	}

	/**
	 * A reference resolving to nothing open is not a payment to record.
	 *
	 * @return void
	 */
	public function testAReferenceResolvingToNothingRecordsNothing(): void
	{
		$outcome = $this->decide($this->entry('Paiement FA2602-0001'));

		$this->assertSame(Camt053PaymentRecorder::SKIPPED, $outcome['status']);
		$this->assertSame('no_document', $outcome['reason']);
	}

	/**
	 * The amount is what makes it certain. Anything else is a partial payment,
	 * an overpayment or a second payment, and each one is a decision.
	 *
	 * @return void
	 */
	public function testAnAmountThatIsNotTheRemainingDueRecordsNothing(): void
	{
		$outcome = $this->decide($this->entry('Paiement FA2602-0001'), array($this->document('FA2602-0001', 200.0)));

		$this->assertSame(Camt053PaymentRecorder::SKIPPED, $outcome['status']);
		$this->assertSame('amount_mismatch', $outcome['reason']);
		$this->assertNotNull($outcome['document'], 'The document found is carried, so the alert can name it');
		$this->assertSame('FA2602-0001', $outcome['document']['ref']);
	}

	/**
	 * Two documents carrying the same reference is a state nobody can act on
	 * blindly.
	 *
	 * @return void
	 */
	public function testSeveralDocumentsForOneReferenceRecordNothing(): void
	{
		$rows = array($this->document('FA2602-0001', 150.0), $this->document('FA2602-0001', 150.0));

		$outcome = $this->decide($this->entry('Paiement FA2602-0001'), $rows);

		$this->assertSame(Camt053PaymentRecorder::SKIPPED, $outcome['status']);
		$this->assertSame('several_documents', $outcome['reason']);
	}

	/**
	 * A partial payment already recorded leaves the rest due, and the entry has
	 * to settle exactly that rest.
	 *
	 * @return void
	 */
	public function testTheRemainingDueIsWhatCounts(): void
	{
		$outcome = $this->decide($this->entry('Paiement FA2602-0001'), array($this->document('FA2602-0001', 400.0, 250.0)));

		// 400 total, 250 already paid, 150 left: the entry settles exactly what is
		// left, so there is nothing to decide and the payment is recorded.
		$this->assertSame(Camt053PaymentRecorder::CERTAIN, $outcome['status']);
		$this->assertSame('FA2602-0001', $outcome['document']['ref']);
		$this->assertSame(150.0, $outcome['document']['remaining']);
	}

	/**
	 * The direction of the movement decides which documents are looked at: a
	 * credit settles a customer invoice, a debit a supplier one.
	 *
	 * @return void
	 */
	public function testTheDirectionDecidesWhichDocumentsAreLookedAt(): void
	{
		$db = new RecorderMockDb();
		$recorder = new Camt053PaymentRecorder($db, null, 'CHF');
		$recorder->decide($this->entry('Paiement FA2602-0001', 150.0), 3, 1);
		$this->assertStringContainsString('llx_facture f', $db->queries[0]);

		$db = new RecorderMockDb();
		$recorder = new Camt053PaymentRecorder($db, null, 'CHF');
		$recorder->decide($this->entry('Paiement SI2602-0042', -150.0), 3, 1);
		$this->assertStringContainsString('llx_facture_fourn f', $db->queries[0]);
	}
}
