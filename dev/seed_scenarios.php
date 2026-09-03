<?php
/**
 * Dev-only: seed bank lines that exercise every CAMT.053 match outcome
 * against dev/camt053_scenarios.xml.
 * Idempotent: safe to re-run.
 */
if (PHP_SAPI !== 'cli') { fwrite(STDERR, "CLI only\n"); exit(1); }

$rootPath = '/var/www/html';
chdir($rootPath);
$_SERVER['DOCUMENT_ROOT'] = $rootPath;
$_SERVER['SERVER_NAME'] = 'localhost';

require_once $rootPath . '/master.inc.php';
require_once DOL_DOCUMENT_ROOT . '/compta/bank/class/account.class.php';
require_once DOL_DOCUMENT_ROOT . '/user/class/user.class.php';

global $db, $user;

$user = new User($db);
$user->fetch(0, 'admin');
$user->loadRights();

$IBAN = 'BE71096123456769';

// Locate the test account.
$sql = "SELECT rowid FROM " . MAIN_DB_PREFIX . "bank_account WHERE iban_prefix = '$IBAN' OR iban_prefix = '" . trim(chunk_split($IBAN, 4, ' ')) . "'";
$resql = $db->query($sql);
$obj = $resql ? $db->fetch_object($resql) : null;
if (!$obj) { fwrite(STDERR, "Account with IBAN $IBAN not found. Run camt_dev_setup.php first.\n"); exit(1); }
$accountId = (int) $obj->rowid;
echo "Account #$accountId ($IBAN)\n";

$acc = new Account($db);
$acc->fetch($accountId);

// label => [datev, amount, reconcile?]
$lines = array(
	'SCEN linked 1500'   => array('2024-02-01', 1500.00, false),
	'SCEN multi-A 800'   => array('2024-02-05',  800.00, false),
	'SCEN multi-B 800'   => array('2024-02-06',  800.00, false),
	'SCEN already 999'   => array('2024-02-15',  999.00, true),
	'SCEN linked -125'   => array('2024-02-20', -125.00, false),
);

foreach ($lines as $label => $def) {
	list($datev, $amount, $reconcile) = $def;
	$ts = strtotime($datev);

	// Idempotency: match on exact label.
	$chk = $db->query("SELECT rowid, rappro FROM " . MAIN_DB_PREFIX . "bank WHERE fk_account = $accountId AND label = '" . $db->escape($label) . "'");
	$existing = $chk ? $db->fetch_object($chk) : null;
	if ($existing) {
		echo "  exists: $label (#{$existing->rowid})\n";
		$lineId = (int) $existing->rowid;
	} else {
		$lineId = $acc->addline($ts, 'VIR', $label, $amount, '', 0, $user, '', '', '', $ts);
		if ($lineId <= 0) { fwrite(STDERR, "  addline failed for $label: " . $acc->error . "\n"); exit(1); }
		echo "  added #$lineId: $label ($amount on $datev)\n";
	}

	// Mark the "already reconciled" case.
	if ($reconcile) {
		$db->query("UPDATE " . MAIN_DB_PREFIX . "bank SET rappro = 1, num_releve = 'REL-2024-02-01' WHERE rowid = $lineId");
		echo "    -> flagged reconciled (num_releve = REL-2024-02-01)\n";
	}
}

echo "DONE. Upload dev/camt053_scenarios.xml with range 2024-02-01 .. 2024-02-29\n";
exit(0);
