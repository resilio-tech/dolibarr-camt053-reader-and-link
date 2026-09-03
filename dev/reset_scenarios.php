<?php
/**
 * Dev-only: reset the CAMT.053 scenario bank lines to their pre-test state so the
 * whole reconciliation flow can be replayed. Un-reconciles every scenario line
 * except the intentionally "already reconciled" one (SCEN already 999).
 */
if (PHP_SAPI !== 'cli') { fwrite(STDERR, "CLI only\n"); exit(1); }

$rootPath = '/var/www/html';
chdir($rootPath);
$_SERVER['DOCUMENT_ROOT'] = $rootPath;
$_SERVER['SERVER_NAME'] = 'localhost';
require_once $rootPath . '/master.inc.php';

global $db;
$IBAN = 'BE71096123456769';

$resql = $db->query("SELECT rowid FROM " . MAIN_DB_PREFIX . "bank_account WHERE iban_prefix = '$IBAN'");
$obj = $resql ? $db->fetch_object($resql) : null;
if (!$obj) { fwrite(STDERR, "Test account not found\n"); exit(1); }
$accountId = (int) $obj->rowid;

// Un-reconcile every scenario line whose label starts with 'SCEN', except the
// one that must stay reconciled to exercise the already_linked case.
$db->query(
	"UPDATE " . MAIN_DB_PREFIX . "bank SET rappro = 0, num_releve = NULL"
	. " WHERE fk_account = $accountId AND label LIKE 'SCEN %' AND label <> 'SCEN already 999'"
);
// Keep the already-linked one reconciled.
$db->query(
	"UPDATE " . MAIN_DB_PREFIX . "bank SET rappro = 1, num_releve = 'REL-2024-02-01'"
	. " WHERE fk_account = $accountId AND label = 'SCEN already 999'"
);

echo "Scenario lines reset on account #$accountId. Ready to replay.\n";
exit(0);
