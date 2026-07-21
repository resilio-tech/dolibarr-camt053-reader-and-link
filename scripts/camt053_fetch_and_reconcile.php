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
 * \file    camt053readerandlink/scripts/camt053_fetch_and_reconcile.php
 * \ingroup camt053readerandlink
 * \brief   CLI entry point to fetch CAMT.053 files over SFTP and reconcile them.
 *
 * Usage: php camt053_fetch_and_reconcile.php [user_login]
 *        If no login is given, the first active admin user is used.
 */

if (PHP_SAPI !== 'cli') {
	echo "This script must be run from the command line.\n";
	exit(1);
}

// Bootstrap Dolibarr (master.inc.php expects htdocs as the working directory).
$rootPath = realpath(__DIR__ . '/../../../');
if ($rootPath === false || !is_file($rootPath . '/master.inc.php')) {
	echo "Cannot locate Dolibarr master.inc.php from " . __DIR__ . "\n";
	exit(1);
}
chdir($rootPath);

require_once $rootPath . '/master.inc.php';
require_once DOL_DOCUMENT_ROOT . '/user/class/user.class.php';
require_once DOL_DOCUMENT_ROOT . '/custom/camt053readerandlink/class/Camt053CronRunner.class.php';

/** @var DoliDB $db */
/** @var Translate $langs */

if (!isModEnabled('camt053readerandlink')) {
	echo "Module camt053readerandlink is not enabled.\n";
	exit(1);
}

// Resolve the user that will own the reconciliation.
$login = isset($argv[1]) ? $argv[1] : '';
$user = new User($db);
if ($login !== '') {
	$user->fetch(0, $login);
} else {
	// Scoped to the entity this run targets: the picked user becomes the author
	// of every reconciliation, so it must not come from another company.
	$sql = "SELECT rowid FROM " . MAIN_DB_PREFIX . "user WHERE admin = 1 AND statut = 1";
	$sql .= " AND entity IN (" . getEntity('user') . ")";
	$sql .= " ORDER BY rowid ASC";
	$resql = $db->query($sql);
	if ($resql && ($obj = $db->fetch_object($resql))) {
		$user->fetch($obj->rowid);
	}
}

if (empty($user->id)) {
	echo "No usable user found (pass a login as first argument).\n";
	exit(1);
}
$user->loadRights();

$langs->loadLangs(array("camt053readerandlink@camt053readerandlink"));

$runner = new Camt053CronRunner($db);
$result = $runner->run();

if (!empty($runner->output)) {
	echo $runner->output . "\n";
}
if (!empty($runner->error)) {
	fwrite(STDERR, "Errors: " . $runner->error . "\n");
}

exit($result < 0 ? 1 : 0);
