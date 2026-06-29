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

CREATE TABLE llx_camt053readerandlink_sftpconfig(
	rowid					integer AUTO_INCREMENT PRIMARY KEY NOT NULL,
	entity					integer DEFAULT 1 NOT NULL,
	ref						varchar(128) NOT NULL,
	label					varchar(255),
	active					smallint DEFAULT 1 NOT NULL,
	host					varchar(255) NOT NULL,
	port					integer DEFAULT 8022 NOT NULL,
	username				varchar(128) NOT NULL,
	auth_type				varchar(16) DEFAULT 'key' NOT NULL,
	private_key				text,
	private_key_passphrase	text,
	password				text,
	remote_dir				varchar(255) DEFAULT 'yellow-net-reports' NOT NULL,
	daily_pattern			varchar(255),
	monthly_pattern			varchar(255),
	post_download_action	varchar(16) DEFAULT 'delete' NOT NULL,
	fk_default_bank_account	integer,
	last_run				datetime,
	last_status				varchar(255),
	date_creation			datetime NOT NULL,
	tms						timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
	fk_user_creat			integer,
	fk_user_modif			integer,
	import_key				varchar(14)
) ENGINE=innodb;
