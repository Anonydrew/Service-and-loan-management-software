<?php

/*
|--------------------------------------------------------------------------
| Onboarding biometric verification
|--------------------------------------------------------------------------
|
| Biometrics are used ONLY to verify a customer's identity during onboarding.
| They are not used for login, repayments, disbursements or transactions.
|
| No driver is configured by default. With "unconfigured", every attempt to
| start a verification is refused with a clear message, and staff use the
| document check + manual review path instead. Nothing ever reports a
| successful match unless a real provider (or, outside production only, the
| clearly labelled simulator) returns one.
|
| See docs/BIOMETRICS.md for provider and hardware integration requirements.
*/

return [

    'face' => [
        // unconfigured | simulated | (your provider adapter, see docs)
        'driver' => env('BIOMETRICS_FACE_DRIVER', 'unconfigured'),
    ],

    'fingerprint' => [
        // unconfigured | simulated | bridge
        'driver' => env('BIOMETRICS_FINGERPRINT_DRIVER', 'unconfigured'),

        'bridge' => [
            // The local capture agent installed on the onboarding workstation,
            // wrapping the scanner vendor's SDK. The browser talks to it on
            // loopback; it returns an HMAC-signed result, never a template.
            'url' => env('FINGERPRINT_BRIDGE_URL', 'http://127.0.0.1:15896'),
            // match_on_card (Ghana Card in the reader, e.g. DERMALOG ZF1+) or
            // nia_register (live finger against the NIA register via IVSP).
            'mode' => env('FINGERPRINT_BRIDGE_MODE', 'match_on_card'),
            'secret' => env('FINGERPRINT_BRIDGE_SECRET'),
            'max_result_age_seconds' => (int) env('FINGERPRINT_BRIDGE_MAX_AGE', 300),
            'min_match_score' => (float) env('FINGERPRINT_MIN_MATCH_SCORE', 0.80),
        ],
    ],

    // The simulator exists for development and automated tests. It is refused
    // in production regardless of this flag, and every result it produces is
    // stored with is_simulated = true and shown as SIMULATED in the UI.
    'allow_simulated' => (bool) env('BIOMETRICS_ALLOW_SIMULATED', false),

    // How long a started session may wait for its result.
    'session_ttl_minutes' => (int) env('BIOMETRICS_SESSION_TTL', 10),
];
