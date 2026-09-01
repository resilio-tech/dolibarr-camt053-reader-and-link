<?php
/* Copyright (C) 2001-2005 Rodolphe Quiedeville <rodolphe@quiedeville.org>
 * Copyright (C) 2004-2015 Laurent Destailleur  <eldy@users.sourceforge.net>
 * Copyright (C) 2005-2012 Regis Houssin        <regis.houssin@inodbox.com>
 * Copyright (C) 2015      Jean-François Ferry	<jfefe@aternatik.fr>
 * Copyright (C) 2024      Slordef
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
 *	\file       camt053readerandlink/confirm.php
 *	\ingroup    camt053readerandlink
 *	\brief      Confirm and finalize bank reconciliations
 */

// Load Dolibarr environment
$res = 0;
// Try main.inc.php into web root known defined into CONTEXT_DOCUMENT_ROOT (not always defined)
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) {
	$res = @include $_SERVER["CONTEXT_DOCUMENT_ROOT"]."/main.inc.php";
}
// Try main.inc.php into web root detected using web root calculated from SCRIPT_FILENAME
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
// Try main.inc.php using relative path
if (!$res && file_exists("../main.inc.php")) {
	$res = @include "../main.inc.php";
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

include_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';
require_once DOL_DOCUMENT_ROOT.'/compta/bank/class/account.class.php';

// Load module classes
require_once __DIR__ . '/lib/camt053readerandlink.lib.php';
require_once __DIR__ . '/class/Camt053StatementArchive.class.php';

// Load translation files required by the page
$langs->loadLangs(array("camt053readerandlink@camt053readerandlink"));

// Security check
if (!isModEnabled('camt053readerandlink')) {
	accessforbidden('Module not enabled');
}
// Writing num_releve and rappro on a bank line is a reconciliation, which
// Dolibarr core gates on banque.consolidate (compta/bank/bankentries_list.php),
// not on banque.modifier.
if (!$user->hasRight('banque', 'consolidate')) {
	accessforbidden();
}
// This page writes (num_releve + rappro) straight away, so it must carry the
// same token check as the admin pages. Core blocks token-less POSTs at the
// default MAIN_SECURITY_CSRF_WITH_TOKEN, but not on instances that lowered it.
if (!camt053VerifCsrfToken()) {
	accessforbidden($langs->trans('SecurityTokenError'));
}

llxHeader("", $langs->trans("Camt053ReaderAndLinkArea"), '', '', 0, 0, '', '', '', 'mod-camt053readerandlink page-index');

print '<div class="fichecenter camt053readerandlink">';

// Get linked entries from form. The explicit dropdown choices come first: when
// one of them collides with an automatic link on the same bank line, the
// duplicate guard below keeps whichever came first, and the user's decision must
// win over a match the module made on its own.
$linked = array();
foreach ($_POST as $key => $value) {
	// Only scalars: a field named "linked_x[]" would otherwise reach the (int)
	// cast below as an array, which PHP evaluates to 1 and would reconcile bank
	// line 1. Entry references come from the bank, so the key is not trusted.
	if (!is_scalar($value)) {
		continue;
	}
	if (preg_match('/^linked_(.+)$/', $key, $matches)) {
		$hash = $matches[1];
		$linked[$hash] = (string) $value;
	}
}
foreach (GETPOST('linked', 'array') as $hash => $value) {
	if (!is_scalar($value) || isset($linked[$hash])) {
		continue;
	}
	$linked[$hash] = (string) $value;
}
$date_start = GETPOST('date_start', 'alphanohtml');
$date_end = GETPOST('date_end', 'alphanohtml');
$bank_account_id = GETPOSTINT('bank_account_id');
$file_json = json_decode(urldecode(GETPOST('file_json', 'alpha')), true);
$upload_file = GETPOST('upload_file', 'alpha');

// Validate upload_file path to prevent path traversal
if (!empty($upload_file)) {
	$realUploadFile = realpath($upload_file);
	$allowedDir = realpath(DOL_DATA_ROOT . '/camt053readerandlink/' . ((int) $conf->entity));
	// realpath() returns false when the directory does not exist yet, and
	// strpos($x, false) matches everything: without the explicit check the guard
	// would wave any path through. The trailing separator keeps a sibling
	// directory such as "camt053readerandlink_evil" from passing the prefix test.
	if ($realUploadFile === false || $allowedDir === false
		|| strpos($realUploadFile, $allowedDir . DIRECTORY_SEPARATOR) !== 0) {
		dol_syslog('CAMT053: Path traversal attempt detected: ' . $upload_file, LOG_WARNING);
		$upload_file = '';
	}
}

// Calculate statement reference from end date
$date_concil = '';
if (!empty($date_end)) {
	$date_end_obj = DateTime::createFromFormat('d/m/Y', $date_end);
	if ($date_end_obj !== false) {
		$date_concil = $date_end_obj->format('Ym');
	}
}

print load_fiche_titre($langs->trans("ConcilationsConfirmed"), '', '');

print '<table class="noborder" style="width: 100%">';
print '<tr class="liste_titre">';
print '<td>'.$langs->trans('Name').'</td>';
print '<td>'.$langs->trans('Date').'</td>';
print '<td class="right">'.$langs->trans('Amount').'</td>';
print '</tr>';

$bank_account = new Account($db);

// Counters and errors to give visible feedback instead of failing silently
$reconcileSuccess = 0;
$reconcileErrors = array();
// Real bank account id(s) of the submitted linked lines (the upload form has no account selector,
// so bank_account_id is empty: we must derive the account from the linked lines). Captured right
// after fetch() so it is known even if the reconciliation itself later fails.
$linkedAccountIds = array();

// Without a statement reference every update_conciliation() call fails once
// BANK_STATEMENT_REGEX_RULE is set, and each failure leaves an unclosed
// transaction behind (core opens one before validating). Stop before the loop
// rather than iterating over a guaranteed failure.
if (empty($date_concil) && !empty($linked)) {
	dol_syslog('CAMT053: Empty statement reference (num_releve) computed from date_end=' . $date_end . ' - aborting', LOG_ERR);
	setEventMessages($langs->trans('Camt053EmptyStatementReference'), null, 'errors');
	$linked = array();
}

dol_syslog('CAMT053: Starting reconciliation of ' . count($linked) . ' linked entry(ies), num_releve=' . $date_concil, LOG_DEBUG);

try {
	// Process each linked entry
	$processedLineIds = array();
	foreach ($linked as $key => $link) {
		if (empty($link) || $link == 0) {
			continue;
		}

		$bankLineId = (int) $link;

		// Two file entries can be pointed at the same bank line through the
		// multi-match dropdowns. Reconciling it twice would report two successes
		// while one entry silently stays unreconciled.
		if (isset($processedLineIds[$bankLineId])) {
			dol_syslog('CAMT053: Bank line rowid=' . $bankLineId . ' selected more than once, ignoring the duplicate', LOG_WARNING);
			$reconcileErrors[] = $langs->trans('Camt053DuplicateBankLineSelected', $bankLineId);
			continue;
		}
		$processedLineIds[$bankLineId] = true;
		$obj = new AccountLine($db);
		$result = $obj->fetch($bankLineId);

		if ($result <= 0) {
			dol_syslog('CAMT053: Bank line not found for reconciliation, rowid=' . $bankLineId, LOG_WARNING);
			$reconcileErrors[] = $langs->trans('ReconciliationFailed') . ' #' . $bankLineId;
			continue;
		}

		// Remember the real bank account now, even if the reconciliation below fails, so the
		// statement file is still archived under the correct account (never under account 0)
		if (!empty($obj->fk_account)) {
			$linkedAccountIds[(int) $obj->fk_account] = (int) $obj->fk_account;
		}

		// Reconcile the entry
		$obj->num_releve = $date_concil;
		$resconcil = $obj->update_conciliation($user, 0, 1);

		if ($resconcil <= 0) {
			$errmsg = !empty($obj->error) ? $obj->error : (!empty($obj->errors) ? implode(', ', $obj->errors) : 'Unknown error');
			dol_syslog('CAMT053: Failed to reconcile bank line rowid=' . $bankLineId . ' num_releve=' . $date_concil . ' - ' . $errmsg, LOG_ERR);
			$reconcileErrors[] = $langs->trans('ReconciliationFailed') . ' #' . $bankLineId . ': ' . $errmsg;
			continue;
		}

		$reconcileSuccess++;
		dol_syslog('CAMT053: Reconciled bank line rowid=' . $bankLineId . ' num_releve=' . $date_concil . ' fk_account=' . $obj->fk_account, LOG_DEBUG);

		if (empty($obj->datev)) {
			continue;
		}

		$bank_links = $bank_account->get_url($obj->id);

		$amount = $obj->amount;
		if (is_numeric($obj->datev)) {
			$value_date = new DateTime();
			$value_date->setTimestamp((int) $obj->datev);
			$value_date = $value_date->format('Y-m-d');
		} else {
			$value_date = new DateTime($obj->datev);
			$value_date = $value_date->format('Y-m-d');
		}
		$name = $obj->label;
		$reg = array();
		preg_match('/\((.+)\)/i', $name, $reg);
		if (!empty($reg[1]) && $langs->trans($reg[1]) != $reg[1]) {
			$name = $langs->trans($reg[1]);
			$type = 'salary';
		} else {
			if ($name == '(payment_salary)') {
				$name = $langs->trans('SalaryPayment');
				$type = 'salary';
			} else {
				$name = dol_escape_htmltag($name);
			}
		}

		if (!empty($bank_links[1]['label'])) {
			$name .= ' - ' . dol_escape_htmltag($bank_links[1]['label']);
		}

		$lineUrl = DOL_URL_ROOT . '/compta/bank/line.php?rowid=' . ((int) $obj->id) . '&save_lastsearch_values=1';
		$name = '<a href="' . dol_escape_htmltag($lineUrl) . '" title="' . dol_escape_htmltag($name, 1) . '" class="classfortooltip" target="_blank" rel="noopener noreferrer">' . img_picto('', $obj->picto) . ' ' . $obj->id . ' ' . $name . '</a>';

		print '<tr>';
		print '<td>' . $name . '</td>';
		print '<td>' . dol_escape_htmltag($value_date) . '</td>';
		print '<td class="right">' . number_format($amount, 2) . '</td>';
		print '</tr>';
	}

	dol_syslog('CAMT053: Reconciliation done, success=' . $reconcileSuccess . ', errors=' . count($reconcileErrors), LOG_INFO);

	// Surface reconciliation errors instead of failing silently
	if (!empty($reconcileErrors)) {
		setEventMessages(null, $reconcileErrors, 'errors');
	}

	// The upload form exposes no account selector, so bank_account_id is empty here.
	// Derive the real account id from the linked lines so the statement file is archived
	// under bank/<id>/statement/<num>/ and shown in the bank statement of the correct account.
	if (empty($bank_account_id) && !empty($linkedAccountIds)) {
		$bank_account_id = reset($linkedAccountIds);
		if (count($linkedAccountIds) > 1) {
			dol_syslog('CAMT053: Linked lines span multiple accounts (' . implode(',', $linkedAccountIds) . '), statement file archived under account ' . $bank_account_id . ' only', LOG_WARNING);
		}
	}

	// Move the uploaded file to the bank statement document storage, then index it.
	// IMPORTANT: move the physical file FIRST and index it in the database (ecm_files) ONLY afterwards.
	// Indexing before the move (the previous behaviour) could leave an orphan ecm_files row pointing
	// to a file that is not on disk: the statement page (which lists from the filesystem) shows nothing,
	// yet Dolibarr reports the file "already exists" when trying to attach it manually.
	if (!empty($upload_file) && file_exists($upload_file) && (int) $bank_account_id <= 0) {
		// No account could be determined (e.g. no linked entries submitted): do not archive under
		// account 0. Keep the temporary upload and warn so the statement file is not lost silently.
		dol_syslog('CAMT053: Statement file not archived, no bank account could be determined (upload_file=' . $upload_file . ')', LOG_ERR);
		setEventMessages($langs->trans('StatementFileNotArchived'), null, 'warnings');
	} elseif (!empty($upload_file) && file_exists($upload_file)) {
		$id = (int) $bank_account_id;
		$numref = $date_concil;

		$object = new Account($db);
		$object->fetch($id);

		$file = basename($upload_file);
		$sanitizedFilename = dol_sanitizeFileName($file);

		$targetDir = $conf->bank->dir_output . '/' . $id . '/statement/' . dol_sanitizeFileName($numref);

		if (!is_dir($targetDir)) {
			dol_mkdir($targetDir);
		}

		$archived = Camt053StatementArchive::store($upload_file, $targetDir, $sanitizedFilename);
		$targetFile = $archived['path'];

		if ($archived['outcome'] === Camt053StatementArchive::ALREADY) {
			dol_syslog('CAMT053: Statement file already present at ' . $targetFile . ', skipping move', LOG_DEBUG);
		} elseif ($archived['outcome'] === Camt053StatementArchive::STORED) {
			dol_syslog('CAMT053: Statement file archived to ' . $targetFile, LOG_DEBUG);
			// Index in database only once the file physically exists, keeping ecm_files in sync
			$resindex = addFileIntoDatabaseIndex($targetDir, basename($targetFile), $file, 'uploaded', 1, $object);
			if ($resindex < 0) {
				dol_syslog('CAMT053: File archived but database indexing failed for ' . $targetFile, LOG_WARNING);
			}
		} else {
			dol_syslog('CAMT053: Error moving statement file ' . $upload_file . ' to ' . $targetDir, LOG_ERR);
			setEventMessages($langs->trans('StatementFileNotArchived'), null, 'warnings');
		}
	}

	print '</table>';

	print '<div class="tabsAction">';

	// Button to view bank statement
	if (!empty($bank_account_id) && !empty($date_concil)) {
		$statementUrl = DOL_URL_ROOT . '/compta/bank/releve.php?account=' . ((int) $bank_account_id) . '&num=' . urlencode($date_concil);
		print '<a class="butAction" href="' . $statementUrl . '">' . $langs->trans('ViewBankStatement') . '</a>';
	}

	// Form to check for new reconciliations
	print '<form method="POST" action="'.dol_buildpath('/custom/camt053readerandlink/submit.php', 1).'" enctype="multipart/form-data" style="display: inline;">';
	print '<input type="hidden" name="date_start" value="' . dol_escape_htmltag($date_start) . '">';
	print '<input type="hidden" name="date_end" value="' . dol_escape_htmltag($date_end) . '">';
	print '<input type="hidden" name="bank_account_id" value="' . ((int) $bank_account_id) . '">';
	print '<input type="hidden" name="file_json" value="' . dol_escape_htmltag(urlencode(json_encode($file_json, 0))) . '">';
	print '<input type="hidden" name="token" value="' . newToken() . '">';
	print '<input type="hidden" name="action" value="upload">';
	print '<input type="submit" class="butAction" value="' . $langs->trans('CheckNewConciliations') . '">';
	print '</form>';

	print '</div>';
	print '</div>';
} catch (Throwable $e) {
	// Catch both Exception and Error/TypeError (PHP 8) to avoid a blank page
	dol_syslog('CAMT053: Error during confirmation - ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine(), LOG_ERR);
	setEventMessages($langs->trans('ErrorProcessingFile') . ': ' . $e->getMessage(), null, 'errors');
}

// End of page
llxFooter();
$db->close();
