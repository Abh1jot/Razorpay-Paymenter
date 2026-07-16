<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Enable Auto-Pay - {{ config('app.name', 'Paymenter') }}</title>
    <script src="https://checkout.razorpay.com/v1/checkout.js"></script>
    <style>
        body {
            margin: 0;
            padding: 0;
            background: #0f172a;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            font-family: system-ui, -apple-system, sans-serif;
            color: #94a3b8;
        }
        .loading {
            text-align: center;
        }
        .spinner {
            width: 40px;
            height: 40px;
            border: 3px solid #1e293b;
            border-top: 3px solid #3b82f6;
            border-radius: 50%;
            animation: spin 1s linear infinite;
            margin: 0 auto 16px;
        }
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
    </style>
</head>
<body>
    <div class="loading">
        <div class="spinner"></div>
        <p>Opening payment gateway...</p>
    </div>

    <script>
        var options = {
            key: "{{ $keyId }}",
            subscription_id: "{{ $subscriptionId }}",
            name: "{{ config('app.name', 'Paymenter') }}",
            description: "Enable auto-pay for recurring billing",
            handler: function(response) {
                var params = new URLSearchParams({
                    razorpay_subscription_id: response.razorpay_subscription_id,
                    razorpay_payment_id: response.razorpay_payment_id,
                    razorpay_signature: response.razorpay_signature,
                    service_id: "{{ $serviceId }}"
                });
                window.location.href = "{{ $callbackUrl }}" + "?" + params.toString();
            },
            prefill: {
                name: "{{ $customerName }}",
                email: "{{ $customerEmail }}"
            },
            theme: {
                color: "#528FF0"
            },
            modal: {
                ondismiss: function() {
                    window.location.href = "{{ $cancelUrl }}";
                },
                confirm_close: true
            }
        };

        var razorpay = new Razorpay(options);
        razorpay.open();
    </script>
</body>
</html>
