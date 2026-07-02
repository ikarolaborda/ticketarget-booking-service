<?php

declare(strict_types=1);

use App\Http\Controllers\BookingController;
use App\Http\Controllers\JoinQueueController;
use App\Http\Controllers\MyBookingsController;
use App\Http\Controllers\VerifyTicketController;
use App\Http\Controllers\ReserveController;
use App\Http\Controllers\StripeWebhookController;
use App\Http\Controllers\VerifyQueueController;
use App\Http\Middleware\OptionalBearerAuth;
use App\Http\Middleware\RequireQueueToken;
use Illuminate\Support\Facades\Route;

Route::post('/queue/join', JoinQueueController::class)->name('queue.join');
Route::get('/internal/queue/verify', VerifyQueueController::class)->name('queue.verify');

Route::post('/reserve', ReserveController::class)->middleware([OptionalBearerAuth::class, RequireQueueToken::class])->name('reserve');
Route::post('/booking', BookingController::class)->middleware(OptionalBearerAuth::class)->name('booking');
Route::get('/booking/mine', MyBookingsController::class)->middleware(OptionalBearerAuth::class)->name('booking.mine');
Route::get('/booking/verify', VerifyTicketController::class)->name('booking.verify');

Route::post('/booking/webhook', StripeWebhookController::class)->name('booking.webhook');
