<?php
/**
 * Dev-only setup for testing the camt053readerandlink module.
 * Run inside the web container:  php /tmp/camt_dev_setup.php
 *  - enables the module (with its modBanque dependency)
 *  - creates a EUR bank account whose IBAN matches the test fixture
 *  - seeds 3 unreconciled bank lines matching the fixture entries
 * Idempotent: safe to re-run.
 */

if (PHP_SAPI !== 'cli') {
	fwrite(STDERR, "CLI only\n");
	exit(1);
}

$rootPath = '/var/www/html';
chdir($rootPath);
$_SERVER['DOCUMENT_ROOT'] = $rootPath;
$_SERVER['SERVER_NAME'] = 'localhost';

require_once $rootPath . '/master.inc.php';
require_once DOL_DOCUMENT_ROOT . '/core/lib/admin.lib.php';
require_once DOL_DOCUMENT_ROOT . '/compta/bank/class/account.class.php';
require_once DOL_DOCUMENT_ROOT . '/user/class/user.class.php';

global $db, $conf, $langs, $user, $mysoc;

$langs->load('main');

// Admin user acting as author.
$user = new User($db);
if ($user->fetch(0, 'admin') <= 0) {
	fwrite(STDERR, "Cannot load admin user\n");
	exit(1);
}
$user->loadRights();

$IBAN = 'BE71096123456769';

echo "== 1. Enable module camt053readerandlink (with deps) ==\n";
if (isModEnabled('camt053readerandlink')) {
	echo "  already enabled\n";
} else {
	$res = activateModule('modCamt053ReaderAndLink', 1);
	if (!empty($res['errors'])) {
		fwrite(STDERR, "  activation errors: " . implode('; ', $res['errors']) . "\n");
		exit(1);
	}
	echo "  enabled (" . (int) $res['nbmodules'] . " module(s), " . (int) $res['nbperms'] . " perms)\n";
}
// Make sure the Bank dependency is on too.
if (!isModEnabled('banque')) {
	activateModule('modBanque', 1);
	echo "  enabled modBanque dependency\n";
}

echo "== 2. Bank account with IBAN $IBAN ==\n";
$accountId = 0;
$sql = "SELECT rowid FROM " . MAIN_DB_PREFIX . "bank_account WHERE iban_prefix IN ('$IBAN','" . chunk_split($IBAN, 4, ' ') . "') OR iban_prefix = '$IBAN'";
$resql = $db->query($sql);
if ($resql && ($obj = $db->fetch_object($resql))) {
	$accountId = (int) $obj->rowid;
	echo "  reusing existing account #$accountId\n";
} else {
	$acc = new Account($db);
	$acc->ref            = 'CAMT-TEST';
	$acc->label          = 'CAMT053 Test Account';
	$acc->type           = Account::TYPE_CURRENT; // courant
	$acc->courant        = Account::TYPE_CURRENT;
	$acc->currency_code  = 'EUR';
	$acc->country_code   = 'BE';
	$acc->country_id     = getCountry('BE', '3') ?: (!empty($mysoc->country_id) ? $mysoc->country_id : 0);
	$acc->iban           = $IBAN;
	$acc->bic            = 'GEBABEBB';
	$acc->date_solde     = dol_now();
	$acc->solde          = 0;
	$acc->status         = 0; // open
	$acc->clos           = 0;
	if ($acc->create($user) <= 0) {
		fwrite(STDERR, "  account create failed: " . $acc->error . "\n");
		exit(1);
	}
	$accountId = (int) $acc->id;
	echo "  created account #$accountId (ref CAMT-TEST)\n";
}

echo "== 3. Seed unreconciled bank lines ==\n";
$acc = new Account($db);
$acc->fetch($accountId);

$lines = array(
	array('datev' => '2024-01-15', 'amount' => 1500.00,  'label' => 'Invoice payment INV-2024-001'),
	array('datev' => '2024-01-20', 'amount' => -250.00,  'label' => 'Supplier payment SUP-2024-001'),
	array('datev' => '2024-01-25', 'amount' => 1000.00,  'label' => 'Wire transfer from client'),
);

foreach ($lines as $l) {
	$ts = strtotime($l['datev']);
	// Skip if an equivalent unreconciled line already exists (idempotency).
	$chk = "SELECT rowid FROM " . MAIN_DB_PREFIX . "bank WHERE fk_account = $accountId"
		. " AND amount = " . $l['amount']
		. " AND datev = '" . $db->idate($ts) . "'";
	$rq = $db->query($chk);
	if ($rq && $db->fetch_object($rq)) {
		echo "  exists: " . $l['label'] . " (" . $l['amount'] . ")\n";
		continue;
	}
	$lineId = $acc->addline($ts, 'VIR', $l['label'], $l['amount'], '', 0, $user, '', '', '', $ts);
	if ($lineId <= 0) {
		fwrite(STDERR, "  addline failed for " . $l['label'] . ": " . $acc->error . "\n");
		exit(1);
	}
	echo "  added #$lineId: " . $l['label'] . " (" . $l['amount'] . " on " . $l['datev'] . ")\n";
}

echo "== DONE ==\n";
echo "Account id=$accountId IBAN=$IBAN\n";
echo "Upload fixture at: Bank > CAMT.053 Link, date range 2024-01-01 .. 2024-01-31\n";
exit(0);
