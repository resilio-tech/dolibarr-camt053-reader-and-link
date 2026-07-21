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
 * \file       test/phpunit/Camt053SshPublicKeyTest.php
 * \ingroup    camt053readerandlink
 * \brief      PHPUnit tests for the OpenSSH public key derivation used to
 *             authenticate through the ssh2 extension.
 */

use PHPUnit\Framework\TestCase;

require_once dirname(__FILE__) . '/../../class/Camt053SshPublicKey.class.php';

/**
 * Class Camt053SshPublicKeyTest
 */
class Camt053SshPublicKeyTest extends TestCase
{
	/**
	 * Generate an RSA key pair for the test.
	 *
	 * @param string $passphrase Passphrase, empty for an unencrypted key
	 * @return array{0:string,1:string} [private PEM, expected public key line]
	 */
	private function generateKey(string $passphrase = ''): array
	{
		$resource = openssl_pkey_new(array(
			'private_key_bits' => 2048,
			'private_key_type' => OPENSSL_KEYTYPE_RSA,
		));
		$this->assertNotFalse($resource, 'OpenSSL must be able to generate a key');

		openssl_pkey_export($resource, $pem, $passphrase !== '' ? $passphrase : null);

		return array($pem, '');
	}

	/**
	 * The derived line is a well-formed ssh-rsa key.
	 *
	 * @return void
	 */
	public function testDerivesAnSshRsaLine(): void
	{
		list($pem) = $this->generateKey();

		$public = Camt053SshPublicKey::fromPrivateKey($pem);

		$this->assertNotNull($public);
		$this->assertStringStartsWith('ssh-rsa AAAAB3NzaC1yc2E', $public);
	}

	/**
	 * The blob decodes back to the fields ssh expects, in order.
	 *
	 * @return void
	 */
	public function testBlobCarriesTheKeyTypeThenExponentThenModulus(): void
	{
		list($pem) = $this->generateKey();

		$blob = base64_decode(substr(Camt053SshPublicKey::fromPrivateKey($pem), strlen('ssh-rsa ')));

		$offset = 0;
		$read = function () use ($blob, &$offset) {
			$len = unpack('N', substr($blob, $offset, 4))[1];
			$offset += 4;
			$value = substr($blob, $offset, $len);
			$offset += $len;
			return $value;
		};

		$this->assertSame('ssh-rsa', $read(), 'First field is the key type');

		$exponent = $read();
		$modulus = $read();

		$details = openssl_pkey_get_details(openssl_pkey_get_private($pem));
		$this->assertSame(ltrim($details['rsa']['e'], "\x00"), ltrim($exponent, "\x00"));
		$this->assertSame(ltrim($details['rsa']['n'], "\x00"), ltrim($modulus, "\x00"));
		$this->assertSame(strlen($blob), $offset, 'No trailing bytes');
	}

	/**
	 * A modulus always has its high bit set, so it must be padded: without the
	 * leading zero ssh reads it as a negative number and authentication fails.
	 *
	 * @return void
	 */
	public function testModulusIsPaddedWhenItsHighBitIsSet(): void
	{
		list($pem) = $this->generateKey();

		$blob = base64_decode(substr(Camt053SshPublicKey::fromPrivateKey($pem), strlen('ssh-rsa ')));
		$details = openssl_pkey_get_details(openssl_pkey_get_private($pem));
		$modulus = $details['rsa']['n'];

		$this->assertTrue((ord($modulus[0]) & 0x80) !== 0, 'An RSA modulus starts with its high bit set');
		$this->assertStringContainsString(pack('N', strlen($modulus) + 1) . "\x00" . $modulus, $blob);
	}

	/**
	 * An encrypted key is read with its passphrase.
	 *
	 * @return void
	 */
	public function testEncryptedKeyIsReadWithItsPassphrase(): void
	{
		list($pem) = $this->generateKey('s3cret');

		$this->assertNotNull(Camt053SshPublicKey::fromPrivateKey($pem, 's3cret'));
		$this->assertNull(Camt053SshPublicKey::fromPrivateKey($pem, 'wrong'), 'A wrong passphrase derives nothing');
		$this->assertNull(Camt053SshPublicKey::fromPrivateKey($pem), 'No passphrase derives nothing');
	}

	/**
	 * Garbage in, null out, without a warning or an exception.
	 *
	 * @return void
	 */
	public function testUnreadableKeyReturnsNull(): void
	{
		$this->assertNull(Camt053SshPublicKey::fromPrivateKey('not a key'));
		$this->assertNull(Camt053SshPublicKey::fromPrivateKey(''));
	}

	/**
	 * The OpenSSH container format is detected: libssh2 cannot read it, and the
	 * operator needs to be told to convert rather than shown an authentication
	 * failure.
	 *
	 * @return void
	 */
	public function testOpenSshContainerFormatIsDetected(): void
	{
		$this->assertTrue(Camt053SshPublicKey::isOpenSshFormat(
			"-----BEGIN OPENSSH PRIVATE KEY-----\nb3BlbnNzaA==\n-----END OPENSSH PRIVATE KEY-----\n"
		));

		list($pem) = $this->generateKey();
		$this->assertFalse(Camt053SshPublicKey::isOpenSshFormat($pem));
	}
}
