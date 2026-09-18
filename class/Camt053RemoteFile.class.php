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
 * \file       class/Camt053RemoteFile.class.php
 * \ingroup    camt053readerandlink
 * \brief      The files the scheduled job takes out of the PostFinance directory.
 */

/**
 * Class Camt053RemoteFile
 *
 * Daily camt.053 statements and their PDF, read from the PostFinance file names.
 */
class Camt053RemoteFile
{
	/** @var string Daily camt.053 statement */
	const STATEMENT = 'statement';

	/** @var string PDF of a daily statement */
	const PDF = 'pdf';

	/**
	 * What a remote file is, from its name.
	 *
	 * @param string $name Remote file name
	 * @return array{kind:string, iban:string, day:string, stamp:string}|null Null for a file the job does not take
	 */
	public static function classify(string $name): ?array
	{
		if (!preg_match('/^(camt\.053|REP)_[A-Z]_([A-Z]{2}\d{2}[A-Z0-9]+)_\d+_\d+_((\d{4})(\d{2})(\d{2})\d*)\.(xml|pdf)$/i', $name, $m)) {
			return null;
		}

		$isStatement = (strtolower($m[1]) === 'camt.053' && strtolower($m[7]) === 'xml');
		$isPdf = (strtoupper($m[1]) === 'REP' && strtolower($m[7]) === 'pdf');
		if (!$isStatement && !$isPdf) {
			return null;
		}

		return array(
			'kind' => $isStatement ? self::STATEMENT : self::PDF,
			'iban' => strtoupper($m[2]),
			'day' => $m[4] . '-' . $m[5] . '-' . $m[6],
			'stamp' => $m[3],
		);
	}

	/**
	 * Split a remote listing into the statements and the PDFs the job takes,
	 * each in delivery order.
	 *
	 * @param string[] $names Remote file names
	 * @return array{statements:array<int,string>, pdfs:array<int,string>}
	 */
	public static function select(array $names): array
	{
		$selected = array(self::STATEMENT => array(), self::PDF => array());

		foreach ($names as $name) {
			$file = self::classify((string) $name);
			if ($file !== null) {
				$selected[$file['kind']][$file['stamp'] . '|' . $name] = (string) $name;
			}
		}

		ksort($selected[self::STATEMENT], SORT_STRING);
		ksort($selected[self::PDF], SORT_STRING);

		return array(
			'statements' => array_values($selected[self::STATEMENT]),
			'pdfs' => array_values($selected[self::PDF]),
		);
	}

	/**
	 * The statement a PDF belongs to: same account, same day.
	 *
	 * @param string   $pdf        PDF file name
	 * @param string[] $statements Statement file names
	 * @return string|null Null when the statement is not there
	 */
	public static function statementOf(string $pdf, array $statements): ?string
	{
		$file = self::classify($pdf);
		if ($file === null || $file['kind'] !== self::PDF) {
			return null;
		}

		foreach ($statements as $statement) {
			$candidate = self::classify((string) $statement);
			if ($candidate !== null && $candidate['kind'] === self::STATEMENT
				&& $candidate['iban'] === $file['iban'] && $candidate['day'] === $file['day']) {
				return (string) $statement;
			}
		}

		return null;
	}
}
