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
 * \file       test/phpunit/FetchSwitchTest.php
 * \ingroup    camt053readerandlink
 * \brief      PHPUnit tests for the download switch. A fresh install must leave
 *             every remote file where it is until an administrator asks for it.
 */

use PHPUnit\Framework\TestCase;

if (!function_exists('getDolGlobalString')) {
	/**
	 * Stand-in for Dolibarr's constant reader.
	 *
	 * @param string $key     Constant name
	 * @param string $default Value returned when the constant is not set
	 * @return string
	 */
	function getDolGlobalString($key, $default = '')
	{
		global $camt053TestConst;

		return isset($camt053TestConst[$key]) ? (string) $camt053TestConst[$key] : $default;
	}
}

require_once dirname(__FILE__) . '/../../lib/camt053readerandlink.lib.php';

/**
 * Class FetchSwitchTest
 */
class FetchSwitchTest extends TestCase
{
	/**
	 * Reset the simulated constants before each test.
	 *
	 * @return void
	 */
	protected function setUp(): void
	{
		global $camt053TestConst;

		$camt053TestConst = array();
	}

	/**
	 * @return void
	 */
	public function testDownloadIsDisabledWhenTheConstantIsUnset()
	{
		$this->assertFalse(camt053SftpFetchEnabled());
	}

	/**
	 * @return void
	 */
	public function testDownloadIsEnabledOnlyByAnExplicitOne()
	{
		global $camt053TestConst;

		$camt053TestConst['CAMT053_SFTP_FETCH_ENABLED'] = '1';
		$this->assertTrue(camt053SftpFetchEnabled());

		$camt053TestConst['CAMT053_SFTP_FETCH_ENABLED'] = '0';
		$this->assertFalse(camt053SftpFetchEnabled());

		$camt053TestConst['CAMT053_SFTP_FETCH_ENABLED'] = '';
		$this->assertFalse(camt053SftpFetchEnabled());
	}
}
