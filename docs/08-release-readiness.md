# Prompt 08 — Fix release blockers and prepare the public RC

Work in `trianity/vpos` after Prompts 01–07. Start from the current implementation branch. The review was pinned to [`48ce91c`](https://github.com/trianity/vpos/commit/48ce91c7a95cddaa2762369c2e88eb5df48d98ee). Read [the prompt index](README.md), [the acceptance evidence](acceptance-and-sandbox.md), [the hosted-card lifecycle](hosted-card-lifecycle.md), and [the Laravel integration guide](laravel-entry-points.md). Verify each finding against the current code before editing. Use the source snapshots in the index for eAPI contract decisions.

Keep the single signed client stack, Laravel 13, PHP 8.4.27 and 8.5 CI coverage, and mandatory verification of signed gateway responses. Keep database and order-state policy in the host application. Use synthetic credentials and deny stray HTTP throughout the default suite.

## 1. Persist the payment reference before browser handoff

Fix the normal Laravel checkout route. Once `payment/init` has returned a verified successful response, give the host a synchronous way to persist the `payId` against the same trusted order before emitting the redirect to `payment/process`. Extend or reuse the checkout-provider boundary with the smallest explicit contract that supports this. Document any host integration change.

- Redirect only after the host confirms durable storage. On missing configuration or a persistence failure, fail closed without a handoff or automatic re-init. Make that failure visible to the host so it can handle an already-created bank transaction.
- Document how the host authorizes the order lookup, handles repeated or concurrent submissions, and recovers or reconciles an existing payment attempt. Include an end-to-end example with an authorized server-owned order, persistence before handoff, signed callback verification, and a status lookup when the browser never returns. Keep fulfillment idempotent and driven by verified, correlated status.
- Add feature tests proving the exact order reference and verified `payId` reach the host, storage completes before redirect, persistence failure produces no handoff or automatic retry, and an unsuccessful or unverified init never records a successful attempt.

## 2. Correlate mutation responses and expose uncertain outcomes

In the shared hosted-card service, match the verified response `payId` to the requested `payId` for `close`, `reverse`, and `refund`, as already done for `status`. Reject a signed response for a different payment before returning it as the operation's result. Keep the requested identifier for reconciliation.

- Distinguish a verified business result, a documented definitive rejection, and a request that may have reached K&H but has no trustworthy outcome. Cover connection loss, HTTP 5xx, malformed responses, signature failures, and identity mismatches after dispatch. Use `UnknownPaymentOutcomeException` or a documented equivalent for uncertain mutations. Preserve a safe failure category, operation, and requested `payId`. Keep local pre-dispatch validation and signing errors distinct.
- Never retry mutations automatically. Direct callers to signed `payment/status` and operator review where status cannot establish the outcome. Preserve asynchronous refund semantics.
- Add parameterized regressions across all three operations for valid matching responses, a correctly signed mismatched identifier, and uncertain post-dispatch outcomes. Assert that only one mutation is sent per call and that a definitive rejection remains distinguishable.

## 3. Redact signed request paths and preserve safe bank diagnostics

Stop logging interpolated signed GET paths. Log a stable operation name and method for Echo, status, and the other transport paths. Check exception messages and any propagated exception chain for accidental exposure of signed URLs, identifiers embedded in URLs, signatures, canonical strings, key material, tokens, or raw payloads.

Parse the documented unsigned non-200 JSON error envelope on a best-effort basis. Preserve HTTP status and a validated numeric gateway `resultCode` as explicitly unverified diagnostic metadata. Treat any exposed gateway message as untrusted, and keep it out of default logs and public-facing exceptions. Preserve the HTTP-status fallback for malformed or non-JSON error bodies. Keep unsigned data out of the authenticated result path and preserve the classification from section 2 for ambiguous mutation failures.

Add log-capture tests for GET Echo and payment status, plus regressions for different K&H errors sharing one HTTP status, invalid error bodies, and strict signature verification of HTTP 200 responses. Use the K&H **API Integration** error examples as the contract.

## 4. Prepare package metadata and consumer documentation

- Turn the root README into a tested installation and integration guide. Remove merchant-specific onboarding details and stale framework references. Cover Composer installation, Laravel 13/PHP requirements, sandbox and production configuration, server-owned checkout data, persistence, callback verification, and status reconciliation. Explain that `merchantData` must be Base64-encoded and at most 255 characters after encoding. Use safe placeholder data in examples and fixtures.
- Add `ext-openssl` to the runtime requirements and refresh the lock without unrelated upgrades. Add the MIT license file consistent with the declared license and verified copyright attribution. Smoke-test package discovery and the documented configuration publishing command in a clean Laravel 13 consumer.
- Add repository ignore rules for local environment/auth files and merchant key paths. Retain the explicitly synthetic test fixtures. Review the tracked tree and reachable Git history for real credentials and merchant data using a redacted local scan. Report counts and safe references only. If exposure is found, report the public-release blocker and any rotation needed. Keep the repository private and leave history remediation to a separate task.
- Update the acceptance runbook with the K&H activation scenarios for successful hosted payment, buyer cancellation, expiry, and reversal. Keep manual close, partial refund, OneClick, Apple Pay, and Google Pay as separately tracked acceptance scenarios. Preserve the existing verified Echo evidence and mark each unexecuted payment scenario pending. This task uses offline checks only and does not authorize payment, sandbox, or production requests.

## Acceptance criteria

- The four findings are closed with focused regressions or documented publication checks. The parity matrix distinguishes offline behavior, prior sandbox evidence, and pending bank acceptance.
- Run Composer strict publish validation, optimized strict PSR-4 autoload generation, Pest offline, configured Larastan, Pint in check mode, and `git diff --check`. Verify the offline suite on PHP 8.4.27 and 8.5 through the existing CI matrix. Verify a clean production-only Laravel 13 consumer dependency resolution on both PHP lines using the candidate code.
- Report changed files, host-facing contract changes, exact commands/results, remaining blockers, and the release status supported by the evidence. Leave reviewable changes for the operator. Tagging and publication are separate release steps.
