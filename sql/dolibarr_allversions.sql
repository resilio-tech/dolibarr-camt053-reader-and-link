--
-- Script run when an upgrade of Dolibarr is done. Whatever is the Dolibarr version.
--

ALTER TABLE llx_camt053readerandlink_sftpconfig ADD COLUMN host_fingerprint varchar(64) AFTER public_key;

-- The menu condition called isModenabled() instead of isModEnabled(). Dolibarr
-- only evaluates the functions of its dol_eval whitelist, compared with the case
-- they are written in, so the condition was refused and the entry never shown.
-- The row keeps the condition it was created with, so an install that already
-- has it needs the repair here.
UPDATE llx_menu SET enabled = REPLACE(enabled, 'isModenabled', 'isModEnabled') WHERE module = 'camt053readerandlink';
