<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Automated cold outreach
    |--------------------------------------------------------------------------
    |
    | The scheduler can source leads, audit their sites and draft emails without
    | supervision. Actually SENDING without a human reading the message is a
    | different risk class, so it is off unless explicitly enabled.
    |
    */

    // Master switch for eye:send-outreach. Off means the scheduler still builds
    // drafts every day, and a human sends them from the Leads page.
    'auto_send' => (bool) env('OUTREACH_AUTO_SEND', false),

    // Which user's leads the scheduled pipeline works on.
    'user_id' => (int) env('OUTREACH_USER_ID', 1),

    /*
    |--------------------------------------------------------------------------
    | Compliance
    |--------------------------------------------------------------------------
    |
    | CAN-SPAM requires every commercial email to carry the sender's valid
    | physical postal address alongside a working opt-out. Sending without one
    | is a per-message violation, so eye:send-outreach refuses to run until this
    | is set rather than quietly shipping non-compliant mail.
    |
    */
    'postal_address' => env('OUTREACH_POSTAL_ADDRESS'),

    // Sending identity. Keep this OFF the domain that sends email verification,
    // install snippets and alerts: cold volume and spam complaints degrade
    // domain reputation, and the first thing to break is the transactional mail
    // that activation depends on. Falls back to the default mailer identity.
    'from_address' => env('OUTREACH_FROM_ADDRESS'),
    'from_name' => env('OUTREACH_FROM_NAME', 'EYE Analytics'),
    'reply_to' => env('OUTREACH_REPLY_TO'),

    /*
    |--------------------------------------------------------------------------
    | Volume
    |--------------------------------------------------------------------------
    |
    | A domain with no cold-sending history that jumps straight to 25/day gets
    | filtered. The ramp raises the ceiling over the first fortnight, keyed off
    | the date of the first send, and the target is only reached once the domain
    | has a track record. Set OUTREACH_WARMUP=false to skip it.
    |
    */
    'daily_target' => (int) env('OUTREACH_DAILY_TARGET', 25),
    'warmup' => (bool) env('OUTREACH_WARMUP', true),

    // Day-of-ramp => max sends. The last entry applies from then on.
    'warmup_schedule' => [
        1 => 5,
        3 => 8,
        5 => 12,
        8 => 16,
        11 => 20,
        14 => 25,
    ],

    /*
    |--------------------------------------------------------------------------
    | Sourcing rotation
    |--------------------------------------------------------------------------
    |
    | Places returns at most 20 results per query and roughly half of those
    | publish a contact address, so hitting 25 mailable leads a day needs
    | several queries. The pipeline walks this list, moving on each run so the
    | same city is not mined dry.
    |
    */
    'provider' => env('OUTREACH_PROVIDER', 'places'),
    'category' => env('OUTREACH_CATEGORY', 'ecommerce'),
    'areas' => [
        'Austin TX', 'Denver CO', 'Nashville TN', 'Portland OR', 'Charlotte NC',
        'Phoenix AZ', 'Tampa FL', 'Columbus OH', 'Salt Lake City UT', 'Raleigh NC',
        'Kansas City MO', 'Indianapolis IN', 'Minneapolis MN', 'San Diego CA',
        'Pittsburgh PA', 'Cincinnati OH', 'Milwaukee WI', 'Orlando FL',
        'Sacramento CA', 'St Louis MO',
    ],

];
