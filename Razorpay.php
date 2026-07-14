<?php

namespace Paymenter\Extensions\Gateways\Razorpay;

use App\Attributes\ExtensionMeta;
use App\Classes\Extension\Gateway;
use App\Events\Service\Updated;
use App\Events\ServiceCancellation\Created;
use App\Helpers\ExtensionHelper;
use App\Models\BillingAgreement;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Service;
use App\Models\User;
use Exception;
use GuzzleHttp\Client;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\View;

#[ExtensionMeta(
    name: 'Razorpay Gateway',
    description: 'Accept payments via Razorpay with automatic subscription and billing agreement support.',
    version: '2.0.0',
    author: 'Paymenter Community',
    url: 'https://github.com/Abh1jot/Razorpay-Paymenter',
)]
class Razorpay extends Gateway
{
    /**
     * Boot the gateway: register routes and event listeners.
     * Mirrors the PayPal/Stripe boot() pattern for subscription lifecycle management.
     */
    public function boot()
    {
        require __DIR__ . '/routes/web.php';
        View::addNamespace('extensions.gateways.razorpay', __DIR__ . '/views');

        // Listen for service updates (price changes, cancellation status)
        Event::listen(Updated::class, function (Updated $event) {
            if ($event->service->properties->where('key', 'has_razorpay_subscription')->first()?->value !== '1') {
                return;
            }
            if ($event->service->isDirty('status') && $event->service->status === Service::STATUS_CANCELLED) {
                try {
                    $this->cancelSubscription($event->service);
                } catch (Exception $e) {
                    Log::warning('Razorpay: Failed to cancel subscription on service status change', [
                        'service_id' => $event->service->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        });

        // Listen for service cancellation requests
        Event::listen(Created::class, function (Created $event) {
            $service = $event->cancellation->service;
            if ($service->properties->where('key', 'has_razorpay_subscription')->first()?->value !== '1') {
                return;
            }
            try {
                $this->cancelSubscription($service);
            } catch (Exception $e) {
                Log::warning('Razorpay: Failed to cancel subscription on cancellation request', [
                    'service_id' => $service->id,
                    'error' => $e->getMessage(),
                ]);
            }
        });
    }

    /**
     * Gateway configuration fields shown in the Paymenter admin panel.
     */
    public function getConfig($values = [])
    {
        return [
            [
                'name' => 'key_id',
                'label' => 'Key ID',
                'description' => 'Live mode Key ID from https://razorpay.com/docs/payments/dashboard/account-settings/api-keys/',
                'type' => 'text',
                'required' => true,
            ],
            [
                'name' => 'key_secret',
                'label' => 'Key Secret',
                'description' => 'Live mode Key Secret from https://razorpay.com/docs/payments/dashboard/account-settings/api-keys/',
                'type' => 'text',
                'required' => true,
            ],
            [
                'name' => 'webhook_secret',
                'label' => 'Webhook Secret',
                'description' => 'Webhook secret from Razorpay Dashboard → Webhooks. URL: https://<your_domain>/extensions/gateways/razorpay/webhook',
                'type' => 'text',
                'required' => true,
            ],
            [
                'name' => 'support_billing_agreements',
                'label' => 'Enable Billing Agreements',
                'description' => 'Enable saved payment methods and recurring subscriptions via Razorpay Subscriptions API.',
                'type' => 'checkbox',
                'required' => false,
            ],
            [
                'name' => 'test_mode',
                'label' => 'Test Mode',
                'type' => 'checkbox',
                'required' => false,
            ],
            [
                'name' => 'test_key_id',
                'label' => 'Test Key ID',
                'description' => 'Test mode Key ID from https://razorpay.com/docs/payments/dashboard/account-settings/api-keys/',
                'type' => 'text',
                'required' => false,
            ],
            [
                'name' => 'test_key_secret',
                'label' => 'Test Key Secret',
                'description' => 'Test mode Key Secret from https://razorpay.com/docs/payments/dashboard/account-settings/api-keys/',
                'type' => 'text',
                'required' => false,
            ],
        ];
    }

    // ─── Billing Agreement Contract ────────────────────────────────────

    /**
     * Whether this gateway supports billing agreements / saved payment methods.
     * Matches the PayPal/Stripe supportsBillingAgreements() pattern.
     */
    public function supportsBillingAgreements(): bool
    {
        return $this->config('support_billing_agreements') ?? false;
    }

    /**
     * Create a billing agreement for the given user.
     *
     * Flow:
     * 1. Create or retrieve a Razorpay customer
     * 2. Create a minimal plan for authorization
     * 3. Create a subscription for the auth transaction
     * 4. Return a view that opens Razorpay Checkout in subscription mode
     *
     * After the user authorizes, Razorpay redirects to setupAgreement().
     */
    public function createBillingAgreement(User $user)
    {
        $customer = $this->createOrGetRazorpayCustomer($user);

        // Create a minimal plan for the authorization transaction (₹1).
        // This subscription is only used to capture payment method authorization
        // and will be cancelled immediately after setup.
        $plan = $this->getOrCreatePlan(100, 'monthly', 1, 'INR', 'auth');

        $subscription = $this->apiRequest('POST', '/v1/subscriptions', [
            'plan_id' => $plan['id'],
            'total_count' => 1,
            'customer_notify' => 0,
            'notes' => [
                'purpose' => 'billing_agreement_auth',
                'user_id' => $user->id,
            ],
        ]);

        Log::info('Razorpay: Created auth subscription for billing agreement', [
            'user_id' => $user->id,
            'subscription_id' => $subscription['id'],
        ]);

        return view('extensions.gateways.razorpay::billing-agreement', [
            'keyId' => $this->getKeyId(),
            'subscriptionId' => $subscription['id'],
            'customerName' => $user->name,
            'customerEmail' => $user->email,
            'setupAgreementUrl' => url('/extensions/gateways/razorpay/setup-agreement'),
            'cancelUrl' => url('/account/payment-methods'),
        ]);
    }

    /**
     * Callback route after user authorizes the billing agreement in Razorpay Checkout.
     * Validates the subscription, stores the billing agreement, and cancels the auth subscription.
     */
    public function setupAgreement(Request $request)
    {
        $request->validate([
            'razorpay_subscription_id' => 'required|string|max:255',
            'razorpay_payment_id' => 'required|string|max:255',
            'razorpay_signature' => 'required|string|max:255',
        ]);

        $subscriptionId = $request->input('razorpay_subscription_id');
        $paymentId = $request->input('razorpay_payment_id');
        $signature = $request->input('razorpay_signature');

        // Verify the payment signature
        $expectedSignature = hash_hmac('sha256', $paymentId . '|' . $subscriptionId, $this->getKeySecret());
        if (!hash_equals($expectedSignature, $signature)) {
            Log::warning('Razorpay: Invalid signature during billing agreement setup');
            return redirect()->route('account.payment-methods')->with('notification', [
                'type' => 'danger',
                'message' => 'Could not verify payment. Please try again.',
            ]);
        }

        // Fetch the subscription to get customer details
        $subscription = $this->apiRequest('GET', '/v1/subscriptions/' . $subscriptionId);
        $customerId = $subscription['customer_id'] ?? null;

        if (!$customerId) {
            Log::warning('Razorpay: No customer_id found on auth subscription', [
                'subscription_id' => $subscriptionId,
            ]);
            return redirect()->route('account.payment-methods')->with('notification', [
                'type' => 'danger',
                'message' => 'Could not set up payment method. Please try again.',
            ]);
        }

        // Store the billing agreement using Paymenter's helper
        ExtensionHelper::makeBillingAgreement(
            Auth::user(),
            'Razorpay',
            'Razorpay (' . Auth::user()->email . ')',
            $customerId,
            'razorpay',
        );

        // Cancel the auth-only subscription — it was just for payment method capture
        try {
            $this->apiRequest('POST', '/v1/subscriptions/' . $subscriptionId . '/cancel', [
                'cancel_at_cycle_end' => 0,
            ]);
        } catch (Exception $e) {
            Log::info('Razorpay: Could not cancel auth subscription (may already be cancelled)', [
                'subscription_id' => $subscriptionId,
            ]);
        }

        Log::info('Razorpay: Billing agreement created successfully', [
            'user_id' => Auth::id(),
            'customer_id' => $customerId,
        ]);

        return redirect()->route('account.payment-methods')->with('notification', [
            'type' => 'success',
            'message' => 'Payment method added successfully.',
        ]);
    }

    /**
     * Cancel a billing agreement.
     * Cancels any active Razorpay subscriptions tied to this agreement.
     */
    public function cancelBillingAgreement(BillingAgreement $billingAgreement): bool
    {
        $services = Service::where('billing_agreement_id', $billingAgreement->id)
            ->whereNotNull('subscription_id')
            ->get();

        foreach ($services as $service) {
            try {
                $this->cancelSubscription($service);
            } catch (Exception $e) {
                Log::warning('Razorpay: Failed to cancel subscription during billing agreement removal', [
                    'service_id' => $service->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Log::info('Razorpay: Billing agreement cancelled', [
            'billing_agreement_id' => $billingAgreement->id,
        ]);

        return true;
    }

    /**
     * Charge a billing agreement for a specific invoice amount.
     * Creates a Razorpay plan + subscription for the invoice's billing period and amount.
     * Razorpay auto-charges — webhook records payment.
     */
    public function charge(Invoice $invoice, $total, BillingAgreement $billingAgreement)
    {
        if ($invoice->currency_code !== 'INR') {
            throw new Exception('Razorpay subscriptions only support INR currency.');
        }

        $customerId = $billingAgreement->external_reference;
        $amountInPaise = (int) round($total * 100);

        // Determine billing period from the invoice's service
        $period = 'monthly';
        $interval = 1;

        $serviceItem = $invoice->items()->where('reference_type', Service::class)->first();
        if ($serviceItem && $serviceItem->reference) {
            $service = $serviceItem->reference;
            if ($service->plan) {
                $period = $this->mapBillingUnit($service->plan->billing_unit);
                $interval = $service->plan->billing_period ?? 1;
            }
        }

        $plan = $this->getOrCreatePlan($amountInPaise, $period, $interval, 'INR');

        $subscription = $this->apiRequest('POST', '/v1/subscriptions', [
            'plan_id' => $plan['id'],
            'total_count' => 120,
            'customer_id' => $customerId,
            'customer_notify' => 0,
            'notes' => [
                'invoice_id' => $invoice->id,
                'user_id' => $billingAgreement->user_id,
                'billing_agreement_id' => $billingAgreement->id,
            ],
        ]);

        // Store subscription ID on all services in this invoice
        $invoice->items()->where('reference_type', Service::class)->each(function (InvoiceItem $item) use ($subscription) {
            $service = $item->reference;
            if ($service) {
                $service->update(['subscription_id' => $subscription['id']]);
                $service->properties()->updateOrCreate(
                    ['key' => 'has_razorpay_subscription'],
                    ['value' => true]
                );
            }
        });

        Log::info('Razorpay: Subscription created for billing agreement charge', [
            'invoice_id' => $invoice->id,
            'subscription_id' => $subscription['id'],
            'plan_id' => $plan['id'],
        ]);

        return true;
    }

    // ─── Invoice Payment (Auto-Subscription) ──────────────────────────

    /**
     * Pay an invoice.
     *
     * If the invoice contains a recurring service AND billing agreements are enabled,
     * a Razorpay subscription is created automatically. The first payment authorizes
     * the card/UPI, and all future renewals are auto-charged by Razorpay.
     *
     * For one-time invoices (or when billing agreements are disabled), the original
     * Razorpay Orders flow is used.
     */
    public function pay($invoice, $total)
    {
        if ($invoice->currency_code !== 'INR') {
            return view('extensions.gateways.razorpay::error', [
                'error' => 'The product currency code must be "INR" to make payments with Razorpay!',
            ]);
        }

        // Check if this invoice has a recurring service and billing agreements are enabled
        $recurringService = $this->getRecurringService($invoice);

        if ($recurringService && $this->supportsBillingAgreements()) {
            // If the service already has an active subscription, don't create a duplicate.
            // The existing subscription will auto-charge via webhook.
            // Fall back to one-time order flow for this specific invoice.
            if ($recurringService->subscription_id) {
                Log::info('Razorpay: Service already has active subscription, using order flow', [
                    'service_id' => $recurringService->id,
                    'subscription_id' => $recurringService->subscription_id,
                    'invoice_id' => $invoice->id,
                ]);
                return $this->payWithOrder($invoice, $total);
            }

            return $this->payWithSubscription($invoice, $total, $recurringService);
        }

        return $this->payWithOrder($invoice, $total);
    }

    /**
     * Pay using Razorpay Subscription — creates a subscription so that the user's
     * card/UPI is auto-charged on every renewal. Also auto-creates a billing agreement.
     */
    private function payWithSubscription(Invoice $invoice, $total, Service $service)
    {
        $keyId = $this->getKeyId();
        $amountInPaise = (int) round($total * 100);
        $user = $invoice->user;

        // Create or get Razorpay customer
        $customer = $this->createOrGetRazorpayCustomer($user);

        // Determine billing period from service plan
        $period = 'monthly';
        $interval = 1;
        if ($service->plan) {
            $period = $this->mapBillingUnit($service->plan->billing_unit);
            $interval = $service->plan->billing_period ?? 1;
        }

        // Create a plan for the invoice amount
        $plan = $this->getOrCreatePlan($amountInPaise, $period, $interval, 'INR');

        // Create the subscription
        $subscription = $this->apiRequest('POST', '/v1/subscriptions', [
            'plan_id' => $plan['id'],
            'total_count' => 120, // Up to 120 billing cycles
            'customer_id' => $customer['id'],
            'customer_notify' => 0,
            'notes' => [
                'invoice_id' => $invoice->id,
                'user_id' => $user->id,
                'purpose' => 'invoice_subscription',
            ],
        ]);

        Log::info('Razorpay: Created subscription for invoice payment', [
            'invoice_id' => $invoice->id,
            'subscription_id' => $subscription['id'],
            'plan_id' => $plan['id'],
        ]);

        return view('extensions.gateways.razorpay::pay', [
            'keyId' => $keyId,
            'invoiceId' => $invoice->id,
            // Subscription mode — checkout uses subscription_id instead of order_id
            'mode' => 'subscription',
            'subscriptionId' => $subscription['id'],
            'customerName' => $user->name,
            'customerEmail' => $user->email,
            // Pass URLs directly — route() is unreliable for extension routes
            'callbackUrl' => url('/extensions/gateways/razorpay/subscription-callback'),
            'cancelUrl' => url('/extensions/gateways/razorpay/cancel/' . $invoice->id),
        ]);
    }

    /**
     * Pay using Razorpay Order — the original one-time payment flow.
     * Used for non-recurring invoices or when billing agreements are disabled.
     */
    private function payWithOrder(Invoice $invoice, $total)
    {
        $keyId = $this->getKeyId();
        $orderAmount = (int) round($total * 100);

        $payload = [
            'amount' => $orderAmount,
            'currency' => $invoice->currency_code,
            'notes' => [
                'invoice_id' => $invoice->id,
            ],
        ];

        try {
            $data = $this->apiRequest('POST', '/v1/orders', $payload);

            return view('extensions.gateways.razorpay::pay', [
                'keyId' => $keyId,
                'invoiceId' => $invoice->id,
                // Order mode — original one-time payment flow
                'mode' => 'order',
                'id' => $data['id'],
                'orderAmount' => $orderAmount,
                // Pass URLs directly — route() is unreliable for extension routes
                'callbackUrl' => url('/extensions/gateways/razorpay/callback/' . $invoice->id),
                'cancelUrl' => url('/extensions/gateways/razorpay/cancel/' . $invoice->id),
            ]);
        } catch (Exception $e) {
            Log::error('Razorpay: Failed to create order', [
                'invoice_id' => $invoice->id,
                'error' => $e->getMessage(),
            ]);
            throw new Exception('Failed to create Razorpay order. Please try again.');
        }
    }

    /**
     * Callback after successful subscription-based invoice payment.
     * Verifies the payment, records it, creates billing agreement, and links subscription.
     */
    public function subscriptionCallback(Request $request)
    {
        $request->validate([
            'razorpay_subscription_id' => 'required|string|max:255',
            'razorpay_payment_id' => 'required|string|max:255',
            'razorpay_signature' => 'required|string|max:255',
            'invoice_id' => 'required',
        ]);

        $subscriptionId = $request->input('razorpay_subscription_id');
        $paymentId = $request->input('razorpay_payment_id');
        $signature = $request->input('razorpay_signature');
        $invoiceId = $request->input('invoice_id');

        // Verify the payment signature
        $expectedSignature = hash_hmac('sha256', $paymentId . '|' . $subscriptionId, $this->getKeySecret());
        if (!hash_equals($expectedSignature, $signature)) {
            Log::warning('Razorpay: Invalid signature on subscription callback', [
                'invoice_id' => $invoiceId,
            ]);
            return redirect()->route('invoices.show', ['invoice' => $invoiceId])->with('notification', [
                'type' => 'danger',
                'message' => 'Payment verification failed. Please contact support.',
            ]);
        }

        // Fetch the subscription to get customer details
        $subscription = $this->apiRequest('GET', '/v1/subscriptions/' . $subscriptionId);
        $customerId = $subscription['customer_id'] ?? null;

        // Load invoice and use its remaining balance as payment amount
        // This guarantees remaining drops to exactly 0, which triggers
        // Paymenter's InvoiceTransactionCreatedListener to mark it as paid
        $invoice = Invoice::findOrFail($invoiceId);
        $amount = $invoice->remaining;

        // Record the first payment on the invoice
        ExtensionHelper::addPayment($invoiceId, 'Razorpay', $amount, null, $paymentId);

        // Link the subscription to services in this invoice
        $invoice->items()->where('reference_type', Service::class)->each(function (InvoiceItem $item) use ($subscriptionId) {
            $service = $item->reference;
            if ($service) {
                $service->update(['subscription_id' => $subscriptionId]);
                $service->properties()->updateOrCreate(
                    ['key' => 'has_razorpay_subscription'],
                    ['value' => true]
                );
            }
        });

        // Auto-create billing agreement so the user has a saved payment method
        if ($customerId && Auth::check()) {
            ExtensionHelper::makeBillingAgreement(
                Auth::user(),
                'Razorpay',
                'Razorpay (' . Auth::user()->email . ')',
                $customerId,
                'razorpay',
            );

            Log::info('Razorpay: Auto-created billing agreement from invoice payment', [
                'user_id' => Auth::id(),
                'customer_id' => $customerId,
            ]);
        }

        Log::info('Razorpay: Subscription payment completed and linked', [
            'invoice_id' => $invoiceId,
            'subscription_id' => $subscriptionId,
            'payment_id' => $paymentId,
        ]);

        return redirect()->route('invoices.show', ['invoice' => $invoiceId])->with('notification', [
            'type' => 'success',
            'message' => 'Payment successful! Your card/UPI will be auto-charged for future renewals.',
        ]);
    }

    // ─── Webhook Handler ───────────────────────────────────────────────

    /**
     * Handle Razorpay webhook events.
     * Processes both subscription lifecycle events and payment events.
     * All handlers are idempotent (safe to replay).
     */
    public function webhook(Request $request)
    {
        $content = $request->getContent();
        $signature = $request->header('X-Razorpay-Signature');
        $secret = $this->config('webhook_secret');

        // Verify webhook signature
        $expectedSignature = hash_hmac('sha256', $content, $secret);
        if (!hash_equals($expectedSignature, $signature ?? '')) {
            Log::warning('Razorpay: Webhook signature verification failed');
            return response('Signature verification failed', 401);
        }

        $data = json_decode($content, true);
        $event = $data['event'] ?? '';

        Log::info('Razorpay: Webhook received', ['event' => $event]);

        switch ($event) {
            // ── One-time payment events (backward compatible) ──
            case 'order.paid':
                $this->handleOrderPaid($data);
                break;

            // ── Payment events ──
            case 'payment.captured':
                $this->handlePaymentCaptured($data);
                break;

            case 'payment.failed':
                $this->handlePaymentFailed($data);
                break;

            case 'payment.authorized':
                Log::info('Razorpay: Payment authorized', [
                    'payment_id' => $data['payload']['payment']['entity']['id'] ?? 'unknown',
                ]);
                break;

            // ── Subscription lifecycle events ──
            case 'subscription.authenticated':
                $this->handleSubscriptionAuthenticated($data);
                break;

            case 'subscription.activated':
                $this->handleSubscriptionActivated($data);
                break;

            case 'subscription.charged':
                $this->handleSubscriptionCharged($data);
                break;

            case 'subscription.completed':
                Log::info('Razorpay: Subscription completed', [
                    'subscription_id' => $data['payload']['subscription']['entity']['id'] ?? 'unknown',
                ]);
                break;

            case 'subscription.cancelled':
                $this->handleSubscriptionCancelled($data);
                break;

            case 'subscription.pending':
                Log::info('Razorpay: Subscription payment pending', [
                    'subscription_id' => $data['payload']['subscription']['entity']['id'] ?? 'unknown',
                ]);
                break;

            case 'subscription.halted':
                Log::warning('Razorpay: Subscription halted (all retries exhausted)', [
                    'subscription_id' => $data['payload']['subscription']['entity']['id'] ?? 'unknown',
                ]);
                break;

            case 'subscription.paused':
                Log::info('Razorpay: Subscription paused', [
                    'subscription_id' => $data['payload']['subscription']['entity']['id'] ?? 'unknown',
                ]);
                break;

            case 'subscription.resumed':
                Log::info('Razorpay: Subscription resumed', [
                    'subscription_id' => $data['payload']['subscription']['entity']['id'] ?? 'unknown',
                ]);
                break;

            default:
                Log::info('Razorpay: Unhandled webhook event', ['event' => $event]);
        }

        return response('Webhook processed', 200);
    }

    // ─── Webhook Event Handlers ────────────────────────────────────────

    /**
     * Handle order.paid — the original one-time payment webhook.
     * Kept for backward compatibility with existing invoices.
     */
    private function handleOrderPaid(array $data)
    {
        $paymentId = $data['payload']['payment']['entity']['id'] ?? null;
        $orderAmount = ($data['payload']['order']['entity']['amount'] ?? 0) / 100;
        $invoiceId = $data['payload']['order']['entity']['notes']['invoice_id'] ?? null;

        if (!$invoiceId || !$paymentId) {
            Log::warning('Razorpay: order.paid webhook missing invoice_id or payment_id');
            return;
        }

        ExtensionHelper::addPayment($invoiceId, 'Razorpay', $orderAmount, null, $paymentId);

        Log::info('Razorpay: Order payment recorded', [
            'invoice_id' => $invoiceId,
            'payment_id' => $paymentId,
        ]);
    }

    /**
     * Handle payment.captured — for one-time payments captured outside of subscriptions.
     */
    private function handlePaymentCaptured(array $data)
    {
        $payment = $data['payload']['payment']['entity'] ?? [];
        $paymentId = $payment['id'] ?? null;
        $amount = ($payment['amount'] ?? 0) / 100;
        $invoiceId = $payment['notes']['invoice_id'] ?? null;

        if (!$invoiceId || !$paymentId) {
            return;
        }

        // Skip if this payment is tied to a subscription (handled by subscription.charged)
        if (!empty($payment['subscription_id'])) {
            return;
        }

        ExtensionHelper::addPayment($invoiceId, 'Razorpay', $amount, null, $paymentId);

        Log::info('Razorpay: Payment captured', [
            'invoice_id' => $invoiceId,
            'payment_id' => $paymentId,
        ]);
    }

    /**
     * Handle payment.failed — log the failure for debugging.
     */
    private function handlePaymentFailed(array $data)
    {
        $payment = $data['payload']['payment']['entity'] ?? [];
        $paymentId = $payment['id'] ?? null;
        $invoiceId = $payment['notes']['invoice_id'] ?? null;
        $amount = ($payment['amount'] ?? 0) / 100;

        Log::warning('Razorpay: Payment failed', [
            'payment_id' => $paymentId,
            'invoice_id' => $invoiceId,
            'error_code' => $payment['error_code'] ?? 'unknown',
            'error_description' => $payment['error_description'] ?? 'unknown',
        ]);

        if ($invoiceId && $paymentId) {
            ExtensionHelper::addFailedPayment($invoiceId, 'Razorpay', $amount, null, $paymentId);
        }
    }

    /**
     * Handle subscription.authenticated — first payment authorization succeeded.
     */
    private function handleSubscriptionAuthenticated(array $data)
    {
        $subscriptionId = $data['payload']['subscription']['entity']['id'] ?? null;

        Log::info('Razorpay: Subscription authenticated', [
            'subscription_id' => $subscriptionId,
        ]);
    }

    /**
     * Handle subscription.activated — subscription is now active.
     */
    private function handleSubscriptionActivated(array $data)
    {
        $subscription = $data['payload']['subscription']['entity'] ?? [];
        $subscriptionId = $subscription['id'] ?? null;
        $invoiceId = $subscription['notes']['invoice_id'] ?? null;

        Log::info('Razorpay: Subscription activated', [
            'subscription_id' => $subscriptionId,
            'invoice_id' => $invoiceId,
        ]);

        // Link the subscription to the service if invoice_id is available
        if ($invoiceId) {
            try {
                Invoice::findOrFail($invoiceId)->items()
                    ->where('reference_type', Service::class)
                    ->each(function (InvoiceItem $item) use ($subscriptionId) {
                        $service = $item->reference;
                        if ($service) {
                            $service->update(['subscription_id' => $subscriptionId]);
                            $service->properties()->updateOrCreate(
                                ['key' => 'has_razorpay_subscription'],
                                ['value' => true]
                            );
                        }
                    });
            } catch (Exception $e) {
                Log::warning('Razorpay: Could not link subscription to service', [
                    'subscription_id' => $subscriptionId,
                    'invoice_id' => $invoiceId,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Handle subscription.charged — a recurring payment was successfully collected.
     * This is the primary handler for recording auto-charged recurring payments.
     */
    private function handleSubscriptionCharged(array $data)
    {
        $subscription = $data['payload']['subscription']['entity'] ?? [];
        $payment = $data['payload']['payment']['entity'] ?? [];
        $subscriptionId = $subscription['id'] ?? null;
        $paymentId = $payment['id'] ?? null;

        if (!$subscriptionId || !$paymentId) {
            Log::warning('Razorpay: subscription.charged missing subscription_id or payment_id');
            return;
        }

        // Find all services linked to this subscription
        $services = Service::where('subscription_id', $subscriptionId)->get();

        foreach ($services as $service) {
            // Add payment to the most recent UNPAID invoice for this service
            $latestInvoice = $service->invoices()->where('status', '!=', 'paid')->latest()->first();
            if ($latestInvoice) {
                $remaining = $latestInvoice->remaining;
                ExtensionHelper::addPayment(
                    $latestInvoice->id,
                    'Razorpay',
                    $remaining,
                    null,
                    $paymentId
                );

                Log::info('Razorpay: Subscription auto-charge recorded', [
                    'service_id' => $service->id,
                    'invoice_id' => $latestInvoice->id,
                    'payment_id' => $paymentId,
                    'amount' => $remaining,
                ]);
            }
        }
    }

    /**
     * Handle subscription.cancelled — clean up service properties.
     */
    private function handleSubscriptionCancelled(array $data)
    {
        $subscription = $data['payload']['subscription']['entity'] ?? [];
        $subscriptionId = $subscription['id'] ?? null;

        if (!$subscriptionId) {
            return;
        }

        $services = Service::where('subscription_id', $subscriptionId)->get();

        foreach ($services as $service) {
            $service->properties()->where('key', 'has_razorpay_subscription')->delete();
            $service->update(['subscription_id' => null]);
        }

        Log::info('Razorpay: Subscription cancelled and cleaned up', [
            'subscription_id' => $subscriptionId,
        ]);
    }

    // ─── Subscription Management ───────────────────────────────────────

    /**
     * Cancel a Razorpay subscription for a service.
     * Gracefully handles already-cancelled subscriptions.
     */
    public function cancelSubscription(Service $service)
    {
        if (!$service->subscription_id) {
            return;
        }

        try {
            $this->apiRequest('POST', '/v1/subscriptions/' . $service->subscription_id . '/cancel', [
                'cancel_at_cycle_end' => 0,
            ]);
        } catch (Exception $e) {
            // Gracefully handle already-cancelled subscriptions
            if (str_contains($e->getMessage(), 'already cancelled') || str_contains($e->getMessage(), 'already completed')) {
                Log::info('Razorpay: Subscription already in terminal state', [
                    'subscription_id' => $service->subscription_id,
                ]);
            } else {
                throw $e;
            }
        }

        $service->update(['subscription_id' => null]);
        $service->properties()->where('key', 'has_razorpay_subscription')->delete();

        Log::info('Razorpay: Subscription cancelled', [
            'service_id' => $service->id,
        ]);

        return true;
    }

    // ─── Customer Management ───────────────────────────────────────────

    /**
     * Create or retrieve a Razorpay customer for the given user.
     * Stores the customer ID in user properties to avoid duplicate creation.
     */
    private function createOrGetRazorpayCustomer(User $user): array
    {
        $existingCustomerId = $user->properties()->where('key', 'razorpay_customer_id')->first();

        if ($existingCustomerId) {
            try {
                $customer = $this->apiRequest('GET', '/v1/customers/' . $existingCustomerId->value);
                return $customer;
            } catch (Exception $e) {
                Log::info('Razorpay: Existing customer not found, creating new one', [
                    'user_id' => $user->id,
                ]);
            }
        }

        $customer = $this->apiRequest('POST', '/v1/customers', [
            'name' => $user->name,
            'email' => $user->email,
            'notes' => [
                'user_id' => $user->id,
            ],
        ]);

        $user->properties()->updateOrCreate(
            ['key' => 'razorpay_customer_id'],
            ['value' => $customer['id']]
        );

        Log::info('Razorpay: Customer created', [
            'user_id' => $user->id,
            'customer_id' => $customer['id'],
        ]);

        return $customer;
    }

    // ─── Plan Management ───────────────────────────────────────────────

    /**
     * Create a Razorpay plan. Plans are lightweight templates —
     * creating a duplicate for the same amount is harmless.
     *
     * @param int    $amountInPaise Amount in smallest currency unit
     * @param string $period        One of: daily, weekly, monthly, yearly
     * @param int    $interval      How many periods between charges
     * @param string $currency      Currency code (must be INR)
     * @param string $suffix        Optional suffix for plan name uniqueness
     */
    private function getOrCreatePlan(int $amountInPaise, string $period, int $interval, string $currency, string $suffix = ''): array
    {
        $planName = 'paymenter_' . $amountInPaise . '_' . strtolower($currency) . '_' . $period . '_' . $interval;
        if ($suffix) {
            $planName .= '_' . $suffix;
        }

        // Check if we already have a cached plan_id for this combination
        // Plans are stored in a Razorpay-specific user property on the gateway settings
        $cacheKey = 'razorpay_plan_' . md5($planName);
        $cachedPlanId = null;
        try {
            $setting = \App\Models\Setting::where('settingable_type', 'App\\Models\\Gateway')
                ->where('key', $cacheKey)
                ->first();
            if ($setting) {
                // Verify the plan still exists at Razorpay
                $plan = $this->apiRequest('GET', '/v1/plans/' . $setting->value);
                if (isset($plan['id'])) {
                    Log::info('Razorpay: Reusing existing plan', [
                        'plan_id' => $plan['id'],
                        'plan_name' => $planName,
                    ]);
                    return $plan;
                }
            }
        } catch (Exception $e) {
            // Plan doesn't exist anymore, create a new one
            Log::info('Razorpay: Cached plan not found, creating new one', [
                'plan_name' => $planName,
            ]);
        }

        try {
            $plan = $this->apiRequest('POST', '/v1/plans', [
                'period' => $period,
                'interval' => $interval,
                'item' => [
                    'name' => $planName,
                    'amount' => $amountInPaise,
                    'currency' => $currency,
                ],
                'notes' => [
                    'paymenter_plan_key' => $planName,
                    'source' => 'paymenter',
                ],
            ]);

            // Cache the plan_id for future reuse
            \App\Models\Setting::updateOrCreate(
                [
                    'settingable_type' => 'App\\Models\\Gateway',
                    'settingable_id' => 0,
                    'key' => $cacheKey,
                ],
                ['value' => $plan['id']]
            );

            Log::info('Razorpay: Plan created', [
                'plan_id' => $plan['id'],
                'plan_name' => $planName,
            ]);

            return $plan;
        } catch (Exception $e) {
            Log::error('Razorpay: Failed to create plan', [
                'plan_name' => $planName,
                'error' => $e->getMessage(),
            ]);
            throw new Exception('Failed to create Razorpay billing plan. Please try again.');
        }
    }

    // ─── Helpers ───────────────────────────────────────────────────────

    /**
     * Check if an invoice contains a recurring service with a billing plan.
     * Returns the first recurring service found, or null for one-time invoices.
     */
    private function getRecurringService(Invoice $invoice): ?Service
    {
        $serviceItem = $invoice->items()->where('reference_type', Service::class)->first();
        if ($serviceItem && $serviceItem->reference && $serviceItem->reference->plan) {
            return $serviceItem->reference;
        }
        return null;
    }

    /**
     * Get the active Key ID (respects test mode toggle).
     */
    private function getKeyId(): string
    {
        return $this->config('test_mode')
            ? $this->config('test_key_id')
            : $this->config('key_id');
    }

    /**
     * Get the active Key Secret (respects test mode toggle).
     */
    private function getKeySecret(): string
    {
        return $this->config('test_mode')
            ? $this->config('test_key_secret')
            : $this->config('key_secret');
    }

    /**
     * Make an authenticated request to the Razorpay API.
     *
     * @throws Exception on API errors or network failures
     */
    private function apiRequest(string $method, string $path, array $data = []): array
    {
        $keyId = $this->getKeyId();
        $keySecret = $this->getKeySecret();

        $client = new Client([
            'base_uri' => 'https://api.razorpay.com',
            'auth' => [$keyId, $keySecret],
            'headers' => [
                'Content-Type' => 'application/json',
            ],
        ]);

        $options = [];
        if (!empty($data) && in_array(strtoupper($method), ['POST', 'PUT', 'PATCH'])) {
            $options['json'] = $data;
        } elseif (!empty($data) && strtoupper($method) === 'GET') {
            $options['query'] = $data;
        }

        // Log EVERY outgoing request with masked key for debugging
        Log::info('Razorpay: >>> API REQUEST', [
            'method' => strtoupper($method),
            'url' => 'https://api.razorpay.com' . $path,
            'key_id' => substr($keyId, 0, 12) . '...',
            'key_mode' => str_starts_with($keyId, 'rzp_test_') ? 'TEST' : 'LIVE',
            'payload' => $data,
        ]);

        try {
            $response = $client->request($method, $path, $options);
            $body = $response->getBody()->getContents();
            $decoded = json_decode($body, true);

            Log::info('Razorpay: <<< API RESPONSE OK', [
                'method' => strtoupper($method),
                'url' => 'https://api.razorpay.com' . $path,
                'http_status' => $response->getStatusCode(),
                'response_id' => $decoded['id'] ?? 'n/a',
            ]);

            return $decoded;
        } catch (\GuzzleHttp\Exception\ClientException $e) {
            $statusCode = $e->getResponse() ? $e->getResponse()->getStatusCode() : 'unknown';
            $responseBody = $e->getResponse() ? $e->getResponse()->getBody()->getContents() : 'No response body';
            $errorData = json_decode($responseBody, true);

            // Log EVERYTHING so we can see the real error
            Log::error('Razorpay: <<< API ERROR', [
                'method' => strtoupper($method),
                'url' => 'https://api.razorpay.com' . $path,
                'key_id' => substr($keyId, 0, 12) . '...',
                'key_mode' => str_starts_with($keyId, 'rzp_test_') ? 'TEST' : 'LIVE',
                'http_status' => $statusCode,
                'request_payload' => $data,
                'raw_response_body' => $responseBody,
                'error_code' => $errorData['error']['code'] ?? null,
                'error_description' => $errorData['error']['description'] ?? null,
                'error_reason' => $errorData['error']['reason'] ?? null,
                'error_field' => $errorData['error']['field'] ?? null,
                'error_source' => $errorData['error']['source'] ?? null,
                'error_step' => $errorData['error']['step'] ?? null,
                'error_metadata' => $errorData['error']['metadata'] ?? null,
            ]);

            $errorDesc = $errorData['error']['description'] ?? $responseBody;
            $errorCode = $errorData['error']['code'] ?? 'HTTP_' . $statusCode;
            $errorReason = $errorData['error']['reason'] ?? 'unknown';

            throw new Exception("Razorpay API error ({$errorCode}): {$errorDesc} (reason: {$errorReason})");
        } catch (\GuzzleHttp\Exception\ConnectException $e) {
            Log::error('Razorpay: Network error', [
                'method' => strtoupper($method),
                'url' => 'https://api.razorpay.com' . $path,
                'error' => $e->getMessage(),
            ]);

            throw new Exception('Could not connect to Razorpay. Please try again later.');
        }
    }

    /**
     * Map Paymenter billing_unit to Razorpay plan period.
     * Razorpay supports: daily, weekly, monthly, yearly.
     */
    private function mapBillingUnit(string $billingUnit): string
    {
        return match (strtolower($billingUnit)) {
            'day', 'daily' => 'daily',
            'week', 'weekly' => 'weekly',
            'month', 'monthly' => 'monthly',
            'year', 'yearly' => 'yearly',
            default => 'monthly',
        };
    }
}
