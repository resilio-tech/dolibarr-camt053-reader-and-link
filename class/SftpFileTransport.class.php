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
 * \brief      SFTP transport (phpseclib 3) to list, download and delete files
 *             on a PostFinance MFTPF server.
 *
 * IMPORTANT: PostFinance locks the account after 3 failed logins. This class
 * therefore performs a SINGLE login attempt and never retries.
 */

require_once __DIR__ . '/../libs/autoload.php';
require_once __DIR__ . '/Camt053SftpConfig.class.php';

/**
 * Class SftpFileTransport
 *
 * Thin wrapper around phpseclib3\Net\SFTP driven by a Camt053SftpConfig.
 */
class SftpFileTransport
{
	/** @var Camt053SftpConfig Connection config */
	private $config;

	/** @var \phpseclib3\Net\SFTP|null Active SFTP client */
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

		try {
			$this->sftp = new \phpseclib3\Net\SFTP($this->config->host, (int) $this->config->port, $this->timeout);

			$credential = $this->buildCredential();
			if ($credential === null) {
				return false;
			}

			if (!$this->sftp->login($this->config->username, $credential)) {
				$this->error = 'SFTP authentication failed for user ' . $this->config->username;
				$this->sftp = null;
				return false;
			}
		} catch (\Throwable $e) {
			$this->error = 'SFTP connection error: ' . $e->getMessage();
			$this->sftp = null;
			return false;
		}

		return true;
	}

	/**
	 * Build the login credential (private key object or password) from config.
	 *
	 * @return \phpseclib3\Crypt\Common\PrivateKey|string|null Credential, or null on error
	 */
	private function buildCredential()
	{
		if ($this->config->auth_type === 'password') {
			if (empty($this->config->password)) {
				$this->error = 'No password configured';
				return null;
			}
			return $this->config->password;
		}

		// Key authentication
		if (empty($this->config->private_key)) {
			$this->error = 'No private key configured';
			return null;
		}

		try {
			$passphrase = (string) $this->config->private_key_passphrase;
			return \phpseclib3\Crypt\PublicKeyLoader::load($this->config->private_key, $passphrase !== '' ? $passphrase : false);
		} catch (\Throwable $e) {
			$this->error = 'Unable to load private key: ' . $e->getMessage();
			return null;
		}
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
		$names = $this->sftp->nlist($dir);
		if ($names === false) {
			$this->error = 'Unable to list remote directory: ' . $dir;
			return null;
		}

		$files = array();
		foreach ($names as $name) {
			if ($name === '.' || $name === '..') {
				continue;
			}
			if ($this->sftp->is_dir($dir . '/' . $name)) {
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

		$content = $this->sftp->get($this->remotePath($name));
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

		if (!$this->sftp->delete($this->remotePath($name), false)) {
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
		if ($this->sftp !== null) {
			try {
				$this->sftp->disconnect();
			} catch (\Throwable $e) {
				// ignore
			}
			$this->sftp = null;
		}
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
