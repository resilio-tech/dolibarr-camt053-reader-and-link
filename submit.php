<?php
/* Copyright (C) 2001-2005 Rodolphe Quiedeville <rodolphe@quiedeville.org>
 * Copyright (C) 2004-2015 Laurent Destailleur  <eldy@users.sourceforge.net>
 * Copyright (C) 2005-2012 Regis Houssin        <regis.houssin@inodbox.com>
 * Copyright (C) 2015      Jean-François Ferry	<jfefe@aternatik.fr>
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
 *	\file       camt053readerandlink/index.php
 *	\ingroup    camt053readerandlink
 *	\brief      Home page of camt053readerandlink top menu
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

// Load translation files required by the page
$langs->loadLangs(array(
	"camt053readerandlink@camt053readerandlink",
	"banks",
	"bills",
	"categories",
	"companies",
	"margins",
	"salaries",
	"loan",
	"donations",
	"trips",
	"members",
	"compta",
	"accountancy"
));

$action = GETPOST('action', 'aZ09');

$max = 5;
$now = dol_now();

// Security check - Protection if external user
$socid = GETPOST('socid', 'int');
if (isset($user->socid) && $user->socid > 0) {
	$action = '';
	$socid = $user->socid;
}

// Security check (enable the most restrictive one)
if ($user->socid > 0) accessforbidden();
if ($user->socid > 0) $socid = $user->socid;
if (!isModEnabled('camt053readerandlink')) {
	accessforbidden('Module not enabled');
}
if ($action != 'upload' or (empty($_FILES['file']) and empty(GETPOST('file_json')))) {
	header('Location: ' . DOL_URL_ROOT . '/custom/camt053readerandlink/index.php');
}

$yes = $langs->trans('Yes');
$no = $langs->trans('No');
$from_file = 'Fichier CAMT.053';
$from_doli = 'Dolibarr';
$bank_account_id = GETPOST('bank_account_id', 'int');
$file_json = GETPOST('file_json', 'alpha');
$date_start = GETPOST('date_start', 'alpha');
$date_end = GETPOST('date_end', 'alpha');
$file = !empty($_FILES['file']) ? $_FILES['file'] : null;
$dir = DOL_DATA_ROOT . '/camt053readerandlink';
if (!file_exists($dir)) {
	mkdir($dir, 0777, true);
}

print '<style content="text/css" media="screen">';
print '@import url("/custom/camt053readerandlink/css/camt053readerandlink.css");';
print '</style>';

/*
 * Actions
 */

$getter_iban = ['BkToCstmrStmt', 'Stmt', 'Acct', 'Id', 'IBAN'];
$getter_ntries = ['BkToCstmrStmt', 'Stmt', 'Ntry'];

$form = new Form($db);
$formfile = new FormFile($db);

llxHeader("", $langs->trans("Camt053ReaderAndLinkArea"), '', '', 0, 0, '', '', '', 'mod-camt053readerandlink page-index');

print '<div class="fichecenter camt053readerandlink">';

require_once './statements.php';
require_once DOL_DOCUMENT_ROOT.'/compta/bank/class/account.class.php';

if ($action == 'upload') {
	try {
		$statement_from_file = new StatementsCamt053($db);
		$statement_from_file->setIsFile(true);

		if (!empty($file_json)) {
			$structure = json_decode(urldecode($file_json), 1);
			$statement_from_file->setStructure($structure);
		} else {
			$upload_file = $dir . '/' . $file['name'];

			if (move_uploaded_file($file['tmp_name'], $upload_file)) {
				// Camt053 file is a XML file
				$xml = simplexml_load_file($upload_file);
				// get structure of the XML file
				$structure = json_decode(json_encode($xml), true);
				$statement_from_file->setStructure($structure);
			} else {
				throw new Exception('Error while uploading the file');
			}
		}

		$statement_from_db = new StatementsCamt053($db);
		$statement_from_db->setIsFile(false);

		if (empty($date_start) or empty($date_end)) {
			$d = $structure['BkToCstmrStmt']['GrpHdr']['CreDtTm'];
			$d = new DateTime($d);
			// base on previous month
			$d->modify('first day of previous month');
			// get first day of the previous month
			$date_start = $d->format('01/m/Y');
			// get last day of the previous month
			$date_end = $d->format('t/m/Y');
		}

		$date_start_obj = date_create_from_format('d/m/Y', $date_start);
		$date_end_obj = date_create_from_format('d/m/Y', $date_end);
		// Get the data from the database
		$sql = 'SELECT rowid, fk_account FROM ' . MAIN_DB_PREFIX . 'bank ';
		$sql .= "WHERE datev >= DATE('" . $date_start_obj->format('Y-m-d') . "') AND datev <= DATE('" . $date_end_obj->format('Y-m-d') . "') ";

		$resql = $db->query($sql);

		$bank_account = new Account($db);

		if ($resql) {
			$data = array();
			while ($obj_b = $db->fetch_object($resql)) {
				$id = $obj_b->rowid;
				$obj = new AccountLine($db);
				$obj->fetch($id);

				if (empty($obj->datev)) {
					continue;
				}

				$bank_links = $bank_account->get_url($obj->id);

				$amount = floatval($obj->amount);
				if (is_numeric($obj->datev)){
					$value_date = new DateTime();
					$value_date->setTimestamp($obj->datev);
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
					$name .= ' - ' . $bank_links[1]['label'];
				}

				$data[] = array(
					'amount' => $amount,
					'value_date' => $value_date,
					'name' => $name,
					'bank_obj' => $obj,
				);
			}

			$statement_from_db->setData($data);
		} else {
			$error = 'Error while getting the data from the database ';
			$error .= $db->lasterror();
			throw new Exception($error);
		}

		$banks = StatementsCamt053::compare($statement_from_file, $statement_from_db);

		function getRelationName($line_id) {
			global $db;
			$sql_client = 'SELECT f.rowid, f.ref, s.nom FROM ' . MAIN_DB_PREFIX . 'facture AS f ';
			$sql_client .= 'INNER JOIN ' . MAIN_DB_PREFIX . 'societe AS s ON f.fk_soc = s.rowid ';
			$sql_client .= 'LEFT JOIN ' . MAIN_DB_PREFIX . 'paiement_facture AS pf ON f.rowid = pf.fk_facture ';
			$sql_client .= 'LEFT JOIN ' . MAIN_DB_PREFIX . 'paiement AS p ON pf.fk_paiement = p.rowid ';
			$sql_client .= 'WHERE p.fk_bank = ' . $line_id;
			$resql_client = $db->query($sql_client);
			if ($resql_client) {
				$obj = $db->fetch_object($resql_client);
				if ($obj) {
					$name = $obj->ref . '<br/>' . $obj->nom;
					return '<a href="' . DOL_URL_ROOT . '/compta/facture/card.php?id=' . ((int)$obj->rowid) . '&save_lastsearch_values=1" title="' . dol_escape_htmltag($name, 1) . '" class="classfortooltip" target="_blank">' . img_picto('', 'bill') . ' ' . $obj->rowid . ' ' . $name . '</a>';
				}
			}

			$sql_fourn = 'SELECT f.rowid, f.ref, s.nom FROM ' . MAIN_DB_PREFIX . 'facture_fourn AS f ';
			$sql_fourn .= 'INNER JOIN ' . MAIN_DB_PREFIX . 'societe AS s ON f.fk_soc = s.rowid ';
			$sql_fourn .= 'LEFT JOIN ' . MAIN_DB_PREFIX . 'paiementfourn_facturefourn AS pf ON f.rowid = pf.fk_facturefourn ';
			$sql_fourn .= 'LEFT JOIN ' . MAIN_DB_PREFIX . 'paiementfourn AS p ON pf.fk_paiementfourn = p.rowid ';
			$sql_fourn .= 'WHERE p.fk_bank = ' . $line_id;
			$resql_fourn = $db->query($sql_fourn);
			if ($resql_fourn) {
				$obj = $db->fetch_object($resql_fourn);
				if ($obj) {
					$name = $obj->ref . '<br/>' . $obj->nom;
					return '<a href="' . DOL_URL_ROOT . '/fourn/facture/card.php?id=' . ((int)$obj->rowid) . '&save_lastsearch_values=1" title="' . dol_escape_htmltag($name, 1) . '" class="classfortooltip" target="_blank">' . img_picto('', 'supplier_invoice') . ' ' . $obj->rowid . ' ' . $name . '</a>';
				}
			}

			$sql = 'SELECT rowid, label FROM ' . MAIN_DB_PREFIX . 'bank WHERE rowid = ' . $line_id;
			$resql = $db->query($sql);
			if ($resql) {
				$obj = $db->fetch_object($resql);
				if ($obj) {
					$name = $obj->label;
					return '<a href="' . DOL_URL_ROOT . '/compta/bank/line.php?rowid=' . ((int)$obj->rowid) . '&save_lastsearch_values=1" title="' . dol_escape_htmltag($name, 1) . '" class="classfortooltip" target="_blank">' . img_picto('', 'bank') . ' ' . $obj->rowid . ' ' . $name . '</a>';
				}
			}
		}

		print '<form id="form" name="form" action="/custom/camt053readerandlink/confirm.php" method="post">';

		foreach ($banks as $bank) {
			$results = $bank['results'];
			$bank_account = new Account($db);
			$bank_account->fetch($bank['account']->rowid);
			$iban_format = $bank['account']->iban_prefix;

			print '<table class="noborder" style="width: 100%">';
			print '<tr class="liste_titre">';
			print '<td>' . $langs->trans('DateStart') . '</td>';
			print '<td>' . $langs->trans('DateEnd') . '</td>';
			print '<td>' . $langs->trans('IBAN') . '</td>';
			print '<td>' . $langs->trans('BankAccount') . '</td>';
			print '</tr>';
			print '<tr>';
			print '<td>' . $date_start . '</td>';
			print '<td>' . $date_end . '</td>';
			print '<td>' . $iban_format . '</td>';
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
			foreach ($results['linkeds'] as $n_obj) {
				$entry = $n_obj['file']->getData();
				$o = $n_obj['db']->getBankObj();
				$name = getRelationName($o->id);
				print '<tr>';
				print '<td>' . ($n_obj['file']->isFromFile() ? $from_file : $from_doli) . '</td>';
				print '<td class="right">' . number_format($entry['amount'], 2) . '</td>';
				print '<td>' . $entry['value_date'] . '</td>';
				print '<td>' . $entry['name'] . '<br /><span class="info">' . $entry['info'] . '</span></td>';
				print '<td><div class="statement_link_linked">' . $langs->trans('WillBeConciliated') . '</div></td>';
				print '<td>' . $name . '<input type="hidden" name="linked[' . $n_obj['file']->getHash() . ']" value="' . $o->id . '" /></td>';
				print '</tr>';
			}
			foreach ($results['multiples'] as $n_obj) {
				$entry = $n_obj['file']->getData();
				$ntry_hash = $n_obj['file']->getHash();
				print '<tr>';
				print '<td>' . ($n_obj['file']->isFromFile() ? $from_file : $from_doli) . '</td>';
				print '<td style="text-align: right">' . number_format($entry['amount'], 2) . '</td>';
				print '<td>' . $entry['value_date'] . '</td>';
				print '<td>' . $entry['name'] . '<br /><span class="info">' . $entry['info'] . '</span></td>';
				print '<td><div class="statement_link_multiple">' . $langs->trans('MultipleConciliated') . '</div></td>';
				print '<td>'. $entry['hash'] .'</td>';
				print '<td>';
				// Select for the conciliated on a form selector custom
				$array = array();
				foreach ($n_obj['db'] as $ntry_db_obj) {
					$entry = $ntry_db_obj->getData();
					$id = $ntry_db_obj->getBankObj()->rowid;
					$n = $entry['name'];
					$a = number_format($entry['amount'], 2);
					$d = $entry['value_date'];
					$array[$id] = '(' . $id . ') ' . $n . '<br />' . $a . '<br />' . $d;
				}
				print $form->selectMassAction('', $array, 1, 'linked_' . $ntry_hash);
				print '</td>';
				print '</tr>';
			}
			foreach ($results['unlinkeds'] as $n_obj) {
				$entry = $n_obj->getData();
				$name = $entry['name'];
				$o = $n_obj->getBankObj();
				if (!$n_obj->isFromFile()) {
					$name = getRelationName($o->id);
	//				$name = '<a href="' . DOL_URL_ROOT . '/compta/bank/line.php?rowid=' . ((int)$o->id) . '&save_lastsearch_values=1" title="' . dol_escape_htmltag($name, 1) . '" class="classfortooltip" target="_blank">' . img_picto('', $o->picto) . ' ' . $o->id . ' ' . $name . '</a>';
				}
				print '<tr>';
				print '<td>' . ($n_obj->isFromFile() ? $from_file : $from_doli) . '</td>';
				print '<td style="text-align: right">' . number_format($entry['amount'], 2) . '</td>';
				print '<td>' . $entry['value_date'] . '</td>';
				print '<td>' . $name . '<br /><span class="info">' . $entry['info'] . '</span></td>';
				print '<td><div class="statement_link_unlinked">' . $langs->trans('WillNotBeConciliated') . '</div></td>';
				print '<td></td>';
				print '</tr>';
			}
			foreach ($results['already_linked'] as $n_obj) {
				$is_file = false;
				$hash = '';
				if (array_key_exists('file', $n_obj) && $n_obj['file'] instanceof EntryCamt053) {
					$entry = $n_obj['file']->getData();
					$is_file = $n_obj['file']->isFromFile();
					$hash = $n_obj['file']->getHash();
				} else {
					$entry = $n_obj['db']->getData();
					$is_file = $n_obj['db']->isFromFile();
					$hash = $n_obj['db']->getHash();
				}
				$o = $n_obj['db']->getBankObj();
				$name = getRelationName($o->id);
	//			$name = $o->label;
	//			$name = '<a href="' . DOL_URL_ROOT . '/compta/bank/line.php?rowid=' . ((int)$o->id) . '&save_lastsearch_values=1" title="' . dol_escape_htmltag($name, 1) . '" class="classfortooltip" target="_blank">' . img_picto('', $o->picto) . ' ' . $o->id . ' ' . $name . '</a>';
				print '<tr>';
				print '<td>' . ($is_file ? $from_file : $from_doli) . '</td>';
				print '<td class="right">' . number_format($entry['amount'], 2) . '</td>';
				print '<td>' . $entry['value_date'] . '</td>';
				print '<td>' . $entry['name'] . '<br /><span class="info">' . $entry['info'] . '</span></td>';
				print '<td><div class="statement_link_already_linked">' . $langs->trans('AlreadyBeConciliated') . '</div></td>';
				print '<td>' . $name . '</td>';
				print '</tr>';
			}
			print '</table>';
			}

		print '<input type="hidden" name="date_start" value="' . $date_start . '" />';
		print '<input type="hidden" name="date_end" value="' . $date_end . '" />';
		print '<input type="hidden" name="bank_account_id" value="' . $bank_account_id . '" />';
		print '<input type="hidden" name="token" value="' . $_SESSION['newtoken'] . '" />';
		print '<input type="hidden" name="action" value="confirm" />';
		print '<input type="hidden" name="file_json" value="' . urlencode(json_encode($structure, 0)) . '" />';
		print '<input type="hidden" name="upload_file" value="' . $upload_file . '" />';
		print '<input type="submit" value="' . $langs->trans('Confirm') . '" />';

		print '</form>';
	} catch (Exception $e) {
		var_dump('Error while uploading and parse the file');
		print $e->getMessage();
	}
}

$NBMAX = getDolGlobalInt('MAIN_SIZE_SHORTLIST_LIMIT');
$max = getDolGlobalInt('MAIN_SIZE_SHORTLIST_LIMIT');

print '</div>';

// End of page
llxFooter();
$db->close();
