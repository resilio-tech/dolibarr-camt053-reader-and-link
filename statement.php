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
 *	\file       camt053readerandlink/statement.php
 *	\ingroup    camt053readerandlink
 *	\brief      Reopen the reconciliation screen for a statement the scheduled job
 *	            already fetched and archived. This is what the Zulip report links
 *	            to, so a finance user lands on the entries that still need a human
 *	            instead of re-uploading the file by hand.
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

require_once DOL_DOCUMENT_ROOT.'/compta/bank/class/account.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';

require_once __DIR__ . '/class/Camt053FileProcessor.class.php';
require_once __DIR__ . '/class/DatabaseBankStatementLoader.class.php';
require_once __DIR__ . '/class/BankStatementMatcher.class.php';
require_once __DIR__ . '/class/Camt053ProcessedFile.class.php';
require_once __DIR__ . '/lib/camt053readerandlink.results.lib.php';

$langs->loadLangs(array(
	"camt053readerandlink@camt053readerandlink",
	"banks",
	"bills",
	"companies",
	"salaries",
	"compta"
));

if (!isModEnabled('camt053readerandlink')) {
	accessforbidden('Module not enabled');
}
if (!$user->hasRight('banque', 'lire')) {
	accessforbidden();
}

$id = GETPOSTINT('id');
$accountFilter = GETPOSTINT('account');

$banks = array();
$processError = null;
$date_start = '';
$date_end = '';
$structure = null;
$record = null;

/**
 * Whether an archived statement path is one this module wrote.
 *
 * The path comes from the tracking row, not from the request, but a page that
 * reads a file off disk and prints it back has to prove where that file lives.
 *
 * @param string $path Candidate path
 * @return bool
 */
function camt053_statement_path_allowed($path)
{
	global $conf;

	$real = realpath($path);
	if ($real === false) {
		return false;
	}

	$roots = array(
		DOL_DATA_ROOT . '/camt053readerandlink/' . ((int) $conf->entity),
	);
	if (!empty($conf->bank->dir_output)) {
		$roots[] = $conf->bank->dir_output;
	}

	foreach ($roots as $root) {
		$realRoot = realpath($root);
		if ($realRoot !== false && strpos($real, $realRoot . DIRECTORY_SEPARATOR) === 0) {
			return true;
		}
	}

	return false;
}

if ($id > 0) {
	try {
		$record = new Camt053ProcessedFile($db);
		if ($record->fetch($id) <= 0) {
			throw new Exception($langs->trans('Camt053StatementNotFound'));
		}
		if (empty($record->archived_path)) {
			throw new Exception($langs->trans('Camt053StatementNoArchive'));
		}
		if (!camt053_statement_path_allowed($record->archived_path) || !is_readable($record->archived_path)) {
			dol_syslog('CAMT053: refusing to reopen statement from ' . $record->archived_path, LOG_WARNING);
			throw new Exception($langs->trans('Camt053StatementFileMissing'));
		}

		$fileProcessor = new Camt053FileProcessor($db);
		if (!$fileProcessor->parseFile($record->archived_path)) {
			throw new Exception($fileProcessor->getError() ?: $langs->trans('Camt053StatementFileMissing'));
		}

		$structure = $fileProcessor->getStructure();

		$dbLoader = new DatabaseBankStatementLoader($db, $langs);
		$matcher = new BankStatementMatcher(1);

		list($date_start, $date_end) = camt053_entries_date_range($fileProcessor);

		$dbStatements = $dbLoader->loadStatements($date_start, $date_end, null, $matcher->getDateTolerance());
		if ($dbLoader->getError()) {
			throw new Exception($dbLoader->getError());
		}

		foreach ($fileProcessor->getStatements() as $stmt) {
			if ($stmt->getAccountId() === null && $stmt->getEntryCount() > 0) {
				setEventMessages($langs->trans('Camt053IbanNotInCurrentEntity', $stmt->getIban()), null, 'warnings');
			}
		}

		$banks = $matcher->compareMultiple($fileProcessor->getStatementsByAccountId(), $dbStatements, $dbLoader);

		// A file can cover several accounts. The report links to one of them, and
		// showing only that one is the whole point of the link.
		if ($accountFilter > 0 && isset($banks[$accountFilter])) {
			$banks = array($accountFilter => $banks[$accountFilter]);
		}
	} catch (Throwable $e) {
		dol_syslog('CAMT053: cannot reopen statement #' . $id . ' - ' . $e->getMessage(), LOG_ERR);
		$processError = $e->getMessage();
	}
}

/*
 * View
 */

$moreCss = '<style>
.statement_link_linked { background-color: #d4edda; padding: 5px; }
.statement_link_unlinked { background-color: #f8d7da; padding: 5px; }
.statement_link_multiple { background-color: #fff3cd; padding: 5px; }
.statement_link_already_linked { background-color: #cce5ff; padding: 5px; }
.info { font-size: 0.85em; color: #666; }
</style>';

llxHeader("", $langs->trans("Camt053StatementReview"), '', '', 0, 0, '', '', '', 'mod-camt053readerandlink page-statement');

print $moreCss;

$linkback = '<a href="'.dol_buildpath('/camt053readerandlink/index.php', 1).'">'.$langs->trans("BackToList").'</a>';
$title = $record !== null
	? $langs->trans("Camt053StatementReviewOf", dol_escape_htmltag($record->filename))
	: $langs->trans("Camt053StatementReview");
print load_fiche_titre($title, $linkback, 'bank_account');

print '<div class="fichecenter camt053readerandlink">';

if ($id <= 0) {
	setEventMessages($langs->trans('Camt053StatementNotFound'), null, 'errors');
} elseif (!empty($processError)) {
	setEventMessages($processError, null, 'errors');
}

if (empty($banks) && empty($processError) && $id > 0) {
	print '<div class="opacitymedium">'.$langs->trans('NoEntriesToReconcile').'</div>';
}

if (!empty($banks)) {
	camt053_render_results($banks, array(
		'date_start' => $date_start,
		'date_end' => $date_end,
		// The file is already archived under its account: confirm.php must not
		// try to move it again, which is what an empty upload_file tells it.
		'bank_account_id' => ($accountFilter > 0 ? $accountFilter : (int) $record->fk_bank_account),
		'structure' => $structure,
		'upload_file' => '',
		'actionable_first' => true,
	));
}

print '</div>';

llxFooter();
$db->close();
