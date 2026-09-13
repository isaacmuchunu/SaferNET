<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Safeguarding notification channels
    |--------------------------------------------------------------------------
    |
    | A deployment may legitimately run without SMS or without mail — what is not
    | supported is pretending an alert went out when no provider was configured.
    | With the "log" driver the message is written to the application log and
    | recorded as a delivery, so a pilot can see exactly what would have been
    | sent to whom.
    |
    */

    'sms' => [
        // none | log | africastalking
        'provider' => env('SMS_PROVIDER', 'log'),

        'africastalking' => [
            'username' => env('AFRICASTALKING_USERNAME', 'sandbox'),
            'api_key' => env('AFRICASTALKING_API_KEY'),

            // The alphanumeric sender id or short code the county has registered.
            // Africa's Talking rejects an unregistered sender, so leaving this
            // empty sends from the account default instead of failing.
            'sender_id' => env('AFRICASTALKING_SENDER_ID'),

            // The sandbox answers on a different host and accepts any number.
            'endpoint' => env(
                'AFRICASTALKING_ENDPOINT',
                env('AFRICASTALKING_USERNAME', 'sandbox') === 'sandbox'
                    ? 'https://api.sandbox.africastalking.com/version1/messaging'
                    : 'https://api.africastalking.com/version1/messaging',
            ),
        ],

        'timeout' => (int) env('SMS_TIMEOUT', 10),

        // An SMS carries a reference and an urgency, never case notes: it travels
        // over an unencrypted channel and may be read on a lock screen.
        'max_length' => 320,
    ],

    'mail' => [
        'timeout' => (int) env('MAIL_TIMEOUT', 15),
    ],

    /*
    |--------------------------------------------------------------------------
    | Routing
    |--------------------------------------------------------------------------
    |
    | Which severities escalate beyond the portal, and who hears about them when
    | an institution has no reachable officer.
    |
    */

    'escalate_by_sms_from' => env('SAFEGUARDING_SMS_SEVERITY', 'high'),

    'fallback_emails' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('SAFEGUARDING_FALLBACK_EMAILS', '')),
    ))),

];
