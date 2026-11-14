# hkm-plugin-loan-expenses

An [HKM Kernel](https://github.com/AlfaCode-Team/hkm-kernel) plugin providing
**`expenses.management`** — part of the **akasente** loan-management set.

Operating expenses a branch pays out of its cash: boda fares, airtime, rent, staff lunch. The category is a closed enum so a typo cannot fragment reports. Money is whole UGX. Every write takes a sync revision; deletes are tombstones and tombstoned rows are excluded from listings and totals alike.

## Install

```bash
hkm plugins install https://github.com/AlfaCode-Team/hkm-plugin-loan-expenses.git
hkm plugins enable loan-expenses
```

It installs as `plugins/LoanExpenses` with the namespace `Plugins\LoanExpenses\`, because
`hkm` takes the folder from `module.json`'s `name`, not from the repository.

## What it owns

Tables, shipped as `database/tenant-template/` migrations and published into a
project on enable:

- `expenses`

Requires the domains: `database.management`, `sync.offline`, `organisation.structure`

Publishes:

- `Plugins\LoanExpenses\API\Contracts\ExpenseServiceContract`

## Routes

| Route | Name |
|---|---|
| `GET /api/expenses` | `expenses.index` |
| `POST /api/expenses` | `expenses.store` |
| `GET /api/expenses/reports/by-category` | `expenses.report.category` |
| `GET /api/expenses/reports/by-branch` | `expenses.report.branch` |
| `GET /api/expenses/reports/daily` | `expenses.report.daily` |
| `GET /api/expenses/{id:num}` | `expenses.show` |
| `PUT /api/expenses/{id:num}` | `expenses.update` |
| `DELETE /api/expenses/{id:num}` | `expenses.destroy` |

## Every write takes a sync revision

Each row carries `rev`, `device_id`, `created_at`, `updated_at` and
`deleted_at`. `rev` comes from `sync.offline`'s counter and is the cursor a
handset pulls against; a delete is a tombstone, never a `DELETE`. Ids are
client-minted from a claimed shard, so a phone with no signal can create a row
that will never collide with one the server made.

## Tests

```bash
HKM_KERNEL_PATH=/path/to/hkm-kernel phpunit
```

Check these out beside this repository (or install them) before running the suite: `hkm-plugin-database`, `hkm-plugin-loan-organisation`, `hkm-plugin-loan-sync`.

The suite migrates the real tenant template into a throwaway database and
drives the real adapter against it. `HKM_TEST_DB` picks which database that is:

```bash
phpunit                                                  # SQLite (default, no server)
HKM_TEST_DB=mysql  HKM_TEST_DB_USERNAME=root  phpunit
HKM_TEST_DB=pgsql  HKM_TEST_DB_PORT=5432      phpunit
HKM_TEST_DB=sqlsrv HKM_TEST_DB_USERNAME=sa    phpunit
```

The account needs permission to CREATE DATABASE; each run provisions
`hkm_test_<suite>_<random>` and drops it in teardown.

**Run at least one non-SQLite driver before believing a green suite.** SQLite
has no strict mode, no real column types, it tolerates a named placeholder used
twice, and it serialises writers for free — so it hides the whole class of
defect this code is most likely to have.

## Licence

MIT. See [LICENSE](LICENSE).
