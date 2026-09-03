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
 *	\file       camt053readerandlink/align_bank_line.php
 *	\ingroup    camt053readerandlink
 *	\brief      Align a bank line date on the statement and reconcile it
 */

if (!defined('CSRFCHECK_WITH_TOKEN')) {
	define('CSRFCHECK_WITH_TOKEN', '1');
}

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

// Load translation files required by the page
$langs->loadLangs(array("camt053readerandlink@camt053readerandlink"));

// Security check
if (!isModEnabled('camt053readerandlink')) {
	accessforbidden('Module not enabled');
}
// Moving a date and writing num_releve is a reconciliation, gated on the right
// Dolibarr core requires for the same operation.
if (!$user->hasRight('banque', 'consolidate')) {
	accessforbidden();
}

$lineId = GETPOSTINT('line_id');
$statementDate = GETPOST('date', 'alpha');
$numReleve = GETPOST('num_releve', 'alphanohtml');
$accountId = GETPOSTINT('account_id');

$back = DOL_URL_ROOT . '/compta/bank/releve.php?account=' . $accountId . '&num=' . urlencode($numReleve);

$date = DateTime::createFromFormat('Y-m-d', (string) $statementDate);
if ($lineId <= 0 || $date === false || $numReleve === '') {
	setEventMessages($langs->trans('Camt053AlignFailed'), null, 'errors');
	header('Location: ' . $back);
	exit;
}

$line = new AccountLine($db);
if ($line->fetch($lineId) <= 0) {
	setEventMessages($langs->trans('Camt053AlignFailed'), null, 'errors');
	header('Location: ' . $back);
	exit;
}

// SPEC section 2. The line is reached by its id, so the entity of the account it
// belongs to is what has to be checked, and never widened.
$sql = "SELECT rowid FROM " . MAIN_DB_PREFIX . "bank_account";
$sql .= " WHERE rowid = " . ((int) $line->fk_account);
$sql .= " AND entity IN (" . getEntity('bank_account', 0) . ")";
$resql = $db->query($sql);
if (!$resql || !$db->fetch_object($resql)) {
	dol_syslog('CAMT053: Refused to align bank line ' . $lineId . ', account outside the current entity', LOG_WARNING);
	accessforbidden();
}

if (!empty($line->rappro)) {
	setEventMessages($langs->trans('Camt053LineAlreadyReconciled', (string) $line->num_releve), null, 'warnings');
	header('Location: ' . $back);
	exit;
}

$db->begin();

// Dolibarr writes these two dates directly (compta/bank/line.php), and only on a
// line that is not reconciled yet, which is what the guard above checks. The
// payment date is deliberately left alone: it is an accounting date, and the
// bank line dates are what the reconciliation compares.
$stamp = dol_mktime(12, 0, 0, (int) $date->format('n'), (int) $date->format('j'), (int) $date->format('Y'));
$sql = "UPDATE " . MAIN_DB_PREFIX . "bank";
$sql .= " SET dateo = '" . $db->idate($stamp) . "', datev = '" . $db->idate($stamp) . "'";
$sql .= " WHERE rowid = " . ((int) $lineId);

if (!$db->query($sql)) {
	$db->rollback();
	dol_syslog('CAMT053: Failed to align bank line ' . $lineId . ' - ' . $db->lasterror(), LOG_ERR);
	setEventMessages($langs->trans('Camt053AlignFailed'), null, 'errors');
	header('Location: ' . $back);
	exit;
}

$line->num_releve = $numReleve;
if ($line->update_conciliation($user, 0, 1) <= 0) {
	$db->rollback();
	$errmsg = !empty($line->error) ? $line->error : implode(', ', $line->errors);
	dol_syslog('CAMT053: Failed to reconcile aligned bank line ' . $lineId . ' - ' . $errmsg, LOG_ERR);
	setEventMessages($langs->trans('ReconciliationFailed') . ' #' . $lineId, null, 'errors');
	header('Location: ' . $back);
	exit;
}

$db->commit();

dol_syslog('CAMT053: Bank line ' . $lineId . ' aligned on ' . $date->format('Y-m-d') . ' and reconciled with ' . $numReleve, LOG_INFO);
setEventMessages($langs->trans('Camt053DateAligned', $date->format('d/m/Y')), null, 'mesgs');

header('Location: ' . $back);
$db->close();
exit;
