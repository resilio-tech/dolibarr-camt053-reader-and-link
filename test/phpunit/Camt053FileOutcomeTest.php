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
 * \file       test/phpunit/Camt053FileOutcomeTest.php
 * \ingroup    camt053readerandlink
 * \brief      PHPUnit tests for the headless runner's decision on what to do
 *             with a downloaded file. Getting this wrong either loses a
 *             statement or pins the scheduled job to "failed" forever.
 */

use PHPUnit\Framework\TestCase;

require_once dirname(__FILE__) . '/../../class/Camt053FileOutcome.class.php';

/**
 * Class Camt053FileOutcomeTest
 */
class Camt053FileOutcomeTest extends TestCase
{
	/**
	 * @param array $summary Reconciliation summary
	 * @return string
	 */
	private function reason(array $summary): string
	{
		return Camt053FileOutcome::unresolvedReason($summary);
	}

	/**
	 * A file whose statements all found their account needs no special handling.
	 *
	 * @return void
	 */
	public function testFullyResolvedFileHasNoReason(): void
	{
		$summary = array(
			'accounts' => array(3 => array('iban' => 'CH00')),
			'unresolved_ibans' => array(),
		);

		$this->assertSame('', $this->reason($summary));
	}

	/**
	 * One unresolved IBAN is reported even when another account did resolve:
	 * that statement is archived nowhere and would otherwise vanish when the
	 * remote file is deleted.
	 *
	 * @return void
	 */
	public function testPartiallyResolvedFileIsReported(): void
	{
		$summary = array(
			'accounts' => array(3 => array('iban' => 'CH00')),
			'unresolved_ibans' => array('CH99 9999' => 4),
		);

		$this->assertStringContainsString('CH99 9999', $this->reason($summary));
	}

	/**
	 * Nothing resolved at all.
	 *
	 * @return void
	 */
	public function testNothingResolvedIsReported(): void
	{
		$summary = array(
			'accounts' => array(),
			'unresolved_ibans' => array('CH99 9999' => 1),
		);

		$this->assertStringContainsString('CH99 9999', $this->reason($summary));
	}

	/**
	 * A file that parsed but carries no readable IBAN at all still needs a
	 * message: an empty one used to read "no bank account matches ,".
	 *
	 * @return void
	 */
	public function testFileWithoutAnyIbanGetsAReadableReason(): void
	{
		$summary = array(
			'accounts' => array(),
			'unresolved_ibans' => array(),
		);

		$reason = $this->reason($summary);

		$this->assertNotSame('', $reason);
		$this->assertStringNotContainsString('matches ,', $reason);
	}

	/**
	 * The summary keys are optional in older payloads.
	 *
	 * @return void
	 */
	public function testMissingKeysAreTreatedAsUnresolved(): void
	{
		$this->assertNotSame('', $this->reason(array()));
	}
}
