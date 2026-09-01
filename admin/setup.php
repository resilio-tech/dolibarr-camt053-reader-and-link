<?php
/* Copyright (C) 2004-2017 Laurent Destailleur  <eldy@users.sourceforge.net>
 * Copyright (C) 2024 SuperAdmin
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

/**
 * \file    camt053readerandlink/admin/setup.php
 * \ingroup camt053readerandlink
 * \brief   Camt053ReaderAndLink setup page.
 */

if (!defined('CSRFCHECK_WITH_TOKEN')) {
	define('CSRFCHECK_WITH_TOKEN', '1');
}

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
if (!$res && file_exists("../../main.inc.php")) {
	$res = @include "../../main.inc.php";
}
if (!$res && file_exists("../../../main.inc.php")) {
	$res = @include "../../../main.inc.php";
}
if (!$res) {
	die("Include of main fails");
}

global $langs, $user;

// Libraries
require_once DOL_DOCUMENT_ROOT."/core/lib/admin.lib.php";
require_once '../lib/camt053readerandlink.lib.php';

// Translations
$langs->loadLangs(array("admin", "camt053readerandlink@camt053readerandlink"));

// Initialize hooks
$hookmanager->initHooks(array('camt053readerandlinksetup', 'globalsetup'));

// Access control
if (!$user->admin) {
	accessforbidden();
}

// Parameters
$action = GETPOST('action', 'aZ09');
$backtopage = GETPOST('backtopage', 'alpha');
$modulepart = GETPOST('modulepart', 'aZ09');

/*
 * Actions
 */

if ($action == 'update_fetch') {
	$enabled = GETPOST('sftp_fetch_enabled', 'int') ? '1' : '0';
	dolibarr_set_const($db, 'CAMT053_SFTP_FETCH_ENABLED', $enabled, 'chaine', 0, '', $conf->entity);
	setEventMessages($langs->trans("RecordSaved"), null, 'mesgs');
}

if ($action == 'update_zulip') {
	dolibarr_set_const($db, 'CAMT053_ZULIP_SITE', GETPOST('zulip_site', 'alphanohtml'), 'chaine', 0, '', $conf->entity);
	dolibarr_set_const($db, 'CAMT053_ZULIP_BOT_EMAIL', GETPOST('zulip_email', 'alphanohtml'), 'chaine', 0, '', $conf->entity);
	dolibarr_set_const($db, 'CAMT053_ZULIP_STREAM', GETPOST('zulip_stream', 'alphanohtml'), 'chaine', 0, '', $conf->entity);
	dolibarr_set_const($db, 'CAMT053_ZULIP_TOPIC', GETPOST('zulip_topic', 'alphanohtml'), 'chaine', 0, '', $conf->entity);
	$apikey = trim((string) GETPOST('zulip_apikey', 'none'));
	if ($apikey !== '') {
		dolibarr_set_const($db, 'CAMT053_ZULIP_BOT_APIKEY', dolEncrypt($apikey), 'chaine', 0, '', $conf->entity);
	}
	setEventMessages($langs->trans("RecordSaved"), null, 'mesgs');
}

include DOL_DOCUMENT_ROOT.'/core/actions_setmoduleoptions.inc.php';

/*
 * View
 */

$form = new Form($db);

$help_url = '';
$page_name = "Camt053ReaderAndLinkSetup";

llxHeader('', $langs->trans($page_name), $help_url, '', 0, 0, '', '', '', 'mod-camt053readerandlink page-admin');

// Subheader
$linkback = '<a href="'.($backtopage ? $backtopage : DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1').'">'.$langs->trans("BackToModuleList").'</a>';

print load_fiche_titre($langs->trans($page_name), $linkback, 'title_setup');

// Configuration header
$head = camt053readerandlinkAdminPrepareHead();
print dol_get_fiche_head($head, 'settings', $langs->trans($page_name), -1, "camt053readerandlink@camt053readerandlink");

// Setup page info
echo '<span class="opacitymedium">'.$langs->trans("Camt053ReaderAndLinkSetupPage").'</span><br><br>';

// Automatic SFTP fetch
print '<form method="POST" action="'.$_SERVER["PHP_SELF"].'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="update_fetch">';

print load_fiche_titre($langs->trans("Camt053FetchSetup"), '', '');
print '<span class="opacitymedium">'.$langs->trans("Camt053FetchSetupHelp").'</span><br><br>';

print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td class="titlefield">'.$langs->trans("Parameter").'</td><td>'.$langs->trans("Value").'</td></tr>';

print '<tr class="oddeven"><td>'.$langs->trans("Camt053FetchEnabled").'</td>';
print '<td>'.$form->selectyesno('sftp_fetch_enabled', camt053SftpFetchEnabled() ? 1 : 0, 1).'</td></tr>';

print '</table>';
print '<div class="center" style="margin-top:10px">';
print '<input type="submit" class="button" value="'.$langs->trans("Save").'">';
print '</div>';
print '</form>';

print '<br>';

// Zulip report configuration
print '<form method="POST" action="'.$_SERVER["PHP_SELF"].'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="update_zulip">';

print load_fiche_titre($langs->trans("Camt053ZulipSetup"), '', '');
print '<span class="opacitymedium">'.$langs->trans("Camt053ZulipSetupHelp").'</span><br><br>';

print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td class="titlefield">'.$langs->trans("Parameter").'</td><td>'.$langs->trans("Value").'</td></tr>';

print '<tr class="oddeven"><td>'.$langs->trans("Camt053ZulipSite").'</td>';
print '<td><input type="text" name="zulip_site" class="minwidth300" value="'.dol_escape_htmltag(getDolGlobalString('CAMT053_ZULIP_SITE')).'" placeholder="https://your-org.zulipchat.com"></td></tr>';

print '<tr class="oddeven"><td>'.$langs->trans("Camt053ZulipBotEmail").'</td>';
print '<td><input type="text" name="zulip_email" class="minwidth300" value="'.dol_escape_htmltag(getDolGlobalString('CAMT053_ZULIP_BOT_EMAIL')).'" placeholder="camt053-bot@your-org.zulipchat.com"></td></tr>';

$apikeyPlaceholder = getDolGlobalString('CAMT053_ZULIP_BOT_APIKEY') !== '' ? $langs->trans("Camt053SftpKeepCurrentSecret") : '';
print '<tr class="oddeven"><td>'.$langs->trans("Camt053ZulipApiKey").'</td>';
print '<td><input type="password" name="zulip_apikey" autocomplete="new-password" class="minwidth300" value="" placeholder="'.dol_escape_htmltag($apikeyPlaceholder).'"></td></tr>';

print '<tr class="oddeven"><td>'.$langs->trans("Camt053ZulipStream").'</td>';
print '<td><input type="text" name="zulip_stream" class="minwidth300" value="'.dol_escape_htmltag(getDolGlobalString('CAMT053_ZULIP_STREAM')).'"></td></tr>';

print '<tr class="oddeven"><td>'.$langs->trans("Camt053ZulipTopic").'</td>';
print '<td><input type="text" name="zulip_topic" class="minwidth300" value="'.dol_escape_htmltag(getDolGlobalString('CAMT053_ZULIP_TOPIC')).'" placeholder="CAMT.053"></td></tr>';

print '</table>';
print '<div class="center" style="margin-top:10px">';
print '<input type="submit" class="button" value="'.$langs->trans("Save").'">';
print '</div>';
print '</form>';

// Page end
print dol_get_fiche_end();

llxFooter();
$db->close();
