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
 * \file       test/phpunit/CsrfTokenTest.php
 * \ingroup    camt053readerandlink
 * \brief      PHPUnit tests for camt053VerifCsrfToken(), which guards every
 *             write action of the module.
 */

use PHPUnit\Framework\TestCase;

if (!function_exists('GETPOSTISSET')) {
	/**
	 * Minimal stand-in for Dolibarr's GETPOSTISSET.
	 *
	 * @param string $paramname Parameter name
	 * @return bool
	 */
	function GETPOSTISSET($paramname)
	{
		return isset($_POST[$paramname]) || isset($_GET[$paramname]);
	}
}

if (!function_exists('GETPOST')) {
	/**
	 * Minimal stand-in for Dolibarr's GETPOST.
	 *
	 * @param string $paramname Parameter name
	 * @param string $check     Sanitizer (ignored here)
	 * @return string
	 */
	function GETPOST($paramname, $check = 'alphanohtml')
	{
		if (isset($_POST[$paramname])) {
			return (string) $_POST[$paramname];
		}
		return isset($_GET[$paramname]) ? (string) $_GET[$paramname] : '';
	}
}

require_once dirname(__FILE__) . '/../../lib/camt053readerandlink.lib.php';

/**
 * Class CsrfTokenTest
 */
class CsrfTokenTest extends TestCase
{
	/**
	 * Reset the request and session state between cases.
	 *
	 * @return void
	 */
	protected function setUp(): void
	{
		$_POST = array();
		$_GET = array();
		$_SESSION = array();
	}

	/**
	 * The token posted by the form is the one the previous page stored.
	 *
	 * @return void
	 */
	public function testValidTokenPasses(): void
	{
		$_SESSION['token'] = 'abc123';
		$_POST['token'] = 'abc123';

		$this->assertTrue(camt053VerifCsrfToken());
	}

	/**
	 * A different token is rejected.
	 *
	 * @return void
	 */
	public function testWrongTokenFails(): void
	{
		$_SESSION['token'] = 'abc123';
		$_POST['token'] = 'nope';

		$this->assertFalse(camt053VerifCsrfToken());
	}

	/**
	 * No token at all is rejected.
	 *
	 * @return void
	 */
	public function testMissingTokenFails(): void
	{
		$_SESSION['token'] = 'abc123';

		$this->assertFalse(camt053VerifCsrfToken());
	}

	/**
	 * An empty session token must not turn an empty posted token into a pass,
	 * which a plain string comparison would.
	 *
	 * @return void
	 */
	public function testEmptySessionTokenFails(): void
	{
		$_SESSION['token'] = '';
		$_POST['token'] = '';

		$this->assertFalse(camt053VerifCsrfToken());
	}

	/**
	 * Same when the session has no token key at all.
	 *
	 * @return void
	 */
	public function testAbsentSessionTokenFails(): void
	{
		$_POST['token'] = '';

		$this->assertFalse(camt053VerifCsrfToken());
	}
}
