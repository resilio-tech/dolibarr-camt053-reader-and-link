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
 * \file       class/Camt053PaymentRecorder.class.php
 * \ingroup    camt053readerandlink
 * \brief      Record the payment of the document an entry names, when nothing
 *             is left to decide.
 */

require_once __DIR__ . '/Camt053Entry.class.php';
require_once __DIR__ . '/Camt053DocumentReference.class.php';
require_once __DIR__ . '/PaymentSuggestionFinder.class.php';

/**
 * Class Camt053PaymentRecorder
 *
 * The daily collections name the invoice they settle, and recording them by hand
 * one at a time is the bulk of the manual work left. Only the case where nothing
 * is left to decide is recorded: one reference, resolving to one open document
 * of the company currency, owing exactly what the bank moved. Anything else is
 * reported with the reason, and waits for a human.
 */
class Camt053PaymentRecorder
{
	/** @var string A payment was created and its bank line reconciled */
	const RECORDED = 'recorded';

	/** @var string Nothing is left to decide, but nothing is written yet */
	const CERTAIN = 'certain';

	/** @var string Nothing was recorded, and nothing was written */
	const SKIPPED = 'skipped';

	/** @var string Recording was attempted and could not be completed */
	const FAILED = 'failed';

	/** @var DoliDB Database handler */
	private $db;

	/** @var User User the payment is created as */
	private $user;

	/** @var string Company accounting currency */
	private $companyCurrency;

	/** @var PaymentSuggestionFinder Resolution of a reference to a document */
	private $finder;

	/** @var string|null Last failure */
	private $error = null;

	/**
	 * Constructor
	 *
	 * @param DoliDB      $db              Database handler
	 * @param User        $user            User the payment is created as
	 * @param string|null $companyCurrency Company currency (defaults to MAIN_MONNAIE)
	 */
	public function __construct($db, $user, ?string $companyCurrency = null)
	{
		$this->db = $db;
		$this->user = $user;
		$this->companyCurrency = strtoupper($companyCurrency ?: (string) getDolGlobalString('MAIN_MONNAIE', 'EUR'));
		$this->finder = new PaymentSuggestionFinder($db, $this->companyCurrency);
	}

	/**
	 * Record the payment of the document an entry names.
	 *
	 * @param Camt053Entry $entry     Entry the matcher could not link
	 * @param int          $accountId Bank account the statement belongs to
	 * @param int          $entity    Entity of that account
	 * @param string       $numReleve Statement reference to reconcile with
	 * @return array{status:string, reason:string, document:array|null, payment_id:int, bank_line_id:int}
	 */
	public function record(Camt053Entry $entry, int $accountId, int $entity, string $numReleve): array
	{
		$decision = $this->decide($entry, $accountId, $entity);
		if ($decision['status'] !== self::CERTAIN) {
			return $decision;
		}

		$data = $entry->getData();

		return $this->createPayment(
			$decision['document'],
			abs((float) $data['amount']),
			(string) $data['value_date'],
			$accountId,
			$numReleve
		);
	}

	/**
	 * Decide whether an entry can be settled without asking anyone.
	 *
	 * Separated from the writing so the rule can be read, and tested, on its own.
	 *
	 * @param Camt053Entry $entry     Entry the matcher could not link
	 * @param int          $accountId Bank account the statement belongs to
	 * @param int          $entity    Entity of that account
	 * @return array{status:string, reason:string, document:array|null, payment_id:int, bank_line_id:int}
	 */
	public function decide(Camt053Entry $entry, int $accountId, int $entity): array
	{
		$this->error = null;

		$data = $entry->getData();
		$amount = (float) $data['amount'];
		$references = Camt053DocumentReference::extract((string) $data['name'], (string) $data['info']);

		if (empty($references)) {
			return $this->outcome(self::SKIPPED, 'no_reference');
		}
		// One transfer settling several invoices has to be split across them,
		// which is a decision, not a certainty.
		if (count($references) > 1) {
			return $this->outcome(self::SKIPPED, 'several_references');
		}
		if ($accountId <= 0 || abs($amount) <= 0) {
			return $this->outcome(self::SKIPPED, 'no_document');
		}

		// A foreign currency payment carries a rate, which is one more thing to
		// decide. Left to a human on purpose.
		$currency = strtoupper($entry->getCurrency() ?: $this->companyCurrency);
		if ($currency !== $this->companyCurrency) {
			return $this->outcome(self::SKIPPED, 'foreign_currency');
		}

		$candidates = $this->finder->findByReference($references, $amount > 0, $currency, $entity);
		if (empty($candidates)) {
			// Two distinct movements pointing at one document: the first one
			// settled it, and this one arrives on a document that owes nothing.
			// Worth saying so rather than reporting a reference that resolves to
			// nothing, which reads like a typo.
			$settled = $this->settledDocument($references, $amount > 0, $entity);
			if ($settled !== null) {
				return $this->outcome(self::SKIPPED, 'double_payment', $settled);
			}

			return $this->outcome(self::SKIPPED, 'no_document');
		}
		if (count($candidates) > 1) {
			return $this->outcome(self::SKIPPED, 'several_documents');
		}

		$document = $candidates[0];
		// The amount is what makes it certain: a collection of anything else is a
		// partial payment, an overpayment or a second payment, and every one of
		// those is a decision.
		if (abs(((float) $document['remaining']) - abs($amount)) > 0.005) {
			return $this->outcome(self::SKIPPED, 'amount_mismatch', $document);
		}

		return $this->outcome(self::CERTAIN, '', $document);
	}

	/**
	 * A document of that reference that is already settled.
	 *
	 * @param array<int, string> $references Compact references carried by the entry
	 * @param bool               $incoming   True for money in, false for money out
	 * @param int                $entity     Entity
	 * @return array|null Reference and id of the settled document, null when none
	 */
	private function settledDocument(array $references, bool $incoming, int $entity): ?array
	{
		$spellings = array();
		foreach ($references as $reference) {
			foreach (Camt053DocumentReference::spellings((string) $reference) as $spelling) {
				$spellings[] = "'" . $this->db->escape($spelling) . "'";
			}
		}
		if (empty($spellings)) {
			return null;
		}

		$table = $incoming ? 'facture' : 'facture_fourn';

		$sql = "SELECT f.rowid, f.ref, f.total_ttc";
		$sql .= " FROM " . MAIN_DB_PREFIX . $table . " AS f";
		$sql .= " WHERE f.ref IN (" . implode(',', array_unique($spellings)) . ")";
		$sql .= " AND f.entity = " . ((int) $entity);
		$sql .= " AND f.paye = 1";

		$resql = $this->db->query($sql);
		if (!$resql) {
			return null;
		}

		$row = $this->db->fetch_object($resql);
		if (!$row) {
			return null;
		}

		return array(
			'type' => $incoming ? 'customer_invoice' : 'supplier_invoice',
			'id' => (int) $row->rowid,
			'ref' => (string) $row->ref,
			'label' => '',
			'remaining' => 0.0,
			'total' => (float) $row->total_ttc,
		);
	}

	/**
	 * Create the payment, put it on the bank account and reconcile its line.
	 *
	 * @param array  $document  Document to settle
	 * @param float  $amount    Amount to record (absolute)
	 * @param string $valueDate Value date of the entry (Y-m-d)
	 * @param int    $accountId Bank account
	 * @param string $numReleve Statement reference
	 * @return array
	 */
	private function createPayment(array $document, float $amount, string $valueDate, int $accountId, string $numReleve): array
	{
		require_once DOL_DOCUMENT_ROOT . '/compta/paiement/class/paiement.class.php';
		require_once DOL_DOCUMENT_ROOT . '/fourn/class/paiementfourn.class.php';
		require_once DOL_DOCUMENT_ROOT . '/compta/bank/class/account.class.php';

		$isCustomer = ($document['type'] === 'customer_invoice');

		$this->db->begin();

		$payment = $isCustomer ? new Paiement($this->db) : new PaiementFourn($this->db);
		$payment->datepaye = $this->timestamp($valueDate);
		$payment->amounts = array((int) $document['id'] => $amount);
		$payment->paiementid = (int) dol_getIdFromCode($this->db, 'VIR', 'c_paiement', 'code', 'id');
		$payment->num_payment = '';
		$payment->note_private = 'CAMT053 ' . $numReleve;
		$payment->fk_account = $accountId;

		// The second argument closes the document once it is fully paid, which it
		// is: the amount was checked against what it still owed.
		$paymentId = $payment->create($this->user, 1);
		if ($paymentId <= 0) {
			return $this->fail($payment, 'payment creation failed', $document);
		}

		$mode = $isCustomer ? 'payment' : 'payment_supplier';
		$label = $isCustomer ? '(CustomerInvoicePayment)' : '(SupplierInvoicePayment)';

		$bankLineId = (int) $payment->addPaymentToBank($this->user, $mode, $label, $accountId, '', '');
		if ($bankLineId <= 0) {
			return $this->fail($payment, 'bank line creation failed', $document);
		}

		$line = new AccountLine($this->db);
		if ($line->fetch($bankLineId) <= 0) {
			return $this->fail($payment, 'bank line not found after creation', $document);
		}

		$line->num_releve = $numReleve;
		if ($line->update_conciliation($this->user, 0, 1) <= 0) {
			return $this->fail($line, 'reconciliation failed', $document);
		}

		$this->db->commit();

		dol_syslog('CAMT053 cron: recorded payment #' . $paymentId . ' of ' . $document['ref']
			. ' (' . $amount . ') on bank line #' . $bankLineId . ', num_releve=' . $numReleve, LOG_INFO);

		return array(
			'status' => self::RECORDED,
			'reason' => '',
			'document' => $document,
			'payment_id' => (int) $paymentId,
			'bank_line_id' => $bankLineId,
		);
	}

	/**
	 * Roll back and report a failure.
	 *
	 * @param object $object   Object carrying the error
	 * @param string $reason   What was being done
	 * @param array  $document Document being settled
	 * @return array
	 */
	private function fail($object, string $reason, array $document): array
	{
		$this->db->rollback();

		$detail = !empty($object->error) ? $object->error : implode(', ', (array) $object->errors);
		$this->error = $reason . ($detail !== '' ? ': ' . $detail : '');
		dol_syslog('CAMT053 cron: ' . $this->error . ' for ' . $document['ref'], LOG_ERR);

		return array(
			'status' => self::FAILED,
			'reason' => $this->error,
			'document' => $document,
			'payment_id' => 0,
			'bank_line_id' => 0,
		);
	}

	/**
	 * Build an outcome that wrote nothing.
	 *
	 * @param string     $status   Outcome
	 * @param string     $reason   Why
	 * @param array|null $document Document the reference resolved to, when it did
	 * @return array
	 */
	private function outcome(string $status, string $reason, ?array $document = null): array
	{
		return array(
			'status' => $status,
			'reason' => $reason,
			'document' => $document,
			'payment_id' => 0,
			'bank_line_id' => 0,
		);
	}

	/**
	 * Midday of a value date, as Dolibarr stamps a payment.
	 *
	 * @param string $valueDate Value date (Y-m-d)
	 * @return int
	 */
	private function timestamp(string $valueDate): int
	{
		$date = DateTime::createFromFormat('Y-m-d', $valueDate);
		if ($date === false) {
			return (int) dol_now();
		}

		return (int) dol_mktime(12, 0, 0, (int) $date->format('n'), (int) $date->format('j'), (int) $date->format('Y'));
	}

	/**
	 * Last failure.
	 *
	 * @return string|null
	 */
	public function getError(): ?string
	{
		return $this->error;
	}
}
