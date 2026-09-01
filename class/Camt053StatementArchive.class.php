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
 * \file       class/Camt053StatementArchive.class.php
 * \ingroup    camt053readerandlink
 * \brief      Move an uploaded statement into its archive directory.
 */

require_once __DIR__ . '/Camt053ArchivePath.class.php';

/**
 * Class Camt053StatementArchive
 *
 * The manual upload archives the file it just received, and dropping that file
 * is irreversible: it is the only copy, unlike the scheduled job which can
 * leave the remote original in place. The target is therefore resolved by
 * content, so a statement published under a name another one already used gets
 * its own copy instead of being taken for it. Kept free of any Dolibarr
 * dependency so the rule can be tested on its own.
 */
class Camt053StatementArchive
{
	/** @var string The file was moved into the archive directory */
	const STORED = 'stored';

	/** @var string That exact content was already archived there */
	const ALREADY = 'already';

	/** @var string Nothing was archived and the upload is still in place */
	const FAILED = 'failed';

	/**
	 * Move an uploaded statement into an archive directory.
	 *
	 * @param string $source   Uploaded file, on disk
	 * @param string $dir      Archive directory, expected to exist
	 * @param string $filename Sanitized name to archive it under
	 * @return array{outcome:string, path:string} Outcome, and where the content
	 *         lives when it is not FAILED
	 */
	public static function store(string $source, string $dir, string $filename): array
	{
		$content = @file_get_contents($source);
		if ($content === false) {
			return array('outcome' => self::FAILED, 'path' => '');
		}

		$target = Camt053ArchivePath::resolve($dir, $filename, $content);

		if ($target['exists']) {
			@unlink($source);
			return array('outcome' => self::ALREADY, 'path' => $target['path']);
		}

		if (!@rename($source, $target['path'])) {
			return array('outcome' => self::FAILED, 'path' => $target['path']);
		}

		return array('outcome' => self::STORED, 'path' => $target['path']);
	}
}
