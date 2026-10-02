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
 * \brief      PHPUnit tests for the CSRF protection of the writing pages. The
 *             check belongs to Dolibarr's main.inc.php; what the module owns is
 *             asking for it, early enough to be seen, and posting a token back.
 */

use PHPUnit\Framework\TestCase;

/**
 * Class CsrfTokenTest
 */
class CsrfTokenTest extends TestCase
{
	/**
	 * Module root.
	 *
	 * @return string
	 */
	private function root(): string
	{
		return (string) realpath(dirname(__FILE__) . '/../..');
	}

	/**
	 * Pages that write, and therefore need a token on every action.
	 *
	 * @return array<int, string>
	 */
	private function writingPages(): array
	{
		return array(
			'submit.php',
			'confirm.php',
			'align_bank_line.php',
			'admin/setup.php',
			'admin/sftp_card.php',
			'admin/sftp_list.php',
		);
	}

	/**
	 * SPEC section 7. A page added later without the constant is what this case
	 * is here to catch.
	 *
	 * @return void
	 */
	public function testEveryWritingPageRequiresTheToken(): void
	{
		foreach ($this->writingPages() as $page) {
			$source = file_get_contents($this->root() . '/' . $page);
			$this->assertNotFalse($source, $page . ' is missing');
			$this->assertStringContainsString(
				"define('CSRFCHECK_WITH_TOKEN', '1')",
				$source,
				$page . ' writes without requiring a token'
			);
		}
	}

	/**
	 * The constant is read while main.inc.php runs, so defining it afterwards
	 * silently protects nothing.
	 *
	 * @return void
	 */
	public function testTheConstantIsDefinedBeforeDolibarrIsLoaded(): void
	{
		foreach ($this->writingPages() as $page) {
			$source = file_get_contents($this->root() . '/' . $page);
			$this->assertLessThan(
				strpos($source, 'main.inc.php'),
				strpos($source, 'CSRFCHECK_WITH_TOKEN'),
				$page . ' defines the constant after loading Dolibarr'
			);
		}
	}

	/**
	 * The other half: a page requiring a token whose forms and action links send
	 * none is a page nobody can use.
	 *
	 * @return void
	 */
	public function testThePagesLeadingToThemSendAToken(): void
	{
		$callers = array(
			'index.php',
			'lib/camt053readerandlink.results.lib.php',
			'admin/setup.php',
			'admin/sftp_card.php',
			'admin/sftp_list.php',
		);

		foreach ($callers as $caller) {
			$source = file_get_contents($this->root() . '/' . $caller);
			$this->assertNotFalse($source, $caller . ' is missing');
			$this->assertStringContainsString('newToken()', $source, $caller . ' posts to a guarded page without a token');
		}
	}

	/**
	 * Every link carrying an action reaches a page that now demands a token, so
	 * none of them may be built without one, wherever it is built.
	 *
	 * @return void
	 */
	public function testActionLinksCarryAToken(): void
	{
		$seen = 0;

		foreach ($this->modulePages() as $page) {
			$lines = file($page);
			foreach ($lines as $number => $line) {
				if (strpos($line, '?action=') === false) {
					continue;
				}
				$seen++;
				$this->assertStringContainsString(
					'token=',
					$line,
					substr($page, strlen($this->root()) + 1) . ' line ' . ($number + 1)
						. ' builds an action link without a token'
				);
			}
		}

		$this->assertGreaterThan(0, $seen, 'No action link found at all, the scan is looking in the wrong place');
	}

	/**
	 * Every PHP file of the module but the tests.
	 *
	 * @return array<int, string>
	 */
	private function modulePages(): array
	{
		$found = array();
		$dirs = new RecursiveDirectoryIterator($this->root(), FilesystemIterator::SKIP_DOTS);

		foreach (new RecursiveIteratorIterator($dirs) as $file) {
			$path = $file->getPathname();
			if (substr($path, -4) !== '.php' || strpos($path, '/test/') !== false || strpos($path, '/.git/') !== false) {
				continue;
			}
			$found[] = $path;
		}

		sort($found);

		return $found;
	}
}
