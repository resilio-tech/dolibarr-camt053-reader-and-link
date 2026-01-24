<?php
/* Copyright (C) 2024 SuperAdmin
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
 * \file    camt053readerandlink/lib/camt053readerandlink.lib.php
 * \ingroup camt053readerandlink
 * \brief   Library files with common functions for Camt053ReaderAndLink
 */

/**
 * Prepare admin pages header
 *
 * @return array
 */
function camt053readerandlinkAdminPrepareHead()
{
	global $langs, $conf;

	$langs->load("camt053readerandlink@camt053readerandlink");

	$h = 0;
	$head = array();

	$head[$h][0] = dol_buildpath("/camt053readerandlink/admin/setup.php", 1);
	$head[$h][1] = $langs->trans("Settings");
	$head[$h][2] = 'settings';
	$h++;

	$head[$h][0] = dol_buildpath("/camt053readerandlink/admin/about.php", 1);
	$head[$h][1] = $langs->trans("About");
	$head[$h][2] = 'about';
	$h++;

	complete_head_from_modules($conf, $langs, null, $head, $h, 'camt053readerandlink@camt053readerandlink');
	complete_head_from_modules($conf, $langs, null, $head, $h, 'camt053readerandlink@camt053readerandlink', 'remove');

	return $head;
}
