# Signed JSON transport and Echo

Prompt 03 introduced the package's single RSA/SHA-256 signing boundary and verified JSON transport.

## Security decisions

- `Canonicalizer` uses operation-owned field schemas. It omits missing, `null` and empty-string optionals, retains `false` and numeric zero, converts backed enums, traverses nested objects and ordered lists, and never signs the `signature` member.
- `SignatureService` rejects missing, unreadable or invalid keys, checks the result of `openssl_sign()`, uses strict Base64 decoding and accepts verification only when `openssl_verify()` returns `1`.
- Response verification is mandatory and cannot be disabled by configuration.
- `SignedJsonTransport` normalizes a configured host-only URL to `/api/v1.0/` and otherwise preserves the configured API path. The active environment selects the URL and both key paths together.
- POST sends a top-level JSON object. Signed GET values are canonicalized before each decoded value is percent-encoded exactly once as its own path segment. Redirect following is disabled and no automatic retries are configured.
- HTTP status `200` must carry an object-shaped JSON response with the operation's required fields and a valid signature. Non-200 unsigned errors, connection failures, malformed JSON/shape and invalid signatures are distinct exceptions. A valid signed HTTP 200 response with nonzero `resultCode` remains an authenticated business result.
- Diagnostics contain only method, endpoint and failure category. Canonical strings, payloads, signatures, key material and signed URLs are not logged by the shared path.

## Caller-facing behavior

`Vpos::echo()` performs verified GET Echo for Laravel checkout. `SignedJsonTransport` also exposes the verified POST/GET primitives used by operation services and deterministic tests. The abandoned duplicate API clients, crypto wrappers and DTO trees were removed before release.
