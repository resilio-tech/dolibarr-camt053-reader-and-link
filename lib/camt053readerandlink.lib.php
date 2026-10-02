<?php
/* Copyright (C) 2024 SuperAdmin
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

/**
 * \file    camt053readerandlink/lib/camt053readerandlink.lib.php
 * \ingroup camt053readerandlink
 * \brief   Library files with common functions for Camt053ReaderAndLink
 */

/**
 * Prepare admin pages header
 *
 * @return array
 */
function camt053readerandlinkAdminPrepareHead()
{
	global $langs, $conf;

	$langs->load("camt053readerandlink@camt053readerandlink");

	$h = 0;
	$head = array();

	$head[$h][0] = dol_buildpath("/camt053readerandlink/admin/setup.php", 1);
	$head[$h][1] = $langs->trans("Settings");
	$head[$h][2] = 'settings';
	$h++;

	$head[$h][0] = dol_buildpath("/camt053readerandlink/admin/sftp_list.php", 1);
	$head[$h][1] = $langs->trans("Camt053SftpAccounts");
	$head[$h][2] = 'sftp';
	$h++;

	$head[$h][0] = dol_buildpath("/camt053readerandlink/admin/about.php", 1);
	$head[$h][1] = $langs->trans("About");
	$head[$h][2] = 'about';
	$h++;

	complete_head_from_modules($conf, $langs, null, $head, $h, 'camt053readerandlink@camt053readerandlink');
	complete_head_from_modules($conf, $langs, null, $head, $h, 'camt053readerandlink@camt053readerandlink', 'remove');

	return $head;
}

/**
 * Warn, on the SFTP configuration screens, when the extension they depend on is
 * absent.
 *
 * Without this the failure only surfaces after an account has been filled in and
 * a private key pasted, either on an explicit connection test or, worse, in a
 * silent cron error. Whoever configures the module is rarely whoever installs
 * PHP extensions, so the message has to reach them where they are.
 *
 * @return void
 */
function camt053WarnIfSftpExtensionMissing()
{
	global $langs;

	if (function_exists('ssh2_connect')) {
		return;
	}

	print '<div class="warning">' . $langs->trans('Camt053SftpExtensionMissing') . '</div>';
}

/**
 * Whether a file name matches a configured PCRE pattern.
 *
 * Shared by the cron, which uses it to decide what to download, and by the
 * connection test, which uses it to show what the cron would pick up.
 *
 * @param string|null $pattern Pattern (with delimiters) or null
 * @param string      $name    File name
 * @return bool
 */
function camt053MatchesFilePattern($pattern, $name)
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

/**
 * Tell whether the scheduled SFTP fetch is allowed to run.
 * Disabled unless an administrator turned it on in the module setup.
 *
 * @return bool
 */
function camt053SftpFetchEnabled()
{
	return (getDolGlobalString('CAMT053_SFTP_FETCH_ENABLED') === '1');
}

/**
 * Archive a statement file under the bank account and the statement it belongs to.
 *
 * The upload is the only copy of the file, so the physical move comes first and
 * the ecm_files index only afterwards: indexing first leaves a row pointing at a
 * file that is not on disk, and Dolibarr then refuses a manual attachment
 * claiming the file already exists.
 *
 * @param DoliDB $db         Database handler
 * @param string $uploadFile Statement file, in the entity upload directory
 * @param int    $accountId  Bank account the statement belongs to
 * @param string $numref     Statement reference (num_releve)
 * @return string One of the Camt053StatementArchive outcomes
 */
function camt053ArchiveStatementFile($db, $uploadFile, $accountId, $numref)
{
	global $conf;

	require_once __DIR__ . '/../class/Camt053StatementArchive.class.php';
	require_once DOL_DOCUMENT_ROOT . '/compta/bank/class/account.class.php';
	include_once DOL_DOCUMENT_ROOT . '/core/lib/files.lib.php';

	$accountId = (int) $accountId;
	$numref = (string) $numref;

	if (empty($uploadFile) || !file_exists($uploadFile) || $accountId <= 0 || $numref === '') {
		return Camt053StatementArchive::FAILED;
	}

	$account = new Account($db);
	if ($account->fetch($accountId) <= 0) {
		dol_syslog('CAMT053: Statement file not archived, bank account ' . $accountId . ' could not be loaded', LOG_ERR);
		return Camt053StatementArchive::FAILED;
	}

	$targetDir = $conf->bank->dir_output . '/' . $accountId . '/statement/' . dol_sanitizeFileName($numref);
	if (!is_dir($targetDir)) {
		dol_mkdir($targetDir);
	}

	$originalName = basename($uploadFile);
	$archived = Camt053StatementArchive::store($uploadFile, $targetDir, dol_sanitizeFileName($originalName));

	if ($archived['outcome'] === Camt053StatementArchive::STORED) {
		dol_syslog('CAMT053: Statement file archived to ' . $archived['path'], LOG_DEBUG);
		$resindex = addFileIntoDatabaseIndex($targetDir, basename($archived['path']), $originalName, 'uploaded', 1, $account);
		if ($resindex < 0) {
			dol_syslog('CAMT053: File archived but database indexing failed for ' . $archived['path'], LOG_WARNING);
		}
	} elseif ($archived['outcome'] === Camt053StatementArchive::ALREADY) {
		dol_syslog('CAMT053: Statement file already present at ' . $archived['path'] . ', skipping move', LOG_DEBUG);
	} else {
		dol_syslog('CAMT053: Error moving statement file ' . $uploadFile . ' to ' . $targetDir, LOG_ERR);
	}

	return $archived['outcome'];
}
