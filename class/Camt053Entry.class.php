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
 * \file       class/Camt053Entry.class.php
 * \ingroup    camt053readerandlink
 * \brief      Class representing a single entry from a CAMT.053 bank statement
 */

/**
 * Class Camt053Entry
 *
 * Represents a single entry from a CAMT.053 bank statement file or database.
 */
class Camt053Entry
{
	/**
	 * @var float Entry amount (positive for credit, negative for debit)
	 */
	private $amount;

	/**
	 * @var string Value date in Y-m-d format
	 */
	private $valueDate;

	/**
	 * @var string Entry name/label
	 */
	private $name;

	/**
	 * @var string Additional information
	 */
	private $info;

	/**
	 * @var string MD5 hash for matching entries
	 */
	private $hash;

	/**
	 * @var bool Whether this entry comes from a CAMT.053 file
	 */
	private $isFromFile = false;

	/**
	 * @var AccountLine|null Associated bank line object
	 */
	private $bankLine = null;

	/**
	 * Constructor
	 *
	 * @param float       $amount    Entry amount
	 * @param string      $valueDate Value date (Y-m-d format)
	 * @param string      $name      Entry name/label
	 * @param string      $info      Additional information
	 * @param string|null $hash      Optional hash (generated if not provided)
	 */
	public function __construct(float $amount, string $valueDate, string $name, string $info = '', ?string $hash = null)
	{
		$this->amount = $amount;
		$this->valueDate = $valueDate;
		$this->name = $name;
		$this->info = $info;
		$this->hash = $hash ?? $this->generateHash();
	}

	/**
	 * Initialize entry from array data
	 *
	 * @param array $data Array with keys: amount, value_date, name, info, hash
	 * @return self
	 */
	public static function initFromArray(array $data): self
	{
		$amount = isset($data['amount']) ? (float) $data['amount'] : 0.0;
		$valueDate = $data['value_date'] ?? '';
		$name = $data['name'] ?? '';
		$info = $data['info'] ?? '';
		$hash = $data['hash'] ?? null;

		return new self($amount, $valueDate, $name, $info, $hash);
	}

	/**
	 * Convert entry to array format
	 *
	 * @return array
	 */
	public function toArray(): array
	{
		return array(
			'amount' => $this->amount,
			'value_date' => $this->valueDate,
			'name' => $this->name,
			'info' => $this->info,
			'hash' => $this->hash
		);
	}

	/**
	 * Generate MD5 hash for this entry
	 *
	 * @return string
	 */
	public function generateHash(): string
	{
		return md5($this->amount . $this->valueDate . $this->name . $this->info);
	}

	/**
	 * Get entry amount
	 *
	 * @return float
	 */
	public function getAmount(): float
	{
		return $this->amount;
	}

	/**
	 * Set entry amount
	 *
	 * @param float $amount
	 * @return void
	 */
	public function setAmount(float $amount): void
	{
		$this->amount = $amount;
	}

	/**
	 * Get value date
	 *
	 * @return string
	 */
	public function getValueDate(): string
	{
		return $this->valueDate;
	}

	/**
	 * Set value date
	 *
	 * @param string $valueDate
	 * @return void
	 */
	public function setValueDate(string $valueDate): void
	{
		$this->valueDate = $valueDate;
	}

	/**
	 * Get entry name
	 *
	 * @return string
	 */
	public function getName(): string
	{
		return $this->name;
	}

	/**
	 * Set entry name
	 *
	 * @param string $name
	 * @return void
	 */
	public function setName(string $name): void
	{
		$this->name = $name;
	}

	/**
	 * Get additional info
	 *
	 * @return string
	 */
	public function getInfo(): string
	{
		return $this->info;
	}

	/**
	 * Set additional info
	 *
	 * @param string $info
	 * @return void
	 */
	public function setInfo(string $info): void
	{
		$this->info = $info;
	}

	/**
	 * Get hash
	 *
	 * @return string
	 */
	public function getHash(): string
	{
		return $this->hash;
	}

	/**
	 * Set hash
	 *
	 * @param string $hash
	 * @return void
	 */
	public function setHash(string $hash): void
	{
		$this->hash = $hash;
	}

	/**
	 * Check if entry is from file
	 *
	 * @return bool
	 */
	public function isFromFile(): bool
	{
		return $this->isFromFile;
	}

	/**
	 * Set whether entry is from file
	 *
	 * @param bool $isFromFile
	 * @return void
	 */
	public function setIsFromFile(bool $isFromFile): void
	{
		$this->isFromFile = $isFromFile;
	}

	/**
	 * Get associated bank line object
	 *
	 * @return AccountLine|null
	 */
	public function getBankLine(): ?object
	{
		return $this->bankLine;
	}

	/**
	 * Set associated bank line object
	 *
	 * @param AccountLine|null $bankLine
	 * @return void
	 */
	public function setBankLine(?object $bankLine): void
	{
		$this->bankLine = $bankLine;
	}

	/**
	 * Get data array (backward compatibility with old EntryCamt053)
	 *
	 * @return array
	 * @deprecated Use toArray() instead
	 */
	public function getData(): array
	{
		return $this->toArray();
	}

	/**
	 * Get bank object (backward compatibility with old EntryCamt053)
	 *
	 * @return AccountLine|null
	 * @deprecated Use getBankLine() instead
	 */
	public function getBankObj(): ?object
	{
		return $this->bankLine;
	}

	/**
	 * Set bank object (backward compatibility with old EntryCamt053)
	 *
	 * @param AccountLine|null $bankObj
	 * @return void
	 * @deprecated Use setBankLine() instead
	 */
	public function setBankObj(?object $bankObj): void
	{
		$this->bankLine = $bankObj;
	}

	/**
	 * Check if this is a debit entry (negative amount)
	 *
	 * @return bool
	 */
	public function isDebit(): bool
	{
		return $this->amount < 0;
	}

	/**
	 * Check if this is a credit entry (positive amount)
	 *
	 * @return bool
	 */
	public function isCredit(): bool
	{
		return $this->amount > 0;
	}
}
