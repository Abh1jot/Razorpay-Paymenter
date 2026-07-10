{{-- Razorpay Checkout View --}}
{{-- Handles both one-time order payments and subscription-based payments --}}

@assets
<script src="https://checkout.razorpay.com/v1/checkout.js"></script>
@endassets

@script
<script>
    const mode = "{{ $mode }}";

    if (mode === 'subscription') {
        // ── Subscription Checkout ──
        // Creates a subscription that auto-charges the user's card/UPI on every renewal
        const options = {
            key: "{{ $keyId }}",
            subscription_id: "{{ $subscriptionId }}",
            name: "{{ config('app.name', 'Paymenter') }}",
            description: "Pay & enable auto-renewal",
            handler: function(response) {
                // Build redirect with payment verification params
                const params = new URLSearchParams({
                    razorpay_subscription_id: response.razorpay_subscription_id,
                    razorpay_payment_id: response.razorpay_payment_id,
                    razorpay_signature: response.razorpay_signature,
                    invoice_id: "{{ $invoiceId }}",
                });
                window.location.href = "{{ route('extensions.gateways.razorpay.subscription-callback') }}?" + params.toString();
            },
            prefill: {
                name: "{{ $customerName ?? '' }}",
                email: "{{ $customerEmail ?? '' }}",
            },
            theme: {
                color: "#528FF0",
            },
            modal: {
                ondismiss: function() {
                    window.location.href = "{{ route('extensions.gateways.razorpay.cancel', ['invoiceId' => $invoiceId]) }}";
                },
                confirm_close: true,
            },
        };

        const razorpay = new Razorpay(options);
        razorpay.open();
    } else {
        // ── Order Checkout (One-Time Payment) ──
        // Original flow — creates a one-time Razorpay order
        const options = {
            key: "{{ $keyId }}",
            amount: "{{ $orderAmount ?? '' }}",
            currency: "INR",
            name: "{{ config('app.name', 'Paymenter') }}",
            order_id: "{{ $id ?? '' }}",
            callback_url: "{{ route('extensions.gateways.razorpay.callback', ['invoiceId' => $invoiceId]) }}",
            modal: {
                ondismiss: function() {
                    window.location.href = "{{ route('extensions.gateways.razorpay.cancel', ['invoiceId' => $invoiceId]) }}";
                },
            },
        };

        const razorpay = new Razorpay(options);
        razorpay.open();
    }
</script>
@endscript