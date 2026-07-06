<?php

declare(strict_types=1);

return [
    // HMAC secret for the QR ticket codes issued at booking confirmation and
    // checked at entry (TicketCodeIssuer). Deliberately separate from the JWT
    // secret: auth moved to RS256/JWKS, but ticket codes remain a symmetric
    // HMAC owned entirely by booking-service.
    'secret' => env('TICKET_CODE_SECRET', ''),
];
