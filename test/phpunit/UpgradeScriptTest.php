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
 * \file       test/phpunit/UpgradeScriptTest.php
 * \ingroup    camt053readerandlink
 * \brief      PHPUnit tests for the upgrade of an existing installation.
 */

use PHPUnit\Framework\TestCase;

/**
 * Class UpgradeScriptTest
 */
class UpgradeScriptTest extends TestCase
{
	/**
	 * Columns added to a table after its first release.
	 *
	 * @return array<string, array<int, string>>
	 */
	public static function addedColumns(): array
	{
		return array(
			'host_fingerprint' => array('llx_camt053readerandlink_sftpconfig', 'host_fingerprint'),
			'archived_path' => array('llx_camt053readerandlink_processedfile', 'archived_path'),
		);
	}

	/**
	 * A column added after the first release is created on a new installation,
	 * and added on an existing one both when the module is activated again and
	 * when Dolibarr is upgraded.
	 *
	 * @dataProvider addedColumns
	 * @param string $table  Table
	 * @param string $column Column
	 * @return void
	 */
	public function testAnAddedColumnReachesExistingInstallations(string $table, string $column): void
	{
		$sqlDir = dirname(__FILE__) . '/../../sql/';
		$alter = '/ALTER TABLE ' . $table . ' ADD COLUMN ' . $column . '\s/';

		$updates = '';
		foreach (glob($sqlDir . 'update_*.sql') as $file) {
			$updates .= file_get_contents($file);
		}

		$this->assertMatchesRegularExpression('/^\s*' . $column . '\s/m', (string) file_get_contents($sqlDir . $table . '.sql'));
		$this->assertMatchesRegularExpression($alter, $updates);
		$this->assertMatchesRegularExpression($alter, (string) file_get_contents($sqlDir . 'dolibarr_allversions.sql'));
	}
}
