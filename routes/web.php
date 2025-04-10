<?php

use Illuminate\Support\Facades\Route;
use Paymenter\Extensions\Gateways\Razorpay\Razorpay;

Route::post(
    '/extensions/gateways/razorpay/webhook',
    [Razorpay::class, 'webhook']
)->name('extensions.gateways.razorpay.webhook');

Route::post('/extensions/gateways/razorpay/callback/{invoiceId}', function ($invoiceId) {
    return redirect()->route('invoices.show', ['invoice' => $invoiceId]);
})->name('extensions.gateways.razorpay.callback');

Route::get('/extensions/gateways/razorpay/cancel/{invoiceId}', function ($invoiceId) {
    return redirect()->route('invoices.show', ['invoice' => $invoiceId]);
})->name('extensions.gateways.razorpay.cancel');
