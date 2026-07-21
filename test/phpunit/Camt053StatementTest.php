<?php
/* Copyright (C) 2026 Resilio SA
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
 * \file       test/phpunit/Camt053StatementTest.php
 * \ingroup    camt053readerandlink
 * \brief      PHPUnit tests for Camt053Statement, focused on entry hash
 *             uniqueness: the hash keys the reconciliation form fields, so a
 *             collision silently drops an entry.
 */

use PHPUnit\Framework\TestCase;

require_once dirname(__FILE__) . '/../../class/Camt053Entry.class.php';
require_once dirname(__FILE__) . '/../../class/Camt053Statement.class.php';

/**
 * Class Camt053StatementTest
 */
class Camt053StatementTest extends TestCase
{
	/**
	 * Two movements identical in amount, date, name and info (a file without
	 * AcctSvcrRef) must still get distinct hashes.
	 *
	 * @return void
	 */
	public function testIdenticalEntriesGetDistinctHashes(): void
	{
		$statement = new Camt053Statement('BE71 0961 2345 6769', 1);
		$statement->setIsFromFile(true);

		$first = $statement->createEntry(-50.00, '2024-01-15', 'Frais bancaires');
		$second = $statement->createEntry(-50.00, '2024-01-15', 'Frais bancaires');
		$third = $statement->createEntry(-50.00, '2024-01-15', 'Frais bancaires');

		$hashes = array($first->getHash(), $second->getHash(), $third->getHash());

		$this->assertCount(3, array_unique($hashes), 'Each entry needs its own form key');
		$this->assertNotEmpty($first->getHash());
	}

	/**
	 * The first entry keeps the hash it was built with; only the duplicates are
	 * moved, so a file that does provide AcctSvcrRef is untouched.
	 *
	 * @return void
	 */
	public function testFirstEntryKeepsItsHash(): void
	{
		$statement = new Camt053Statement('BE71 0961 2345 6769', 1);
		$statement->setIsFromFile(true);

		$expected = md5('-50' . '2024-01-15' . 'Frais bancaires' . '');
		$first = $statement->createEntry(-50.00, '2024-01-15', 'Frais bancaires');

		$this->assertSame($expected, $first->getHash());
	}

	/**
	 * Explicit hashes (AcctSvcrRef) are preserved when they differ.
	 *
	 * @return void
	 */
	public function testExplicitHashesArePreserved(): void
	{
		$statement = new Camt053Statement('BE71 0961 2345 6769', 1);
		$statement->setIsFromFile(true);

		$first = $statement->createEntry(-50.00, '2024-01-15', 'Frais', '', 'REF-A');
		$second = $statement->createEntry(-50.00, '2024-01-15', 'Frais', '', 'REF-B');

		$this->assertSame('REF-A', $first->getHash());
		$this->assertSame('REF-B', $second->getHash());
	}

	/**
	 * A bank repeating the same AcctSvcrRef must not cost us an entry either.
	 *
	 * @return void
	 */
	public function testRepeatedExplicitHashIsMadeUnique(): void
	{
		$statement = new Camt053Statement('BE71 0961 2345 6769', 1);
		$statement->setIsFromFile(true);

		$first = $statement->createEntry(-50.00, '2024-01-15', 'Frais', '', 'REF-A');
		$second = $statement->createEntry(-70.00, '2024-01-16', 'Autre', '', 'REF-A');

		$this->assertSame('REF-A', $first->getHash());
		$this->assertNotSame('REF-A', $second->getHash());
	}
}
