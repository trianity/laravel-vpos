# Prompt 08 release-readiness report

Date: 2026-11-09

Candidate base: `6e71ca0` plus the reviewable Prompt 08 worktree changes

Release status: **Offline verification complete. Bank payment acceptance pending.**

No tag, push, package publication, sandbox payment or production request was performed.

## Closed code findings

1. **Payment-reference persistence:** `CheckoutDataProvider` now requires synchronous `persistPaymentReference(orderReference, payId)` handling. The Laravel controller calls it with the exact trusted order reference and verified successful Init `payId` before constructing the redirect. Persistence failures propagate to the host, produce no handoff and trigger no automatic Init retry.
2. **Mutation correlation and uncertain outcomes:** close, reverse and refund correlate the verified response `payId`. A mismatched identity, connection loss, HTTP 5xx, malformed body or invalid signature becomes `UnknownPaymentOutcomeException` with a safe category, requested operation and requested `payId`. A documented unsigned HTTP 4xx envelope with a numeric result code remains distinguishable as a definitive rejection. Mutations are sent once and are never retried automatically.
3. **Safe diagnostics:** signed GET paths are no longer logged. Transport logs contain only the HTTP method and stable operation name. Unsigned non-200 envelopes expose only the HTTP status and a validated numeric `unverifiedResultCode`; untrusted bank messages and unsafe underlying transport/JSON exception text are not propagated.
4. **Package readiness:** the runtime requires `ext-openssl`; the lock was refreshed without package upgrades; an MIT license and a Laravel 13 installation guide were added. The obsolete package transaction migration and stale provider publishing behavior were removed because persistence belongs to the host.

## Host-facing contract change

Every host implementation of `CheckoutDataProvider` must implement:

```php
public function persistPaymentReference(string $orderReference, string $payId): void;
```

The method must durably and atomically associate the verified `payId` with the authorized server-owned order before returning. It should lock or otherwise serialize concurrent submissions, accept an idempotent repeat of the same association, and reject a different existing attempt for reconciliation. The package intentionally contains no legacy adapter or fallback implementation.

The complete integration and recovery flow is documented in the root [README](../README.md) and [Laravel entry-point guide](laravel-entry-points.md).

## Offline and consumer evidence

| Check | Result |
| --- | --- |
| PHP 8.4 Pest, sandbox excluded | 114 passed, 342 assertions |
| PHP 8.5 Pest, sandbox excluded | 114 passed, 342 assertions |
| Larastan on PHP 8.4 | 0 errors |
| Larastan on PHP 8.5 | 0 errors |
| Composer strict validation | Valid |
| Optimized strict PSR-4 autoload | Generated, 8,652 classes, no violations |
| Pint check mode | Passed |
| `git diff --check` | Passed |
| Production-only resolution, PHP 8.4 | Candidate plus Laravel 13.40.0 resolved with `--no-dev` |
| Production-only resolution, PHP 8.5 | Candidate plus Laravel 13.40.0 resolved with `--no-dev` |
| Clean Laravel consumer | Laravel 13.40.0 discovered `trianity/vpos`; documented `vpos-config` publish command produced config, translations and views |

Commands used:

```text
php8.4 vendor/bin/pest --exclude-group=sandbox
php8.5 vendor/bin/pest --exclude-group=sandbox
php8.4 vendor/bin/phpstan analyse -c phpstan.neon.dist --no-progress
php8.5 vendor/bin/phpstan analyse -c phpstan.neon.dist --no-progress
php8.5 /usr/local/bin/composer validate --strict --no-check-publish
php8.5 /usr/local/bin/composer dump-autoload --optimize --strict-psr
php8.5 vendor/bin/pint --test
git diff --check
php8.4 /usr/local/bin/composer update --no-dev --prefer-dist --no-interaction --no-progress
php8.5 /usr/local/bin/composer update --no-dev --prefer-dist --no-interaction --no-progress
php8.5 artisan package:discover --ansi
php8.5 artisan vendor:publish --provider="Trianity\Vpos\Providers\PackageServiceProvider" --tag=vpos-config --force
```

The two production-only resolutions were performed in fresh temporary Composer projects that required both `laravel/framework:^13.0` and the candidate package through a local path repository. The package-discovery check used a separately created clean Laravel 13 application.

## Publication snapshot

The public distribution starts from a standalone Git root. A scan of this snapshot found only the intentionally synthetic test key fixtures.

## Pending bank acceptance

The K&H activation scenarios remain pending: successful hosted payment, buyer cancellation, expiry, reversal, manual close, partial refund, OneClick, Apple Pay and Google Pay. The already verified signed GET/POST Echo evidence remains recorded in the [acceptance runbook](acceptance-and-sandbox.md). These payment scenarios require separate authorization and do not alter the offline code-readiness result.
