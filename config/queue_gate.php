<?php

declare(strict_types=1);

return [
    // Secret used to sign waiting-room tokens (defaults to the app key).
    'secret' => env('QUEUE_TOKEN_SECRET', env('APP_KEY', '')),

    // How long an admitted token stays valid.
    'ttl_seconds' => (int) env('QUEUE_TOKEN_TTL', 600),

    // Maximum number of users concurrently admitted to the purchase path per
    // event. Everyone above the cap waits — this is the on-sale waiting room.
    'admission_cap' => (int) env('QUEUE_ADMISSION_CAP', 5000),
];
