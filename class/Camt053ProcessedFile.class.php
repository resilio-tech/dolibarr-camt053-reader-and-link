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
 * \file       class/Camt053ProcessedFile.class.php
 * \ingroup    camt053readerandlink
 * \brief      Tracking of downloaded/processed CAMT.053 files for idempotency.
 */

/**
 * Class Camt053ProcessedFile
 *
 * One row per CAMT.053 file that was fetched and processed. The unique index on
 * (file_hash, entity) prevents a file from ever being processed twice, even if
 * the remote deletion fails.
 */
class Camt053ProcessedFile
{
	const TABLE = 'camt053readerandlink_processedfile';

	/** @var DoliDb Database connection */
	private $db;

	/** @var string|null Error message */
	private $error;

	/** @var int Row id */
	public $id = 0;

	/** @var int Entity */
	public $entity;

	/** @var int Related SFTP config id */
	public $fk_config;

	/** @var string File name */
	public $filename;

	/** @var string SHA-256 of the file content */
	public $file_hash;

	/** @var int|null Resolved bank account id */
	public $fk_bank_account;

	/** @var string|null Statement reference (YYYYMM) */
	public $num_releve;

	/** @var int 1 if this is the monthly statement file */
	public $is_monthly = 0;

	/** @var int Number of auto-reconciled entries */
	public $nb_auto = 0;

	/** @var int Number of ambiguous entries */
	public $nb_ambiguous = 0;

	/** @var int Number of unmatched entries */
	public $nb_unmatched = 0;

	/** @var string Status (done|error) */
	public $status = 'done';

	/** @var string|null Error detail */
	public $error_detail;

	/**
	 * Constructor
	 *
	 * @param DoliDb $db Database connection
	 */
	public function __construct($db)
	{
		global $conf;
		$this->db = $db;
		$this->entity = isset($conf->entity) ? (int) $conf->entity : 1;
	}

	/**
	 * Whether a file with this hash was already processed in the current entity.
	 *
	 * @param string $hash SHA-256 hash
	 * @return bool
	 */
	public function isProcessed(string $hash): bool
	{
		$sql = "SELECT rowid FROM " . MAIN_DB_PREFIX . self::TABLE;
		$sql .= " WHERE file_hash = '" . $this->db->escape($hash) . "'";
		$sql .= " AND entity = " . ((int) $this->entity);

		$resql = $this->db->query($sql);
		if (!$resql) {
			// Can't determine the state: return false so the file is processed
			// rather than silently skipped (reconciliation is idempotent and the
			// unique index protects against a duplicate record).
			$this->error = 'Database error: ' . $this->db->lasterror();
			dol_syslog('CAMT053 cron: isProcessed query failed - ' . $this->error, LOG_ERR);
			return false;
		}

		return ($this->db->num_rows($resql) > 0);
	}

	/**
	 * Insert a processed-file record.
	 *
	 * @return int Row id on success, -1 on error
	 */
	public function create(): int
	{
		$now = dol_now();

		$sql = "INSERT INTO " . MAIN_DB_PREFIX . self::TABLE . " (";
		$sql .= "entity, fk_config, filename, file_hash, fk_bank_account, num_releve,";
		$sql .= " is_monthly, nb_auto, nb_ambiguous, nb_unmatched, status, error,";
		$sql .= " date_processed, date_creation";
		$sql .= ") VALUES (";
		$sql .= ((int) $this->entity);
		$sql .= ", " . ((int) $this->fk_config);
		$sql .= ", '" . $this->db->escape($this->filename) . "'";
		$sql .= ", '" . $this->db->escape($this->file_hash) . "'";
		$sql .= ", " . ($this->fk_bank_account ? (int) $this->fk_bank_account : 'NULL');
		$sql .= ", " . ($this->num_releve ? "'" . $this->db->escape($this->num_releve) . "'" : 'NULL');
		$sql .= ", " . ((int) $this->is_monthly);
		$sql .= ", " . ((int) $this->nb_auto);
		$sql .= ", " . ((int) $this->nb_ambiguous);
		$sql .= ", " . ((int) $this->nb_unmatched);
		$sql .= ", '" . $this->db->escape($this->status) . "'";
		$sql .= ", " . ($this->error_detail ? "'" . $this->db->escape(dol_trunc($this->error_detail, 2000, 'right', 'UTF-8', 1)) . "'" : 'NULL');
		$sql .= ", '" . $this->db->idate($now) . "'";
		$sql .= ", '" . $this->db->idate($now) . "'";
		$sql .= ")";

		if (!$this->db->query($sql)) {
			$this->error = 'Database error: ' . $this->db->lasterror();
			return -1;
		}

		$this->id = (int) $this->db->last_insert_id(MAIN_DB_PREFIX . self::TABLE);
		return $this->id;
	}

	/**
	 * Get last error message.
	 *
	 * @return string|null
	 */
	public function getError(): ?string
	{
		return $this->error;
	}
}
