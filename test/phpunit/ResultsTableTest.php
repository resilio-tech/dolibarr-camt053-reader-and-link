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
 * \file       test/phpunit/ResultsTableTest.php
 * \ingroup    camt053readerandlink
 * \brief      PHPUnit tests keeping every row of the comparison screen as wide
 *             as its header
 */

use PHPUnit\Framework\TestCase;

/**
 * Class ResultsTableTest
 *
 * The rendering needs a Dolibarr page to run, so the cell counts are read from
 * the source: a section printing one cell short is invisible in a unit test but
 * plain to see on the screen, as a table cut short of its own header.
 */
class ResultsTableTest extends TestCase
{
	/** @var string Rendering library source */
	private $source;

	/**
	 * Load the rendering library.
	 *
	 * @return void
	 */
	protected function setUp(): void
	{
		$path = dirname(__FILE__) . '/../../lib/camt053readerandlink.results.lib.php';
		$source = file_get_contents($path);
		$this->assertNotFalse($source, 'The rendering library is missing');
		$this->source = (string) $source;
	}

	/**
	 * Cells printed by a slice of the source.
	 *
	 * @param string $slice Source between two markers
	 * @return int
	 */
	private function countCells(string $slice): int
	{
		return preg_match_all("/print '<td/", $slice);
	}

	/**
	 * Source of one function, up to the next one.
	 *
	 * @param string $name Function name
	 * @return string
	 */
	private function functionSource(string $name): string
	{
		$start = strpos($this->source, 'function ' . $name . '(');
		$this->assertNotFalse($start, $name . '() is missing');

		$next = strpos($this->source, "\nfunction ", $start + 1);

		return $next === false ? substr($this->source, $start) : substr($this->source, $start, $next - $start);
	}

	/**
	 * Header of the entries table.
	 *
	 * @return int
	 */
	private function headerCells(): int
	{
		$render = $this->functionSource('camt053_render_results');
		$start = strpos($render, "print '<tr class=\"liste_titre\">';");
		$this->assertNotFalse($start, 'The entries table has no header');
		$end = strpos($render, "print '</tr>';", $start);

		return $this->countCells(substr($render, $start, $end - $start));
	}

	/**
	 * Row of each section of the table.
	 *
	 * @return array<string, int>
	 */
	private function sectionCells(): array
	{
		$section = $this->functionSource('camt053_render_results_section');

		$offsets = array();
		foreach (array('linkeds', 'multiples', 'unlinkeds') as $name) {
			$offset = strpos($section, "if (\$section === '" . $name . "')");
			$this->assertNotFalse($offset, $name . ' is not rendered');
			$offsets[$name] = $offset;
		}
		// The last section is the fall-through: it has no test of its own.
		$offsets['already_linked'] = strpos($section, "foreach (\$results['already_linked']");
		$this->assertNotFalse($offsets['already_linked'], 'already_linked is not rendered');

		$bounds = array_values($offsets);
		$bounds[] = strlen($section);

		$cells = array();
		$i = 0;
		foreach ($offsets as $name => $offset) {
			$i++;
			$cells[$name] = $this->countCells(substr($section, $offset, $bounds[$i] - $offset));
		}

		return $cells;
	}

	/**
	 * A row one cell short leaves the table looking cut wherever that section
	 * appears, which is every screen with an entry needing no dropdown.
	 *
	 * @return void
	 */
	public function testEverySectionPrintsAsManyCellsAsTheHeader(): void
	{
		$header = $this->headerCells();
		$this->assertGreaterThan(0, $header);

		foreach ($this->sectionCells() as $section => $cells) {
			$this->assertSame(
				$header,
				$cells,
				$section . ' prints ' . $cells . ' cells for a header of ' . $header
			);
		}
	}

	/**
	 * Two columns labelled the same say nothing about what either holds.
	 *
	 * @return void
	 */
	public function testTheHeaderLabelsEveryColumnOnce(): void
	{
		$render = $this->functionSource('camt053_render_results');
		$start = strpos($render, "print '<tr class=\"liste_titre\">';");
		$end = strpos($render, "print '</tr>';", $start);
		$header = substr($render, $start, $end - $start);

		$labels = array();
		preg_match_all("/trans\('([^']+)'\)/", $header, $labels);

		$this->assertSame(
			$labels[1],
			array_values(array_unique($labels[1])),
			'The header uses the same label for two columns'
		);
	}

	/**
	 * The hash is the form key, not something to read: it was printed as a
	 * column of its own, which is also what made one section wider than the
	 * others.
	 *
	 * @return void
	 */
	public function testTheEntryHashIsNotDisplayed(): void
	{
		$this->assertStringNotContainsString(
			"dol_escape_htmltag(\$entry['hash'])",
			$this->source,
			'The entry hash is still printed as a cell'
		);
		$this->assertStringNotContainsString(
			"print '<td>hash</td>'",
			$this->source,
			'The table still carries a hash column'
		);
	}

	/**
	 * The reconciliation form keys must not change with the display: confirm.php
	 * reads them as they are built here.
	 *
	 * @return void
	 */
	public function testTheFormKeysAreUnchanged(): void
	{
		$this->assertStringContainsString("name=\"linked[' . dol_escape_htmltag(\$fieldKey) . ']\"", $this->source);
		$this->assertStringContainsString("'linked_' . dol_escape_htmltag(\$accountId . '-' . \$ntry_hash)", $this->source);
	}
}
