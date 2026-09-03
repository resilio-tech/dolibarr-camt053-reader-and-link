# CAMT.053 dev test environment

Helpers to exercise the module in the Dolibarr docker-dev stack, which lives in
the Dolibarr checkout under `dev/build/docker-dev`. The PHP scripts bootstrap
Dolibarr inside the `web` container, so they are run with `docker cp` +
`docker exec`.

## One-time setup

- `setup.php` enables the module (+ `modBanque`), creates the EUR bank account
  `CAMT-TEST` (IBAN `BE71096123456769`) and 3 unreconciled lines matching the
  original module fixture.
- `seed_scenarios.php` seeds the bank lines for the matching cases (below).
- `seed_payments.php` enables invoicing + supplier modules, creates two third
  parties, validated unpaid EUR invoices, and a second bank account
  `CAMT-TEST-2` (IBAN `BE68539007547034`) for the internal-transfer case.

## Scenario coverage

Upload `camt053_scenarios.xml` with date range **01/02/2024 → 29/02/2024**.
Each file entry exercises a different branch of the module:

### Reconciliation (bank line already in Dolibarr)

| Entry           | Amount / date  | Bank line(s)                    | Expected status  |
|-----------------|----------------|---------------------------------|------------------|
| SC-CREDIT-1500  | +1500 · 01/02  | one unreconciled line           | linked (auto)    |
| SC-CREDIT-800   | +800  · 05/02  | two lines (05/02 + 06/02)       | multiples        |
| SC-CREDIT-999   | +999  · 15/02  | one line already rapproché      | already_linked   |
| SC-DEBIT-125    | -125  · 20/02  | one unreconciled line           | linked (debit)   |

### Not in Dolibarr bank -> suggestion on the results page

| Entry            | Amount / date  | Seeded data                      | Expected suggestion            |
|------------------|----------------|----------------------------------|--------------------------------|
| SC-CREDIT-333    | +333.33 · 10/02| none                             | none (manual processing)       |
| SC-CREDIT-450    | +450  · 03/02  | 1 unpaid customer invoice 450    | create customer payment        |
| SC-DEBIT-275     | -275  · 08/02  | 1 unpaid supplier invoice 275    | create supplier payment        |
| SC-CREDIT-600    | +600  · 12/02  | 2 unpaid customer invoices 600   | choice between the candidates  |
| SC-TRANSFER-2000 | -2000 · 18/02  | 2nd account CAMT-TEST-2 (counterparty IBAN) | internal transfer   |

## Commands

```bash
cd dev
run() { docker cp "$1" docker-dev-web-1:/tmp/x.php && docker exec docker-dev-web-1 php /tmp/x.php; }

run setup.php            # module + first account + fixture lines (idempotent)
run seed_scenarios.php   # bank lines for the reconciliation cases (idempotent)
run seed_payments.php    # invoices + 2nd account for the suggestion cases (idempotent)
run reset_scenarios.php  # un-reconcile scenario lines to replay the flow
run seed_postfinance.php # PostFinance CHF account + lines for the SFTP test
```

Headless proof (no UI), print each entry's classification and suggestions:

```bash
docker cp camt053_scenarios.xml docker-dev-web-1:/tmp/x.xml
docker cp match_check.php       docker-dev-web-1:/tmp/x.php
docker exec -e CAMT_XML=/tmp/x.xml docker-dev-web-1 php /tmp/x.php   # buckets

docker cp suggestions_check.php docker-dev-web-1:/tmp/x.php
docker exec -e CAMT_XML=/tmp/x.xml docker-dev-web-1 php /tmp/x.php   # pay / transfer links
```

## SFTP test (PostFinance)

Account `PF-TEST` (id 3, IBAN `CH3089144455991389966`, CHF) and its two bank
lines are already in the seed. To recreate them elsewhere:

```bash
docker cp seed_postfinance.php docker-dev-web-1:/tmp/sp.php
docker exec -e PF_DATE=2026-08-03 docker-dev-web-1 php /tmp/sp.php
```

`PF_IBAN`, `PF_CCY` and `PF_DATE` override the defaults. The script is
idempotent (keyed on the IBAN and the line labels).

`make_camt053.php` generates the matching statement to drop on the bank test
server. It needs the PHP CLI of the container (no php on the host):

```bash
docker cp make_camt053.php docker-dev-web-1:/tmp/mk.php
docker exec docker-dev-web-1 php /tmp/mk.php \
    --iban=CH3089144455991389966 --ccy=CHF --date=2026-08-03 --out=/tmp --targz --zip
docker cp docker-dev-web-1:/tmp/camt053_20260803_389966.xml out/
```

Options: `--iban` (required), `--date` (default today), `--ccy` (default CHF),
`--out`, `--ref` (AcctSvcrRef prefix), `--targz`, `--zip`. Output holds 3 entries
(+1500 credit with an ESR reference, -275 debit, +450.50 credit) and OPBD/CLBD
balances. The `.xml`, `.tar.gz` and `.zip` variants all round-trip through
`SftpFileTransport::extractXmlPayloads()`.

Against the seeded account the first two entries reconcile automatically and
+450.50 stays unmatched on purpose (no bank line, no invoice), which exercises
the suggestion path:

```bash
docker cp out/camt053_20260803_389966.xml docker-dev-web-1:/tmp/x.xml
docker cp match_check.php docker-dev-web-1:/tmp/x.php
docker exec -e CAMT_XML=/tmp/x.xml -e CAMT_FROM=01/08/2026 -e CAMT_TO=31/08/2026 \
    docker-dev-web-1 php /tmp/x.php
```

`CAMT_FROM` / `CAMT_TO` default to the February 2024 scenario range.

The IBAN must exist on a Dolibarr bank account, otherwise the cron reports
"unresolved IBAN", archives the raw file and never reconciles anything.

SFTP configuration lives in **Bank > CAMT.053 Link > Setup > SFTP**:

| Field                 | Test value                                       |
|-----------------------|--------------------------------------------------|
| host / port           | PostFinance MFTPF host, port 8022                |
| auth type             | `key` (PEM only) or `password`                   |
| remote dir            | `yellow-net-reports` (`-t` suffix on test)       |
| daily / monthly pattern | leave empty to pick up every file, or a PCRE with delimiters |
| post download action  | **leave** while testing, `delete` removes the remote file |

Constraints read from the code:

- The `ssh2` PHP extension is required. It is not in the upstream docker-dev
  image; the local Dockerfile of that stack adds it.
- A single login attempt is made per run: PostFinance locks the account after 3
  failures, so a wrong password costs a lockout, not a retry.
- OpenSSH-format private keys are rejected. Convert with
  `ssh-keygen -p -m PEM -f <keyfile>`.
- Files already downloaded are skipped on their sha256, so re-uploading the very
  same bytes is a no-op. Change `--date` or `--ref` to get a fresh file.

Run the fetch manually:

```bash
docker exec docker-dev-web-1 php \
    /var/www/html/custom/camt053readerandlink/scripts/camt053_fetch_and_reconcile.php admin
```

## UI test

1. Log in at http://localhost (admin / admin)
2. Menu **Bank > CAMT.053 Link**
3. Upload `camt053_scenarios.xml`, range 01/02/2024 → 29/02/2024
4. Reconcile the matched entries; for the unmatched ones follow the suggested
   "create payment" / "internal transfer" links
5. `run reset_scenarios.php` to replay the reconciliation part from a clean state

## Notes

- `seed_payments.php` is idempotent (keyed on `note_private` markers), so it will
  not create duplicate invoices. To retest payment creation after paying an
  invoice in the UI, change the marker or add a fresh invoice.
- The bank accounts' opening lines (amount 0, today's date) sit outside every
  test date range and are harmless.
- Invoice creation logs `Undefined property ...dir_output` warnings under the
  minimal CLI bootstrap: cosmetic, the invoices are created and validated.
- `phpunit` is not installed in the container. The module unit tests under
  `test/phpunit/` need a `phpunit` phar or a `composer install` first.
