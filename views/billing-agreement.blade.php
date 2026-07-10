{{-- Razorpay Billing Agreement Authorization View --}}
{{-- Opens Razorpay Checkout in subscription mode for payment method capture --}}

@assets
<script src="https://checkout.razorpay.com/v1/checkout.js"></script>
@endassets

@script
<script>
    const options = {
        key: "{{ $keyId }}",
        subscription_id: "{{ $subscriptionId }}",
        name: "{{ config('app.name', 'Paymenter') }}",
        description: "Save payment method for recurring billing",
        handler: function(response) {
            // Build the redirect URL with query parameters
            const params = new URLSearchParams({
                razorpay_subscription_id: response.razorpay_subscription_id,
                razorpay_payment_id: response.razorpay_payment_id,
                razorpay_signature: response.razorpay_signature,
            });
            window.location.href = "{{ route('extensions.gateways.razorpay.setup-agreement') }}?" + params.toString();
        },
        prefill: {
            name: "{{ $customerName }}",
            email: "{{ $customerEmail }}",
        },
        theme: {
            color: "#528FF0",
        },
        modal: {
            ondismiss: function() {
                window.location.href = "{{ route('account.payment-methods') }}";
            },
            confirm_close: true,
        },
    };

    const razorpay = new Razorpay(options);
    razorpay.open();
</script>
@endscript
