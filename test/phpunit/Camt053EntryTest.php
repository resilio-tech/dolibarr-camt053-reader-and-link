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
 * \file       test/phpunit/Camt053EntryTest.php
 * \ingroup    camt053readerandlink
 * \brief      PHPUnit tests for Camt053Entry class
 */

use PHPUnit\Framework\TestCase;

// Include the class to test
require_once dirname(__FILE__) . '/../../class/Camt053Entry.class.php';

/**
 * Class Camt053EntryTest
 *
 * Unit tests for Camt053Entry class
 */
class Camt053EntryTest extends TestCase
{
	/**
	 * Test creating an entry with valid data
	 *
	 * @return void
	 */
	public function testCreateEntryWithValidData(): void
	{
		$entry = new Camt053Entry(
			1500.00,
			'2024-01-15',
			'Invoice payment INV-2024-001',
			'Customer payment'
		);

		$this->assertEquals(1500.00, $entry->getAmount());
		$this->assertEquals('2024-01-15', $entry->getValueDate());
		$this->assertEquals('Invoice payment INV-2024-001', $entry->getName());
		$this->assertEquals('Customer payment', $entry->getInfo());
		$this->assertNotEmpty($entry->getHash());
	}

	/**
	 * Test that hash is consistent for same data
	 *
	 * @return void
	 */
	public function testHashConsistency(): void
	{
		$entry1 = new Camt053Entry(
			1500.00,
			'2024-01-15',
			'Invoice payment',
			'Info'
		);

		$entry2 = new Camt053Entry(
			1500.00,
			'2024-01-15',
			'Invoice payment',
			'Info'
		);

		$this->assertEquals($entry1->getHash(), $entry2->getHash());
	}

	/**
	 * Test that different data produces different hash
	 *
	 * @return void
	 */
	public function testHashDifference(): void
	{
		$entry1 = new Camt053Entry(
			1500.00,
			'2024-01-15',
			'Invoice payment',
			'Info'
		);

		$entry2 = new Camt053Entry(
			1500.01, // Different amount
			'2024-01-15',
			'Invoice payment',
			'Info'
		);

		$this->assertNotEquals($entry1->getHash(), $entry2->getHash());
	}

	/**
	 * Test debit entry (negative amount)
	 *
	 * @return void
	 */
	public function testDebitEntry(): void
	{
		$entry = new Camt053Entry(
			-250.00,
			'2024-01-20',
			'Supplier payment',
			'Payment for supplies'
		);

		$this->assertEquals(-250.00, $entry->getAmount());
		$this->assertTrue($entry->isDebit());
		$this->assertFalse($entry->isCredit());
	}

	/**
	 * Test credit entry (positive amount)
	 *
	 * @return void
	 */
	public function testCreditEntry(): void
	{
		$entry = new Camt053Entry(
			1500.00,
			'2024-01-15',
			'Customer payment',
			''
		);

		$this->assertTrue($entry->isCredit());
		$this->assertFalse($entry->isDebit());
	}

	/**
	 * Test zero amount entry
	 *
	 * @return void
	 */
	public function testZeroAmountEntry(): void
	{
		$entry = new Camt053Entry(
			0.00,
			'2024-01-15',
			'Zero transaction',
			''
		);

		$this->assertFalse($entry->isCredit());
		$this->assertFalse($entry->isDebit());
	}

	/**
	 * Test toArray method
	 *
	 * @return void
	 */
	public function testToArray(): void
	{
		$entry = new Camt053Entry(
			1500.00,
			'2024-01-15',
			'Test name',
			'Test info',
			'custom_hash'
		);

		$array = $entry->toArray();

		$this->assertIsArray($array);
		$this->assertEquals(1500.00, $array['amount']);
		$this->assertEquals('2024-01-15', $array['value_date']);
		$this->assertEquals('Test name', $array['name']);
		$this->assertEquals('Test info', $array['info']);
		$this->assertEquals('custom_hash', $array['hash']);
	}

	/**
	 * Test initFromArray static method
	 *
	 * @return void
	 */
	public function testInitFromArray(): void
	{
		$data = array(
			'amount' => 1500.00,
			'value_date' => '2024-01-15',
			'name' => 'Test name',
			'info' => 'Test info',
			'hash' => 'custom_hash'
		);

		$entry = Camt053Entry::initFromArray($data);

		$this->assertEquals(1500.00, $entry->getAmount());
		$this->assertEquals('2024-01-15', $entry->getValueDate());
		$this->assertEquals('Test name', $entry->getName());
		$this->assertEquals('Test info', $entry->getInfo());
		$this->assertEquals('custom_hash', $entry->getHash());
	}

	/**
	 * Test setters
	 *
	 * @return void
	 */
	public function testSetters(): void
	{
		$entry = new Camt053Entry(0, '', '');

		$entry->setAmount(999.99);
		$entry->setValueDate('2024-02-01');
		$entry->setName('New name');
		$entry->setInfo('New info');
		$entry->setHash('new_hash');
		$entry->setIsFromFile(true);

		$this->assertEquals(999.99, $entry->getAmount());
		$this->assertEquals('2024-02-01', $entry->getValueDate());
		$this->assertEquals('New name', $entry->getName());
		$this->assertEquals('New info', $entry->getInfo());
		$this->assertEquals('new_hash', $entry->getHash());
		$this->assertTrue($entry->isFromFile());
	}

	/**
	 * Test isFromFile flag
	 *
	 * @return void
	 */
	public function testIsFromFileFlag(): void
	{
		$entry = new Camt053Entry(100, '2024-01-01', 'Test');

		$this->assertFalse($entry->isFromFile());

		$entry->setIsFromFile(true);
		$this->assertTrue($entry->isFromFile());

		$entry->setIsFromFile(false);
		$this->assertFalse($entry->isFromFile());
	}

	/**
	 * Test bank line association
	 *
	 * @return void
	 */
	public function testBankLineAssociation(): void
	{
		$entry = new Camt053Entry(100, '2024-01-01', 'Test');

		$this->assertNull($entry->getBankLine());

		// Create a mock bank line object
		$mockBankLine = new stdClass();
		$mockBankLine->rowid = 123;
		$mockBankLine->rappro = 0;

		$entry->setBankLine($mockBankLine);
		$this->assertSame($mockBankLine, $entry->getBankLine());
	}

	/**
	 * Test backward compatibility methods
	 *
	 * @return void
	 */
	public function testBackwardCompatibility(): void
	{
		$entry = new Camt053Entry(100, '2024-01-01', 'Test', 'Info');

		// Test getData() (deprecated alias for toArray())
		$data = $entry->getData();
		$this->assertIsArray($data);
		$this->assertEquals(100, $data['amount']);

		// Test getBankObj() and setBankObj() (deprecated aliases)
		$mockObj = new stdClass();
		$entry->setBankObj($mockObj);
		$this->assertSame($mockObj, $entry->getBankObj());
	}

	/**
	 * Test custom hash overrides generated hash
	 *
	 * @return void
	 */
	public function testCustomHashOverride(): void
	{
		$customHash = 'my_custom_hash_123';
		$entry = new Camt053Entry(
			100,
			'2024-01-01',
			'Test',
			'',
			$customHash
		);

		$this->assertEquals($customHash, $entry->getHash());
	}

	/**
	 * Test generateHash method
	 *
	 * @return void
	 */
	public function testGenerateHash(): void
	{
		$entry = new Camt053Entry(100, '2024-01-01', 'Test', 'Info');

		$expectedHash = md5('100' . '2024-01-01' . 'Test' . 'Info');
		$this->assertEquals($expectedHash, $entry->generateHash());
	}

	/**
	 * Test entry with empty info
	 *
	 * @return void
	 */
	public function testEntryWithEmptyInfo(): void
	{
		$entry = new Camt053Entry(100, '2024-01-01', 'Test');

		$this->assertEquals('', $entry->getInfo());

		$array = $entry->toArray();
		$this->assertEquals('', $array['info']);
	}

	/**
	 * Test entry with special characters in name
	 *
	 * @return void
	 */
	public function testEntryWithSpecialCharacters(): void
	{
		$name = 'Test <script>alert("XSS")</script> & "quotes"';
		$entry = new Camt053Entry(100, '2024-01-01', $name);

		$this->assertEquals($name, $entry->getName());
	}

	/**
	 * Test large amount
	 *
	 * @return void
	 */
	public function testLargeAmount(): void
	{
		$amount = 999999999.99;
		$entry = new Camt053Entry($amount, '2024-01-01', 'Large payment');

		$this->assertEquals($amount, $entry->getAmount());
		$this->assertTrue($entry->isCredit());
	}

	/**
	 * Test negative large amount
	 *
	 * @return void
	 */
	public function testNegativeLargeAmount(): void
	{
		$amount = -999999999.99;
		$entry = new Camt053Entry($amount, '2024-01-01', 'Large debit');

		$this->assertEquals($amount, $entry->getAmount());
		$this->assertTrue($entry->isDebit());
	}
}
