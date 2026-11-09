<?php

declare(strict_types=1);

use Trianity\Vpos\Support\Canonicalizer;
use Trianity\Vpos\Support\PaymentInitSchema;
use Trianity\Vpos\Tests\Support\EapiFixtures;

it('uses the documented payment-init order instead of property order', function (): void {
    $payload = [
        'signature' => 'excluded',
        'language' => 'cs',
        'merchantData' => 'some-base64-encoded-merchant-data',
        'cart' => [
            ['amount' => 123400, 'quantity' => 1, 'name' => 'Wireless headphones'],
            ['description' => 'DPL', 'amount' => 0, 'quantity' => 1, 'name' => 'Shipping'],
        ],
        'returnMethod' => 'POST',
        'returnUrl' => 'https://shop.example.com/return',
        'closePayment' => true,
        'currency' => 'HUF',
        'totalAmount' => 123400,
        'payMethod' => 'card',
        'payOperation' => 'payment',
        'dttm' => EapiFixtures::REQUEST_TIMESTAMP,
        'orderNo' => '5547',
        'merchantId' => EapiFixtures::MERCHANT_ID,
    ];

    expect((new Canonicalizer)->canonicalize($payload, PaymentInitSchema::REQUEST))
        ->toBe(EapiFixtures::PAYMENT_INIT_CANONICAL);
})->group('crypto', 'offline');

it('preserves UTF-8 lists booleans and zero while omitting absent optional values', function (): void {
    $payload = [
        'merchantId' => 'Árvíztűrő',
        'enabled' => false,
        'count' => 0,
        'empty' => '',
        'missingValue' => null,
        'items' => [
            ['name' => 'Első', 'amount' => 0],
            ['name' => 'Második', 'amount' => 25],
        ],
        'signature' => 'must-not-be-signed',
    ];

    $schema = [
        'merchantId',
        'enabled',
        'count',
        'empty',
        'missingValue',
        'items' => ['name', 'amount'],
        'signature',
    ];

    expect((new Canonicalizer)->canonicalize($payload, $schema))
        ->toBe('Árvíztűrő|false|0|Első|0|Második|25');
})->group('crypto', 'offline');
