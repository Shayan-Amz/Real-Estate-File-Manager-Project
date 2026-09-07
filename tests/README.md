# Regression tests (fix26 / fix27)

Run from the repository root. No test contacts the host, reads `src/config.php`,
uses real credentials, or changes a production database. Do not upload `tests/`
to `public_html`.

## Authenticated data scope — 8 tests

```sh
PYTHONDONTWRITEBYTECODE=1 python3 -m unittest discover -s tests -p 'test_*.py' -v
```

Extracts the real authenticated `getData` SELECTs and executes them on in-memory
SQLite fixtures. The earlier guest assertions moved to the PHP suite when fix27
retired the unbounded guest `getData` response.

## Public API — 18 tests

```sh
php tests/guest_api_test.php
```

Requires PHP with PDO SQLite and OpenSSL (AES-256-GCM). Executes the production
`guest_api.php` functions, including the actual prepared SQL, typed numeric
bindings, public serialization, encrypted selectors/cursors, and page hashes.
Also checks the public-action allowlist and legacy-client gate in `api.php`.

Covers tied/null sort keys, full cursor traversal, empty/deleted pages, literal
LIKE characters, Persian/Arabic normalization, all numeric/type filters, hidden
prices/images, agency counts, metadata, tampering, wrong-query cursors, and the
`unmodified` short circuit. Fixtures are synthetic and the database is in memory.

When native PHP is unavailable, the same suite can run via WebAssembly:

```sh
npm install --prefix /tmp/amlak-test-tools --no-audit --no-fund @php-wasm/cli@3.1.53
/tmp/amlak-test-tools/node_modules/.bin/php-wasm-cli tests/guest_api_test.php
```

Verify the summary says `18 PHP/SQLite tests; 0 failures.` The WASM CLI version
used here does not reliably propagate PHP's exit status to the shell. Native PHP
does return a nonzero status for a failure. Validation for this change used PHP
8.5.10/WASM; production files also parse with PHP 7.4 grammar.

Optional: `php tests/guest_api_test.php --dump-sql` emits the actual generated
SELECT shapes. The 10 distinct shapes and two additive index statements were
also parsed in MySQL mode with `node-sql-parser@5.3.13`. This is a dialect check,
not a real MySQL execution plan or migration test.

## Request state machine — 14 tests

```sh
node --test tests/guest_pager.test.cjs
```

Uses Node's built-in test runner, with no external dependencies. Tests debounce,
request cancellation, stale responses, page/hash isolation, retry, backoff,
timeout, independent views, and bounded page data retention.

## Actual HTML/UI integration and service worker — 10 tests

```sh
npm install --prefix /tmp/amlak-test-tools --no-audit --no-fund jsdom@26.1.0
NODE_PATH=/tmp/amlak-test-tools/node_modules node --test tests/guest_ui.test.cjs
```

Runs the real inline application script and guest controller against a fixture
HTTP API inside jsdom. Covers fresh/restored guest sessions, filters, navigation,
directory counts/selectors, gallery/details/phone links, escaped directory names,
private-cache isolation, unchanged manager startup/cards, and versioned shell
caching/API cache exclusion. It does not load external assets or make network
requests. This is DOM integration testing, not a real-browser visual test.

## Deployment checks still required

All 50 local tests passed. No production load test, real MySQL/MariaDB migration,
or host PHP-FPM/Apache execution has been performed. After deployment, test both
authenticated roles and all three guest views; check health-check sections 1
(OpenSSL/AES-GCM) and 5 (the two new public-pagination indexes). See the Persian
README in `fix27-guest-pagination.zip` for installation and rollback instructions.
