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
 * \brief      Cron orchestrator: fetch the daily CAMT.053 statements and their PDF
 *             over SFTP, reconcile unique matches, track processed files, and
 *             tell Zulip what could not be settled.
 */

require_once DOL_DOCUMENT_ROOT . '/core/lib/files.lib.php';
require_once DOL_DOCUMENT_ROOT . '/compta/bank/class/account.class.php';
require_once __DIR__ . '/../lib/camt053readerandlink.lib.php';
require_once __DIR__ . '/Camt053ReviewAlert.class.php';
require_once __DIR__ . '/Camt053FileOutcome.class.php';
require_once __DIR__ . '/Camt053SafeFile.class.php';
require_once __DIR__ . '/Camt053ArchivePath.class.php';
require_once __DIR__ . '/Camt053SftpConfig.class.php';
require_once __DIR__ . '/Camt053ProcessedFile.class.php';
require_once __DIR__ . '/Camt053RemoteFile.class.php';
require_once __DIR__ . '/SftpFileTransport.class.php';
require_once __DIR__ . '/Camt053HostKey.class.php';
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
	 * @param string ...$hours Hours the job works at, Swiss time, none to work now
	 * @return int 0 on success, negative on error
	 */
	public function run(...$hours): int
	{
		global $user, $langs;

		$this->output = '';
		$this->error = '';

		if (!camt053IsCheckHour($hours)) {
			$this->output = 'Not a check hour (' . implode(', ', $hours) . ')';
			return 0;
		}

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
			$this->alertConnectionFailure($config, $transport->getError(), $transport->isHostKeyMismatch());
			return $config->ref . ': ' . $msg;
		}

		$this->rememberHostKey($config, $transport);

		$files = $transport->listFiles(null);
		if ($files === null) {
			$transport->disconnect();
			$msg = 'list failed: ' . $transport->getError();
			$config->recordRun($msg);
			$this->error .= '[' . $config->ref . '] ' . $msg . '; ';
			return $config->ref . ': ' . $msg;
		}

		$selected = Camt053RemoteFile::select($files);

		if (!camt053SftpFetchEnabled()) {
			$transport->disconnect();
			foreach (array_merge($selected['statements'], $selected['pdfs']) as $name) {
				dol_syslog('CAMT053 cron: download disabled, leaving ' . $name . ' on the server', LOG_INFO);
			}
			$status = sprintf(
				'download disabled: %d file(s) on the server, %d statement(s) and %d PDF(s) to take',
				count($files), count($selected['statements']), count($selected['pdfs'])
			);
			$config->recordRun($status);

			return $config->ref . ': ' . $status;
		}

		$service = new ReconciliationService($this->db, $user, $langs, 1);
		$processedTracker = new Camt053ProcessedFile($this->db);

		$counters = array('files' => 0, 'pdfs' => 0, 'skipped' => 0, 'auto' => 0, 'ambiguous' => 0, 'unmatched' => 0, 'errors' => 0);
		$unresolvedFiles = array();
		$reviewFiles = array();

		foreach ($selected['statements'] as $name) {
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

			$archived = $this->archiveForSummary($name, $content, $summary);

			// Nothing landed on disk although there was something to write (full
			// or unwritable bank document directory): the remote copy is the only
			// one left and must stay, whatever the reconciliation achieved.
			if (!$archived['ok']) {
				$this->error .= '[' . $config->ref . '] ' . $name . ': archiving failed, file kept on the server; ';
				dol_syslog('CAMT053 cron: could not archive ' . $name . ' anywhere, keeping the remote file', LOG_ERR);
				$counters['errors']++;
				continue;
			}

			// A statement whose IBAN resolves to no Dolibarr account is data
			// archiveForSummary() does not write anywhere. Keep a copy of the raw
			// file so the remote one is no longer the only one, then carry on with
			// the normal bookkeeping: the condition is permanent (a foreign or
			// closed account), so pinning the file to the server would re-download,
			// re-parse and re-error it on every run forever, pinning the job to
			// "failed" and drowning any genuine failure.
			$archivedPaths = $archived['paths'];

			$reason = Camt053FileOutcome::unresolvedReason($summary);
			if ($reason !== '') {
				$counters['errors']++;
				$unresolvedFiles[$name] = array(
					'reason' => $reason,
					'ibans' => $summary['unresolved_ibans'],
				);

				$unresolvedPath = $this->archiveUnresolved($config, $name, $content);
				if ($unresolvedPath === null) {
					// Nothing was written locally: the remote copy is the only one
					// left, so it must stay until the archive succeeds.
					$this->error .= '[' . $config->ref . '] ' . $name . ': ' . $reason
						. ' and archiving failed, file kept on the server; ';
					dol_syslog('CAMT053 cron: ' . $name . ' - ' . $reason . ' and archiving failed, keeping the remote file', LOG_ERR);
					continue;
				}

				if (empty($archivedPaths)) {
					$archivedPaths = array(0 => $unresolvedPath);
				}

				$this->error .= '[' . $config->ref . '] ' . $name . ': ' . $reason . '; ';
				dol_syslog('CAMT053 cron: ' . $name . ' - ' . $reason . ', raw file archived locally', LOG_WARNING);
			}

			$recordId = $this->recordProcessed($processedTracker, $config, $name, $hash, $summary, $reason, $archivedPaths);

			// Whatever this file could not settle has to reach a human now, not
			// at the end of the month: a daily file is most of what the job reads.
			if (!empty($summary['totals']['unmatched'])) {
				$accountIds = array_keys($summary['accounts']);
				$reviewFiles[$name] = array(
					'summary' => $summary,
					'url' => $this->statementUrl($recordId, (int) reset($accountIds)),
				);
			}

			$this->postDownloadCleanup($transport, $config, $name);
		}

		foreach ($selected['pdfs'] as $name) {
			$this->archivePdf($transport, $config, $processedTracker, $name, $counters);
		}

		$transport->disconnect();

		if (!empty($unresolvedFiles)) {
			$this->alertUnresolvedStatements($config, $unresolvedFiles);
		}

		if (!empty($reviewFiles)) {
			$this->alertEntriesNeedingADecision($config, $reviewFiles);
		}

		$status = sprintf(
			'%d file(s), %d PDF(s), %d auto, %d ambiguous, %d unmatched, %d skipped, %d error(s)',
			$counters['files'], $counters['pdfs'], $counters['auto'], $counters['ambiguous'], $counters['unmatched'],
			$counters['skipped'], $counters['errors']
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
			'intraday' => false,
			'pending' => 0,
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

			$merged['intraday'] = $merged['intraday'] || !empty($summary['intraday']);
			$merged['pending'] += (int) $summary['pending'];

			foreach (array('auto', 'ambiguous', 'unmatched', 'errors') as $k) {
				$merged['totals'][$k] += $summary['totals'][$k];
			}
		}

		return $merged;
	}

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
	 * @return string|null Path of the copy on disk, null when nothing was written
	 */
	private function archiveUnresolved(Camt053SftpConfig $config, string $name, string $content): ?string
	{
		$targetDir = DOL_DATA_ROOT . '/camt053readerandlink/' . ((int) $config->entity) . '/unresolved/' . dol_sanitizeFileName($config->ref);

		if (!is_dir($targetDir)) {
			dol_mkdir($targetDir);
		}

		$target = Camt053ArchivePath::resolve($targetDir, dol_sanitizeFileName($name), $content);
		$targetFile = $target['path'];

		if ($target['exists']) {
			return $targetFile;
		}
		if (!Camt053SafeFile::write($targetFile, $content)) {
			dol_syslog('CAMT053 cron: failed to archive unresolved file to ' . $targetFile, LOG_ERR);
			return null;
		}

		dol_syslog('CAMT053 cron: unresolved statement archived to ' . $targetFile, LOG_WARNING);

		return $targetFile;
	}

	/**
	 * Archive the downloaded file under each resolved account/statement.
	 *
	 * @param string $name    File name
	 * @param string $content Raw content
	 * @param array  $summary Merged summary
	 * @return array{ok:bool,paths:array<int,string>} ok is false when there was
	 *         something to archive and not a single copy landed: the caller must
	 *         then keep the remote file, which is the only one left. paths holds
	 *         where each account's copy went, so the statement can be reopened.
	 */
	private function archiveForSummary(string $name, string $content, array $summary): array
	{
		global $conf;

		$attempted = 0;
		$archived = 0;
		$paths = array();

		foreach ($summary['accounts'] as $accountId => $account) {
			$id = (int) $accountId;
			if ($id <= 0 || empty($account['num_releve'])) {
				continue;
			}

			// Counted before the fetch: an account that resolved from an IBAN but
			// cannot be loaded is still something we owed a copy of.
			$attempted++;

			$object = new Account($this->db);
			if ($object->fetch($id) <= 0) {
				continue;
			}

			$targetDir = $conf->bank->dir_output . '/' . $id . '/statement/' . dol_sanitizeFileName($account['num_releve']);

			if (!is_dir($targetDir)) {
				dol_mkdir($targetDir);
			}

			// Resolved by content: banks reuse one remote name for every
			// statement, and a same-named copy of another day must not be taken
			// for this one, or the remote original is deleted for nothing.
			$target = Camt053ArchivePath::resolve($targetDir, dol_sanitizeFileName($name), $content);
			$targetFile = $target['path'];
			$safe = basename($targetFile);

			if ($target['exists']) {
				$archived++;
				$paths[$id] = $targetFile;
				continue;
			}
			if (!Camt053SafeFile::write($targetFile, $content)) {
				dol_syslog('CAMT053 cron: failed to archive ' . $name . ' to ' . $targetFile, LOG_ERR);
				continue;
			}

			$archived++;
			$paths[$id] = $targetFile;

			$resindex = addFileIntoDatabaseIndex($targetDir, $safe, $name, 'uploaded', 1, $object);
			if ($resindex < 0) {
				dol_syslog('CAMT053 cron: archived ' . $targetFile . ' but database indexing failed', LOG_WARNING);
			}
		}

		return array('ok' => ($attempted === 0 || $archived > 0), 'paths' => $paths);
	}

	/**
	 * Archive the PDF of a statement beside the statement it belongs to.
	 *
	 * @param SftpFileTransport    $transport Connected transport
	 * @param Camt053SftpConfig    $config    Config being processed
	 * @param Camt053ProcessedFile $tracker   Tracker of the processed statements
	 * @param string               $name      Remote PDF name
	 * @param array                $counters  Run counters
	 * @return void
	 */
	private function archivePdf(SftpFileTransport $transport, Camt053SftpConfig $config, Camt053ProcessedFile $tracker, string $name, array &$counters): void
	{
		$pdf = Camt053RemoteFile::classify($name);
		$statement = $tracker->findStatement($pdf['iban'], $pdf['day']);
		if ($statement === null) {
			dol_syslog('CAMT053 cron: ' . $name . ' left on the server until its statement is archived', LOG_INFO);
			return;
		}

		$content = $transport->getContent($name);
		if ($content === null) {
			$counters['errors']++;
			dol_syslog('CAMT053 cron: cannot download ' . $name . ' - ' . $transport->getError(), LOG_ERR);
			return;
		}

		$targetDir = dirname($statement['archived_path']);
		$target = Camt053ArchivePath::resolve($targetDir, dol_sanitizeFileName($name), $content);

		if (!$target['exists']) {
			if (!Camt053SafeFile::write($target['path'], $content)) {
				$counters['errors']++;
				$this->error .= '[' . $config->ref . '] ' . $name . ': archiving failed, file kept on the server; ';
				dol_syslog('CAMT053 cron: could not archive ' . $name . ' to ' . $target['path'], LOG_ERR);
				return;
			}

			$account = new Account($this->db);
			if ($statement['fk_bank_account'] > 0 && $account->fetch($statement['fk_bank_account']) > 0
				&& addFileIntoDatabaseIndex($targetDir, basename($target['path']), $name, 'uploaded', 1, $account) < 0) {
				dol_syslog('CAMT053 cron: archived ' . $target['path'] . ' but database indexing failed', LOG_WARNING);
			}

			$counters['pdfs']++;
		}

		$this->postDownloadCleanup($transport, $config, $name);
	}

	/**
	 * Insert the processed-file tracking record.
	 *
	 * @param Camt053ProcessedFile $tracker   Tracker
	 * @param Camt053SftpConfig    $config    Config
	 * @param string               $name      File name
	 * @param string               $hash      File hash
	 * @param array                $summary   Merged summary
	 * @param string               $reason    What the file failed to attach to an
	 *                                        account, empty when it all resolved
	 * @param array                $archivedPaths Where the file was archived, keyed
	 *                                        by account id, so the statement can be
	 *                                        reopened from a link
	 * @return int Row id, 0 when the row could not be written
	 */
	private function recordProcessed(Camt053ProcessedFile $tracker, Camt053SftpConfig $config, string $name, string $hash, array $summary, string $reason = '', array $archivedPaths = array()): int
	{
		$firstAccount = null;
		foreach ($summary['accounts'] as $account) {
			$firstAccount = $account;
			break;
		}

		// The row points at the copy filed under the account it records, so the
		// link opens the statement of that account. Any copy will do otherwise:
		// they all hold the same content.
		$accountId = $firstAccount ? (int) $firstAccount['account_id'] : 0;
		$archivedPath = $archivedPaths[$accountId] ?? (reset($archivedPaths) ?: null);

		$record = new Camt053ProcessedFile($this->db);
		$record->fk_config = (int) $config->id;
		$record->filename = $name;
		$record->file_hash = $hash;
		$record->fk_bank_account = $firstAccount ? (int) $firstAccount['account_id'] : null;
		$record->num_releve = $firstAccount ? $firstAccount['num_releve'] : null;
		$record->archived_path = $archivedPath;
		$record->nb_auto = (int) $summary['totals']['auto'];
		$record->nb_ambiguous = (int) $summary['totals']['ambiguous'];
		$record->nb_unmatched = (int) $summary['totals']['unmatched'];
		// The file is never revisited once recorded, so this row is the only
		// durable trace: an unresolved IBAN has to show up here, not just in the
		// run output that ages out.
		$record->status = ($summary['totals']['errors'] > 0 || $reason !== '') ? 'error' : 'done';
		$details = array_filter(array($reason, $this->collectErrorDetail($summary)));
		$record->error_detail = !empty($details) ? implode(' | ', $details) : null;

		$recordId = $record->create();
		if ($recordId < 0) {
			dol_syslog('CAMT053 cron: failed to record processed file ' . $name . ' - ' . $record->getError(), LOG_ERR);
			return 0;
		}

		return (int) $recordId;
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
	 * Pin the account to the host key it just met, when it carried none.
	 *
	 * @param Camt053SftpConfig $config    Config being processed
	 * @param SftpFileTransport $transport Connected transport
	 * @return void
	 */
	private function rememberHostKey(Camt053SftpConfig $config, SftpFileTransport $transport): void
	{
		if (!$transport->isHostKeyLearned()) {
			return;
		}

		if ($config->recordFingerprint($transport->getHostFingerprint()) > 0) {
			dol_syslog('CAMT053 cron: pinned ' . $config->ref . ' to the host key of ' . $config->host
				. ' (' . Camt053HostKey::format($transport->getHostFingerprint()) . ')', LOG_WARNING);
		}
	}

	/**
	 * Send a short Zulip alert when a connection fails (lockout risk).
	 *
	 * @param Camt053SftpConfig $config          Config
	 * @param string|null       $detail          Error detail
	 * @param bool              $hostKeyMismatch Whether the host key changed, in
	 *                                           which case no login was attempted
	 * @return void
	 */
	private function alertConnectionFailure(Camt053SftpConfig $config, ?string $detail, bool $hostKeyMismatch = false): void
	{
		if ($hostKeyMismatch) {
			$content = ":rotating_light: **CAMT.053 SFTP host key changed** for `" . $config->ref . "` (" . $config->host . ")\n";
			$content .= '> ' . ($detail ?: 'unknown error') . "\n";
			$content .= "*No credential was sent. Confirm the new key with the bank before clearing the fingerprint on the account.*";
			$this->notify($config, $content);

			return;
		}

		$content = ":warning: **CAMT.053 SFTP connection failed** for `" . $config->ref . "` (" . $config->host . ")\n";
		$content .= '> ' . ($detail ?: 'unknown error') . "\n";
		$content .= "*Careful: PostFinance locks the account after 3 failed logins.*";

		$this->notify($config, $content);
	}

	/**
	 * Send a Zulip alert for the statements that resolved to no bank account.
	 *
	 * @param Camt053SftpConfig $config          Config
	 * @param array             $unresolvedFiles Reason and IBANs, keyed by file name
	 * @return void
	 */
	private function alertUnresolvedStatements(Camt053SftpConfig $config, array $unresolvedFiles): void
	{
		$lines = array();
		$lines[] = ':grey_question: **CAMT.053 statements attached to no bank account** for `' . $config->ref . '`';
		$lines[] = '';

		foreach ($unresolvedFiles as $name => $detail) {
			$lines[] = '- `' . $name . '`: ' . $detail['reason'];
			foreach (($detail['ibans'] ?? array()) as $iban => $count) {
				$lines[] = '  - `' . $iban . '` (' . $count . ' entries)';
			}
		}

		$lines[] = '';
		$lines[] = '_Nothing was reconciled for these. The raw files are kept under'
			. ' `camt053readerandlink/' . ((int) $config->entity) . '/unresolved/' . $config->ref . '`._';

		$this->notify($config, implode("\n", $lines));
	}

	/**
	 * Tell a human about the entries a run could not settle.
	 *
	 * @param Camt053SftpConfig $config      SFTP config being processed
	 * @param array             $reviewFiles Summary and statement link, keyed by file name
	 * @return void
	 */
	private function alertEntriesNeedingADecision(Camt053SftpConfig $config, array $reviewFiles): void
	{
		$message = Camt053ReviewAlert::format($config->ref, $reviewFiles);
		if ($message !== '') {
			$this->notify($config, $message);
		}
	}

	/**
	 * Send a message to Zulip, or write it to the log when Zulip is not configured.
	 *
	 * @param Camt053SftpConfig $config  Config the message is about
	 * @param string            $content Zulip markdown
	 * @return void
	 */
	private function notify(Camt053SftpConfig $config, string $content): void
	{
		$notifier = ZulipNotifier::fromConf();
		if ($notifier === null) {
			dol_syslog('CAMT053 cron: Zulip is not configured, message not sent:' . "\n" . $content, LOG_WARNING);
			return;
		}

		if (!$notifier->sendStream(getDolGlobalString('CAMT053_ZULIP_STREAM'), $this->zulipTopic($config), $content)) {
			dol_syslog('CAMT053 cron: Zulip message failed - ' . $notifier->getError(), LOG_ERR);
			$this->error .= '[' . $config->ref . '] Zulip message failed; ';
		}
	}

	/**
	 * Zulip topic to post under for a config.
	 *
	 * @param Camt053SftpConfig $config Config
	 * @return string
	 */
	private function zulipTopic(Camt053SftpConfig $config): string
	{
		$topic = getDolGlobalString('CAMT053_ZULIP_TOPIC');

		return ($topic !== '') ? $topic : 'CAMT.053 ' . $config->ref;
	}

	/**
	 * Absolute URL of the reconciliation screen for one archived statement.
	 *
	 * @param int $recordId  Tracking row id
	 * @param int $accountId Bank account id
	 * @return string Empty when the file was not recorded, so no link is offered
	 */
	private function statementUrl(int $recordId, int $accountId): string
	{
		if ($recordId <= 0) {
			return '';
		}

		return dol_buildpath('/camt053readerandlink/statement.php', 2)
			. '?id=' . $recordId . '&account=' . $accountId;
	}
}
