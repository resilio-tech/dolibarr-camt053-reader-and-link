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
 * \file       test/phpunit/FilePatternTest.php
 * \ingroup    camt053readerandlink
 * \brief      PHPUnit tests for the file pattern helper. The cron and the
 *             connection test both classify remote files with it, so what the
 *             test screen announces has to be what the cron then downloads.
 */

use PHPUnit\Framework\TestCase;

if (!function_exists('dol_syslog')) {
	/**
	 * Stand-in for Dolibarr's logger.
	 *
	 * @param string $message Message
	 * @param int    $level   Syslog level
	 * @return void
	 */
	function dol_syslog($message, $level = LOG_INFO)
	{
	}
}

require_once dirname(__FILE__) . '/../../lib/camt053readerandlink.lib.php';

/**
 * Class FilePatternTest
 */
class FilePatternTest extends TestCase
{
	/**
	 * A file the bank delivers for one account matches its pattern, and the
	 * files of the other accounts sharing the directory do not.
	 *
	 * @return void
	 */
	public function testPatternSelectsOnlyTheTargetedFiles()
	{
		$daily = '/^camt053_.*_CH9300762011623852957_.*\.xml$/';

		$this->assertTrue(camt053MatchesFilePattern($daily, 'camt053_20260827_CH9300762011623852957_001.xml'));
		$this->assertFalse(camt053MatchesFilePattern($daily, 'camt053_20260827_CH5604835012345678009_001.xml'));
	}

	/**
	 * No pattern configured means the helper claims nothing: the caller decides
	 * what an absent pattern implies.
	 *
	 * @return void
	 */
	public function testEmptyPatternNeverMatches()
	{
		$this->assertFalse(camt053MatchesFilePattern(null, 'camt053.xml'));
		$this->assertFalse(camt053MatchesFilePattern('', 'camt053.xml'));
	}

	/**
	 * An invalid regex typed in the configuration must not raise, it must
	 * simply match nothing.
	 *
	 * @return void
	 */
	public function testInvalidPatternMatchesNothingWithoutRaising()
	{
		$this->assertFalse(camt053MatchesFilePattern('/camt053(/', 'camt053.xml'));
		$this->assertFalse(camt053MatchesFilePattern('not a regex', 'camt053.xml'));
	}
}
