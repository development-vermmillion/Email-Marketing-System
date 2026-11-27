<?php

return [
    /*
    | Default sending controls. Each can be overridden on the SMTP setting
    | (rate / daily limit) or on the individual campaign.
    */
    'batch_size' => (int) env('MAILING_BATCH_SIZE', 50),
    'rate_per_second' => (int) env('MAILING_RATE_PER_SECOND', 5),
    'rate_per_minute' => (int) env('MAILING_RATE_PER_MINUTE', 300),
    'daily_limit' => env('MAILING_DAILY_LIMIT') !== null && env('MAILING_DAILY_LIMIT') !== ''
        ? (int) env('MAILING_DAILY_LIMIT') : null,
    'max_attempts' => (int) env('MAILING_MAX_ATTEMPTS', 3),
    'retry_delay' => (int) env('MAILING_RETRY_DELAY', 300),

    /* A recipient stuck in "sending" longer than this had an unknown outcome and is never auto-resent. */
    'stale_sending_minutes' => (int) env('MAILING_STALE_SENDING_MINUTES', 15),

    'trash_retention_days' => (int) env('MAILING_TRASH_RETENTION_DAYS', 7),

    'csv' => [
        'max_kb' => (int) env('MAILING_CSV_MAX_KB', 51200),
        'chunk_size' => (int) env('MAILING_CSV_CHUNK_SIZE', 500),
        'disk' => 'local',
        'directory' => 'imports',
    ],

    'queues' => [
        'emails' => 'emails',
        'imports' => 'imports',
        'default' => 'default',
    ],

    /* Click-tracking links older than this are still honoured; kept for future expiry rules. */
    'tracking' => [
        'opens' => true,
        'clicks' => true,
    ],

    'validation' => [
        // Look up the recipient domain's MX/A records. Turn off on a machine without working DNS.
        'dns' => (bool) env('MAILING_VALIDATION_DNS', true),
        // Contacts whose address failed validation are left out of new campaigns (they would only bounce).
        'skip_invalid' => (bool) env('MAILING_SKIP_INVALID_EMAILS', true),
        'chunk_size' => 200,

        // Mailbox-level check: ask the recipient's mail server whether it accepts the address (no email is sent).
        // Needs outbound port 25, which home connections and many cloud hosts block. See docs/DEPLOYMENT.md.
        'smtp_probe' => (bool) env('MAILING_VALIDATION_SMTP_PROBE', false),
        'probe_helo' => env('MAILING_PROBE_HELO'),        // your server's public host name (must resolve); defaults to the APP_URL host
        'probe_mail_from' => env('MAILING_PROBE_FROM'),   // envelope sender used for the probe; defaults to verify@<helo host>
        'probe_port' => (int) env('MAILING_PROBE_PORT', 25),
        'probe_timeout' => (int) env('MAILING_PROBE_TIMEOUT', 8),
        'probe_delay_ms' => (int) env('MAILING_PROBE_DELAY_MS', 1000), // minimum gap between probes to the same domain
    ],

    'event_retention_days' => 365,
];
