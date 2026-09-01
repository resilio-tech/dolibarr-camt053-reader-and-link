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
 * \file       test/phpunit/Camt053HostKeyTest.php
 * \ingroup    camt053readerandlink
 * \brief      PHPUnit tests for host key trust. A wrong verdict either hands the
 *             SFTP credentials to an impostor or locks the job out of the bank.
 */

use PHPUnit\Framework\TestCase;

require_once dirname(__FILE__) . '/../../class/Camt053HostKey.class.php';

/**
 * Class Camt053HostKeyTest
 */
class Camt053HostKeyTest extends TestCase
{
	/** @var string A SHA-1 fingerprint as ssh2_fingerprint() returns it */
	const SHA1 = 'A1B2C3D4E5F60718293A4B5C6D7E8F9012345678';

	/**
	 * The colon-separated form an operator copies out of ssh-keygen compares
	 * equal to the bare one the extension returns.
	 *
	 * @return void
	 */
	public function testSeparatorsAndCaseAreIgnored(): void
	{
		$this->assertSame(self::SHA1, Camt053HostKey::normalize(strtolower(Camt053HostKey::format(self::SHA1))));
		$this->assertSame(
			Camt053HostKey::TRUSTED,
			Camt053HostKey::verdict(Camt053HostKey::format(self::SHA1), strtolower(self::SHA1))
		);
	}

	/**
	 * @return void
	 */
	public function testAnAccountWithoutAFingerprintLearnsTheOneItMeets(): void
	{
		$this->assertSame(Camt053HostKey::LEARN, Camt053HostKey::verdict('', self::SHA1));
		$this->assertSame(Camt053HostKey::LEARN, Camt053HostKey::verdict(null, self::SHA1));
	}

	/**
	 * The whole point: a server presenting another key is refused, before a
	 * single credential is sent to it.
	 *
	 * @return void
	 */
	public function testAChangedKeyIsRefused(): void
	{
		$other = str_repeat('AB', 20);

		$this->assertSame(Camt053HostKey::MISMATCH, Camt053HostKey::verdict(self::SHA1, $other));
	}

	/**
	 * A truncated fingerprint must not pass as a prefix of the other, whichever
	 * side it is truncated on.
	 *
	 * @return void
	 */
	public function testATruncatedFingerprintIsNeverTrusted(): void
	{
		$this->assertNotSame(Camt053HostKey::TRUSTED, Camt053HostKey::verdict(self::SHA1, substr(self::SHA1, 0, 30)));
		$this->assertNotSame(Camt053HostKey::TRUSTED, Camt053HostKey::verdict(substr(self::SHA1, 0, 30), self::SHA1));
	}

	/**
	 * No key read means no decision: authenticating anyway is what this class
	 * exists to prevent.
	 *
	 * @return void
	 */
	public function testAnUnreadableKeyIsNeverTrusted(): void
	{
		$this->assertSame(Camt053HostKey::UNAVAILABLE, Camt053HostKey::verdict(self::SHA1, ''));
		$this->assertSame(Camt053HostKey::UNAVAILABLE, Camt053HostKey::verdict('', false));
		$this->assertSame(Camt053HostKey::UNAVAILABLE, Camt053HostKey::verdict('', 'no hex here'));
	}

	/**
	 * @return void
	 */
	public function testOnlyMd5AndSha1LengthsAreAccepted(): void
	{
		$this->assertTrue(Camt053HostKey::isValid(self::SHA1));
		$this->assertTrue(Camt053HostKey::isValid(Camt053HostKey::format(self::SHA1)));
		$this->assertTrue(Camt053HostKey::isValid(str_repeat('0F', 16)));

		$this->assertFalse(Camt053HostKey::isValid(''));
		$this->assertFalse(Camt053HostKey::isValid('SHA256:2f4c9a+Bd/8='), 'A SHA-256 base64 fingerprint is not comparable');
		$this->assertFalse(Camt053HostKey::isValid(substr(self::SHA1, 0, 30)));
	}

	/**
	 * @return void
	 */
	public function testFormatPrintsPairsAndNothingOnEmpty(): void
	{
		$this->assertSame('A1:B2:C3', Camt053HostKey::format('a1b2c3'));
		$this->assertSame('', Camt053HostKey::format(''));
		$this->assertSame('', Camt053HostKey::format(null));
	}
}
