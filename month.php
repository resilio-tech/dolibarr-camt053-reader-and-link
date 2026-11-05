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
 *	\file       camt053readerandlink/month.php
 *	\ingroup    camt053readerandlink
 *	\brief      Reconciliation screen of one bank account over one month, built
 *	            from every statement archived for that month. The monthly Zulip
 *	            report links to it.
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

require_once DOL_DOCUMENT_ROOT.'/compta/bank/class/account.class.php';

require_once __DIR__ . '/class/Camt053ArchivedMonth.class.php';
require_once __DIR__ . '/class/DatabaseBankStatementLoader.class.php';
require_once __DIR__ . '/class/BankStatementMatcher.class.php';
require_once __DIR__ . '/lib/camt053readerandlink.results.lib.php';

$langs->loadLangs(array(
	"camt053readerandlink@camt053readerandlink",
	"banks",
	"bills",
	"companies",
	"salaries",
	"compta"
));

if (!isModEnabled('camt053readerandlink')) {
	accessforbidden('Module not enabled');
}
if (!$user->hasRight('banque', 'lire')) {
	accessforbidden();
}

$accountId = GETPOSTINT('account');
$month = GETPOST('month', 'aZ09');

$banks = array();
$processError = null;
$date_start = '';
$date_end = '';

$firstDay = preg_match('/^\d{6}$/', $month) ? DateTime::createFromFormat('!Ymd', $month . '01') : false;

if ($accountId <= 0 || $firstDay === false) {
	$processError = $langs->trans('Camt053MonthNotFound');
} else {
	$date_start = $firstDay->format('d/m/Y');
	$date_end = $firstDay->format('t/m/Y');

	$fileStatement = Camt053ArchivedMonth::merge(Camt053ArchivedMonth::load($db, $accountId, $month), $accountId);

	$dbLoader = new DatabaseBankStatementLoader($db, $langs);
	$matcher = new BankStatementMatcher(1);

	$dbStatements = $dbLoader->loadStatements($date_start, $date_end, $accountId, $matcher->getDateTolerance());
	if ($dbLoader->getError()) {
		$processError = $dbLoader->getError();
	} else {
		$banks = $matcher->compareMultiple(array($accountId => $fileStatement), $dbStatements, $dbLoader);
	}
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

llxHeader("", $langs->trans("Camt053MonthReview"), '', '', 0, 0, '', '', '', 'mod-camt053readerandlink page-month');

print $moreCss;

$linkback = '<a href="'.dol_buildpath('/camt053readerandlink/index.php', 1).'">'.$langs->trans("BackToList").'</a>';
$title = $firstDay !== false
	? $langs->trans("Camt053MonthReviewOf", $firstDay->format('m/Y'))
	: $langs->trans("Camt053MonthReview");
print load_fiche_titre($title, $linkback, 'bank_account');

print '<div class="fichecenter camt053readerandlink">';

if (!empty($processError)) {
	setEventMessages($processError, null, 'errors');
} elseif (empty($banks)) {
	print '<div class="opacitymedium">'.$langs->trans('NoEntriesToReconcile').'</div>';
} else {
	camt053_render_results($banks, array(
		'date_start' => $date_start,
		'date_end' => $date_end,
		'bank_account_id' => $accountId,
		'structure' => null,
		'upload_file' => '',
		'actionable_first' => true,
	));
}

print '</div>';

llxFooter();
$db->close();
