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

// None

$form = new Form($db);
$formfile = new FormFile($db);

llxHeader("", $langs->trans("Camt053ReaderAndLinkArea"), '', '', 0, 0, '', '', '', 'mod-camt053readerandlink page-index');

print '<div class="fichecenter camt053readerandlink">';

require_once './statements.php';
require_once DOL_DOCUMENT_ROOT.'/compta/bank/class/account.class.php';

if ($action == 'upload') {
	$statement_from_file = new StatementsCamt053();
	$statement_from_file->setIsFile(true);

	if (!empty($file_json)) {
		$structure = json_decode(urldecode($file_json), 1);

		$iban = $structure['BkToCstmrStmt']['Stmt']['Acct']['Id']['IBAN'];
		$iban_format =
			substr($iban, 0, 4) . ' ' .
			substr($iban, 4, 4) . ' ' .
			substr($iban, 8, 4) . ' ' .
			substr($iban, 12, 4) . ' ' .
			substr($iban, 16, 4) . ' ' .
			substr($iban, 20, 1);

	} else {
		$upload_file = $dir . '/' . $file['name'];

		if (move_uploaded_file($file['tmp_name'], $upload_file)){
			// Camt053 file is a XML file
			$xml = simplexml_load_file($upload_file);
			// get structure of the XML file
			$structure = json_decode(json_encode($xml), true);

			// Get Bank Account
			$iban = $structure['BkToCstmrStmt']['Stmt']['Acct']['Id']['IBAN'];
			$iban_format =
				substr($iban, 0, 4) . ' ' .
				substr($iban, 4, 4) . ' ' .
				substr($iban, 8, 4) . ' ' .
				substr($iban, 12, 4) . ' ' .
				substr($iban, 16, 4) . ' ' .
				substr($iban, 20, 1);
			$sql = "SELECT rowid FROM " . MAIN_DB_PREFIX . "bank_account ";
			$sql .= "WHERE iban_prefix = '" . $iban_format . "' ";
			$sql .= "OR iban_prefix = '" . $iban . "'";
			$resql = $db->query($sql);
			if ($resql) {
				$obj = $db->fetch_object($resql);
				$bank_account_id = $obj->rowid;
			} else {
				$error = 'Error while getting the bank account';
			}
		} else {
			$error = 'Error while uploading the file';
		}
	}

	if (!empty($error)) {
		print $error;
		throw new Exception($error);
	}

	foreach ($structure['BkToCstmrStmt']['Stmt']['Ntry'] as $ntry) {
		$type = $ntry['CdtDbtInd'];
		$amount = floatval($ntry['Amt']);
		if ($type == 'DBIT') $amount = -$amount;
		$value_date = new DateTime($ntry['ValDt']['Dt']);
		$name = $ntry['NtryDtls']['TxDtls']['RltdPties']['Cdtr']['Nm'];
		$info = $ntry['NtryDtls']['TxDtls']['RmtInf']['Strd']['AddtlRmtInf'];
		$hash = $ntry['AcctSvcrRef'];

		$n = $statement_from_file->addEntry($amount, $value_date->format('Y-m-d'), $name, $info);
		$n->setHash($hash);
	}

	$statement_from_db = new StatementsCamt053();
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

	if (!empty($error)) {
		print $error;
		throw new Exception($error);
	}


	$date_start_obj = date_create_from_format('d/m/Y', $date_start);
	$date_end_obj = date_create_from_format('d/m/Y', $date_end);
	// Get the data from the database
	$sql = 'SELECT rowid FROM '.MAIN_DB_PREFIX.'bank ';
	$sql .= "WHERE datev >= DATE('". $date_start_obj->format('Y-m-d') ."') AND datev <= DATE('". $date_end_obj->format('Y-m-d') ."') ";
	$sql .= 'AND fk_account = '. $bank_account_id .' ';

	$resql = $db->query($sql);

	$bank_account = new Account($db);
	$bank_account->fetch($bank_account_id);

	if ($resql) {
		while ($obj = $db->fetch_object($resql)) {
			$id = $obj->rowid;
			$obj = new AccountLine($db);
			$obj->fetch($id);
			$bank_links = $bank_account->get_url($obj->id);

			$amount = $obj->amount;
			$value_date = new DateTime();
			date_timestamp_set($value_date, $obj->datev);
			$value_date = $value_date->format('Y-m-d');
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
				$name .= ' - '.$bank_links[1]['label'];
			}

			$ntry_obj = $statement_from_db->addEntry($amount, $value_date, $name);
			$ntry_obj->setBankObj($obj);
		}
	} else {
		$error = 'Error while getting the data from the database ';
		$error .= $db->lasterror();
	}

	if (!empty($error)) {
		print $error;
		throw new Exception($error);
	}

	$results = StatementsCamt053::compare($statement_from_file, $statement_from_db);

	print '<form id="form" name="form" action="/custom/camt053readerandlink/confirm.php" method="post">';

	print '<table class="noborder" style="width: 100%">';
	print '<tr class="liste_titre">';
	print '<td>'.$langs->trans('DateStart').'</td>';
	print '<td>'.$langs->trans('DateEnd').'</td>';
	print '<td>'.$langs->trans('IBAN').'</td>';
	print '<td>'.$langs->trans('BankAccount').'</td>';
	print '</tr>';
	print '<tr>';
	print '<td>'.$date_start.'</td>';
	print '<td>'.$date_end.'</td>';
	print '<td>'.$iban_format.'</td>';
	print '<td>'.$bank_account->getNomUrl(1).'</td>';
	print '</tr>';
	print '</table>';

	print '<table class="noborder" style="width: 100%">';
	print '<tr class="liste_titre">';
	print '<td>'.$langs->trans('Location').'</td>';
	print '<td class="right">'.$langs->trans('Amount').'</td>';
	print '<td>'.$langs->trans('Date').'</td>';
	print '<td>'.$langs->trans('Name').'</td>';
	print '<td>'.$langs->trans('Conciliated').'</td>';
	print '<td>'.$langs->trans('Conciliated').'</td>';
	print '</tr>';
	foreach ($results['linkeds'] as $ntry_obj) {
		$entry = $ntry_obj['file']->getData();
		$o = $ntry_obj['db']->getBankObj();
		$name = $o->label;
		$name = '<a href="'.DOL_URL_ROOT.'/compta/bank/line.php?rowid='.((int) $o->id).'&save_lastsearch_values=1" title="'.dol_escape_htmltag($name, 1).'" class="classfortooltip" target="_blank">'.img_picto('', $o->picto).' '.$o->id.' '.$name.'</a>';
		print '<tr>';
		print '<td>'.($ntry_obj['file']->isFromFile() ? $from_file : $from_doli).'</td>';
		print '<td class="right">'.number_format($entry['amount'],2).'</td>';
		print '<td>'.$entry['value_date'].'</td>';
		print '<td>'.$entry['name'].'<br />'.$entry['info'].'</td>';
		print '<td><div class="statement_link_linked">'.$langs->trans('WillBeConciliated').'</div></td>';
		print '<td>'.$name.'<input type="hidden" name="linked['.$ntry_obj['file']->getHash().']" value="'.$o->id.'" /></td>';
		print '</tr>';
	}
	foreach ($results['multiples'] as $ntry_obj) {
		$entry = $ntry_obj['file']->getData();
		$ntry_hash = $ntry_obj['file']->getHash();
		print '<tr>';
		print '<td>'.($ntry_obj['file']->isFromFile() ? $from_file : $from_doli).'</td>';
		print '<td style="text-align: right">'.number_format($entry['amount'],2).'</td>';
		print '<td>'.$entry['value_date'].'</td>';
		print '<td>'.$entry['name'].'<br />'.$entry['info'].'</td>';
		print '<td><div class="statement_link_multiple">'.$langs->trans('MultipleConciliated').'</div></td>';
		print '<td>';
		// Select for the conciliated on a form selector custom
		$array = array();
		foreach ($ntry_obj['db'] as $ntry_db_obj) {
			$entry = $ntry_db_obj->getData();
			$id = $ntry_db_obj->getBankObj()->rowid;
			$n = $entry['name'];
			$a = number_format($entry['amount'],2);
			$d = $entry['value_date'];
			$array[$id] = '('.$id.') '.$n.'<br />'.$a.'<br />'.$d;
		}
		print $form->selectMassAction('', $array, 1, 'linked_'.$ntry_hash);
		print '</td>';
		print '</tr>';
	}
	foreach ($results['unlinkeds'] as $ntry_obj) {
		$entry = $ntry_obj->getData();
		$name = $entry['name'].'<br />'.$entry['info'];
		$o = $ntry_obj->getBankObj();
		if (!$ntry_obj->isFromFile()) {
			$name = '<a href="'.DOL_URL_ROOT.'/compta/bank/line.php?rowid='.((int) $o->id).'&save_lastsearch_values=1" title="'.dol_escape_htmltag($name, 1).'" class="classfortooltip" target="_blank">'.img_picto('', $o->picto).' '.$o->id.' '.$name.'</a>';
		}
		print '<tr>';
		print '<td>'.($ntry_obj->isFromFile() ? $from_file : $from_doli).'</td>';
		print '<td style="text-align: right">'.number_format($entry['amount'],2).'</td>';
		print '<td>'.$entry['value_date'].'</td>';
		print '<td>'.$name.'</td>';
		print '<td><div class="statement_link_unlinked">'.$langs->trans('WillNotBeConciliated').'</div></td>';
		print '<td></td>';
		print '</tr>';
	}
	print '</table>';

	print '<input type="hidden" name="date_start" value="'.$date_start.'" />';
	print '<input type="hidden" name="date_end" value="'.$date_end.'" />';
	print '<input type="hidden" name="bank_account_id" value="'.$bank_account_id.'" />';
	print '<input type="hidden" name="token" value="'.$_SESSION['newtoken'].'" />';
	print '<input type="hidden" name="action" value="confirm" />';
	print '<input type="hidden" name="file_json" value="'.urlencode(json_encode($structure, 0)).'" />';
	print '<input type="hidden" name="upload_file" value="'.$upload_file.'" />';
	print '<input type="submit" value="'.$langs->trans('Confirm').'" />';
	print '</form>';
}

$NBMAX = getDolGlobalInt('MAIN_SIZE_SHORTLIST_LIMIT');
$max = getDolGlobalInt('MAIN_SIZE_SHORTLIST_LIMIT');

print '</div>';

// End of page
llxFooter();
$db->close();
