<?php

namespace App\Services;

/**
 * Legacy gateway entrypoint kept for existing callers.
 * Uses EPS payment gateway under the hood.
 */
class AamarPayService
{
    static function gateway( $price, $traxId, $type, $successUrl, $tenant_type )
    {
        return EpsPaymentService::gateway( (float) $price, (string) $traxId, (string) $type, $successUrl, (string) $tenant_type );
    }
}
