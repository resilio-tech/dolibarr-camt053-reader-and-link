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

/**
 * Derive the reconciliation period from the entries a CAMT.053 file carries.
 *
 * Mirrors ReconciliationService::dateRange() so the interactive and the headless
 * paths agree on the period, and therefore on the statement number computed from
 * its end date. Falls back to the previous month when the file has no usable
 * entry date.
 *
 * @param Camt053FileProcessor $fileProcessor Parsed file
 * @return array{0:string,1:string} [start, end] in d/m/Y
 */
function camt053_entries_date_range($fileProcessor)
{
	$min = null;
	$max = null;

	// Resolved accounts only, exactly like ReconciliationService: an IBAN that
	// matches no Dolibarr account contributes nothing to the reconciliation, and
	// letting its dates widen the window drags unrelated bank lines into the
	// results as "unlinked".
	foreach ($fileProcessor->getStatementsByAccountId() as $statement) {
		foreach ($statement->getEntries() as $entry) {
			// Pin the time: createFromFormat() would otherwise stamp "now", which
			// makes two same-day entries compare unequal.
			$d = DateTime::createFromFormat('Y-m-d H:i:s', $entry->getValueDate() . ' 00:00:00');
			if ($d === false) {
				continue;
			}
			if ($min === null || $d < $min) {
				$min = clone $d;
			}
			if ($max === null || $d > $max) {
				$max = clone $d;
			}
		}
	}

	if ($min === null || $max === null) {
		$creationDate = $fileProcessor->getCreationDate();
		try {
			$d = $creationDate ? new DateTime($creationDate) : new DateTime();
		} catch (Exception $e) {
			$d = new DateTime();
		}
		$d->modify('first day of previous month');

		return array($d->format('01/m/Y'), $d->format('t/m/Y'));
	}

	return array($min->format('d/m/Y'), $max->format('d/m/Y'));
}

/**
 * Render the action links (prefilled payment or internal transfer) offered for
 * a file entry that has no counterpart in Dolibarr.
 *
 * @param Camt053Entry             $entry     Unmatched file entry
 * @param int                      $entity    Bank account entity
 * @param int                      $accountId Bank account the statement belongs to
 * @param PaymentSuggestionFinder  $finder    Payment suggestion finder
 * @param InternalTransferDetector $detector  Internal transfer detector
 * @param Translate                $langs     Language object
 * @return string HTML (empty when nothing is suggested)
 */
function camt053_render_suggestions($entry, $entity, $accountId, $finder, $detector, $langs)
{
	$out = array();

	// Internal transfer: the counterparty is one of the company's own accounts.
	$transfer = $detector->detect($entry, (int) $accountId, (int) $entity);
	if ($transfer !== null) {
		$label = $langs->trans('Camt053SuggestInternalTransfer', dol_escape_htmltag($transfer['counterparty_ref']));
		$out[] = '<a href="' . $detector->confirmUrl($transfer) . '">'
			. img_picto('', 'bank_account', 'class="paddingright"') . $label . '</a>';
	}

	// Unpaid documents of the same amount and currency.
	$labelKeys = array(
		'customer_invoice' => 'Camt053SuggestPayCustomerInvoice',
		'supplier_invoice' => 'Camt053SuggestPaySupplierInvoice',
		'expense_report' => 'Camt053SuggestPayExpenseReport',
		'social_charge' => 'Camt053SuggestPaySocialCharge',
	);
	$pictos = array(
		'customer_invoice' => 'bill',
		'supplier_invoice' => 'supplier_invoice',
		'expense_report' => 'trip',
		'social_charge' => 'payment',
	);

	$suggestions = $finder->findForEntry($entry, (int) $entity, (int) $accountId);
	foreach ($suggestions['links'] as $link) {
		if ($link['kind'] === 'pay') {
			$label = $langs->trans($labelKeys[$link['type']], dol_escape_htmltag($link['ref']));
			$out[] = '<a href="' . $link['url'] . '" target="_blank">'
				. img_picto('', $pictos[$link['type']], 'class="paddingright"') . $label . '</a>';
		} else {
			// Several documents share this amount: let the user pick which one to pay.
			$options = '<option value="">'
				. dol_escape_htmltag($langs->trans('Camt053SuggestChoose', (int) $link['count']))
				. '</option>';
			foreach ($link['options'] as $o) {
				$text = $o['ref'] . ' - ' . $o['label']
					. ' (' . price($o['amount'], 0, $langs, 1, -1, -1, $o['currency']) . ')';
				$options .= '<option value="' . dol_escape_htmltag($o['url']) . '">'
					. dol_escape_htmltag($text) . '</option>';
			}
			$out[] = img_picto('', $pictos[$link['type']], 'class="paddingright"')
				. '<select class="flat maxwidth200onsmartphone"'
				. ' onchange="if(this.value){window.open(this.value,\'_blank\');this.selectedIndex=0;}">'
				. $options . '</select>';
		}
	}

	return implode('<br />', $out);
}

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

/*
 * Actions - Process before any output
 */

$form = new Form($db);
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
		$relationLookup = new BankRelationshipLookup($db);

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
	print '<form id="form" name="form" action="'.dol_buildpath('/custom/camt053readerandlink/confirm.php', 1).'" method="post">';

		$suggestionFinder = new PaymentSuggestionFinder($db);
		$transferDetector = new InternalTransferDetector($db);

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
				// The field key must be unique across the whole form, which spans
				// every account of the file: two accounts can carry an identical
				// movement, and hashes are only deduplicated within a statement.
				$fieldKey = ((int) $accountId) . '-' . $n_obj['file']->getHash();
				print '<td>' . $name . '<input type="hidden" name="linked[' . dol_escape_htmltag($fieldKey) . ']" value="' . ((int) $o->rowid) . '" /></td>';
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
					// Prepend the related document (invoice ref + third party) so
					// same-amount candidates can be told apart in the dropdown.
					$doc = '';
					$relation = $relationLookup->getRelation((int) $id);
					if ($relation !== null && !empty($relation['ref'])) {
						$doc = dol_escape_htmltag(trim($relation['ref'] . ' - ' . $relation['label'])) . '<br />';
					}
					$array[$id] = '(' . $id . ') ' . $doc . $n . '<br />' . $a . '<br />' . $d;
				}
				print $form->selectMassAction('', $array, 1, 'linked_' . dol_escape_htmltag(((int) $accountId) . '-' . $ntry_hash));
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
				// Account::fetch does not load entity; the page runs in the current
				// entity context, which is the one the bank account belongs to.
				$suggestionHtml = $n_obj->isFromFile()
					? camt053_render_suggestions($n_obj, (int) $conf->entity, (int) $accountId, $suggestionFinder, $transferDetector, $langs)
					: '';
				print '<td>' . $suggestionHtml . '</td>';
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
}

print '</div>';

// End of page
llxFooter();
$db->close();
