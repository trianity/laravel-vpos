<?php

declare(strict_types=1);

namespace Trianity\Vpos\Support;

final class PaymentSchema
{
    public const REFERENCE_REQUEST = ['merchantId', 'payId', 'dttm'];

    public const CLOSE_REQUEST = ['merchantId', 'payId', 'dttm', 'totalAmount'];

    public const REFUND_REQUEST = ['merchantId', 'payId', 'dttm', 'amount'];

    public const INIT_RESPONSE = [
        'payId', 'dttm', 'resultCode', 'resultMessage', 'paymentStatus', 'customerCode', 'statusDetail',
    ];

    public const PAYMENT_RESPONSE = [
        'payId', 'dttm', 'resultCode', 'resultMessage', 'paymentStatus', 'authCode', 'statusDetail',
        'actions' => WalletSchema::ACTIONS,
    ];

    public const BROWSER_RETURN = [
        'payId', 'dttm', 'resultCode', 'resultMessage', 'paymentStatus', 'authCode', 'merchantData', 'statusDetail',
    ];
}
