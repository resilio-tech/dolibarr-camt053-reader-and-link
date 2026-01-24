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
require_once __DIR__ . '/statements.php';
require_once __DIR__ . '/class/Camt053FileProcessor.class.php';
require_once __DIR__ . '/class/DatabaseBankStatementLoader.class.php';
require_once __DIR__ . '/class/BankStatementMatcher.class.php';
require_once __DIR__ . '/class/BankRelationshipLookup.class.php';

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

// Redirect if no upload action
if ($action != 'upload' || (empty($_FILES['file']) && empty(GETPOST('file_json')))) {
	header('Location: ' . dol_buildpath('/custom/camt053readerandlink/index.php', 1));
	exit;
}

$yes = $langs->trans('Yes');
$no = $langs->trans('No');
$from_file = 'Fichier CAMT.053';
$from_doli = 'Dolibarr';
$bank_account_id = GETPOSTINT('bank_account_id');
$file_json = GETPOST('file_json', 'alpha');
$date_start = GETPOST('date_start', 'alpha');
$date_end = GETPOST('date_end', 'alpha');
$file = !empty($_FILES['file']) ? $_FILES['file'] : null;

// Secure directory creation using Dolibarr function
$dir = DOL_DATA_ROOT . '/camt053readerandlink';
if (!file_exists($dir)) {
	dol_mkdir($dir);
}

print '<style content="text/css" media="screen">';
print '@import url("/custom/camt053readerandlink/css/camt053readerandlink.css");';
print '</style>';

/*
 * Actions
 */

$form = new Form($db);

llxHeader("", $langs->trans("Camt053ReaderAndLinkArea"), '', '', 0, 0, '', '', '', 'mod-camt053readerandlink page-index');

print '<div class="fichecenter camt053readerandlink">';

if ($action == 'upload') {
	try {
		$fileProcessor = new Camt053FileProcessor($db);
		$dbLoader = new DatabaseBankStatementLoader($db, $langs);
		$matcher = new BankStatementMatcher(1); // 1 day tolerance
		$relationLookup = new BankRelationshipLookup($db);

		$structure = null;
		$upload_file = '';

		if (!empty($file_json)) {
			// Parse from previously uploaded JSON
			$structure = json_decode(urldecode($file_json), true);
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

		// Get date range
		if (empty($date_start) || empty($date_end)) {
			$creationDate = $fileProcessor->getCreationDate();
			if ($creationDate) {
				$d = new DateTime($creationDate);
			} else {
				$d = new DateTime();
			}
			// Base on previous month
			$d->modify('first day of previous month');
			$date_start = $d->format('01/m/Y');
			$date_end = $d->format('t/m/Y');
		}

		// Validate date format
		if (!$dbLoader->validateDateFormat($date_start) || !$dbLoader->validateDateFormat($date_end)) {
			throw new Exception('Invalid date format. Use dd/mm/yyyy');
		}

		// Load database statements
		$dbStatements = $dbLoader->loadStatements($date_start, $date_end);
		if ($dbLoader->getError()) {
			throw new Exception($dbLoader->getError());
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
		}

		// If nothing to reconcile, redirect to bank statement page
		if (!$hasEntriesToReconcile && $firstAccountId !== null) {
			$date_end_obj = DateTime::createFromFormat('d/m/Y', $date_end);
			$date_concil = $date_end_obj ? $date_end_obj->format('Ym') : '';
			$statementUrl = DOL_URL_ROOT . '/compta/bank/releve.php?account=' . ((int) $firstAccountId) . '&num=' . urlencode($date_concil);
			setEventMessages($langs->trans('AllEntriesReconciled'), null, 'mesgs');
			header('Location: ' . $statementUrl);
			exit;
		}

		// Display results
		print '<form id="form" name="form" action="'.dol_buildpath('/custom/camt053readerandlink/confirm.php', 1).'" method="post">';

		foreach ($banks as $accountId => $bank) {
			$results = $bank['results'];
			$bank_account = new Account($db);
			$bank_account->fetch($accountId);
			$iban_format = isset($bank['account']) ? $bank['account']->iban_prefix : '';

			print '<table class="noborder" style="width: 100%">';
			print '<tr class="liste_titre">';
			print '<td>' . $langs->trans('DateStart') . '</td>';
			print '<td>' . $langs->trans('DateEnd') . '</td>';
			print '<td>' . $langs->trans('IBAN') . '</td>';
			print '<td>' . $langs->trans('BankAccount') . '</td>';
			print '</tr>';
			print '<tr>';
			print '<td>' . dol_escape_htmltag($date_start) . '</td>';
			print '<td>' . dol_escape_htmltag($date_end) . '</td>';
			print '<td>' . dol_escape_htmltag($iban_format) . '</td>';
			print '<td>' . $bank_account->getNomUrl(1) . '</td>';
			print '</tr>';
			print '</table>';

			print '<table class="noborder" style="width: 100%">';
			print '<tr class="liste_titre">';
			print '<td>' . $langs->trans('Location') . '</td>';
			print '<td class="right">' . $langs->trans('Amount') . '</td>';
			print '<td>' . $langs->trans('Date') . '</td>';
			print '<td>' . $langs->trans('Name') . '</td>';
			print '<td>' . $langs->trans('Conciliated') . '</td>';
			print '<td>' . $langs->trans('Conciliated') . '</td>';
			print '<td>hash</td>';
			print '</tr>';

			// Linked entries
			foreach ($results['linkeds'] as $n_obj) {
				$entry = $n_obj['file']->getData();
				$o = $n_obj['db']->getBankLine();
				$name = $relationLookup->getRelationHtml($o->rowid);
				print '<tr>';
				print '<td>' . ($n_obj['file']->isFromFile() ? $from_file : $from_doli) . '</td>';
				print '<td class="right">' . number_format($entry['amount'], 2) . '</td>';
				print '<td>' . dol_escape_htmltag($entry['value_date']) . '</td>';
				print '<td>' . dol_escape_htmltag($entry['name']) . '<br /><span class="info">' . dol_escape_htmltag($entry['info']) . '</span></td>';
				print '<td><div class="statement_link_linked">' . $langs->trans('WillBeConciliated') . '</div></td>';
				print '<td>' . $name . '<input type="hidden" name="linked[' . dol_escape_htmltag($n_obj['file']->getHash()) . ']" value="' . ((int) $o->rowid) . '" /></td>';
				print '</tr>';
			}

			// Multiple matches
			foreach ($results['multiples'] as $n_obj) {
				$entry = $n_obj['file']->getData();
				$ntry_hash = $n_obj['file']->getHash();
				print '<tr>';
				print '<td>' . ($n_obj['file']->isFromFile() ? $from_file : $from_doli) . '</td>';
				print '<td style="text-align: right">' . number_format($entry['amount'], 2) . '</td>';
				print '<td>' . dol_escape_htmltag($entry['value_date']) . '</td>';
				print '<td>' . dol_escape_htmltag($entry['name']) . '<br /><span class="info">' . dol_escape_htmltag($entry['info']) . '</span></td>';
				print '<td><div class="statement_link_multiple">' . $langs->trans('MultipleConciliated') . '</div></td>';
				print '<td>' . dol_escape_htmltag($entry['hash']) . '</td>';
				print '<td>';
				// Select for multiple matches
				$array = array();
				foreach ($n_obj['db'] as $ntry_db_obj) {
					$dbEntry = $ntry_db_obj->getData();
					$id = $ntry_db_obj->getBankLine()->rowid;
					$n = dol_escape_htmltag($dbEntry['name']);
					$a = number_format($dbEntry['amount'], 2);
					$d = dol_escape_htmltag($dbEntry['value_date']);
					$array[$id] = '(' . $id . ') ' . $n . '<br />' . $a . '<br />' . $d;
				}
				print $form->selectMassAction('', $array, 1, 'linked_' . dol_escape_htmltag($ntry_hash));
				print '</td>';
				print '</tr>';
			}

			// Unlinked entries
			foreach ($results['unlinkeds'] as $n_obj) {
				$entry = $n_obj->getData();
				$name = dol_escape_htmltag($entry['name']);
				$o = $n_obj->getBankLine();
				if (!$n_obj->isFromFile() && $o) {
					$name = $relationLookup->getRelationHtml($o->id);
				}
				print '<tr>';
				print '<td>' . ($n_obj->isFromFile() ? $from_file : $from_doli) . '</td>';
				print '<td style="text-align: right">' . number_format($entry['amount'], 2) . '</td>';
				print '<td>' . dol_escape_htmltag($entry['value_date']) . '</td>';
				print '<td>' . $name . '<br /><span class="info">' . dol_escape_htmltag($entry['info']) . '</span></td>';
				print '<td><div class="statement_link_unlinked">' . $langs->trans('WillNotBeConciliated') . '</div></td>';
				print '<td></td>';
				print '</tr>';
			}

			// Already linked entries
			foreach ($results['already_linked'] as $n_obj) {
				$is_file = false;
				$hash = '';
				if (isset($n_obj['file']) && $n_obj['file'] instanceof Camt053Entry) {
					$entry = $n_obj['file']->getData();
					$is_file = $n_obj['file']->isFromFile();
					$hash = $n_obj['file']->getHash();
				} else {
					$entry = $n_obj['db']->getData();
					$is_file = $n_obj['db']->isFromFile();
					$hash = $n_obj['db']->getHash();
				}
				$o = $n_obj['db']->getBankLine();
				$name = $relationLookup->getRelationHtml($o->id);
				print '<tr>';
				print '<td>' . ($is_file ? $from_file : $from_doli) . '</td>';
				print '<td class="right">' . number_format($entry['amount'], 2) . '</td>';
				print '<td>' . dol_escape_htmltag($entry['value_date']) . '</td>';
				print '<td>' . dol_escape_htmltag($entry['name']) . '<br /><span class="info">' . dol_escape_htmltag($entry['info']) . '</span></td>';
				print '<td><div class="statement_link_already_linked">' . $langs->trans('AlreadyBeConciliated') . '</div></td>';
				print '<td>' . $name . '</td>';
				print '</tr>';
			}
			print '</table>';
		}

		print '<input type="hidden" name="date_start" value="' . dol_escape_htmltag($date_start) . '" />';
		print '<input type="hidden" name="date_end" value="' . dol_escape_htmltag($date_end) . '" />';
		print '<input type="hidden" name="bank_account_id" value="' . ((int) $bank_account_id) . '" />';
		print '<input type="hidden" name="token" value="' . newToken() . '" />';
		print '<input type="hidden" name="action" value="confirm" />';
		print '<input type="hidden" name="file_json" value="' . dol_escape_htmltag(urlencode(json_encode($structure, 0))) . '" />';
		print '<input type="hidden" name="upload_file" value="' . dol_escape_htmltag($upload_file) . '" />';
		print '<input type="submit" value="' . $langs->trans('Confirm') . '" />';

		print '</form>';
	} catch (Exception $e) {
		dol_syslog('CAMT053: Error processing file - ' . $e->getMessage(), LOG_ERR);
		setEventMessages($e->getMessage(), null, 'errors');
	}
}

print '</div>';

// End of page
llxFooter();
$db->close();
