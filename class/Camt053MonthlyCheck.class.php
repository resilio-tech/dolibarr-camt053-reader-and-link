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
 * \file       class/Camt053MonthlyCheck.class.php
 * \ingroup    camt053readerandlink
 * \brief      Monthly cron job: report to Zulip what the fetch job did over the
 *             previous month, the bank lines still not reconciled and the gap
 *             between the Dolibarr and PostFinance balances.
 */

require_once DOL_DOCUMENT_ROOT . '/core/lib/files.lib.php';
require_once __DIR__ . '/Camt053FileProcessor.class.php';
require_once __DIR__ . '/Camt053ProcessedFile.class.php';
require_once __DIR__ . '/SftpFileTransport.class.php';
require_once __DIR__ . '/ZulipNotifier.class.php';

/**
 * Class Camt053MonthlyCheck
 */
class Camt053MonthlyCheck
{
	/** @var DoliDb Database connection */
	public $db;

	/** @var string Human readable output (shown in the cron job result) */
	public $output = '';

	/** @var string Error message (non-empty means failure) */
	public $error = '';

	/**
	 * Constructor
	 *
	 * @param DoliDb $db Database connection
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * Cron entry point: check the previous month and send the report to Zulip.
	 *
	 * @param string $params Optional parameters (unused)
	 * @return int 0 on success, negative on error
	 */
	public function run($params = ''): int
	{
		$this->output = '';
		$this->error = '';

		$firstDay = date('Y-m-01', strtotime('first day of last month'));
		$lastDay = date('Y-m-t', strtotime($firstDay));

		$tracker = new Camt053ProcessedFile($this->db);
		$totals = $tracker->fetchPeriodTotals(date('Ym', strtotime($firstDay)));
		if ($totals === null) {
			$this->error = (string) $tracker->getError();
			return -1;
		}

		$accounts = $this->fetchAccounts();
		if ($accounts === null) {
			return -1;
		}

		$lines = array();
		$lines[] = '**CAMT.053 monthly check: ' . substr($firstDay, 0, 7) . '**';
		$lines[] = '';
		$lines[] = sprintf(
			'Fetched by the job: %d file(s), %d auto-reconciled, %d ambiguous, %d unmatched, %d in error',
			$totals['files'], $totals['auto'], $totals['ambiguous'], $totals['unmatched'], $totals['errors']
		);

		foreach ($accounts as $account) {
			$lines[] = '';
			$lines = array_merge($lines, $this->accountLines($account, $firstDay, $lastDay));
		}

		if ($this->error !== '') {
			return -1;
		}

		$this->output = implode("\n", $lines);

		$notifier = ZulipNotifier::fromConf();
		if ($notifier === null) {
			$this->error = 'Zulip is not configured';
			return -1;
		}

		$topic = getDolGlobalString('CAMT053_ZULIP_TOPIC') ?: 'CAMT.053 monthly check';
		if (!$notifier->sendStream(getDolGlobalString('CAMT053_ZULIP_STREAM'), $topic, $this->output)) {
			$this->error = 'Zulip report failed: ' . $notifier->getError();
			return -1;
		}

		return 0;
	}

	/**
	 * Open reconcilable bank accounts of the current entity.
	 *
	 * @return array<int,stdClass>|null Null on error
	 */
	private function fetchAccounts(): ?array
	{
		$sql = "SELECT rowid, ref, label, currency_code";
		$sql .= " FROM " . MAIN_DB_PREFIX . "bank_account";
		$sql .= " WHERE entity IN (" . getEntity('bank_account', 0) . ")";
		$sql .= " AND clos = 0 AND rappro = 1";
		$sql .= " ORDER BY ref";

		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = 'Database error: ' . $this->db->lasterror();
			return null;
		}

		$accounts = array();
		while ($obj = $this->db->fetch_object($resql)) {
			$accounts[] = $obj;
		}

		return $accounts;
	}

	/**
	 * Report lines of one bank account.
	 *
	 * @param stdClass $account  Bank account row
	 * @param string   $firstDay First day of the month, Y-m-d
	 * @param string   $lastDay  Last day of the month, Y-m-d
	 * @return array<int,string> Markdown lines
	 */
	private function accountLines($account, string $firstDay, string $lastDay): array
	{
		$id = (int) $account->rowid;

		$lines = array();
		$lines[] = '**' . $account->label . '** (' . $account->ref . ', ' . $account->currency_code . ')';

		$closing = $this->closingBalance($id, $firstDay, $lastDay);
		if ($closing === null) {
			$lines[] = ':x: No PostFinance closing balance received for the month';
		} else {
			$dolibarr = $this->dolibarrBalance($id, $closing['date']);
			$gap = $dolibarr - $closing['amount'];
			$line = (abs($gap) < 0.005 ? ':white_check_mark:' : ':x:') . ' Balance on ' . $closing['date'];
			$line .= ': PostFinance ' . number_format($closing['amount'], 2) . ', Dolibarr ' . number_format($dolibarr, 2);
			if (abs($gap) >= 0.005) {
				$line .= ', gap ' . number_format($gap, 2);
			}
			$lines[] = $line;
		}

		$unreconciled = $this->unreconciledLines($id, $firstDay, $lastDay);
		if ($unreconciled['nb'] === 0) {
			$lines[] = ':white_check_mark: Every bank line of the month is reconciled';
		} else {
			$url = dol_buildpath('/compta/bank/bankentries_list.php', 2) . '?id=' . $id . '&search_conciliated=0'
				. '&search_start_dvday=1&search_start_dvmonth=' . (int) substr($firstDay, 5, 2) . '&search_start_dvyear=' . (int) substr($firstDay, 0, 4)
				. '&search_end_dvday=' . (int) substr($lastDay, 8, 2) . '&search_end_dvmonth=' . (int) substr($lastDay, 5, 2) . '&search_end_dvyear=' . (int) substr($lastDay, 0, 4);
			$lines[] = ':warning: [' . $unreconciled['nb'] . ' bank line(s) of the month not reconciled, total ' . number_format($unreconciled['total'], 2) . '](' . $url . ')';
		}

		return $lines;
	}

	/**
	 * Latest PostFinance closing balance of the month, read from the statements
	 * archived under the account for that month and the following one.
	 *
	 * @param int    $accountId Bank account id
	 * @param string $firstDay  First day of the month, Y-m-d
	 * @param string $lastDay   Last day of the month, Y-m-d
	 * @return array{date:string,amount:float}|null Null when none was received
	 */
	private function closingBalance(int $accountId, string $firstDay, string $lastDay): ?array
	{
		global $conf;

		$found = null;
		foreach (array(date('Ym', strtotime($firstDay)), date('Ym', strtotime($firstDay . ' +1 month'))) as $numReleve) {
			$dir = $conf->bank->dir_output . '/' . $accountId . '/statement/' . $numReleve;

			foreach (dol_dir_list($dir, 'files') as $file) {
				$content = @file_get_contents($file['fullname']);
				if ($content === false) {
					continue;
				}

				foreach (SftpFileTransport::extractXmlPayloads($file['name'], $content) as $xml) {
					$processor = new Camt053FileProcessor($this->db);
					if (!$processor->parseContent($xml)) {
						continue;
					}

					foreach ($processor->getStatements() as $statement) {
						$balance = $statement->getClosingBalance();
						if ((int) $statement->getAccountId() !== $accountId || $balance === null
							|| $balance['date'] < $firstDay || $balance['date'] > $lastDay) {
							continue;
						}
						if ($found === null || $balance['date'] > $found['date']) {
							$found = $balance;
						}
					}
				}
			}
		}

		return $found;
	}

	/**
	 * Dolibarr balance of an account at a date, on the value date.
	 *
	 * @param int    $accountId Bank account id
	 * @param string $date      Date, Y-m-d
	 * @return float
	 */
	private function dolibarrBalance(int $accountId, string $date): float
	{
		$sql = "SELECT SUM(amount) as total FROM " . MAIN_DB_PREFIX . "bank";
		$sql .= " WHERE fk_account = " . $accountId;
		$sql .= " AND datev <= '" . $this->db->escape($date) . "'";

		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error .= 'Database error: ' . $this->db->lasterror() . '; ';
			return 0.0;
		}

		$obj = $this->db->fetch_object($resql);

		return $obj ? (float) $obj->total : 0.0;
	}

	/**
	 * Bank lines of the month not reconciled yet, on the value date.
	 *
	 * @param int    $accountId Bank account id
	 * @param string $firstDay  First day of the month, Y-m-d
	 * @param string $lastDay   Last day of the month, Y-m-d
	 * @return array{nb:int,total:float}
	 */
	private function unreconciledLines(int $accountId, string $firstDay, string $lastDay): array
	{
		$sql = "SELECT COUNT(rowid) as nb, SUM(amount) as total FROM " . MAIN_DB_PREFIX . "bank";
		$sql .= " WHERE fk_account = " . $accountId;
		$sql .= " AND rappro = 0";
		$sql .= " AND datev BETWEEN '" . $this->db->escape($firstDay) . "' AND '" . $this->db->escape($lastDay) . "'";

		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error .= 'Database error: ' . $this->db->lasterror() . '; ';
			return array('nb' => 0, 'total' => 0.0);
		}

		$obj = $this->db->fetch_object($resql);

		return array('nb' => $obj ? (int) $obj->nb : 0, 'total' => $obj ? (float) $obj->total : 0.0);
	}
}
