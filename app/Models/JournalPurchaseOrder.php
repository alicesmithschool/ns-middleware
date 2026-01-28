<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class JournalPurchaseOrder extends Model
{
    protected $fillable = [
        'po_id',
        'tran_id',
        'transaction_date',
        'department_code',
        'subcode',
        'amount',
        'currency_code',
        'memo',
        'full_data',
        'processed_at',
        'netsuite_last_modified',
    ];

    protected $casts = [
        'full_data' => 'array',
        'transaction_date' => 'date',
        'processed_at' => 'datetime',
        'netsuite_last_modified' => 'datetime',
        'amount' => 'decimal:2',
    ];
}
