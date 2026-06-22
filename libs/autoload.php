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
 * \file       libs/autoload.php
 * \ingroup    camt053readerandlink
 * \brief      Minimal PSR-4 autoloader for the vendored phpseclib 3 library and
 *             its paragonie/constant_time_encoding dependency (no composer here).
 */

if (!defined('CAMT053_VENDOR_AUTOLOAD_REGISTERED')) {
	define('CAMT053_VENDOR_AUTOLOAD_REGISTERED', 1);

	// phpseclib mbstring sanity check.
	require_once __DIR__ . '/phpseclib/bootstrap.php';

	$camt053VendorPrefixes = array(
		'phpseclib3\\' => __DIR__ . '/phpseclib/',
		'ParagonIE\\ConstantTime\\' => __DIR__ . '/constant_time/',
	);

	spl_autoload_register(function ($class) use ($camt053VendorPrefixes) {
		foreach ($camt053VendorPrefixes as $prefix => $baseDir) {
			$len = strlen($prefix);
			if (strncmp($prefix, $class, $len) !== 0) {
				continue;
			}
			$relativeClass = substr($class, $len);
			$file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';
			if (is_file($file)) {
				require_once $file;
			}
			return;
		}
	});
}
