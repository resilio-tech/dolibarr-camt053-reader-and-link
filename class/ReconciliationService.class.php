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
 * \file       class/ReconciliationService.class.php
 * \ingroup    camt053readerandlink
 * \brief      Headless CAMT.053 reconciliation: parse, match, and auto-reconcile
 *             ONLY unambiguous (unique) matches. Ambiguous and unmatched entries
 *             are reported for manual handling. Shared by the cron runner.
 */

require_once DOL_DOCUMENT_ROOT . '/compta/bank/class/account.class.php';
require_once __DIR__ . '/Camt053FileProcessor.class.php';
require_once __DIR__ . '/DatabaseBankStatementLoader.class.php';
require_once __DIR__ . '/BankStatementMatcher.class.php';

/**
 * Class ReconciliationService
 *
 * Processes a CAMT.053 XML payload and reconciles the bank lines that have a
 * single matching entry. Returns a structured summary (no HTML, no output).
 */
class ReconciliationService
{
	/** @var DoliDb Database connection */
	private $db;

	/** @var User User performing the reconciliation */
	private $user;

	/** @var Translate|null Language object */
	private $langs;

	/** @var int Date tolerance in days for matching */
	private $dateTolerance;

	/** @var string|null Last error message */
	private $error;

	/**
	 * Constructor
	 *
	 * @param DoliDb        $db            Database connection
	 * @param User          $user          User performing the reconciliation
	 * @param Translate|null $langs        Language object (optional)
	 * @param int           $dateTolerance Date tolerance in days (default: 1)
	 */
	public function __construct($db, $user, $langs = null, int $dateTolerance = 1)
	{
		$this->db = $db;
		$this->user = $user;
		$this->langs = $langs;
		$this->dateTolerance = $dateTolerance;
	}

	/**
	 * Process a CAMT.053 XML payload: parse, match and auto-reconcile unique matches.
	 *
	 * @param string $xmlContent Raw CAMT.053 XML
	 * @return array Structured summary (see keys below)
	 */
	public function processContent(string $xmlContent): array
	{
		$this->error = null;

		$summary = $this->emptySummary();

		$fileProcessor = new Camt053FileProcessor($this->db);
		if (!$fileProcessor->parseContent($xmlContent)) {
			$summary['success'] = false;
			$summary['error'] = $fileProcessor->getError() ?: 'Unable to parse CAMT.053 content';
			$this->error = $summary['error'];
			return $summary;
		}

		// Statements whose IBAN could not be matched to a Dolibarr account: report, never reconcile.
		foreach ($fileProcessor->getStatements() as $statement) {
			if ($statement->getAccountId() === null) {
				$iban = $statement->getIban();
				$summary['unresolved_ibans'][$iban] = ($summary['unresolved_ibans'][$iban] ?? 0) + $statement->getEntryCount();
			}
		}

		$fileStatements = $fileProcessor->getStatementsByAccountId();
		if (empty($fileStatements)) {
			return $summary;
		}

		// Derive the date window from the file entries (expanded by the tolerance).
		list($startDate, $endDate) = $this->dateRange($fileStatements);

		$dbLoader = new DatabaseBankStatementLoader($this->db, $this->langs);
		$dbStatements = $dbLoader->loadStatements($startDate, $endDate);

		$matcher = new BankStatementMatcher($this->dateTolerance);
		$banks = $matcher->compareMultiple($fileStatements, $dbStatements, $dbLoader);

		foreach ($banks as $accountId => $bank) {
			$results = $bank['results'];
			$fileStatement = $fileStatements[$accountId];
			$numReleve = $this->computeNumReleve($fileStatement);

			$account = array(
				'account_id' => (int) $accountId,
				'iban' => $fileStatement->getIban(),
				'num_releve' => $numReleve,
				'auto' => array(),
				'ambiguous' => array(),
				'unmatched' => array(),
				'already' => count($results['already_linked'] ?? array()),
				'errors' => array(),
			);

			// Auto-reconcile only the unique matches.
			foreach ($results['linkeds'] as $pair) {
				$dbEntry = $pair['db'];
				$bankLine = $dbEntry->getBankLine();
				$bankLineId = ($bankLine && !empty($bankLine->rowid)) ? (int) $bankLine->rowid : 0;
				$info = $this->entryInfo($pair['file']->getData(), $bankLineId);

				if ($bankLineId <= 0) {
					$account['errors'][] = $info + array('reason' => 'missing bank line');
					continue;
				}

				if ($this->reconcileLine($bankLineId, $numReleve)) {
					$account['auto'][] = $info;
				} else {
					$account['errors'][] = $info + array('reason' => $this->error ?: 'reconciliation failed');
				}
			}

			// Ambiguous: several non-reconciled candidates, left for manual handling.
			foreach ($results['multiples'] as $pair) {
				$account['ambiguous'][] = $this->entryInfo($pair['file']->getData(), 0);
			}

			// Unmatched file entries: no candidate at all.
			foreach ($results['unlinkeds'] as $entry) {
				if ($entry->isFromFile()) {
					$account['unmatched'][] = $this->entryInfo($entry->getData(), 0);
				}
			}

			$summary['accounts'][(int) $accountId] = $account;
			$summary['totals']['auto'] += count($account['auto']);
			$summary['totals']['ambiguous'] += count($account['ambiguous']);
			$summary['totals']['unmatched'] += count($account['unmatched']);
			$summary['totals']['errors'] += count($account['errors']);
		}

		return $summary;
	}

	/**
	 * Reconcile a single bank line.
	 *
	 * @param int    $bankLineId Bank line rowid
	 * @param string $numReleve  Statement reference (YYYYMM)
	 * @return bool True on success
	 */
	private function reconcileLine(int $bankLineId, string $numReleve): bool
	{
		$this->error = null;

		$obj = new AccountLine($this->db);
		if ($obj->fetch($bankLineId) <= 0) {
			$this->error = 'Bank line not found #' . $bankLineId;
			return false;
		}

		$obj->num_releve = $numReleve;
		if ($obj->update_conciliation($this->user, 0, 1) <= 0) {
			$this->error = !empty($obj->error) ? $obj->error : 'update_conciliation failed';
			dol_syslog('CAMT053 cron: failed to reconcile bank line #' . $bankLineId . ' - ' . $this->error, LOG_ERR);
			return false;
		}

		dol_syslog('CAMT053 cron: reconciled bank line #' . $bankLineId . ' num_releve=' . $numReleve, LOG_DEBUG);
		return true;
	}

	/**
	 * Build a normalized entry info row for the report.
	 *
	 * @param array $data       Entry data (amount, value_date, name, ...)
	 * @param int   $bankLineId Associated bank line id (0 if none)
	 * @return array
	 */
	private function entryInfo(array $data, int $bankLineId): array
	{
		return array(
			'amount' => isset($data['amount']) ? (float) $data['amount'] : 0.0,
			'date' => $data['value_date'] ?? '',
			'name' => trim(str_replace('<br />', ' ', (string) ($data['name'] ?? ''))),
			'bank_line_id' => $bankLineId,
		);
	}

	/**
	 * Compute the statement reference (YYYYMM) from the latest entry value date.
	 *
	 * @param Camt053Statement $statement File statement
	 * @return string Reference in YYYYMM format
	 */
	private function computeNumReleve(Camt053Statement $statement): string
	{
		$max = null;
		foreach ($statement->getEntries() as $entry) {
			$d = $this->toDate($entry->getValueDate());
			if ($d !== null && ($max === null || $d > $max)) {
				$max = $d;
			}
		}

		if ($max === null) {
			$creation = $statement->getCreationDate();
			$max = $this->toDate($creation) ?: new DateTime();
		}

		return $max->format('Ym');
	}

	/**
	 * Derive the [start, end] date window covering all file entries (± tolerance).
	 *
	 * @param array<int, Camt053Statement> $fileStatements File statements
	 * @return array{0: DateTime, 1: DateTime}
	 */
	private function dateRange(array $fileStatements): array
	{
		$min = null;
		$max = null;

		foreach ($fileStatements as $statement) {
			foreach ($statement->getEntries() as $entry) {
				$d = $this->toDate($entry->getValueDate());
				if ($d === null) {
					continue;
				}
				if ($min === null || $d < $min) {
					$min = clone $d;
				}
				if ($max === null || $d > $max) {
					$max = clone $d;
				}
			}
		}

		if ($min === null || $max === null) {
			$now = new DateTime();
			$min = (clone $now)->modify('first day of previous month');
			$max = (clone $now)->modify('last day of this month');
		}

		$tolerance = max(0, $this->dateTolerance);
		$min->modify('-' . $tolerance . ' day');
		$max->modify('+' . $tolerance . ' day');

		return array($min, $max);
	}

	/**
	 * Parse a date string into a DateTime (or null).
	 *
	 * @param string|null $value Date string
	 * @return DateTime|null
	 */
	private function toDate($value): ?DateTime
	{
		if (empty($value)) {
			return null;
		}
		try {
			return new DateTime($value);
		} catch (Exception $e) {
			return null;
		}
	}

	/**
	 * Build an empty summary structure.
	 *
	 * @return array
	 */
	private function emptySummary(): array
	{
		return array(
			'success' => true,
			'error' => null,
			'accounts' => array(),
			'unresolved_ibans' => array(),
			'totals' => array('auto' => 0, 'ambiguous' => 0, 'unmatched' => 0, 'errors' => 0),
		);
	}

	/**
	 * Get last error message.
	 *
	 * @return string|null
	 */
	public function getError(): ?string
	{
		return $this->error;
	}
}
