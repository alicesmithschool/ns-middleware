<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NetSuiteDepartment extends Model
{
    protected $table = 'netsuite_departments';

    protected $fillable = [
        'netsuite_id',
        'name',
        'is_inactive',
        'is_sandbox',
    ];

    protected $casts = [
        'is_inactive' => 'boolean',
        'is_sandbox' => 'boolean',
    ];
}
