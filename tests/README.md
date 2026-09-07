# Active recovery regression checks

The unsuccessful fix27 guest-pagination feature has been retired from the active
application. Its source and tests remain available in Git history (`4a2c01c`),
and its old ZIP is historical, not a deployment recommendation. Runtime business
logic is restored to the accepted fix26 baseline (`a24348e`).

## SQL scope — 9 tests

```sh
PYTHONDONTWRITEBYTECODE=1 python3 -m unittest discover -s tests -p 'test_*.py' -v
```

These execute the actual getData SELECTs against synthetic in-memory SQLite
fixtures. They do not access the live database or execute a MySQL migration.

## Actual browser startup — 10 checks

```sh
npm install --prefix /tmp/recovery29-tools --no-audit --no-fund \
  puppeteer-core@24.17.1 @sparticuz/chromium@138.0.2
NODE_PATH=/tmp/recovery29-tools/node_modules node tests/recovery29.browser.cjs
```

Uses headless Chromium with every HTTP request intercepted. It reproduces the
old parser-blocking Neshan dependency, then verifies recovery startup with all
optional resources deliberately stalled. Also covers restored guest sessions,
bad/blocked browser storage, manager data, fresh login, healthy optional local
assets, API 500, and a bounded timeout for a stalled server request. No production
host, real account, map service, model, or database is contacted.

On minimal Linux sandboxes the script extracts the NSS/NSPR libraries already
included in the pinned Chromium package. Screenshots go outside the repo, under
`~/.cache/amlak-recovery29`.

## Recovery service worker — 5 tests

```sh
node --test tests/recovery29.worker.test.cjs
```

Verifies that only old app-shell caches are removed, the main/new API paths are
excluded, unrelated caches and normal image HTTP caching remain intact, and failed
script requests are never replaced by cached HTML.

## Safety and deployment boundary

The restored `api.php` and `stt.php` match the approved baseline. Temporary fresh
PHP entry files bypass reliance on old compiled paths; their differences are only
a version header, a guard, and the path to the copied speech helper. No config,
SQL dump, user upload, or database rollback is included in the ZIP.

Local regression success does not prove the host is recovered. The user must
open `recover29.html?api.php=29` after installation and confirm the visible build
marker and login/data behavior. Academic work stays paused until that confirmation.
