# TicketArget — Booking Service

Laravel 13 (FrankenPHP) service owning the purchase path: the waiting-room queue
(HMAC admission tokens), seat holds (per-seat Redis locks + `SELECT … FOR UPDATE`
in an ACID transaction), Stripe payment with refund-on-failed-commit
compensation, and the reservation-expiry sweeper.

Endpoints: `POST /queue/join`, `POST /reserve`, `POST /booking`,
`POST /booking/webhook`.

Part of the [TicketArget platform](https://github.com/ikarolaborda/ticketarget) —
run it from the aggregator repo, which provides the Docker topology and shared
`ticketarget/logging` package.
