<?php

function flatten(array $array) {
	$return = array();
	array_walk_recursive($array, function($a) use (&$return) { $return[] = $a; });
	return $return;
}

class EntryCamt053 {
	private $data = array();
	private $is_from_file = false;
	private $bank_obj = null;
	private $hash = null;

	public function __construct(
		$amount,
		$value_date,
		$name,
		$info = '',
		$hash = null
	){
		if (!empty($hash)) {
			$this->hash = $hash;
		} else {
			$this->hash = md5($amount . $value_date . $name . $info);
		}
		$this->data = array(
			'amount' => $amount,
			'value_date' => $value_date,
			'name' => $name,
			'info' => $info,
			'hash' => $this->hash
		);
	}

	/**
	 * @param $is_from_file bool
	 * @return void
	 */
	public function setIsFromFile($is_from_file){
		$this->is_from_file = $is_from_file;
	}

	/**
	 * @return bool
	 */
	public function isFromFile(){
		return $this->is_from_file;
	}

	/**
	 * @return array
	 */
	public function getData(){
		return $this->data;
	}

	/**
	 * @return void
	 */
	public function setBankObj($bank_obj){
		$this->bank_obj = $bank_obj;
	}

	/**
	 * @return AccountLine
	 */
	public function getBankObj(){
		return $this->bank_obj;
	}

	/**
	 * @return string
	 */
	public function getHash(){
		return $this->hash;
	}

	public function setHash(mixed $hash)
	{
		$this->hash = $hash;
	}
}

function getArrayKeys($array, $search_keys, $default = null)
{
	if (
		is_array($array)
		&& !empty($array)
	) {
		for ($i = 0; $i < count($search_keys); $i++) {
			if (is_array($array) && array_key_exists($search_keys[$i], $array)) {
				$array = $array[$search_keys[$i]];
			} else {
				return $default;
			}
		}
	}
	return $array;
}

function formatIBAN($iban){
	$iban = str_replace(' ', '', $iban);
	return chunk_split($iban, 4, ' ');
}

class StatementsCamt053 {
	private $db = null;
	private $is_file = false;
	private $structure = array();
	private $banks = array();

	public function __construct($db)
	{
		$this->db = $db;
	}

	public function setStructure($structure){
		$this->structure = $structure;
		$this->processStructure();
	}

	public function getDbBank($bankId){
		$sql = "SELECT rowid, iban_prefix FROM " . MAIN_DB_PREFIX . "bank_account ";
		$sql .= "WHERE rowid = " . ((int) $bankId);
		$resql = $this->db->query($sql);
		if (!$resql) {
			throw new Exception('Error while getting the bank account for ID ' . $bankId);
		}
		$bank = $this->db->fetch_object($resql);
		if ($bank) {
			$bank->iban_prefix = formatIBAN($bank->iban_prefix);
			return $bank;
		} else {
			throw new Exception('Bank account not found for ID ' . $bankId);
		}
	}

	public function setData($data)
	{
		$banksIds = array();
		foreach ($data as $value) {
			$v = $value['bank_obj']->fk_account;
			$banksIds[$v] = $v;
		}

		foreach ($banksIds as $bankId) {
			$bank = $this->getDbBank($bankId);

			$entries = array();

			foreach ($data as $value){
				if ($value['bank_obj']->fk_account == $bankId){
					$n = $this->addEntry(
						$value['amount'],
						$value['value_date'],
						$value['name']
					);
					$n->setBankObj($value['bank_obj']);
					$entries[] = $n;
				}
			}

			$this->banks[] = array(
				'IBAN' => $bank->iban_prefix,
				'AccountId' => $bankId,
				'Ntries' => $entries,
			);
		}
	}

	/**
	 * @param $is_file bool
	 * @return void
	 */
	public function setIsFile($is_file){
		$this->is_file = $is_file;
	}

	/**
	 * @return bool
	 */
	public function isFile(){
		return $this->is_file;
	}

	/**
	 * @param $amount float
	 * @param $value_date_time string
	 * @param $name string
	 * @param $address array
	 * @return EntryCamt053
	 */
	private function addEntry(
		$amount,
		$value_date,
		$name,
		$info = '',
		$hash = null
	){
		$ntry = new EntryCamt053($amount, $value_date, $name, $info, $hash);
		$ntry->setIsFromFile($this->is_file);
		return $ntry;
	}

	/**
	 * @param $f StatementsCamt053
	 * @param $b StatementsCamt053
	 * @return array
	 */
	public static function compare($a, $b){

		if ($a->isFile()) {
			$file_datas = $a->getBanks();
			$db_datas = $b->getBanks();
		} else {
			$file_datas = $b->getBanks();
			$db_datas = $a->getBanks();
		}

		$banks = array();

		// Compare Banks
		foreach ($file_datas as $k => $file_data){
			$bankAccount = $a->getDbBank($k);
			$db_bank = $db_datas[$k];

			$linkeds = array();
			$multiples = array();
			$unlinked = array();
			$already_linked = array();

			$aEntries = $file_data['Ntries'];

			if (!empty($db_bank)) $bEntries = $db_bank['Ntries'];
			else $bEntries = array();

			foreach ($aEntries as $aEntry) {
				$founds = array();

				$ad = $aEntry->getData();
				$ad_date = date_create_from_format('Y-m-d', $ad['value_date']);
				$ad_amount = price($ad['amount']);

				foreach ($bEntries as $bEntry){
					$bd = $bEntry->getData();
					$bd_date = date_create_from_format('Y-m-d', $bd['value_date']);
					$bd_amount = price($bd['amount']);

					if (
						$ad_amount == $bd_amount
						&& (
							$ad_date->format('Y-m-d') == $bd_date->format('Y-m-d')
							|| $ad_date->format('Y-m-d') == $bd_date->modify('+1 day')->format('Y-m-d')
							|| $ad_date->modify('+1 day')->format('Y-m-d') == $bd_date->format('Y-m-d')
						)
					){
						$founds[] = $bEntry;
					}
				}

				if (count($founds) == 1){
					$l = $founds[0];
					if ($l->getBankObj()->rappro != 1) {
						$linkeds[] = array(
							'file' => $aEntry,
							'db' => $l
						);
					} else {
						$already_linked[] = array(
							'file' => $aEntry,
							'db' => $l
						);
					}
				} else if (count($founds) > 1){
					$f = array_filter($founds, function($a){
						return $a->getBankObj()->rappro != 1;
					});
					$f = array_values($f);
					if (count($f) == 1){
						$linkeds[] = array(
							'file' => $aEntry,
							'db' => $f[0]
						);
					} else {
						$multiples[] = array(
							'file' => $aEntry,
							'db' => $f
						);
					}
				} else {
					$unlinked[] = $aEntry;
				}
			}

			foreach ($bEntries as $bEntry){
				$al = array_column($already_linked, 'db');
				$m = flatten(array_column($multiples, 'db'));
				$rap = $bEntry->getBankObj()->rappro == 1;


				if (!in_array($bEntry, array_column($linkeds, 'db')) && !in_array($bEntry, $m) && !in_array($bEntry, $al)){
					if (!$rap) {
						$unlinked[] = $bEntry;
					} else {
						$already_linked[] = array(
							'file' => null,
							'db' => $bEntry
						);
					}
				}
			}

			$results = array(
				'linkeds' => $linkeds,
				'multiples' => $multiples,
				'unlinkeds' => $unlinked,
				'already_linked' => $already_linked
			);
			$banks[$k] = array(
				'account' => $bankAccount,
				'results' => $results
			);
		}

		return $banks;
	}

	/**
	 * @return array
	 */
	public function getData(){
		return $this->data;
	}

	private function processStructure()
	{
		$this->extractStructureBanks();
	}

	private function matchBank($iban){
		$ibanNoSpace = str_replace(' ', '', $iban);
		$sql = "SELECT rowid FROM " . MAIN_DB_PREFIX . "bank_account ";
		$sql .= "WHERE iban_prefix = '" . $this->db->escape($iban) . "' ";
		$sql .= "OR iban_prefix = '" . $this->db->escape($ibanNoSpace) . "'";
		$resql = $this->db->query($sql);
		if (!$resql) {
			throw new Exception('Error while getting the bank account for IBAN ' . $iban);
		}
		$bankAccount = $this->db->fetch_object($resql);
		if (!$bankAccount) throw new Exception('Error while getting the bank account for IBAN ' . $iban);
		return $bankAccount->rowid;
	}

	private function extractBank($statement) {
		$entries = $statement['Ntry'];
		if (isset($entries['CdtDbtInd'])) {
			$entries = array($entries);
		}
		$iban = $statement['Acct']['Id']['IBAN'];
		$iban = formatIBAN($iban);
		$bankAccountId = $this->matchBank($iban);

		$entries = $this->evaluateEntries($entries);

		$this->banks[] = array(
			'IBAN' => $iban,
			'AccountId' => $bankAccountId,
			'Ntries' => $entries,
		);
	}

	private function extractStructureBanks()
	{
		$statements = $this->structure['BkToCstmrStmt']['Stmt'];
		if (isset($statements['Ntry'])){
			$this->extractBank($statements);
		} else {
			foreach ($statements as $statement) {
				$this->extractBank($statement);
			}
		}
	}

	private function evaluateEntries($entries)
	{
		$data = array();

		foreach ($entries as $entry){
			$amount = floatval($entry['Amt']);
			$type = $entry['CdtDbtInd'];
			if ($type == 'DBIT') $amount = -$amount;
			$value_date = new DateTime($entry['ValDt']['Dt']);
			$hash = getArrayKeys($entry, ['AcctSvcrRef'], null);

			$type_nm = $type == 'DBIT' ? 'Dbtr' : 'Cdtr';

			$name = '';

			$name_1 = getArrayKeys($entry, ['NtryDtls', 'TxDtls', 'RmtInf', 'Ustrd'], '');
			if (is_array($name_1)) $name_1 = implode(' ', $name_1);
			if (!empty($name_1)) $name .= $name_1;

			$name_2 = getArrayKeys($entry, ['NtryDtls', 'TxDtls', 'RltdPties', $type_nm, 'Nm'], '');
			if (!empty($name_2)) $name .= '<br />'.$name_2;

			$info = getArrayKeys($entry, ['AddtlNtryInf'], '');
			if (!empty($info)) {
				// split string on COMMUNICATIONS and REFERENCES to add a line break
				$info = str_replace('COMMUNICATIONS', '<br />COMMUNICATIONS', $info);
				$info = str_replace('REFERENCES', '<br />REFERENCES', $info);
			}
			$info .= '<br />'.getArrayKeys($entry, ['NtryDtls', 'TxDtls', 'AddtlTxInf'], '');

			$n = $this->addEntry(
				$amount,
				$value_date->format('Y-m-d'),
				$name,
				$info,
				$hash
			);

			$data[] = $n;
		}

		return $data;
	}

	public function getBanks()
	{
		$banks = array();
		foreach ($this->banks as $b) {
			$banks[$b['AccountId']] = $b;
		}
		return $banks;
	}
}

// Include new class files
require_once __DIR__ . '/class/Camt053Entry.class.php';
require_once __DIR__ . '/class/Camt053Statement.class.php';
require_once __DIR__ . '/class/Camt053FileProcessor.class.php';
require_once __DIR__ . '/class/BankStatementMatcher.class.php';
require_once __DIR__ . '/class/DatabaseBankStatementLoader.class.php';
require_once __DIR__ . '/class/BankEntryReconciler.class.php';
require_once __DIR__ . '/class/BankRelationshipLookup.class.php';
