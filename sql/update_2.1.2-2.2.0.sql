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

--
-- Script run to make a migration of module version 2.1.2 to module version 2.2.0
--

ALTER TABLE llx_camt053readerandlink_sftpconfig ADD COLUMN host_fingerprint varchar(64) AFTER public_key;
ALTER TABLE llx_camt053readerandlink_processedfile ADD COLUMN archived_path varchar(512) AFTER num_releve;
