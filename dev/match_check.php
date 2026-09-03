<?php
/** Dev-only: run the module matcher headlessly on the scenario file and print buckets. */
if (PHP_SAPI !== 'cli') { fwrite(STDERR, "CLI only\n"); exit(1); }

$rootPath = '/var/www/html';
chdir($rootPath);
$_SERVER['DOCUMENT_ROOT'] = $rootPath;
$_SERVER['SERVER_NAME'] = 'localhost';

require_once $rootPath . '/master.inc.php';
require_once DOL_DOCUMENT_ROOT . '/user/class/user.class.php';
require_once DOL_DOCUMENT_ROOT . '/compta/bank/class/account.class.php';
$mod = '/var/www/html/custom/camt053readerandlink/class/';
require_once $mod . 'Camt053FileProcessor.class.php';
require_once $mod . 'DatabaseBankStatementLoader.class.php';
require_once $mod . 'BankStatementMatcher.class.php';

global $db, $langs, $user;
$user = new User($db); $user->fetch(0, 'admin'); $user->loadRights();

$xml = getenv('CAMT_XML') ?: '';
if (!$xml || !is_file($xml)) { fwrite(STDERR, "Set CAMT_XML to the scenario XML path (see README).\n"); exit(1); }

$fileProcessor = new Camt053FileProcessor($db);
$dbLoader = new DatabaseBankStatementLoader($db, $langs);
$matcher = new BankStatementMatcher(1);

if (!$fileProcessor->parseFile($xml)) { fwrite(STDERR, "parse error: " . $fileProcessor->getError() . "\n"); exit(1); }

$date_start = getenv('CAMT_FROM') ?: '01/02/2024';
$date_end   = getenv('CAMT_TO') ?: '29/02/2024';
$dbStatements = $dbLoader->loadStatements($date_start, $date_end, null, $matcher->getDateTolerance());
if ($dbLoader->getError()) { fwrite(STDERR, "loader error: " . $dbLoader->getError() . "\n"); exit(1); }

$fileStatements = $fileProcessor->getStatementsByAccountId();
$banks = $matcher->compareMultiple($fileStatements, $dbStatements, $dbLoader);

function amt($item) {
	// Try to extract a readable amount/ref from whatever shape a bucket item has.
	$cands = array();
	if (is_object($item)) $cands[] = $item;
	if (is_array($item)) foreach (array('file','entry','db') as $k) if (isset($item[$k]) && is_object($item[$k])) $cands[] = $item[$k];
	foreach ($cands as $o) {
		if (method_exists($o, 'getAmount')) {
			$ref = method_exists($o, 'getHash') ? $o->getHash() : '';
			return number_format($o->getAmount(), 2) . ($ref ? " [$ref]" : '');
		}
	}
	return '(item)';
}

foreach ($banks as $accountId => $bank) {
	echo "==== Account #$accountId ====\n";
	$results = $bank['results'];
	foreach ($results as $bucket => $items) {
		if (!is_array($items)) continue;
		echo sprintf("  %-16s : %d\n", $bucket, count($items));
		foreach ($items as $it) echo "      - " . amt($it) . "\n";
	}
}
exit(0);
