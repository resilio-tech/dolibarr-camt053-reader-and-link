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
 * \file       test/phpunit/FetchJobWarningTest.php
 * \ingroup    camt053readerandlink
 * \brief      PHPUnit tests for the warning shown when the download is enabled
 *             but no scheduled job will run it.
 */

use PHPUnit\Framework\TestCase;

/**
 * Class FetchJobWarningTest
 */
class FetchJobWarningTest extends TestCase
{
	/**
	 * Load the library with stand-ins for what it reads from Dolibarr.
	 *
	 * @param bool       $fetchEnabled Download switch
	 * @param bool       $cronEnabled  Scheduled jobs module
	 * @param array|bool $activeJob    Row returned for an active fetch job, false for none
	 * @return object Database stand-in
	 */
	private function load(bool $fetchEnabled, bool $cronEnabled, $activeJob)
	{
		global $conf, $langs, $camt053TestConst, $camt053TestCron;

		define('MAIN_DB_PREFIX', 'llx_');
		define('DOL_URL_ROOT', '/dolibarr');

		$camt053TestConst = array('CAMT053_SFTP_FETCH_ENABLED' => $fetchEnabled ? '1' : '0');
		$camt053TestCron = $cronEnabled;

		/**
		 * @param string $key     Constant name
		 * @param string $default Value returned when the constant is not set
		 * @return string
		 */
		function getDolGlobalString($key, $default = '')
		{
			global $camt053TestConst;

			return isset($camt053TestConst[$key]) ? (string) $camt053TestConst[$key] : $default;
		}

		/**
		 * @param string $module Module name
		 * @return bool
		 */
		function isModEnabled($module)
		{
			global $camt053TestCron;

			return $module === 'cron' && $camt053TestCron;
		}

		require_once dirname(__FILE__) . '/../../lib/camt053readerandlink.lib.php';

		$conf = (object) array('entity' => 1);
		$langs = new class {
			/**
			 * @param string $key   Translation key
			 * @param string $param First parameter
			 * @return string
			 */
			public function trans($key, $param = '')
			{
				return $key . ($param !== '' ? '|' . $param : '');
			}
		};

		return new class ($activeJob) {
			/** @var string[] */
			public $queries = array();

			/** @var array|bool */
			private $row;

			/**
			 * @param array|bool $row Row returned by fetch_object
			 */
			public function __construct($row)
			{
				$this->row = $row;
			}

			/**
			 * @param string $sql SQL
			 * @return bool
			 */
			public function query($sql)
			{
				$this->queries[] = $sql;
				return true;
			}

			/**
			 * @param mixed $resql Result
			 * @return object|false
			 */
			public function fetch_object($resql)
			{
				return $this->row ? (object) $this->row : false;
			}
		};
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 *
	 * @return void
	 */
	public function testNothingIsSaidWhileTheDownloadIsDisabled()
	{
		$db = $this->load(false, false, false);

		$this->expectOutputString('');
		camt053WarnIfFetchJobInactive($db);
		$this->assertSame(array(), $db->queries);
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 *
	 * @return void
	 */
	public function testTheScheduledJobsModuleBeingOffIsSaid()
	{
		$db = $this->load(true, false, false);

		$this->expectOutputString('<div class="warning">Camt053CronModuleDisabled</div>');
		camt053WarnIfFetchJobInactive($db);
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 *
	 * @return void
	 */
	public function testAnInactiveFetchJobIsSaidWithALinkToTheJobs()
	{
		$db = $this->load(true, true, false);

		$this->expectOutputString('<div class="warning">Camt053FetchJobInactive|/dolibarr/cron/list.php?search_module_name=camt053readerandlink</div>');
		camt053WarnIfFetchJobInactive($db);
		$this->assertStringContainsString("objectname = 'Camt053CronRunner' AND status = 1", $db->queries[0]);
		$this->assertStringContainsString('entity IN (0, 1)', $db->queries[0]);
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 *
	 * @return void
	 */
	public function testNothingIsSaidWhenTheFetchJobIsActive()
	{
		$db = $this->load(true, true, array('rowid' => 7));

		$this->expectOutputString('');
		camt053WarnIfFetchJobInactive($db);
	}
}
