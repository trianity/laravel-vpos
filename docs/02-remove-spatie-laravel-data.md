# Prompt 02 — Replace Spatie DTOs with package-owned PHP objects

Work in `trianity/vpos` after Prompt 01. Read [docs/README.md](README.md) and the baseline inventory. Limit this task to data contracts and dependency removal. Keep the protocol corrections assigned to later prompts.

1. Replace all `Spatie\LaravelData\Data` inheritance and `Optional` usage with native typed DTOs and explicit hydration/serialization. Include unused-looking public DTOs such as `EuVatData`. Keep existing FQCNs and characterize used constructor signatures, named arguments, `from()`, `all()`, `toArray()` and JSON behavior before changing them. Retain compatibility where evidence supports it and document the boundary of the former Spatie API.
2. Handle missing values, null, empty strings, false, zero, backed enums and ordered lists deliberately. Test nested `cart`, `customer/account/login`, `order/billing/shipping`, and response extensions. Keep raw DTO export and protocol serialization separate where their requirements differ. Keep constructor, array factory and direct mutation behavior consistent for supported callers.
3. Remove the package from `composer.json`, update `composer.lock` narrowly, and remove the Spatie provider from Testbench. Inspect whether any transitive dependency still requires it. Treat the JSON Mapper packages as a separate decision and leave them in place for this task.
4. Verify production and dev installs using the supported dependency sets, check autoloading, and run focused DTO and existing offline compatibility tests. Record known gateway defects remaining for Prompts 03–06.

Acceptance criteria: no runtime, test or bootstrapping code depends on Spatie Laravel Data, the clean package works without it, and representative existing callers still hydrate and export DTOs as documented.
