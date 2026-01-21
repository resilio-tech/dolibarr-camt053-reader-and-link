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
 * \file       class/DatabaseBankStatementLoader.class.php
 * \ingroup    camt053readerandlink
 * \brief      Class for loading bank statement entries from database
 */

require_once __DIR__ . '/Camt053Entry.class.php';
require_once __DIR__ . '/Camt053Statement.class.php';

/**
 * Class DatabaseBankStatementLoader
 *
 * Loads bank statement entries from Dolibarr database with secure SQL.
 */
class DatabaseBankStatementLoader
{
	/**
	 * @var DoliDb Database connection
	 */
	private $db;

	/**
	 * @var Translate Language object
	 */
	private $langs;

	/**
	 * @var string|null Error message
	 */
	private $error;

	/**
	 * Constructor
	 *
	 * @param DoliDb    $db    Database connection
	 * @param Translate $langs Language object (optional)
	 */
	public function __construct($db, $langs = null)
	{
		$this->db = $db;
		$this->langs = $langs;
	}

	/**
	 * Load bank statements from database for a date range
	 *
	 * @param DateTime|string $startDate Start date
	 * @param DateTime|string $endDate   End date
	 * @param int|null        $accountId Optional: limit to specific bank account
	 * @return array<int, Camt053Statement> Statements indexed by account ID
	 */
	public function loadStatements($startDate, $endDate, ?int $accountId = null): array
	{
		$this->error = null;

		// Normalize dates
		$startDateStr = $this->formatDateForSql($startDate);
		$endDateStr = $this->formatDateForSql($endDate);

		if (empty($startDateStr) || empty($endDateStr)) {
			$this->error = 'Invalid date format';
			return array();
		}

		// Build secure SQL query
		$sql = "SELECT rowid FROM " . MAIN_DB_PREFIX . "bank ";
		$sql .= "WHERE datev >= DATE('" . $this->db->escape($startDateStr) . "') ";
		$sql .= "AND datev <= DATE('" . $this->db->escape($endDateStr) . "') ";

		if ($accountId !== null) {
			$sql .= "AND fk_account = " . ((int) $accountId) . " ";
		}

		$sql .= "ORDER BY datev ASC";

		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = 'Database error: ' . $this->db->lasterror();
			return array();
		}

		// Group entries by account
		$statements = array();
		$bankAccount = new Account($this->db);

		while ($obj = $this->db->fetch_object($resql)) {
			$bankLine = new AccountLine($this->db);
			$bankLine->fetch($obj->rowid);

			if (empty($bankLine->datev)) {
				continue;
			}

			$fkAccount = (int) $bankLine->fk_account;

			// Create statement for this account if not exists
			if (!isset($statements[$fkAccount])) {
				try {
					$accountInfo = $this->getDbBank($fkAccount);
					$iban = $accountInfo->iban_prefix ?? '';
				} catch (Exception $e) {
					$iban = '';
				}

				$statements[$fkAccount] = new Camt053Statement($iban, $fkAccount);
				$statements[$fkAccount]->setIsFromFile(false);
			}

			// Get bank links for additional info
			$bankLinks = $bankAccount->get_url($bankLine->id);

			// Build entry data
			$amount = (float) $bankLine->amount;
			$valueDate = $this->formatDateValue($bankLine->datev);
			$name = $this->buildEntryName($bankLine, $bankLinks);

			// Create and add entry
			$entry = new Camt053Entry($amount, $valueDate, $name);
			$entry->setBankLine($bankLine);
			$entry->setIsFromFile(false);

			$statements[$fkAccount]->addEntry($entry);
		}

		return $statements;
	}

	/**
	 * Load statements as flat data array (legacy format)
	 *
	 * @param DateTime|string $startDate Start date
	 * @param DateTime|string $endDate   End date
	 * @return array Array of entry data with bank_obj
	 */
	public function loadFlatData($startDate, $endDate): array
	{
		$this->error = null;

		// Normalize dates
		$startDateStr = $this->formatDateForSql($startDate);
		$endDateStr = $this->formatDateForSql($endDate);

		if (empty($startDateStr) || empty($endDateStr)) {
			$this->error = 'Invalid date format';
			return array();
		}

		// Build secure SQL query
		$sql = "SELECT rowid FROM " . MAIN_DB_PREFIX . "bank ";
		$sql .= "WHERE datev >= DATE('" . $this->db->escape($startDateStr) . "') ";
		$sql .= "AND datev <= DATE('" . $this->db->escape($endDateStr) . "') ";
		$sql .= "ORDER BY datev ASC";

		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = 'Database error: ' . $this->db->lasterror();
			return array();
		}

		$data = array();
		$bankAccount = new Account($this->db);

		while ($obj = $this->db->fetch_object($resql)) {
			$bankLine = new AccountLine($this->db);
			$bankLine->fetch($obj->rowid);

			if (empty($bankLine->datev)) {
				continue;
			}

			$bankLinks = $bankAccount->get_url($bankLine->id);

			$amount = (float) $bankLine->amount;
			$valueDate = $this->formatDateValue($bankLine->datev);
			$name = $this->buildEntryName($bankLine, $bankLinks);

			$data[] = array(
				'amount' => $amount,
				'value_date' => $valueDate,
				'name' => $name,
				'bank_obj' => $bankLine
			);
		}

		return $data;
	}

	/**
	 * Get bank account by ID with secure SQL
	 *
	 * @param int $bankId Bank account ID
	 * @return object Bank account data
	 * @throws Exception If bank not found
	 */
	public function getDbBank(int $bankId): object
	{
		$sql = "SELECT rowid, iban_prefix FROM " . MAIN_DB_PREFIX . "bank_account ";
		$sql .= "WHERE rowid = " . ((int) $bankId);

		$resql = $this->db->query($sql);
		if (!$resql) {
			throw new Exception('Error while getting the bank account for ID ' . $bankId);
		}

		$bank = $this->db->fetch_object($resql);
		if (!$bank) {
			throw new Exception('Bank account not found for ID ' . $bankId);
		}

		// Format IBAN
		if (!empty($bank->iban_prefix)) {
			$bank->iban_prefix = $this->formatIban($bank->iban_prefix);
		}

		return $bank;
	}

	/**
	 * Get account ID by IBAN with secure SQL
	 *
	 * @param string $iban IBAN to search for
	 * @return int|null Account ID or null if not found
	 */
	public function getAccountIdByIban(string $iban): ?int
	{
		$ibanNoSpace = str_replace(' ', '', $iban);
		$ibanWithSpace = $this->formatIban($iban);

		$sql = "SELECT rowid FROM " . MAIN_DB_PREFIX . "bank_account ";
		$sql .= "WHERE iban_prefix = '" . $this->db->escape($ibanWithSpace) . "' ";
		$sql .= "OR iban_prefix = '" . $this->db->escape($ibanNoSpace) . "'";

		$resql = $this->db->query($sql);
		if ($resql) {
			$obj = $this->db->fetch_object($resql);
			if ($obj) {
				return (int) $obj->rowid;
			}
		}

		return null;
	}

	/**
	 * Format IBAN with spaces
	 *
	 * @param string $iban Raw IBAN
	 * @return string Formatted IBAN
	 */
	private function formatIban(string $iban): string
	{
		$iban = str_replace(' ', '', $iban);
		return trim(chunk_split($iban, 4, ' '));
	}

	/**
	 * Format date for SQL query
	 *
	 * @param DateTime|string $date Date to format
	 * @return string Date in Y-m-d format
	 */
	private function formatDateForSql($date): string
	{
		if ($date instanceof DateTime) {
			return $date->format('Y-m-d');
		}

		if (is_string($date)) {
			// Try d/m/Y format (French format)
			$dateObj = DateTime::createFromFormat('d/m/Y', $date);
			if ($dateObj !== false) {
				return $dateObj->format('Y-m-d');
			}

			// Try Y-m-d format
			$dateObj = DateTime::createFromFormat('Y-m-d', $date);
			if ($dateObj !== false) {
				return $dateObj->format('Y-m-d');
			}

			// Try to parse as general date
			try {
				$dateObj = new DateTime($date);
				return $dateObj->format('Y-m-d');
			} catch (Exception $e) {
				return '';
			}
		}

		return '';
	}

	/**
	 * Format date value from database
	 *
	 * @param mixed $datev Date value (timestamp or string)
	 * @return string Date in Y-m-d format
	 */
	private function formatDateValue($datev): string
	{
		if (is_numeric($datev)) {
			$dateObj = new DateTime();
			$dateObj->setTimestamp((int) $datev);
			return $dateObj->format('Y-m-d');
		}

		if (is_string($datev)) {
			try {
				$dateObj = new DateTime($datev);
				return $dateObj->format('Y-m-d');
			} catch (Exception $e) {
				return '';
			}
		}

		return '';
	}

	/**
	 * Build entry name from bank line and links
	 *
	 * @param AccountLine $bankLine  Bank line object
	 * @param array       $bankLinks Bank links from get_url()
	 * @return string Entry name
	 */
	private function buildEntryName(object $bankLine, array $bankLinks): string
	{
		$name = $bankLine->label ?? '';

		// Try to translate label if it's a translation key
		if ($this->langs !== null) {
			$reg = array();
			if (preg_match('/\((.+)\)/i', $name, $reg)) {
				if (!empty($reg[1]) && $this->langs->trans($reg[1]) !== $reg[1]) {
					$name = $this->langs->trans($reg[1]);
				}
			} elseif ($name === '(payment_salary)') {
				$name = $this->langs->trans('SalaryPayment');
			}
		}

		// Escape HTML
		$name = dol_escape_htmltag($name);

		// Add bank link label if present
		if (!empty($bankLinks[1]['label'])) {
			$name .= ' - ' . dol_escape_htmltag($bankLinks[1]['label']);
		}

		return $name;
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
	 * Validate date format (d/m/Y)
	 *
	 * @param string $date Date string
	 * @return bool True if valid
	 */
	public function validateDateFormat(string $date): bool
	{
		$dateObj = DateTime::createFromFormat('d/m/Y', $date);
		return $dateObj !== false && $dateObj->format('d/m/Y') === $date;
	}
}
