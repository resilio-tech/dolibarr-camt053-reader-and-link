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
 * \file       test/phpunit/Camt053StatementArchiveTest.php
 * \ingroup    camt053readerandlink
 * \brief      PHPUnit tests for the manual upload archiving. The uploaded file
 *             is the only copy there is, so nothing may drop it unless the very
 *             same content is already archived.
 */

use PHPUnit\Framework\TestCase;

require_once dirname(__FILE__) . '/../../class/Camt053StatementArchive.class.php';

/**
 * Class Camt053StatementArchiveTest
 */
class Camt053StatementArchiveTest extends TestCase
{
	/** @var string Temporary working directory */
	private $dir;

	/** @var string Archive directory inside it */
	private $archive;

	/**
	 * @return void
	 */
	protected function setUp(): void
	{
		$this->dir = sys_get_temp_dir() . '/camt053archive' . getmypid();
		$this->archive = $this->dir . '/statement';
		if (!is_dir($this->archive)) {
			mkdir($this->archive, 0777, true);
		}
	}

	/**
	 * @return void
	 */
	protected function tearDown(): void
	{
		foreach (array($this->archive, $this->dir) as $dir) {
			foreach (glob($dir . '/*') ?: array() as $file) {
				if (is_file($file)) {
					unlink($file);
				}
			}
		}
		foreach (array($this->archive, $this->dir) as $dir) {
			if (is_dir($dir)) {
				rmdir($dir);
			}
		}
	}

	/**
	 * Write an upload to archive.
	 *
	 * @param string $content Payload
	 * @return string Path of the upload
	 */
	private function upload(string $content): string
	{
		$path = $this->dir . '/camt053.xml';
		file_put_contents($path, $content);

		return $path;
	}

	/**
	 * @return void
	 */
	public function testAFreeNameTakesTheUploadAsIs(): void
	{
		$source = $this->upload('<Document>january</Document>');

		$result = Camt053StatementArchive::store($source, $this->archive, 'camt053.xml');

		$this->assertSame(Camt053StatementArchive::STORED, $result['outcome']);
		$this->assertSame($this->archive . '/camt053.xml', $result['path']);
		$this->assertSame('<Document>january</Document>', file_get_contents($result['path']));
		$this->assertFileDoesNotExist($source, 'The upload is moved, not copied');
	}

	/**
	 * Re-uploading the very same statement is the only case where dropping the
	 * upload is safe: the content is already on disk.
	 *
	 * @return void
	 */
	public function testTheSameContentIsRecognisedAndTheUploadDropped(): void
	{
		file_put_contents($this->archive . '/camt053.xml', '<Document>january</Document>');
		$source = $this->upload('<Document>january</Document>');

		$result = Camt053StatementArchive::store($source, $this->archive, 'camt053.xml');

		$this->assertSame(Camt053StatementArchive::ALREADY, $result['outcome']);
		$this->assertSame($this->archive . '/camt053.xml', $result['path']);
		$this->assertFileDoesNotExist($source);
		$this->assertCount(1, glob($this->archive . '/*'));
	}

	/**
	 * The regression this class exists for: the bank publishes February under
	 * the name January already used, and the upload used to be deleted while
	 * January stayed in place as the statement of the month.
	 *
	 * @return void
	 */
	public function testAnotherStatementUnderTheSameNameKeepsBothCopies(): void
	{
		file_put_contents($this->archive . '/camt053.xml', '<Document>january</Document>');
		$source = $this->upload('<Document>february</Document>');

		$result = Camt053StatementArchive::store($source, $this->archive, 'camt053.xml');

		$this->assertSame(Camt053StatementArchive::STORED, $result['outcome']);
		$this->assertNotSame($this->archive . '/camt053.xml', $result['path']);
		$this->assertSame('<Document>february</Document>', file_get_contents($result['path']));
		$this->assertSame('<Document>january</Document>', file_get_contents($this->archive . '/camt053.xml'));
		$this->assertCount(2, glob($this->archive . '/*'));
	}

	/**
	 * A failure must leave the upload where it is: it is the only copy.
	 *
	 * @return void
	 */
	public function testAnUnreadableUploadIsReportedAndNothingIsWritten(): void
	{
		$result = Camt053StatementArchive::store($this->dir . '/absent.xml', $this->archive, 'camt053.xml');

		$this->assertSame(Camt053StatementArchive::FAILED, $result['outcome']);
		$this->assertSame('', $result['path']);
		$this->assertCount(0, glob($this->archive . '/*'));
	}

	/**
	 * @return void
	 */
	public function testAnUnwritableTargetLeavesTheUploadInPlace(): void
	{
		$source = $this->upload('<Document>january</Document>');

		$result = Camt053StatementArchive::store($source, $this->dir . '/absent', 'camt053.xml');

		$this->assertSame(Camt053StatementArchive::FAILED, $result['outcome']);
		$this->assertFileExists($source, 'The upload is the only copy left, it must stay');
	}
}
