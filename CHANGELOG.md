# Changelog

## [1.0.0-rc.1](https://github.com/trianity/laravel-vpos/releases/tag/v1.0.0-rc.1) (2026-11-09)

First public release candidate of `trianity/laravel-vpos`. Requires Laravel 13, PHP 8.4.27 or later in the PHP 8 series, and the OpenSSL PHP extension.

### Added

- K&H eAPI v1.0 support for hosted card payments, OneClick, Apple Pay, and Google Pay, including browser and mobile SDK 3DS action data.
- A signed transport that verifies gateway responses and supported response extensions. The Laravel provider, facade, and checkout route use the same client.
- Server-owned checkout data and synchronous persistence of the verified payment ID before browser handoff. The host owns callback reconciliation and fulfillment.
- Payment ID correlation for close, reverse, and refund. Ambiguous mutation outcomes expose the requested operation and payment ID for reconciliation without automatic retries.
- Offline CI on PHP 8.4.27 and 8.5, an opt-in sandbox Echo harness, and a merchant acceptance runbook.

### Changed before the first public release

- Published package name is `trianity/laravel-vpos`. Package-owned data objects replace the Laravel Data dependency, and the host application owns transaction storage.
- Transport diagnostics use operation names and safe error codes. Signed request URLs, credentials, and raw gateway payloads are excluded from default diagnostics.

See the [release notes](docs/releases/1.0.0-rc.1.md) for the current verification scope.
