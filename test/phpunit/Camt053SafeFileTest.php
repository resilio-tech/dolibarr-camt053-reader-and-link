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
 * \file       test/phpunit/Camt053SafeFileTest.php
 * \ingroup    camt053readerandlink
 * \brief      PHPUnit tests for the all-or-nothing archive write. A write
 *             reported as successful lets the runner delete the remote
 *             statement, so a half-written file must never count as one.
 */

use PHPUnit\Framework\TestCase;

require_once dirname(__FILE__) . '/../../class/Camt053SafeFile.class.php';

/**
 * Stream wrapper that accepts a few bytes and then stalls, the way a filling
 * disk does. There is no portable way to fill a real disk in a test.
 */
class ShortWriteStream
{
	/** @var int Bytes the first write() call accepts */
	public static $accept = 1;

	/** @var bool Whether unlink() was called on the wrapper */
	public static $unlinked = false;

	/** @var bool Whether the first write already happened */
	private $started = false;

	/** @var resource|null Stream context, set by PHP */
	public $context;

	public function stream_open($path, $mode, $options, &$openedPath)
	{
		return true;
	}

	public function stream_write($data)
	{
		if ($this->started) {
			return 0;
		}
		$this->started = true;

		return min(self::$accept, strlen($data));
	}

	public function stream_close()
	{
	}

	public function stream_flush()
	{
		return true;
	}

	public function unlink($path)
	{
		self::$unlinked = true;
		return true;
	}

	public function url_stat($path, $flags)
	{
		return false;
	}
}

/**
 * Class Camt053SafeFileTest
 */
class Camt053SafeFileTest extends TestCase
{
	/** @var string Temporary working directory */
	private $dir;

	/**
	 * @return void
	 */
	protected function setUp(): void
	{
		$this->dir = sys_get_temp_dir() . '/camt053safefile' . getmypid();
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
	 * A complete write reports success and lands the whole content.
	 *
	 * @return void
	 */
	public function testCompleteWriteSucceeds(): void
	{
		$path = $this->dir . '/statement.xml';

		$this->assertTrue(Camt053SafeFile::write($path, '<Document/>'));
		$this->assertSame('<Document/>', file_get_contents($path));
	}

	/**
	 * Empty content is still a complete write.
	 *
	 * @return void
	 */
	public function testEmptyContentSucceeds(): void
	{
		$path = $this->dir . '/empty.xml';

		$this->assertTrue(Camt053SafeFile::write($path, ''));
		$this->assertSame('', file_get_contents($path));
	}

	/**
	 * An unwritable target reports failure instead of raising a warning, so the
	 * caller keeps the remote copy.
	 *
	 * @return void
	 */
	public function testUnwritableTargetFails(): void
	{
		$path = $this->dir . '/missing-subdir/statement.xml';

		$this->assertFalse(Camt053SafeFile::write($path, '<Document/>'));
		$this->assertFileDoesNotExist($path);
	}

	/**
	 * A write that stalls part way (a filling disk) must be reported as a
	 * failure and must not leave the partial file behind: the runner deletes the
	 * remote statement as soon as a local copy is claimed to exist.
	 *
	 * @return void
	 */
	public function testStalledWriteFailsAndRemovesThePartialFile(): void
	{
		stream_wrapper_register('shortwrite', 'ShortWriteStream');
		ShortWriteStream::$accept = 3;
		ShortWriteStream::$unlinked = false;

		try {
			$this->assertFalse(Camt053SafeFile::write('shortwrite://statement.xml', '<Document/>'));
			$this->assertTrue(ShortWriteStream::$unlinked, 'The truncated file must be removed');
		} finally {
			stream_wrapper_unregister('shortwrite');
		}
	}

	/**
	 * A failed write leaves nothing behind that a later run could mistake for a
	 * valid archive.
	 *
	 * @return void
	 */
	public function testFailedWriteLeavesNoFile(): void
	{
		$path = $this->dir . '/dir-in-the-way';
		mkdir($path);

		$this->assertFalse(Camt053SafeFile::write($path, '<Document/>'));
		$this->assertDirectoryExists($path, 'The existing directory is left alone');

		rmdir($path);
	}
}
