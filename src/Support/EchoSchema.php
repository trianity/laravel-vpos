<?php

declare(strict_types=1);

namespace Trianity\Vpos\Support;

final class EchoSchema
{
    public const REQUEST = ['merchantId', 'dttm'];

    public const RESPONSE = ['dttm', 'resultCode', 'resultMessage'];
}
