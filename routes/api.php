<?php

declare(strict_types=1);

use App\Http\Controllers\AdminBookingsController;
use App\Http\Controllers\AdminStatsController;
use App\Http\Controllers\BookingController;
use App\Http\Controllers\JoinQueueController;
use App\Http\Controllers\MyBookingsController;
use App\Http\Controllers\RefundBookingController;
use App\Http\Controllers\ReserveController;
use App\Http\Controllers\ShowReservationController;
use App\Http\Controllers\StripeWebhookController;
use App\Http\Controllers\VerifyQueueController;
use App\Http\Controllers\VerifyTicketController;
use App\Http\Middleware\AdminBearerAuth;
use App\Http\Middleware\OptionalBearerAuth;
use App\Http\Middleware\RequireQueueToken;
use Illuminate\Support\Facades\Route;

Route::post('/queue/join', JoinQueueController::class)->name('queue.join');
Route::get('/internal/queue/verify', VerifyQueueController::class)->name('queue.verify');

Route::post('/reserve', ReserveController::class)->middleware([OptionalBearerAuth::class, RequireQueueToken::class])->name('reserve');
Route::post('/booking', BookingController::class)->middleware(OptionalBearerAuth::class)->name('booking');
Route::get('/booking/mine', MyBookingsController::class)->middleware(OptionalBearerAuth::class)->name('booking.mine');
Route::get('/booking/verify', VerifyTicketController::class)->name('booking.verify');
Route::get('/booking/reservation/{id}', ShowReservationController::class)->middleware(OptionalBearerAuth::class)->name('booking.reservation.show');
Route::post('/booking/{booking}/refund', RefundBookingController::class)->middleware(OptionalBearerAuth::class)->name('booking.refund');

Route::post('/booking/webhook', StripeWebhookController::class)->name('booking.webhook');

// Admin analytics — platform JWT with is_admin only (no Sanctum in this service).
Route::middleware(AdminBearerAuth::class)->group(function (): void {
    Route::get('/booking/admin/stats', AdminStatsController::class)->name('booking.admin.stats');
    Route::get('/booking/admin/bookings', AdminBookingsController::class)->name('booking.admin.bookings');
});
