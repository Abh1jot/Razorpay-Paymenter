{{-- Razorpay Enable Auto-Pay Checkout --}}
{{-- Opens Razorpay Checkout in subscription mode for auto-pay authorization --}}
{{-- After authorization, redirects to callback to save subscription_id --}}

@assets
<script src="https://checkout.razorpay.com/v1/checkout.js"></script>
@endassets

@script
<script>
    const options = {
        key: "{{ $keyId }}",
        subscription_id: "{{ $subscriptionId }}",
        name: "{{ config('app.name', 'Paymenter') }}",
        description: "Enable auto-pay for recurring billing",
        handler: function(response) {
            const params = new URLSearchParams({
                razorpay_subscription_id: response.razorpay_subscription_id,
                razorpay_payment_id: response.razorpay_payment_id,
                razorpay_signature: response.razorpay_signature,
                service_id: "{{ $serviceId }}",
            });
            window.location.href = "{{ $callbackUrl }}" + "?" + params.toString();
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
