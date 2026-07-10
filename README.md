# Razorpay Payment Gateway for Paymenter

![](https://upload.wikimedia.org/wikipedia/commons/7/77/Razorpay_logo.png)

A full-featured Razorpay integration for Paymenter with **automatic recurring subscriptions**. When a customer pays an invoice for a recurring service, their card/UPI is automatically saved and charged on every renewal — no manual setup needed.

[![License](https://img.shields.io/badge/License-MIT-blue.svg)](https://github.com/Abh1jot/Razorpay-Paymenter/blob/main/LICENSE)
[![Compatibility Paymenter v1.x](https://img.shields.io/badge/Paymenter-v1.x-4B32C3.svg)](https://paymenter.org)

## Features

| Feature | Status |
|---------|--------|
| One-time payments (Razorpay Orders API) | ✅ |
| **Auto-subscription on invoice payment** | ✅ |
| **Auto-charge card/UPI on renewals** | ✅ |
| **Auto-create billing agreement on checkout** | ✅ |
| Saved payment methods / billing agreements | ✅ |
| Webhook signature verification | ✅ |
| Customer management (create/reuse) | ✅ |
| Plan management (deterministic, no duplicates) | ✅ |
| Auto-cancel subscription on service cancellation | ✅ |
| Test mode | ✅ |
| Multi-currency | ❌ INR only (Razorpay limitation) |

## Prerequisites

- Paymenter v1.x
- Razorpay Merchant Account
- PHP 8.1+

## Installation

Replace `/var/www/paymenter` with your Paymenter root directory.

### Automatic Installation

```bash
git clone https://github.com/Abh1jot/Razorpay-Paymenter.git /var/www/paymenter/extensions/Gateways/Razorpay
```

### Manual Installation

[Download](https://github.com/Abh1jot/Razorpay-Paymenter/releases/latest/download/Razorpay.zip) the extension and extract it in `/var/www/paymenter/extensions/Gateways/`.

## Configuration

Navigate to **Admin → Gateways → New Gateway → Razorpay** in Paymenter.

### Required Settings

| Setting | Description |
|---------|-------------|
| **Key ID** | Live mode API Key ID from [Razorpay Dashboard](https://razorpay.com/docs/payments/dashboard/account-settings/api-keys/) |
| **Key Secret** | Live mode API Key Secret |
| **Webhook Secret** | Webhook secret from Razorpay Dashboard → Webhooks |

### Optional Settings

| Setting | Description |
|---------|-------------|
| **Enable Billing Agreements** | Enables auto-subscription on checkout and saved payment methods. **Enable this for recurring billing.** |
| **Test Mode** | Use test/sandbox API keys |
| **Test Key ID** | Test mode API Key ID |
| **Test Key Secret** | Test mode API Key Secret |

## Webhook Setup

### Webhook URL

```
https://<your_paymenter_url>/extensions/gateways/razorpay/webhook
```

### Required Webhook Events

Enable these events in [Razorpay Dashboard → Webhooks](https://razorpay.com/docs/payments/dashboard/account-settings/webhooks/):

#### For one-time payments only:
- `order.paid`
- `payment.captured`
- `payment.failed`
- `payment.authorized`

#### For subscriptions (enable all of the above plus):
- `subscription.authenticated`
- `subscription.activated`
- `subscription.charged`
- `subscription.completed`
- `subscription.cancelled`
- `subscription.pending`
- `subscription.halted`
- `subscription.paused`
- `subscription.resumed`

## How It Works

### Recurring Services (auto-subscription)

When **"Enable Billing Agreements"** is turned on and a customer pays an invoice for a recurring service:

1. **Customer clicks "Pay"** on an invoice
2. A **Razorpay customer, plan, and subscription** are created automatically
3. **Razorpay Checkout opens in subscription mode** — the customer pays with card/UPI
4. After successful payment:
   - ✅ The **first payment is recorded** on the invoice
   - ✅ A **billing agreement is auto-created** (saved payment method)
   - ✅ The **subscription is linked** to the service
5. **On every renewal**, Razorpay automatically charges the saved card/UPI
6. The `subscription.charged` webhook records each auto-payment in Paymenter

**The customer doesn't need to do anything manually — their card/UPI is saved and auto-charged!**

### One-Time Payments

For non-recurring invoices (or when billing agreements are disabled):

1. Customer clicks "Pay" on an invoice
2. Razorpay Order is created via the API
3. Razorpay Checkout opens with the order
4. On payment, `order.paid` webhook records the payment in Paymenter

### Manual Billing Agreement Setup

Customers can also manually add a payment method from **Account → Payment Methods → Add Payment Method**. This creates a billing agreement that Paymenter can use for future charges.

### Auto-Cancellation

- If a service is **cancelled** in Paymenter, the Razorpay subscription is automatically cancelled
- If a **billing agreement is removed**, all linked Razorpay subscriptions are cancelled

## Limitations

- **INR only**: Razorpay Subscriptions API only supports Indian Rupees (INR)
- **Plan-based subscriptions**: Razorpay ties subscriptions to fixed-amount plans. The charge amount is set at subscription creation time.
- **No mid-cycle price changes**: Changing a service price won't update an existing Razorpay subscription. A new subscription would be created for the next invoice.

## Migration Notes (v1.x → v2.x)

- **Backward compatible**: Existing one-time payments and `order.paid` webhooks continue to work
- **New config field**: Enable "Enable Billing Agreements" checkbox for auto-subscription features
- **New webhook events**: Add subscription webhook events in Razorpay Dashboard
- **No database migration required**: Uses Paymenter's existing billing agreement and service properties tables

## File Structure

```
Razorpay/
├── Razorpay.php                      # Main gateway class
├── routes/
│   └── web.php                       # Route definitions
├── views/
│   ├── pay.blade.php                 # Checkout view (order + subscription modes)
│   ├── billing-agreement.blade.php   # Manual billing agreement authorization
│   └── error.blade.php               # Error display view
├── README.md
└── LICENSE
```

## Support

For assistance, please [open an issue](https://github.com/Abh1jot/Razorpay-Paymenter/issues) or reach out on Discord: [@sarthak77](https://discord.com/users/877064899065446461).
