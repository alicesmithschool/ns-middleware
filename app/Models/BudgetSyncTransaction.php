<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BudgetSyncTransaction extends Model
{
    protected $fillable = [
        'transaction_id',
        'department_code',
        'source_sheet',
        'subcode',
        'transaction_date',
        'transaction_type',
        'external_reference',
        'description',
        'financial_year',
        'period_number',
        'period_name',
        'myr_amount',
        'currency_amount',
        'currency_code',
        'exchange_rate',
        'finance_staff',
        'invoice_id',
        'kissflow_item_id',
        'synced_at',
    ];

    protected $casts = [
        'transaction_date' => 'date',
        'financial_year' => 'integer',
        'period_number' => 'integer',
        'myr_amount' => 'decimal:2',
        'currency_amount' => 'decimal:2',
        'exchange_rate' => 'decimal:6',
        'synced_at' => 'datetime',
    ];

    /**
     * Scope to filter by financial year
     */
    public function scopeForYear($query, int $year)
    {
        return $query->where('financial_year', $year);
    }

    /**
     * Scope to filter by period/month
     */
    public function scopeForPeriod($query, int $period)
    {
        return $query->where('period_number', $period);
    }

    /**
     * Scope to filter by department code
     */
    public function scopeForDepartment($query, string $departmentCode)
    {
        return $query->where('department_code', $departmentCode);
    }

    /**
     * Scope to get only synced transactions
     */
    public function scopeSynced($query)
    {
        return $query->whereNotNull('synced_at');
    }

    /**
     * Scope to get only unsynced transactions
     */
    public function scopeUnsynced($query)
    {
        return $query->whereNull('synced_at');
    }

    /**
     * Scope to filter by source sheet
     */
    public function scopeFromSheet($query, string $sheet)
    {
        return $query->where('source_sheet', $sheet);
    }

    /**
     * Check if this transaction has been synced
     */
    public function isSynced(): bool
    {
        return $this->synced_at !== null;
    }

    /**
     * Mark this transaction as synced
     */
    public function markAsSynced(?string $kissflowItemId = null): void
    {
        $this->synced_at = now();
        if ($kissflowItemId) {
            $this->kissflow_item_id = $kissflowItemId;
        }
        $this->save();
    }

    /**
     * Check if transaction already exists
     */
    public static function exists(string $transactionId, string $sourceSheet): bool
    {
        return static::where('transaction_id', $transactionId)
            ->where('source_sheet', $sourceSheet)
            ->exists();
    }

    /**
     * Get aggregated MYR amount by department code for a given year and period
     */
    public static function getAggregatedByDepartment(int $year, int $period, bool $unsyncedOnly = true)
    {
        $query = static::forYear($year)->forPeriod($period);
        
        if ($unsyncedOnly) {
            $query->unsynced();
        }

        return $query->selectRaw('department_code, SUM(myr_amount) as total_amount, COUNT(*) as transaction_count')
            ->groupBy('department_code')
            ->get()
            ->keyBy('department_code');
    }
}
