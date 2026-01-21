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
 * \file       class/BankStatementMatcher.class.php
 * \ingroup    camt053readerandlink
 * \brief      Class for comparing and matching bank statement entries
 */

require_once __DIR__ . '/Camt053Entry.class.php';
require_once __DIR__ . '/Camt053Statement.class.php';

/**
 * Class BankStatementMatcher
 *
 * Compares entries between file and database statements to find matches.
 */
class BankStatementMatcher
{
	/**
	 * @var int Date tolerance in days for matching
	 */
	private $dateTolerance = 1;

	/**
	 * @var array Matched results indexed by account ID
	 */
	private $results = array();

	/**
	 * Constructor
	 *
	 * @param int $dateTolerance Date tolerance in days (default: 1)
	 */
	public function __construct(int $dateTolerance = 1)
	{
		$this->dateTolerance = $dateTolerance;
	}

	/**
	 * Get date tolerance
	 *
	 * @return int
	 */
	public function getDateTolerance(): int
	{
		return $this->dateTolerance;
	}

	/**
	 * Set date tolerance
	 *
	 * @param int $days
	 * @return void
	 */
	public function setDateTolerance(int $days): void
	{
		$this->dateTolerance = max(0, $days);
	}

	/**
	 * Compare file statement entries with database statement entries
	 *
	 * @param Camt053Statement $fileStatement Statement from CAMT.053 file
	 * @param Camt053Statement $dbStatement   Statement from database
	 * @return array Results with keys: linked, multiples, unlinked, already_linked
	 */
	public function compare(Camt053Statement $fileStatement, Camt053Statement $dbStatement): array
	{
		$linked = array();
		$multiples = array();
		$unlinked = array();
		$alreadyLinked = array();

		$fileEntries = $fileStatement->getEntries();
		$dbEntries = $dbStatement->getEntries();

		// Track which DB entries have been matched
		$matchedDbEntries = array();

		// For each file entry, find matching DB entries
		foreach ($fileEntries as $fileEntry) {
			$matches = $this->findMatches($fileEntry, $dbEntries);

			if (count($matches) === 0) {
				// No match found
				$unlinked[] = $fileEntry;
			} elseif (count($matches) === 1) {
				// Single match
				$dbEntry = $matches[0];
				$bankLine = $dbEntry->getBankLine();

				if ($bankLine && $bankLine->rappro == 1) {
					// Already reconciled
					$alreadyLinked[] = array(
						'file' => $fileEntry,
						'db' => $dbEntry
					);
				} else {
					// Link them
					$linked[] = array(
						'file' => $fileEntry,
						'db' => $dbEntry
					);
					$matchedDbEntries[] = spl_object_id($dbEntry);
				}
			} else {
				// Multiple matches - filter out already reconciled
				$nonReconciledMatches = array_filter($matches, function ($entry) {
					$bankLine = $entry->getBankLine();
					return !$bankLine || $bankLine->rappro != 1;
				});
				$nonReconciledMatches = array_values($nonReconciledMatches);

				if (count($nonReconciledMatches) === 0) {
					// All matches are already reconciled
					$alreadyLinked[] = array(
						'file' => $fileEntry,
						'db' => $matches[0]
					);
				} elseif (count($nonReconciledMatches) === 1) {
					// Only one non-reconciled match
					$linked[] = array(
						'file' => $fileEntry,
						'db' => $nonReconciledMatches[0]
					);
					$matchedDbEntries[] = spl_object_id($nonReconciledMatches[0]);
				} else {
					// Multiple non-reconciled matches - user must choose
					$multiples[] = array(
						'file' => $fileEntry,
						'db' => $nonReconciledMatches
					);
					foreach ($nonReconciledMatches as $match) {
						$matchedDbEntries[] = spl_object_id($match);
					}
				}
			}
		}

		// Check for DB entries not matched to any file entry
		foreach ($dbEntries as $dbEntry) {
			$entryId = spl_object_id($dbEntry);

			// Skip if already matched
			if (in_array($entryId, $matchedDbEntries)) {
				continue;
			}

			// Skip if in already linked (from file side)
			$isInAlreadyLinked = false;
			foreach ($alreadyLinked as $item) {
				if (isset($item['db']) && spl_object_id($item['db']) === $entryId) {
					$isInAlreadyLinked = true;
					break;
				}
			}
			if ($isInAlreadyLinked) {
				continue;
			}

			$bankLine = $dbEntry->getBankLine();
			if ($bankLine && $bankLine->rappro == 1) {
				// Already reconciled, no file match
				$alreadyLinked[] = array(
					'file' => null,
					'db' => $dbEntry
				);
			} else {
				// Unmatched DB entry
				$unlinked[] = $dbEntry;
			}
		}

		return array(
			'linkeds' => $linked,
			'multiples' => $multiples,
			'unlinkeds' => $unlinked,
			'already_linked' => $alreadyLinked
		);
	}

	/**
	 * Compare multiple statements by account ID
	 *
	 * @param array<int, Camt053Statement> $fileStatements Statements from file indexed by account ID
	 * @param array<int, Camt053Statement> $dbStatements   Statements from DB indexed by account ID
	 * @param object $bankAccountGetter Object with getDbBank($id) method for account info
	 * @return array Results indexed by account ID
	 */
	public function compareMultiple(array $fileStatements, array $dbStatements, $bankAccountGetter = null): array
	{
		$results = array();

		foreach ($fileStatements as $accountId => $fileStatement) {
			$dbStatement = $dbStatements[$accountId] ?? new Camt053Statement('', $accountId);

			$comparisonResults = $this->compare($fileStatement, $dbStatement);

			$accountInfo = null;
			if ($bankAccountGetter !== null && method_exists($bankAccountGetter, 'getDbBank')) {
				try {
					$accountInfo = $bankAccountGetter->getDbBank($accountId);
				} catch (Exception $e) {
					// Ignore errors getting account info
				}
			}

			$results[$accountId] = array(
				'account' => $accountInfo,
				'results' => $comparisonResults
			);
		}

		return $results;
	}

	/**
	 * Find matching DB entries for a file entry
	 *
	 * @param Camt053Entry   $fileEntry File entry to match
	 * @param Camt053Entry[] $dbEntries Database entries to search
	 * @return Camt053Entry[] Array of matching entries
	 */
	public function findMatches(Camt053Entry $fileEntry, array $dbEntries): array
	{
		$matches = array();

		$fileAmount = $this->formatAmount($fileEntry->getAmount());
		$fileDate = $this->parseDate($fileEntry->getValueDate());

		if ($fileDate === null) {
			return $matches;
		}

		foreach ($dbEntries as $dbEntry) {
			$dbAmount = $this->formatAmount($dbEntry->getAmount());

			// Amount must match exactly
			if ($fileAmount !== $dbAmount) {
				continue;
			}

			$dbDate = $this->parseDate($dbEntry->getValueDate());
			if ($dbDate === null) {
				continue;
			}

			// Check date within tolerance
			if ($this->datesMatch($fileDate, $dbDate)) {
				$matches[] = $dbEntry;
			}
		}

		return $matches;
	}

	/**
	 * Check if two dates match within tolerance
	 *
	 * @param DateTime $date1
	 * @param DateTime $date2
	 * @return bool
	 */
	private function datesMatch(DateTime $date1, DateTime $date2): bool
	{
		// Exact match
		if ($date1->format('Y-m-d') === $date2->format('Y-m-d')) {
			return true;
		}

		// Check within tolerance
		if ($this->dateTolerance > 0) {
			$diff = abs($date1->diff($date2)->days);
			return $diff <= $this->dateTolerance;
		}

		return false;
	}

	/**
	 * Parse date string to DateTime object
	 *
	 * @param string $dateStr Date string
	 * @return DateTime|null
	 */
	private function parseDate(string $dateStr): ?DateTime
	{
		if (empty($dateStr)) {
			return null;
		}

		try {
			return new DateTime($dateStr);
		} catch (Exception $e) {
			// Try Y-m-d format explicitly
			$date = DateTime::createFromFormat('Y-m-d', $dateStr);
			if ($date !== false) {
				return $date;
			}
			return null;
		}
	}

	/**
	 * Format amount for comparison (using price() if available)
	 *
	 * @param float $amount
	 * @return string
	 */
	private function formatAmount(float $amount): string
	{
		// Use Dolibarr's price function if available for consistent formatting
		if (function_exists('price')) {
			return price($amount);
		}
		return number_format($amount, 2, '.', '');
	}

	/**
	 * Check if an entry is already reconciled
	 *
	 * @param Camt053Entry $entry
	 * @return bool
	 */
	public function isReconciled(Camt053Entry $entry): bool
	{
		$bankLine = $entry->getBankLine();
		return $bankLine && $bankLine->rappro == 1;
	}

	/**
	 * Get match statistics from results
	 *
	 * @param array $results Results from compare()
	 * @return array Statistics with counts
	 */
	public function getStatistics(array $results): array
	{
		return array(
			'linked_count' => count($results['linkeds'] ?? array()),
			'multiples_count' => count($results['multiples'] ?? array()),
			'unlinked_count' => count($results['unlinkeds'] ?? array()),
			'already_linked_count' => count($results['already_linked'] ?? array()),
			'total_to_process' => count($results['linkeds'] ?? array()) + count($results['multiples'] ?? array())
		);
	}
}
