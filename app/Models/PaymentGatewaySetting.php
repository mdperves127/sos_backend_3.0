<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaymentGatewaySetting extends Model
{
    public const GATEWAY_EPS = 'eps';

    public const GATEWAY_UDDOKTAPAY = 'uddoktapay';

    public const GATEWAYS = [
        self::GATEWAY_EPS,
        self::GATEWAY_UDDOKTAPAY,
    ];

    protected $fillable = [
        'gateway',
        'enabled',
        'credentials',
    ];

    protected $casts = [
        'enabled'     => 'boolean',
        'credentials' => 'encrypted:array',
    ];

    /**
     * Never serialize decrypted credentials.
     *
     * @var list<string>
     */
    protected $hidden = [
        'credentials',
    ];
}
