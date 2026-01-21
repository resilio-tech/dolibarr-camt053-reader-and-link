# CHANGELOG CAMT053READERANDLINK FOR [DOLIBARR ERP CRM](https://www.dolibarr.org)

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

## 1.15

Previous release (various fixes)

## 1.0

Initial version
