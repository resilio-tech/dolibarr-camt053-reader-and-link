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
 * \file       class/Camt053ReconciliationPeriod.class.php
 * \ingroup    camt053readerandlink
 * \brief      Period a CAMT file is reconciled and archived under.
 */

/**
 * Class Camt053ReconciliationPeriod
 *
 * The period decides which Dolibarr bank lines are compared and, through its end
 * date, the statement number the file is archived under. It is read from the
 * entries first, then from the period the file declares, and only then from the
 * previous month of the creation date, which is a guess and is wrong for
 * anything but a monthly statement.
 */
class Camt053ReconciliationPeriod
{
	/**
	 * Resolve the period of a parsed file.
	 *
	 * @param Camt053FileProcessor $fileProcessor Parsed file
	 * @return array{0:string,1:string} [start, end] in d/m/Y
	 */
	public static function resolve($fileProcessor): array
	{
		$min = null;
		$max = null;

		// Resolved accounts only, exactly like ReconciliationService: an IBAN that
		// matches no Dolibarr account contributes nothing to the reconciliation, and
		// letting its dates widen the window drags unrelated bank lines into the
		// results as "unlinked".
		foreach ($fileProcessor->getStatementsByAccountId() as $statement) {
			foreach ($statement->getEntries() as $entry) {
				// Pin the time: createFromFormat() would otherwise stamp "now", which
				// makes two same-day entries compare unequal.
				$d = DateTime::createFromFormat('Y-m-d H:i:s', $entry->getValueDate() . ' 00:00:00');
				if ($d === false) {
					continue;
				}
				if ($min === null || $d < $min) {
					$min = clone $d;
				}
				if ($max === null || $d > $max) {
					$max = clone $d;
				}
			}
		}

		// A statement carrying no entry still says which period it covers, and a
		// statement is valid with nothing on it: that period is what it must be
		// reconciled and archived under.
		if ($min === null || $max === null) {
			foreach ($fileProcessor->getStatementsByAccountId() as $statement) {
				$start = self::readDate($statement->getPeriodStart());
				$end = self::readDate($statement->getPeriodEnd());
				if ($start === null || $end === null) {
					continue;
				}
				if ($min === null || $start < $min) {
					$min = $start;
				}
				if ($max === null || $end > $max) {
					$max = $end;
				}
			}
		}

		if ($min === null || $max === null) {
			$creationDate = $fileProcessor->getCreationDate();
			try {
				$d = $creationDate ? new DateTime($creationDate) : new DateTime();
			} catch (Exception $e) {
				$d = new DateTime();
			}
			$d->modify('first day of previous month');

			return array($d->format('01/m/Y'), $d->format('t/m/Y'));
		}

		return array($min->format('d/m/Y'), $max->format('d/m/Y'));
	}

	/**
	 * Read a period boundary carried by the file into a comparable date.
	 *
	 * @param string|null $value Date or datetime as the file spells it
	 * @return DateTime|null Midnight of that day, null when it cannot be read
	 */
	private static function readDate(?string $value): ?DateTime
	{
		if (empty($value)) {
			return null;
		}

		try {
			$date = new DateTime($value);
		} catch (Exception $e) {
			return null;
		}

		// Pin the time so a boundary compares with an entry date of the same day.
		$date->setTime(0, 0, 0);

		return $date;
	}
}
