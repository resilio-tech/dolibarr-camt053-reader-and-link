# CHANGELOG CAMT053READERANDLINK FOR [DOLIBARR ERP CRM](https://www.dolibarr.org)

## 2.0.2-pre2 (2026)

### New Features
- SFTP auto-fetch of CAMT.053 files (PostFinance MFTPF), headless reconciliation and Zulip report
- Payment and internal transfer suggestions for unmatched CAMT.053 entries

### Bug Fixes
- Split a collective (batch) CAMT.053 booking into one entry per `<TxDtls>` so a grouped salary transfer (one bank debit, one detail line per employee) reconciles against Dolibarr's individual salary bank lines instead of matching nothing. The split is only applied when the detailed amounts reconstruct the group total, otherwise the entry is kept whole
- Read the counterparty name from the ISO-correct related party (creditor for a debit, debtor for a credit, as the counterparty IBAN already did), falling back to the other tag, so an outgoing payment now shows the beneficiary instead of the account owner
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
- `Camt053FileProcessorTest.php` - splitting a collective salary booking into per-transaction entries, and keeping the entry whole when the detail is partial
- `PaymentSuggestionFinderTest.php` - payment link building and currency handling
- `EntityScopeSqlTest.php` - entity scoping of the IBAN and bank-line queries
- `BankRelationshipLookupTest.php` - related document lookup for the reconciliation dropdown
- `DatabaseBankStatementLoaderTest.php` - date window and in/out-of-period flagging
- `Camt053StatementTest.php` - entry hash uniqueness

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
