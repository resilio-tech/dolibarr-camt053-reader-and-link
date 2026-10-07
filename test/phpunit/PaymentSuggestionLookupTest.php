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
 * \file       test/phpunit/PaymentSuggestionLookupTest.php
 * \ingroup    camt053readerandlink
 * \brief      PHPUnit tests for the documents PaymentSuggestionFinder looks up
 *             for an entry.
 */

use PHPUnit\Framework\TestCase;

if (!defined('DOL_URL_ROOT')) {
	define('DOL_URL_ROOT', '/dolibarr');
}
if (!defined('MAIN_DB_PREFIX')) {
	define('MAIN_DB_PREFIX', 'llx_');
}

require_once dirname(__FILE__) . '/../../class/Camt053Entry.class.php';
require_once dirname(__FILE__) . '/../../class/PaymentSuggestionFinder.class.php';

/**
 * Database mock answering every query with the same document rows.
 */
class FinderMockDb
{
	/** @var array<int, object> Rows returned by each query */
	private $rows;

	/** @var array<int, array<int, object>> Rows left per query */
	private $pending = array();

	/** @var array<int, string> Queries received */
	public $queries = array();

	/**
	 * @param array<int, object> $rows Rows returned by each query
	 */
	public function __construct(array $rows = array())
	{
		$this->rows = $rows;
	}

	/**
	 * @param string $sql Query
	 * @return int
	 */
	public function query($sql)
	{
		$this->queries[] = $sql;
		$this->pending[count($this->queries)] = $this->rows;

		return count($this->queries);
	}

	/**
	 * @param int $result Result handle
	 * @return object|null
	 */
	public function fetch_object($result)
	{
		return array_shift($this->pending[(int) $result]);
	}

	/**
	 * @param string $value Value
	 * @return string
	 */
	public function escape($value)
	{
		return str_replace("'", "\\'", (string) $value);
	}
}

/**
 * Class PaymentSuggestionLookupTest
 */
class PaymentSuggestionLookupTest extends TestCase
{
	/**
	 * One unpaid customer invoice row, in the company currency.
	 *
	 * @param float $remaining What it still owes
	 * @return object
	 */
	private function invoice(float $remaining): object
	{
		return (object) array(
			'rowid' => 12,
			'ref' => 'FA2602-0001',
			'label' => 'ACME Corporation',
			'total_ttc' => $remaining,
			'multicurrency_code' => '',
			'multicurrency_total_ttc' => 0,
			'paid' => 0,
			'paid_mc' => 0,
		);
	}

	/**
	 * A credit carrying the given text.
	 *
	 * @param string $name   Entry text
	 * @param float  $amount Signed amount
	 * @return Camt053Entry
	 */
	private function entry(string $name, float $amount): Camt053Entry
	{
		$entry = new Camt053Entry($amount, '2026-02-10', $name, '');
		$entry->setCurrency('CHF');

		return $entry;
	}

	/**
	 * An invoice issued after the payment cannot be what it paid.
	 *
	 * @return void
	 */
	public function testSuggestionsOnlyKeepInvoicesIssuedByThePaymentDate(): void
	{
		$db = new FinderMockDb();
		$finder = new PaymentSuggestionFinder($db, 'CHF');

		$finder->findForEntry($this->entry('Virement ACME', 150.0), 1);

		$this->assertStringContainsString("f.datef <= '2026-02-10'", $db->queries[0]);
	}

	/**
	 * The document the entry names is offered with the amount received when it
	 * owes something else, so a partial payment is one click away.
	 *
	 * @return void
	 */
	public function testANamedDocumentOwingAnotherAmountIsOfferedWithTheAmountReceived(): void
	{
		$finder = new PaymentSuggestionFinder(new FinderMockDb(array($this->invoice(250.0))), 'CHF');

		$links = $finder->findNamedForEntry($this->entry('Paiement FA2602-0001', 100.0), 1, 3);

		$this->assertCount(1, $links);
		$this->assertSame('FA2602-0001', $links[0]['ref']);
		$this->assertEqualsWithDelta(250.0, $links[0]['remaining'], 0.001);
		$this->assertStringContainsString('amount_12=100.00', $links[0]['url']);
		$this->assertStringContainsString('accountid=3', $links[0]['url']);
	}

	/**
	 * A named document owing exactly the amount is already offered with the
	 * amount suggestions, so it is not offered twice.
	 *
	 * @return void
	 */
	public function testANamedDocumentOwingTheAmountIsNotOfferedTwice(): void
	{
		$finder = new PaymentSuggestionFinder(new FinderMockDb(array($this->invoice(100.0))), 'CHF');

		$this->assertSame(array(), $finder->findNamedForEntry($this->entry('Paiement FA2602-0001', 100.0), 1, 3));
	}
}
