<?php

return [
    'enabled' => (bool) env('VNPAY_ENABLED', false),
    'tmn_code' => env('VNPAY_TMN_CODE', ''),
    'hash_secret' => env('VNPAY_HASH_SECRET', ''),
    'payment_url' => env('VNPAY_PAYMENT_URL', 'https://sandbox.vnpayment.vn/paymentv2/vpcpay.html'),
    'query_url' => env('VNPAY_QUERY_URL', 'https://sandbox.vnpayment.vn/merchant_webapi/api/transaction'),
    'return_url' => env('VNPAY_RETURN_URL', 'http://127.0.0.1:8000/api/v1/payments/vnpay/return'),
    'frontend_url' => env('FRONTEND_URL', 'http://127.0.0.1:5173'),
    'query_ip' => env('VNPAY_QUERY_IP', '127.0.0.1'),
    'timeout_minutes' => (int) env('VNPAY_PAYMENT_TIMEOUT_MINUTES', 15),
    'query_cooldown_seconds' => max(300, (int) env('VNPAY_QUERY_COOLDOWN_SECONDS', 300)),
];
