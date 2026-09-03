<?php
/* Copyright (C) 2026 Resilio SA
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

/**
 * \file    camt053readerandlink/lib/camt053readerandlink.results.lib.php
 * \ingroup camt053readerandlink
 * \brief   Rendering of the comparison screen, shared by the interactive upload
 *          (submit.php) and the reopening of an archived statement
 *          (statement.php), so both offer the same dropdowns and suggestions.
 */

require_once DOL_DOCUMENT_ROOT . '/compta/bank/class/account.class.php';
require_once __DIR__ . '/../class/Camt053Entry.class.php';
require_once __DIR__ . '/../class/Camt053DocumentReference.class.php';
require_once __DIR__ . '/../class/BankRelationshipLookup.class.php';
require_once __DIR__ . '/../class/PaymentSuggestionFinder.class.php';
require_once __DIR__ . '/../class/InternalTransferDetector.class.php';

/**
 * Derive the reconciliation period from the entries a CAMT file carries.
 *
 * Mirrors ReconciliationService::dateRange() so the interactive and the headless
 * paths agree on the period, and therefore on the statement number computed from
 * its end date. Falls back to the previous month when the file has no usable
 * entry date.
 *
 * @param Camt053FileProcessor $fileProcessor Parsed file
 * @return array{0:string,1:string} [start, end] in d/m/Y
 */
function camt053_entries_date_range($fileProcessor)
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
 * Render the action links (prefilled payment or internal transfer) offered for
 * a file entry that has no counterpart in Dolibarr.
 *
 * @param Camt053Entry             $entry     Unmatched file entry
 * @param int                      $entity    Bank account entity
 * @param int                      $accountId Bank account the statement belongs to
 * @param PaymentSuggestionFinder  $finder    Payment suggestion finder
 * @param InternalTransferDetector $detector  Internal transfer detector
 * @param Translate                $langs     Language object
 * @return string HTML (empty when nothing is suggested)
 */
function camt053_render_suggestions($entry, $entity, $accountId, $finder, $detector, $langs)
{
	$out = array();

	// Internal transfer: the counterparty is one of the company's own accounts.
	$transfer = $detector->detect($entry, (int) $accountId, (int) $entity);
	if ($transfer !== null) {
		$label = $langs->trans('Camt053SuggestInternalTransfer', dol_escape_htmltag($transfer['counterparty_ref']));
		$out[] = '<a href="' . dol_escape_htmltag($detector->confirmUrl($transfer)) . '">'
			. img_picto('', 'bank_account', 'class="paddingright"') . $label . '</a>';
	}

	// Unpaid documents of the same amount and currency.
	$labelKeys = array(
		'customer_invoice' => 'Camt053SuggestPayCustomerInvoice',
		'supplier_invoice' => 'Camt053SuggestPaySupplierInvoice',
		'expense_report' => 'Camt053SuggestPayExpenseReport',
		'social_charge' => 'Camt053SuggestPaySocialCharge',
	);
	$pictos = array(
		'customer_invoice' => 'bill',
		'supplier_invoice' => 'supplier_invoice',
		'expense_report' => 'trip',
		'social_charge' => 'payment',
	);

	$suggestions = $finder->findForEntry($entry, (int) $entity, (int) $accountId);
	foreach ($suggestions['links'] as $link) {
		if ($link['kind'] === 'pay') {
			$label = $langs->trans($labelKeys[$link['type']], dol_escape_htmltag($link['ref']));
			$out[] = '<a href="' . dol_escape_htmltag($link['url']) . '" target="_blank" rel="noopener noreferrer">'
				. img_picto('', $pictos[$link['type']], 'class="paddingright"') . $label . '</a>';
		} else {
			// Several documents share this amount: let the user pick which one to pay.
			$options = '<option value="">'
				. dol_escape_htmltag($langs->trans('Camt053SuggestChoose', (int) $link['count']))
				. '</option>';
			foreach ($link['options'] as $o) {
				$text = $o['ref'] . ' - ' . $o['label']
					. ' (' . price($o['amount'], 0, $langs, 1, -1, -1, $o['currency']) . ')';
				$options .= '<option value="' . dol_escape_htmltag($o['url']) . '">'
					. dol_escape_htmltag($text) . '</option>';
			}
			$out[] = img_picto('', $pictos[$link['type']], 'class="paddingright"')
				. '<select class="flat maxwidth200onsmartphone"'
				. ' onchange="if(this.value){window.open(this.value,\'_blank\');this.selectedIndex=0;}">'
				. $options . '</select>';
		}
	}

	return implode('<br />', $out);
}

/**
 * Bank line of the only candidate whose document the entry names.
 *
 * The amount matched several Dolibarr lines, so the module cannot choose on its
 * own (SPEC section 3). It can when the file says which document was paid: the
 * reference is preselected in the dropdown, which stays open on every other
 * candidate. An entry naming two of them is still a manual choice.
 *
 * @param Camt053Entry $fileEntry      Entry read from the file
 * @param array        $candidates     Matching Dolibarr entries
 * @param object       $relationLookup Lookup of the document behind a bank line
 * @return string Bank line id to preselect, empty string for none
 */
function camt053_candidate_named_by_entry($fileEntry, array $candidates, $relationLookup)
{
	$data = $fileEntry->getData();
	$references = Camt053DocumentReference::extract((string) $data['name'], (string) $data['info']);
	if (empty($references)) {
		return '';
	}

	$candidateRefs = array();
	foreach ($candidates as $candidate) {
		$bankLine = $candidate->getBankLine();
		if (!$bankLine) {
			continue;
		}
		// The dropdown keys its options on rowid, so the preselection has to
		// answer with the same id.
		$lineId = (int) (!empty($bankLine->rowid) ? $bankLine->rowid : (isset($bankLine->id) ? $bankLine->id : 0));
		if ($lineId <= 0) {
			continue;
		}
		$relation = $relationLookup->getRelation($lineId);
		if ($relation !== null && !empty($relation['ref'])) {
			$candidateRefs[$lineId] = (string) $relation['ref'];
		}
	}

	return Camt053DocumentReference::pickNamed($references, $candidateRefs);
}

/**
 * Print the comparison screen and the form that submits the reconciliation.
 *
 * @param array $banks   Comparison results, keyed by bank account id
 * @param array $context date_start, date_end, bank_account_id, structure,
 *                       upload_file, and actionable_first to put what still
 *                       needs a human at the top of each account
 * @return void
 */
function camt053_render_results(array $banks, array $context)
{
	global $db, $langs, $conf;

	$form = new Form($db);
	$relationLookup = new BankRelationshipLookup($db);
	$suggestionFinder = new PaymentSuggestionFinder($db);
	$transferDetector = new InternalTransferDetector($db);

	$actionableFirst = !empty($context['actionable_first']);

	print '<form id="form" name="form" action="' . dol_buildpath('/camt053readerandlink/confirm.php', 1) . '" method="post">';

	foreach ($banks as $accountId => $bank) {
		$results = $bank['results'];

		camt053_render_account_header((int) $accountId, $bank, $context, $results, $actionableFirst);

		print '<table class="noborder" style="width: 100%">';
		print '<tr class="liste_titre">';
		print '<td>' . $langs->trans('Location') . '</td>';
		print '<td class="right">' . $langs->trans('Amount') . '</td>';
		print '<td>' . $langs->trans('Date') . '</td>';
		print '<td>' . $langs->trans('Name') . '</td>';
		print '<td>' . $langs->trans('Conciliated') . '</td>';
		print '<td>' . $langs->trans('Conciliated') . '</td>';
		print '<td>hash</td>';
		print '</tr>';

		// The two sections a human has to act on, and the two that only report.
		$toReview = array('multiples', 'unlinkeds');
		$done = array('linkeds', 'already_linked');
		$order = $actionableFirst ? array_merge($toReview, $done) : array('linkeds', 'multiples', 'unlinkeds', 'already_linked');

		foreach ($order as $section) {
			camt053_render_results_section(
				$section,
				$results,
				(int) $accountId,
				$form,
				$relationLookup,
				$suggestionFinder,
				$transferDetector
			);
		}

		print '</table>';
	}

	print '<input type="hidden" name="date_start" value="' . dol_escape_htmltag($context['date_start']) . '" />';
	print '<input type="hidden" name="date_end" value="' . dol_escape_htmltag($context['date_end']) . '" />';
	print '<input type="hidden" name="bank_account_id" value="' . ((int) $context['bank_account_id']) . '" />';
	print '<input type="hidden" name="token" value="' . newToken() . '" />';
	print '<input type="hidden" name="action" value="confirm" />';
	print '<input type="hidden" name="file_json" value="' . dol_escape_htmltag(urlencode(json_encode($context['structure'], 0))) . '" />';
	print '<input type="hidden" name="upload_file" value="' . dol_escape_htmltag((string) $context['upload_file']) . '" />';
	print '<input type="submit" value="' . $langs->trans('Confirm') . '" />';

	print '</form>';
}

/**
 * Print the account identification block above its entries.
 *
 * @param int   $accountId       Bank account id
 * @param array $bank            Comparison result for this account
 * @param array $context         Render context
 * @param array $results         Comparison buckets
 * @param bool  $actionableFirst Whether the entries needing a human come first
 * @return void
 */
function camt053_render_account_header(int $accountId, array $bank, array $context, array $results, bool $actionableFirst)
{
	global $db, $langs;

	$bank_account = new Account($db);
	$bank_account->fetch($accountId);
	$iban_format = isset($bank['account']) ? $bank['account']->iban_prefix : '';

	print '<table class="noborder" style="width: 100%">';
	print '<tr class="liste_titre">';
	print '<td>' . $langs->trans('DateStart') . '</td>';
	print '<td>' . $langs->trans('DateEnd') . '</td>';
	print '<td>' . $langs->trans('IBAN') . '</td>';
	print '<td>' . $langs->trans('BankAccount') . '</td>';
	print '</tr>';
	print '<tr>';
	print '<td>' . dol_escape_htmltag($context['date_start']) . '</td>';
	print '<td>' . dol_escape_htmltag($context['date_end']) . '</td>';
	print '<td>' . dol_escape_htmltag($iban_format) . '</td>';
	print '<td>' . $bank_account->getNomUrl(1) . '</td>';
	print '</tr>';
	print '</table>';

	if (!$actionableFirst) {
		return;
	}

	$fileUnlinked = 0;
	foreach ($results['unlinkeds'] as $entry) {
		if ($entry->isFromFile()) {
			$fileUnlinked++;
		}
	}

	print '<div class="opacitymedium" style="margin:4px 0">';
	print $langs->trans(
		'Camt053ReviewCounts',
		count($results['multiples']),
		$fileUnlinked,
		count($results['linkeds']),
		count($results['already_linked'])
	);
	print '</div>';
}

/**
 * Print one bucket of the comparison for an account.
 *
 * @param string                   $section           linkeds, multiples, unlinkeds or already_linked
 * @param array                    $results           Comparison buckets
 * @param int                      $accountId         Bank account id
 * @param Form                     $form              Dolibarr form helper
 * @param BankRelationshipLookup   $relationLookup    Related document lookup
 * @param PaymentSuggestionFinder  $suggestionFinder  Payment suggestion finder
 * @param InternalTransferDetector $transferDetector  Internal transfer detector
 * @return void
 */
function camt053_render_results_section($section, array $results, int $accountId, $form, $relationLookup, $suggestionFinder, $transferDetector)
{
	global $conf, $langs;

	$from_file = 'Fichier CAMT.053';
	$from_doli = 'Dolibarr';

	if ($section === 'linkeds') {
		foreach ($results['linkeds'] as $n_obj) {
			$entry = $n_obj['file']->getData();
			$o = $n_obj['db']->getBankLine();
			$name = $relationLookup->getRelationHtml($o->rowid);
			print '<tr>';
			print '<td>' . ($n_obj['file']->isFromFile() ? $from_file : $from_doli) . '</td>';
			print '<td class="right">' . number_format($entry['amount'], 2) . '</td>';
			print '<td>' . dol_escape_htmltag($entry['value_date']) . '</td>';
			print '<td>' . dol_escape_htmltag($entry['name']) . '<br /><span class="info">' . dol_escape_htmltag($entry['info']) . '</span></td>';
			print '<td><div class="statement_link_linked">' . $langs->trans('WillBeConciliated') . '</div></td>';
			// The field key must be unique across the whole form, which spans
			// every account of the file: two accounts can carry an identical
			// movement, and hashes are only deduplicated within a statement.
			$fieldKey = $accountId . '-' . $n_obj['file']->getHash();
			print '<td>' . $name . '<input type="hidden" name="linked[' . dol_escape_htmltag($fieldKey) . ']" value="' . ((int) $o->rowid) . '" /></td>';
			print '</tr>';
		}

		return;
	}

	if ($section === 'multiples') {
		foreach ($results['multiples'] as $n_obj) {
			$entry = $n_obj['file']->getData();
			$ntry_hash = $n_obj['file']->getHash();
			$preselected = camt053_candidate_named_by_entry($n_obj['file'], $n_obj['db'], $relationLookup);
			print '<tr>';
			print '<td>' . ($n_obj['file']->isFromFile() ? $from_file : $from_doli) . '</td>';
			print '<td style="text-align: right">' . number_format($entry['amount'], 2) . '</td>';
			print '<td>' . dol_escape_htmltag($entry['value_date']) . '</td>';
			print '<td>' . dol_escape_htmltag($entry['name']) . '<br /><span class="info">' . dol_escape_htmltag($entry['info']) . '</span></td>';
			print '<td><div class="statement_link_multiple">' . $langs->trans('MultipleConciliated') . '</div></td>';
			print '<td>' . dol_escape_htmltag($entry['hash']) . '</td>';
			print '<td>';
			$array = array();
			foreach ($n_obj['db'] as $ntry_db_obj) {
				$dbEntry = $ntry_db_obj->getData();
				$id = $ntry_db_obj->getBankLine()->rowid;
				$n = dol_escape_htmltag($dbEntry['name']);
				$a = number_format($dbEntry['amount'], 2);
				$d = dol_escape_htmltag($dbEntry['value_date']);
				// Prepend the related document (invoice ref + third party) so
				// same-amount candidates can be told apart in the dropdown.
				$doc = '';
				$relation = $relationLookup->getRelation((int) $id);
				if ($relation !== null && !empty($relation['ref'])) {
					$doc = dol_escape_htmltag(trim($relation['ref'] . ' - ' . $relation['label'])) . '<br />';
				}
				$array[$id] = '(' . $id . ') ' . $doc . $n . '<br />' . $a . '<br />' . $d;
			}
			print $form->selectMassAction($preselected, $array, 1, 'linked_' . dol_escape_htmltag($accountId . '-' . $ntry_hash));
			print '</td>';
			print '</tr>';
		}

		return;
	}

	if ($section === 'unlinkeds') {
		foreach ($results['unlinkeds'] as $n_obj) {
			$entry = $n_obj->getData();
			$name = dol_escape_htmltag($entry['name']);
			$o = $n_obj->getBankLine();
			if (!$n_obj->isFromFile() && $o) {
				$name = $relationLookup->getRelationHtml($o->id);
			}
			print '<tr>';
			print '<td>' . ($n_obj->isFromFile() ? $from_file : $from_doli) . '</td>';
			print '<td style="text-align: right">' . number_format($entry['amount'], 2) . '</td>';
			print '<td>' . dol_escape_htmltag($entry['value_date']) . '</td>';
			print '<td>' . $name . '<br /><span class="info">' . dol_escape_htmltag($entry['info']) . '</span></td>';
			print '<td><div class="statement_link_unlinked">' . $langs->trans('WillNotBeConciliated') . '</div></td>';
			$suggestionHtml = $n_obj->isFromFile()
				? camt053_render_suggestions($n_obj, (int) $conf->entity, $accountId, $suggestionFinder, $transferDetector, $langs)
				: '';
			print '<td>' . $suggestionHtml . '</td>';
			print '</tr>';
		}

		return;
	}

	foreach ($results['already_linked'] as $n_obj) {
		$is_file = false;
		if (isset($n_obj['file']) && $n_obj['file'] instanceof Camt053Entry) {
			$entry = $n_obj['file']->getData();
			$is_file = $n_obj['file']->isFromFile();
		} else {
			$entry = $n_obj['db']->getData();
			$is_file = $n_obj['db']->isFromFile();
		}
		$o = $n_obj['db']->getBankLine();
		$name = $relationLookup->getRelationHtml($o->id);
		print '<tr>';
		print '<td>' . ($is_file ? $from_file : $from_doli) . '</td>';
		print '<td class="right">' . number_format($entry['amount'], 2) . '</td>';
		print '<td>' . dol_escape_htmltag($entry['value_date']) . '</td>';
		print '<td>' . dol_escape_htmltag($entry['name']) . '<br /><span class="info">' . dol_escape_htmltag($entry['info']) . '</span></td>';
		print '<td><div class="statement_link_already_linked">' . $langs->trans('AlreadyBeConciliated') . '</div></td>';
		print '<td>' . $name . '</td>';
		print '</tr>';
	}
}
