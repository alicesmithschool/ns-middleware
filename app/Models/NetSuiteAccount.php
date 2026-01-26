<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NetSuiteAccount extends Model
{
    protected $table = 'netsuite_accounts';

    protected $fillable = [
        'netsuite_id',
        'name',
        'account_type',
        'account_number',
        'is_inactive',
        'is_sandbox',
    ];

    protected $casts = [
        'is_inactive' => 'boolean',
        'is_sandbox' => 'boolean',
    ];
}
