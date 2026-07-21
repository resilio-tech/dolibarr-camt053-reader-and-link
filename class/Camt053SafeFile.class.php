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
 * \file       class/Camt053SafeFile.class.php
 * \ingroup    camt053readerandlink
 * \brief      All-or-nothing file write.
 */

/**
 * Class Camt053SafeFile
 *
 * The archiver deletes the remote statement once a local copy exists, so
 * "the write succeeded" must mean the whole content is on disk. Kept free of
 * any Dolibarr dependency so the rule can be tested on its own.
 */
class Camt053SafeFile
{
	/**
	 * Write a file, treating a partial write as a failure.
	 *
	 * file_put_contents() returns the byte count, not false, when the disk fills
	 * up mid-write. Taking that for success would leave a truncated statement
	 * behind and let the caller delete the only other copy.
	 *
	 * @param string $path    Target path
	 * @param string $content Content to write
	 * @return bool True only when the whole content is on disk
	 */
	public static function write(string $path, string $content): bool
	{
		$written = @file_put_contents($path, $content);

		if ($written === false || $written !== strlen($content)) {
			// Never leave a half-written statement behind.
			@unlink($path);
			return false;
		}

		return true;
	}
}
