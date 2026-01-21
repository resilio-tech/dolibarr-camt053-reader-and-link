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
 * \file       class/Camt053FileProcessor.class.php
 * \ingroup    camt053readerandlink
 * \brief      Secure XML parser for CAMT.053 files with XXE protection
 */

require_once __DIR__ . '/Camt053Entry.class.php';
require_once __DIR__ . '/Camt053Statement.class.php';

/**
 * Class Camt053FileProcessor
 *
 * Securely parses CAMT.053 XML files with XXE protection.
 */
class Camt053FileProcessor
{
	/**
	 * @var DoliDb Database connection
	 */
	private $db;

	/**
	 * @var array Parsed XML structure
	 */
	private $structure;

	/**
	 * @var Camt053Statement[] Extracted statements
	 */
	private $statements = array();

	/**
	 * @var string|null Error message
	 */
	private $error;

	/**
	 * Constructor
	 *
	 * @param DoliDb $db Database connection
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * Parse a CAMT.053 XML file with XXE protection
	 *
	 * @param string $filepath Path to the XML file
	 * @return bool True on success, false on error
	 */
	public function parseFile(string $filepath): bool
	{
		$this->error = null;
		$this->structure = null;
		$this->statements = array();

		// Check file exists
		if (!file_exists($filepath)) {
			$this->error = 'File not found: ' . $filepath;
			return false;
		}

		// Read file content
		$xmlContent = file_get_contents($filepath);
		if ($xmlContent === false) {
			$this->error = 'Unable to read file: ' . $filepath;
			return false;
		}

		return $this->parseContent($xmlContent);
	}

	/**
	 * Parse XML content string with XXE protection
	 *
	 * @param string $xmlContent XML content to parse
	 * @return bool True on success, false on error
	 */
	public function parseContent(string $xmlContent): bool
	{
		$this->error = null;
		$this->structure = null;
		$this->statements = array();

		// XXE Protection: Check for external entity declarations
		if (preg_match('/<!ENTITY/i', $xmlContent)) {
			$this->error = 'XML with external entities not allowed for security reasons';
			return false;
		}

		// XXE Protection: Disable external entity loading for older PHP versions
		$previousValue = null;
		if (LIBXML_VERSION < 20900) {
			$previousValue = libxml_disable_entity_loader(true);
		}

		// Use internal errors to capture libxml errors
		$previousUseErrors = libxml_use_internal_errors(true);

		try {
			// Parse XML with security flags
			$xml = simplexml_load_string(
				$xmlContent,
				'SimpleXMLElement',
				LIBXML_NOENT | LIBXML_NOCDATA | LIBXML_NONET
			);

			if ($xml === false) {
				$errors = libxml_get_errors();
				$errorMsg = 'XML parsing error';
				if (!empty($errors)) {
					$errorMsg .= ': ' . $errors[0]->message;
				}
				libxml_clear_errors();
				$this->error = $errorMsg;
				return false;
			}

			// Convert to array structure
			$this->structure = json_decode(json_encode($xml), true);

			// Extract statements
			$this->extractStatements();

			return true;
		} finally {
			// Restore previous settings
			libxml_use_internal_errors($previousUseErrors);
			if ($previousValue !== null && LIBXML_VERSION < 20900) {
				libxml_disable_entity_loader($previousValue);
			}
		}
	}

	/**
	 * Parse from a pre-parsed structure (e.g., from JSON)
	 *
	 * @param array $structure Pre-parsed XML structure
	 * @return bool True on success, false on error
	 */
	public function parseStructure(array $structure): bool
	{
		$this->error = null;
		$this->structure = $structure;
		$this->statements = array();

		try {
			$this->extractStatements();
			return true;
		} catch (Exception $e) {
			$this->error = $e->getMessage();
			return false;
		}
	}

	/**
	 * Extract statements from parsed structure
	 *
	 * @return void
	 * @throws Exception If structure is invalid
	 */
	private function extractStatements(): void
	{
		if (empty($this->structure)) {
			throw new Exception('No XML structure to process');
		}

		$stmts = $this->getArrayValue($this->structure, array('BkToCstmrStmt', 'Stmt'));
		if ($stmts === null) {
			throw new Exception('Invalid CAMT.053 structure: missing BkToCstmrStmt/Stmt');
		}

		// Handle single statement (convert to array of one)
		if (isset($stmts['Ntry'])) {
			$stmts = array($stmts);
		}

		foreach ($stmts as $stmt) {
			$statement = $this->extractSingleStatement($stmt);
			if ($statement !== null) {
				$this->statements[] = $statement;
			}
		}
	}

	/**
	 * Extract a single statement from XML structure
	 *
	 * @param array $stmt Statement structure
	 * @return Camt053Statement|null
	 */
	private function extractSingleStatement(array $stmt): ?Camt053Statement
	{
		// Get IBAN
		$iban = $this->getArrayValue($stmt, array('Acct', 'Id', 'IBAN'));
		if (empty($iban)) {
			return null;
		}

		// Format IBAN
		$formattedIban = $this->formatIban($iban);

		// Find matching Dolibarr bank account
		$accountId = $this->findAccountByIban($iban);

		// Create statement
		$statement = new Camt053Statement($formattedIban, $accountId);
		$statement->setIsFromFile(true);

		// Get creation date from header if available
		$creationDate = $this->getArrayValue($this->structure, array('BkToCstmrStmt', 'GrpHdr', 'CreDtTm'));
		if ($creationDate) {
			$statement->setCreationDate($creationDate);
		}

		// Get entries
		$entries = $this->getArrayValue($stmt, array('Ntry'));
		if (!empty($entries)) {
			// Handle single entry (convert to array)
			if (isset($entries['CdtDbtInd'])) {
				$entries = array($entries);
			}

			foreach ($entries as $entry) {
				$camt053Entry = $this->extractEntry($entry);
				if ($camt053Entry !== null) {
					$statement->addEntry($camt053Entry);
				}
			}
		}

		return $statement;
	}

	/**
	 * Extract a single entry from XML structure
	 *
	 * @param array $entry Entry structure
	 * @return Camt053Entry|null
	 */
	private function extractEntry(array $entry): ?Camt053Entry
	{
		// Get amount
		$amount = isset($entry['Amt']) ? (float) $entry['Amt'] : 0.0;

		// Get debit/credit indicator and adjust amount sign
		$type = $entry['CdtDbtInd'] ?? '';
		if ($type === 'DBIT') {
			$amount = -abs($amount);
		} else {
			$amount = abs($amount);
		}

		// Get value date
		$valueDateStr = $this->getArrayValue($entry, array('ValDt', 'Dt'));
		if (empty($valueDateStr)) {
			$valueDateStr = $this->getArrayValue($entry, array('BookgDt', 'Dt'));
		}

		$valueDate = '';
		if (!empty($valueDateStr)) {
			try {
				$dateObj = new DateTime($valueDateStr);
				$valueDate = $dateObj->format('Y-m-d');
			} catch (Exception $e) {
				$valueDate = '';
			}
		}

		// Get hash (account service reference)
		$hash = $this->getArrayValue($entry, array('AcctSvcrRef'));

		// Determine type name for party lookup
		$typeNm = ($type === 'DBIT') ? 'Dbtr' : 'Cdtr';

		// Build name from various fields
		$name = '';

		// Try unstructured remittance info
		$name1 = $this->getArrayValue($entry, array('NtryDtls', 'TxDtls', 'RmtInf', 'Ustrd'));
		if (is_array($name1)) {
			$name1 = implode(' ', $name1);
		}
		if (!empty($name1)) {
			$name .= $name1;
		}

		// Try related party name
		$name2 = $this->getArrayValue($entry, array('NtryDtls', 'TxDtls', 'RltdPties', $typeNm, 'Nm'));
		if (!empty($name2)) {
			$name .= (!empty($name) ? '<br />' : '') . $name2;
		}

		// Build additional info
		$info = '';
		$addtlNtryInf = $this->getArrayValue($entry, array('AddtlNtryInf'));
		if (!empty($addtlNtryInf)) {
			// Split on COMMUNICATIONS and REFERENCES for readability
			$addtlNtryInf = str_replace('COMMUNICATIONS', '<br />COMMUNICATIONS', $addtlNtryInf);
			$addtlNtryInf = str_replace('REFERENCES', '<br />REFERENCES', $addtlNtryInf);
			$info .= $addtlNtryInf;
		}

		$addtlTxInf = $this->getArrayValue($entry, array('NtryDtls', 'TxDtls', 'AddtlTxInf'));
		if (!empty($addtlTxInf)) {
			$info .= (!empty($info) ? '<br />' : '') . $addtlTxInf;
		}

		return new Camt053Entry($amount, $valueDate, $name, $info, $hash);
	}

	/**
	 * Find Dolibarr bank account by IBAN
	 *
	 * @param string $iban IBAN to search for
	 * @return int|null Account ID or null if not found
	 */
	private function findAccountByIban(string $iban): ?int
	{
		// Skip database lookup if not in Dolibarr context
		if (!defined('MAIN_DB_PREFIX') || $this->db === null) {
			return null;
		}

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
	 * Safely get nested array value
	 *
	 * @param array      $array      Source array
	 * @param array      $keys       Array of keys to traverse
	 * @param mixed|null $default    Default value if not found
	 * @return mixed
	 */
	private function getArrayValue(array $array, array $keys, $default = null)
	{
		foreach ($keys as $key) {
			if (!is_array($array) || !array_key_exists($key, $array)) {
				return $default;
			}
			$array = $array[$key];
		}
		return $array;
	}

	/**
	 * Get parsed structure
	 *
	 * @return array|null
	 */
	public function getStructure(): ?array
	{
		return $this->structure;
	}

	/**
	 * Get extracted statements
	 *
	 * @return Camt053Statement[]
	 */
	public function getStatements(): array
	{
		return $this->statements;
	}

	/**
	 * Get statements indexed by account ID
	 *
	 * @return array<int, Camt053Statement>
	 */
	public function getStatementsByAccountId(): array
	{
		$result = array();
		foreach ($this->statements as $statement) {
			$accountId = $statement->getAccountId();
			if ($accountId !== null) {
				$result[$accountId] = $statement;
			}
		}
		return $result;
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
	 * Get creation date from parsed structure
	 *
	 * @return string|null
	 */
	public function getCreationDate(): ?string
	{
		if (empty($this->structure)) {
			return null;
		}
		return $this->getArrayValue($this->structure, array('BkToCstmrStmt', 'GrpHdr', 'CreDtTm'));
	}
}
