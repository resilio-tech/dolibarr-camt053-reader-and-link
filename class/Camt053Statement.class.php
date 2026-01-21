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
	 * @var bool Whether this statement comes from a CAMT.053 file
	 */
	private $isFromFile = false;

	/**
	 * @var string|null Statement creation date
	 */
	private $creationDate;

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
		$this->entries[] = $entry;
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
	 * Clear all entries
	 *
	 * @return void
	 */
	public function clearEntries(): void
	{
		$this->entries = array();
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
