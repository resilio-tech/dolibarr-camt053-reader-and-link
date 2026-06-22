<?php
/* Copyright (C) 2026 Resilio SA
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
 * \file    camt053readerandlink/admin/sftp_list.php
 * \ingroup camt053readerandlink
 * \brief   List of SFTP accounts (PostFinance MFTPF) configs.
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
if (!$res && file_exists("../../main.inc.php")) {
	$res = @include "../../main.inc.php";
}
if (!$res && file_exists("../../../main.inc.php")) {
	$res = @include "../../../main.inc.php";
}
if (!$res) {
	die("Include of main fails");
}

global $langs, $user, $db;

require_once DOL_DOCUMENT_ROOT."/core/lib/admin.lib.php";
require_once __DIR__.'/../lib/camt053readerandlink.lib.php';
require_once __DIR__.'/../class/Camt053SftpConfig.class.php';

$langs->loadLangs(array("admin", "camt053readerandlink@camt053readerandlink"));

if (!$user->admin) {
	accessforbidden();
}

$action = GETPOST('action', 'aZ09');
$id = GETPOSTINT('id');
$cardurl = dol_buildpath('/camt053readerandlink/admin/sftp_card.php', 1);

/*
 * Actions
 */

if ($action == 'confirm_delete' && $id > 0 && $user->admin) {
	if (!verifCsrfToken()) {
		setEventMessages($langs->trans("SecurityTokenError"), null, 'errors');
	} else {
		$object = new Camt053SftpConfig($db);
		if ($object->fetch($id) > 0) {
			if ($object->delete($user) > 0) {
				setEventMessages($langs->trans("RecordDeleted"), null, 'mesgs');
			} else {
				setEventMessages($object->getError(), null, 'errors');
			}
		}
	}
	header("Location: ".$_SERVER["PHP_SELF"]);
	exit;
}

/*
 * View
 */

$form = new Form($db);
$help_url = '';
$page_name = "Camt053SftpAccounts";

llxHeader('', $langs->trans($page_name), $help_url, '', 0, 0, '', '', '', 'mod-camt053readerandlink page-admin');

$linkback = '<a href="'.DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1">'.$langs->trans("BackToModuleList").'</a>';
print load_fiche_titre($langs->trans("Camt053ReaderAndLinkSetup"), $linkback, 'title_setup');

$head = camt053readerandlinkAdminPrepareHead();
print dol_get_fiche_head($head, 'sftp', $langs->trans($page_name), -1, "camt053readerandlink@camt053readerandlink");

print '<span class="opacitymedium">'.$langs->trans("Camt053SftpAccountsHelp").'</span><br><br>';

// Delete confirmation
if ($action == 'delete' && $id > 0) {
	print $form->formconfirm($_SERVER["PHP_SELF"].'?id='.$id, $langs->trans("Camt053SftpDeleteTitle"), $langs->trans("Camt053SftpDeleteConfirm"), 'confirm_delete', '', 0, 1);
}

// New button
print '<div class="tabsAction">';
print '<a class="butAction" href="'.$cardurl.'?action=create">'.$langs->trans("Camt053SftpAccountNew").'</a>';
print '</div>';

$configLoader = new Camt053SftpConfig($db);
$list = $configLoader->fetchAll(false);

print '<div class="div-table-responsive">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre">';
print '<th>'.$langs->trans("Ref").'</th>';
print '<th>'.$langs->trans("Label").'</th>';
print '<th>'.$langs->trans("Camt053SftpHost").'</th>';
print '<th class="center">'.$langs->trans("Camt053SftpPort").'</th>';
print '<th>'.$langs->trans("Camt053SftpUsername").'</th>';
print '<th>'.$langs->trans("Camt053SftpAuthType").'</th>';
print '<th>'.$langs->trans("Camt053SftpRemoteDir").'</th>';
print '<th class="center">'.$langs->trans("Enabled").'</th>';
print '<th>'.$langs->trans("Camt053SftpLastRun").'</th>';
print '<th class="right"></th>';
print '</tr>';

if (empty($list)) {
	print '<tr class="oddeven"><td colspan="10" class="opacitymedium center">'.$langs->trans("Camt053SftpNoAccount").'</td></tr>';
} else {
	foreach ($list as $cfg) {
		$editlink = $cardurl.'?action=edit&id='.$cfg->id;
		print '<tr class="oddeven">';
		print '<td><a href="'.$editlink.'">'.dol_escape_htmltag($cfg->ref).'</a></td>';
		print '<td>'.dol_escape_htmltag($cfg->label).'</td>';
		print '<td>'.dol_escape_htmltag($cfg->host).'</td>';
		print '<td class="center">'.((int) $cfg->port).'</td>';
		print '<td>'.dol_escape_htmltag($cfg->username).'</td>';
		print '<td>'.dol_escape_htmltag($cfg->auth_type).'</td>';
		print '<td>'.dol_escape_htmltag($cfg->remote_dir).'</td>';
		print '<td class="center">'.($cfg->active ? $langs->trans("Yes") : $langs->trans("No")).'</td>';
		$lastrun = $cfg->last_run ? dol_print_date($cfg->last_run, 'dayhour') : '<span class="opacitymedium">-</span>';
		print '<td>'.$lastrun.'</td>';
		print '<td class="right nowraponall">';
		print '<a class="editfielda" href="'.$editlink.'">'.img_edit().'</a>';
		print ' <a class="reposition" href="'.$_SERVER["PHP_SELF"].'?action=delete&id='.$cfg->id.'&token='.newToken().'">'.img_delete().'</a>';
		print '</td>';
		print '</tr>';
	}
}

print '</table>';
print '</div>';

print dol_get_fiche_end();

llxFooter();
$db->close();
