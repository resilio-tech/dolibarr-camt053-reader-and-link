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
 * \file       class/Camt053SftpConfig.class.php
 * \ingroup    camt053readerandlink
 * \brief      Per-account SFTP connection config (PostFinance MFTPF).
 *             Sensitive fields (SSH private key, passphrase, password) are
 *             stored encrypted at rest with dolEncrypt() and exposed decrypted.
 */

require_once DOL_DOCUMENT_ROOT . '/core/lib/security.lib.php';

/**
 * Class Camt053SftpConfig
 *
 * CRUD for the SFTP config table. Object properties always hold plaintext;
 * encryption/decryption happens only at the database boundary.
 */
class Camt053SftpConfig
{
	const TABLE = 'camt053readerandlink_sftpconfig';

	/** @var DoliDb Database connection */
	private $db;

	/** @var string|null Error message */
	private $error;

	/** @var int Row id (0 when not persisted) */
	public $id = 0;

	/** @var int Dolibarr entity */
	public $entity;

	/** @var string Unique reference / short code */
	public $ref;

	/** @var string Human label */
	public $label;

	/** @var int 1 = active, 0 = disabled */
	public $active = 1;

	/** @var string SFTP host (e.g. mftp1.postfinance.ch) */
	public $host;

	/** @var int SFTP port (PostFinance MFTPF = 8022) */
	public $port = 8022;

	/** @var string SFTP username (MFTPF User ID) */
	public $username;

	/** @var string Authentication type: 'key' or 'password' */
	public $auth_type = 'key';

	/** @var string|null SSH private key (PEM), plaintext in memory, encrypted at rest */
	public $private_key;

	/** @var string|null Passphrase for the private key, plaintext in memory, encrypted at rest */
	public $private_key_passphrase;

	/** @var string|null SFTP password (non-PostFinance servers), plaintext in memory, encrypted at rest */
	public $password;

	/** @var string Remote directory to read (yellow-net-reports, or -t for test) */
	public $remote_dir = 'yellow-net-reports';

	/** @var string|null Regex matching daily CAMT.053 file names */
	public $daily_pattern;

	/** @var string|null Regex matching the monthly CAMT.053 file name (triggers Zulip report) */
	public $monthly_pattern;

	/** @var string Action after a successful download: 'delete' or 'leave' */
	public $post_download_action = 'delete';

	/** @var int|null Fallback Dolibarr bank account when the IBAN cannot be resolved */
	public $fk_default_bank_account;

	/** @var int|null Last run timestamp */
	public $last_run;

	/** @var string|null Last run status message */
	public $last_status;

	/** @var int|null Creation timestamp */
	public $date_creation;

	/** @var int|null Author user id */
	public $fk_user_creat;

	/** @var int|null Last modifier user id */
	public $fk_user_modif;

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
	 * Validate required fields before persisting.
	 *
	 * @return bool True if valid, false otherwise (see getError())
	 */
	public function validate(): bool
	{
		$this->error = null;

		if (empty(trim((string) $this->ref))) {
			$this->error = 'Ref is required';
			return false;
		}
		if (empty(trim((string) $this->host))) {
			$this->error = 'Host is required';
			return false;
		}
		if (empty(trim((string) $this->username))) {
			$this->error = 'Username is required';
			return false;
		}
		if ($this->auth_type === 'key' && empty(trim((string) $this->private_key))) {
			$this->error = 'A private key is required for key authentication';
			return false;
		}
		if ($this->auth_type === 'password' && empty(trim((string) $this->password))) {
			$this->error = 'A password is required for password authentication';
			return false;
		}
		if (!in_array($this->post_download_action, array('delete', 'leave'), true)) {
			$this->error = 'Invalid post download action';
			return false;
		}

		return true;
	}

	/**
	 * Insert a new config row.
	 *
	 * @param User|null $user User performing the action
	 * @return int Row id on success, -1 on error
	 */
	public function create($user = null): int
	{
		if (!$this->validate()) {
			return -1;
		}

		$now = dol_now();
		$this->date_creation = $now;
		$this->fk_user_creat = ($user && !empty($user->id)) ? (int) $user->id : null;

		$sql = "INSERT INTO " . MAIN_DB_PREFIX . self::TABLE . " (";
		$sql .= "entity, ref, label, active, host, port, username, auth_type,";
		$sql .= " private_key, private_key_passphrase, password, remote_dir,";
		$sql .= " daily_pattern, monthly_pattern, post_download_action,";
		$sql .= " fk_default_bank_account, date_creation, fk_user_creat";
		$sql .= ") VALUES (";
		$sql .= ((int) $this->entity);
		$sql .= ", " . $this->quote($this->ref);
		$sql .= ", " . $this->quote($this->label);
		$sql .= ", " . ((int) $this->active);
		$sql .= ", " . $this->quote($this->host);
		$sql .= ", " . ((int) $this->port);
		$sql .= ", " . $this->quote($this->username);
		$sql .= ", " . $this->quote($this->auth_type);
		$sql .= ", " . $this->quote($this->encrypt($this->private_key));
		$sql .= ", " . $this->quote($this->encrypt($this->private_key_passphrase));
		$sql .= ", " . $this->quote($this->encrypt($this->password));
		$sql .= ", " . $this->quote($this->remote_dir);
		$sql .= ", " . $this->quote($this->daily_pattern);
		$sql .= ", " . $this->quote($this->monthly_pattern);
		$sql .= ", " . $this->quote($this->post_download_action);
		$sql .= ", " . ($this->fk_default_bank_account ? (int) $this->fk_default_bank_account : 'NULL');
		$sql .= ", '" . $this->db->idate($now) . "'";
		$sql .= ", " . ($this->fk_user_creat ? (int) $this->fk_user_creat : 'NULL');
		$sql .= ")";

		if (!$this->db->query($sql)) {
			$this->error = 'Database error: ' . $this->db->lasterror();
			return -1;
		}

		$this->id = (int) $this->db->last_insert_id(MAIN_DB_PREFIX . self::TABLE);
		return $this->id;
	}

	/**
	 * Update an existing config row.
	 *
	 * @param User|null $user User performing the action
	 * @return int 1 on success, -1 on error
	 */
	public function update($user = null): int
	{
		if (empty($this->id)) {
			$this->error = 'Cannot update a config without id';
			return -1;
		}
		if (!$this->validate()) {
			return -1;
		}

		$this->fk_user_modif = ($user && !empty($user->id)) ? (int) $user->id : null;

		$sql = "UPDATE " . MAIN_DB_PREFIX . self::TABLE . " SET";
		$sql .= " ref = " . $this->quote($this->ref);
		$sql .= ", label = " . $this->quote($this->label);
		$sql .= ", active = " . ((int) $this->active);
		$sql .= ", host = " . $this->quote($this->host);
		$sql .= ", port = " . ((int) $this->port);
		$sql .= ", username = " . $this->quote($this->username);
		$sql .= ", auth_type = " . $this->quote($this->auth_type);
		$sql .= ", private_key = " . $this->quote($this->encrypt($this->private_key));
		$sql .= ", private_key_passphrase = " . $this->quote($this->encrypt($this->private_key_passphrase));
		$sql .= ", password = " . $this->quote($this->encrypt($this->password));
		$sql .= ", remote_dir = " . $this->quote($this->remote_dir);
		$sql .= ", daily_pattern = " . $this->quote($this->daily_pattern);
		$sql .= ", monthly_pattern = " . $this->quote($this->monthly_pattern);
		$sql .= ", post_download_action = " . $this->quote($this->post_download_action);
		$sql .= ", fk_default_bank_account = " . ($this->fk_default_bank_account ? (int) $this->fk_default_bank_account : 'NULL');
		$sql .= ", fk_user_modif = " . ($this->fk_user_modif ? (int) $this->fk_user_modif : 'NULL');
		$sql .= " WHERE rowid = " . ((int) $this->id);

		if (!$this->db->query($sql)) {
			$this->error = 'Database error: ' . $this->db->lasterror();
			return -1;
		}

		return 1;
	}

	/**
	 * Fetch a config by id. Sensitive fields are decrypted into properties.
	 *
	 * @param int $id Row id
	 * @return int 1 if found, 0 if not found, -1 on error
	 */
	public function fetch(int $id): int
	{
		// Scoped like fetchAll(): a config belonging to another entity must never
		// be readable, let alone usable to open an SFTP session with its
		// credentials.
		$sql = "SELECT " . $this->fieldList();
		$sql .= " FROM " . MAIN_DB_PREFIX . self::TABLE;
		$sql .= " WHERE rowid = " . ((int) $id);
		$sql .= " AND entity IN (" . getEntity(self::TABLE) . ")";

		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = 'Database error: ' . $this->db->lasterror();
			return -1;
		}

		$obj = $this->db->fetch_object($resql);
		if (!$obj) {
			$this->error = 'SFTP configuration not found: ' . ((int) $id);
			return 0;
		}

		$this->setFromObject($obj);
		return 1;
	}

	/**
	 * Fetch all configs.
	 *
	 * @param bool $onlyActive Limit to active configs
	 * @return array<int, Camt053SftpConfig> List of configs (empty on error)
	 */
	public function fetchAll(bool $onlyActive = false): array
	{
		global $conf;

		$sql = "SELECT " . $this->fieldList();
		$sql .= " FROM " . MAIN_DB_PREFIX . self::TABLE;
		$sql .= " WHERE entity IN (" . getEntity(self::TABLE) . ")";
		if ($onlyActive) {
			$sql .= " AND active = 1";
		}
		$sql .= " ORDER BY ref ASC";

		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = 'Database error: ' . $this->db->lasterror();
			return array();
		}

		$list = array();
		while ($obj = $this->db->fetch_object($resql)) {
			$cfg = new self($this->db);
			$cfg->setFromObject($obj);
			$list[$cfg->id] = $cfg;
		}

		return $list;
	}

	/**
	 * Delete a config row.
	 *
	 * @param User|null $user User performing the action
	 * @return int 1 on success, -1 on error
	 */
	public function delete($user = null): int
	{
		if (empty($this->id)) {
			$this->error = 'Cannot delete a config without id';
			return -1;
		}

		$sql = "DELETE FROM " . MAIN_DB_PREFIX . self::TABLE . " WHERE rowid = " . ((int) $this->id);
		if (!$this->db->query($sql)) {
			$this->error = 'Database error: ' . $this->db->lasterror();
			return -1;
		}

		return 1;
	}

	/**
	 * Record the result of a run (last_run / last_status).
	 *
	 * @param string $status Short status message
	 * @return int 1 on success, -1 on error
	 */
	public function recordRun(string $status): int
	{
		if (empty($this->id)) {
			$this->error = 'Cannot record a run on a config without id';
			return -1;
		}

		$now = dol_now();
		$sql = "UPDATE " . MAIN_DB_PREFIX . self::TABLE . " SET";
		$sql .= " last_run = '" . $this->db->idate($now) . "'";
		$sql .= ", last_status = " . $this->quote(dol_trunc($status, 250, 'right', 'UTF-8', 1));
		$sql .= " WHERE rowid = " . ((int) $this->id);

		if (!$this->db->query($sql)) {
			$this->error = 'Database error: ' . $this->db->lasterror();
			return -1;
		}

		$this->last_run = $now;
		$this->last_status = $status;
		return 1;
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

	/**
	 * Column list used by SELECT queries.
	 *
	 * @return string
	 */
	private function fieldList(): string
	{
		return "rowid, entity, ref, label, active, host, port, username, auth_type,"
			. " private_key, private_key_passphrase, password, remote_dir,"
			. " daily_pattern, monthly_pattern, post_download_action,"
			. " fk_default_bank_account, last_run, last_status, date_creation,"
			. " fk_user_creat, fk_user_modif";
	}

	/**
	 * Hydrate this object from a DB row, decrypting sensitive fields.
	 *
	 * @param object $obj Row object
	 * @return void
	 */
	private function setFromObject(object $obj): void
	{
		$this->id = (int) $obj->rowid;
		$this->entity = (int) $obj->entity;
		$this->ref = $obj->ref;
		$this->label = $obj->label;
		$this->active = (int) $obj->active;
		$this->host = $obj->host;
		$this->port = (int) $obj->port;
		$this->username = $obj->username;
		$this->auth_type = $obj->auth_type;
		$this->private_key = $this->decrypt($obj->private_key);
		$this->private_key_passphrase = $this->decrypt($obj->private_key_passphrase);
		$this->password = $this->decrypt($obj->password);
		$this->remote_dir = $obj->remote_dir;
		$this->daily_pattern = $obj->daily_pattern;
		$this->monthly_pattern = $obj->monthly_pattern;
		$this->post_download_action = $obj->post_download_action;
		$this->fk_default_bank_account = $obj->fk_default_bank_account !== null ? (int) $obj->fk_default_bank_account : null;
		$this->last_run = !empty($obj->last_run) ? $this->db->jdate($obj->last_run) : null;
		$this->last_status = $obj->last_status;
		$this->date_creation = !empty($obj->date_creation) ? $this->db->jdate($obj->date_creation) : null;
		$this->fk_user_creat = $obj->fk_user_creat !== null ? (int) $obj->fk_user_creat : null;
		$this->fk_user_modif = $obj->fk_user_modif !== null ? (int) $obj->fk_user_modif : null;
	}

	/**
	 * Encrypt a sensitive value for storage (no-op on empty).
	 *
	 * @param string|null $value Plaintext value
	 * @return string|null Encrypted value (dolcrypt:...) or original empty value
	 */
	private function encrypt($value)
	{
		if ($value === null || $value === '') {
			return $value;
		}
		return dolEncrypt($value);
	}

	/**
	 * Decrypt a stored value (no-op on empty or non-encrypted).
	 *
	 * @param string|null $value Stored value
	 * @return string|null Plaintext value
	 */
	private function decrypt($value)
	{
		if ($value === null || $value === '') {
			return $value;
		}
		return dolDecrypt($value);
	}

	/**
	 * SQL-quote a nullable string, escaping its content.
	 *
	 * @param string|null $value Value to quote
	 * @return string "'escaped'" or "NULL"
	 */
	private function quote($value): string
	{
		if ($value === null || $value === '') {
			return 'NULL';
		}
		return "'" . $this->db->escape($value) . "'";
	}
}
