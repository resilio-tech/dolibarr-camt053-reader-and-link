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
 * \file       class/Camt053DocumentReference.class.php
 * \ingroup    camt053readerandlink
 * \brief      Document references carried by the text of a bank entry.
 */

/**
 * Class Camt053DocumentReference
 *
 * The transfer message almost always names the document being paid, as the
 * Dolibarr reference of an invoice (`FA2602-0001`, `SI2602-0042`), sometimes
 * without its separator, sometimes several of them, always surrounded by other
 * text. That text reaches the module as the name and the info of the entry, and
 * nothing read it.
 *
 * A reference found here is a signal, never a decision: it ranks a candidate the
 * amount already matched (SPEC section 3), it never reconciles anything on its
 * own. Kept free of any Dolibarr dependency so the reading can be tested alone.
 */
class Camt053DocumentReference
{
	/**
	 * Reference shape: two to four letters, the numbering year and month, then
	 * the counter, with or without the separator the mask prints.
	 */
	const PATTERN = '/\b([A-Z]{2,4}\d{4}[-\/.]?\d{3,6})\b/';

	/**
	 * References carried by the text of an entry.
	 *
	 * @param string ...$texts Name and info of the entry, in any order
	 * @return array<int, string> Compact references, in order of appearance,
	 *         each one listed once
	 */
	public static function extract(string ...$texts): array
	{
		$found = array();

		foreach ($texts as $text) {
			// The name and the info carry the <br /> the processor joins them
			// with, and a tag glued to a reference would hide it behind a word
			// boundary.
			$plain = strtoupper(strip_tags(str_replace(array('<br />', '<br>', '<br/>'), ' ', $text)));

			$matches = array();
			if (!preg_match_all(self::PATTERN, $plain, $matches)) {
				continue;
			}

			foreach ($matches[1] as $match) {
				$compact = self::compact($match);
				if ($compact !== '' && !in_array($compact, $found, true)) {
					$found[] = $compact;
				}
			}
		}

		return $found;
	}

	/**
	 * Comparable form of a reference.
	 *
	 * Banks drop the separator of the mask, so `FA26020001` and `FA2602-0001`
	 * are the same document and have to compare equal.
	 *
	 * @param string $reference Reference as written anywhere
	 * @return string Letters and digits only, uppercased
	 */
	public static function compact(string $reference): string
	{
		return (string) preg_replace('/[^A-Z0-9]/', '', strtoupper($reference));
	}

	/**
	 * Whether a document reference is named in the text of an entry.
	 *
	 * @param string $reference Document reference, as Dolibarr numbered it
	 * @param string ...$texts  Name and info of the entry
	 * @return bool
	 */
	public static function isNamedIn(string $reference, string ...$texts): bool
	{
		$compact = self::compact($reference);
		if ($compact === '') {
			return false;
		}

		return in_array($compact, self::extract(...$texts), true);
	}

	/**
	 * Every spelling a reference can carry in the database.
	 *
	 * The bank writes the reference with the separator of the mask, without it,
	 * or with another one. The lookup compares against the column itself so the
	 * index is usable, which means every spelling has to be listed.
	 *
	 * @param string $compact Compact reference
	 * @return array<int, string> Spellings, the compact one first
	 */
	public static function spellings(string $compact): array
	{
		$compact = self::compact($compact);
		$parts = array();
		if (!preg_match('/^([A-Z]{2,4}\d{4})(\d{3,6})$/', $compact, $parts)) {
			return $compact === '' ? array() : array($compact);
		}

		$spellings = array($compact);
		foreach (array('-', '/', '.') as $separator) {
			$spellings[] = $parts[1] . $separator . $parts[2];
		}

		return $spellings;
	}

	/**
	 * Pick the one candidate the entry names.
	 *
	 * @param array<int, string>        $references    References carried by the entry
	 * @param array<int|string, string> $candidateRefs Document reference of each
	 *                                                 candidate, keyed by bank line id
	 * @return string Bank line id of the only candidate named, empty string when
	 *         none is named or when several are: an entry naming two candidates
	 *         is still a manual choice
	 */
	public static function pickNamed(array $references, array $candidateRefs): string
	{
		if (empty($references)) {
			return '';
		}

		$named = array();
		foreach ($candidateRefs as $lineId => $reference) {
			$compact = self::compact((string) $reference);
			if ($compact !== '' && in_array($compact, $references, true)) {
				$named[] = (string) $lineId;
			}
		}

		return count($named) === 1 ? $named[0] : '';
	}
}
