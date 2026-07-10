<?php

use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\Route;
use Paymenter\Extensions\Gateways\Razorpay\Razorpay;

// Webhook endpoint — exempt from CSRF protection since Razorpay sends POST requests directly
Route::post(
    '/extensions/gateways/razorpay/webhook',
    [Razorpay::class, 'webhook']
)->withoutMiddleware([VerifyCsrfToken::class])
    ->name('extensions.gateways.razorpay.webhook');

// Subscription payment callback — verifies payment, records it, creates billing agreement,
// and links subscription to the service for auto-renewal
Route::get(
    '/extensions/gateways/razorpay/subscription-callback',
    [Razorpay::class, 'subscriptionCallback']
)->middleware(['web', 'auth'])
    ->name('extensions.gateways.razorpay.subscription-callback');

// Billing agreement setup callback — user is redirected here after authorizing
// a billing agreement from the saved payment methods page
Route::get(
    '/extensions/gateways/razorpay/setup-agreement',
    [Razorpay::class, 'setupAgreement']
)->middleware(['web', 'auth'])
    ->name('extensions.gateways.razorpay.setup-agreement');

// One-time payment callback — redirect back to invoice after payment
Route::post('/extensions/gateways/razorpay/callback/{invoiceId}', function ($invoiceId) {
    return redirect()->route('invoices.show', ['invoice' => $invoiceId]);
})->name('extensions.gateways.razorpay.callback');

// Payment cancellation — redirect back to invoice
Route::get('/extensions/gateways/razorpay/cancel/{invoiceId}', function ($invoiceId) {
    return redirect()->route('invoices.show', ['invoice' => $invoiceId]);
})->name('extensions.gateways.razorpay.cancel');
