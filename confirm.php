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
require_once __DIR__ . '/class/BankEntryReconciler.class.php';

// Load translation files required by the page
$langs->loadLangs(array("camt053readerandlink@camt053readerandlink"));

// Security check
if (!isModEnabled('camt053readerandlink')) {
	accessforbidden('Module not enabled');
}
if (empty($user->admin)) {
	accessforbidden('Must be admin');
}

llxHeader("", $langs->trans("Camt053ReaderAndLinkArea"), '', '', 0, 0, '', '', '', 'mod-camt053readerandlink page-index');

print '<div class="fichecenter camt053readerandlink">';

// Get linked entries from form
$linked = GETPOST('linked', 'array');
foreach ($_POST as $key => $value) {
	if (preg_match('/^linked_(.+)$/', $key, $matches)) {
		$hash = $matches[1];
		$linked[$hash] = $value;
	}
}
$date_start = GETPOST('date_start', 'alphanohtml');
$date_end = GETPOST('date_end', 'alphanohtml');
$bank_account_id = GETPOSTINT('bank_account_id');
$file_json = json_decode(urldecode(GETPOST('file_json', 'alpha')), true);
$upload_file = GETPOST('upload_file', 'alpha');

// Validate upload_file path to prevent path traversal
if (!empty($upload_file)) {
	$realUploadFile = realpath($upload_file);
	$allowedDir = realpath(DOL_DATA_ROOT . '/camt053readerandlink');
	if ($realUploadFile === false || strpos($realUploadFile, $allowedDir) !== 0) {
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
$reconciler = new BankEntryReconciler($db, $user);

try {
	// Process each linked entry
	foreach ($linked as $key => $link) {
		if (empty($link) || $link == 0) {
			continue;
		}

		$bankLineId = (int) $link;
		$obj = new AccountLine($db);
		$result = $obj->fetch($bankLineId);

		if ($result <= 0) {
			continue;
		}

		// Reconcile the entry
		$obj->num_releve = $date_concil;
		$obj->update_conciliation($user, 0, 1);

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

		$name = '<a href="' . DOL_URL_ROOT . '/compta/bank/line.php?rowid=' . ((int)$obj->id) . '&save_lastsearch_values=1" title="' . dol_escape_htmltag($name, 1) . '" class="classfortooltip" target="_blank">' . img_picto('', $obj->picto) . ' ' . $obj->id . ' ' . $name . '</a>';

		print '<tr>';
		print '<td>' . $name . '</td>';
		print '<td>' . dol_escape_htmltag($value_date) . '</td>';
		print '<td class="right">' . number_format($amount, 2) . '</td>';
		print '</tr>';
	}

	// Move uploaded file to document storage
	if (!empty($upload_file) && file_exists($upload_file)) {
		$id = $bank_account_id;
		$numref = $date_concil;
		$modulepart = 'bank';
		$permissiontoadd = $user->rights->banque->modifier;
		$permtoedit = $user->rights->banque->modifier;
		$param = '&id=' . $id . '&num=' . urlencode($numref);
		$moreparam = '&num=' . urlencode($numref);
		$relativepathwithnofile = $id . "/statement/" . dol_sanitizeFileName($numref) . "/";
		$object = new Account($db);
		$object->fetch($id);

		// Get filename from upload path
		$file = basename($upload_file);
		$dir = 'bank/' . ((int) $id) . '/statement/' . dol_sanitizeFileName($numref);

		include_once DOL_DOCUMENT_ROOT . '/ecm/class/ecmfiles.class.php';
		$ecmfile = new EcmFiles($db);
		$sanitizedFilename = dol_sanitizeFileName($file);
		$relativepath = $dir . '/' . $sanitizedFilename;

		// Check if ECM entry already exists for this file
		$existingEcm = $ecmfile->fetch(0, '', $relativepath);

		if ($existingEcm <= 0) {
			// Entry does not exist, create it
			$ecmfile->filepath = $dir;
			$ecmfile->filename = $sanitizedFilename;
			$ecmfile->label = md5_file(dol_osencode($upload_file)); // MD5 of file content
			$ecmfile->fullpath_orig = $file;
			$ecmfile->gen_or_uploaded = 'uploaded';
			$ecmfile->description = '';
			$ecmfile->keywords = '';

			if (is_object($object) && $object->id > 0) {
				$ecmfile->src_object_id = $object->id;
				if (isset($object->table_element)) {
					$ecmfile->src_object_type = $object->table_element;
				} else {
					dol_syslog('Error: object ' . get_class($object) . ' has no table_element attribute.');
				}
				if (isset($object->src_object_description)) {
					$ecmfile->description = $object->src_object_description;
				}
				if (isset($object->src_object_keywords)) {
					$ecmfile->keywords = $object->src_object_keywords;
				}
			}

			require_once DOL_DOCUMENT_ROOT . '/core/lib/security2.lib.php';
			$ecmfile->share = getRandomPassword(true);
			$result = $ecmfile->create($user);
			if ($result < 0) {
				dol_syslog('CAMT053: Error creating ECM file entry - ' . $ecmfile->error, LOG_ERR);
			}
		} else {
			dol_syslog('CAMT053: ECM file entry already exists for ' . $relativepath, LOG_DEBUG);
		}

		// Create target directory using Dolibarr function (secure permissions)
		$targetDir = DOL_DATA_ROOT . '/' . $dir;
		if (!is_dir($targetDir)) {
			dol_mkdir($targetDir);
		}

		// Move file to target directory (only if it doesn't already exist)
		$targetFile = $targetDir . '/' . $sanitizedFilename;
		if (file_exists($targetFile)) {
			// File already exists, remove the uploaded temporary file
			@unlink($upload_file);
			dol_syslog('CAMT053: File already exists at ' . $targetFile . ', skipping move', LOG_DEBUG);
		} elseif (!rename($upload_file, $targetFile)) {
			dol_syslog('CAMT053: Error moving file to ' . $targetFile, LOG_ERR);
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
} catch (Exception $e) {
	dol_syslog('CAMT053: Error during confirmation - ' . $e->getMessage(), LOG_ERR);
	setEventMessages($langs->trans('ErrorProcessingFile') . ': ' . $e->getMessage(), null, 'errors');
}

// End of page
llxFooter();
$db->close();
