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
 * \file       test/phpunit/PaymentSuggestionFinderTest.php
 * \ingroup    camt053readerandlink
 * \brief      PHPUnit tests for PaymentSuggestionFinder (payment link building
 *             and multicurrency handling).
 */

use PHPUnit\Framework\TestCase;

// The link builder concatenates DOL_URL_ROOT; provide a stable value for tests.
if (!defined('DOL_URL_ROOT')) {
	define('DOL_URL_ROOT', '/dolibarr');
}

require_once dirname(__FILE__) . '/../../class/Camt053Entry.class.php';
require_once dirname(__FILE__) . '/../../class/PaymentSuggestionFinder.class.php';

/**
 * Class PaymentSuggestionFinderTest
 *
 * The DB-backed lookups need a live Dolibarr database, so these tests focus on
 * the pure link-building and currency logic reached through reflection.
 */
class PaymentSuggestionFinderTest extends TestCase
{
	/**
	 * @var PaymentSuggestionFinder Finder with CHF as company currency
	 */
	private $finder;

	/**
	 * Set up a finder with an explicit company currency (no DB access needed).
	 *
	 * @return void
	 */
	protected function setUp(): void
	{
		// Company currency passed explicitly so the constructor never touches
		// getDolGlobalString(); the DB handle is unused by the tested methods.
		$this->finder = new PaymentSuggestionFinder(null, 'CHF');
	}

	/**
	 * Invoke the private payUrl() method.
	 *
	 * @param string $type     Document type
	 * @param int    $id       Document id
	 * @param float  $amount   Amount
	 * @param string $date     Value date (Y-m-d)
	 * @param string $currency Payable currency
	 * @return string
	 */
	private function payUrl(string $type, int $id, float $amount, string $date, string $currency): string
	{
		$method = new ReflectionMethod(PaymentSuggestionFinder::class, 'payUrl');
		$method->setAccessible(true);
		return $method->invoke($this->finder, $type, $id, $amount, $date, $currency);
	}

	/**
	 * Invoke the private payable() method.
	 *
	 * @param object $row Candidate row
	 * @return array [currency, remaining]
	 */
	private function payable(object $row): array
	{
		$method = new ReflectionMethod(PaymentSuggestionFinder::class, 'payable');
		$method->setAccessible(true);
		return $method->invoke($this->finder, $row);
	}

	/**
	 * A customer invoice in the company currency fills the standard amount field.
	 *
	 * @return void
	 */
	public function testPayUrlCustomerInvoiceCompanyCurrency(): void
	{
		$url = $this->payUrl('customer_invoice', 7, 100.0, '2026-06-25', 'CHF');

		$this->assertStringContainsString('/compta/paiement.php?facid=7', $url);
		$this->assertStringContainsString('&amount_7=100.00', $url);
		$this->assertStringNotContainsString('multicurrency_amount_', $url);
	}

	/**
	 * A foreign-currency customer invoice fills the multicurrency amount field.
	 *
	 * @return void
	 */
	public function testPayUrlCustomerInvoiceForeignCurrency(): void
	{
		$url = $this->payUrl('customer_invoice', 7, 100.0, '2026-06-25', 'EUR');

		$this->assertStringContainsString('/compta/paiement.php?facid=7', $url);
		$this->assertStringContainsString('&multicurrency_amount_7=100.00', $url);
	}

	/**
	 * A foreign-currency supplier invoice fills the multicurrency amount field.
	 *
	 * @return void
	 */
	public function testPayUrlSupplierInvoiceForeignCurrency(): void
	{
		$url = $this->payUrl('supplier_invoice', 12, 250.5, '2026-06-25', 'EUR');

		$this->assertStringContainsString('/fourn/facture/paiement.php?facid=12', $url);
		$this->assertStringContainsString('&multicurrency_amount_12=250.50', $url);
	}

	/**
	 * Expense reports are company-currency only and never use the multicurrency
	 * field, even if a foreign currency is passed.
	 *
	 * @return void
	 */
	public function testPayUrlExpenseReportAlwaysCompanyField(): void
	{
		$url = $this->payUrl('expense_report', 3, 42.0, '2026-06-25', 'EUR');

		$this->assertStringContainsString('/expensereport/payment/payment.php?id=3', $url);
		$this->assertStringContainsString('&amount_3=42.00', $url);
		$this->assertStringNotContainsString('multicurrency_amount_', $url);
	}

	/**
	 * Social charges are company-currency only and never use the multicurrency
	 * field.
	 *
	 * @return void
	 */
	public function testPayUrlSocialChargeAlwaysCompanyField(): void
	{
		$url = $this->payUrl('social_charge', 9, 88.0, '2026-06-25', 'EUR');

		$this->assertStringContainsString('/compta/paiement_charge.php?id=9', $url);
		$this->assertStringContainsString('&amount_9=88.00', $url);
		$this->assertStringNotContainsString('multicurrency_amount_', $url);
	}

	/**
	 * The value date is split into remonth/reday/reyear parameters.
	 *
	 * @return void
	 */
	public function testPayUrlIncludesDateParts(): void
	{
		$url = $this->payUrl('customer_invoice', 1, 10.0, '2026-06-05', 'CHF');

		$this->assertStringContainsString('remonth=6', $url);
		$this->assertStringContainsString('reday=5', $url);
		$this->assertStringContainsString('reyear=2026', $url);
	}

	/**
	 * An unknown document type yields an empty URL.
	 *
	 * @return void
	 */
	public function testPayUrlUnknownTypeIsEmpty(): void
	{
		$this->assertSame('', $this->payUrl('unknown', 1, 10.0, '2026-06-05', 'CHF'));
	}

	/**
	 * payable() returns the multicurrency amount and code for a foreign invoice.
	 *
	 * @return void
	 */
	public function testPayableForeignCurrency(): void
	{
		$row = (object) array(
			'total_ttc' => 108.0,
			'multicurrency_code' => 'EUR',
			'multicurrency_total_ttc' => 100.0,
			'paid' => 0,
			'paid_mc' => 0,
		);

		list($currency, $remaining) = $this->payable($row);

		$this->assertSame('EUR', $currency);
		$this->assertEqualsWithDelta(100.0, $remaining, 0.001);
	}

	/**
	 * payable() falls back to the company currency and total_ttc when the
	 * multicurrency code equals the company currency.
	 *
	 * @return void
	 */
	public function testPayableCompanyCurrency(): void
	{
		$row = (object) array(
			'total_ttc' => 100.0,
			'multicurrency_code' => 'CHF',
			'multicurrency_total_ttc' => 100.0,
			'paid' => 40.0,
			'paid_mc' => 40.0,
		);

		list($currency, $remaining) = $this->payable($row);

		$this->assertSame('CHF', $currency);
		$this->assertEqualsWithDelta(60.0, $remaining, 0.001);
	}
}
