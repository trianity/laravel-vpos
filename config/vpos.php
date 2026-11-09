<?php

/**
 * A K&H Pénzforgalmi Szolgáltató Payment Gateway magyar nyelvű dokumentációja,
 * mely a bankkártyás, OneClick, Applepay és Googlepay on-line fizetéseket támogatja.
 * URL: https://github.com/khpos/Payment-gateway_HU
 */
return [
    'env' => env('KH_ENV', 'test'), // test = sandbox, test environment, live = live environment
    'merchant_id' => env('KH_VPOSID'),
    'currency' => env('KH_CURRENCY', 'HUF'),
    'lang' => env('KH_LANG', 'HU'),

    'checkout' => [
        // Trusted host callback URL. Request input is never used as a return URL.
        'return_url' => env('KH_RETURN_URL'),

    ],

    'routes' => [
        'middleware' => ['web'],
    ],

    'test' => [
        'apiUrl' => 'https://api.sandbox.khpos.hu/api/v1.0/',
        'privateKey' => env('KH_TEST_PRIVATE_KEY'),
        'publicKey' => env('KH_TEST_PUBLIC_KEY'),
    ],
    'live' => [
        'apiUrl' => 'https://api.khpos.hu/api/v1.0/',
        'privateKey' => env('KH_PRIVATE_KEY'),
        'publicKey' => env('KH_PUBLIC_KEY'),
    ],
];
