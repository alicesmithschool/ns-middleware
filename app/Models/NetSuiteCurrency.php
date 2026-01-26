<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NetSuiteCurrency extends Model
{
    protected $table = 'netsuite_currencies';

    protected $fillable = [
        'netsuite_id',
        'name',
        'symbol',
        'currency_code',
        'exchange_rate',
        'is_base_currency',
        'is_inactive',
        'is_sandbox',
    ];

    protected $casts = [
        'exchange_rate' => 'decimal:6',
        'is_base_currency' => 'boolean',
        'is_inactive' => 'boolean',
        'is_sandbox' => 'boolean',
    ];
}
