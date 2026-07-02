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
 * \file       class/PaymentSuggestionFinder.class.php
 * \ingroup    camt053readerandlink
 * \brief      Find unpaid documents matching an unreconciled CAMT.053 entry and
 *             build deep links to their (prefilled) Dolibarr payment pages.
 */

require_once __DIR__ . '/Camt053Entry.class.php';

/**
 * Class PaymentSuggestionFinder
 *
 * For a bank entry that exists in the CAMT.053 file but not in Dolibarr, look up
 * unpaid documents of the same amount and currency (filtered by entity) and
 * return ready-to-use links:
 *  - debit  (money out) -> supplier invoice, expense report, social/fiscal charge
 *  - credit (money in)  -> customer invoice
 * A single match yields a prefilled payment link; several matches of one type
 * yield a link to the filtered list.
 */
class PaymentSuggestionFinder
{
	/** @var DoliDb Database connection */
	private $db;

	/** @var string Company accounting currency (e.g. CHF) */
	private $companyCurrency;

	/** @var float Amount matching tolerance */
	private $tolerance = 0.005;

	/**
	 * Constructor
	 *
	 * @param DoliDb      $db              Database connection
	 * @param string|null $companyCurrency Company currency (defaults to MAIN_MONNAIE)
	 */
	public function __construct($db, ?string $companyCurrency = null)
	{
		$this->db = $db;
		$this->companyCurrency = strtoupper($companyCurrency ?: (string) getDolGlobalString('MAIN_MONNAIE', 'EUR'));
	}

	/**
	 * Find payment suggestions for one unreconciled file entry.
	 *
	 * @param Camt053Entry $entry  Entry from the CAMT.053 file (not in Dolibarr)
	 * @param int          $entity Entity of the bank account
	 * @return array{currency:string, links:array<int,array>} Link descriptors
	 */
	public function findForEntry(Camt053Entry $entry, int $entity): array
	{
		if ($entity <= 0) {
			global $conf;
			$entity = (int) $conf->entity;
		}

		$amount = $entry->getAmount();
		$absAmount = abs($amount);
		$currency = strtoupper($entry->getCurrency() ?: $this->companyCurrency);
		$date = $entry->getValueDate();

		if ($absAmount <= 0) {
			return array('currency' => $currency, 'links' => array());
		}

		$candidatesByType = array();
		if ($amount < 0) {
			// Debit: things we pay out.
			$candidatesByType['supplier_invoice'] = $this->supplierInvoices($absAmount, $currency, $entity);
			$candidatesByType['expense_report'] = $this->expenseReports($absAmount, $currency, $entity);
			$candidatesByType['social_charge'] = $this->socialCharges($absAmount, $currency, $entity);
		} else {
			// Credit: money received from a customer.
			$candidatesByType['customer_invoice'] = $this->customerInvoices($absAmount, $currency, $entity);
		}

		$links = array();
		foreach ($candidatesByType as $type => $candidates) {
			if (empty($candidates)) {
				continue;
			}
			if (count($candidates) === 1) {
				$c = $candidates[0];
				$links[] = array(
					'kind' => 'pay',
					'type' => $type,
					'id' => $c['id'],
					'ref' => $c['ref'],
					'label' => $c['label'],
					'amount' => $c['remaining'],
					'currency' => $currency,
					'url' => $this->payUrl($type, $c['id'], $c['remaining'], $date),
				);
			} else {
				$options = array();
				foreach ($candidates as $c) {
					$options[] = array(
						'id' => $c['id'],
						'ref' => $c['ref'],
						'label' => $c['label'],
						'amount' => $c['remaining'],
						'currency' => $currency,
						'url' => $this->payUrl($type, $c['id'], $c['remaining'], $date),
					);
				}
				$links[] = array(
					'kind' => 'choice',
					'type' => $type,
					'count' => count($candidates),
					'currency' => $currency,
					'options' => $options,
				);
			}
		}

		return array('currency' => $currency, 'links' => $links);
	}

	/**
	 * Decide a document's payable currency and remaining due.
	 *
	 * @param object $row             Row with total_ttc, multicurrency_code,
	 *                                multicurrency_total_ttc, paid, paid_mc
	 * @return array{0:string,1:float} [currency, remaining]
	 */
	private function payable(object $row): array
	{
		$mcCode = isset($row->multicurrency_code) ? strtoupper((string) $row->multicurrency_code) : '';
		if ($mcCode !== '' && $mcCode !== $this->companyCurrency) {
			$remaining = (float) $row->multicurrency_total_ttc - (float) ($row->paid_mc ?? 0);
			return array($mcCode, $remaining);
		}
		$remaining = (float) $row->total_ttc - (float) ($row->paid ?? 0);
		return array($this->companyCurrency, $remaining);
	}

	/**
	 * @param float $a
	 * @param float $b
	 * @return bool True when amounts match within tolerance
	 */
	private function amountMatches(float $a, float $b): bool
	{
		return abs($a - $b) < $this->tolerance;
	}

	/**
	 * Unpaid customer invoices matching amount + currency.
	 *
	 * @param float  $absAmount Entry amount (absolute)
	 * @param string $currency  Entry currency
	 * @param int    $entity    Entity
	 * @return array<int,array>
	 */
	private function customerInvoices(float $absAmount, string $currency, int $entity): array
	{
		$sql = "SELECT f.rowid, f.ref, s.nom AS label, f.total_ttc, f.multicurrency_code, f.multicurrency_total_ttc,";
		$sql .= " COALESCE((SELECT SUM(pf.amount) FROM " . MAIN_DB_PREFIX . "paiement_facture pf WHERE pf.fk_facture = f.rowid), 0) AS paid,";
		$sql .= " COALESCE((SELECT SUM(pf.multicurrency_amount) FROM " . MAIN_DB_PREFIX . "paiement_facture pf WHERE pf.fk_facture = f.rowid), 0) AS paid_mc";
		$sql .= " FROM " . MAIN_DB_PREFIX . "facture f";
		$sql .= " LEFT JOIN " . MAIN_DB_PREFIX . "societe s ON s.rowid = f.fk_soc";
		$sql .= " WHERE f.entity = " . ((int) $entity) . " AND f.paye = 0 AND f.fk_statut = 1";

		return $this->collect($sql, $absAmount, $currency);
	}

	/**
	 * Unpaid supplier invoices matching amount + currency.
	 *
	 * @param float  $absAmount Entry amount (absolute)
	 * @param string $currency  Entry currency
	 * @param int    $entity    Entity
	 * @return array<int,array>
	 */
	private function supplierInvoices(float $absAmount, string $currency, int $entity): array
	{
		$sql = "SELECT f.rowid, f.ref, s.nom AS label, f.total_ttc, f.multicurrency_code, f.multicurrency_total_ttc,";
		$sql .= " COALESCE((SELECT SUM(pf.amount) FROM " . MAIN_DB_PREFIX . "paiementfourn_facturefourn pf WHERE pf.fk_facturefourn = f.rowid), 0) AS paid,";
		$sql .= " COALESCE((SELECT SUM(pf.multicurrency_amount) FROM " . MAIN_DB_PREFIX . "paiementfourn_facturefourn pf WHERE pf.fk_facturefourn = f.rowid), 0) AS paid_mc";
		$sql .= " FROM " . MAIN_DB_PREFIX . "facture_fourn f";
		$sql .= " LEFT JOIN " . MAIN_DB_PREFIX . "societe s ON s.rowid = f.fk_soc";
		$sql .= " WHERE f.entity = " . ((int) $entity) . " AND f.paye = 0 AND f.fk_statut = 1";

		return $this->collect($sql, $absAmount, $currency);
	}

	/**
	 * Unpaid (approved) expense reports matching amount. Company currency only.
	 *
	 * @param float  $absAmount Entry amount (absolute)
	 * @param string $currency  Entry currency
	 * @param int    $entity    Entity
	 * @return array<int,array>
	 */
	private function expenseReports(float $absAmount, string $currency, int $entity): array
	{
		if ($currency !== $this->companyCurrency) {
			return array(); // expense reports have no multicurrency support
		}

		$sql = "SELECT er.rowid, er.ref, CONCAT_WS(' ', u.firstname, u.lastname) AS label, er.total_ttc,";
		$sql .= " '' AS multicurrency_code, 0 AS multicurrency_total_ttc,";
		$sql .= " COALESCE((SELECT SUM(p.amount) FROM " . MAIN_DB_PREFIX . "payment_expensereport p WHERE p.fk_expensereport = er.rowid), 0) AS paid,";
		$sql .= " 0 AS paid_mc";
		$sql .= " FROM " . MAIN_DB_PREFIX . "expensereport er";
		$sql .= " LEFT JOIN " . MAIN_DB_PREFIX . "user u ON u.rowid = er.fk_user_author";
		$sql .= " WHERE er.entity = " . ((int) $entity) . " AND er.paid = 0 AND er.fk_statut = 5";

		return $this->collect($sql, $absAmount, $currency);
	}

	/**
	 * Unpaid social/fiscal charges matching amount. Company currency only.
	 *
	 * @param float  $absAmount Entry amount (absolute)
	 * @param string $currency  Entry currency
	 * @param int    $entity    Entity
	 * @return array<int,array>
	 */
	private function socialCharges(float $absAmount, string $currency, int $entity): array
	{
		if ($currency !== $this->companyCurrency) {
			return array();
		}

		$sql = "SELECT cs.rowid, cs.libelle AS ref, cs.libelle AS label, cs.amount AS total_ttc,";
		$sql .= " '' AS multicurrency_code, 0 AS multicurrency_total_ttc,";
		$sql .= " COALESCE((SELECT SUM(p.amount) FROM " . MAIN_DB_PREFIX . "paiementcharge p WHERE p.fk_charge = cs.rowid), 0) AS paid,";
		$sql .= " 0 AS paid_mc";
		$sql .= " FROM " . MAIN_DB_PREFIX . "chargesociales cs";
		$sql .= " WHERE cs.entity = " . ((int) $entity) . " AND cs.paye = 0";

		return $this->collect($sql, $absAmount, $currency);
	}

	/**
	 * Run a candidate query and keep rows whose payable currency + remaining match.
	 *
	 * @param string $sql       Query returning the expected columns
	 * @param float  $absAmount Target amount
	 * @param string $currency  Target currency
	 * @return array<int,array> Matched candidates
	 */
	private function collect(string $sql, float $absAmount, string $currency): array
	{
		$out = array();
		$resql = $this->db->query($sql);
		if (!$resql) {
			dol_syslog('CAMT053 PaymentSuggestionFinder query failed: ' . $this->db->lasterror(), LOG_ERR);
			return $out;
		}
		while ($row = $this->db->fetch_object($resql)) {
			list($docCurrency, $remaining) = $this->payable($row);
			if ($docCurrency !== $currency || !$this->amountMatches($remaining, $absAmount)) {
				continue;
			}
			$out[] = array(
				'id' => (int) $row->rowid,
				'ref' => (string) $row->ref,
				'label' => trim((string) $row->label),
				'remaining' => $remaining,
			);
		}
		return $out;
	}

	/**
	 * Build a deep link to a prefilled payment-creation page.
	 *
	 * @param string $type   Document type
	 * @param int    $id     Document id
	 * @param float  $amount Amount to prefill
	 * @param string $date   Value date (Y-m-d)
	 * @return string URL
	 */
	private function payUrl(string $type, int $id, float $amount, string $date): string
	{
		$amt = number_format($amount, 2, '.', '');
		list($y, $m, $d) = $this->dateParts($date);
		$dateParams = '&remonth=' . $m . '&reday=' . $d . '&reyear=' . $y;

		switch ($type) {
			case 'customer_invoice':
				return DOL_URL_ROOT . '/compta/paiement.php?facid=' . $id . '&amount_' . $id . '=' . $amt . $dateParams;
			case 'supplier_invoice':
				return DOL_URL_ROOT . '/fourn/facture/paiement.php?facid=' . $id . '&amount_' . $id . '=' . $amt . $dateParams;
			case 'expense_report':
				return DOL_URL_ROOT . '/expensereport/payment/payment.php?id=' . $id . '&action=create&amount_' . $id . '=' . $amt . $dateParams;
			case 'social_charge':
				return DOL_URL_ROOT . '/compta/paiement_charge.php?id=' . $id . '&action=create&amount_' . $id . '=' . $amt . $dateParams;
		}
		return '';
	}

	/**
	 * Split a Y-m-d date into [year, month, day]; today when unparsable.
	 *
	 * @param string $date Date string
	 * @return array{0:string,1:string,2:string}
	 */
	private function dateParts(string $date): array
	{
		if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $date, $m)) {
			return array($m[1], (string) ((int) $m[2]), (string) ((int) $m[3]));
		}
		return array(date('Y'), date('n'), date('j'));
	}
}
