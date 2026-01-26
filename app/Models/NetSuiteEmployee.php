<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NetSuiteEmployee extends Model
{
    protected $table = 'netsuite_employees';

    protected $fillable = [
        'netsuite_id',
        'name',
        'entity_id',
        'email',
        'phone',
        'employee_type',
        'is_inactive',
        'is_sandbox',
    ];

    protected $casts = [
        'is_inactive' => 'boolean',
        'is_sandbox' => 'boolean',
    ];
}


