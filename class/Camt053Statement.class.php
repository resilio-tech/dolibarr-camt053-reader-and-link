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
 * \file       class/Camt053Statement.class.php
 * \ingroup    camt053readerandlink
 * \brief      Class representing a complete CAMT.053 bank statement
 */

require_once __DIR__ . '/Camt053Entry.class.php';

/**
 * Class Camt053Statement
 *
 * Represents a complete bank statement from a CAMT.053 file or database.
 */
class Camt053Statement
{
	/**
	 * @var string IBAN of the bank account
	 */
	private $iban;

	/**
	 * @var int|null Dolibarr bank account ID
	 */
	private $accountId;

	/**
	 * @var Camt053Entry[] Array of statement entries
	 */
	private $entries = array();

	/**
	 * @var array<string,bool> Hashes already used by an entry of this statement
	 */
	private $takenHashes = array();

	/**
	 * @var bool Whether this statement comes from a CAMT.053 file
	 */
	private $isFromFile = false;

	/**
	 * @var string|null Statement creation date
	 */
	private $creationDate;

	/**
	 * @var string|null First day of the period the statement covers
	 */
	private $periodStart;

	/**
	 * @var string|null Last day of the period the statement covers
	 */
	private $periodEnd;

	/**
	 * Constructor
	 *
	 * @param string   $iban      IBAN of the bank account
	 * @param int|null $accountId Dolibarr bank account ID
	 */
	public function __construct(string $iban = '', ?int $accountId = null)
	{
		$this->iban = $iban;
		$this->accountId = $accountId;
	}

	/**
	 * Get IBAN
	 *
	 * @return string
	 */
	public function getIban(): string
	{
		return $this->iban;
	}

	/**
	 * Set IBAN
	 *
	 * @param string $iban
	 * @return void
	 */
	public function setIban(string $iban): void
	{
		$this->iban = $iban;
	}

	/**
	 * Get formatted IBAN with spaces
	 *
	 * @return string
	 */
	public function getFormattedIban(): string
	{
		$iban = str_replace(' ', '', $this->iban);
		return chunk_split($iban, 4, ' ');
	}

	/**
	 * Get Dolibarr bank account ID
	 *
	 * @return int|null
	 */
	public function getAccountId(): ?int
	{
		return $this->accountId;
	}

	/**
	 * Set Dolibarr bank account ID
	 *
	 * @param int|null $accountId
	 * @return void
	 */
	public function setAccountId(?int $accountId): void
	{
		$this->accountId = $accountId;
	}

	/**
	 * Add an entry to the statement
	 *
	 * @param Camt053Entry $entry
	 * @return void
	 */
	public function addEntry(Camt053Entry $entry): void
	{
		$entry->setIsFromFile($this->isFromFile);
		$this->ensureUniqueHash($entry);
		$this->entries[] = $entry;
	}

	/**
	 * Guarantee the entry hash is unique within this statement.
	 *
	 * Without AcctSvcrRef the hash falls back to amount + date + name + info, so
	 * two identical movements on the same day collide. The hash keys the
	 * reconciliation form fields, and duplicate field names mean PHP keeps only
	 * the last one: one of the two entries would be dropped with no error.
	 *
	 * @param Camt053Entry $entry Entry about to be added
	 * @return void
	 */
	private function ensureUniqueHash(Camt053Entry $entry): void
	{
		$hash = $entry->getHash();
		if ($hash === '') {
			return;
		}

		if (!isset($this->takenHashes[$hash])) {
			$this->takenHashes[$hash] = true;
			return;
		}

		$suffix = 2;
		do {
			$candidate = md5($hash . '#' . $suffix);
			$suffix++;
		} while (isset($this->takenHashes[$candidate]));

		$entry->setHash($candidate);
		$this->takenHashes[$candidate] = true;
	}

	/**
	 * Create and add a new entry to the statement
	 *
	 * @param float       $amount    Entry amount
	 * @param string      $valueDate Value date
	 * @param string      $name      Entry name
	 * @param string      $info      Additional info
	 * @param string|null $hash      Optional hash
	 * @return Camt053Entry The created entry
	 */
	public function createEntry(float $amount, string $valueDate, string $name, string $info = '', ?string $hash = null): Camt053Entry
	{
		$entry = new Camt053Entry($amount, $valueDate, $name, $info, $hash);
		$this->addEntry($entry);
		return $entry;
	}

	/**
	 * Get all entries
	 *
	 * @return Camt053Entry[]
	 */
	public function getEntries(): array
	{
		return $this->entries;
	}

	/**
	 * Get entry count
	 *
	 * @return int
	 */
	public function getEntryCount(): int
	{
		return count($this->entries);
	}

	/**
	 * Check if statement is from file
	 *
	 * @return bool
	 */
	public function isFromFile(): bool
	{
		return $this->isFromFile;
	}

	/**
	 * Set whether statement is from file
	 *
	 * @param bool $isFromFile
	 * @return void
	 */
	public function setIsFromFile(bool $isFromFile): void
	{
		$this->isFromFile = $isFromFile;
		// Update all existing entries
		foreach ($this->entries as $entry) {
			$entry->setIsFromFile($isFromFile);
		}
	}

	/**
	 * Get creation date
	 *
	 * @return string|null
	 */
	public function getCreationDate(): ?string
	{
		return $this->creationDate;
	}

	/**
	 * Set creation date
	 *
	 * @param string|null $creationDate
	 * @return void
	 */
	public function setCreationDate(?string $creationDate): void
	{
		$this->creationDate = $creationDate;
	}

	/**
	 * Get the first day of the covered period
	 *
	 * @return string|null
	 */
	public function getPeriodStart(): ?string
	{
		return $this->periodStart;
	}

	/**
	 * Get the last day of the covered period
	 *
	 * @return string|null
	 */
	public function getPeriodEnd(): ?string
	{
		return $this->periodEnd;
	}

	/**
	 * Set the period the statement covers
	 *
	 * @param string|null $periodStart First day, as carried by the file
	 * @param string|null $periodEnd   Last day, as carried by the file
	 * @return void
	 */
	public function setPeriod(?string $periodStart, ?string $periodEnd): void
	{
		$this->periodStart = $periodStart;
		$this->periodEnd = $periodEnd;
	}

	/**
	 * Get total credits (positive amounts)
	 *
	 * @return float
	 */
	public function getTotalCredits(): float
	{
		$total = 0.0;
		foreach ($this->entries as $entry) {
			if ($entry->isCredit()) {
				$total += $entry->getAmount();
			}
		}
		return $total;
	}

	/**
	 * Get total debits (negative amounts)
	 *
	 * @return float
	 */
	public function getTotalDebits(): float
	{
		$total = 0.0;
		foreach ($this->entries as $entry) {
			if ($entry->isDebit()) {
				$total += $entry->getAmount();
			}
		}
		return $total;
	}

	/**
	 * Get net balance (credits + debits)
	 *
	 * @return float
	 */
	public function getNetBalance(): float
	{
		$total = 0.0;
		foreach ($this->entries as $entry) {
			$total += $entry->getAmount();
		}
		return $total;
	}

	/**
	 * Convert to array format (for backward compatibility)
	 *
	 * @return array
	 */
	public function toArray(): array
	{
		return array(
			'IBAN' => $this->getFormattedIban(),
			'AccountId' => $this->accountId,
			'Ntries' => $this->entries
		);
	}
}
