<?php
/** Dev-only: for every UNLINKED file entry, print the payment / transfer
 *  suggestions the results page would render. Proves the "create payment" and
 *  "internal transfer" paths without the UI. Set CAMT_XML to the scenario file. */
if (PHP_SAPI !== 'cli') { fwrite(STDERR, "CLI only\n"); exit(1); }

$rootPath = '/var/www/html';
chdir($rootPath);
$_SERVER['DOCUMENT_ROOT'] = $rootPath;
$_SERVER['SERVER_NAME'] = 'localhost';

require_once $rootPath . '/master.inc.php';
require_once DOL_DOCUMENT_ROOT . '/user/class/user.class.php';
require_once DOL_DOCUMENT_ROOT . '/compta/bank/class/account.class.php';
$mod = '/var/www/html/custom/camt053readerandlink/class/';
foreach (array('Camt053FileProcessor', 'DatabaseBankStatementLoader', 'BankStatementMatcher',
	'PaymentSuggestionFinder', 'InternalTransferDetector') as $c) {
	require_once $mod . $c . '.class.php';
}

global $db, $conf, $langs, $user;
$user = new User($db); $user->fetch(0, 'admin'); $user->loadRights();
$entity = (int) $conf->entity;

$xml = getenv('CAMT_XML') ?: '';
if (!$xml || !is_file($xml)) { fwrite(STDERR, "Set CAMT_XML to the scenario XML path.\n"); exit(1); }

$fileProcessor = new Camt053FileProcessor($db);
$dbLoader = new DatabaseBankStatementLoader($db, $langs);
$matcher = new BankStatementMatcher(1);
$finder = new PaymentSuggestionFinder($db);
$detector = new InternalTransferDetector($db);

if (!$fileProcessor->parseFile($xml)) { fwrite(STDERR, "parse: " . $fileProcessor->getError() . "\n"); exit(1); }
$dbStatements = $dbLoader->loadStatements('01/02/2024', '29/02/2024', null, $matcher->getDateTolerance());
$fileStatements = $fileProcessor->getStatementsByAccountId();
$banks = $matcher->compareMultiple($fileStatements, $dbStatements, $dbLoader);

foreach ($banks as $accountId => $bank) {
	echo "==== Account #$accountId : suggestions for UNLINKED file entries ====\n";
	foreach ($bank['results']['unlinkeds'] as $entry) {
		if (!is_object($entry) || !method_exists($entry, 'isFromFile') || !$entry->isFromFile()) { continue; }
		$ref = method_exists($entry, 'getHash') ? $entry->getHash() : '';
		$amt = number_format($entry->getAmount(), 2);
		echo "  [$ref] amount=$amt\n";

		$transfer = $detector->detect($entry, (int) $accountId, $entity);
		if ($transfer !== null) {
			echo "      -> INTERNAL TRANSFER to account '{$transfer['counterparty_ref']}' "
				. "({$transfer['counterparty_label']}) amount {$transfer['amount']}\n";
		}

		$sugg = $finder->findForEntry($entry, $entity, (int) $accountId);
		if (empty($sugg['links'])) {
			if ($transfer === null) { echo "      -> no suggestion (manual processing)\n"; }
			continue;
		}
		foreach ($sugg['links'] as $link) {
			if ($link['kind'] === 'pay') {
				echo "      -> CREATE PAYMENT ({$link['type']}) on {$link['ref']} "
					. "[{$link['label']}] amount {$link['amount']} {$link['currency']}\n";
			} else { // choice
				echo "      -> CHOICE: {$link['count']} {$link['type']} candidates "
					. "(" . implode(', ', array_map(function ($o) { return $o['ref']; }, $link['options'])) . ")\n";
			}
		}
	}
}
exit(0);
