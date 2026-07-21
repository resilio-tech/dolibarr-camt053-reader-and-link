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
 * \file       class/SftpFileTransport.class.php
 * \ingroup    camt053readerandlink
 * \brief      SFTP transport to list, download and delete files on a
 *             PostFinance MFTPF server.
 *
 * Built on the ssh2 PHP extension, which is what Dolibarr core itself uses for
 * SFTP (see core/lib/ftp.lib.php): the module ships no third-party library.
 *
 * IMPORTANT: PostFinance locks the account after 3 failed logins. This class
 * therefore performs a SINGLE login attempt and never retries.
 */

require_once __DIR__ . '/Camt053SftpConfig.class.php';
require_once __DIR__ . '/Camt053SshPublicKey.class.php';

/**
 * Class SftpFileTransport
 *
 * Thin wrapper around the ssh2 extension driven by a Camt053SftpConfig.
 */
class SftpFileTransport
{
	/** @var Camt053SftpConfig Connection config */
	private $config;

	/** @var resource|null Active SSH session */
	private $session;

	/** @var resource|null Active SFTP subsystem */
	private $sftp;

	/** @var string|null Last error message */
	private $error;

	/** @var int Connection timeout in seconds */
	private $timeout = 30;

	/**
	 * Constructor
	 *
	 * @param Camt053SftpConfig $config Connection config (decrypted secrets)
	 */
	public function __construct(Camt053SftpConfig $config)
	{
		$this->config = $config;
	}

	/**
	 * Open the SFTP connection and authenticate.
	 * Performs a SINGLE login attempt to avoid account lockout.
	 *
	 * @return bool True on success, false otherwise (see getError())
	 */
	public function connect(): bool
	{
		$this->error = null;

		if (!function_exists('ssh2_connect')) {
			$this->error = 'The PHP ssh2 extension is required for SFTP and is not installed on this server';
			return false;
		}

		$session = @ssh2_connect($this->config->host, (int) $this->config->port);
		if ($session === false) {
			$this->error = 'Unable to reach ' . $this->config->host . ':' . $this->config->port;
			return false;
		}

		if (!$this->authenticate($session)) {
			return false;
		}

		$sftp = @ssh2_sftp($session);
		if ($sftp === false) {
			$this->error = 'Authenticated, but the server refused to open the SFTP subsystem';
			return false;
		}

		$this->session = $session;
		$this->sftp = $sftp;

		return true;
	}

	/**
	 * Perform the single authentication attempt allowed by the remote policy.
	 *
	 * @param resource $session Open SSH session
	 * @return bool True when authenticated (see getError() otherwise)
	 */
	private function authenticate($session): bool
	{
		if ($this->config->auth_type === 'password') {
			if (empty($this->config->password)) {
				$this->error = 'No password configured';
				return false;
			}
			if (!@ssh2_auth_password($session, $this->config->username, $this->config->password)) {
				$this->error = 'SFTP authentication failed for user ' . $this->config->username;
				return false;
			}

			return true;
		}

		return $this->authenticateWithKey($session);
	}

	/**
	 * Authenticate with the configured private key.
	 *
	 * ssh2_auth_pubkey_file() reads both keys from disk, so the material is
	 * written to a private directory for the duration of the handshake and
	 * removed immediately afterwards, whatever the outcome.
	 *
	 * @param resource $session Open SSH session
	 * @return bool
	 */
	private function authenticateWithKey($session): bool
	{
		if (empty($this->config->private_key)) {
			$this->error = 'No private key configured';
			return false;
		}

		$privateKey = (string) $this->config->private_key;
		$passphrase = (string) $this->config->private_key_passphrase;

		if (Camt053SshPublicKey::isOpenSshFormat($privateKey)) {
			$this->error = 'The private key is in the OpenSSH format, which the ssh2 extension cannot read.'
				. ' Convert it with: ssh-keygen -p -m PEM -f <keyfile>';
			return false;
		}

		$publicKey = (string) $this->config->public_key;
		if ($publicKey === '') {
			$publicKey = (string) Camt053SshPublicKey::fromPrivateKey($privateKey, $passphrase);
		}
		if ($publicKey === '') {
			$this->error = 'Unable to derive the public key from the private key.'
				. ' Paste the matching public key in the configuration (RSA keys only).';
			return false;
		}

		$paths = $this->writeKeyPair($privateKey, $publicKey);
		if ($paths === null) {
			return false;
		}

		try {
			$authenticated = @ssh2_auth_pubkey_file(
				$session,
				$this->config->username,
				$paths['public'],
				$paths['private'],
				$passphrase !== '' ? $passphrase : null
			);
		} finally {
			foreach ($paths as $path) {
				@unlink($path);
			}
		}

		if (!$authenticated) {
			$this->error = 'SFTP authentication failed for user ' . $this->config->username;
			return false;
		}

		return true;
	}

	/**
	 * Write the key pair to a private directory readable by this process only.
	 *
	 * @param string $privateKey Private key material
	 * @param string $publicKey  Matching OpenSSH public key line
	 * @return array{private:string,public:string}|null Paths, or null on error
	 */
	private function writeKeyPair(string $privateKey, string $publicKey): ?array
	{
		$dir = DOL_DATA_ROOT . '/camt053readerandlink/keys';
		if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
			$this->error = 'Unable to create the key directory ' . $dir;
			return null;
		}
		@chmod($dir, 0700);

		$base = $dir . '/' . uniqid('sftp', true);
		$paths = array('private' => $base, 'public' => $base . '.pub');

		foreach (array($paths['private'] => $privateKey, $paths['public'] => $publicKey) as $path => $content) {
			// Create empty and lock down the permissions before any key material
			// touches the file, so it is never briefly world-readable.
			if (@file_put_contents($path, '') === false || !@chmod($path, 0600)
				|| @file_put_contents($path, $content) !== strlen($content)) {
				foreach ($paths as $written) {
					@unlink($written);
				}
				$this->error = 'Unable to write the key material to ' . $dir;
				return null;
			}
		}

		return $paths;
	}

	/**
	 * List files in the configured remote directory.
	 *
	 * @param string|null $pattern Optional PCRE pattern (with delimiters) to keep only matching names
	 * @return array<int, string>|null File names (no path), or null on error
	 */
	public function listFiles(?string $pattern = null): ?array
	{
		if (!$this->ensureConnected()) {
			return null;
		}

		$dir = $this->config->remote_dir;
		$names = @scandir($this->streamPath($dir));
		if ($names === false) {
			$this->error = 'Unable to list remote directory: ' . $dir;
			return null;
		}

		$files = array();
		foreach ($names as $name) {
			if ($name === '.' || $name === '..') {
				continue;
			}
			if (is_dir($this->streamPath(rtrim($dir, '/') . '/' . $name))) {
				continue;
			}
			if ($pattern !== null && $pattern !== '' && !preg_match($pattern, $name)) {
				continue;
			}
			$files[] = $name;
		}

		sort($files);
		return $files;
	}

	/**
	 * Download a remote file content into a string.
	 *
	 * @param string $name Remote file name (relative to the configured directory)
	 * @return string|null File content, or null on error
	 */
	public function getContent(string $name): ?string
	{
		if (!$this->ensureConnected()) {
			return null;
		}

		$content = @file_get_contents($this->streamPath($this->remotePath($name)));
		if ($content === false) {
			$this->error = 'Unable to download remote file: ' . $name;
			return null;
		}

		return $content;
	}

	/**
	 * Delete a remote file.
	 *
	 * @param string $name Remote file name (relative to the configured directory)
	 * @return bool True on success, false otherwise
	 */
	public function delete(string $name): bool
	{
		if (!$this->ensureConnected()) {
			return false;
		}

		if (!@ssh2_sftp_unlink($this->sftp, $this->remotePath($name))) {
			$this->error = 'Unable to delete remote file: ' . $name;
			return false;
		}

		return true;
	}

	/**
	 * Close the connection.
	 *
	 * @return void
	 */
	public function disconnect(): void
	{
		if ($this->session !== null && function_exists('ssh2_disconnect')) {
			@ssh2_disconnect($this->session);
		}
		$this->sftp = null;
		$this->session = null;
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
	 * Build the full remote path for a file name.
	 *
	 * @param string $name File name
	 * @return string Full remote path
	 */
	private function remotePath(string $name): string
	{
		return rtrim($this->config->remote_dir, '/') . '/' . ltrim($name, '/');
	}

	/**
	 * Turn a remote path into the stream wrapper URL the ssh2 extension exposes.
	 *
	 * The resource id has to be cast to int: that is the documented way to
	 * address an open SFTP subsystem, and it is what Dolibarr core does too.
	 *
	 * @param string $path Absolute or relative remote path
	 * @return string
	 */
	private function streamPath(string $path): string
	{
		return 'ssh2.sftp://' . (int) $this->sftp . '/' . ltrim($path, '/');
	}

	/**
	 * Ensure the connection is open.
	 *
	 * @return bool
	 */
	private function ensureConnected(): bool
	{
		if ($this->sftp === null) {
			$this->error = 'Not connected';
			return false;
		}
		return true;
	}

	/**
	 * Extract CAMT.053 XML payloads from a downloaded file.
	 * Handles raw .xml, gzip (.gz), tar.gz (.tar.gz/.tgz) and zip archives.
	 *
	 * @param string $name    File name (used to detect the format)
	 * @param string $content Raw file content
	 * @return array<int, string> List of XML payloads (empty if none found)
	 */
	public static function extractXmlPayloads(string $name, string $content): array
	{
		$lower = strtolower($name);

		// Plain XML
		if (substr($lower, -4) === '.xml') {
			return array($content);
		}

		// Zip archive (ISO test platform delivers result files as .zip)
		if (substr($lower, -4) === '.zip') {
			return self::extractFromZip($content);
		}

		// tar.gz / tgz (PostFinance camt.053 with TIFF images)
		if (substr($lower, -7) === '.tar.gz' || substr($lower, -4) === '.tgz') {
			$tar = @gzdecode($content);
			if ($tar === false) {
				return array();
			}
			return self::extractXmlFromTar($tar);
		}

		// Plain gzip
		if (substr($lower, -3) === '.gz') {
			$decoded = @gzdecode($content);
			return ($decoded !== false) ? array($decoded) : array();
		}

		// Fallback: looks like XML content
		if (strpos($content, '<Document') !== false || strpos($content, '<?xml') !== false) {
			return array($content);
		}

		return array();
	}

	/**
	 * Extract .xml members from a ZIP archive content.
	 *
	 * @param string $content Zip binary content
	 * @return array<int, string> XML payloads
	 */
	private static function extractFromZip(string $content): array
	{
		if (!class_exists('ZipArchive')) {
			return array();
		}

		$payloads = array();
		$tmp = tempnam(sys_get_temp_dir(), 'camt053zip');
		if ($tmp === false) {
			return array();
		}

		file_put_contents($tmp, $content);
		$zip = new ZipArchive();
		if ($zip->open($tmp) === true) {
			for ($i = 0; $i < $zip->numFiles; $i++) {
				$entryName = $zip->getNameIndex($i);
				if ($entryName === false || substr(strtolower($entryName), -4) !== '.xml') {
					continue;
				}
				$data = $zip->getFromIndex($i);
				if ($data !== false) {
					$payloads[] = $data;
				}
			}
			$zip->close();
		}

		@unlink($tmp);
		return $payloads;
	}

	/**
	 * Extract .xml members from an uncompressed TAR archive content.
	 *
	 * @param string $tar Tar binary content
	 * @return array<int, string> XML payloads
	 */
	private static function extractXmlFromTar(string $tar): array
	{
		$payloads = array();
		$offset = 0;
		$length = strlen($tar);

		while ($offset + 512 <= $length) {
			$header = substr($tar, $offset, 512);
			$offset += 512;

			// Two consecutive zero blocks mark the end of the archive.
			if (trim($header) === '') {
				break;
			}

			$entryName = trim(substr($header, 0, 100));
			$sizeOctal = trim(substr($header, 124, 12));
			$size = octdec($sizeOctal !== '' ? $sizeOctal : '0');

			$data = substr($tar, $offset, $size);
			// Each entry is padded to a 512-byte boundary.
			$offset += (int) (ceil($size / 512) * 512);

			if ($size > 0 && substr(strtolower($entryName), -4) === '.xml') {
				$payloads[] = $data;
			}
		}

		return $payloads;
	}
}
