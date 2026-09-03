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
 * Tell whether the scheduled job may record a payment on its own.
 * Disabled unless an administrator turned it on in the module setup: it is the
 * one thing the module does that writes money movements without being asked.
 *
 * @return bool
 */
function camt053AutoPaymentEnabled()
{
	return (getDolGlobalString('CAMT053_AUTO_PAYMENT_ENABLED') === '1');
}
