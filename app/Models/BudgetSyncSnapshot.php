<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BudgetSyncSnapshot extends Model
{
    protected $fillable = [
        'department_code',
        'kissflow_item_id',
        'financial_year',
        'period_number',
        'period_name',
        'previous_value',
        'synced_amount',
        'new_value',
        'transaction_count',
        'transaction_ids',
        'synced_at',
    ];

    protected $casts = [
        'financial_year' => 'integer',
        'period_number' => 'integer',
        'previous_value' => 'decimal:2',
        'synced_amount' => 'decimal:2',
        'new_value' => 'decimal:2',
        'transaction_count' => 'integer',
        'transaction_ids' => 'array',
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
     * Get the latest snapshot for a department in a given year
     */
    public static function getLatestForDepartment(string $departmentCode, int $year)
    {
        return static::forYear($year)
            ->forDepartment($departmentCode)
            ->orderBy('synced_at', 'desc')
            ->first();
    }

    /**
     * Get total synced amount for a department in a given year
     */
    public static function getTotalSyncedForDepartment(string $departmentCode, int $year): float
    {
        return (float) static::forYear($year)
            ->forDepartment($departmentCode)
            ->sum('synced_amount');
    }

    /**
     * Create a new snapshot record
     */
    public static function createSnapshot(
        string $departmentCode,
        string $kissflowItemId,
        int $financialYear,
        int $periodNumber,
        ?string $periodName,
        ?float $previousValue,
        float $syncedAmount,
        float $newValue,
        array $transactionIds = []
    ): self {
        return static::create([
            'department_code' => $departmentCode,
            'kissflow_item_id' => $kissflowItemId,
            'financial_year' => $financialYear,
            'period_number' => $periodNumber,
            'period_name' => $periodName,
            'previous_value' => $previousValue,
            'synced_amount' => $syncedAmount,
            'new_value' => $newValue,
            'transaction_count' => count($transactionIds),
            'transaction_ids' => $transactionIds,
            'synced_at' => now(),
        ]);
    }
}
