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
 * \file       class/Camt053CronRunner.class.php
 * \ingroup    camt053readerandlink
 * \brief      Cron orchestrator: fetch CAMT.053 files over SFTP, reconcile unique
 *             matches, track processed files, and send a Zulip report for the
 *             monthly statement file.
 */

require_once DOL_DOCUMENT_ROOT . '/core/lib/files.lib.php';
require_once DOL_DOCUMENT_ROOT . '/compta/bank/class/account.class.php';
require_once __DIR__ . '/Camt053FileOutcome.class.php';
require_once __DIR__ . '/Camt053SftpConfig.class.php';
require_once __DIR__ . '/Camt053ProcessedFile.class.php';
require_once __DIR__ . '/SftpFileTransport.class.php';
require_once __DIR__ . '/ReconciliationService.class.php';
require_once __DIR__ . '/ZulipNotifier.class.php';

/**
 * Class Camt053CronRunner
 *
 * Entry point for the scheduled job. Designed to be safe against the PostFinance
 * 3-strike lockout: a single login attempt per config and never a retry loop.
 */
class Camt053CronRunner
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
	 * Cron entry point: process every active SFTP config.
	 *
	 * @param string $params Optional parameters (unused)
	 * @return int 0 on success, negative on error
	 */
	public function run($params = ''): int
	{
		global $user, $langs;

		$this->output = '';
		$this->error = '';

		$configLoader = new Camt053SftpConfig($this->db);
		$configs = $configLoader->fetchAll(true);

		if (empty($configs)) {
			$this->output = 'No active SFTP config to process';
			return 0;
		}

		$lines = array();
		foreach ($configs as $config) {
			$line = $this->processConfig($config, $user, $langs);
			$lines[] = $line;
		}

		$this->output = implode("\n", $lines);
		return ($this->error !== '') ? -1 : 0;
	}

	/**
	 * Process a single SFTP config: connect, sweep files, reconcile, report.
	 *
	 * @param Camt053SftpConfig $config Config to process
	 * @param User              $user   User for reconciliation
	 * @param Translate|null    $langs  Language object
	 * @return string One-line summary for the config
	 */
	private function processConfig(Camt053SftpConfig $config, $user, $langs): string
	{
		$transport = new SftpFileTransport($config);

		// SINGLE login attempt: do not retry (PostFinance locks after 3 failures).
		if (!$transport->connect()) {
			$msg = 'connection failed: ' . $transport->getError();
			$config->recordRun($msg);
			$this->error .= '[' . $config->ref . '] ' . $msg . '; ';
			$this->alertConnectionFailure($config, $transport->getError());
			return $config->ref . ': ' . $msg;
		}

		$files = $transport->listFiles(null);
		if ($files === null) {
			$transport->disconnect();
			$msg = 'list failed: ' . $transport->getError();
			$config->recordRun($msg);
			$this->error .= '[' . $config->ref . '] ' . $msg . '; ';
			return $config->ref . ': ' . $msg;
		}

		$service = new ReconciliationService($this->db, $user, $langs, 1);
		$processedTracker = new Camt053ProcessedFile($this->db);

		$counters = array('files' => 0, 'skipped' => 0, 'auto' => 0, 'ambiguous' => 0, 'unmatched' => 0, 'errors' => 0);
		$monthlySummaries = array();

		foreach ($files as $name) {
			$isDaily = $this->matchesPattern($config->daily_pattern, $name);
			$isMonthly = $this->matchesPattern($config->monthly_pattern, $name);

			// When patterns are configured, ignore files that match neither.
			if ((!empty($config->daily_pattern) || !empty($config->monthly_pattern)) && !$isDaily && !$isMonthly) {
				continue;
			}

			$content = $transport->getContent($name);
			if ($content === null) {
				$counters['errors']++;
				dol_syslog('CAMT053 cron: cannot download ' . $name . ' - ' . $transport->getError(), LOG_ERR);
				continue;
			}

			$hash = hash('sha256', $content);
			if ($processedTracker->isProcessed($hash)) {
				$counters['skipped']++;
				// Already handled in a previous run: clean it up if requested.
				$this->postDownloadCleanup($transport, $config, $name);
				continue;
			}

			$summary = $this->processFileContent($service, $name, $content);

			// Parsing/extraction failure: never mark as processed nor delete the
			// remote file, otherwise the source would be lost for good. Count it
			// as an error so it stays visible and gets retried on the next run.
			if (!$summary['success']) {
				$counters['errors']++;
				$this->error .= '[' . $config->ref . '] ' . $name . ': ' . ($summary['error'] ?: 'parsing failed') . '; ';
				dol_syslog('CAMT053 cron: ' . $name . ' not processed - ' . ($summary['error'] ?: 'parsing failed'), LOG_ERR);
				continue;
			}

			$counters['files']++;
			$counters['auto'] += $summary['totals']['auto'];
			$counters['ambiguous'] += $summary['totals']['ambiguous'];
			$counters['unmatched'] += $summary['totals']['unmatched'];
			$counters['errors'] += $summary['totals']['errors'];

			// Whatever matched is reconciled and archived by now. Capture the
			// monthly summary before any early exit below: it is the only place
			// unresolved IBANs are ever shown to a human.
			$this->archiveForSummary($name, $content, $summary);
			if ($isMonthly) {
				$monthlySummaries[$name] = $summary;
			}

			// A statement whose IBAN resolves to no Dolibarr account is data
			// archiveForSummary() does not write anywhere. Keep a copy of the raw
			// file so the remote one is no longer the only one, then carry on with
			// the normal bookkeeping: the condition is permanent (a foreign or
			// closed account), so pinning the file to the server would re-download,
			// re-parse and re-error it on every run forever, pinning the job to
			// "failed" and drowning any genuine failure.
			$reason = Camt053FileOutcome::unresolvedReason($summary);
			if ($reason !== '') {
				$counters['errors']++;

				if (!$this->archiveUnresolved($config, $name, $content)) {
					// Nothing was written locally: the remote copy is the only one
					// left, so it must stay until the archive succeeds.
					$this->error .= '[' . $config->ref . '] ' . $name . ': ' . $reason
						. ' and archiving failed, file kept on the server; ';
					dol_syslog('CAMT053 cron: ' . $name . ' - ' . $reason . ' and archiving failed, keeping the remote file', LOG_ERR);
					continue;
				}

				$this->error .= '[' . $config->ref . '] ' . $name . ': ' . $reason . '; ';
				dol_syslog('CAMT053 cron: ' . $name . ' - ' . $reason . ', raw file archived locally', LOG_WARNING);
			}

			$this->recordProcessed($processedTracker, $config, $name, $hash, $summary, $isMonthly);
			$this->postDownloadCleanup($transport, $config, $name);
		}

		$transport->disconnect();

		if (!empty($monthlySummaries)) {
			$this->sendMonthlyReport($config, $monthlySummaries);
		}

		$status = sprintf(
			'%d file(s), %d auto, %d ambiguous, %d unmatched, %d skipped, %d error(s)',
			$counters['files'], $counters['auto'], $counters['ambiguous'], $counters['unmatched'], $counters['skipped'], $counters['errors']
		);
		$config->recordRun($status);

		return $config->ref . ': ' . $status;
	}

	/**
	 * Reconcile all XML payloads contained in a downloaded file, merging summaries.
	 *
	 * @param ReconciliationService $service Reconciliation service
	 * @param string                $name    File name
	 * @param string                $content Raw file content
	 * @return array Merged summary
	 */
	private function processFileContent(ReconciliationService $service, string $name, string $content): array
	{
		$payloads = SftpFileTransport::extractXmlPayloads($name, $content);
		$merged = array(
			'success' => true,
			'error' => null,
			'accounts' => array(),
			'unresolved_ibans' => array(),
			'totals' => array('auto' => 0, 'ambiguous' => 0, 'unmatched' => 0, 'errors' => 0),
		);

		if (empty($payloads)) {
			$merged['success'] = false;
			$merged['error'] = 'no XML payload found';
			dol_syslog('CAMT053 cron: no XML payload found in ' . $name, LOG_WARNING);
			return $merged;
		}

		foreach ($payloads as $xml) {
			$summary = $service->processContent($xml);

			// A payload that could not be parsed marks the whole file as failed so
			// it is neither recorded nor deleted (reconciliation stays idempotent).
			if (!$summary['success']) {
				$merged['success'] = false;
				$merged['error'] = $summary['error'] ?: 'unable to parse CAMT.053 content';
				$merged['totals']['errors']++;
				continue;
			}

			foreach ($summary['accounts'] as $accountId => $account) {
				if (!isset($merged['accounts'][$accountId])) {
					$merged['accounts'][$accountId] = $account;
				} else {
					$merged['accounts'][$accountId]['auto'] = array_merge($merged['accounts'][$accountId]['auto'], $account['auto']);
					$merged['accounts'][$accountId]['ambiguous'] = array_merge($merged['accounts'][$accountId]['ambiguous'], $account['ambiguous']);
					$merged['accounts'][$accountId]['unmatched'] = array_merge($merged['accounts'][$accountId]['unmatched'], $account['unmatched']);
					$merged['accounts'][$accountId]['errors'] = array_merge($merged['accounts'][$accountId]['errors'], $account['errors']);
					$merged['accounts'][$accountId]['already'] += $account['already'];
				}
			}

			foreach ($summary['unresolved_ibans'] as $iban => $count) {
				$merged['unresolved_ibans'][$iban] = ($merged['unresolved_ibans'][$iban] ?? 0) + $count;
			}

			foreach (array('auto', 'ambiguous', 'unmatched', 'errors') as $k) {
				$merged['totals'][$k] += $summary['totals'][$k];
			}
		}

		return $merged;
	}

	/**
	 * Archive the downloaded file under each resolved account/statement.
	 *
	 * @param string $name    File name
	 * @param string $content Raw content
	 * @param array  $summary Merged summary
	 * @return void
	 */
	/**
	 * Archive the raw file outside any bank account.
	 *
	 * archiveForSummary() files a copy under each account it matched. When an
	 * IBAN matched nothing, that leaves the statement with no copy at all, and
	 * the remote file becomes the only one in existence. This writes it under the
	 * module's own directory so the remote copy can be released.
	 *
	 * @param Camt053SftpConfig $config  Config the file came from
	 * @param string            $name    Remote file name
	 * @param string            $content Raw file content
	 * @return bool True when a copy exists on disk afterwards
	 */
	private function archiveUnresolved(Camt053SftpConfig $config, string $name, string $content): bool
	{
		$targetDir = DOL_DATA_ROOT . '/camt053readerandlink/unresolved/' . dol_sanitizeFileName($config->ref);
		$targetFile = $targetDir . '/' . dol_sanitizeFileName($name);

		if (!is_dir($targetDir)) {
			dol_mkdir($targetDir);
		}
		if (file_exists($targetFile)) {
			return true;
		}
		if (file_put_contents($targetFile, $content) === false) {
			dol_syslog('CAMT053 cron: failed to archive unresolved file to ' . $targetFile, LOG_ERR);
			return false;
		}

		dol_syslog('CAMT053 cron: unresolved statement archived to ' . $targetFile, LOG_WARNING);

		return true;
	}

	private function archiveForSummary(string $name, string $content, array $summary): void
	{
		global $conf;

		foreach ($summary['accounts'] as $accountId => $account) {
			$id = (int) $accountId;
			if ($id <= 0 || empty($account['num_releve'])) {
				continue;
			}

			$object = new Account($this->db);
			if ($object->fetch($id) <= 0) {
				continue;
			}

			$safe = dol_sanitizeFileName($name);
			$targetDir = $conf->bank->dir_output . '/' . $id . '/statement/' . dol_sanitizeFileName($account['num_releve']);
			$targetFile = $targetDir . '/' . $safe;

			if (!is_dir($targetDir)) {
				dol_mkdir($targetDir);
			}
			if (file_exists($targetFile)) {
				continue;
			}
			if (file_put_contents($targetFile, $content) === false) {
				dol_syslog('CAMT053 cron: failed to archive ' . $name . ' to ' . $targetFile, LOG_ERR);
				continue;
			}

			$resindex = addFileIntoDatabaseIndex($targetDir, $safe, $name, 'uploaded', 1, $object);
			if ($resindex < 0) {
				dol_syslog('CAMT053 cron: archived ' . $targetFile . ' but database indexing failed', LOG_WARNING);
			}
		}
	}

	/**
	 * Insert the processed-file tracking record.
	 *
	 * @param Camt053ProcessedFile $tracker   Tracker
	 * @param Camt053SftpConfig    $config    Config
	 * @param string               $name      File name
	 * @param string               $hash      File hash
	 * @param array                $summary   Merged summary
	 * @param bool                 $isMonthly Whether this is the monthly file
	 * @return void
	 */
	private function recordProcessed(Camt053ProcessedFile $tracker, Camt053SftpConfig $config, string $name, string $hash, array $summary, bool $isMonthly): void
	{
		$firstAccount = null;
		foreach ($summary['accounts'] as $account) {
			$firstAccount = $account;
			break;
		}

		$record = new Camt053ProcessedFile($this->db);
		$record->fk_config = (int) $config->id;
		$record->filename = $name;
		$record->file_hash = $hash;
		$record->fk_bank_account = $firstAccount ? (int) $firstAccount['account_id'] : null;
		$record->num_releve = $firstAccount ? $firstAccount['num_releve'] : null;
		$record->is_monthly = $isMonthly ? 1 : 0;
		$record->nb_auto = (int) $summary['totals']['auto'];
		$record->nb_ambiguous = (int) $summary['totals']['ambiguous'];
		$record->nb_unmatched = (int) $summary['totals']['unmatched'];
		$record->status = ($summary['totals']['errors'] > 0) ? 'error' : 'done';
		$record->error_detail = $this->collectErrorDetail($summary);

		if ($record->create() < 0) {
			dol_syslog('CAMT053 cron: failed to record processed file ' . $name . ' - ' . $record->getError(), LOG_ERR);
		}
	}

	/**
	 * Aggregate per-line reconciliation error reasons into a short detail string.
	 *
	 * @param array $summary Merged summary
	 * @return string|null Concatenated reasons, or null when there is none
	 */
	private function collectErrorDetail(array $summary): ?string
	{
		$reasons = array();
		foreach ($summary['accounts'] as $account) {
			foreach (($account['errors'] ?? array()) as $err) {
				if (!empty($err['reason'])) {
					$reasons[] = $err['reason'];
				}
			}
		}

		return empty($reasons) ? null : implode('; ', $reasons);
	}

	/**
	 * Delete the remote file when the config asks for it.
	 *
	 * @param SftpFileTransport $transport Transport
	 * @param Camt053SftpConfig $config    Config
	 * @param string            $name      File name
	 * @return void
	 */
	private function postDownloadCleanup(SftpFileTransport $transport, Camt053SftpConfig $config, string $name): void
	{
		if ($config->post_download_action !== 'delete') {
			return;
		}
		if (!$transport->delete($name)) {
			dol_syslog('CAMT053 cron: failed to delete remote file ' . $name . ' - ' . $transport->getError(), LOG_WARNING);
		}
	}

	/**
	 * Build and send the consolidated Zulip report for the monthly file(s).
	 *
	 * @param Camt053SftpConfig $config           Config
	 * @param array             $monthlySummaries Summaries keyed by file name
	 * @return void
	 */
	private function sendMonthlyReport(Camt053SftpConfig $config, array $monthlySummaries): void
	{
		$notifier = ZulipNotifier::fromConf();
		if ($notifier === null) {
			dol_syslog('CAMT053 cron: monthly file processed but Zulip is not configured', LOG_WARNING);
			return;
		}

		$content = $this->formatReport($config, $monthlySummaries);
		$stream = getDolGlobalString('CAMT053_ZULIP_STREAM');
		$topic = getDolGlobalString('CAMT053_ZULIP_TOPIC');
		if ($topic === '') {
			$topic = 'CAMT.053 ' . $config->ref;
		}

		if (!$notifier->sendStream($stream, $topic, $content)) {
			dol_syslog('CAMT053 cron: Zulip report failed - ' . $notifier->getError(), LOG_ERR);
			$this->error .= '[' . $config->ref . '] Zulip report failed; ';
		}
	}

	/**
	 * Send a short Zulip alert when a connection fails (lockout risk).
	 *
	 * @param Camt053SftpConfig $config Config
	 * @param string|null       $detail Error detail
	 * @return void
	 */
	private function alertConnectionFailure(Camt053SftpConfig $config, ?string $detail): void
	{
		$notifier = ZulipNotifier::fromConf();
		if ($notifier === null) {
			return;
		}

		$stream = getDolGlobalString('CAMT053_ZULIP_STREAM');
		$topic = getDolGlobalString('CAMT053_ZULIP_TOPIC');
		if ($topic === '') {
			$topic = 'CAMT.053 ' . $config->ref;
		}

		$content = ":warning: **CAMT.053 SFTP connection failed** for `" . $config->ref . "` (" . $config->host . ")\n";
		$content .= '> ' . ($detail ?: 'unknown error') . "\n";
		$content .= "_Careful: PostFinance locks the account after 3 failed logins._";

		$notifier->sendStream($stream, $topic, $content);
	}

	/**
	 * Format the Zulip Markdown report.
	 *
	 * @param Camt053SftpConfig $config           Config
	 * @param array             $monthlySummaries Summaries keyed by file name
	 * @return string
	 */
	private function formatReport(Camt053SftpConfig $config, array $monthlySummaries): string
	{
		$lines = array();
		$lines[] = '**CAMT.053 monthly reconciliation — ' . ($config->label ?: $config->ref) . '**';

		foreach ($monthlySummaries as $name => $summary) {
			$lines[] = '';
			$lines[] = '*File: `' . $name . '`*';

			if (empty($summary['accounts'])) {
				$lines[] = '_No reconcilable account found in this file._';
			}

			foreach ($summary['accounts'] as $account) {
				$lines[] = '';
				$lines[] = 'Account `' . $account['iban'] . '` — statement ' . $account['num_releve'];
				$lines[] = ':white_check_mark: Auto-reconciled: ' . count($account['auto']);
				$lines[] = ':warning: Ambiguous (manual): ' . count($account['ambiguous']);
				$lines = array_merge($lines, $this->formatEntryList($account['ambiguous']));
				$lines[] = ':x: Unmatched: ' . count($account['unmatched']);
				$lines = array_merge($lines, $this->formatEntryList($account['unmatched']));
				if (!empty($account['errors'])) {
					$lines[] = ':red_circle: Errors: ' . count($account['errors']);
				}
				if (!empty($account['already'])) {
					$lines[] = '_Already reconciled: ' . $account['already'] . '_';
				}
			}

			if (!empty($summary['unresolved_ibans'])) {
				$lines[] = '';
				$lines[] = ':grey_question: Unresolved IBANs (no Dolibarr account):';
				foreach ($summary['unresolved_ibans'] as $iban => $count) {
					$lines[] = '- `' . $iban . '` (' . $count . ' entries)';
				}
			}
		}

		return implode("\n", $lines);
	}

	/**
	 * Format a short bullet list of entries for the report (capped).
	 *
	 * @param array $entries Entry info rows
	 * @return array<int, string> Markdown lines
	 */
	private function formatEntryList(array $entries): array
	{
		$lines = array();
		$max = 20;
		$i = 0;
		foreach ($entries as $entry) {
			if ($i >= $max) {
				$lines[] = '  - … and ' . (count($entries) - $max) . ' more';
				break;
			}
			$amount = number_format((float) $entry['amount'], 2);
			$lines[] = '  - ' . $amount . ' on ' . $entry['date'] . ' — ' . dol_trunc((string) $entry['name'], 60);
			$i++;
		}
		return $lines;
	}

	/**
	 * Whether a file name matches a configured PCRE pattern.
	 *
	 * @param string|null $pattern Pattern (with delimiters) or null
	 * @param string      $name    File name
	 * @return bool
	 */
	private function matchesPattern(?string $pattern, string $name): bool
	{
		if (empty($pattern)) {
			return false;
		}

		$result = @preg_match($pattern, $name);
		if ($result === false) {
			// An invalid admin-supplied regex would otherwise silently make the
			// cron skip every file, with nothing in the log to explain why.
			// preg_last_error_msg() is PHP 8.0+, the module supports 7.4.
			dol_syslog('CAMT053: invalid file pattern ' . $pattern . ' (preg error ' . preg_last_error() . ')', LOG_ERR);
			return false;
		}

		return (bool) $result;
	}
}
