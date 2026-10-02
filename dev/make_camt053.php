<?php
/**
 * Generate a CAMT.053 file to drop on a bank SFTP test server.
 *
 * Usage:
 *   php make_camt053.php --iban=CH... [--date=2026-08-03] [--ccy=CHF]
 *                        [--out=DIR] [--ref=PREFIX] [--targz] [--zip]
 */

$opts = getopt('', array('iban:', 'date::', 'ccy::', 'out::', 'ref::', 'targz', 'zip', 'help'));

if (isset($opts['help']) || empty($opts['iban'])) {
	fwrite(STDERR, "Usage: php make_camt053.php --iban=CH9300762011623852957 [--date=YYYY-MM-DD] [--ccy=CHF] [--out=DIR] [--ref=PREFIX] [--targz] [--zip]\n");
	exit(isset($opts['help']) ? 0 : 1);
}

$iban = strtoupper(preg_replace('/\s+/', '', (string) $opts['iban']));
$ccy = strtoupper($opts['ccy'] ?? 'CHF');
$date = $opts['date'] ?? date('Y-m-d');
$outDir = rtrim($opts['out'] ?? __DIR__, '/');
$refPrefix = $opts['ref'] ?? ('T' . str_replace('-', '', $date));

if (strtotime($date) === false) {
	fwrite(STDERR, "Invalid --date: $date\n");
	exit(1);
}

$stamp = date('Y-m-d\TH:i:s', strtotime($date . ' 23:30:00'));
$prevDate = date('Y-m-d', strtotime($date . ' -1 day'));

$entries = array(
	array('amount' => 1500.00, 'ind' => 'CRDT', 'ref' => $refPrefix . '-C1500', 'party' => 'ACME Corporation', 'iban' => 'CH5604835012345678009', 'info' => 'Invoice INV-2024-001', 'esr' => '000000000000000000000012345'),
	array('amount' => 275.00, 'ind' => 'DBIT', 'ref' => $refPrefix . '-D275', 'party' => 'Fournisseur Test SA', 'iban' => 'CH3908704016075473007', 'info' => 'Supplier invoice SI-2024-042', 'esr' => ''),
	array('amount' => 450.50, 'ind' => 'CRDT', 'ref' => $refPrefix . '-C450', 'party' => 'Client Deux Sarl', 'iban' => 'CH1204835098765432001', 'info' => 'Payment on account', 'esr' => ''),
);

$opening = 10000.00;
$closing = $opening;
foreach ($entries as $e) {
	$closing += ($e['ind'] === 'CRDT' ? $e['amount'] : -$e['amount']);
}

$x = array();
$x[] = '<?xml version="1.0" encoding="UTF-8"?>';
$x[] = '<Document xmlns="urn:iso:std:iso:20022:tech:xsd:camt.053.001.04" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">';
$x[] = '	<BkToCstmrStmt>';
$x[] = '		<GrpHdr>';
$x[] = '			<MsgId>' . $refPrefix . '-MSG</MsgId>';
$x[] = '			<CreDtTm>' . $stamp . '</CreDtTm>';
$x[] = '			<MsgRcpt><Nm>Dolibarr Test</Nm></MsgRcpt>';
$x[] = '		</GrpHdr>';
$x[] = '		<Stmt>';
$x[] = '			<Id>' . $refPrefix . '-STMT</Id>';
$x[] = '			<ElctrncSeqNb>1</ElctrncSeqNb>';
$x[] = '			<CreDtTm>' . $stamp . '</CreDtTm>';
$x[] = '			<Acct>';
$x[] = '				<Id><IBAN>' . htmlspecialchars($iban) . '</IBAN></Id>';
$x[] = '				<Ccy>' . $ccy . '</Ccy>';
$x[] = '				<Ownr><Nm>Dolibarr Test</Nm></Ownr>';
$x[] = '				<Svcr><FinInstnId><BIC>POFICHBEXXX</BIC><Nm>PostFinance AG</Nm></FinInstnId></Svcr>';
$x[] = '			</Acct>';
$x[] = '			<Bal>';
$x[] = '				<Tp><CdOrPrtry><Cd>OPBD</Cd></CdOrPrtry></Tp>';
$x[] = '				<Amt Ccy="' . $ccy . '">' . number_format($opening, 2, '.', '') . '</Amt>';
$x[] = '				<CdtDbtInd>CRDT</CdtDbtInd>';
$x[] = '				<Dt><Dt>' . $prevDate . '</Dt></Dt>';
$x[] = '			</Bal>';
$x[] = '			<Bal>';
$x[] = '				<Tp><CdOrPrtry><Cd>CLBD</Cd></CdOrPrtry></Tp>';
$x[] = '				<Amt Ccy="' . $ccy . '">' . number_format($closing, 2, '.', '') . '</Amt>';
$x[] = '				<CdtDbtInd>CRDT</CdtDbtInd>';
$x[] = '				<Dt><Dt>' . $date . '</Dt></Dt>';
$x[] = '			</Bal>';

foreach ($entries as $e) {
	$party = ($e['ind'] === 'CRDT') ? 'Dbtr' : 'Cdtr';
	$partyAcct = ($e['ind'] === 'CRDT') ? 'DbtrAcct' : 'CdtrAcct';

	$x[] = '			<Ntry>';
	$x[] = '				<AcctSvcrRef>' . $e['ref'] . '</AcctSvcrRef>';
	$x[] = '				<Amt Ccy="' . $ccy . '">' . number_format($e['amount'], 2, '.', '') . '</Amt>';
	$x[] = '				<CdtDbtInd>' . $e['ind'] . '</CdtDbtInd>';
	$x[] = '				<Sts>BOOK</Sts>';
	$x[] = '				<BookgDt><Dt>' . $date . '</Dt></BookgDt>';
	$x[] = '				<ValDt><Dt>' . $date . '</Dt></ValDt>';
	$x[] = '				<BkTxCd><Domn><Cd>PMNT</Cd><Fmly><Cd>RCDT</Cd><SubFmlyCd>ESCT</SubFmlyCd></Fmly></Domn></BkTxCd>';
	$x[] = '				<NtryDtls>';
	$x[] = '					<TxDtls>';
	$x[] = '						<Refs><AcctSvcrRef>' . $e['ref'] . '</AcctSvcrRef><EndToEndId>' . $e['ref'] . '-E2E</EndToEndId></Refs>';
	$x[] = '						<Amt Ccy="' . $ccy . '">' . number_format($e['amount'], 2, '.', '') . '</Amt>';
	$x[] = '						<CdtDbtInd>' . $e['ind'] . '</CdtDbtInd>';
	$x[] = '						<RltdPties>';
	$x[] = '							<' . $party . '><Nm>' . htmlspecialchars($e['party']) . '</Nm></' . $party . '>';
	$x[] = '							<' . $partyAcct . '><Id><IBAN>' . $e['iban'] . '</IBAN></Id></' . $partyAcct . '>';
	$x[] = '						</RltdPties>';
	if ($e['esr'] !== '') {
		$x[] = '						<RmtInf><Strd><CdtrRefInf><Tp><CdOrPrtry><Prtry>ESR</Prtry></CdOrPrtry></Tp><Ref>' . $e['esr'] . '</Ref></CdtrRefInf></Strd></RmtInf>';
	} else {
		$x[] = '						<RmtInf><Ustrd>' . htmlspecialchars($e['info']) . '</Ustrd></RmtInf>';
	}
	$x[] = '					</TxDtls>';
	$x[] = '				</NtryDtls>';
	$x[] = '				<AddtlNtryInf>' . htmlspecialchars($e['info']) . '</AddtlNtryInf>';
	$x[] = '			</Ntry>';
}

$x[] = '		</Stmt>';
$x[] = '	</BkToCstmrStmt>';
$x[] = '</Document>';

$xml = implode("\n", $x) . "\n";

if (simplexml_load_string($xml) === false) {
	fwrite(STDERR, "Generated XML is not well-formed\n");
	exit(1);
}

$base = 'camt053_' . str_replace('-', '', $date) . '_' . substr($iban, -6);
$xmlPath = $outDir . '/' . $base . '.xml';
file_put_contents($xmlPath, $xml);
echo "wrote $xmlPath\n";

if (isset($opts['targz'])) {
	$name = $base . '.xml';
	$header = str_pad($name, 100, "\0");
	$header .= str_pad('0000644', 8, "\0");
	$header .= str_pad('0000000', 8, "\0");
	$header .= str_pad('0000000', 8, "\0");
	$header .= str_pad(sprintf('%011o', strlen($xml)), 12, "\0");
	$header .= str_pad(sprintf('%011o', strtotime($date)), 12, "\0");
	$header .= str_repeat(' ', 8);
	$header .= '0';
	$header = str_pad($header, 512, "\0");

	$checksum = 0;
	for ($i = 0; $i < 512; $i++) {
		$checksum += ord($header[$i]);
	}
	$header = substr_replace($header, str_pad(sprintf('%06o', $checksum), 7, "\0", STR_PAD_LEFT) . ' ', 148, 8);

	$tar = $header . str_pad($xml, (int) (ceil(strlen($xml) / 512) * 512), "\0") . str_repeat("\0", 1024);
	$targzPath = $outDir . '/' . $base . '.tar.gz';
	file_put_contents($targzPath, gzencode($tar));
	echo "wrote $targzPath\n";
}

if (isset($opts['zip'])) {
	$zipPath = $outDir . '/' . $base . '.zip';
	@unlink($zipPath);
	$zip = new ZipArchive();
	if ($zip->open($zipPath, ZipArchive::CREATE) !== true) {
		fwrite(STDERR, "Unable to create $zipPath\n");
		exit(1);
	}
	$zip->addFromString($base . '.xml', $xml);
	$zip->close();
	echo "wrote $zipPath\n";
}

echo "IBAN=$iban  date=$date  entries=" . count($entries) . "  net=" . number_format($closing - $opening, 2, '.', '') . " $ccy\n";
