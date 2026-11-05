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
 * \file       class/Camt053ArchivedMonth.class.php
 * \ingroup    camt053readerandlink
 * \brief      Statements archived under a bank account for one month.
 */

require_once __DIR__ . '/Camt053Statement.class.php';
require_once __DIR__ . '/Camt053FileProcessor.class.php';

/**
 * Class Camt053ArchivedMonth
 */
class Camt053ArchivedMonth
{
	/**
	 * Parse every statement file archived under an account for a month.
	 *
	 * @param DoliDb $db        Database connection
	 * @param int    $accountId Bank account id
	 * @param string $numReleve Month, Ym
	 * @return Camt053FileProcessor[] One per parsed XML payload
	 */
	public static function load($db, int $accountId, string $numReleve): array
	{
		global $conf;

		require_once DOL_DOCUMENT_ROOT . '/core/lib/files.lib.php';
		require_once __DIR__ . '/SftpFileTransport.class.php';

		$dir = $conf->bank->dir_output . '/' . $accountId . '/statement/' . $numReleve;

		$processors = array();
		foreach (dol_dir_list($dir, 'files') as $file) {
			$content = @file_get_contents($file['fullname']);
			if ($content === false) {
				continue;
			}

			foreach (SftpFileTransport::extractXmlPayloads($file['name'], $content) as $xml) {
				$processor = new Camt053FileProcessor($db);
				if ($processor->parseContent($xml)) {
					$processors[] = $processor;
				}
			}
		}

		return $processors;
	}

	/**
	 * Merge the entries of an account across several statement files into one statement.
	 *
	 * A movement reported again by another file (same bank reference) is kept once.
	 *
	 * @param Camt053FileProcessor[] $processors Parsed files
	 * @param int                    $accountId  Bank account id
	 * @return Camt053Statement
	 */
	public static function merge(array $processors, int $accountId): Camt053Statement
	{
		$merged = new Camt053Statement('', $accountId);
		$merged->setIsFromFile(true);

		$seenReferences = array();
		foreach ($processors as $fileIndex => $processor) {
			$statement = $processor->getStatementsByAccountId()[$accountId] ?? null;
			if ($statement === null) {
				continue;
			}

			$merged->setIban($statement->getIban());

			foreach ($statement->getEntries() as $entry) {
				$reference = $entry->getBankReference();
				if ($reference !== '') {
					if (isset($seenReferences[$reference]) && $seenReferences[$reference] !== $fileIndex) {
						continue;
					}
					$seenReferences[$reference] = $fileIndex;
				}
				$merged->addEntry($entry);
			}
		}

		return $merged;
	}
}
