--
-- Script run when an upgrade of Dolibarr is done. Whatever is the Dolibarr version.
--

ALTER TABLE llx_camt053readerandlink_sftpconfig ADD COLUMN host_fingerprint varchar(64) AFTER public_key;
