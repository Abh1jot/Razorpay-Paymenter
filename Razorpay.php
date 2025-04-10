<?php

namespace Paymenter\Extensions\Gateways\Razorpay;

use GuzzleHttp\Client;
use Illuminate\Http\Request;
use App\Helpers\ExtensionHelper;
use App\Classes\Extension\Gateway;
use Illuminate\Support\Facades\View;

class Razorpay extends Gateway
{
    public function boot()
    {
        require __DIR__ . '/routes/web.php';
        View::addNamespace('extensions.gateways.razorpay', __DIR__ . '/views');
    }

    public function getConfig($values = [])
    {
        return [
            [
                'name' => 'key_id',
                'label' => 'Key ID',
                'description' => 'Generate your Key ID at https://razorpay.com/docs/payments/dashboard/account-settings/api-keys/#live-mode-api-keys',
                'type' => 'text',
                'required' => true,
            ],
            [
                'name' => 'key_secret',
                'label' => 'Key Secret',
                'description' => 'Generate your Key Secret at https://razorpay.com/docs/payments/dashboard/account-settings/api-keys/#live-mode-api-keys',
                'type' => 'text',
                'required' => true,
            ],
            [
                'name' => 'webhook_secret',
                'label' => 'Webhook Secret',
                'description' => 'Generate your Webhook Secret at https://razorpay.com/docs/payments/dashboard/account-settings/webhooks/#set-up-webhooks and make sure your Webhook URL format is "https://<your_paymenter_url>/extensions/gateways/razorpay/webhook"',
                'type' => 'text',
                'required' => true,
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
                'description' => 'Generate your Test Key ID at https://razorpay.com/docs/payments/dashboard/account-settings/api-keys/#test-mode-api-keys',
                'type' => 'text',
                'required' => false,
            ],
            [
                'name' => 'test_key_secret',
                'label' => 'Test Key Secret',
                'description' => 'Generate your Test Key Secret at https://razorpay.com/docs/payments/dashboard/account-settings/api-keys/#test-mode-api-keys',
                'type' => 'text',
                'required' => false,
            ],
        ];
    }

    public function pay($invoice, $total)
    {
        if ($invoice->currency_code !== "INR") {
            return view('extensions.gateways.razorpay::error', [
                'error' => 'The product currency code must be "INR" to make payments with Razorpay!',
            ]);
        }

        $keyId = $this->config('test_mode') ? $this->config('test_key_id') : $this->config('key_id');
        $keySecret = $this->config('test_mode') ? $this->config('test_key_secret') : $this->config('key_secret');
        $orderAmount = $total * 100;

        $url = "https://api.razorpay.com/v1/orders";

        $client = new Client();

        $payload = [
            "amount" => $orderAmount,
            "currency" => $invoice->currency_code,
            "notes" => [
                'invoice_id' => $invoice->id,
            ],
        ];

        try {
            $response = $client->post($url, [
                'auth' => [$keyId, $keySecret],
                'headers' => [
                    'Content-Type' => 'application/json',
                ],
                'json' => $payload,
            ]);

            $data = json_decode($response->getBody(), true);

            return view('extensions.gateways.razorpay::pay', [
                'keyId' => $keyId,
                'id' => $data['id'],
                'orderAmount' => $orderAmount,
                'invoiceId' => $invoice->id,
            ]);
        } catch (\Exception $e) {
            throw new \Exception('Failed to create order: ' . $e->getMessage());
        }
    }

    public function webhook(Request $request)
    {
        $content = $request->getContent();
        $signature = $request->header('X-Razorpay-Signature');
        $secret = $this->config('webhook_secret');

        $expected_signature = hash_hmac('sha256', $content, $secret);

        if ($signature !== $expected_signature) {
            return response('Signature verification failed', 401);
        }

        $data = json_decode($content, true);

        $orderId = $data['payload']['payment']['entity']['id'];
        $orderAmount = $data['payload']['order']['entity']['amount'] / 100;
        $invoiceId = $data['payload']['order']['entity']['notes']['invoice_id'];

        if ($data['event'] === 'order.paid') {
            ExtensionHelper::addPayment($invoiceId, 'Razorpay', $orderAmount, null, $orderId);
        }

        return response('Webhook received and processed successfully');
    }
}
