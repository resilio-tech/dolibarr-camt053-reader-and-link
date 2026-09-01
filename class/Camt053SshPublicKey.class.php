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
 * \file       class/Camt053SshPublicKey.class.php
 * \ingroup    camt053readerandlink
 * \brief      Derive an OpenSSH public key from a private key.
 */

/**
 * Class Camt053SshPublicKey
 *
 * The ssh2 extension authenticates with ssh2_auth_pubkey_file(), which wants
 * the public key as a file too. Operators usually only keep the private key, so
 * this derives the public one from it using ext-openssl alone. Kept free of any
 * Dolibarr dependency so it can be tested on its own.
 *
 * RSA only: it is what the SFTP services this module targets use, and PHP's
 * OpenSSL binding exposes no usable raw material for ed25519.
 */
class Camt053SshPublicKey
{
	/**
	 * Build the "ssh-rsa AAAA..." line matching a private key.
	 *
	 * @param string $privateKey PEM private key
	 * @param string $passphrase Passphrase, empty when the key is not encrypted
	 * @return string|null Public key line, or null when it cannot be derived
	 */
	public static function fromPrivateKey(string $privateKey, string $passphrase = ''): ?string
	{
		if (!function_exists('openssl_pkey_get_private')) {
			return null;
		}

		$key = @openssl_pkey_get_private($privateKey, $passphrase);
		if ($key === false) {
			return null;
		}

		$details = @openssl_pkey_get_details($key);
		if (!is_array($details) || empty($details['rsa']['n']) || empty($details['rsa']['e'])) {
			return null;
		}

		$blob = self::field('ssh-rsa')
			. self::mpint($details['rsa']['e'])
			. self::mpint($details['rsa']['n']);

		return 'ssh-rsa ' . base64_encode($blob);
	}

	/**
	 * Whether a private key is in the OpenSSH container format.
	 *
	 * libssh2, which the ssh2 extension is built on, only reads the classic PEM
	 * encoding. Telling the two apart turns a cryptic authentication failure into
	 * an actionable message.
	 *
	 * @param string $privateKey Private key material
	 * @return bool
	 */
	public static function isOpenSshFormat(string $privateKey): bool
	{
		return strpos($privateKey, '-----BEGIN OPENSSH PRIVATE KEY-----') !== false;
	}

	/**
	 * Length-prefixed string, as used by the SSH wire format.
	 *
	 * @param string $value Raw bytes
	 * @return string
	 */
	private static function field(string $value): string
	{
		return pack('N', strlen($value)) . $value;
	}

	/**
	 * Length-prefixed multiple-precision integer.
	 *
	 * Unlike a plain string, a leading zero byte is required when the high bit is
	 * set, otherwise the value reads as negative.
	 *
	 * @param string $value Big-endian bytes
	 * @return string
	 */
	private static function mpint(string $value): string
	{
		if ($value !== '' && (ord($value[0]) & 0x80)) {
			$value = "\x00" . $value;
		}

		return self::field($value);
	}
}
