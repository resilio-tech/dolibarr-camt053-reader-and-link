<?php
/** Dev-only: create the PostFinance test bank account and the bank lines matching the generated CAMT.053. */
if (PHP_SAPI !== 'cli') { fwrite(STDERR, "CLI only\n"); exit(1); }

$rootPath = '/var/www/html';
chdir($rootPath);
$_SERVER['DOCUMENT_ROOT'] = $rootPath;
$_SERVER['SERVER_NAME'] = 'localhost';

require_once $rootPath . '/master.inc.php';
require_once DOL_DOCUMENT_ROOT . '/user/class/user.class.php';
require_once DOL_DOCUMENT_ROOT . '/compta/bank/class/account.class.php';

global $db, $user, $conf;

$IBAN = getenv('PF_IBAN') ?: 'CH3089144455991389966';
$CCY = getenv('PF_CCY') ?: 'CHF';
$DATE = getenv('PF_DATE') ?: date('Y-m-d');
$REF = 'PF-TEST';

$user = new User($db);
$user->fetch(0, 'admin');
$user->loadRights();

$sql = "SELECT rowid FROM " . MAIN_DB_PREFIX . "bank_account WHERE iban_prefix = '" . $db->escape($IBAN) . "'";
$res = $db->query($sql);
$obj = $res ? $db->fetch_object($res) : null;

if ($obj) {
	$accountId = (int) $obj->rowid;
	echo "account already there: #$accountId ($IBAN)\n";
} else {
	$account = new Account($db);
	$account->ref = $REF;
	$account->label = 'PostFinance test account';
	$account->courant = Account::TYPE_CURRENT;
	$account->type = Account::TYPE_CURRENT;
	$account->currency_code = $CCY;
	$account->country_id = getCountry('CH', 3);
	$account->iban = $IBAN;
	$account->bic = 'POFICHBEXXX';
	$account->date_solde = dol_now();
	$account->solde = 0;
	$account->clos = 0;
	$account->status = 0;

	$accountId = $account->create($user);
	if ($accountId <= 0) {
		fwrite(STDERR, "create failed: " . $account->error . ' ' . implode(',', $account->errors) . "\n");
		exit(1);
	}
	echo "created account #$accountId (ref $REF, $IBAN, $CCY)\n";
}

$account = new Account($db);
$account->fetch($accountId);

$lines = array(
	array('amount' => 1500.00, 'label' => 'Invoice INV-2024-001', 'type' => 'VIR'),
	array('amount' => -275.00, 'label' => 'Supplier invoice SI-2024-042', 'type' => 'VIR'),
);

foreach ($lines as $l) {
	$sql = "SELECT rowid FROM " . MAIN_DB_PREFIX . "bank"
		. " WHERE fk_account = " . (int) $accountId
		. " AND label = '" . $db->escape($l['label']) . "'";
	$res = $db->query($sql);
	if ($res && $db->fetch_object($res)) {
		echo "  line already there: " . $l['label'] . "\n";
		continue;
	}

	$id = $account->addline(
		(int) strtotime($DATE . ' 12:00:00'),
		$l['type'],
		$l['label'],
		$l['amount'],
		'',
		0,
		$user
	);
	if ($id <= 0) {
		fwrite(STDERR, "  addline failed: " . $account->error . "\n");
		continue;
	}
	echo "  added line #$id: " . $l['label'] . " (" . $l['amount'] . " on " . $DATE . ")\n";
}

echo "DONE. Account #$accountId, generate the file with --iban=$IBAN --ccy=$CCY --date=$DATE\n";
