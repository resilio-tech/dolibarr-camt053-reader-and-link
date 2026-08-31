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
 *	\file       camt053readerandlink/submit.php
 *	\ingroup    camt053readerandlink
 *	\brief      Process uploaded CAMT.053 file and compare with database
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

require_once DOL_DOCUMENT_ROOT.'/core/class/html.formfile.class.php';
require_once DOL_DOCUMENT_ROOT.'/compta/bank/class/account.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';

// Load module classes
require_once __DIR__ . '/class/Camt053FileProcessor.class.php';
require_once __DIR__ . '/class/DatabaseBankStatementLoader.class.php';
require_once __DIR__ . '/class/BankStatementMatcher.class.php';
require_once __DIR__ . '/class/BankRelationshipLookup.class.php';
require_once __DIR__ . '/class/PaymentSuggestionFinder.class.php';
require_once __DIR__ . '/class/InternalTransferDetector.class.php';
require_once __DIR__ . '/lib/camt053readerandlink.results.lib.php';

// Load translation files required by the page
$langs->loadLangs(array(
	"camt053readerandlink@camt053readerandlink",
	"banks",
	"bills",
	"companies",
	"salaries",
	"compta"
));

$action = GETPOST('action', 'aZ09');

// Security check
if (!isModEnabled('camt053readerandlink')) {
	accessforbidden('Module not enabled');
}
if (!$user->hasRight('banque', 'lire')) {
	accessforbidden();
}

// Redirect if no upload action
if ($action != 'upload' || (empty($_FILES['file']) && empty(GETPOST('file_json')))) {
	header('Location: ' . dol_buildpath('/custom/camt053readerandlink/index.php', 1));
	exit;
}

$bank_account_id = GETPOSTINT('bank_account_id');
$file_json = GETPOST('file_json', 'alpha');
$date_start = GETPOST('date_start', 'alpha');
$date_end = GETPOST('date_end', 'alpha');
$file = !empty($_FILES['file']) ? $_FILES['file'] : null;

// Secure directory creation using Dolibarr function
$dir = DOL_DATA_ROOT . '/camt053readerandlink/' . ((int) $conf->entity);
if (!is_dir($dir)) {
	dol_mkdir($dir);
}

/*
 * Actions - Process before any output
 */

$banks = array();
$processError = null;
$redirectUrl = null;
$structure = null;
$upload_file = '';

if ($action == 'upload') {
	try {
		$fileProcessor = new Camt053FileProcessor($db);
		$dbLoader = new DatabaseBankStatementLoader($db, $langs);
		$matcher = new BankStatementMatcher(1); // 1 day tolerance

		if (!empty($file_json)) {
			// Parse from previously uploaded JSON
			$structure = json_decode(urldecode($file_json), true);
			if (!is_array($structure)) {
				throw new Exception('Error decoding JSON structure');
			}
			if (!$fileProcessor->parseStructure($structure)) {
				throw new Exception($fileProcessor->getError() ?? 'Error parsing JSON structure');
			}
		} else {
			// Validate uploaded file
			if (empty($file['name']) || $file['error'] !== UPLOAD_ERR_OK) {
				throw new Exception('Error uploading file');
			}

			// Validate file extension
			$extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
			if ($extension !== 'xml') {
				throw new Exception('Only XML files are allowed');
			}

			// Validate MIME type
			$mimeType = mime_content_type($file['tmp_name']);
			if (!in_array($mimeType, array('application/xml', 'text/xml', 'text/plain'))) {
				throw new Exception('Invalid file type. Only XML files are allowed.');
			}

			// Sanitize filename
			$safeFilename = dol_sanitizeFileName($file['name']);
			$upload_file = $dir . '/' . $safeFilename;

			if (!move_uploaded_file($file['tmp_name'], $upload_file)) {
				throw new Exception('Error while uploading the file');
			}

			// Parse XML file with XXE protection
			if (!$fileProcessor->parseFile($upload_file)) {
				throw new Exception($fileProcessor->getError() ?? 'Error parsing XML file');
			}

			$structure = $fileProcessor->getStructure();
		}

		// Get date range. It is derived from the entries the file actually
		// carries, exactly like the headless path (ReconciliationService), so a
		// weekly or daily statement is compared against its own days instead of a
		// whole calendar month. Falling back to the previous month of the creation
		// date, as this page used to do unconditionally, sent every non-monthly
		// statement against a window it has no entry in.
		if (empty($date_start) || empty($date_end)) {
			list($date_start, $date_end) = camt053_entries_date_range($fileProcessor);
		}

		// Validate date format
		if (!$dbLoader->validateDateFormat($date_start) || !$dbLoader->validateDateFormat($date_end)) {
			throw new Exception('Invalid date format. Use dd/mm/yyyy');
		}

		// Load database statements. The window is widened by the matcher's date
		// tolerance: a Dolibarr line dated one day off the CAMT booking date must
		// be loaded, otherwise the tolerance can never reach it.
		$dbStatements = $dbLoader->loadStatements($date_start, $date_end, null, $matcher->getDateTolerance());
		if ($dbLoader->getError()) {
			throw new Exception($dbLoader->getError());
		}

		// Warn about statements whose IBAN matches no bank account in the current
		// entity: their entries are dropped (getStatementsByAccountId keeps only
		// resolved accounts), so reconciliation must not silently ignore them.
		foreach ($fileProcessor->getStatements() as $stmt) {
			if ($stmt->getAccountId() === null && $stmt->getEntryCount() > 0) {
				setEventMessages($langs->trans('Camt053IbanNotInCurrentEntity', $stmt->getIban()), null, 'warnings');
			}
		}

		// Get file statements indexed by account ID
		$fileStatements = $fileProcessor->getStatementsByAccountId();

		// Compare statements
		$banks = $matcher->compareMultiple($fileStatements, $dbStatements, $dbLoader);

		// Check if there are any entries to reconcile
		$hasEntriesToReconcile = false;
		$firstAccountId = null;
		foreach ($banks as $accountId => $bank) {
			if ($firstAccountId === null) {
				$firstAccountId = $accountId;
			}
			$results = $bank['results'];
			if (!empty($results['linkeds']) || !empty($results['multiples'])) {
				$hasEntriesToReconcile = true;
				break;
			}
			// File entries with no counterpart in Dolibarr carry payment/transfer
			// suggestions, so the results page must still be shown for them.
			foreach ($results['unlinkeds'] as $u) {
				if (is_object($u) && method_exists($u, 'isFromFile') && $u->isFromFile()) {
					$hasEntriesToReconcile = true;
					break 2;
				}
			}
		}

		// If nothing to reconcile, prepare redirect to bank statement page
		if (!$hasEntriesToReconcile && $firstAccountId !== null) {
			$date_end_obj = DateTime::createFromFormat('d/m/Y', $date_end);
			$date_concil = $date_end_obj ? $date_end_obj->format('Ym') : '';
			$redirectUrl = DOL_URL_ROOT . '/compta/bank/releve.php?account=' . ((int) $firstAccountId) . '&num=' . urlencode($date_concil);
			setEventMessages($langs->trans('AllEntriesReconciled'), null, 'mesgs');
		}
	} catch (Throwable $e) {
		// Throwable already covers Error and Exception; no narrower catch below.
		dol_syslog('CAMT053: Error processing file - ' . $e->getMessage(), LOG_ERR);
		$processError = $e->getMessage();
	}
}

// Do redirect before any output if needed
if (!empty($redirectUrl)) {
	header('Location: ' . $redirectUrl);
	exit;
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

llxHeader("", $langs->trans("Camt053ReaderAndLinkResults"), '', '', 0, 0, '', '', '', 'mod-camt053readerandlink page-submit');

print $moreCss;

print '<div class="fichecenter camt053readerandlink">';

// Show error if any
if (!empty($processError)) {
	setEventMessages($processError, null, 'errors');
}

// Display results if we have data
if (empty($banks) && empty($processError)) {
	print '<div class="opacitymedium">'.$langs->trans('NoEntriesToReconcile').'</div>';
}
if (!empty($banks)) {
	camt053_render_results($banks, array(
		'date_start' => $date_start,
		'date_end' => $date_end,
		'bank_account_id' => $bank_account_id,
		'structure' => $structure,
		'upload_file' => $upload_file,
		'actionable_first' => false,
	));
}

print '</div>';

// End of page
llxFooter();
$db->close();
