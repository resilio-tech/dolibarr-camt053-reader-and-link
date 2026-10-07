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
 * \file       test/phpunit/MenuConditionTest.php
 * \ingroup    camt053readerandlink
 * \brief      PHPUnit tests for the conditions Dolibarr evaluates on our behalf
 */

use PHPUnit\Framework\TestCase;

/**
 * Class MenuConditionTest
 *
 * The menu entry, its permission and the scheduled job all carry a condition
 * string that Dolibarr evaluates through dol_eval(). Since the fix for advisory
 * GHSA-x3w7-24rq-gvc5, shipped with Dolibarr 23, only the functions of its
 * whitelist may be called, and the name is compared exactly as written. A single
 * wrong letter makes the condition refused, and verifCond() turns a refused
 * condition into false: the entry then disappears with nothing said anywhere,
 * which is how the module lost its menu on every Dolibarr from 23 on.
 */
class MenuConditionTest extends TestCase
{
	/**
	 * The functions Dolibarr accepts in an evaluated condition, as
	 * $dolibarr_main_restrict_eval_methods lists them by default.
	 *
	 * @return array<int, string>
	 */
	private function allowedFunctions(): array
	{
		return array(
			'getDolGlobalString', 'getDolGlobalInt', 'getDolCurrency', 'getDolEntity', 'getDolDBType',
			'fetchNoCompute', 'hasRight', 'isAdmin', 'isExternalUser', 'isModEnabled', 'isStringVarMatching',
			'abs', 'min', 'max', 'round', 'dol_now', 'preg_match',
		);
	}

	/**
	 * Every condition string of the module descriptor, keyed by the field it
	 * belongs to.
	 *
	 * @return array<int, array{0:string,1:string}>
	 */
	private function conditions(): array
	{
		$path = dirname(__FILE__) . '/../../core/modules/modCamt053ReaderAndLink.class.php';
		$source = file_get_contents($path);
		$this->assertNotFalse($source, 'The module descriptor is missing');

		$matches = array();
		preg_match_all("/'(enabled|perms|test)'\s*=>\s*'([^']*)'/", (string) $source, $matches, PREG_SET_ORDER);

		$conditions = array();
		foreach ($matches as $match) {
			if (trim($match[2]) !== '') {
				$conditions[] = array($match[1], $match[2]);
			}
		}

		return $conditions;
	}

	/**
	 * The descriptor must carry conditions at all: a test finding none would
	 * pass while proving nothing.
	 *
	 * @return void
	 */
	public function testTheDescriptorCarriesConditions(): void
	{
		$conditions = $this->conditions();

		$this->assertNotEmpty($conditions);

		$fields = array();
		foreach ($conditions as $condition) {
			$fields[] = $condition[0];
		}
		$this->assertContains('enabled', $fields, 'The menu entry has no enabled condition');
		$this->assertContains('perms', $fields, 'The menu entry has no permission condition');
	}

	/**
	 * Same reading as Dolibarr's: the name written before an opening
	 * parenthesis has to be one of the allowed functions, spelled the same way.
	 *
	 * @return void
	 */
	public function testEveryConditionOnlyCallsFunctionsDolibarrAllows(): void
	{
		foreach ($this->conditions() as $condition) {
			list($field, $expression) = $condition;

			$matches = array();
			preg_match_all('/([\s\w\'\]\"]+)\(/', $expression, $matches);

			foreach ($matches[1] as $called) {
				$called = trim($called);
				if ($called === '' || $called === "'" || $called === '"') {
					continue;
				}

				$this->assertContains(
					$called,
					$this->allowedFunctions(),
					$field . ' calls "' . $called . '", which Dolibarr refuses to evaluate: ' . $expression
				);
			}
		}
	}

	/**
	 * The stored menu row keeps the condition it was created with, so an
	 * install that already carries the refused one has to be repaired on
	 * upgrade.
	 *
	 * @return void
	 */
	public function testExistingInstallationsAreRepaired(): void
	{
		$sql = file_get_contents(dirname(__FILE__) . '/../../sql/dolibarr_allversions.sql');
		$this->assertNotFalse($sql);

		$this->assertStringContainsString('UPDATE llx_menu', (string) $sql);
		$this->assertStringContainsString("REPLACE(enabled, 'isModenabled', 'isModEnabled')", (string) $sql);
	}
}
