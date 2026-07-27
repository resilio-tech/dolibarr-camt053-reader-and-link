# CAMT.053 Reader And Link, module specification

What the module must do, and what it must never do. Any change that breaks a
rule below is a regression, whatever the reason.

---

## 1. Purpose

Import a CAMT.053 file (ISO 20022) coming from **one bank**, match its entries
against the Dolibarr bank lines of the matching account, and reconcile them
(`num_releve` + `rappro`). The statement file is then archived under the bank
account so it shows on the Dolibarr statement page.

Two entry points, same rules:

- **Manual**: upload from the module page (`index.php` > `submit.php` > `confirm.php`).
- **Automatic**: daily cron fetching files over SFTP (`Camt053CronRunner`).

---

## 2. Entities

The rules that matter most, because Dolibarr shares nothing by default and the
module must not invent sharing.

| Situation | Expected behaviour |
| --- | --- |
| File whose IBAN belongs to an account of entity 2, user on entity 1 | Nothing is found. No reconciliation, no archiving. The user is warned (`Camt053IbanNotInCurrentEntity`) |
| File whose IBAN belongs to an account of entity 2, user on entity 2 | Reconciled and archived in the accounting of entity 2 |

Consequences:

- Every bank account and bank line lookup uses `getEntity('bank_account', 0)`.
  The `0` is mandatory: the default `1` adds the entities that share the element
  through multicompany, which would let entity 1 reach an account of entity 2.
- Archiving goes through `$conf->bank->dir_output` and `ecm_files` on
  `$conf->entity`. This is correct **because** the lookup is strict: the account
  entity and the browsing entity are always the same one.
- Uploaded files and cron leftovers are stored under
  `DOL_DATA_ROOT/camt053readerandlink/<entity>/`, never in a directory shared by
  several entities. The cron takes that entity from the SFTP config it is
  processing, not from `$conf->entity`, since `fetchAll()` follows the sharing
  configured for the config table.
- The module is activated per entity. Its menu entry lives in `llx_menu` with
  `entity = $conf->entity`, so it must be enabled from each entity that needs it.

**Never** widen a lookup to reach an account outside the current entity, and
never route a write towards another entity.

---

## 3. Reconciliation

Must:

- Match on amount and value date, with a tolerance of 1 day.
- Report as ambiguous, never reconcile automatically, when several Dolibarr
  lines match one CAMT entry. The user chooses in a dropdown.
- Split a collective booking (grouped salary transfers) so each underlying
  transfer can be matched on its own.
- Stay idempotent: a file already processed leaves the database unchanged.
  The cron tracks this in `llx_camt053readerandlink_processedfile` keyed on
  `(file_hash, entity)`.

Must not:

- Reconcile an ambiguous entry by picking one candidate.
- Reconcile a line that already carries a `num_releve`.
- Touch anything when parsing fails. The file is neither recorded as processed
  nor deleted from the SFTP server, so the next run retries it.

---

## 4. Payment suggestions

For an entry present in the file but absent from Dolibarr, the screen offers a
one-click action:

- debit: unpaid supplier invoice, expense report or social charge
- credit: unpaid customer invoice
- counterparty IBAN belonging to another company account: internal transfer

Filtered by entity and currency, compared on the remaining due amount, so
partial payments work. A foreign currency invoice is prefilled through the
`multicurrency_amount_<id>` field, not the company currency one.

These are suggestions. The module opens a prefilled page, it never records a
payment on its own.

---

## 5. Archiving

- Target: `<bank dir_output>/<account id>/statement/<num_releve>/`, the exact
  directory the Dolibarr statement page reads.
- Move the physical file **first**, index it in `ecm_files` afterwards. Indexing
  first leaves an orphan row pointing at a missing file, and Dolibarr then
  refuses a manual attachment claiming the file already exists.
- Identify by content, not by name: banks reuse a single remote name for every
  statement, so a same named file holding different content gets its own copy.
- No bank account resolved means no archiving. The upload is kept and the user
  is warned (`StatementFileNotArchived`), the file is never silently dropped.

---

## 6. Security

- XXE protection on every parse. External entities are disabled and declarations
  are rejected.
- `.xml` extension and MIME type checked on upload.
- Path traversal guard on the file path travelling between `submit.php` and
  `confirm.php`: it must resolve inside the entity upload directory.
- CSRF token required on every writing page, including `confirm.php`.
- Rights: `banque->lire` to read, `banque->consolidate` to reconcile, which is
  what Dolibarr core requires for the same operation.
- SFTP secrets (private key, passphrase, password, Zulip API key) are encrypted
  at rest with `dolEncrypt`/`dolDecrypt`, read with the `none` filter, and the
  private key is never rendered back in the edit form.
- A single SFTP login attempt per run: PostFinance locks the account after three
  failures.

---

## 7. Out of scope

The module does not:

- create or modify bank accounts
- record payments, invoices or transfers by itself
- move data between entities
- handle formats other than CAMT.053

---

## 8. Conventions

- No explanatory comments in the code. Only the doc blocks required by the
  Dolibarr coding standard.
- No em dash anywhere, including code and commit messages.
- English in the code, the tests and everything published on GitHub.
- Every fix comes with a PHPUnit test under `test/phpunit/`, runnable without a
  Dolibarr database (the Dolibarr functions used are stubbed).
