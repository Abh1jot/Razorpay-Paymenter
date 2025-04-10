@assets
<script src="https://checkout.razorpay.com/v1/checkout.js"></script>
@endassets

@script
<script>
    const checkout = {
        key: "{{ $keyId }}",
        amount: "{{ $orderAmount }}",
        currency: "INR",
        name: "{{ config('app.name', 'Paymenter') }}",
        order_id: "{{ $id }}",
        callback_url: "{{ route('extensions.gateways.razorpay.callback', ['invoiceId' => $invoiceId]) }}",
        modal: {
            ondismiss: function() {
                window.location.href = "{{ route('extensions.gateways.razorpay.cancel', ['invoiceId' => $invoiceId]) }}";
            }
        }
    };

    const razorpay = new Razorpay(checkout);
    razorpay.open();
</script>
@endscript