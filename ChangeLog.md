# CHANGELOG CAMT053READERANDLINK FOR [DOLIBARR ERP CRM](https://www.dolibarr.org)

## 2.2.0 (unreleased)

### New Features
- CAMT.052 intraday reports are read alongside CAMT.053 statements. Only entries the bank has booked are reconciled: a pending one can still be dropped, and the count of those left out is reported instead of being silently lost
- The scheduled job now runs every 12 hours, so the intraday report is picked up twice a day and the monthly statement within 12 hours of its delivery. This seeds new installations only, an existing job keeps the frequency stored in its own row
- `statement.php` reopens the reconciliation screen for a statement the job already fetched, with the entries needing a human first. The monthly Zulip report links straight to it, per bank account, so a finance user no longer has to re-upload the file by hand to find what to review
- Drop the fallback bank account from the SFTP configuration. The bank account is always resolved from the IBAN the file carries, because one SFTP directory delivers the files of several accounts, so booking an unresolved statement onto a preset account would file it under the wrong one. An IBAN matching no account is reported to Zulip and the raw file is kept under `unresolved/`
- Downloading is now a setting, off by default: until an administrator turns it on in the module setup, the scheduled job still logs in and lists the remote directory, reporting how many files the patterns target, but downloads nothing, records nothing and deletes nothing. The connection test details the remote layout either way, so the patterns can be validated against a live server before a single file is taken
- The SFTP connection test now reports what the server actually holds: every entry with its size and date, subdirectories included, and for each file whether the scheduled job downloads it, ignores it because it matches no pattern, or cannot reach it because it sits in a subdirectory
- Verify the SSH host key before authenticating. The connection used to accept whatever key the server presented, so anyone able to answer for the host could collect the SFTP credentials. The fingerprint is now compared against the one stored on the account, learned on the first successful connection when the field is left empty, and a change refuses the connection before a single credential is sent, with its own Zulip alert

### Bug Fixes
- Alert Zulip when a fetched statement resolves to no bank account. Only the monthly report ever mentioned an unresolved IBAN, so a daily file carrying one left its entries unbooked with nothing but a syslog line
- Archive the manual upload by content, as the scheduled job already did. Banks reuse a single name for every statement, and the interactive path treated "a file already exists at that path" as "this statement is archived": the newly uploaded file was deleted and last month's copy kept in its place. A failed archiving is now reported to the user instead of only reaching the log
- Require a CSRF token on the upload processing page, which writes the uploaded file to disk and was the last writing page relying on the core check alone
- Build the module links from the module path instead of a hardcoded `/custom/` prefix, so the upload form, the confirmation form, the results form and the internal transfer suggestion keep working when Dolibarr serves the module from an alternate directory
- Stop OpenSSL prompting for a passphrase on the terminal when an encrypted private key is read without one. The derivation now fails cleanly instead of waiting on standard input, which in a cron run is nobody
- Drop the unused `temp` data directory from the module descriptor. Dolibarr creates a declared directory as `DOL_DATA_ROOT/<entity>/<dir>`, which is not the `DOL_DATA_ROOT/camt053readerandlink/<entity>/` layout the module actually writes to, so the declaration only ever produced a decoy directory. The directories the module uses are created where they are written

### Build
- Stop the release build from pushing the version bump to main. The push was rejected by the branch protection on every release since v2.0.3, which left the descriptor reporting a version older than the published zip. The descriptor is now bumped in the pull request that prepares a release, and the build refuses a tag that does not match it
- Pass the version bump workflow inputs through the environment and validate the computed version, closing the same shell injection the build workflow was already hardened against
- Realign the module descriptor and this changelog with the published tags: both stopped at `2.0.2-pre2` while v2.0.2 through v2.1.2 were released

### Tests
- `Camt053FileProcessorTest.php` - CAMT.052 root detection, booked-only filtering and the pending count
- `EntityScopeSqlTest.php` - entity scoping of the processed-file lookup behind the reconciliation link
- `FilePatternTest.php` - the file pattern selection shared by the cron and the connection test
- `FetchSwitchTest.php` - downloading stays off until the setting is explicitly turned on
- `Camt053HostKeyTest.php` - host key fingerprint normalization and the trust verdict
- `Camt053StatementArchiveTest.php` - the manual upload is archived by content, and stays in place whenever the archiving fails
- `Camt053SshPublicKeyTest.php` - an encrypted key read without a passphrase fails without prompting
- `CsrfTokenTest.php` - every writing page carries the token guard

## 2.1.2 (2026-07-27)

### Bug Fixes
- Restrict the bank account lookup to the current entity only (#11)
- Scope the transfer entity guard to the current entity, and tighten the scope tests
- Archive cron leftovers under the entity of the SFTP config rather than the browsing one

## 2.1.1 (2026-07-23)

### Bug Fixes
- Split a collective (batch) CAMT.053 booking into one entry per `<TxDtls>` so a grouped salary transfer (one bank debit, one detail line per employee) reconciles against Dolibarr's individual salary bank lines instead of matching nothing. The split is only applied when the detailed amounts reconstruct the group total, otherwise the entry is kept whole
- Read the counterparty name from the ISO-correct related party (creditor for a debit, debtor for a credit, as the counterparty IBAN already did), falling back to the other tag, so an outgoing payment now shows the beneficiary instead of the account owner

### Tests
- `Camt053FileProcessorTest.php` - splitting a collective salary booking into per-transaction entries, and keeping the entry whole when the detail is partial

## 2.1.0 (2026-07-21)

### New Features
- SFTP auto-fetch of CAMT.053 files (PostFinance MFTPF), headless reconciliation and Zulip report
- Payment and internal transfer suggestions for unmatched CAMT.053 entries

### Bug Fixes
- Scope the bank account IBAN lookup to the current entity so a file imported for the wrong entity can no longer reconcile foreign entries (#7)
- Show the related invoice reference and third party in the multi-match reconciliation dropdown (#8)
- Prefill foreign-currency invoice payments in the `multicurrency_amount` field instead of the company-currency field (#9)
- Harden SFTP secret handling and cron parse-failure safety
- Open the supplier invoice payment page with `action=create`, without which it showed no form
- Preselect the statement bank account on every prefilled payment page
- Load Dolibarr bank lines with a backward margin equal to the matcher date tolerance, so a line keyed a day early (typically a manually entered salary or various payment) is matched instead of being ignored. Lines from that margin are matchable but never listed, an in-period line always wins over one from the margin, and a margin line already reconciled to another statement can no longer absorb an entry
- Derive the interactive reconciliation period from the entries the file carries, like the headless path already did: a weekly or daily statement was previously compared against the whole previous calendar month
- Define the missing `verifCsrfToken()` helper (renamed `camt053VerifCsrfToken()`), without which saving the setup, saving an SFTP account and deleting one all raised a fatal error
- Require a CSRF token on the reconciliation confirmation, and gate it on `banque.consolidate` like Dolibarr core instead of `banque.modifier`
- Ignore a bank line selected for several statement entries instead of reporting it reconciled twice, and let an explicit dropdown choice win over an automatic link
- Keep statement entry hashes unique so two identical movements on the same day no longer collapse into one form field, silently dropping an entry
- Survive a self-closed `<AcctSvcrRef/>`, which aborted the whole import with a type error
- Scope the SFTP config read/write, the bank relationship lookups and the CLI runner's user pick to the current entity
- Declare the prerequisites the code actually needs (Dolibarr 17, PHP 7.4, `modBanque`) instead of Dolibarr 11 / PHP 7.0 / no dependency

### Build
- Stamp the version into the module descriptor before packaging, so the published zip no longer ships the previous version number
- Pass workflow inputs through the environment and validate the version string, closing a shell injection from a crafted tag name
- Fix a duplicate `env:` key that made the build workflow file invalid, which silently stopped every workflow run on the repository

### Tests
- `PaymentSuggestionFinderTest.php` - payment link building and currency handling
- `EntityScopeSqlTest.php` - entity scoping of the IBAN and bank-line queries
- `BankRelationshipLookupTest.php` - related document lookup for the reconciliation dropdown
- `DatabaseBankStatementLoaderTest.php` - date window and in/out-of-period flagging
- `Camt053StatementTest.php` - entry hash uniqueness

## 2.0.3 (2026-06-04)

### Bug Fixes
- Archive the statement file under the account the reconciled lines belong to, and keep the ECM index in sync by moving the file before indexing it
- Capture the bank account before reconciliation and skip archiving when no account could be determined, instead of filing the statement under account 0
- Catch `Throwable` in `submit.php` so a PHP 8 error no longer renders a blank page

## 2.0.2 (2026-02-03)

### Bug Fixes
- Check the decoded structure before `parseStructure()` so invalid JSON no longer raises a `TypeError`, and show a message when no entry is found instead of a blank page

## 2.0.1 (2024)

### Bug Fixes
- Fixed file path sanitization
- Fixed relative path error handling

### Documentation
- Added comprehensive README documentation
- Updated code comments

### Maintenance
- Removed unused code
- Added build workflow for automated releases

## 2.0.0 (2024)

### Security Fixes
- **SQL Injection**: Fixed SQL injection vulnerabilities in `statements.php` (bankId, IBAN parameters)
- **XSS**: Fixed cross-site scripting vulnerabilities in `submit.php` (entry names and info not escaped)
- **XXE Protection**: Added XML External Entity (XXE) protection in `Camt053FileProcessor.class.php`
- **File Upload Security**: Added MIME type validation and filename sanitization
- **CSRF Protection**: Replaced `$_SESSION['newtoken']` with `newToken()` function
- **Path Traversal**: Added validation to prevent directory traversal attacks in file paths
- **Information Disclosure**: Removed `var_dump()` calls, replaced with proper logging

### New Features
- Complete refactoring with new class-based architecture
- Redirect to bank statement when all entries are reconciled
- Added PHPUnit test suite with comprehensive tests

### New Classes
- `Camt053Entry.class.php` - Model representing a single bank statement entry
- `Camt053Statement.class.php` - Model representing a complete bank statement
- `Camt053FileProcessor.class.php` - Secure XML parser with XXE protection
- `BankStatementMatcher.class.php` - Logic for comparing file and database entries
- `DatabaseBankStatementLoader.class.php` - Secure database access layer
- `BankEntryReconciler.class.php` - Bank reconciliation operations
- `BankRelationshipLookup.class.php` - Invoice/payment relationship lookups

### Tests
- `Camt053EntryTest.php` - Tests for entry model
- `Camt053FileProcessorTest.php` - Tests for XML parsing including XXE protection
- `BankStatementMatcherTest.php` - Tests for matching algorithm
- `fixtures/sample_camt053.xml` - Sample CAMT.053 file for testing

### Improvements
- Better separation of concerns (MVC-like architecture)
- Improved error handling with proper logging
- Type hints and PHPDoc documentation
- Backward compatibility with existing code

## 1.15.0 (2024)

### Maintenance
- Removed debug print_r calls

## 1.14.0 (2024)

### Bug Fixes
- Fixed bank object handling
- Fixed multiple bank account support
- Fixed multiple statement merging
- Fixed date input handling

## 1.11.0 (2024)

### Bug Fixes
- Various error corrections
- Fixed amount comparison
- Removed salary import feature
- Fixed numeric date parsing

## 1.10.0 (2024)

### Bug Fixes
- Fixed entry date null handling
- Fixed error handling
- Added "already linked" status

## 1.2.0 (2024)

### Bug Fixes
- Fixed missing CAMT.053 entries reading
- Fixed date handling from database

## 1.0.0 (2024)

### Initial Release
- CAMT.053 file upload and parsing
- Bank statement matching by amount and date
- Reconciliation workflow
- Support for multiple bank accounts
