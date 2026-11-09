<?php

declare(strict_types=1);

namespace Trianity\Vpos\Support;

final class PaymentInitSchema
{
    public const REQUEST = [
        'merchantId',
        'orderNo',
        'dttm',
        'payOperation',
        'payMethod',
        'totalAmount',
        'currency',
        'closePayment',
        'returnUrl',
        'returnMethod',
        'cart' => ['name', 'quantity', 'amount', 'description'],
        'customer' => [
            'name',
            'email',
            'homePhone',
            'workPhone',
            'mobilePhone',
            'account' => [
                'createdAt',
                'changedAt',
                'changedPwdAt',
                'orderHistory',
                'paymentsDay',
                'paymentsYear',
                'oneclickAdds',
                'suspicious',
            ],
            'login' => ['auth', 'authAt', 'authData'],
        ],
        'order' => [
            'type',
            'availability',
            'delivery',
            'deliveryMode',
            'deliveryEmail',
            'nameMatch',
            'addressMatch',
            'billing' => ['address1', 'address2', 'address3', 'city', 'zip', 'state', 'country'],
            'shipping' => ['address1', 'address2', 'address3', 'city', 'zip', 'state', 'country'],
            'shippingAddedAt',
            'reorder',
        ],
        'merchantData',
        'language',
        'ttlSec',
        'customExpiry',
    ];
}
