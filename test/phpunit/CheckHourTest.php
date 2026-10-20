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
 * \file       test/phpunit/CheckHourTest.php
 * \ingroup    camt053readerandlink
 * \brief      PHPUnit tests for the hours the scheduled job works at.
 */

use PHPUnit\Framework\TestCase;

require_once dirname(__FILE__) . '/../../lib/camt053readerandlink.lib.php';

/**
 * Class CheckHourTest
 */
class CheckHourTest extends TestCase
{
	/**
	 * Timestamp of a Swiss local time.
	 *
	 * @param string $local Local date and time
	 * @return int
	 */
	private function zurich(string $local): int
	{
		return (new DateTime($local, new DateTimeZone('Europe/Zurich')))->getTimestamp();
	}

	/**
	 * The slot is the latest check hour already reached, so a run that misses
	 * the hour itself still works later in the day.
	 *
	 * @return void
	 */
	public function testTheSlotIsTheLatestHourReached(): void
	{
		$this->assertSame($this->zurich('2026-09-18 11:00'), camt053LastCheckSlot(array('11', '16'), $this->zurich('2026-09-18 11:00')));
		$this->assertSame($this->zurich('2026-09-18 11:00'), camt053LastCheckSlot(array('11', '16'), $this->zurich('2026-09-18 13:27')));
		$this->assertSame($this->zurich('2026-09-18 16:00'), camt053LastCheckSlot(array('11', '16'), $this->zurich('2026-09-18 23:59')));
	}

	/**
	 * Before the first hour of the day, the slot is the last one of the day before.
	 *
	 * @return void
	 */
	public function testBeforeTheFirstHourTheSlotIsYesterdays(): void
	{
		$this->assertSame($this->zurich('2026-09-17 16:00'), camt053LastCheckSlot(array('11', '16'), $this->zurich('2026-09-18 10:59')));
		$this->assertSame($this->zurich('2026-09-17 11:00'), camt053LastCheckSlot(array('11'), $this->zurich('2026-09-18 03:00')));
	}

	/**
	 * The hours stay Swiss hours in winter as in summer.
	 *
	 * @return void
	 */
	public function testTheHoursFollowTheSwissTimeChange(): void
	{
		$this->assertSame($this->zurich('2026-12-15 11:00'), camt053LastCheckSlot(array('11'), $this->zurich('2026-12-15 11:10')));
		$this->assertSame($this->zurich('2026-10-24 11:00'), camt053LastCheckSlot(array('11'), $this->zurich('2026-10-25 09:00')));
	}

	/**
	 * No hour given means a run started by hand, which always works.
	 *
	 * @return void
	 */
	public function testNoHourMeansNoSlot(): void
	{
		$this->assertNull(camt053LastCheckSlot(array(), $this->zurich('2026-09-18 03:00')));
	}
}
