# OneClick, wallet and extensions

Prompt 05 moves the nine OneClick, Apple Pay and Google Pay POST operations onto the shared signed JSON transport. The implementation is verified with synthetic offline fixtures only; it does not claim merchant provisioning, real wallet UI, live tokenization, or mobile 3DS SDK execution.

## Wire contract

- `WalletService` owns `{oneclick,applepay,googlepay}/{echo,init,process}` validation, canonicalization, signing, response verification and identity correlation. All operations send a top-level JSON object with `POST`.
- OneClick echo correlates the returned `origPayId` with the requested template. OneClick init keeps that template ID distinct from the newly returned `payId`.
- Customer-initiated OneClick requires `clientIp` at init and a browser or SDK fingerprint at process. Merchant-initiated processing may explicitly omit the fingerprint.
- Apple Pay and Google Pay init require positive integer minor units, a supported currency, `clientIp`, the provider payload and a valid return target. Optional purchase metadata, `sdkUsed`, `merchantData`, `language` and TTL retain their documented canonical positions.
- The provider Echo responses hydrate their different nested `initParams` structures. A signed unavailable response may omit `initParams`.
- Every process response is correlated with the requested `payId`. A correctly signed response with a mismatched identifier is rejected.

The canonical schemas live in `WalletSchema`. Literal canonical-string regressions cover all nine operations, independently of signature verification.

## 3DS host boundary

Validated PSR-4 types represent:

- `actions.fingerprint.browserInit` as an `Endpoint` with URL, method and scalar `vars`;
- `actions.fingerprint.sdkInit` as `SdkInitialization`;
- `actions.authenticate.browserChallenge` as an `Endpoint`;
- `actions.authenticate.sdkChallenge` as `SdkChallenge`;
- request `fingerprint.browser` and `fingerprint.sdk` as separate types.

Exactly one browser or SDK branch is allowed at each conditional boundary. Signed but ambiguous or malformed actions are rejected. Frictionless responses may omit actions. The package validates and exposes action data; the host application remains responsible for browser form submission, wallet UI and mobile SDK calls.

## Extensions

The parent response signature deliberately excludes `extensions`. Every known extension is canonicalized and verified separately with the gateway public key before it is exposed as a `VerifiedExtension`:

| Identifier | Placement and canonical fields | State |
| --- | --- | --- |
| `maskClnRP` | OneClick echo/status extension; `extension,dttm,maskedCln,expiration,longMaskedCln` | Verified and hydrated |
| `maskCln` | Basic payment response extension; same field order as `maskClnRP` | Verified and hydrated |
| `trxDates` | Payment response extension; `extension,dttm,createdDate,authDate,settlementDate` | Verified and hydrated; last two dates may be absent |

An invalid signature, malformed envelope, unsigned extra field or unknown extension rejects extension hydration; unknown data is never labelled verified. No extension payload or masked card value is logged by these paths.

## Offline evidence and deferred verification

`tests/Unit/WalletAndExtensionsTest.php` uses distinct synthetic OneClick, Apple Pay and Google Pay payloads and prevents live credentials from entering fixtures. It covers active/unavailable echoes, customer/merchant OneClick, browser/SDK fingerprints, frictionless and challenge outcomes, status hydration, malformed actions, signature tampering and unknown extensions.

Merchant activation, Apple Pay and Google Pay production UI, real wallet tokens, ACS/browser execution, mobile SDK execution and sandbox results remain unverified. They belong to the opt-in Prompt 07 runbook and must not be inferred from the offline suite.
