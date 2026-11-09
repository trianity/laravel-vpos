# K&H eAPI v1.0 refactor and parity prompts

Run one prompt per Codex session on the same implementation branch, in order. Give the agent this README, the linked prompt, and the prior step's report. Start with Prompt 01. Stop at the first unmet acceptance criterion or unresolved protocol ambiguity and record the evidence. The current files are work orders for the implementation agent.

| Prompt | Deliverable |
| --- | --- |
| [01 — Baseline and parity matrix](01-baseline-and-parity-matrix.md) | Reproducible offline baseline, source-backed contract inventory and regression fixtures |
| [02 — Remove Spatie Laravel Data](02-remove-spatie-laravel-data.md) | Spatie removal; the abandoned duplicate DTO layer was subsequently removed before release |
| [03 — Signed JSON transport and echo](03-signed-json-transport-and-echo.md) | Shared wire handling, canonical signing and verification; see the [transport notes](signed-json-transport.md) |
| [04 — Hosted card lifecycle](04-hosted-card-lifecycle.md) | Init, browser handoff, status, close, reverse, refund and return verification; see the [lifecycle notes](hosted-card-lifecycle.md) |
| [05 — OneClick, Apple Pay, Google Pay and extensions](05-oneclick-wallets-and-extensions.md) | Signed checkout flows and typed authentication actions; see the [implementation notes](oneclick-wallets-and-extensions.md) |
| [06 — Laravel entry points](06-laravel-entry-points.md) | Provider, facade and route integration through the verified client; see the [setup and migration notes](laravel-entry-points.md) |
| [07 — Acceptance and sandbox runbook](07-acceptance-and-sandbox.md) | Offline and sandbox evidence; see the completed [acceptance evidence and sandbox runbook](acceptance-and-sandbox.md) |
| [08 — Release blockers and RC readiness](08-release-readiness.md) | Checkout persistence, mutation reconciliation, safe diagnostics, and publication checks; see the [release-readiness report](release-readiness.md) |

## Source snapshots

- Package reviewed at [a37f01d](https://github.com/trianity/vpos/tree/a37f01d0e31207e6bb4b0f57937add1a11232c68).
- [Supplied PHP examples and fixtures](https://github.com/trianity/Payment-gateway_HU/tree/253a87c0b45216487285c7f12097aeb31e86047f/examples/eApi%20v1.0/php), commit `253a87c0b45216487285c7f12097aeb31e86047f`. Read `src/apiservice.php`, `src/cryptoservice.php`, `src/mipsconnector.php`, `src/examples.php` and `config/*.json`. Java and .NET examples in the same snapshot provide cross-checks.
- [Official K&H wiki](https://github.com/khpos/Payment-gateway_HU/wiki), separate Git repository `https://github.com/khpos/Payment-gateway_HU.wiki.git`, reviewed at `f35dab5e6b55f97ffa033e67d85e6db88a355b8f`. Read Basic Methods, API Integration, Request Signing and Response Signature Validation, the payment lifecycle, purchase metadata, each payment family page, and the extension pages. Use the upstream wiki if the supplied fork has no accessible wiki.
- Use the eAPI v1.0 specification to resolve sample inconsistencies. Cite a page, section or sample file for every material contract decision. Record contradictions and isolate any case that needs merchant-specific documentation or sandbox confirmation. Keep optional capabilities outside the PHP examples, such as payment links, separate in the matrix.

## Initial static review

Method names cover the 17 operations implemented by the PHP sample. Functional parity is incomplete. This review did not execute the package or contact a payment gateway.

| Operations | Evidence in the reviewed package |
| --- | --- |
| GET and POST `echo` | `SignedJsonTransport` uses explicit Echo schemas and mandatory gateway-signature verification. Laravel checkout uses verified GET Echo. |
| `payment/init` including OneClick template creation | `HostedCardService` validates amounts/cart totals and verifies signed responses. Laravel checkout supplies only host-owned data. |
| `payment/process` | The Laravel route returns a real redirect to the signed handoff URL; URL construction performs no server-side browser request. |
| `payment/status`, `payment/close`, `payment/reverse`, `payment/refund` | The shared service uses operation schemas and mandatory response verification. Optional close/refund amounts and unknown mutating outcomes are covered. |
| OneClick, Apple Pay and Google Pay `echo/init/process` | `WalletService` owns all nine signed operations. Explicit browser/SDK actions are hydrated after signature verification; known extensions are independently verified. |
| `echo/customer` | Listed in the sample README, absent from the executable PHP modes and recorded as removed in the upstream wiki changelog of 2022-10-13. Keep the evidence as an exclusion. |
| Laravel integration | Provider, route and facade share the lazy verified `Vpos` singleton. The host supplies server-owned checkout data through `CheckoutDataProvider`; return URL is trusted configuration, response fields/signatures are enforced, and offline Laravel 13 feature coverage replaces the skipped controller test. |

## Rules for every prompt

- Read repository instructions and inspect the current branch before editing. This package has no released consumer surface: do not retain abandoned adapters or compatibility shims.
- Keep changes scoped, use one PSR-4 class per file, and share the signing and transport implementation across entry points. Keep application-specific persistence, checkout UI, wallet enrollment, and mobile SDK execution with the host application. The package owns the eAPI wire contract and exposes the data needed by those hosts.
- Use synthetic fixture data and generated merchant/gateway key pairs for default tests. Prevent stray HTTP requests. Avoid real keys, payment data, complete signed URLs, tokens, and raw payloads in logs, fixtures, or reports. Never call production from these tasks.
- At the end of each prompt, report changed paths, test commands and results, covered parity rows, contract decisions, and unresolved items. If a command cannot run, report the exact blocker. Leave reviewable changes for the operator and stop before the next prompt.
