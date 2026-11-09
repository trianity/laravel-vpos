# Acceptance evidence and sandbox runbook

This is the Prompt 07 closure report for the pre-release package. It separates deterministic offline evidence from merchant-dependent K&H sandbox work. Only the explicitly authorized GET and POST Echo checks were sent to the K&H sandbox; no payment mutation or production request was made.

## Evidence status vocabulary

- **Offline verified** — a deterministic test uses independent synthetic merchant/gateway keys, denies unfaked HTTP and asserts the wire contract and signature behavior.
- **Sandbox verified** — an explicitly authorized run reached the pinned K&H sandbox with merchant credentials and retained a sanitized result record.
- **Awaiting merchant setup** — implementation and offline coverage exist, but credentials, entitlement, a test card, wallet account or device are required.
- **Confirmed exclusion** — pinned upstream evidence establishes that the operation is not part of the supported eAPI surface.
- **Unresolved gap** — neither adequate implementation evidence nor a justified exclusion exists.

## Offline acceptance result

The supported runtime is PHP 8.4.27 or later in the PHP 8 series with Laravel 13. The package has one client stack: `Vpos`/`KHVpos` delegates to `SignedJsonTransport`, `HostedCardService`, `WalletService` and `BrowserReturnVerifier`. Response signature verification is mandatory. There is no configuration switch that can disable it.

The default suite prevents every unfaked Laravel HTTP request. It contains no live test, credential lookup or skipped sandbox case. Synthetic PEM files under `tests/Fixtures` are unrelated merchant and gateway key pairs and are not accepted by the sandbox harness.

The final local evidence was collected on 2026-11-09 from Prompt 06 commit `bb818a3` plus the reviewable Prompt 07 worktree:

| Check | PHP 8.4.27 | PHP 8.5.12 |
| --- | --- | --- |
| Fresh production-only consumer `composer update --no-dev` | 22 installs; `trianity/laravel-vpos dev-main bb818a3`; Illuminate 13.40.0 | 22 installs; `trianity/laravel-vpos dev-main bb818a3`; Illuminate 13.40.0 |
| Locked Pest suite | 85 passed, 189 assertions, 0 failures, 0 skipped | 85 passed, 189 assertions, 0 failures, 0 skipped |
| Larastan level 5, `src` + `tests` + `sandbox` | No errors | No errors |

Additional gates: Composer 2.10.3 strict validation passed; optimized strict PSR-4 generation produced 8,652 classes without warnings; Pint check mode passed; `git diff --check` passed. The isolated consumer resolutions installed no development dependencies and reported no security advisories.

Authorized K&H sandbox Echo gate on PHP 8.5.12: 2 tests, 6 assertions, 0 failures. Both signed Echo responses verified; POST also matched the sandbox `REQUEST_TEXT_TO_SIGN` header in memory. No payment or production endpoint was called.

The exact acceptance command set is:

```bash
composer validate --strict --no-check-publish
composer dump-autoload --optimize --strict-psr
vendor/bin/pint --test
php8.4 vendor/bin/phpstan analyse -c phpstan.neon.dist --debug --no-progress
php8.4 vendor/bin/pest --compact
php8.5 vendor/bin/phpstan analyse -c phpstan.neon.dist --debug --no-progress
php8.5 vendor/bin/pest --compact
git diff --check
```

`--debug` keeps PHPStan single-process in restricted environments where its normal worker TCP listener is unavailable. It does not reduce the configured level or scope; `phpstan.neon.dist` covers `src`, `tests` and the separate `sandbox` harness.

## Closure of the 17 PHP sample operations

The source links point to the pinned upstream snapshots listed in [the parity matrix](eapi-v1-parity.md#pinned-evidence). Test names are exact Pest descriptions; parameterized cases exercise every listed variant.

| # | Operation | Pinned source | Supported implementation | Passing offline evidence | Status |
| ---: | --- | --- | --- | --- | --- |
| 1 | `POST /echo` | Official **Basic Methods** and PHP `echoPost` sample | `SignedJsonTransport::echoPost()` | `posts top-level JSON and verifies the independently signed Echo response`; tamper, wrong-key, malformed and transport failure cases; authorized sandbox Echo and canonical-header comparison passed | **Sandbox verified** |
| 2 | `GET /echo/...` | Official **Basic Methods** and PHP `echoGet` sample | `Vpos::echo()`, `SignedJsonTransport::echoGet()` | `signs decoded GET values and percent-encodes every path segment once`; authorized signed sandbox Echo passed | **Sandbox verified** |
| 3 | `POST /payment/init` | Official **Basic Methods**, **Request Signing**, PHP `paymentInit` sample | `Vpos::init()`, `HostedCardService::init()` | `initializes standard extended and OneClick-template card payments with signed top-level JSON`; validation and omission cases | **Offline verified** |
| 4 | `GET /payment/process/...` | Official **Basic Methods**, Java/PHP process samples | `Vpos::processUrl()`, `HostedCardService::processUrl()` and low-level `processLocation()` | `generates an exactly-once encoded browser process URL without making a request`; strict 303/Location tests | **Offline verified** |
| 5 | `GET /payment/status/...` | Official **Basic Methods**, PHP `paymentStatus` sample | `Vpos::status()`, `HostedCardService::status()` | `sends signed status close reverse and refund operations with exact amount field names`; action/extension hydration tests | **Offline verified** |
| 6 | `PUT /payment/close` | Official **Basic Methods**, PHP `paymentClose` sample | `HostedCardService::close()` | same lifecycle wire test covers full and partial close; unknown-outcome policy is tested | **Offline verified** |
| 7 | `PUT /payment/reverse` | Official **Basic Methods**, PHP `paymentReverse` sample | `HostedCardService::reverse()` | same lifecycle wire test plus signed business failure and unknown-outcome tests | **Offline verified** |
| 8 | `PUT /payment/refund` | Official **Basic Methods**, PHP `paymentRefund` sample | `HostedCardService::refund()` | same lifecycle wire test covers full and partial refund; reconciliation policy is tested | **Offline verified** |
| 9 | `POST /oneclick/echo` | Official **Methods for OneClick Payment**, PHP OneClick Echo sample | `WalletService::oneclickEcho()` | `covers active and unavailable OneClick template echo results with distinct origPayId identity` | **Offline verified** |
| 10 | `POST /oneclick/init` | Official **Methods for OneClick Payment**, PHP OneClick Init sample | `WalletService::oneclickInit()` | `initializes customer and merchant initiated OneClick payments while keeping template and payment IDs distinct` | **Offline verified** |
| 11 | `POST /oneclick/process` | Official **Methods for OneClick Payment**, PHP OneClick Process sample | `WalletService::process('oneclick', ...)` | `processes browser and SDK fingerprints for all providers...`; merchant-initiated no-fingerprint case | **Offline verified** |
| 12 | `POST /applepay/echo` | Official **Methods for Apple Pay**, PHP Apple Pay Echo sample | `WalletService::providerEcho('applepay', ...)` | `covers Apple Pay and Google Pay echo with typed provider-specific nested parameters`; unavailable case | **Offline verified** |
| 13 | `POST /applepay/init` | Official **Methods for Apple Pay**, PHP Apple Pay Init sample | `WalletService::providerInit('applepay', ...)` | `initializes Apple Pay and Google Pay with integer minor units payload metadata and sdkUsed` | **Offline verified** |
| 14 | `POST /applepay/process` | Official **Methods for Apple Pay**, PHP Apple Pay Process sample | `WalletService::process('applepay', ...)` | browser and SDK fingerprint/challenge cases in the shared process test | **Offline verified** |
| 15 | `POST /googlepay/echo` | Official **Methods for Google Pay**, PHP Google Pay Echo sample | `WalletService::providerEcho('googlepay', ...)` | provider-specific Echo and unavailable cases | **Offline verified** |
| 16 | `POST /googlepay/init` | Official **Methods for Google Pay**, PHP Google Pay Init sample | `WalletService::providerInit('googlepay', ...)` | shared provider-init test and literal canonical-string regression | **Offline verified** |
| 17 | `POST /googlepay/process` | Official **Methods for Google Pay**, PHP Google Pay Process sample | `WalletService::process('googlepay', ...)` | browser/SDK fingerprint and frictionless/challenge cases in the shared process test | **Offline verified** |

`echo/customer` is a **Confirmed exclusion**: it is absent from the executable PHP modes and the pinned official changelog removes it. Custom payment links, low-value payment, activated extensions and all three reusable/wallet families are **Awaiting merchant setup** for bank-side behavior. There is no unresolved offline parity gap among the 17 sample operations.

## Dependency and surface checks

Fresh production-only consumer resolution must be performed in disposable directories, once with PHP 8.4.27 and once with PHP 8.5. It must require this package through a path/VCS repository and run `composer update --no-dev`; copying this repository's lock file is not a fresh resolution. Record the resolved Laravel and package versions.

This acceptance used `/tmp/vpos-consumer-84` and `/tmp/vpos-consumer-85`. Each project required its exact PHP runtime and `trianity/laravel-vpos:dev-main` from the local path repository. Both independently selected Illuminate 13.40.0. There is no runtime restriction unique to either supported PHP version.

The development toolchain is checked separately because it is not installed for consumers. Both supported PHP executables must run the locked Pest and Larastan suites. A toolchain-only restriction does not narrow the runtime claim unless fresh `--no-dev` resolution also fails.

There is no direct or transitive installed dependency on `spatie/laravel-data`, `json-mapper/json-mapper` or `json-mapper/laravel-package`. Mentions of other Spatie packages in upstream packages' `require-dev`, `suggest` or `replace` metadata do not install Laravel Data and are not runtime dependencies. The abandoned clients, DTOs, mappers, crypto wrappers and raw cURL implementation are absent.

## Sandbox safety gate

Sandbox acceptance is deliberately outside `phpunit.xml.dist`, so the offline suite never skips or contacts a network. `composer test:sandbox` runs only `sandbox/SandboxEchoTest.php` and fails closed unless the explicit opt-in and all required values are present. It loads the gitignored `tests/.env`, using the package's canonical `KH_VPOSID`, `KH_TEST_PRIVATE_KEY` and `KH_TEST_PUBLIC_KEY` names. `KH_VPOSID` is the signed eAPI `merchantId`.

Keep `tests/.env` and the referenced key files untracked. This repository's `/key/` directory is gitignored and accepted by the harness; tracked fixtures and every other tracked repository path are rejected. Run from an isolated shell:

```bash
export KH_SANDBOX_ACCEPTANCE=1
composer test:sandbox
```

The harness uses `https://api.sandbox.khpos.hu/api/v1.0/` and permits only that exact HTTPS endpoint. An optional `KH_SANDBOX_API_URL` can repeat this value for an explicit CI setup, but a production host or alternate host/path is rejected. A missing opt-in, unreadable key or key in a tracked repository path also aborts before a request. Laravel HTTP allows only URLs below the sandbox prefix during the run.

The production-host negative probe was executed locally with `https://api.khpos.hu/api/v1.0/`: both Echo cases aborted in setup with `Sandbox acceptance only permits the pinned K&H sandbox endpoint`, before key loading or network I/O.

Both Echo cases sign locally and verify the signed response. The sandbox exposes `REQUEST_TEXT_TO_SIGN` for POST Echo, where the harness compares it to the local canonical string in memory with `hash_equals()`. The successful GET Echo response did not include that diagnostic header, so GET evidence relies on its verified response signature and result. Assertion failures contain no canonical value, signature, key or response payload. Do not add verbose HTTP logging or save test output containing headers.

After the run, clear the shell variables. Do not commit `.env` files, credentials, auth files, PANs, wallet payloads, signed URLs, `payId` values or raw gateway responses.

## Ordered merchant acceptance procedure

Obtain the current K&H sandbox credentials and test-card instructions from merchant onboarding. Never invent card data or reuse a production card. Execute each mutating case with a fresh sandbox transaction and retain only a sanitized checklist containing date, commit SHA, operation, result code, payment status and pass/fail.

### 1. Echo gate

1. Run `composer test:sandbox` before every transaction family.
2. Require both GET and POST Echo to return authenticated, correctly shaped responses.
3. Require the POST Echo in-memory `REQUEST_TEXT_TO_SIGN` comparison to pass; require GET Echo's response signature and successful result.
4. Stop on a signature, endpoint or canonical mismatch. Do not continue to payments.

Current state: **Sandbox verified** on 2026-11-09 for signed POST and GET Echo. POST canonical text matched `REQUEST_TEXT_TO_SIGN`; GET returned no diagnostic header but its signed response verified successfully.

### 2. Hosted-card activation scenarios

Bind `CheckoutDataProvider` in a disposable Laravel acceptance application. For every case, authorize a fresh server-owned order, durably store the verified Init `payId` before handoff, verify any browser return, and query signed status before changing the order.

Run four independent K&H activation cases:

1. **Successful hosted payment:** complete payment with a K&H-provided sandbox card, execute any ACS action, verify the return and prove one idempotent fulfillment transition from correlated status. Repeat without returning the payer browser and prove stored-`payId` reconciliation reaches the same safe outcome.
2. **Buyer cancellation:** cancel on the hosted payment page, verify the returned payload when present, and confirm signed status leaves the order unfulfilled and safely retryable only under host policy.
3. **Expiry:** create a short-lived fresh transaction using an allowed sandbox TTL, do not complete it, wait for documented expiry, and confirm signed status plus host reconciliation without a second automatic Init.
4. **Reversal:** create a separate transaction in the K&H-documented reversible state, call `reverse()` once, then reconcile the requested `payId` through signed status. Treat an uncertain response as operator-review work, not permission to retry.

Current state: **Awaiting merchant setup** for all four scenarios; requires K&H activation, a test card and a reachable HTTPS return URL.

### 3. Manual close and partial refund

Use separate fresh transactions after the Echo gate and hosted activation:

- **Manual close:** initialize with `closePayment=false`, authorize using a K&H test card, confirm status, call full or lower `close()`, then confirm the final signed status and amount.
- **Partial refund:** create and settle a separate refundable transaction, call `refund()` once with a lower positive amount, and poll `status()` because refund processing is asynchronous.

Record signed business failures as authenticated results. For any close/refund uncertain outcome, query status and require operator review; do not issue a blind retry.

Current state: **Awaiting merchant setup**; allowed state transitions and settlement timing must be confirmed with K&H.

### 4. OneClick template and reuse

1. Have K&H enable OneClick for the sandbox merchant.
2. Create a fresh hosted-card transaction with `payOperation=oneclickPayment` and complete its card/3DS flow.
3. Confirm the template with `oneclickEcho()` using the original template `payId`; verify any `maskClnRP` extension before exposure.
4. Create a new customer-initiated reuse payment with a new order number and IP, execute fingerprint/authentication actions, then reconcile its new `payId` through status.
5. In a separate authorized scenario, exercise merchant-initiated reuse and document why the host is entitled to omit a fingerprint.
6. Confirm that template and new transaction identifiers are never interchanged.

Current state: **Awaiting merchant setup**; requires OneClick entitlement and fresh template/payment transactions.

### 5. Apple Pay and Google Pay

Run each provider independently:

1. Obtain the provider entitlement for the K&H sandbox merchant and require provider Echo to report availability.
2. For Apple Pay, provision the sandbox merchant identity/domain or app entitlement and use a compatible test device/account. For Google Pay, provision the K&H test gateway merchant settings and a compatible test device/account.
3. Obtain a fresh provider payload through the official sandbox UI/SDK. Never store it in fixtures or logs.
4. Call provider Init, execute the returned browser or mobile SDK fingerprint action, then call Process and execute any authentication challenge.
5. Correlate the returned `payId`, verify all signatures/extensions and reconcile through hosted-card status.

Current state: **Awaiting merchant setup** for both providers; no entitlement, real wallet token or compatible acceptance device was available.

## Host callback responsibilities

The package intentionally registers no public callback route. The host application owns authentication/CSRF decisions appropriate to the bank return method, order lookup, durable `payId` association, replay protection, idempotent state transitions, persistence, fulfillment and reconciliation scheduling. A verified browser return is not sufficient authority to fulfill; signed status is authoritative.

No sandbox or production scenario may be reported as verified unless it was explicitly authorized, actually executed against the pinned sandbox host and recorded in the sanitized checklist. At this report's creation, only GET and POST Echo are **Sandbox verified**; every payment, OneClick and wallet scenario remains **Awaiting merchant setup**.
