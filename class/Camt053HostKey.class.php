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
 * \file       class/Camt053HostKey.class.php
 * \ingroup    camt053readerandlink
 * \brief      Decide whether an SSH host key may be trusted.
 */

/**
 * Class Camt053HostKey
 *
 * ssh2_connect() accepts whatever key the server presents, so without this the
 * SFTP credentials go to whoever answers for the host. The fingerprint stored
 * on the account is the reference; an account that carries none learns the one
 * it first meets, which is the only moment the identity is taken on trust.
 * Kept free of any Dolibarr dependency so the rule can be tested on its own.
 */
class Camt053HostKey
{
	/** @var string The key matches the one stored on the account */
	const TRUSTED = 'trusted';

	/** @var string No key is stored yet: this one becomes the reference */
	const LEARN = 'learn';

	/** @var string The key differs from the stored one: refuse to authenticate */
	const MISMATCH = 'mismatch';

	/** @var string The server presented no readable key */
	const UNAVAILABLE = 'unavailable';

	/**
	 * Reduce a fingerprint to comparable hex, so the colon-separated form an
	 * operator copies out of ssh-keygen matches the bare one ssh2 returns.
	 *
	 * @param string|null $fingerprint Fingerprint in any separator style
	 * @return string Uppercase hex, empty when there is nothing usable
	 */
	public static function normalize($fingerprint): string
	{
		return (string) preg_replace('/[^0-9A-F]/', '', strtoupper((string) $fingerprint));
	}

	/**
	 * Whether a fingerprint is one this module can compare against.
	 *
	 * The ssh2 extension only produces MD5 (32 hex) and SHA-1 (40 hex), so
	 * anything else is a paste mistake, and letting it through would refuse
	 * every connection with a message about a changed key.
	 *
	 * @param string|null $fingerprint Candidate fingerprint
	 * @return bool
	 */
	public static function isValid($fingerprint): bool
	{
		$length = strlen(self::normalize($fingerprint));

		return ($length === 32 || $length === 40);
	}

	/**
	 * Compare the key a server presented with the one stored on the account.
	 *
	 * @param string|null $expected Fingerprint stored on the account
	 * @param string|null $actual   Fingerprint the server presented
	 * @return string One of the class constants
	 */
	public static function verdict($expected, $actual): string
	{
		if (!self::isValid($actual)) {
			return self::UNAVAILABLE;
		}

		$actual = self::normalize($actual);

		$expected = self::normalize($expected);
		if ($expected === '') {
			return self::LEARN;
		}

		return hash_equals($expected, $actual) ? self::TRUSTED : self::MISMATCH;
	}

	/**
	 * Colon-separated form, as ssh-keygen prints it.
	 *
	 * @param string|null $fingerprint Fingerprint
	 * @return string Empty when there is nothing to show
	 */
	public static function format($fingerprint): string
	{
		$value = self::normalize($fingerprint);
		if ($value === '') {
			return '';
		}

		return implode(':', str_split($value, 2));
	}
}
