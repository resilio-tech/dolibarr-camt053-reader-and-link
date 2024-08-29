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
		$info
	){
		$this->hash = md5($amount . $value_date . $name . $info);
		$this->data = array(
			'amount' => $amount,
			'value_date' => $value_date,
			'name' => $name,
			'info' => $info
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

class StatementsCamt053 {
	private $data = array();
	private $is_file = false;
	public function parse($data){
		// Parse the data
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
	public function addEntry(
		$amount,
		$value_date,
		$name,
		$info = ''
	){
		$ntry = new EntryCamt053($amount, $value_date, $name, $info);
		$ntry->setIsFromFile($this->is_file);
		$this->data[] = $ntry;
		return $ntry;
	}

	/**
	 * @param $a StatementsCamt053
	 * @param $b StatementsCamt053
	 * @return array
	 */
	public static function compare($a, $b){
		$linkeds = array();
		$multiples = array();
		$unlinked = array();
		$already_linked = array();

		if ($a->isFile()) {
			$file_datas = $a->getData();
			$db_datas = $b->getData();
		} else {
			$file_datas = $b->getData();
			$db_datas = $a->getData();
		}


		// Compare the data
		foreach ($file_datas as $file_data){
			$founds = array();

			$ad = $file_data->getData();
			$ad_date = date_create_from_format('Y-m-d', $ad['value_date']);
			$ad_amount = price($ad['amount']);
			foreach ($db_datas as $db_data){
				$bd = $db_data->getData();
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
					$founds[] = $db_data;
				}
			}

			if (count($founds) == 1){
				$l = $founds[0];
				if ($l->getBankObj()->rappro != 1) {
					$linkeds[] = array(
						'file' => $file_data,
						'db' => $founds[0]
					);
				} else {
					$already_linked[] = array(
						'file' => $file_data,
						'db' => $founds[0]
					);
				}
			} else if (count($founds) > 1){
				$a = array_filter($founds, function($a){
					return $a->getBankObj()->rappro != 1;
				});
				$a = array_values($a);
				if (count($a) == 1){
					$linkeds[] = array(
						'file' => $file_data,
						'db' => $a[0]
					);
				} else {
					$multiples[] = array(
						'file' => $file_data,
						'db' => $a
					);
				}
			} else {
				$unlinked[] = $file_data;
			}
		}

		foreach ($db_datas as $db_data){
			$m = flatten(array_column($multiples, 'db'));
			$al = $db_data->getBankObj()->rappro == 1;
			if (!in_array($db_data, array_column($linkeds, 'db')) && !in_array($db_data, $m)){
				if (!$al) {
					$unlinked[] = $db_data;
				} else {
					$already_linked[] = array(
						'file' => null,
						'db' => $db_data
					);
				}
			}
		}

		return array(
			'linkeds' => $linkeds,
			'multiples' => $multiples,
			'unlinkeds' => $unlinked,
			'already_linked' => $already_linked
		);
	}

	/**
	 * @return array
	 */
	public function getData(){
		return $this->data;
	}
}
