<?php

declare(strict_types=1);

namespace Trianity\Vpos\Support;

final class WalletSchema
{
    public const CUSTOMER = [
        'name', 'email', 'homePhone', 'workPhone', 'mobilePhone',
        'account' => ['createdAt', 'changedAt', 'changedPwdAt', 'orderHistory', 'paymentsDay', 'paymentsYear', 'oneclickAdds', 'suspicious'],
        'login' => ['auth', 'authAt', 'authData'],
    ];

    public const ORDER = [
        'type', 'availability', 'delivery', 'deliveryMode', 'deliveryEmail', 'nameMatch', 'addressMatch',
        'billing' => ['address1', 'address2', 'address3', 'city', 'zip', 'state', 'country'],
        'shipping' => ['address1', 'address2', 'address3', 'city', 'zip', 'state', 'country'],
        'shippingAddedAt', 'reorder',
    ];

    public const BROWSER_FINGERPRINT = [
        'userAgent', 'acceptHeader', 'language', 'javascriptEnabled', 'colorDepth',
        'screenHeight', 'screenWidth', 'timezone', 'javaEnabled', 'challengeWindowSize',
    ];

    public const SDK_FINGERPRINT = [
        'appID', 'encData', 'ephemPubKey', 'maxTimeout', 'referenceNumber', 'transID',
    ];

    public const FINGERPRINT = [
        'browser' => self::BROWSER_FINGERPRINT,
        'sdk' => self::SDK_FINGERPRINT,
    ];

    public const ENDPOINT = ['url', 'method', 'vars' => ['*']];

    public const ACTIONS = [
        'fingerprint' => [
            'browserInit' => self::ENDPOINT,
            'sdkInit' => ['directoryServerID', 'schemeId', 'messageVersion'],
        ],
        'authenticate' => [
            'browserChallenge' => self::ENDPOINT,
            'sdkChallenge' => ['threeDSServerTransID', 'acsReferenceNumber', 'acsTransID', 'acsSignedContent'],
        ],
    ];

    public const ONECLICK_ECHO_REQUEST = ['merchantId', 'origPayId', 'dttm'];

    public const ONECLICK_ECHO_RESPONSE = ['origPayId', 'dttm', 'resultCode', 'resultMessage'];

    public const ONECLICK_INIT_REQUEST = [
        'merchantId', 'origPayId', 'orderNo', 'dttm', 'clientIp', 'totalAmount', 'currency',
        'closePayment', 'returnUrl', 'returnMethod', 'customer' => self::CUSTOMER,
        'order' => self::ORDER, 'clientInitiated', 'sdkUsed', 'merchantData', 'language', 'ttlSec',
    ];

    public const WALLET_INIT_REQUEST = [
        'merchantId', 'orderNo', 'dttm', 'clientIp', 'totalAmount', 'currency', 'closePayment',
        'payload', 'returnUrl', 'returnMethod', 'customer' => self::CUSTOMER, 'order' => self::ORDER,
        'sdkUsed', 'merchantData', 'language', 'ttlSec',
    ];

    public const PROCESS_REQUEST = [
        'merchantId', 'payId', 'dttm', 'fingerprint' => self::FINGERPRINT,
    ];

    public const OPERATION_RESPONSE = [
        'payId', 'dttm', 'resultCode', 'resultMessage', 'paymentStatus', 'statusDetail',
        'actions' => self::ACTIONS,
    ];

    public const APPLE_INIT_PARAMS = [
        'countryCode', 'supportedNetworks' => ['*'], 'merchantCapabilities' => ['*'],
    ];

    public const APPLE_ECHO_RESPONSE = [
        'dttm', 'resultCode', 'resultMessage', 'initParams' => self::APPLE_INIT_PARAMS,
    ];

    public const GOOGLE_INIT_PARAMS = [
        'apiVersion', 'apiVersionMinor', 'paymentMethodType', 'allowedCardNetworks' => ['*'],
        'allowedCardAuthMethods' => ['*'], 'assuranceDetailsRequired', 'billingAddressRequired',
        'billingAddressParametersFormat', 'tokenizationSpecificationType', 'gateway',
        'gatewayMerchantId', 'googlepayMerchantId', 'merchantName', 'environment',
        'totalPriceStatus', 'countryCode',
    ];

    public const GOOGLE_ECHO_RESPONSE = [
        'dttm', 'resultCode', 'resultMessage',
        'initParams' => self::GOOGLE_INIT_PARAMS,
    ];
}
