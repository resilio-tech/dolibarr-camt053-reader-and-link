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
 * \file       class/RecordedPaymentLookup.class.php
 * \ingroup    camt053readerandlink
 * \brief      Bank line already recorded for a document the entry names.
 */

require_once __DIR__ . '/Camt053DocumentReference.class.php';

/**
 * Class RecordedPaymentLookup
 *
 * An entry the matcher could not link is not necessarily missing from Dolibarr:
 * the most frequent reason is a payment recorded a few days off the date the
 * bank booked it, further than the matching tolerance. Nothing said so, and the
 * entry was reported as one that will not be reconciled, which sends someone
 * looking for a movement that is already there.
 *
 * The document the file names is what finds it: from its reference to its
 * payment, and from the payment to the bank line behind it.
 */
class RecordedPaymentLookup
{
	/** @var DoliDB Database handler */
	private $db;

	/** @var float Amount matching tolerance */
	private $tolerance = 0.005;

	/**
	 * Constructor
	 *
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * Bank line recorded for one of the documents an entry names.
	 *
	 * @param array<int, string> $references Compact references carried by the entry
	 * @param float              $amount     Signed amount of the entry
	 * @param int                $accountId  Bank account the statement belongs to
	 * @return array|null Line and document found, null when there is none
	 */
	public function find(array $references, float $amount, int $accountId): ?array
	{
		if (empty($references) || $accountId <= 0 || abs($amount) <= 0) {
			return null;
		}

		$spellings = array();
		foreach ($references as $reference) {
			foreach (Camt053DocumentReference::spellings((string) $reference) as $spelling) {
				$spellings[] = "'" . $this->db->escape($spelling) . "'";
			}
		}
		if (empty($spellings)) {
			return null;
		}
		$inList = implode(',', array_unique($spellings));

		// A credit is money in, so only a customer invoice payment can answer for
		// it; a debit is money out, so the three outgoing documents can.
		$queries = $amount > 0
			? array('customer_invoice' => $this->customerInvoiceSql($inList, $accountId))
			: array(
				'supplier_invoice' => $this->supplierInvoiceSql($inList, $accountId),
				'expense_report' => $this->expenseReportSql($inList, $accountId),
			);

		foreach ($queries as $type => $sql) {
			$found = $this->firstMatch($sql, $amount, $type);
			if ($found !== null) {
				return $found;
			}
		}

		return null;
	}

	/**
	 * Run one query and keep the first line whose amount matches the entry.
	 *
	 * @param string $sql    Query returning the expected columns
	 * @param float  $amount Signed amount of the entry
	 * @param string $type   Document type the query looks in
	 * @return array|null
	 */
	private function firstMatch(string $sql, float $amount, string $type): ?array
	{
		$resql = $this->db->query($sql);
		if (!$resql) {
			dol_syslog('CAMT053 RecordedPaymentLookup query failed: ' . $this->db->lasterror(), LOG_ERR);
			return null;
		}

		while ($row = $this->db->fetch_object($resql)) {
			// Compared in PHP: the amount is a float on both sides, and the sign
			// of the bank line is the one of the entry.
			if (abs(((float) $row->line_amount) - $amount) > $this->tolerance) {
				continue;
			}

			return array(
				'type' => $type,
				'line_id' => (int) $row->line_id,
				'line_date' => (string) $row->line_date,
				'line_amount' => (float) $row->line_amount,
				'reconciled' => !empty($row->rappro),
				'num_releve' => (string) $row->num_releve,
				'document_id' => (int) $row->document_id,
				'document_ref' => (string) $row->document_ref,
			);
		}

		return null;
	}

	/**
	 * Columns every query returns, and the scoping every query applies.
	 *
	 * @param string $documentTable Document table, without prefix
	 * @param string $documentAlias Alias of the document table
	 * @param string $linkTable     Payment link table, without prefix
	 * @param string $linkColumn    Column of the link table holding the document
	 * @param string $paymentTable  Payment table, without prefix
	 * @param string $paymentColumn Column of the link table holding the payment
	 * @param string $refColumn     Column holding the document reference
	 * @param string $inList        Escaped reference spellings
	 * @param int    $accountId     Bank account of the statement
	 * @return string
	 */
	private function paymentSql(
		string $documentTable,
		string $documentAlias,
		string $linkTable,
		string $linkColumn,
		string $paymentTable,
		string $paymentColumn,
		string $refColumn,
		string $inList,
		int $accountId
	): string {
		$d = $documentAlias;

		$sql = "SELECT b.rowid AS line_id, b.datev AS line_date, b.amount AS line_amount, b.rappro, b.num_releve,";
		$sql .= " " . $d . ".rowid AS document_id, " . $d . "." . $refColumn . " AS document_ref";
		$sql .= " FROM " . MAIN_DB_PREFIX . $documentTable . " AS " . $d;
		$sql .= " INNER JOIN " . MAIN_DB_PREFIX . $linkTable . " AS pl ON pl." . $linkColumn . " = " . $d . ".rowid";
		// An expense report payment is its own link to the document, so there is
		// no second table to join through.
		$payment = 'pl';
		if ($linkTable !== $paymentTable) {
			$payment = 'p';
			$sql .= " INNER JOIN " . MAIN_DB_PREFIX . $paymentTable . " AS p ON p.rowid = pl." . $paymentColumn;
		}
		$sql .= " INNER JOIN " . MAIN_DB_PREFIX . "bank AS b ON b.rowid = " . $payment . ".fk_bank";
		$sql .= " INNER JOIN " . MAIN_DB_PREFIX . "bank_account AS ba ON ba.rowid = b.fk_account";
		$sql .= " WHERE " . $d . "." . $refColumn . " IN (" . $inList . ")";
		// SPEC section 2: the document and the account it was paid from both stay
		// inside the entity, and the line has to belong to the statement account.
		$sql .= " AND " . $d . ".entity IN (" . getEntity('bank_account', 0) . ")";
		$sql .= " AND ba.entity IN (" . getEntity('bank_account', 0) . ")";
		$sql .= " AND b.fk_account = " . ((int) $accountId);
		$sql .= " ORDER BY b.datev DESC";

		return $sql;
	}

	/**
	 * Payment of a customer invoice.
	 *
	 * @param string $inList    Escaped reference spellings
	 * @param int    $accountId Bank account of the statement
	 * @return string
	 */
	private function customerInvoiceSql(string $inList, int $accountId): string
	{
		return $this->paymentSql('facture', 'f', 'paiement_facture', 'fk_facture', 'paiement', 'fk_paiement', 'ref', $inList, $accountId);
	}

	/**
	 * Payment of a supplier invoice.
	 *
	 * @param string $inList    Escaped reference spellings
	 * @param int    $accountId Bank account of the statement
	 * @return string
	 */
	private function supplierInvoiceSql(string $inList, int $accountId): string
	{
		return $this->paymentSql('facture_fourn', 'f', 'paiementfourn_facturefourn', 'fk_facturefourn', 'paiementfourn', 'fk_paiementfourn', 'ref', $inList, $accountId);
	}

	/**
	 * Payment of an expense report. The link to the payment is the payment row
	 * itself, so it stands in for the link table.
	 *
	 * @param string $inList    Escaped reference spellings
	 * @param int    $accountId Bank account of the statement
	 * @return string
	 */
	private function expenseReportSql(string $inList, int $accountId): string
	{
		return $this->paymentSql('expensereport', 'er', 'payment_expensereport', 'fk_expensereport', 'payment_expensereport', 'rowid', 'ref', $inList, $accountId);
	}

}
