<?php

namespace App\Services;

/**
 * Public gateway name: aamarpay.
 * Actual processor: EPS (see EpsPaymentService).
 */
class AamarPayService
{
    static function gateway( $price, $traxId, $type, $successUrl, $tenant_type )
    {
        return EpsPaymentService::gateway( (float) $price, (string) $traxId, (string) $type, $successUrl, (string) $tenant_type );
    }
}
