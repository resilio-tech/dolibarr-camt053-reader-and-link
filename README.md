# CAMT.053 Reader And Link - Dolibarr Module

Bank reconciliation module that allows importing bank statements in CAMT.053 format (ISO 20022) and automatically matching them with Dolibarr entries.

## Features

- CAMT.053 file import (European standard XML format)
- Built-in XXE (XML External Entity) protection
- Automatic matching by amount and date
- Configurable date tolerance
- Multiple match handling
- Imported file archiving

---

## Installation

### Prerequisites

- Dolibarr >= 11.0
- PHP >= 7.0
- Bank module enabled in Dolibarr

### Module Installation

1. Copy the `camt053readerandlink` folder into `htdocs/custom/`
2. Enable the module in **Setup > Modules > Financial**

---

## Usage

### Access
Menu: **Bank > CAMT.053 Link**

### Workflow

1. **Upload**: Select a date range and upload the CAMT.053 XML file
2. **Comparison**: The system compares file entries with Dolibarr entries
3. **Validation**: Confirm proposed reconciliations
4. **Archiving**: The file is archived in the statement documents

### CAMT.053 Format

The CAMT.053 format (Cash Management - Bank to Customer Statement) is an ISO 20022 standard used by European banks for account statements. XML structure:

```xml
<Document>
  <BkToCstmrStmt>
    <GrpHdr>...</GrpHdr>
    <Stmt>
      <Acct><Id><IBAN>...</IBAN></Id></Acct>
      <Ntry>
        <Amt>1234.56</Amt>
        <CdtDbtInd>CRDT</CdtDbtInd>  <!-- CRDT=credit, DBIT=debit -->
        <ValDt><Dt>2024-01-15</Dt></ValDt>
        <AcctSvcrRef>...</AcctSvcrRef>
        ...
      </Ntry>
    </Stmt>
  </BkToCstmrStmt>
</Document>
```

### Matching Algorithm

The module compares each file entry with database entries:
- **Amount**: Must match exactly
- **Date**: ±1 day tolerance by default

Possible results:
- **Linked**: Single match found → automatic reconciliation
- **Multiple**: Several matches → manual choice required
- **Not linked**: No match → manual processing needed
- **Already linked**: Entry already reconciled

---

## Architecture

### File Structure

```
camt053readerandlink/
├── class/                              # Business classes
│   ├── Camt053Entry.class.php          # Statement entry
│   ├── Camt053Statement.class.php      # Complete statement
│   ├── Camt053FileProcessor.class.php  # Secure XML parser
│   ├── BankStatementMatcher.class.php  # Matching algorithm
│   ├── DatabaseBankStatementLoader.class.php  # DB loading
│   ├── BankEntryReconciler.class.php   # Reconciliation
│   └── BankRelationshipLookup.class.php  # Third party lookup
├── core/modules/
│   └── modCamt053ReaderAndLink.class.php  # Module descriptor
├── css/
│   └── camt053readerandlink.css        # Styles (status badges)
├── js/
│   └── camt053readerandlink.js.php     # JavaScript
├── admin/
│   ├── setup.php                       # Configuration
│   └── about.php                       # About
├── langs/
│   ├── en_US/camt053readerandlink.lang
│   └── fr_FR/camt053readerandlink.lang
├── test/phpunit/                       # Unit tests
│   └── fixtures/sample_camt053.xml     # Test file
├── index.php                           # Upload page
├── submit.php                          # Processing + comparison
├── confirm.php                         # Reconciliation confirmation
└── statements.php                      # Legacy classes
```

### Flow Diagram

```
┌─────────────────┐
│  XML File       │
│   CAMT.053      │
└────────┬────────┘
         │
         ▼
┌─────────────────┐
│ FileProcessor   │  Parses XML with XXE protection
│                 │  Extracts IBAN, entries, dates
└────────┬────────┘
         │
         ▼
┌─────────────────┐
│ DatabaseLoader  │  Loads Dolibarr entries
│                 │  for selected period
└────────┬────────┘
         │
         ▼
┌─────────────────┐
│ Matcher         │  Compares file ↔ database
│                 │  By amount and date (±1 day)
└────────┬────────┘
         │
         ▼
┌─────────────────┐
│ PREVIEW         │  Displays matches:
│                 │  linked, multiple, not linked
└────────┬────────┘
         │ (user confirmation)
         ▼
┌─────────────────┐
│ Reconciler      │  Updates llx_bank
│                 │  (rappro = 1, num_releve)
│                 │  Archives XML file
└─────────────────┘
```

### Main Classes

#### `Camt053FileProcessor`
Secure XML parser with XXE protection:
- `parseFile()`: Parses from a file
- `parseContent()`: Parses from an XML string
- Blocks `<!ENTITY` declarations
- Uses `LIBXML_NOENT | LIBXML_NOCDATA | LIBXML_NONET` flags

#### `Camt053Entry`
Represents a bank entry:
- `amount`: Amount (negative = debit)
- `value_date`: Value date
- `name`: Label
- `hash`: Unique identifier (AcctSvcrRef)
- `isFromFile`: Origin (file or DB)

#### `Camt053Statement`
Represents a complete statement:
- `iban`: Account IBAN
- `accountId`: Dolibarr account ID
- `entries[]`: Entry list
- Calculations: `getTotalCredits()`, `getTotalDebits()`, `getNetAmount()`

#### `BankStatementMatcher`
Matching algorithm:
- `compare()`: Compares two statements
- `findMatches()`: Finds matches for an entry
- Configurable date tolerance (default: 1 day)

#### `DatabaseBankStatementLoader`
Loads entries from Dolibarr:
- `loadStatements()`: Loads by date range
- Queries `llx_bank` and `llx_bank_account`

#### `BankEntryReconciler`
Performs reconciliation:
- `reconcile()`: Marks an entry as reconciled
- Updates `rappro = 1` and `num_releve`

---

## Development

### Running Tests

```bash
cd htdocs/custom/camt053readerandlink/test/phpunit

# All tests
phpunit

# Specific test
phpunit BankStatementMatcherTest.php
```

### Test File

A test CAMT.053 file is available in `test/phpunit/fixtures/sample_camt053.xml`.

### Dolibarr Tables Used

| Table | Usage |
|-------|-------|
| `llx_bank_account` | Bank accounts (IBAN lookup) |
| `llx_bank` | Bank entries (read + update rappro) |

### Security

The module implements several protections:
- **XXE**: External XML entity blocking
- **Path traversal**: File path validation
- **CSRF**: Tokens on all forms
- **SQL Injection**: Use of `$db->escape()`

---

## Known Issues

### IBAN Not Recognized
The module looks for the file's IBAN in `llx_bank_account.iban_prefix`. If the account is not found, verify that the IBAN is correctly configured in Dolibarr.

### Entries Not Matched
If entries are not automatically reconciled:
- Check that amounts match exactly
- Date can have ±1 day offset maximum
- Verify the entry is not already reconciled

---

## License

GPLv3 - See COPYING file
