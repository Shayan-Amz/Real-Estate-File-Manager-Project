# Academic-version validation

These tests are not Whisper benchmarks, LLM accuracy measurements, production
load tests, or a real-user study. They never use the host config or provider keys.
Test credentials and signed sample tokens are synthetic, not deployment secrets.

Install tools outside the repository:

```sh
npm install --prefix /tmp/thesis-tools --no-audit --no-fund \
  @php-wasm/cli@3.1.53 php-parser@3.2.5 jsdom@26.1.0 jose@6.1.0
```

## JWT profile — 23 tests

```sh
php academic/tests/jwt_test.php
# or:
/tmp/thesis-tools/node_modules/.bin/php-wasm-cli academic/tests/jwt_test.php
```

Checks standard compact HS256 encoding, Unicode, tampering, issuer/audience,
claim types, lifetime boundaries, algorithm/key confusion, malformed/legacy
tokens, independent contexts, key requirements and timezone independence.
The WASM CLI can return shell exit code zero even when PHP exits nonzero; check
that the printed summary says `23 JWT tests; 0 failures.`

Bidirectional interoperability was additionally checked with the independent
`jose` library. `jwt_test.php --interop-issue` emits a synthetic sample, and
`jwt_test.php --verify-peer <file>` checks an independently signed test token.
These samples deliberately use a public fixture key and fixed time.

## ASR parser / LLM transport — 6 fixture tests

```sh
php academic/tests/ai_test.php
```

Provider responses are fixtures. Tests preserve the baseline system prompt,
configured single model, endpoint order, JSON/no-JSON behavior, bounded retries,
error retention and absence of credentials in returned metadata. No model call
is made and fixture success is NOT model accuracy.

## Scoring — 13 tests; UI — 7 tests

```sh
NODE_PATH=/tmp/thesis-tools/node_modules node --test \
  academic/tests/metrics.test.cjs academic/tests/ui.test.cjs
```

Includes declared normalization, WER counts/denominators, unbounded WER when
insertions exceed reference length, field aliases, numeric type errors, extras,
missing values, null metrics, percentile interpolation, coverage, failure
retention, raw versus corrected outputs, manual-only operation, password/token
non-persistence and cancelled microphone-permission races.

## API HTTP paths — 10 real-PHP tests, in memory

```sh
NODE_PATH=/tmp/thesis-tools/node_modules node academic/tests/http.test.cjs
```

Executes the actual evaluator API with PHP 8.5/WASM against an in-memory fake
site config and filesystem. Covers owner authentication, explicit consent,
JWT issuance/validation, denied writes, origin/method checks, safe metadata and
fail-closed state corruption. It calls no cloud service and uses no database.
The runtime loader requires `emscriptenOptions.processId` in this version.

## Deployment limitations

This validates local code, not the real host's PHP-FPM/Apache setup, available
provider quota, network, model quality, or real audio. Production code files in
`src/` are unchanged; the isolated defense copy is not silently deployed.
Real measurements must be collected through `thesis-evaluation` and imported
before reporting quantitative project results.
