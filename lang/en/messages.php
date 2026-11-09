<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| K&H VPos Gateway Message Language Lines - EN
|--------------------------------------------------------------------------
|
*/

return [
    'res_0' => 'OK (the operation was processed correctly, a transaction created, status updated, etc.)',
    'res_100' => 'Missing parameter {name} (missing mandatory parameter)',
    'res_110' => 'Invalid parameter {name} (invalid parameter format)',
    'res_120' => 'Merchant blocked (the merchant is not allowed payments)',
    'res_130' => 'Session expired (the request has expired)',
    'res_140' => 'Payment not found (payment not found)',
    'res_150' => 'Payment not in valid state (incorrect payment status, the operation cannot be performed)',
    'res_160' => 'Payment method disabled (the operation is not allowed, the merchant must contractually request the settings)',
    'res_170' => 'Payment method unavailable (unavailability of the method provider, the service is not available at this time)',
    'res_180' => 'Operation not allowed (unauthorised operation)',
    'res_190' => 'Payment method error (processing error at method provider)',
    'res_700' => 'Oneclick template not found (OneClick template has not been found)',
    'res_710' => 'Oneclick template payment expired (OneClick the template has not been used for more than 12 months, the payment has expired)',
    'res_720' => 'Oneclick template card expired (the card for the OneClick template has expired)',
    'res_730' => 'Oneclick template customer rejected (the OneClick template was cancelled at the customer\'s request)',
    'res_740' => 'Oneclick template payment reversed (OneClick template has been reversed)',
    'res_740' => 'Cardholder account closed (OneClick template was cancelled due to the closed cardholder account)',
    'res_900' => 'Internal error (internal request processing error)',
];
