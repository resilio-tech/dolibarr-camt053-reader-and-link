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
 * \file       class/Camt053ArchivePath.class.php
 * \ingroup    camt053readerandlink
 * \brief      Pick an archive path that never lets one file answer for another.
 */

/**
 * Class Camt053ArchivePath
 *
 * Banks commonly publish every statement under the same remote name
 * (camt053.xml). Treating "a file already exists at that path" as "this content
 * is archived" then lets yesterday's copy stand in for today's, after which the
 * remote original is deleted and today's statement exists nowhere.
 *
 * This resolves the target path by content: same content reuses the file,
 * different content gets its own. Kept free of any Dolibarr dependency so the
 * rule can be tested on its own.
 */
class Camt053ArchivePath
{
	/**
	 * Resolve where a payload must be written inside a directory.
	 *
	 * @param string $dir      Target directory (no trailing separator required)
	 * @param string $filename Sanitized file name
	 * @param string $content  Payload about to be archived
	 * @return array{path:string, exists:bool} Target path, and whether that exact
	 *                                         content is already archived there
	 */
	public static function resolve(string $dir, string $filename, string $content): array
	{
		$dir = rtrim($dir, '/\\');
		$path = $dir . '/' . $filename;

		if (!file_exists($path)) {
			return array('path' => $path, 'exists' => false);
		}

		if (self::holds($path, $content)) {
			return array('path' => $path, 'exists' => true);
		}

		// Same name, different statement: keep both, identified by content.
		$suffixed = $dir . '/' . self::insertSuffix($filename, substr(hash('sha256', $content), 0, 12));

		return array('path' => $suffixed, 'exists' => file_exists($suffixed) && self::holds($suffixed, $content));
	}

	/**
	 * Whether a file already holds exactly this content.
	 *
	 * @param string $path    Existing file
	 * @param string $content Expected content
	 * @return bool
	 */
	private static function holds(string $path, string $content): bool
	{
		$existing = @file_get_contents($path);

		return ($existing !== false && $existing === $content);
	}

	/**
	 * Insert a suffix before the file extension.
	 *
	 * @param string $filename File name
	 * @param string $suffix   Suffix to insert
	 * @return string
	 */
	private static function insertSuffix(string $filename, string $suffix): string
	{
		$dot = strrpos($filename, '.');
		if ($dot === false || $dot === 0) {
			return $filename . '-' . $suffix;
		}

		return substr($filename, 0, $dot) . '-' . $suffix . substr($filename, $dot);
	}
}
