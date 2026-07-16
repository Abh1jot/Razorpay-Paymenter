{{-- Manage Auto-Pay for a Service --}}
{{-- Shows current subscription status with enable/disable buttons --}}

<div class="bg-white dark:bg-gray-800 rounded-lg shadow-md p-6 max-w-lg mx-auto mt-8">
    <h2 class="text-xl font-semibold text-gray-900 dark:text-white mb-2">
        Auto-Pay Settings
    </h2>
    <p class="text-sm text-gray-500 dark:text-gray-400 mb-4">
        {{ $service->product->name ?? 'Service' }} #{{ $service->id }}
    </p>

    <div class="border-t border-gray-200 dark:border-gray-700 pt-4">
        @if($hasSubscription)
            {{-- Auto-pay is active --}}
            <div class="flex items-center gap-3 mb-4">
                <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium bg-green-100 text-green-800 dark:bg-green-800/30 dark:text-green-400">
                    <svg class="w-4 h-4 mr-1.5" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
                    </svg>
                    Auto-Pay Active
                </span>
            </div>

            @if($subscriptionInfo)
                <div class="text-sm text-gray-600 dark:text-gray-300 space-y-1 mb-4">
                    @if(isset($subscriptionInfo['status']))
                        <p><span class="font-medium">Status:</span> {{ ucfirst($subscriptionInfo['status']) }}</p>
                    @endif
                    @if(isset($subscriptionInfo['current_end']))
                        <p><span class="font-medium">Next charge:</span> {{ \Carbon\Carbon::createFromTimestamp($subscriptionInfo['current_end'])->format('M d, Y') }}</p>
                    @endif
                </div>
            @endif

            <p class="text-sm text-gray-500 dark:text-gray-400 mb-4">
                Your payment method will be automatically charged for future renewals.
            </p>

            <form method="POST" action="{{ url('/extensions/gateways/razorpay/disable-subscription/' . $service->id) }}">
                @csrf
                <button type="submit"
                    class="w-full px-4 py-2 text-sm font-medium text-red-600 bg-red-50 border border-red-200 rounded-lg hover:bg-red-100 dark:bg-red-900/20 dark:border-red-800 dark:text-red-400 dark:hover:bg-red-900/40 transition-colors"
                    onclick="return confirm('Are you sure you want to disable auto-pay? You will need to manually pay future invoices.')">
                    Disable Auto-Pay
                </button>
            </form>

        @else
            {{-- Auto-pay is not active --}}
            <div class="flex items-center gap-3 mb-4">
                <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-400">
                    <svg class="w-4 h-4 mr-1.5" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"/>
                    </svg>
                    Auto-Pay Not Active
                </span>
            </div>

            <p class="text-sm text-gray-500 dark:text-gray-400 mb-4">
                Enable auto-pay to have your payment method automatically charged for future renewals.
                @if($service->expires_at && $service->expires_at->isFuture())
                    Your first auto-charge will happen on <strong>{{ $service->expires_at->format('M d, Y') }}</strong>.
                @endif
            </p>

            <form method="POST" action="{{ url('/extensions/gateways/razorpay/enable-subscription/' . $service->id) }}">
                @csrf
                <button type="submit"
                    class="w-full px-4 py-2 text-sm font-medium text-white bg-blue-600 rounded-lg hover:bg-blue-700 dark:bg-blue-500 dark:hover:bg-blue-600 transition-colors">
                    Enable Auto-Pay
                </button>
            </form>
        @endif
    </div>

    <div class="mt-4 pt-4 border-t border-gray-200 dark:border-gray-700">
        <a href="{{ route('services.show', $service) }}"
            class="text-sm text-blue-600 hover:text-blue-800 dark:text-blue-400 dark:hover:text-blue-300">
            ← Back to Service
        </a>
    </div>
</div>
