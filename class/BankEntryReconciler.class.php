<?php
/* Copyright (C) 2024 Slordef
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
 * \file       class/BankEntryReconciler.class.php
 * \ingroup    camt053readerandlink
 * \brief      Class for reconciling bank entries with statements
 */

/**
 * Class BankEntryReconciler
 *
 * Handles reconciliation of bank entries with statement references.
 */
class BankEntryReconciler
{
	/**
	 * @var DoliDb Database connection
	 */
	private $db;

	/**
	 * @var User Current user
	 */
	private $user;

	/**
	 * @var string|null Error message
	 */
	private $error;

	/**
	 * @var array Errors from batch operations
	 */
	private $errors = array();

	/**
	 * @var int Number of successful reconciliations
	 */
	private $reconcileCount = 0;

	/**
	 * Constructor
	 *
	 * @param DoliDb $db   Database connection
	 * @param User   $user Current user
	 */
	public function __construct($db, $user)
	{
		$this->db = $db;
		$this->user = $user;
	}

	/**
	 * Reconcile a single bank line
	 *
	 * @param int    $bankLineId   Bank line ID
	 * @param string $statementRef Statement reference (e.g., YYYYMM format)
	 * @return bool True on success, false on error
	 */
	public function reconcile(int $bankLineId, string $statementRef): bool
	{
		$this->error = null;

		if ($bankLineId <= 0) {
			$this->error = 'Invalid bank line ID';
			return false;
		}

		if (empty($statementRef)) {
			$this->error = 'Statement reference is required';
			return false;
		}

		$bankLine = new AccountLine($this->db);
		$result = $bankLine->fetch($bankLineId);

		if ($result <= 0) {
			$this->error = 'Bank line not found: ' . $bankLineId;
			return false;
		}

		// Set statement reference and reconcile
		$bankLine->num_releve = $statementRef;

		$result = $bankLine->update_conciliation($this->user, 0, 1);
		if ($result < 0) {
			$this->error = 'Failed to reconcile bank line: ' . ($bankLine->error ?? 'Unknown error');
			return false;
		}

		return true;
	}

	/**
	 * Reconcile multiple bank lines in batch
	 *
	 * @param array  $linkedPairs  Array of linked pairs with 'hash' => bankLineId
	 * @param string $statementRef Statement reference (e.g., YYYYMM format)
	 * @return int Number of successfully reconciled entries
	 */
	public function reconcileMultiple(array $linkedPairs, string $statementRef): int
	{
		$this->errors = array();
		$this->reconcileCount = 0;

		foreach ($linkedPairs as $hash => $bankLineId) {
			if (empty($bankLineId) || $bankLineId == 0) {
				continue;
			}

			if ($this->reconcile((int) $bankLineId, $statementRef)) {
				$this->reconcileCount++;
			} else {
				$this->errors[$hash] = $this->error;
			}
		}

		return $this->reconcileCount;
	}

	/**
	 * Reconcile from linked array with multiple select handling
	 *
	 * @param array  $linked       Array of linked pairs ('hash' => bankLineId)
	 * @param array  $postData     POST data containing linked_* fields for multiples
	 * @param string $statementRef Statement reference
	 * @return int Number of successfully reconciled entries
	 */
	public function reconcileFromPost(array $linked, array $postData, string $statementRef): int
	{
		$this->errors = array();
		$this->reconcileCount = 0;

		// Process standard linked entries
		foreach ($linked as $hash => $bankLineId) {
			if (empty($bankLineId) || $bankLineId == 0) {
				continue;
			}

			if ($this->reconcile((int) $bankLineId, $statementRef)) {
				$this->reconcileCount++;
			} else {
				$this->errors[$hash] = $this->error;
			}
		}

		// Process multiple select entries (linked_HASH keys in POST)
		foreach ($postData as $key => $value) {
			if (preg_match('/^linked_(.+)$/', $key, $matches)) {
				$hash = $matches[1];
				$bankLineId = $value;

				if (empty($bankLineId) || $bankLineId == 0) {
					continue;
				}

				if ($this->reconcile((int) $bankLineId, $statementRef)) {
					$this->reconcileCount++;
				} else {
					$this->errors[$hash] = $this->error;
				}
			}
		}

		return $this->reconcileCount;
	}

	/**
	 * Generate statement reference from date
	 *
	 * @param DateTime|string $endDate End date of the statement period
	 * @return string Statement reference in YYYYMM format
	 */
	public function generateStatementRef($endDate): string
	{
		if ($endDate instanceof DateTime) {
			return $endDate->format('Ym');
		}

		// Try d/m/Y format
		$dateObj = DateTime::createFromFormat('d/m/Y', $endDate);
		if ($dateObj !== false) {
			return $dateObj->format('Ym');
		}

		// Try Y-m-d format
		$dateObj = DateTime::createFromFormat('Y-m-d', $endDate);
		if ($dateObj !== false) {
			return $dateObj->format('Ym');
		}

		// Try general parsing
		try {
			$dateObj = new DateTime($endDate);
			return $dateObj->format('Ym');
		} catch (Exception $e) {
			return date('Ym');
		}
	}

	/**
	 * Get error message
	 *
	 * @return string|null
	 */
	public function getError(): ?string
	{
		return $this->error;
	}

	/**
	 * Get all errors from batch operation
	 *
	 * @return array
	 */
	public function getErrors(): array
	{
		return $this->errors;
	}

	/**
	 * Get count of successful reconciliations
	 *
	 * @return int
	 */
	public function getReconcileCount(): int
	{
		return $this->reconcileCount;
	}

	/**
	 * Check if a bank line is already reconciled
	 *
	 * @param int $bankLineId Bank line ID
	 * @return bool True if already reconciled
	 */
	public function isReconciled(int $bankLineId): bool
	{
		$bankLine = new AccountLine($this->db);
		$result = $bankLine->fetch($bankLineId);

		if ($result <= 0) {
			return false;
		}

		return $bankLine->rappro == 1;
	}

	/**
	 * Cancel reconciliation for a bank line
	 *
	 * @param int $bankLineId Bank line ID
	 * @return bool True on success
	 */
	public function cancelReconciliation(int $bankLineId): bool
	{
		$this->error = null;

		$bankLine = new AccountLine($this->db);
		$result = $bankLine->fetch($bankLineId);

		if ($result <= 0) {
			$this->error = 'Bank line not found: ' . $bankLineId;
			return false;
		}

		// Clear reconciliation
		$bankLine->rappro = 0;
		$bankLine->num_releve = '';

		$result = $bankLine->update($this->user);
		if ($result < 0) {
			$this->error = 'Failed to cancel reconciliation: ' . ($bankLine->error ?? 'Unknown error');
			return false;
		}

		return true;
	}
}
