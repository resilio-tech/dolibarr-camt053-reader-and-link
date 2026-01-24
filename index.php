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

// Load translation files required by the page
$langs->loadLangs(array("camt053readerandlink@camt053readerandlink"));

// Security check
if (!isModEnabled('camt053readerandlink')) {
	accessforbidden('Module not enabled');
}
if (!$user->hasRight('banque', 'lire')) {
	accessforbidden();
}

$form = new Form($db);

llxHeader("", $langs->trans("Camt053ReaderAndLinkArea"), '', '', 0, 0, '', '', '', 'mod-camt053readerandlink page-index');

print '<div class="fichecenter camt053readerandlink"><div class="fichethirdleft">';
print '<form id="form" name="form" action="/custom/camt053readerandlink/submit.php" method="post" enctype="multipart/form-data">';
//
//print '<label for="bank_account_id">' . $langs->trans("Account") . '</label>';
//print $form->select_comptes(0, 'bank_account_id', 0, '', 1, '', 0, 'maxwidth200 widthcentpercentminusx', 1);

print '<br /><br />';

print '<p>' . $langs->trans('BasedOnPreviousMonthIfNotFilled') . '</p>';
print '<label for="date">' . $langs->trans("Date") . '</label>';
print $form->selectDateToDate(-1, -1, 'date', '', '');

print '<br /><br />';

print '<div id="file_input">';
print '<label for="file">' . $langs->trans("Camt053ReaderAndLinkFile") . '</label>';
print '<input type="file" name="file" id="file" accept=".xml" required />';
print '</div>';

print '<input type="hidden" name="token" value="' . newToken() . '" />';
print '<input type="hidden" name="action" value="upload" />';

print '</form>';
print '</div></div>';

// End of page
llxFooter();
$db->close();
