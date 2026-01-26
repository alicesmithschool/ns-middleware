<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NetSuiteLocation extends Model
{
    protected $table = 'netsuite_locations';

    protected $fillable = [
        'netsuite_id',
        'name',
        'location_type',
        'is_inactive',
        'is_sandbox',
    ];

    protected $casts = [
        'is_inactive' => 'boolean',
        'is_sandbox' => 'boolean',
    ];
}
