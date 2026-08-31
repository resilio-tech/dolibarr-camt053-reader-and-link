-- Copyright (C) 2026 Resilio SA
--
-- This program is free software; you can redistribute it and/or modify
-- it under the terms of the GNU General Public License as published by
-- the Free Software Foundation; either version 3 of the License, or
-- (at your option) any later version.
--
-- This program is distributed in the hope that it will be useful,
-- but WITHOUT ANY WARRANTY; without even the implied warranty of
-- MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
-- GNU General Public License for more details.
--
-- You should have received a copy of the GNU General Public License
-- along with this program. If not, see <https://www.gnu.org/licenses/>.

CREATE TABLE llx_camt053readerandlink_processedfile(
	rowid					integer AUTO_INCREMENT PRIMARY KEY NOT NULL,
	entity					integer DEFAULT 1 NOT NULL,
	fk_config				integer NOT NULL,
	filename				varchar(255) NOT NULL,
	file_hash				varchar(64) NOT NULL,
	fk_bank_account			integer,
	num_releve				varchar(50),
	archived_path			varchar(512),
	is_monthly				smallint DEFAULT 0 NOT NULL,
	nb_auto					integer DEFAULT 0 NOT NULL,
	nb_ambiguous			integer DEFAULT 0 NOT NULL,
	nb_unmatched			integer DEFAULT 0 NOT NULL,
	status					varchar(32) DEFAULT 'done' NOT NULL,
	error					text,
	date_processed			datetime NOT NULL,
	date_creation			datetime NOT NULL,
	tms						timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=innodb;
