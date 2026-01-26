<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NetSuiteVendor extends Model
{
    protected $table = 'netsuite_vendors';

    protected $fillable = [
        'netsuite_id',
        'name',
        'entity_id',
        'email',
        'phone',
        'default_currency_id',
        'supported_currencies',
        'is_inactive',
        'is_sandbox',
    ];

    protected $casts = [
        'supported_currencies' => 'array',
        'is_inactive' => 'boolean',
        'is_sandbox' => 'boolean',
    ];
}
