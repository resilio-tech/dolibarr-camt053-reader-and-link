<?php
/* Copyright (C) 2026 Resilio SA
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

/**
 * \file       class/InternalTransferDetector.class.php
 * \ingroup    camt053readerandlink
 * \brief      Detect transfers between the company's own bank accounts from an
 *             unreconciled CAMT.053 entry, to offer a prefilled internal transfer.
 */

require_once __DIR__ . '/Camt053Entry.class.php';

/**
 * Class InternalTransferDetector
 *
 * When an unreconciled entry's counterparty IBAN matches another Dolibarr bank
 * account (same entity), the movement is an internal transfer. Transfers can be
 * cross-currency (e.g. a CHF account to a EUR account).
 */
class InternalTransferDetector
{
	/** @var DoliDb Database connection */
	private $db;

	/**
	 * Constructor
	 *
	 * @param DoliDb $db Database connection
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * Detect an internal transfer for one unreconciled file entry.
	 *
	 * @param Camt053Entry $entry           File entry (with counterparty IBAN)
	 * @param int          $sourceAccountId Bank account the statement belongs to
	 * @param int          $entity          Entity of the source account
	 * @return array|null Transfer descriptor, or null when not an internal transfer
	 */
	public function detect(Camt053Entry $entry, int $sourceAccountId, int $entity): ?array
	{
		$iban = $entry->getCounterpartyIban();
		if ($iban === '') {
			return null;
		}

		if ($entity <= 0) {
			global $conf;
			$entity = (int) $conf->entity;
		}

		$other = $this->findAccountByIban($iban, $entity, $sourceAccountId);
		if ($other === null) {
			return null;
		}

		// Debit -> money leaves the source account (source -> other).
		// Credit -> money enters the source account (other -> source).
		$isDebit = $entry->getAmount() < 0;
		$fromId = $isDebit ? $sourceAccountId : (int) $other->rowid;
		$toId = $isDebit ? (int) $other->rowid : $sourceAccountId;

		return array(
			'from_id' => $fromId,
			'to_id' => $toId,
			'amount' => abs($entry->getAmount()),
			'currency' => strtoupper($entry->getCurrency()),
			'date' => $entry->getValueDate(),
			'counterparty_ref' => (string) $other->ref,
			'counterparty_label' => (string) $other->label,
		);
	}

	/**
	 * Build the URL to the module's transfer confirmation page.
	 *
	 * @param array $transfer Descriptor from detect()
	 * @return string URL
	 */
	public function confirmUrl(array $transfer): string
	{
		$params = array(
			'from' => (int) $transfer['from_id'],
			'to' => (int) $transfer['to_id'],
			'amount' => number_format((float) $transfer['amount'], 2, '.', ''),
			'date' => $transfer['date'],
		);
		return dol_buildpath('/custom/camt053readerandlink/transfer_confirm.php', 1) . '?' . http_build_query($params);
	}

	/**
	 * Find an open bank account by IBAN within an entity, excluding one account.
	 *
	 * @param string $iban      Counterparty IBAN (any spacing)
	 * @param int    $entity    Entity
	 * @param int    $excludeId Account id to exclude (the source account)
	 * @return object|null Account row (rowid, ref, label, currency_code) or null
	 */
	private function findAccountByIban(string $iban, int $entity, int $excludeId): ?object
	{
		$ibanNoSpace = strtoupper(str_replace(' ', '', $iban));
		if ($ibanNoSpace === '') {
			return null;
		}

		$sql = "SELECT rowid, ref, label, currency_code FROM " . MAIN_DB_PREFIX . "bank_account";
		$sql .= " WHERE entity = " . ((int) $entity);
		$sql .= " AND clos = 0";
		$sql .= " AND rowid <> " . ((int) $excludeId);
		$sql .= " AND UPPER(REPLACE(iban_prefix, ' ', '')) = '" . $this->db->escape($ibanNoSpace) . "'";

		$resql = $this->db->query($sql);
		if (!$resql) {
			dol_syslog('CAMT053 InternalTransferDetector query failed: ' . $this->db->lasterror(), LOG_ERR);
			return null;
		}
		$obj = $this->db->fetch_object($resql);
		return $obj ?: null;
	}
}
