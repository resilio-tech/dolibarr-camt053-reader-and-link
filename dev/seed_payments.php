<?php
/**
 * Dev-only: seed the data that turns the "unlinked" CAMT entries into actionable
 * suggestions (create customer/supplier payment, internal transfer).
 *  - enables the invoicing + supplier modules
 *  - creates a customer and a supplier third party
 *  - creates validated, unpaid EUR invoices matching the scenario amounts
 *  - creates a second bank account for the internal-transfer case
 * Idempotent: keyed on note_private / account ref / third-party name.
 */
if (PHP_SAPI !== 'cli') { fwrite(STDERR, "CLI only\n"); exit(1); }

$rootPath = '/var/www/html';
chdir($rootPath);
$_SERVER['DOCUMENT_ROOT'] = $rootPath;
$_SERVER['SERVER_NAME'] = 'localhost';

require_once $rootPath . '/master.inc.php';
require_once DOL_DOCUMENT_ROOT . '/core/lib/admin.lib.php';
require_once DOL_DOCUMENT_ROOT . '/user/class/user.class.php';
require_once DOL_DOCUMENT_ROOT . '/societe/class/societe.class.php';
require_once DOL_DOCUMENT_ROOT . '/compta/facture/class/facture.class.php';
require_once DOL_DOCUMENT_ROOT . '/fourn/class/fournisseur.facture.class.php';
require_once DOL_DOCUMENT_ROOT . '/compta/bank/class/account.class.php';

global $db, $conf, $langs, $user, $mysoc;

$user = new User($db);
$user->fetch(0, 'admin');
$user->loadRights();

// Company object needed by invoice creation.
$mysoc = new Societe($db);
$mysoc->setMysoc($conf);

$entity = (int) $conf->entity;

echo "== 1. Enable invoicing + supplier modules ==\n";
foreach (array('modFacture' => 'facture', 'modFournisseur' => 'fournisseur') as $modClass => $modKey) {
	if (isModEnabled($modKey)) {
		echo "  $modKey already enabled\n";
		continue;
	}
	$res = activateModule($modClass, 1);
	if (!empty($res['errors'])) { fwrite(STDERR, "  $modClass errors: " . implode('; ', $res['errors']) . "\n"); exit(1); }
	echo "  enabled $modClass\n";
}

/** Find or create a third party. */
function ensureSociete($db, $user, $name, $isCustomer, $isSupplier)
{
	$sql = "SELECT rowid FROM " . MAIN_DB_PREFIX . "societe WHERE nom = '" . $db->escape($name) . "'";
	$r = $db->query($sql);
	if ($r && ($o = $db->fetch_object($r))) { return (int) $o->rowid; }
	$soc = new Societe($db);
	$soc->name = $name;
	$soc->client = $isCustomer ? 1 : 0;
	$soc->fournisseur = $isSupplier ? 1 : 0;
	$soc->code_client = $isCustomer ? -1 : '';
	$soc->code_fournisseur = $isSupplier ? -1 : '';
	$soc->country_id = getCountry('BE', '3') ?: 0;
	if ($soc->create($user) <= 0) { fwrite(STDERR, "  societe create failed: " . $soc->error . "\n"); exit(1); }
	return (int) $soc->id;
}

/** Create a validated, unpaid customer invoice. Returns ref, or existing one. */
function ensureCustomerInvoice($db, $user, $socid, $amount, $marker)
{
	$r = $db->query("SELECT ref FROM " . MAIN_DB_PREFIX . "facture WHERE note_private = '" . $db->escape($marker) . "'");
	if ($r && ($o = $db->fetch_object($r))) { return $o->ref . ' (exists)'; }
	$inv = new Facture($db);
	$inv->socid = $socid;
	$inv->type = Facture::TYPE_STANDARD;
	$inv->date = dol_now();
	$inv->cond_reglement_id = 1;
	$inv->mode_reglement_id = 0;
	$inv->note_private = $marker;
	if ($inv->create($user) <= 0) { fwrite(STDERR, "  invoice create failed: " . $inv->error . "\n"); exit(1); }
	if ($inv->addline('CAMT test line', $amount, 1, 0) <= 0) { fwrite(STDERR, "  addline failed: " . $inv->error . "\n"); exit(1); }
	$inv->fetch($inv->id);
	if ($inv->validate($user) <= 0) { fwrite(STDERR, "  validate failed: " . $inv->error . "\n"); exit(1); }
	return $inv->ref . ' (ttc=' . $inv->total_ttc . ')';
}

/** Create a validated, unpaid supplier invoice. */
function ensureSupplierInvoice($db, $user, $socid, $amount, $marker, $refSupplier)
{
	$r = $db->query("SELECT ref FROM " . MAIN_DB_PREFIX . "facture_fourn WHERE note_private = '" . $db->escape($marker) . "'");
	if ($r && ($o = $db->fetch_object($r))) { return $o->ref . ' (exists)'; }
	$inv = new FactureFournisseur($db);
	$inv->socid = $socid;
	$inv->ref_supplier = $refSupplier;
	$inv->date = dol_now();
	$inv->note_private = $marker;
	if ($inv->create($user) <= 0) { fwrite(STDERR, "  supplier invoice create failed: " . $inv->error . "\n"); exit(1); }
	// addline($desc, $pu, $txtva, $txlocaltax1, $txlocaltax2, $qty, ...)
	if ($inv->addline('CAMT test line', $amount, 0, 0, 0, 1) <= 0) { fwrite(STDERR, "  supplier addline failed: " . $inv->error . "\n"); exit(1); }
	$inv->fetch($inv->id);
	if ($inv->validate($user) <= 0) { fwrite(STDERR, "  supplier validate failed: " . $inv->error . "\n"); exit(1); }
	return $inv->ref . ' (ttc=' . $inv->total_ttc . ')';
}

echo "== 2. Third parties ==\n";
$custId = ensureSociete($db, $user, 'CAMT Test Customer', true, false);
$supId  = ensureSociete($db, $user, 'CAMT Test Supplier', false, true);
echo "  customer #$custId, supplier #$supId\n";

echo "== 3. Unpaid invoices ==\n";
echo "  cust 450 : " . ensureCustomerInvoice($db, $user, $custId, 450.00, 'CAMT-SEED-CUST-450') . "\n";
echo "  cust 600a: " . ensureCustomerInvoice($db, $user, $custId, 600.00, 'CAMT-SEED-CUST-600A') . "\n";
echo "  cust 600b: " . ensureCustomerInvoice($db, $user, $custId, 600.00, 'CAMT-SEED-CUST-600B') . "\n";
echo "  supp 275 : " . ensureSupplierInvoice($db, $user, $supId, 275.00, 'CAMT-SEED-SUPP-275', 'SUP-CAMT-275') . "\n";

echo "== 4. Second bank account (internal transfer target) ==\n";
$IBAN2 = 'BE68539007547034';
$r = $db->query("SELECT rowid FROM " . MAIN_DB_PREFIX . "bank_account WHERE ref = 'CAMT-TEST-2'");
if ($r && ($o = $db->fetch_object($r))) {
	echo "  exists #{$o->rowid} ($IBAN2)\n";
} else {
	$acc = new Account($db);
	$acc->ref = 'CAMT-TEST-2';
	$acc->label = 'CAMT053 Savings Account';
	$acc->type = Account::TYPE_CURRENT;
	$acc->courant = Account::TYPE_CURRENT;
	$acc->currency_code = 'EUR';
	$acc->country_code = 'BE';
	$acc->country_id = getCountry('BE', '3') ?: (!empty($GLOBALS['mysoc']->country_id) ? $GLOBALS['mysoc']->country_id : 0);
	$acc->iban = $IBAN2;
	$acc->bic = 'GKCCBEBB';
	$acc->date_solde = dol_now();
	$acc->solde = 0;
	$acc->status = 0;
	$acc->clos = 0;
	if ($acc->create($user) <= 0) { fwrite(STDERR, "  account2 create failed: " . $acc->error . "\n"); exit(1); }
	echo "  created #{$acc->id} ($IBAN2)\n";
}

echo "== DONE ==\n";
exit(0);
