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
 * \file       class/Camt053ReviewAlert.class.php
 * \ingroup    camt053readerandlink
 * \brief      The message telling a human what a run could not decide.
 */

/**
 * Class Camt053ReviewAlert
 *
 * SPEC section 6: a file nobody can act on must reach a human, not just the log.
 * Only the monthly report ever said anything about an entry needing a decision,
 * so one arriving in a daily file waited until the end of the month, or forever
 * when the monthly file carried nothing about it.
 *
 * Kept free of any Dolibarr dependency: the message is a rule about what is
 * worth telling, and it is tested as one.
 */
class Camt053ReviewAlert
{
	/** @var int Entries listed per group before the rest is counted */
	const MAX_PER_GROUP = 20;

	/**
	 * Why an entry could not be settled, in the order a reader cares about.
	 *
	 * @return array<string, string>
	 */
	private static function reasons(): array
	{
		return array(
			'double_payment' => 'Already settled, the document is paid',
			'amount_mismatch' => 'Not what the document still owes',
			'several_documents' => 'The reference matches several documents',
			'several_references' => 'Several documents referenced, the amount has to be split',
			'foreign_currency' => 'Foreign currency, the rate has to be decided',
			'no_document' => 'The reference resolves to no open document',
			'no_reference' => 'No document reference in the message',
			'disabled' => 'Matched no bank line',
		);
	}

	/**
	 * Entries of one summary that need a human, grouped by account and reason.
	 *
	 * @param array $summary Result of ReconciliationService::processContent()
	 * @return array<int, array<string, array<int, array>>>
	 */
	public static function collect(array $summary): array
	{
		$groups = array();

		foreach (($summary['accounts'] ?? array()) as $accountId => $account) {
			foreach (($account['unmatched'] ?? array()) as $entry) {
				$reason = (string) ($entry['skip_reason'] ?? 'disabled');
				if (!isset(self::reasons()[$reason])) {
					$reason = 'disabled';
				}
				$groups[(int) $accountId][$reason][] = $entry;
			}
		}

		return $groups;
	}

	/**
	 * The message for one SFTP config, empty when nothing needs a human.
	 *
	 * @param string $configRef Config reference, as the reader knows it
	 * @param array  $files     Per file: summary, iban and url, keyed by file name
	 * @return string Zulip markdown, empty when there is nothing to say
	 */
	public static function format(string $configRef, array $files): string
	{
		$lines = array();

		foreach ($files as $name => $file) {
			$groups = self::collect($file['summary'] ?? array());
			if (empty($groups)) {
				continue;
			}

			$lines[] = '';
			$lines[] = '**`' . $name . '`**';

			foreach ($groups as $accountId => $byReason) {
				$account = ($file['summary']['accounts'][$accountId] ?? array());
				$iban = (string) ($account['iban'] ?? '');
				$numReleve = (string) ($account['num_releve'] ?? '');

				$header = 'Account `' . ($iban !== '' ? $iban : '#' . $accountId) . '`';
				if ($numReleve !== '') {
					$header .= ', statement ' . $numReleve;
				}
				$url = (string) ($file['url'] ?? '');
				if ($url !== '') {
					$header .= ' - [open the reconciliation screen](' . $url . ')';
				}
				$lines[] = $header;

				foreach (self::reasons() as $reason => $label) {
					if (empty($byReason[$reason])) {
						continue;
					}
					$lines[] = '- ' . $label . ' (' . count($byReason[$reason]) . ')';
					$lines = array_merge($lines, self::formatEntries($byReason[$reason]));
				}
			}
		}

		if (empty($lines)) {
			return '';
		}

		array_unshift(
			$lines,
			':warning: **CAMT entries needing a decision** for `' . $configRef . '`'
		);
		$lines[] = '';
		$lines[] = '_Nothing was recorded for these._';

		return implode("\n", $lines);
	}

	/**
	 * One line per entry, with what it takes to decide without opening the file.
	 *
	 * @param array<int, array> $entries Entries of one group
	 * @return array<int, string>
	 */
	private static function formatEntries(array $entries): array
	{
		$lines = array();
		$shown = 0;

		foreach ($entries as $entry) {
			if ($shown >= self::MAX_PER_GROUP) {
				$lines[] = '  - … and ' . (count($entries) - self::MAX_PER_GROUP) . ' more';
				break;
			}

			$amount = (float) ($entry['amount'] ?? 0);
			$direction = $amount < 0 ? 'debit' : 'credit';
			$currency = (string) ($entry['currency'] ?? '');

			$line = '  - ' . $direction . ' ' . number_format(abs($amount), 2)
				. ($currency !== '' ? ' ' . $currency : '')
				. ' on ' . (string) ($entry['date'] ?? '');

			$documentRef = (string) ($entry['document_ref'] ?? '');
			if ($documentRef !== '') {
				$line .= ', ' . $documentRef;
				$remaining = (float) ($entry['document_remaining'] ?? 0);
				if ($remaining > 0) {
					$line .= ' owes ' . number_format($remaining, 2);
				}
			}

			$counterparty = trim((string) ($entry['counterparty_iban'] ?? ''));
			if ($counterparty !== '') {
				$line .= ', from `' . $counterparty . '`';
			}

			$text = trim((string) ($entry['name'] ?? '') . ' ' . (string) ($entry['info'] ?? ''));
			if ($text !== '') {
				$line .= ': ' . self::truncate($text, 120);
			}

			$lines[] = $line;
			$shown++;
		}

		return $lines;
	}

	/**
	 * Shorten a text without cutting the message in half.
	 *
	 * @param string $text   Text
	 * @param int    $length Maximum length
	 * @return string
	 */
	private static function truncate(string $text, int $length): string
	{
		$text = preg_replace('/\s+/', ' ', $text);

		return (strlen($text) <= $length) ? $text : (substr($text, 0, $length - 1) . '…');
	}
}
