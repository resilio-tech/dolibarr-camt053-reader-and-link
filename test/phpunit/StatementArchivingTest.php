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
 * \file       test/phpunit/StatementArchivingTest.php
 * \ingroup    camt053readerandlink
 * \brief      PHPUnit tests for SPEC section 5: an uploaded statement is always
 *             archived under its bank account, whatever it reconciled
 */

use PHPUnit\Framework\TestCase;

/**
 * Class StatementArchivingTest
 */
class StatementArchivingTest extends TestCase
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
	 * Read one of the module files.
	 *
	 * @param string $path Path relative to the module root
	 * @return string
	 */
	private function source(string $path): string
	{
		$source = file_get_contents($this->root() . '/' . $path);
		$this->assertNotFalse($source, $path . ' is missing');

		return (string) $source;
	}

	/**
	 * Both interactive paths can reach the end of an upload, so both have to
	 * file it. A page archiving on its own is what let the two of them drift.
	 *
	 * @return void
	 */
	public function testBothUploadPathsArchiveThroughTheSharedHelper(): void
	{
		foreach (array('confirm.php', 'submit.php') as $page) {
			$this->assertStringContainsString(
				'camt053ArchiveStatementFile(',
				$this->source($page),
				$page . ' does not archive the statement it received'
			);
		}
	}

	/**
	 * A confirmation that reconciled nothing has no linked line to read the
	 * account from, and the account is what decides where the file is filed.
	 * Without the fallback the upload stayed in the upload directory.
	 *
	 * @return void
	 */
	public function testConfirmingWithoutReconciliationStillResolvesTheAccount(): void
	{
		$source = $this->source('confirm.php');

		$fallback = strpos($source, 'parseStructure($file_json)');
		$this->assertNotFalse($fallback, 'confirm.php never derives the account from the file');

		$archiving = strpos($source, 'camt053ArchiveStatementFile(');
		$this->assertNotFalse($archiving);
		$this->assertLessThan(
			$archiving,
			$fallback,
			'The account is derived from the file after the file is archived'
		);
	}

	/**
	 * Nothing to reconcile sends the user straight to the bank statement page.
	 * The archiving has to happen before that redirect, since the page exits on
	 * it and confirm.php is never reached.
	 *
	 * @return void
	 */
	public function testTheRedirectPathArchivesBeforeRedirecting(): void
	{
		$source = $this->source('submit.php');

		$archiving = strpos($source, 'camt053ArchiveStatementFile(');
		$this->assertNotFalse($archiving, 'submit.php redirects without archiving');

		$redirect = strpos($source, '$redirectUrl = DOL_URL_ROOT');
		$this->assertNotFalse($redirect);
		$this->assertLessThan(
			$redirect,
			$archiving,
			'The statement is archived after the redirect is prepared'
		);
	}

	/**
	 * SPEC section 5. Indexing before the move leaves an ecm_files row pointing
	 * at a file that is not on disk, and Dolibarr then refuses a manual
	 * attachment claiming the file already exists.
	 *
	 * @return void
	 */
	public function testTheFileIsMovedBeforeItIsIndexed(): void
	{
		$source = $this->source('lib/camt053readerandlink.lib.php');

		$move = strpos($source, 'Camt053StatementArchive::store(');
		$index = strpos($source, 'addFileIntoDatabaseIndex(');

		$this->assertNotFalse($move, 'The helper does not move the statement file');
		$this->assertNotFalse($index, 'The helper does not index the statement file');
		$this->assertLessThan($index, $move, 'The file is indexed before it is moved');
	}

	/**
	 * An account that could not be resolved must never end up as account 0: the
	 * file would be archived under a directory belonging to no account.
	 *
	 * @return void
	 */
	public function testNothingIsArchivedWithoutAnAccountAndAReference(): void
	{
		$source = $this->source('lib/camt053readerandlink.lib.php');

		$this->assertStringContainsString(
			"\$accountId <= 0 || \$numref === ''",
			$source,
			'The helper archives without an account or without a statement reference'
		);
	}
}
