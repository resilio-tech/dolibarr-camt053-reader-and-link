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
 * \brief      Secure XML parser for CAMT.053 statements and CAMT.052 intraday
 *             reports, with XXE protection
 */

require_once __DIR__ . '/Camt053Entry.class.php';
require_once __DIR__ . '/Camt053Statement.class.php';

/**
 * Class Camt053FileProcessor
 *
 * Securely parses CAMT.053 and CAMT.052 XML files with XXE protection.
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
	 * @var array<string,string> Entry currency indexed by AcctSvcrRef.
	 *      json_encode() drops XML attributes, so the per-entry Amt@Ccy is
	 *      captured directly from SimpleXML and looked up here during extraction.
	 */
	private $entryCurrencyByRef = array();

	/**
	 * @var array<string,string> Document roots this parser reads, and the tag
	 *      holding one account inside each. camt.053 delivers final statements,
	 *      camt.052 the intraday report, and both carry the same Acct and Ntry
	 *      building blocks below that root.
	 */
	private static $documentRoots = array(
		'BkToCstmrStmt' => 'Stmt',
		'BkToCstmrAcctRpt' => 'Rpt',
	);

	/**
	 * @var string Root tag of the parsed document, empty before a parse
	 */
	private $documentRoot = '';

	/**
	 * @var int Entries left out of an intraday report because they are not booked
	 */
	private $pendingEntryCount = 0;

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

			// Capture per-entry currency from attributes (lost by json_encode)
			$this->entryCurrencyByRef = $this->buildCurrencyMap($xml);

			// Extract statements
			$this->extractStatements();

			return true;
		} catch (Throwable $e) {
			// extractStatements() throws on anything that is not a CAMT.053
			// document. parseStructure() already reports that as a false return;
			// letting it escape here killed the whole cron run (and with it the
			// SFTP disconnect and the run status) on the first stray XML file
			// sitting in the remote directory.
			$this->error = $e->getMessage();
			return false;
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
		// No SimpleXML here: entries fall back to the statement-level currency.
		$this->entryCurrencyByRef = array();

		try {
			$this->extractStatements();
			return true;
		} catch (Throwable $e) {
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

		$this->documentRoot = '';
		$this->pendingEntryCount = 0;

		$stmts = null;
		foreach (self::$documentRoots as $root => $tag) {
			$stmts = $this->getArrayValue($this->structure, array($root, $tag));
			if ($stmts !== null) {
				$this->documentRoot = $root;
				break;
			}
		}

		if ($stmts === null) {
			throw new Exception('Invalid CAMT structure: missing BkToCstmrStmt/Stmt (camt.053) or BkToCstmrAcctRpt/Rpt (camt.052)');
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
	 * Build a map of AcctSvcrRef => entry currency from the raw SimpleXML.
	 *
	 * The per-entry currency lives in the Amt@Ccy attribute, which is dropped by
	 * json_encode(); we read it here so it can be looked up during extraction.
	 *
	 * @param SimpleXMLElement $xml Parsed CAMT.053 document
	 * @return array<string,string> Currency indexed by AcctSvcrRef
	 */
	private function buildCurrencyMap($xml): array
	{
		$map = array();

		foreach (self::$documentRoots as $root => $tag) {
			if (!isset($xml->{$root}->{$tag})) {
				continue;
			}

			foreach ($xml->{$root}->{$tag} as $stmt) {
				if (!isset($stmt->Ntry)) {
					continue;
				}
				foreach ($stmt->Ntry as $ntry) {
					$ref = isset($ntry->AcctSvcrRef) ? (string) $ntry->AcctSvcrRef : '';
					$ccy = isset($ntry->Amt) ? (string) $ntry->Amt['Ccy'] : '';
					if ($ref !== '' && $ccy !== '') {
						$map[$ref] = strtoupper($ccy);
					}
				}
			}

			break;
		}

		return $map;
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

		// Statement currency (fallback when an entry has no captured Amt@Ccy)
		$statementCcy = (string) $this->getArrayValue($stmt, array('Acct', 'Ccy'), '');

		// Create statement
		$statement = new Camt053Statement($formattedIban, $accountId);
		$statement->setIsFromFile(true);

		// Get creation date from header if available
		$creationDate = $this->getArrayValue($this->structure, array($this->documentRoot, 'GrpHdr', 'CreDtTm'));
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
				// An intraday report also lists movements the bank has not booked
				// yet and can still drop. Reconciling one would tie a Dolibarr
				// line to something that may never exist.
				if ($this->isIntradayReport() && !$this->isBookedEntry($entry)) {
					$this->pendingEntryCount++;
					continue;
				}

				foreach ($this->extractEntriesFromNtry($entry, $statementCcy, $iban) as $camt053Entry) {
					$statement->addEntry($camt053Entry);
				}
			}
		}

		return $statement;
	}

	/**
	 * Extract one or more entries from a single <Ntry>.
	 *
	 * A collective (batch) booking carries the group total in <Ntry><Amt> and the
	 * individual movements in <NtryDtls> as several <TxDtls>, each with its own
	 * <Amt>. Banks book a salary run this way: one debit for the whole transfer,
	 * one <TxDtls> per employee. Dolibarr, on the other hand, records one bank
	 * line per salary, so the collective entry must be split into one sub-entry
	 * per transaction for the 1:1 matcher to reconcile each line; left whole, the
	 * group total matches no single line and stays unreconciled.
	 *
	 * A non-collective entry (0 or 1 detailed transaction) is returned unchanged.
	 * The split is only kept when the per-transaction amounts reconstruct the
	 * group total; otherwise (partial detail, missing amounts) the entry is left
	 * whole so its total can still be listed and reconciled or suggested.
	 *
	 * @param array  $entry        <Ntry> structure
	 * @param string $statementCcy Statement-level currency (fallback)
	 * @param string $ownIban      Statement account IBAN (to exclude from counterparty)
	 * @return Camt053Entry[]
	 */
	private function extractEntriesFromNtry(array $entry, string $statementCcy, string $ownIban): array
	{
		$txList = $this->getArrayValue($entry, array('NtryDtls', 'TxDtls'));

		// Normalise to a list of TxDtls: a single <TxDtls> is an associative array,
		// several come back numerically indexed.
		if (is_array($txList) && isset($txList[0])) {
			$transactions = $txList;
		} elseif (is_array($txList) && !empty($txList)) {
			$transactions = array($txList);
		} else {
			$transactions = array();
		}

		// Only a genuine collective booking (2+ detailed transactions) is split.
		if (count($transactions) >= 2) {
			$expanded = $this->expandCollectiveEntry($entry, $transactions, $statementCcy, $ownIban);
			if ($expanded !== null) {
				return $expanded;
			}
		}

		$single = $this->extractEntry($entry, $statementCcy, $ownIban);
		return $single !== null ? array($single) : array();
	}

	/**
	 * Split a collective <Ntry> into one entry per <TxDtls>.
	 *
	 * Each transaction is turned into a synthetic single-transaction <Ntry> so the
	 * existing extractEntry() handles the name, remittance info, counterparty and
	 * currency exactly as it does for a normal entry. The entry-level value date is
	 * inherited by every transaction (TxDtls rarely carries its own), and the
	 * per-transaction bank reference (Refs) keys the sub-entry so cross-block dedup
	 * and the reconciliation form stay stable.
	 *
	 * @param array  $entry        <Ntry> structure
	 * @param array  $transactions List of <TxDtls> structures (2 or more)
	 * @param string $statementCcy Statement-level currency (fallback)
	 * @param string $ownIban      Statement account IBAN
	 * @return Camt053Entry[]|null Sub-entries, or null when the split is unreliable
	 */
	private function expandCollectiveEntry(array $entry, array $transactions, string $statementCcy, string $ownIban): ?array
	{
		$entryType = isset($entry['CdtDbtInd']) ? (string) $entry['CdtDbtInd'] : '';
		$entryTotal = (isset($entry['Amt']) && is_scalar($entry['Amt'])) ? abs((float) $entry['Amt']) : 0.0;

		// Currency to inherit: the group entry keeps its Amt@Ccy in entryCurrencyByRef
		// (keyed by the group <AcctSvcrRef>). Sub-entries are rekeyed to their own
		// per-transaction reference, so that lookup would miss and leave them with an
		// empty currency when the statement itself carries no Acct/Ccy. Resolve the
		// group currency once here and pass it as the sub-entry fallback.
		$groupRef = (isset($entry['AcctSvcrRef']) && is_string($entry['AcctSvcrRef'])) ? $entry['AcctSvcrRef'] : '';
		$groupCcy = ($groupRef !== '' && isset($this->entryCurrencyByRef[$groupRef]))
			? $this->entryCurrencyByRef[$groupRef]
			: $statementCcy;

		$subEntries = array();
		$signedSum = 0.0;
		foreach ($transactions as $tx) {
			// A transaction without its own amount cannot be reconciled on its own;
			// give up on the split and keep the entry whole.
			if (!is_array($tx) || !isset($tx['Amt']) || !is_scalar($tx['Amt'])) {
				return null;
			}

			// Build a synthetic <Ntry> holding just this transaction.
			$pseudo = array(
				'Amt' => $tx['Amt'],
				'CdtDbtInd' => isset($tx['CdtDbtInd']) ? $tx['CdtDbtInd'] : $entryType,
				'NtryDtls' => array('TxDtls' => $tx),
			);
			if (isset($entry['ValDt'])) {
				$pseudo['ValDt'] = $entry['ValDt'];
			}
			if (isset($entry['BookgDt'])) {
				$pseudo['BookgDt'] = $entry['BookgDt'];
			}
			$reference = $this->transactionReference($tx);
			if ($reference !== '') {
				$pseudo['AcctSvcrRef'] = $reference;
			}

			$subEntry = $this->extractEntry($pseudo, $groupCcy, $ownIban);
			if ($subEntry === null) {
				return null;
			}
			$signedSum += $subEntry->getAmount();
			$subEntries[] = $subEntry;
		}

		// Guard: the detailed lines must reconstruct the group total. When they do
		// not (mixed signs the bank netted, partial detail), the split is unreliable
		// and the caller keeps the entry whole.
		if ($entryTotal > 0 && abs(abs($signedSum) - $entryTotal) > 0.01) {
			return null;
		}

		return $subEntries;
	}

	/**
	 * First usable transaction reference from a <TxDtls><Refs> block.
	 *
	 * @param array $tx <TxDtls> structure
	 * @return string Reference, or empty string when none is present
	 */
	private function transactionReference(array $tx): string
	{
		$paths = array(
			array('Refs', 'AcctSvcrRef'),
			array('Refs', 'EndToEndId'),
			array('Refs', 'InstrId'),
			array('Refs', 'TxId'),
		);
		foreach ($paths as $path) {
			$value = $this->getArrayValue($tx, $path);
			if (is_string($value) && $value !== '') {
				return $value;
			}
		}
		return '';
	}

	/**
	 * Extract a single entry from XML structure
	 *
	 * @param array  $entry        Entry structure
	 * @param string $statementCcy Statement-level currency (fallback)
	 * @param string $ownIban      Statement account IBAN (to exclude from counterparty)
	 * @return Camt053Entry|null
	 */
	private function extractEntry(array $entry, string $statementCcy = '', string $ownIban = ''): ?Camt053Entry
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

		// Get hash (account service reference). A self-closed <AcctSvcrRef/> comes
		// back as an empty array from the SimpleXML round trip, which the entry
		// constructor would reject: normalise anything but a usable string to
		// null so the entry falls back to its content hash.
		$hash = $this->getArrayValue($entry, array('AcctSvcrRef'));
		$hash = (is_string($hash) && $hash !== '') ? $hash : null;

		// Counterparty party order for the name lookup. For a debit the beneficiary
		// is the creditor, for a credit the payer is the debtor, matching the ISO
		// orientation used for the counterparty IBAN below. The opposite tag is a
		// fallback so a file that fills only the account-owner side still yields a
		// name (as some banks do for outgoing payments).
		$partyOrder = ($type === 'DBIT') ? array('Cdtr', 'Dbtr') : array('Dbtr', 'Cdtr');

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

		// Try related party name (ISO-correct counterparty first, then the other tag).
		// A self-closed <Nm/> comes back as an empty array from the SimpleXML round
		// trip, so only a non-empty string is accepted (concatenating an array would
		// raise an "Array to string conversion" warning).
		$name2 = '';
		foreach ($partyOrder as $partyTag) {
			$candidate = $this->getArrayValue($entry, array('NtryDtls', 'TxDtls', 'RltdPties', $partyTag, 'Nm'));
			if (is_string($candidate) && $candidate !== '') {
				$name2 = $candidate;
				break;
			}
		}
		if ($name2 !== '') {
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

		$camt053Entry = new Camt053Entry($amount, $valueDate, $name, $info, $hash);

		// Currency: per-entry Amt@Ccy captured from SimpleXML, else statement currency.
		$ref = (string) $hash;
		$currency = ($ref !== '' && isset($this->entryCurrencyByRef[$ref]))
			? $this->entryCurrencyByRef[$ref]
			: $statementCcy;
		$camt053Entry->setCurrency((string) $currency);

		// Counterparty IBAN (ISO: debit -> creditor account, credit -> debtor
		// account), falling back to the other tag and never our own account.
		$cdtrIban = (string) $this->getArrayValue($entry, array('NtryDtls', 'TxDtls', 'RltdPties', 'CdtrAcct', 'Id', 'IBAN'), '');
		$dbtrIban = (string) $this->getArrayValue($entry, array('NtryDtls', 'TxDtls', 'RltdPties', 'DbtrAcct', 'Id', 'IBAN'), '');
		$ownNoSpace = strtoupper(str_replace(' ', '', $ownIban));
		$candidates = ($type === 'DBIT') ? array($cdtrIban, $dbtrIban) : array($dbtrIban, $cdtrIban);
		foreach ($candidates as $cand) {
			$candNoSpace = strtoupper(str_replace(' ', '', $cand));
			if ($candNoSpace !== '' && $candNoSpace !== $ownNoSpace) {
				$camt053Entry->setCounterpartyIban($cand);
				break;
			}
		}

		return $camt053Entry;
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
		$sql .= "WHERE (iban_prefix = '" . $this->db->escape($ibanWithSpace) . "' ";
		$sql .= "OR iban_prefix = '" . $this->db->escape($ibanNoSpace) . "') ";
		$sql .= "AND entity IN (" . getEntity('bank_account', 0) . ")";

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
	 * Whether the parsed document is an intraday report (camt.052) rather than
	 * a final statement (camt.053).
	 *
	 * @return bool
	 */
	public function isIntradayReport(): bool
	{
		return ($this->documentRoot === 'BkToCstmrAcctRpt');
	}

	/**
	 * How many entries an intraday report carried that were not booked yet.
	 *
	 * They are deliberately dropped, and silently dropping data is what this
	 * counter exists to prevent: the caller reports it.
	 *
	 * @return int
	 */
	public function getPendingEntryCount(): int
	{
		return $this->pendingEntryCount;
	}

	/**
	 * Whether an <Ntry> is booked.
	 *
	 * The status is a plain code in camt.05x.001.02 and a <Cd> child from .06
	 * onwards. Only an explicit BOOK counts: an unreadable or proprietary status
	 * on an intraday report is treated as pending, because reconciling a
	 * movement the bank has not committed to is the costly mistake here.
	 *
	 * @param array $entry <Ntry> structure
	 * @return bool
	 */
	private function isBookedEntry(array $entry): bool
	{
		$status = $this->getArrayValue($entry, array('Sts', 'Cd'));
		if (!is_string($status) || trim($status) === '') {
			$status = $this->getArrayValue($entry, array('Sts'));
		}

		return (is_string($status) && strtoupper(trim($status)) === 'BOOK');
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
	 * Get statements indexed by account ID.
	 *
	 * A CAMT.053 document may carry several <Stmt> blocks for the same account
	 * (monthly bundles routinely do). Their entries are merged into one statement
	 * instead of the last block overwriting the previous ones, which used to drop
	 * entries silently: they were never displayed, never reconciled, and the cron
	 * still marked the file processed and deleted it from the SFTP server.
	 *
	 * A movement repeated across blocks (same AcctSvcrRef) is kept once. The
	 * merged statements are new objects, so calling this twice is safe.
	 *
	 * @return array<int, Camt053Statement>
	 */
	public function getStatementsByAccountId(): array
	{
		$result = array();
		$seenReferences = array();
		foreach ($this->statements as $blockIndex => $statement) {
			$accountId = $statement->getAccountId();
			if ($accountId === null) {
				continue;
			}

			if (!isset($result[$accountId])) {
				// A fresh statement: merging into the parsed one would mutate
				// $this->statements and make a second call return more entries.
				$merged = new Camt053Statement($statement->getIban(), $accountId);
				$merged->setIsFromFile(true);
				$merged->setCreationDate($statement->getCreationDate());
				$result[$accountId] = $merged;
				$seenReferences[$accountId] = array();
			}

			foreach ($statement->getEntries() as $entry) {
				// Overlapping blocks re-report the same movement. The bank
				// reference identifies it, so a repeat coming from another block
				// is dropped rather than added under a rewritten hash, which would
				// show up as a phantom unmatched entry inviting a duplicate
				// payment. Inside one block a repeated reference is a second real
				// movement (split or collective bookings do reuse it) and must be
				// kept: the block index is what tells the two cases apart.
				$reference = $entry->getBankReference();
				if ($reference !== '') {
					if (isset($seenReferences[$accountId][$reference])
						&& $seenReferences[$accountId][$reference] !== $blockIndex) {
						continue;
					}
					$seenReferences[$accountId][$reference] = $blockIndex;
				}
				$result[$accountId]->addEntry($entry);
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
