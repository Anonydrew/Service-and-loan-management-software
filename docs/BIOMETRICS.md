# Onboarding biometric verification

## Scope

Biometric verification is used **only to confirm a customer's identity during onboarding**. It is not used for sign-in, loan repayments, disbursements, staff attendance, service transactions or any other authorisation. The API enforces this:
- Attempts can only be started while a customer's onboarding is open (`onboarding_closed` otherwise).
- No other workflow reads biometric results.

**WebAuthn and passkeys are not biometric identity matching.** A platform authenticator proves possession of a device, not that a person matches an identity record, so this system does not use them for onboarding.

## Ghana Card holders: L.I. 2523

The National Identity Register (Amendment) Regulations, 2026 (L.I. 2523) came into force on 9 June 2026. The transition window ends on **2 November 2026**. The rules:
- Any organisation that onboards customers with the Ghana Card must confirm identity by **biometric authentication**: either **match-on-card** against the card's chip, or a match against the **National Identity Register** using an NIA-approved device or the NIA Identity Verification System Platform (IVSP).
- Organisations **may not request, reproduce or retain a photocopy, scan or image** of the Ghana Card, and may not rely on visual inspection.

This is not legal advice. Confirm your obligations with your legal adviser and the NIA (onboarding: idverification@nia.gov.gh).

The system enforces these rules regardless of company settings:
- **No card images.** `ghana_card` is not an uploadable document type, and the API refuses it (`ghana_card_image_prohibited`). Existing images can be removed with `php artisan documents:purge-ghana-card-images --dry-run`, then without `--dry-run`.
- **Biometric check required.** Every customer, and every business signatory, whose ID type is Ghana Card gets a required checklist item: "Ghana Card biometric verification". Only a verified biometric attempt satisfies it. A face check against the NIA register or a fingerprint match-on-card both count.
- **No approval by inspection.** Manual review cannot approve a Ghana Card holder until that check passes (`ghana_card_biometric_required`). A reviewer can still reject.
- **Card must belong to the customer.** In match-on-card mode the bridge must return the card number read from the chip. The server compares it with the customer's recorded Ghana Card number and rejects someone else's card (`card_mismatch`).
- **Method kept.** The method used (`match_on_card` or `nia_register`) is stored in `verification_method`. It is kept after retention purges as evidence of how identity was confirmed.

## Your scanner: DERMALOG ZF1+

According to DERMALOG's datasheet, the ZF1+ combines an optical fingerprint scanner with a chip-card reader:
- 500 dpi, 480 × 320 px.
- FBI PIV and BSI-TR-03121 certified image quality.
- Liveness detection is an optional purchase.
- The card reader is ISO 7816, PC/SC, CCID, T=0/T=1.
- Match-on-card is supported for MCU-based cards.
- Connects over USB 2.0. The SDK runs on Windows, Linux and Android, with NFIQ 1/2 quality scoring and WSQ compression.

That makes it a suitable device for match-on-card onboarding with `FINGERPRINT_BRIDGE_MODE=match_on_card`. Before production, get written confirmation of three things:

1. **From NIA:** the ZF1+ (with your firmware and liveness option) is an **NIA-approved device**. NIA publishes technical specifications for approved devices and card readers.
2. **From NIA:** how a third party performs **match-on-card with the Ghana Card chip**: the applet, secure-messaging keys or certificates, and any NIA SDK. DERMALOG's generic SDK alone cannot authenticate to the Ghana Card's biometric applet.
3. **From DERMALOG (or its Ghana distributor):** the **ZF1+ SDK**, including the match-on-card API and liveness option licences, for the workstation operating system you will use.

Buy the liveness option. Without it, a fake finger may pass a match-on-card check.

## Workflow

1. Staff register the customer (individual or business) and capture **data-processing consent**.
2. Staff upload the identity document (Ghana Card, passport, voter ID, driver's licence; or business registration documents plus each signatory's ID). A different authorised person verifies each document.
3. If biometrics are to be used, staff read the consent text and record **biometric consent** for the person being verified. For a business, that is a named representative: a company has no face or fingerprint.
4. Staff start **face** verification (camera and provider) or **fingerprint** verification (compatible scanner and capture bridge).
5. The outcome, provider reference, time and operator are recorded. The customer's verification status updates.
6. Staff then do one of the following:
   - **complete onboarding**, if every policy requirement is met
   - **try again**, within the daily attempt limit
   - **refer for manual review**, where a compliance reviewer who didn't register the customer inspects the original documents and approves or rejects.

A customer may decline biometrics at any point. For holders of other identity documents, the manual-review path remains available (`allow_manual_review`). A **Ghana Card holder who declines cannot complete onboarding**, because the law requires the biometric check (see above).

### Statuses

Not started · Pending · Verified · Failed · Requires manual review · Cancelled.

### Failure reasons handled

| Code | Raised by | Operator message (summary) |
|---|---|---|
| `camera_permission_denied` | browser | Allow camera access and retry |
| `device_unavailable` | browser / bridge | No working camera or scanner (also HTTPS required) |
| `poor_quality` | provider / bridge | Improve lighting, remove hats/glasses, retry |
| `liveness_failed` | provider | Customer must be physically present |
| `no_match` | provider / bridge | Capture didn't match the identity record |
| `provider_unavailable` | provider | Outage; retry later or refer for review |
| `provider_not_configured` | server | No provider set up; use documents and manual review |
| `interrupted` | browser | Session abandoned (page closed, cancelled, new attempt started) |
| `expired` | server | No result within `BIOMETRICS_SESSION_TTL` minutes, or a stale bridge result |
| `invalid_signature` | server | Result failed authentication; also written to the audit log as a security event |

## What is stored, and what is not

Stored in `biometric_verifications`:
- Modality, provider name, status and failure reason.
- Match score and liveness flag.
- Operator, consent record and timestamps.
- `retain_until`, plus the opaque provider reference, session id and non-biometric diagnostics (device model, quality score). These last three are **encrypted at rest** and **cleared** by `biometrics:purge-expired` once `retain_until` passes (company setting `biometric_retention_days`, default 365).

**Never stored:**
- face images or video
- fingerprint images or templates
- liveness frames

The face preview is rendered locally in the browser and is not uploaded to this server. Biometric data goes only from the capture component to the provider.

Simulated results are flagged `is_simulated`. In production they never satisfy onboarding.

## Configuration

| Variable | Default | Meaning |
|---|---|---|
| `BIOMETRICS_FACE_DRIVER` | `unconfigured` | `unconfigured`, `simulated` (dev only) or your provider adapter |
| `BIOMETRICS_FINGERPRINT_DRIVER` | `unconfigured` | `unconfigured`, `simulated` (dev only) or `bridge` |
| `BIOMETRICS_ALLOW_SIMULATED` | `false` | Allows the simulator **outside production only** |
| `BIOMETRICS_SESSION_TTL` | `10` | Minutes a session may wait for its result |
| `FINGERPRINT_BRIDGE_URL` | `http://127.0.0.1:15896` | Local capture agent on each onboarding workstation |
| `FINGERPRINT_BRIDGE_SECRET` | — | Shared HMAC secret; the bridge driver is "configured" only when set |
| `FINGERPRINT_BRIDGE_MODE` | `match_on_card` | `match_on_card` (Ghana Card in the reader, e.g. DERMALOG ZF1+) or `nia_register` |
| `FINGERPRINT_BRIDGE_MAX_AGE` | `300` | Seconds a signed result stays valid |
| `FINGERPRINT_MIN_MATCH_SCORE` | `0.80` | Matches scoring below this go to manual review |

Company policy (Settings → Onboarding) decides whether face and/or fingerprint is **required** for individuals and for a business's primary representative, the daily attempt limit, retention and manual-review availability. Set these according to your lawful onboarding (KYC) policy.

With the defaults, **no biometric verification can succeed**. That is intentional: the system never reports a match it didn't obtain from a real, configured integration.

## Integrating a facial verification provider

Choose a specialist provider offering **1:1 face match against the identity document or national ID record, plus active or passive liveness detection**. In Ghana that usually means a provider with licensed access to Ghana Card (NIA) verification. Before selecting one, confirm with your legal and compliance advisers: data-processing terms, hosting location, retention and deletion, and registration obligations under Ghana's Data Protection Act, 2012 (Act 843).

Implementation steps:

1. Implement `App\Services\Biometrics\BiometricProvider` (e.g. `app/Services/Biometrics/Providers/AcmeFaceProvider.php`):
   - `start()`: create a provider session/job for the attempt, passing the `VerificationSubject` (name, date of birth, ID type and number). Return what the web SDK needs, e.g. `['mode' => 'acme', 'session_id' => ..., 'token' => ...]`. A returned `session_id` is stored encrypted on the attempt.
   - `complete()`: given the client's completion payload (usually a job id), fetch the authoritative result **server-to-server**. Never trust a score sent by the browser. Map it to `VerificationResult::verified()`, `::failed($reason)` or `::manualReview()`.
   - `parseWebhook()`: if the provider calls back asynchronously, verify its signature, then return `['session_id' => ..., 'result' => VerificationResult]`. The endpoint is `POST /api/webhooks/biometrics/{provider-name}`.
   - `purge()`: call the provider's deletion API when retention expires.
2. Register the driver in `ProviderRegistry::for()` and add its credentials to `config/biometrics.php` (read from `.env`; never commit keys).
3. In the SPA, mount the provider's web capture SDK in `FaceCapture` (`frontend/src/pages/customers/BiometricPanel.tsx`) for your `client.mode`. Call `complete` with the job id when the SDK finishes, or `client-failure` with the matching reason on camera or SDK errors.
4. Add feature tests modelled on `tests/Feature/BiometricVerificationTest.php`, using HTTP fakes for the provider API.

## Fingerprint hardware: capture bridge contract

Browsers cannot access fingerprint scanners directly. Fingerprint verification therefore needs three pieces:

1. A **compatible scanner** with a vendor SDK (FBI PIV/FAP-certified optical scanners, for example, are common).
2. A **matching service** that can compare the capture against the reference: a national ID verification service or your provider's 1:1 matcher.
3. A small **capture bridge**: a service installed on each onboarding workstation that wraps the SDK and the matcher, listening on loopback only. It is hardware-specific and not part of this repository.

The SPA talks to the bridge at `FINGERPRINT_BRIDGE_URL` as follows.

`GET /status` → `{ "device_connected": true, "device_model": "..." }`

`POST /capture` with

```json
{ "attempt_id": 42, "nonce": "<40 chars from the server>", "method": "match_on_card", "require_card": true,
  "subject": { "id_type": "ghana_card" }, "expires_at": "2026-10-11T12:10:00+00:00" }
```

In `nia_register` mode, `subject` also carries `id_number` and `country`, so the bridge can query the register. In `match_on_card` mode the server does **not** send the number. The bridge reads it from the chip instead.

The bridge does the following:
1. In `match_on_card` mode, reads the Ghana Card chip.
2. Captures the finger, with the liveness check if licensed.
3. Runs the match on the card or against the NIA register.
4. Discards the image and template.
5. Returns:

```json
{
  "result": {
    "attempt_id": 42,
    "nonce": "<same nonce>",
    "method": "match_on_card | nia_register",
    "outcome": "match | no_match | poor_quality | card_unreadable | device_error",
    "card_pin": "GHA-XXXXXXXXX-X",
    "match_score": 0.93,
    "liveness_passed": true,
    "provider_reference": "card/IVSP transaction id",
    "issued_at": "2026-10-11T12:01:02+00:00",
    "device_model": "DERMALOG ZF1+", "quality_score": 81, "finger": "right_index"
  },
  "signature": "<hex HMAC-SHA256 of the canonical result>"
}
```

Field rules:
- `card_pin` (the Ghana Card number read from the chip) is **required** for a `match` in `match_on_card` mode.
- `liveness_passed: false` is always treated as a failure.

The canonical form is the `result` object with keys sorted alphabetically, JSON-encoded without escaped slashes or unicode (`FingerprintBridgeProvider::sign()` shows the exact algorithm). The SPA forwards `{result, signature}` to `POST /api/customers/{id}/biometrics/{attempt}/complete`. The server rejects the result if any of these fail:
- the signature check
- the attempt id, the single-use nonce or the requested `method` don't match
- the result is older than `FINGERPRINT_BRIDGE_MAX_AGE`
- the outcome is unknown
- in match-on-card mode, `card_pin` isn't the customer's recorded Ghana Card number

A `match` scoring below `FINGERPRINT_MIN_MATCH_SCORE` goes to manual review.

Operational notes:
- The bridge must enable CORS for the SPA origin only.
- Store the shared secret in the OS keychain or a service account.
- Rotate the secret if a workstation is lost.
- For stronger assurance, have the matcher deliver results through the provider webhook instead of the browser.

## Testing without hardware or a provider

- Automated tests: `php artisan test --filter=Biometric` covers the unconfigured refusal, consent enforcement, each outcome, browser failures, attempt limits, business representatives, production refusal of the simulator, bridge signature/nonce/staleness checks and retention purge.
- Manual testing: set `BIOMETRICS_FACE_DRIVER=simulated` and `BIOMETRICS_ALLOW_SIMULATED=true` in a non-production `.env`. The UI shows a violet **Development simulator** panel and labels every result **SIMULATED — not a real verification**.
