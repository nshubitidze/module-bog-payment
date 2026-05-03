# BUG-BOG-3 Finish — Config-Driven RSA Key, Const Removal

Date: 2026-05-03
Module: `Shubo_BogPayment`
Scope: `Gateway/Validator/CallbackValidator.php`, `Gateway/Config/Config.php` (read-only), `etc/adminhtml/system.xml` (read-only), `etc/di.xml` (read-only)
Status: Architect-signed; cleared for developer pass

## Decision tree walk

CLAUDE.md "Simplicity-first" prescribes a four-step ordering before introducing any new abstraction. Walking it for the BUG-BOG-3 cleanup:

**DELETE.** Cannot delete. BOG sends a `Callback-Signature` header on payment callbacks and our security posture requires SHA256withRSA verification when that header is present. Removing the verification path would force every callback through the status-API fallback unconditionally — extra HTTPS round-trip per callback and loss of the cryptographic primary signal. Step does not apply.

**REUSE.** Stops here. `Shubo\BogPayment\Gateway\Config\Config::getRsaPublicKey()` already exists (Config.php lines 100-107), already reads `payment/shubo_bog/rsa_public_key`, already decrypts via the injected `EncryptorInterface`, and is already wired into `CallbackValidator` through `etc/di.xml` lines 172-177. The validator's constructor takes `?Config $config` today purely as a transitional courtesy; the wiring is in place. The only remaining work is to make the dependency required, drop the const fallback, and adjust log levels. No new code path is created — we are removing a dead branch and tightening a nullable into a required dependency.

**INLINE.** Does not apply. Inlining would mean injecting `ScopeConfigInterface` + `EncryptorInterface` directly into `CallbackValidator` and reproducing the two-line decrypt-or-empty shape that `Config::getRsaPublicKey()` already encapsulates. That duplicates a getter that has more than one caller-class candidate (the validator today, any future signature-rotation tooling tomorrow) and breaks the established pattern of "all `payment/shubo_bog/*` reads live on `Gateway\Config\Config`." Inline is the right call when the relationship is 1:1 and stable inside one module — here it is 1:N (multiple BogPayment classes call `Config`) and unstable (key rotation tooling will want the same getter).

**NEW abstraction.** Explicitly out of scope per the session prompt and per the decision tree. No `RsaPublicKeyResolver`, no helper class. Reuse already covers the need.

## Why use Config not ScopeConfig+Encryptor directly

`Shubo\BogPayment\Gateway\Config\Config` extends `Magento\Payment\Gateway\Config\Config`. That parent class exists in Magento core specifically to be the per-payment-method config aggregator — it is constructed with a `methodCode` ("shubo_bog") and a `pathPattern`, and its single job is to translate `payment/<method>/<key>` reads into typed getters. Bypassing it for the RSA key while routing every other config field (`client_id`, `client_secret`, `api_url`, `oauth_url`, `environment`, `payment_lifetime`, `payment_theme`, `payment_methods`, `payment_action_mode`, `split_*`, `debug`) through it would be a deliberate inconsistency with no payback.

The precedent is right next door. `Config::getClientSecret()` (lines 88-92) reads the encrypted client_secret and returns the decrypted value — exactly the shape `getRsaPublicKey()` follows. If the client_secret reader belongs on `Config`, so does the RSA public key reader. Having the validator depend on the lower-level `(ScopeConfigInterface, EncryptorInterface)` pair would also widen its constructor surface for no reason: the validator does not read any other scope config, so injecting `ScopeConfigInterface` to consume one key is overprovisioning.

A second-order benefit: `Config` is the natural seam for future scope-aware behaviour. When merchant-level overrides land (per-website BOG credentials), every consumer that already goes through `Config` inherits the scope handling for free. A validator that bypassed `Config` would have to be retrofitted.

## Field name + type — why no change

The session prompt suggests renaming to `callback_rsa_public_key` and re-typing to `obscure`. Both changes are rejected.

The field id `rsa_public_key` already lives inside the `payment/shubo_bog` group (`etc/adminhtml/system.xml` lines 46-51). Group context already supplies the qualifier — the full config path is `payment/shubo_bog/rsa_public_key`, which an operator reads as "the RSA public key for the BOG payment method." Renaming would force a `core_config_data` migration for any environment where the key has been stored, and the field has been live in this module since the BUG-BOG-3 cutover work. Cosmetic gain, real migration cost — not worth it. CLAUDE.md "Verify, Don't Assume" applies: the renamer would also need to update every staging and production env that already populated the field, which we have no inventory of.

The type stays `textarea`. PEM-encoded RSA public keys are multi-line by definition — a `BEGIN PUBLIC KEY` header, base64 wrapped at 64 characters, an `END PUBLIC KEY` footer. Magento's `obscure` admin frontend renders a single-line masked input (used for short secrets like API passwords or client_secrets); it does not preserve newlines and is wrong for a PEM. The TBC `password` field cited as a reference is a single-line client password — same encryption need, different shape, different correct widget. The encryption-at-rest property is provided by the `<backend_model>Magento\Config\Model\Config\Backend\Encrypted</backend_model>` declaration on the field, which is already present and is independent of the frontend type. We get the storage protection without distorting the input UX.

## Fail-closed semantics — preserved

Removing the const does not change the system's failure behaviour, only its honesty about it.

When the admin has not configured a key, `Config::getRsaPublicKey()` returns `''`. `CallbackValidator::verifySignature()` then has nothing to feed `openssl_pkey_get_public()` and must return false. Returning false from `verifySignature()` causes `validate()` to fall through the warning log and into `validateViaStatusApi()` — exactly the path it took before, with the const fallback also returning false because the const was deliberately malformed. End-to-end behaviour: status-API fallback handles validation. No regression.

When the admin has configured a malformed PEM (typo, copy-paste mangled, wrong header), `openssl_pkey_get_public()` returns false, the validator logs a warning, returns false, and falls through to the status API. Same end-to-end behaviour, with a louder warning so the operator notices.

When the admin has configured a valid PEM, `openssl_verify()` returns 1 (signature matches) or 0 (signature does not match). Match → callback accepted. Mismatch → fall through to the status API.

The only behaviour the const removal eliminates is the misleading "we have a key but it's intentionally broken" branch. Nothing downstream of `CallbackValidator` distinguished that case from the unconfigured case before, so nothing downstream notices it is gone.

## What changes / what doesn't

Changes:

- The `BOG_PUBLIC_KEY` const in `CallbackValidator.php` is removed entirely.
- The constructor parameter `?Config $config = null` becomes the required `Config $config` (no default, no nullable).
- `verifySignature()` reads `$this->config->getRsaPublicKey()` directly, with no const fallback.
- An empty PEM is logged at `info` level, not `warning` — empty is the expected pre-cutover state on staging and demo envs and should not pollute warning channels.
- A malformed PEM is logged at `warning` level — this is an operator misconfiguration that needs attention.

Unchanged:

- Field id (`rsa_public_key`), field type (`textarea`), backend model (`Encrypted`), sort order (52), group placement.
- DI wiring in `etc/di.xml` (lines 172-177) — `Config` is already passed as the `config` argument.
- The status-API fallback path in `validateViaStatusApi()` — semantics, return shape, error handling.
- `Config::getRsaPublicKey()` signature, return type, and decryption behaviour.
- The public shape of `validate()` and the `array{valid, status, data}` contract consumed by `Controller\Payment\Callback`.

## Tests being added

Four unit tests under `Test/Unit/Gateway/Validator/CallbackValidatorTest` cover the verification matrix end-to-end:

1. **Empty config, signature header present.** `Config::getRsaPublicKey()` returns `''`. Expectation: `verifySignature()` returns false silently (info-level log, not warning); `validate()` falls through to a stubbed `StatusClient` whose response decides the outcome. Asserts no warning was logged for the empty-config branch.

2. **Malformed PEM in config.** `Config::getRsaPublicKey()` returns a deliberately broken PEM string. Expectation: `openssl_pkey_get_public()` fails, validator logs a warning, returns false, status-API fallback drives the outcome. Asserts the warning was logged.

3. **Valid PEM, signature matches.** Test generates an ephemeral RSA keypair with `openssl_pkey_new()` in `setUp()`, signs a fixture callback body with the private half, configures the public half on the mocked `Config`. Expectation: `verifySignature()` returns true, `validate()` returns the parsed callback payload without touching the status API. Mocked `StatusClient` asserts zero invocations.

4. **Valid PEM, signature does not match.** Same ephemeral keypair, but the test signs a different body than it submits (or tampers a byte). Expectation: `openssl_verify()` returns 0, validator returns false, status-API fallback drives the outcome.

All four tests use `openssl_pkey_new()` for the keypair so no real BOG keys ship in the repo and no fixture rotation is required if BOG ever rotates their production key. The keypair lives only inside the test process.
