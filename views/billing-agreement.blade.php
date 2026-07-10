{{-- Razorpay Billing Agreement Authorization View --}}
{{-- Opens Razorpay Checkout in subscription mode for payment method capture --}}
{{-- All URLs are passed from PHP to avoid Blade route() resolution issues --}}

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
            const params = new URLSearchParams({
                razorpay_subscription_id: response.razorpay_subscription_id,
                razorpay_payment_id: response.razorpay_payment_id,
                razorpay_signature: response.razorpay_signature,
            });
            window.location.href = "{{ $setupAgreementUrl }}" + "?" + params.toString();
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
                window.location.href = "{{ $cancelUrl }}";
            },
            confirm_close: true,
        },
    };

    const razorpay = new Razorpay(options);
    razorpay.open();
</script>
@endscript
