# Hosted card lifecycle

Prompt 04 moves the hosted-card operations onto `SignedJsonTransport` through `HostedCardService`, the only hosted-card operation service shipped by the package.

## Host-facing flow

1. Call `HostedCardService::init()` with an array or DTO. Amounts are integer minor units. The package validates the fields it can know: order-number shape, currency, HUF whole-forint amounts, one or two cart lines, cart reconciliation, Boolean `closePayment`, language and TTL.
2. Give the payer the result of `processUrl()`. This only builds the signed, exactly-once encoded browser URL and performs no HTTP request. `processLocation()` is a low-level verifier for the documented 303 behavior; normal Laravel checkout redirects the browser to `processUrl()`.
3. Accept both query-string GET and form-urlencoded POST return parameters and pass their decoded field array to `BrowserReturnVerifier::verify()`. The caller supplies the expected `payId` and, when used as order context, expected `merchantData`.
4. Treat the verified browser return as a signed notification, not final fulfillment authority. Resolve `payId` to the host-owned order, call `status()`, and make the host's order transition idempotent. If the browser never returns, reconciliation starts directly with `status()`.
5. `close()`, `reverse()` and `refund()` validate positive optional integer amounts. Full close/refund omits the amount; lower close uses `totalAmount`; partial refund uses `amount`. The gateway owns balances and legal state transitions.

## Failure and retry policy

HTTP errors, malformed or unsigned responses and signature failures remain distinct exceptions for read operations. A signed HTTP 200 response with a matching `payId` and nonzero `resultCode` is returned as an authenticated business result.

For close, reverse and refund, a validated unsigned HTTP 4xx envelope with a numeric `resultCode` remains a distinguishable `GatewayHttpException`; its message is untrusted and is not exposed. Connection loss, HTTP 5xx, malformed content, signature failure and a signed response carrying another `payId` become `UnknownPaymentOutcomeException`. It retains the requested operation, `payId` and a safe category (`connection`, `http_failure`, `malformed_response`, `signature_failure` or `identity_mismatch`) without embedding the identifier in its message.

Never retry a mutation automatically. Query signed `payment/status`, reconcile local records and require operator review when status cannot establish the outcome. Refund processing is asynchronous even after an accepted response, so poll status rather than treating the immediate response as completion.

## Deferred capabilities

- `payOperation=oneclickPayment` uses the same signed init contract and is covered as template creation. Its subsequent OneClick authentication flow belongs to Prompt 05.
- `payOperation=customPayment`, `customExpiry` and init response `customerCode` are represented by the shared schema, but payment-link distribution and merchant activation have no dedicated package workflow and remain sandbox-dependent.
- `payMethod=card#LVP` is accepted by the documented field validator, but bank-side activation remains merchant-specific.
- Concrete `actions.fingerprint`, `actions.authenticate` and separately signed extensions are verified and hydrated by the Prompt 05 implementation.

No automatic HTTP retries are configured. Logs contain only the HTTP method and stable operation name; signed URLs, identifiers from their paths, signatures, canonical strings, raw payloads and key material are not logged.
