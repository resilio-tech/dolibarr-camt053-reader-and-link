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
 * \file    camt053readerandlink/admin/sftp_card.php
 * \ingroup camt053readerandlink
 * \brief   Create / edit an SFTP account (PostFinance MFTPF) config.
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

$langs->loadLangs(array("admin", "banks", "camt053readerandlink@camt053readerandlink"));

if (!$user->admin) {
	accessforbidden();
}

$action = GETPOST('action', 'aZ09');
$id = GETPOSTINT('id');
$listurl = dol_buildpath('/camt053readerandlink/admin/sftp_list.php', 1);

$object = new Camt053SftpConfig($db);
if ($id > 0) {
	$r = $object->fetch($id);
	if ($r <= 0) {
		setEventMessages($langs->trans("RecordNotFound"), null, 'errors');
		header("Location: ".$listurl);
		exit;
	}
}

/*
 * Actions
 */

if (($action == 'add' || $action == 'update') && $user->admin) {
	if (GETPOST('cancel', 'alpha')) {
		header("Location: ".$listurl);
		exit;
	}

	if (!camt053VerifCsrfToken()) {
		setEventMessages($langs->trans("SecurityTokenError"), null, 'errors');
	} else {
		// On edit, keep the existing secrets when the corresponding field is left empty.
		$keepKey = ($action == 'update') ? $object->private_key : null;
		$keepPass = ($action == 'update') ? $object->private_key_passphrase : null;
		$keepPwd = ($action == 'update') ? $object->password : null;

		$object->ref = GETPOST('ref', 'alphanohtml');
		$object->label = GETPOST('label', 'alphanohtml');
		$object->active = GETPOSTINT('active');
		$object->host = GETPOST('host', 'alphanohtml');
		$object->port = GETPOSTINT('port') ? GETPOSTINT('port') : 8022;
		$object->username = GETPOST('username', 'alphanohtml');
		$object->auth_type = (GETPOST('auth_type', 'alpha') == 'password') ? 'password' : 'key';

		// Secrets are read raw ('none'): any HTML-oriented filter (restricthtml,
		// alphanohtml) would alter characters like & < " and corrupt the stored
		// credential. They are escaped at the SQL boundary and never echoed raw.
		$postedKey = trim((string) GETPOST('private_key', 'none'));
		$object->private_key = ($postedKey !== '') ? $postedKey : $keepKey;

		$postedPass = (string) GETPOST('private_key_passphrase', 'none');
		$object->private_key_passphrase = ($postedPass !== '') ? $postedPass : $keepPass;

		$postedPwd = (string) GETPOST('password', 'none');
		$object->password = ($postedPwd !== '') ? $postedPwd : $keepPwd;

		$object->remote_dir = GETPOST('remote_dir', 'alphanohtml') ? GETPOST('remote_dir', 'alphanohtml') : 'yellow-net-reports';
		// Patterns are PCRE: keep regex metacharacters (alphanohtml would strip them).
		$object->daily_pattern = GETPOST('daily_pattern', 'restricthtml');
		$object->monthly_pattern = GETPOST('monthly_pattern', 'restricthtml');
		$object->post_download_action = (GETPOST('post_download_action', 'alpha') == 'leave') ? 'leave' : 'delete';
		$object->fk_default_bank_account = GETPOSTINT('fk_default_bank_account') ? GETPOSTINT('fk_default_bank_account') : null;

		if ($action == 'add') {
			$result = $object->create($user);
		} else {
			$result = $object->update($user);
		}

		if ($result > 0) {
			setEventMessages($langs->trans("RecordSaved"), null, 'mesgs');
			header("Location: ".$listurl);
			exit;
		} else {
			setEventMessages($object->getError(), null, 'errors');
			$action = ($action == 'add') ? 'create' : 'edit';
		}
	}
}

/*
 * View
 */

$form = new Form($db);
$help_url = '';
$title = ($id > 0) ? $langs->trans("Camt053SftpAccountEdit") : $langs->trans("Camt053SftpAccountNew");

llxHeader('', $title, $help_url, '', 0, 0, '', '', '', 'mod-camt053readerandlink page-admin');

$linkback = '<a href="'.$listurl.'">'.$langs->trans("BackToList").'</a>';
print load_fiche_titre($title, $linkback, 'title_setup');

$head = camt053readerandlinkAdminPrepareHead();
print dol_get_fiche_head($head, 'sftp', $langs->trans("Camt053SftpAccounts"), -1, "camt053readerandlink@camt053readerandlink");

print '<form method="POST" action="'.$_SERVER["PHP_SELF"].'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="'.($id > 0 ? 'update' : 'add').'">';
if ($id > 0) {
	print '<input type="hidden" name="id" value="'.$id.'">';
}

print '<table class="border centpercent tableforfieldcreate">';

// Ref
print '<tr><td class="titlefieldcreate fieldrequired">'.$langs->trans("Ref").'</td>';
print '<td><input type="text" name="ref" class="minwidth300" value="'.dol_escape_htmltag($object->ref).'" autofocus></td></tr>';

// Label
print '<tr><td>'.$langs->trans("Label").'</td>';
print '<td><input type="text" name="label" class="minwidth300" value="'.dol_escape_htmltag($object->label).'"></td></tr>';

// Active
print '<tr><td>'.$langs->trans("Enabled").'</td>';
print '<td>'.$form->selectyesno('active', (string) $object->active, 1).'</td></tr>';

// Host
print '<tr><td class="fieldrequired">'.$langs->trans("Camt053SftpHost").'</td>';
$hostval = $object->host ? $object->host : 'mftp1.postfinance.ch';
print '<td><input type="text" name="host" class="minwidth300" value="'.dol_escape_htmltag($hostval).'"></td></tr>';

// Port
print '<tr><td class="fieldrequired">'.$langs->trans("Camt053SftpPort").'</td>';
print '<td><input type="number" name="port" class="width75" value="'.((int) $object->port).'"></td></tr>';

// Username
print '<tr><td class="fieldrequired">'.$langs->trans("Camt053SftpUsername").'</td>';
print '<td><input type="text" name="username" class="minwidth300" value="'.dol_escape_htmltag($object->username).'"> <span class="opacitymedium">'.$langs->trans("Camt053SftpUsernameHelp").'</span></td></tr>';

// Auth type
print '<tr><td class="fieldrequired">'.$langs->trans("Camt053SftpAuthType").'</td>';
print '<td>'.$form->selectarray('auth_type', array('key' => $langs->trans("Camt053SftpAuthKey"), 'password' => $langs->trans("Camt053SftpAuthPassword")), $object->auth_type, 0, 0, 0, '', 0, 0, 0, '', 'minwidth200').'</td></tr>';

// Private key
$keyPlaceholder = ($id > 0 && !empty($object->private_key)) ? $langs->trans("Camt053SftpKeepCurrentSecret") : '-----BEGIN OPENSSH PRIVATE KEY----- ...';
print '<tr><td>'.$langs->trans("Camt053SftpPrivateKey").'</td>';
print '<td><textarea name="private_key" class="quatrevingtpercent" rows="8" placeholder="'.dol_escape_htmltag($keyPlaceholder).'"></textarea>';
print '<br><span class="opacitymedium">'.$langs->trans("Camt053SftpPrivateKeyHelp").'</span></td></tr>';

// Private key passphrase
$passPlaceholder = ($id > 0 && !empty($object->private_key_passphrase)) ? $langs->trans("Camt053SftpKeepCurrentSecret") : '';
print '<tr><td>'.$langs->trans("Camt053SftpPassphrase").'</td>';
print '<td><input type="password" name="private_key_passphrase" autocomplete="new-password" class="minwidth300" value="" placeholder="'.dol_escape_htmltag($passPlaceholder).'"></td></tr>';

// Password (for non-key auth)
$pwdPlaceholder = ($id > 0 && !empty($object->password)) ? $langs->trans("Camt053SftpKeepCurrentSecret") : '';
print '<tr><td>'.$langs->trans("Password").'</td>';
print '<td><input type="password" name="password" autocomplete="new-password" class="minwidth300" value="" placeholder="'.dol_escape_htmltag($pwdPlaceholder).'"></td></tr>';

// Remote dir
print '<tr><td class="fieldrequired">'.$langs->trans("Camt053SftpRemoteDir").'</td>';
$dirval = $object->remote_dir ? $object->remote_dir : 'yellow-net-reports';
print '<td><input type="text" name="remote_dir" class="minwidth300" value="'.dol_escape_htmltag($dirval).'"> <span class="opacitymedium">'.$langs->trans("Camt053SftpRemoteDirHelp").'</span></td></tr>';

// Daily pattern
print '<tr><td>'.$langs->trans("Camt053SftpDailyPattern").'</td>';
print '<td><input type="text" name="daily_pattern" class="minwidth300" value="'.dol_escape_htmltag($object->daily_pattern).'"> <span class="opacitymedium">'.$langs->trans("Camt053SftpPatternHelp").'</span></td></tr>';

// Monthly pattern
print '<tr><td>'.$langs->trans("Camt053SftpMonthlyPattern").'</td>';
print '<td><input type="text" name="monthly_pattern" class="minwidth300" value="'.dol_escape_htmltag($object->monthly_pattern).'"> <span class="opacitymedium">'.$langs->trans("Camt053SftpMonthlyPatternHelp").'</span></td></tr>';

// Post download action
print '<tr><td>'.$langs->trans("Camt053SftpPostAction").'</td>';
print '<td>'.$form->selectarray('post_download_action', array('delete' => $langs->trans("Camt053SftpPostDelete"), 'leave' => $langs->trans("Camt053SftpPostLeave")), $object->post_download_action, 0, 0, 0, '', 0, 0, 0, '', 'minwidth200').'</td></tr>';

// Default bank account
print '<tr><td>'.$langs->trans("Camt053SftpDefaultAccount").'</td>';
print '<td>'.$form->select_comptes((int) $object->fk_default_bank_account, 'fk_default_bank_account', 0, '', 1, '', 0, '', 1).' <span class="opacitymedium">'.$langs->trans("Camt053SftpDefaultAccountHelp").'</span></td></tr>';

print '</table>';

print dol_get_fiche_end();

print $form->buttonsSaveCancel($id > 0 ? "Save" : "Add", "Cancel");

print '</form>';

llxFooter();
$db->close();
