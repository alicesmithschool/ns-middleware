<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NetSuiteExpenseCategory extends Model
{
    protected $table = 'netsuite_expense_categories';

    protected $fillable = [
        'netsuite_id',
        'name',
        'description',
        'is_inactive',
        'is_sandbox',
    ];

    protected $casts = [
        'is_inactive' => 'boolean',
        'is_sandbox' => 'boolean',
    ];
}


