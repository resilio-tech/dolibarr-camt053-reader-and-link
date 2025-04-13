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

include_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/html.formfile.class.php';

// Load translation files required by the page
$langs->loadLangs(array("camt053readerandlink@camt053readerandlink"));

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
if (empty($user->admin)) {
	accessforbidden('Must be admin');
}


/*
 * Actions
 */

// None


/*
 * View
 */
require_once DOL_DOCUMENT_ROOT.'/compta/bank/class/account.class.php';

print '<style content="text/css" media="screen">';
print '@import url("/custom/camt053readerandlink/css/camt053readerandlink.css");';
print '</style>';

$form = new Form($db);
$formfile = new FormFile($db);

llxHeader("", $langs->trans("Camt053ReaderAndLinkArea"), '', '', 0, 0, '', '', '', 'mod-camt053readerandlink page-index');

print '<div class="fichecenter camt053readerandlink">';

$linked = GETPOST('linked', 'array');
print '<pre>';
print_r($linked);
print '</pre>';
print '<hr />';
foreach ($_POST as $key => $value) {
	if (preg_match('/^linked_(.+)$/', $key, $matches)) {
		$hash = $matches[1];
		$linked[$hash] = $value;
	}
}
$date_start = GETPOST('date_start', 'alphanohtml');
$date_end = GETPOST('date_end', 'alphanohtml');
$bank_account_id = GETPOSTINT('bank_account_id');
$file_json = json_decode(urldecode(GETPOST('file_json', 'alpha')), 1);
$upload_file = GETPOST('upload_file', 'alpha');

$date_end_obj = date_create_from_format('d/m/Y', $date_end);
$date_concil = $date_end_obj->format('Ym');

print load_fiche_titre($langs->trans("ConcilationsConfirmed"), '', '');

print '<table class="noborder" style="width: 100%">';
print '<tr class="liste_titre">';
print '<td>'.$langs->trans('Name').'</td>';
print '<td>'.$langs->trans('Date').'</td>';
print '<td class="right">'.$langs->trans('Amount').'</td>';
print '</tr>';

$bank_account = new Account($db);
try {
	foreach ($linked as $key => $link) {
		if (empty($link) || $link == 0) {
			continue;
		}
		$obj = new AccountLine($db);
		$obj->fetch($link);
		$obj->num_releve = $date_concil;
		$obj->update_conciliation($user, 0, 1);

		if (empty($obj->datev)) {
			continue;
		}

		$bank_links = $bank_account->get_url($obj->id);

		$amount = $obj->amount;
		if (is_numeric($obj->datev)) {
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

		$name = '<a href="' . DOL_URL_ROOT . '/compta/bank/line.php?rowid=' . ((int)$obj->id) . '&save_lastsearch_values=1" title="' . dol_escape_htmltag($name, 1) . '" class="classfortooltip" target="_blank">' . img_picto('', $obj->picto) . ' ' . $obj->id . ' ' . $name . '</a>';

		print '<tr>';
		print '<td>' . $name . '</td>';
		print '<td>' . $value_date . '</td>';
		print '<td class="right">' . number_format($amount, 2) . '</td>';
		print '</tr>';
	}

	if (!empty($upload_file)) {
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
		// get all directories from $upload_file
//	$dir = substr($upload_file, 0, strrpos($upload_file, '/'));
		$file = substr($upload_file, strrpos($upload_file, '/') + 1);
		$dir = 'bank/' . $id . '/statement/' . dol_sanitizeFileName($numref);
		include_once DOL_DOCUMENT_ROOT . '/ecm/class/ecmfiles.class.php';
		$ecmfile = new EcmFiles($db);
		$ecmfile->filepath = $dir;
		$ecmfile->filename = $file;
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
				return -1;
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
			dol_syslog($ecmfile->error);
		}
		if (!is_dir(DOL_DOCUMENT_ROOT . '/documents/' . $dir)) {
			mkdir(DOL_DOCUMENT_ROOT . '/documents/' . $dir, 0777, true);
		}
		rename($upload_file, DOL_DOCUMENT_ROOT . '/documents/' . $dir . '/' . $file);
	}

	print '</table>';

	print '<form method="POST" action="/custom/camt053readerandlink/submit.php" enctype="multipart/form-data">';
	print '<input type="hidden" name="date_start" value="' . $date_start . '">';
	print '<input type="hidden" name="date_end" value="' . $date_end . '">';
	print '<input type="hidden" name="bank_account_id" value="' . $bank_account_id . '">';
	print '<input type="hidden" name="file_json" value="' . urlencode(json_encode($file_json, 0)) . '">';
	print '<input type="hidden" name="token" value="' . newToken() . '">';
	print '<input type="hidden" name="action" value="upload">';
	print '<input type="submit" value="' . $langs->trans('CheckNewConciliations') . '">';
	print '</form>';


	$NBMAX = getDolGlobalInt('MAIN_SIZE_SHORTLIST_LIMIT');
	$max = getDolGlobalInt('MAIN_SIZE_SHORTLIST_LIMIT');

	print '</div>';
} catch (Exception $e) {
	var_dump($e);
	var_dump($e->getMessage());
	print $e->getMessage();
}

// End of page
llxFooter();
$db->close();
