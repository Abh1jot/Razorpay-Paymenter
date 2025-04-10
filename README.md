# Razorpay Payment Gateway for Paymenter

![](https://upload.wikimedia.org/wikipedia/commons/7/77/Razorpay_logo.png)

A seamless Razorpay integration for Paymenter, enabling secure and efficient payment processing for your customers.

[![License](https://img.shields.io/badge/License-MIT-blue.svg)](https://github.com/Sarthak-07/razorpay-payment-gateway/blob/main/LICENSE)
[![Compatibility Paymenter v1.x](https://img.shields.io/badge/Paymenter-v1.x-4B32C3.svg)](https://paymenter.org)

## Prerequisites
- Paymenter v1.x
- Razorpay Merchant Account 

## Setup

1. **Install Razorpay Gateway Extension** (Replace `/var/www/paymenter` with your paymenter root if it is different)
   - **Automatic Installation:** `git clone https://github.com/Sarthak-07/razorpay-payment-gateway.git /var/www/paymenter/extensions/Gateways/Razorpay`
   - **Manual Installation:** [Download](https://github.com/Sarthak-07/razorpay-payment-gateway/releases/latest/download/Razorpay.zip) the extension and extract it in `/var/www/paymenter/extensions/Gateways`
2. **Enable Razorpay Gateway Extension**  
   Navigate to `Admin → Gateways → New Gateway → Razorpay` in Paymenter and follow the Configuration steps.

## Configuration

1. **Key ID:** Get Razorpay Key ID from [here](https://razorpay.com/docs/payments/dashboard/account-settings/api-keys/#live-mode-api-keys).

2. **Key Secret:** Get Razorpay Key Secret from [here](https://razorpay.com/docs/payments/dashboard/account-settings/api-keys/#live-mode-api-keys).

3. **Webhook Secret:** Generate Razorpay Webhook Secret from [here](https://razorpay.com/docs/payments/dashboard/account-settings/webhooks/#set-up-webhooks), Add a Webhook with event **`order.paid`** in Razorpay Dashboard. Ensure the Webhook URL format is **`https://<your_paymenter_url>/extensions/gateways/razorpay/webhook`**.

4. **Test Mode (optional):** Enable this if you want to test Razorpay Gateway Extension.

5. **Test Key ID (optional):** Get Razorpay Test Key ID from [here](https://razorpay.com/docs/payments/dashboard/account-settings/api-keys/#test-mode-api-keys).

6. **Test Key Secret (optional):** Get Razorpay Test Key Secret from [here](https://razorpay.com/docs/payments/dashboard/account-settings/api-keys/#test-mode-api-keys).

Congratulations! Your Razorpay Payment Gateway Configuration is now complete!

## Support

For assistance, please reach out to me on Discord: [@sarthak77](https://discord.com/users/877064899065446461).
