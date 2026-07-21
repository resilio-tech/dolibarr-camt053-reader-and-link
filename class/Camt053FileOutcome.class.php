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
 * \file       class/Camt053FileOutcome.class.php
 * \ingroup    camt053readerandlink
 * \brief      What a processed CAMT.053 file failed to attach to a bank account.
 */

/**
 * Class Camt053FileOutcome
 *
 * Pure decision helper for the headless runner: it answers whether a file that
 * parsed successfully still left data behind. Kept free of any Dolibarr
 * dependency so the rule can be tested on its own, because getting it wrong
 * either loses a statement or pins the scheduled job to "failed" forever.
 */
class Camt053FileOutcome
{
	/**
	 * Describe what a parsed file failed to attach to a Dolibarr bank account.
	 *
	 * @param array $summary Reconciliation summary
	 * @return string Empty when every statement of the file found its account
	 */
	public static function unresolvedReason(array $summary): string
	{
		$unresolved = $summary['unresolved_ibans'] ?? array();

		if (!empty($unresolved)) {
			return 'no bank account matches ' . implode(', ', array_keys($unresolved));
		}
		if (empty($summary['accounts'])) {
			// Parsed, but not a single statement carried an IBAN we could read.
			return 'no statement carries a usable IBAN';
		}

		return '';
	}
}
