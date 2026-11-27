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
    ],

    'event_retention_days' => 365,
];
