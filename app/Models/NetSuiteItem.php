<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NetSuiteItem extends Model
{
    protected $table = 'netsuite_items';

    protected $fillable = [
        'netsuite_id',
        'name',
        'item_number',
        'item_type',
        'description',
        'base_price',
        'unit_of_measure',
        'is_inactive',
        'is_sandbox',
    ];

    protected $casts = [
        'base_price' => 'decimal:4',
        'is_inactive' => 'boolean',
        'is_sandbox' => 'boolean',
    ];
}
