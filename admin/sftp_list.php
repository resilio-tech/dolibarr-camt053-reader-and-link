<?php
/* Copyright (C) 2026 Resilio SA
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
 * \file    camt053readerandlink/admin/sftp_list.php
 * \ingroup camt053readerandlink
 * \brief   List of SFTP accounts (PostFinance MFTPF) configs.
 */

if (!defined('CSRFCHECK_WITH_TOKEN')) {
	define('CSRFCHECK_WITH_TOKEN', '1');
}

// Load Dolibarr environment
$res = 0;
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) {
	$res = @include $_SERVER["CONTEXT_DOCUMENT_ROOT"]."/main.inc.php";
}
$tmp = empty($_SERVER['SCRIPT_FILENAME']) ? '' : $_SERVER['SCRIPT_FILENAME']; $tmp2 = realpath(__FILE__); $i = strlen($tmp) - 1; $j = strlen($tmp2) - 1;
while ($i > 0 && $j > 0 && isset($tmp[$i]) && isset($tmp2[$j]) && $tmp[$i] == $tmp2[$j]) {
	$i--;
	$j--;
}
if (!$res && $i > 0 && file_exists(substr($tmp, 0, ($i + 1))."/main.inc.php")) {
	$res = @include substr($tmp, 0, ($i + 1))."/main.inc.php";
}
if (!$res && $i > 0 && file_exists(dirname(substr($tmp, 0, ($i + 1)))."/main.inc.php")) {
	$res = @include dirname(substr($tmp, 0, ($i + 1)))."/main.inc.php";
}
if (!$res && file_exists("../../main.inc.php")) {
	$res = @include "../../main.inc.php";
}
if (!$res && file_exists("../../../main.inc.php")) {
	$res = @include "../../../main.inc.php";
}
if (!$res) {
	die("Include of main fails");
}

global $langs, $user, $db;

require_once DOL_DOCUMENT_ROOT."/core/lib/admin.lib.php";
require_once __DIR__.'/../lib/camt053readerandlink.lib.php';
require_once __DIR__.'/../class/Camt053SftpConfig.class.php';
require_once __DIR__.'/../class/SftpFileTransport.class.php';
require_once __DIR__.'/../class/Camt053HostKey.class.php';

$langs->loadLangs(array("admin", "camt053readerandlink@camt053readerandlink"));

if (!$user->admin) {
	accessforbidden();
}

$action = GETPOST('action', 'aZ09');
$id = GETPOSTINT('id');
$cardurl = dol_buildpath('/camt053readerandlink/admin/sftp_card.php', 1);

/*
 * Actions
 */

if ($action == 'testconn' && $id > 0 && $user->admin) {
	// Walking two levels is enough to tell a flat delivery directory from a
	// nested one, which is all the test has to answer.
	$testMaxDepth = 2;
	$testMaxEntries = 500;

	$object = new Camt053SftpConfig($db);
	if ($object->fetch($id) > 0) {
		$transport = new SftpFileTransport($object);
		// trans() restores some tags after encoding, so escape every parameter
		// that carries user-supplied text.
		$host = dol_escape_htmltag($object->host);
		$port = dol_escape_htmltag($object->port);
		$remoteDir = dol_escape_htmltag($object->remote_dir);

		$report = array(
			'ref' => $object->ref,
			'host' => $object->host,
			'port' => (int) $object->port,
			'username' => $object->username,
			'auth_type' => $object->auth_type,
			'remote_dir' => $object->remote_dir,
			'daily_pattern' => (string) $object->daily_pattern,
			'monthly_pattern' => (string) $object->monthly_pattern,
			'post_download_action' => $object->post_download_action,
			'connected' => false,
			'error' => '',
			'entries' => array(),
			'truncated' => false,
			'fingerprint' => '',
			'fingerprint_learned' => false,
		);

		if ($transport->connect()) {
			$report['connected'] = true;
			$report['fingerprint'] = $transport->getHostFingerprint();
			$report['fingerprint_learned'] = $transport->isHostKeyLearned();
			$object->recordFingerprint($transport->getHostFingerprint());
			$entries = $transport->listEntries($testMaxDepth, $testMaxEntries);
			if ($entries === null) {
				$report['error'] = (string) $transport->getError();
				setEventMessages($langs->trans("Camt053SftpTestListFailed", $host, $port, $remoteDir, dol_escape_htmltag($transport->getError())), null, 'warnings');
			} else {
				$report['entries'] = $entries;
				$report['truncated'] = (count($entries) >= $testMaxEntries);
				setEventMessages($langs->trans("Camt053SftpTestConnectOk", $host, $port), null, 'mesgs');
			}
			$transport->disconnect();
		} else {
			$report['error'] = (string) $transport->getError();
			setEventMessages($langs->trans("Camt053SftpTestFailed", $host, $port, dol_escape_htmltag($transport->getError())), null, 'errors');
		}

		// Carried over the redirect rather than rendered here: reloading the
		// page must not open a second SSH session, since a repeated failure
		// is what locks a PostFinance account.
		$_SESSION['camt053_test_report'] = $report;
	} else {
		setEventMessages($object->getError(), null, 'errors');
	}
	header("Location: ".$_SERVER["PHP_SELF"]);
	exit;
}

if ($action == 'confirm_delete' && $id > 0 && $user->admin) {
	$object = new Camt053SftpConfig($db);
	if ($object->fetch($id) > 0) {
		if ($object->delete($user) > 0) {
			setEventMessages($langs->trans("RecordDeleted"), null, 'mesgs');
		} else {
			setEventMessages($object->getError(), null, 'errors');
		}
	}
	header("Location: ".$_SERVER["PHP_SELF"]);
	exit;
}

/**
 * Print what the connection test found on the server.
 *
 * The listing is the point: several accounts land in the same directory, so the
 * only way to write patterns that pick the right files is to see the names the
 * bank actually delivers, and to see which of them the cron would take.
 *
 * @param array     $report Report built by the testconn action
 * @param Translate $langs  Language object
 * @return void
 */
function camt053PrintTestReport(array $report, $langs)
{
	print '<div class="div-table-responsive-no-min">';
	print '<table class="noborder centpercent">';
	print '<tr class="liste_titre"><th colspan="5">'.$langs->trans("Camt053SftpTestReport", dol_escape_htmltag($report['ref'])).'</th></tr>';

	$target = dol_escape_htmltag($report['username']).'@'.dol_escape_htmltag($report['host']).':'.((int) $report['port']);
	print '<tr class="oddeven"><td class="titlefield">'.$langs->trans("Camt053SftpTestTarget").'</td>';
	print '<td colspan="4">'.$target.' ('.dol_escape_htmltag($report['auth_type']).') - '.dol_escape_htmltag($report['remote_dir']).'</td></tr>';

	$postAction = ($report['post_download_action'] === 'leave') ? "Camt053SftpPostLeave" : "Camt053SftpPostDelete";
	print '<tr class="oddeven"><td>'.$langs->trans("Camt053SftpPostAction").'</td>';
	print '<td colspan="4">'.$langs->trans($postAction).'</td></tr>';

	if (!empty($report['fingerprint'])) {
		$fingerprint = dol_escape_htmltag(Camt053HostKey::format($report['fingerprint']));
		if (!empty($report['fingerprint_learned'])) {
			$fingerprint .= ' <span class="warning">'.$langs->trans("Camt053SftpTestFingerprintLearned").'</span>';
		}
		print '<tr class="oddeven"><td>'.$langs->trans("Camt053SftpHostFingerprint").'</td>';
		print '<td colspan="4">'.$fingerprint.'</td></tr>';
	}

	if (!$report['connected'] || $report['error'] !== '') {
		print '<tr class="oddeven"><td>'.$langs->trans("Error").'</td>';
		print '<td colspan="4"><span class="error">'.dol_escape_htmltag($report['error']).'</span></td></tr>';
	}

	print '</table>';
	print '</div>';

	if (!$report['connected'] || $report['error'] !== '') {
		print '<br>';
		return;
	}

	$hasPattern = ($report['daily_pattern'] !== '' || $report['monthly_pattern'] !== '');
	$files = 0;
	$taken = 0;
	$dirs = 0;
	$nested = 0;

	print '<div class="div-table-responsive">';
	print '<table class="noborder centpercent">';
	print '<tr class="liste_titre">';
	print '<th>'.$langs->trans("Camt053SftpTestEntry").'</th>';
	print '<th class="center">'.$langs->trans("Type").'</th>';
	print '<th class="right">'.$langs->trans("Size").'</th>';
	print '<th>'.$langs->trans("DateModification").'</th>';
	print '<th>'.$langs->trans("Camt053SftpTestCronAction").'</th>';
	print '</tr>';

	if (empty($report['entries'])) {
		print '<tr class="oddeven"><td colspan="5" class="opacitymedium center">'.$langs->trans("Camt053SftpTestEmptyDir").'</td></tr>';
	}

	foreach ($report['entries'] as $entry) {
		if ($entry['is_dir']) {
			$dirs++;
			$verdict = '<span class="opacitymedium">'.$langs->trans("Camt053SftpTestCronSkipsDir").'</span>';
		} elseif ($entry['depth'] > 0) {
			$nested++;
			$verdict = '<span class="warning">'.$langs->trans("Camt053SftpTestCronOutOfReach").'</span>';
		} else {
			$files++;
			$isDaily = camt053MatchesFilePattern($report['daily_pattern'], $entry['name']);
			$isMonthly = camt053MatchesFilePattern($report['monthly_pattern'], $entry['name']);
			if ($isDaily && $isMonthly) {
				$taken++;
				$verdict = $langs->trans("Camt053SftpTestCronDailyAndMonthly");
			} elseif ($isDaily) {
				$taken++;
				$verdict = $langs->trans("Camt053SftpTestCronDaily");
			} elseif ($isMonthly) {
				$taken++;
				$verdict = $langs->trans("Camt053SftpTestCronMonthly");
			} elseif (!$hasPattern) {
				$taken++;
				$verdict = '<span class="warning">'.$langs->trans("Camt053SftpTestCronNoPattern").'</span>';
			} else {
				$verdict = '<span class="opacitymedium">'.$langs->trans("Camt053SftpTestCronIgnored").'</span>';
			}
		}

		print '<tr class="oddeven">';
		print '<td>'.str_repeat('&nbsp;&nbsp;&nbsp;&nbsp;', (int) $entry['depth']).dol_escape_htmltag($entry['path']).'</td>';
		print '<td class="center">'.($entry['is_dir'] ? img_picto('', 'folder').' '.$langs->trans("Camt053SftpTestTypeDir") : $langs->trans("Camt053SftpTestTypeFile")).'</td>';
		print '<td class="right">'.($entry['is_dir'] ? '' : dol_print_size((int) $entry['size'], 1, 1)).'</td>';
		print '<td>'.(!empty($entry['mtime']) ? dol_print_date((int) $entry['mtime'], 'dayhour') : '').'</td>';
		print '<td>'.$verdict.'</td>';
		print '</tr>';
	}

	print '</table>';
	print '</div>';

	print '<div class="opacitymedium" style="margin-top:6px">';
	print $langs->trans("Camt053SftpTestCounts", $files, $taken, $dirs);
	print '</div>';

	if ($nested > 0) {
		print '<div class="warning">'.$langs->trans("Camt053SftpTestNestedWarning").'</div>';
	}
	if (!$hasPattern && $files > 0) {
		print '<div class="warning">'.$langs->trans("Camt053SftpTestNoPatternWarning").'</div>';
	}
	if (!empty($report['truncated'])) {
		print '<div class="opacitymedium">'.$langs->trans("Camt053SftpTestTruncated", count($report['entries'])).'</div>';
	}

	print '<br>';
}

/*
 * View
 */

$form = new Form($db);
$help_url = '';
$page_name = "Camt053SftpAccounts";

llxHeader('', $langs->trans($page_name), $help_url, '', 0, 0, '', '', '', 'mod-camt053readerandlink page-admin');

$linkback = '<a href="'.DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1">'.$langs->trans("BackToModuleList").'</a>';
print load_fiche_titre($langs->trans("Camt053ReaderAndLinkSetup"), $linkback, 'title_setup');

camt053WarnIfSftpExtensionMissing();

$head = camt053readerandlinkAdminPrepareHead();
print dol_get_fiche_head($head, 'sftp', $langs->trans($page_name), -1, "camt053readerandlink@camt053readerandlink");

print '<span class="opacitymedium">'.$langs->trans("Camt053SftpAccountsHelp").'</span><br><br>';

if (!camt053SftpFetchEnabled()) {
	$setupurl = dol_buildpath('/camt053readerandlink/admin/setup.php', 1);
	print '<div class="warning">'.$langs->trans("Camt053FetchDisabledWarning");
	print ' <a href="'.dol_escape_htmltag($setupurl).'">'.$langs->trans("Camt053ReaderAndLinkSetup").'</a></div><br>';
}

if (!empty($_SESSION['camt053_test_report'])) {
	$report = $_SESSION['camt053_test_report'];
	unset($_SESSION['camt053_test_report']);
	camt053PrintTestReport($report, $langs);
}

// Delete confirmation
if ($action == 'delete' && $id > 0) {
	print $form->formconfirm($_SERVER["PHP_SELF"].'?id='.$id, $langs->trans("Camt053SftpDeleteTitle"), $langs->trans("Camt053SftpDeleteConfirm"), 'confirm_delete', '', 0, 1);
}

// New button
print '<div class="tabsAction">';
print '<a class="butAction" href="'.$cardurl.'?action=create&token='.newToken().'">'.$langs->trans("Camt053SftpAccountNew").'</a>';
print '</div>';

$configLoader = new Camt053SftpConfig($db);
$list = $configLoader->fetchAll(false);

print '<div class="div-table-responsive">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre">';
print '<th>'.$langs->trans("Ref").'</th>';
print '<th>'.$langs->trans("Label").'</th>';
print '<th>'.$langs->trans("Camt053SftpHost").'</th>';
print '<th class="center">'.$langs->trans("Camt053SftpPort").'</th>';
print '<th>'.$langs->trans("Camt053SftpUsername").'</th>';
print '<th>'.$langs->trans("Camt053SftpAuthType").'</th>';
print '<th>'.$langs->trans("Camt053SftpRemoteDir").'</th>';
print '<th class="center">'.$langs->trans("Enabled").'</th>';
print '<th>'.$langs->trans("Camt053SftpLastRun").'</th>';
print '<th class="right"></th>';
print '</tr>';

if (empty($list)) {
	print '<tr class="oddeven"><td colspan="10" class="opacitymedium center">'.$langs->trans("Camt053SftpNoAccount").'</td></tr>';
} else {
	foreach ($list as $cfg) {
		$editlink = $cardurl.'?action=edit&token='.newToken().'&id='.$cfg->id;
		print '<tr class="oddeven">';
		print '<td><a href="'.$editlink.'">'.dol_escape_htmltag($cfg->ref).'</a></td>';
		print '<td>'.dol_escape_htmltag($cfg->label).'</td>';
		print '<td>'.dol_escape_htmltag($cfg->host).'</td>';
		print '<td class="center">'.((int) $cfg->port).'</td>';
		print '<td>'.dol_escape_htmltag($cfg->username).'</td>';
		print '<td>'.dol_escape_htmltag($cfg->auth_type).'</td>';
		print '<td>'.dol_escape_htmltag($cfg->remote_dir).'</td>';
		print '<td class="center">'.($cfg->active ? $langs->trans("Yes") : $langs->trans("No")).'</td>';
		$lastrun = $cfg->last_run ? dol_print_date($cfg->last_run, 'dayhour') : '<span class="opacitymedium">-</span>';
		print '<td>'.$lastrun.'</td>';
		print '<td class="right nowraponall">';
		print '<a class="butAction small" href="'.$_SERVER["PHP_SELF"].'?action=testconn&id='.$cfg->id.'&token='.newToken().'">'.img_picto($langs->trans("Camt053SftpTest"), 'globe', 'class="pictofixedwidth"').$langs->trans("Camt053SftpTest").'</a>';
		print ' <a class="editfielda" href="'.$editlink.'">'.img_edit().'</a>';
		print ' <a class="reposition" href="'.$_SERVER["PHP_SELF"].'?action=delete&id='.$cfg->id.'&token='.newToken().'">'.img_delete().'</a>';
		print '</td>';
		print '</tr>';
	}
}

print '</table>';
print '</div>';

print dol_get_fiche_end();

llxFooter();
$db->close();
