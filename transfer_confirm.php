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
 *	\file       camt053readerandlink/transfer_confirm.php
 *	\ingroup    camt053readerandlink
 *	\brief      Prefilled confirmation page for an internal (account-to-account)
 *	            transfer detected from a CAMT.053 entry. Submits to the native
 *	            bank transfer page so Dolibarr creates the transfer.
 */

// Load Dolibarr environment
$res = 0;
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) {
	$res = @include $_SERVER["CONTEXT_DOCUMENT_ROOT"]."/main.inc.php";
}
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

$langs->loadLangs(array("camt053readerandlink@camt053readerandlink", "banks", "compta"));

// Security check
if (!isModEnabled('camt053readerandlink')) {
	accessforbidden('Module not enabled');
}
if (!$user->hasRight('banque', 'transfer')) {
	accessforbidden();
}

$fromId = GETPOSTINT('from');
$toId = GETPOSTINT('to');
$amount = (float) price2num(GETPOST('amount', 'alpha'), 'MT');
$date = GETPOST('date', 'alphanohtml');

$error = '';

$accountFrom = new Account($db);
$accountTo = new Account($db);
if ($fromId <= 0 || $toId <= 0 || $fromId === $toId) {
	$error = $langs->trans('Camt053TransferInvalidAccounts');
} elseif ($accountFrom->fetch($fromId) <= 0 || $accountTo->fetch($toId) <= 0) {
	$error = $langs->trans('Camt053TransferInvalidAccounts');
} else {
	// Account::fetch does not load entity; verify both accounts belong to an
	// allowed entity directly (prevents crafting a cross-entity transfer URL).
	$sqlEnt = "SELECT rowid FROM ".MAIN_DB_PREFIX."bank_account";
	$sqlEnt .= " WHERE entity IN (".getEntity('bank_account').")";
	$sqlEnt .= " AND rowid IN (".((int) $fromId).", ".((int) $toId).")";
	$resEnt = $db->query($sqlEnt);
	if (!$resEnt || $db->num_rows($resEnt) < 2) {
		$error = $langs->trans('Camt053TransferInvalidAccounts');
	}
}

// Parse date (Y-m-d), default today
$day = (int) date('j');
$month = (int) date('n');
$year = (int) date('Y');
if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', (string) $date, $m)) {
	$year = (int) $m[1];
	$month = (int) $m[2];
	$day = (int) $m[3];
}

/**
 * Latest multicurrency rate for a currency (1.0 for the company currency).
 */
function camt053_currency_rate($db, string $code, int $entity): float
{
	$code = strtoupper($code);
	if ($code === '' || $code === strtoupper((string) getDolGlobalString('MAIN_MONNAIE'))) {
		return 1.0;
	}
	$sql = "SELECT r.rate FROM ".MAIN_DB_PREFIX."multicurrency m";
	$sql .= " JOIN ".MAIN_DB_PREFIX."multicurrency_rate r ON r.fk_multicurrency = m.rowid";
	$sql .= " WHERE m.code = '".$db->escape($code)."' AND m.entity = ".((int) $entity);
	$sql .= " ORDER BY r.date_sync DESC";
	$db->plimit(1);
	$resql = $db->query($sql);
	if ($resql && ($o = $db->fetch_object($resql)) && (float) $o->rate > 0) {
		return (float) $o->rate;
	}
	return 1.0;
}

// Destination amount: equal currencies -> same amount, else convert via rates.
$amountTo = $amount;
if (empty($error) && $accountFrom->currency_code !== $accountTo->currency_code) {
	$rateFrom = camt053_currency_rate($db, $accountFrom->currency_code, (int) $conf->entity);
	$rateTo = camt053_currency_rate($db, $accountTo->currency_code, (int) $conf->entity);
	if ($rateFrom > 0) {
		$amountTo = round($amount / $rateFrom * $rateTo, 2);
	}
}

$idvir = dol_getIdFromCode($db, 'VIR', 'c_paiement', 'code', 'id');

/*
 * View
 */

llxHeader("", $langs->trans("Camt053TransferConfirmTitle"), '', '', 0, 0, '', '', '', 'mod-camt053readerandlink page-transfer-confirm');

print '<div class="fichecenter camt053readerandlink">';
print load_fiche_titre($langs->trans("Camt053TransferConfirmTitle"), '', 'bank_account');

if (!empty($error)) {
	setEventMessages($error, null, 'errors');
	print '<a class="butAction" href="javascript:history.back();">'.$langs->trans('Back').'</a>';
	print '</div>';
	llxFooter();
	$db->close();
	exit;
}

print '<span class="opacitymedium">'.$langs->trans('Camt053TransferConfirmHelp').'</span><br><br>';

print '<form name="camt053transfer" method="POST" action="'.DOL_URL_ROOT.'/compta/bank/transfer.php">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="add">';
print '<input type="hidden" name="1_account_from" value="'.((int) $fromId).'">';
print '<input type="hidden" name="1_account_to" value="'.((int) $toId).'">';
print '<input type="hidden" name="1_type" value="'.((int) $idvir).'">';
print '<input type="hidden" name="1_day" value="'.((int) $day).'">';
print '<input type="hidden" name="1_month" value="'.((int) $month).'">';
print '<input type="hidden" name="1_year" value="'.((int) $year).'">';

// transfer.php loops over MAXLINESFORTRANSFERT (20) lines and treats a line as
// active unless its accounts are < 0. Mark the unused lines as empty (-1) so
// only line 1 (our transfer) is processed.
for ($k = 2; $k < 20; $k++) {
	print '<input type="hidden" name="'.$k.'_account_from" value="-1">';
	print '<input type="hidden" name="'.$k.'_account_to" value="-1">';
}

print '<table class="border centpercent">';

print '<tr><td class="titlefieldcreate">'.$langs->trans('TransferFrom').'</td><td>';
print $accountFrom->getNomUrl(1).' <span class="opacitymedium">('.dol_escape_htmltag($accountFrom->currency_code).')</span>';
print '</td></tr>';

print '<tr><td>'.$langs->trans('TransferTo').'</td><td>';
print $accountTo->getNomUrl(1).' <span class="opacitymedium">('.dol_escape_htmltag($accountTo->currency_code).')</span>';
print '</td></tr>';

print '<tr><td>'.$langs->trans('Date').'</td><td>'.dol_print_date(dol_mktime(12, 0, 0, $month, $day, $year), 'day').'</td></tr>';

print '<tr><td class="fieldrequired">'.$langs->trans('Description').'</td><td>';
print '<input type="text" name="1_label" class="minwidth300" value="'.dol_escape_htmltag($langs->trans('Camt053TransferLabel')).'">';
print '</td></tr>';

print '<tr><td class="fieldrequired">'.$langs->trans('Amount').' ('.dol_escape_htmltag($accountFrom->currency_code).')</td><td>';
print '<input type="text" name="1_amount" class="width100" value="'.price($amount).'">';
print '</td></tr>';

// Destination amount only matters (and is required) for cross-currency transfers.
if ($accountFrom->currency_code !== $accountTo->currency_code) {
	print '<tr><td class="fieldrequired">'.$langs->trans('AmountToOthercurrency').' ('.dol_escape_htmltag($accountTo->currency_code).')</td><td>';
	print '<input type="text" name="1_amountto" class="width100" value="'.price($amountTo).'">';
	print ' <span class="opacitymedium">'.$langs->trans('Camt053TransferRateHint').'</span>';
	print '</td></tr>';
} else {
	print '<input type="hidden" name="1_amountto" value="'.price($amountTo).'">';
}

print '</table>';

print '<br><div class="center">';
print '<input type="submit" class="button" value="'.$langs->trans('Camt053TransferCreate').'">';
print ' &nbsp; <a class="button button-cancel" href="javascript:history.back();">'.$langs->trans('Cancel').'</a>';
print '</div>';

print '</form>';
print '</div>';

llxFooter();
$db->close();
