<?php
/* Copyright (C) 2024 Slordef
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
 * \file       class/BankRelationshipLookup.class.php
 * \ingroup    camt053readerandlink
 * \brief      Class for looking up relationships between bank lines and invoices/payments
 */

/**
 * Class BankRelationshipLookup
 *
 * Finds invoices, supplier invoices, and other documents related to bank lines.
 */
class BankRelationshipLookup
{
	/**
	 * @var DoliDb Database connection
	 */
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
	 * Get relation name and link for a bank line
	 *
	 * @param int $lineId Bank line ID
	 * @return string|null HTML link to related document or null if not found
	 */
	public function getRelationHtml(int $lineId): ?string
	{
		// Try customer invoice
		$result = $this->getCustomerInvoice($lineId);
		if ($result !== null) {
			return $this->formatInvoiceLink($result, 'customer');
		}

		// Try supplier invoice
		$result = $this->getSupplierInvoice($lineId);
		if ($result !== null) {
			return $this->formatInvoiceLink($result, 'supplier');
		}

		// Try bank line itself
		$result = $this->getBankLine($lineId);
		if ($result !== null) {
			return $this->formatBankLineLink($result);
		}

		return null;
	}

	/**
	 * Get relation data for a bank line
	 *
	 * @param int $lineId Bank line ID
	 * @return array|null Relation data or null if not found
	 */
	public function getRelation(int $lineId): ?array
	{
		// Try customer invoice
		$result = $this->getCustomerInvoice($lineId);
		if ($result !== null) {
			return array(
				'type' => 'customer_invoice',
				'id' => $result->rowid,
				'ref' => $result->ref,
				'label' => $result->nom
			);
		}

		// Try supplier invoice
		$result = $this->getSupplierInvoice($lineId);
		if ($result !== null) {
			return array(
				'type' => 'supplier_invoice',
				'id' => $result->rowid,
				'ref' => $result->ref,
				'label' => $result->nom
			);
		}

		// Try bank line itself
		$result = $this->getBankLine($lineId);
		if ($result !== null) {
			return array(
				'type' => 'bank_line',
				'id' => $result->rowid,
				'ref' => '',
				'label' => $result->label
			);
		}

		return null;
	}

	/**
	 * Get customer invoice linked to bank line
	 *
	 * @param int $lineId Bank line ID
	 * @return object|null Invoice data or null
	 */
	private function getCustomerInvoice(int $lineId): ?object
	{
		$sql = "SELECT f.rowid, f.ref, s.nom FROM " . MAIN_DB_PREFIX . "facture AS f ";
		$sql .= "INNER JOIN " . MAIN_DB_PREFIX . "societe AS s ON f.fk_soc = s.rowid ";
		$sql .= "LEFT JOIN " . MAIN_DB_PREFIX . "paiement_facture AS pf ON f.rowid = pf.fk_facture ";
		$sql .= "LEFT JOIN " . MAIN_DB_PREFIX . "paiement AS p ON pf.fk_paiement = p.rowid ";
		$sql .= "WHERE p.fk_bank = " . ((int) $lineId);

		$resql = $this->db->query($sql);
		if ($resql) {
			$obj = $this->db->fetch_object($resql);
			if ($obj) {
				return $obj;
			}
		}

		return null;
	}

	/**
	 * Get supplier invoice linked to bank line
	 *
	 * @param int $lineId Bank line ID
	 * @return object|null Invoice data or null
	 */
	private function getSupplierInvoice(int $lineId): ?object
	{
		$sql = "SELECT f.rowid, f.ref, s.nom FROM " . MAIN_DB_PREFIX . "facture_fourn AS f ";
		$sql .= "INNER JOIN " . MAIN_DB_PREFIX . "societe AS s ON f.fk_soc = s.rowid ";
		$sql .= "LEFT JOIN " . MAIN_DB_PREFIX . "paiementfourn_facturefourn AS pf ON f.rowid = pf.fk_facturefourn ";
		$sql .= "LEFT JOIN " . MAIN_DB_PREFIX . "paiementfourn AS p ON pf.fk_paiementfourn = p.rowid ";
		$sql .= "WHERE p.fk_bank = " . ((int) $lineId);

		$resql = $this->db->query($sql);
		if ($resql) {
			$obj = $this->db->fetch_object($resql);
			if ($obj) {
				return $obj;
			}
		}

		return null;
	}

	/**
	 * Get bank line data
	 *
	 * @param int $lineId Bank line ID
	 * @return object|null Bank line data or null
	 */
	private function getBankLine(int $lineId): ?object
	{
		$sql = "SELECT rowid, label FROM " . MAIN_DB_PREFIX . "bank WHERE rowid = " . ((int) $lineId);

		$resql = $this->db->query($sql);
		if ($resql) {
			$obj = $this->db->fetch_object($resql);
			if ($obj) {
				return $obj;
			}
		}

		return null;
	}

	/**
	 * Format invoice link HTML
	 *
	 * @param object $invoice Invoice data
	 * @param string $type    'customer' or 'supplier'
	 * @return string HTML link
	 */
	private function formatInvoiceLink(object $invoice, string $type): string
	{
		$name = dol_escape_htmltag($invoice->ref) . '<br/>' . dol_escape_htmltag($invoice->nom);
		$title = dol_escape_htmltag($invoice->ref . ' - ' . $invoice->nom, 1);

		if ($type === 'customer') {
			$url = DOL_URL_ROOT . '/compta/facture/card.php?id=' . ((int) $invoice->rowid) . '&save_lastsearch_values=1';
			$icon = img_picto('', 'bill');
		} else {
			$url = DOL_URL_ROOT . '/fourn/facture/card.php?id=' . ((int) $invoice->rowid) . '&save_lastsearch_values=1';
			$icon = img_picto('', 'supplier_invoice');
		}

		return '<a href="' . $url . '" title="' . $title . '" class="classfortooltip" target="_blank">'
			. $icon . ' ' . ((int) $invoice->rowid) . ' ' . $name . '</a>';
	}

	/**
	 * Format bank line link HTML
	 *
	 * @param object $bankLine Bank line data
	 * @return string HTML link
	 */
	private function formatBankLineLink(object $bankLine): string
	{
		$name = dol_escape_htmltag($bankLine->label);
		$url = DOL_URL_ROOT . '/compta/bank/line.php?rowid=' . ((int) $bankLine->rowid) . '&save_lastsearch_values=1';

		return '<a href="' . $url . '" title="' . $name . '" class="classfortooltip" target="_blank">'
			. img_picto('', 'bank') . ' ' . ((int) $bankLine->rowid) . ' ' . $name . '</a>';
	}

	/**
	 * Get multiple relations at once (batch lookup)
	 *
	 * @param array $lineIds Array of bank line IDs
	 * @return array<int, string|null> HTML links indexed by line ID
	 */
	public function getRelationsHtml(array $lineIds): array
	{
		$results = array();
		foreach ($lineIds as $lineId) {
			$results[(int) $lineId] = $this->getRelationHtml((int) $lineId);
		}
		return $results;
	}

	/**
	 * Check if a bank line has any related documents
	 *
	 * @param int $lineId Bank line ID
	 * @return bool True if has relations
	 */
	public function hasRelation(int $lineId): bool
	{
		return $this->getRelation($lineId) !== null;
	}
}
