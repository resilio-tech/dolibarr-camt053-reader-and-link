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
 * \file       test/phpunit/Camt053ArchivePathTest.php
 * \ingroup    camt053readerandlink
 * \brief      PHPUnit tests for archive path resolution. Reporting a file as
 *             already archived releases the remote original, so one statement
 *             must never answer for another.
 */

use PHPUnit\Framework\TestCase;

require_once dirname(__FILE__) . '/../../class/Camt053ArchivePath.class.php';

/**
 * Class Camt053ArchivePathTest
 */
class Camt053ArchivePathTest extends TestCase
{
	/** @var string Temporary working directory */
	private $dir;

	/**
	 * @return void
	 */
	protected function setUp(): void
	{
		$this->dir = sys_get_temp_dir() . '/camt053archivepath' . getmypid();
		if (!is_dir($this->dir)) {
			mkdir($this->dir, 0777, true);
		}
	}

	/**
	 * @return void
	 */
	protected function tearDown(): void
	{
		foreach (glob($this->dir . '/*') ?: array() as $file) {
			unlink($file);
		}
		if (is_dir($this->dir)) {
			rmdir($this->dir);
		}
	}

	/**
	 * Nothing there yet: the plain name, not archived.
	 *
	 * @return void
	 */
	public function testFreeNameIsUsedAsIs(): void
	{
		$target = Camt053ArchivePath::resolve($this->dir, 'camt053.xml', '<A/>');

		$this->assertSame($this->dir . '/camt053.xml', $target['path']);
		$this->assertFalse($target['exists']);
	}

	/**
	 * The same statement downloaded twice reuses its file and reports it as
	 * already archived, so the run stays idempotent.
	 *
	 * @return void
	 */
	public function testIdenticalContentIsRecognised(): void
	{
		file_put_contents($this->dir . '/camt053.xml', '<A/>');

		$target = Camt053ArchivePath::resolve($this->dir, 'camt053.xml', '<A/>');

		$this->assertSame($this->dir . '/camt053.xml', $target['path']);
		$this->assertTrue($target['exists']);
	}

	/**
	 * The heart of it: a different statement published under the same remote
	 * name must not be reported as archived, and must get its own file.
	 *
	 * @return void
	 */
	public function testDifferentContentUnderTheSameNameGetsItsOwnFile(): void
	{
		file_put_contents($this->dir . '/camt053.xml', '<YESTERDAY/>');

		$target = Camt053ArchivePath::resolve($this->dir, 'camt053.xml', '<TODAY/>');

		$this->assertNotSame($this->dir . '/camt053.xml', $target['path']);
		$this->assertFalse($target['exists'], 'Today has not been archived yet');
		$this->assertMatchesRegularExpression('#/camt053-[0-9a-f]{12}\.xml$#', $target['path']);
		$this->assertSame('<YESTERDAY/>', file_get_contents($this->dir . '/camt053.xml'), 'The old copy is untouched');
	}

	/**
	 * Once written under its suffixed name, the same content is recognised
	 * there: a third run neither rewrites nor duplicates it.
	 *
	 * @return void
	 */
	public function testSuffixedFileIsRecognisedOnTheNextRun(): void
	{
		file_put_contents($this->dir . '/camt053.xml', '<YESTERDAY/>');
		$first = Camt053ArchivePath::resolve($this->dir, 'camt053.xml', '<TODAY/>');
		file_put_contents($first['path'], '<TODAY/>');

		$second = Camt053ArchivePath::resolve($this->dir, 'camt053.xml', '<TODAY/>');

		$this->assertSame($first['path'], $second['path']);
		$this->assertTrue($second['exists']);
	}

	/**
	 * Two different statements colliding on the name get two different files.
	 *
	 * @return void
	 */
	public function testTwoDifferentPayloadsDoNotCollide(): void
	{
		file_put_contents($this->dir . '/camt053.xml', '<DAY1/>');

		$second = Camt053ArchivePath::resolve($this->dir, 'camt053.xml', '<DAY2/>');
		$third = Camt053ArchivePath::resolve($this->dir, 'camt053.xml', '<DAY3/>');

		$this->assertNotSame($second['path'], $third['path']);
	}

	/**
	 * The suffix goes before the extension so the file stays a readable .xml.
	 *
	 * @return void
	 */
	public function testSuffixIsInsertedBeforeTheExtension(): void
	{
		file_put_contents($this->dir . '/statement.tar.gz', 'old');

		$target = Camt053ArchivePath::resolve($this->dir, 'statement.tar.gz', 'new');

		$this->assertStringEndsWith('.gz', $target['path']);
		$this->assertStringContainsString('statement.tar-', basename($target['path']));
	}

	/**
	 * A name without an extension is still handled.
	 *
	 * @return void
	 */
	public function testNameWithoutExtension(): void
	{
		file_put_contents($this->dir . '/statement', 'old');

		$target = Camt053ArchivePath::resolve($this->dir, 'statement', 'new');

		$this->assertMatchesRegularExpression('#/statement-[0-9a-f]{12}$#', $target['path']);
	}

	/**
	 * A trailing separator on the directory does not double up.
	 *
	 * @return void
	 */
	public function testTrailingSeparatorIsNormalised(): void
	{
		$target = Camt053ArchivePath::resolve($this->dir . '/', 'camt053.xml', '<A/>');

		$this->assertSame($this->dir . '/camt053.xml', $target['path']);
	}
}
