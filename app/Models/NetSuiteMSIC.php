<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NetSuiteMSIC extends Model
{
    protected $table = 'netsuite_msic_codes';

    protected $fillable = [
        'netsuite_id',
        'msic_code',
        'description',
        'ref_name',
        'is_sandbox',
    ];

    protected $casts = [
        'is_sandbox' => 'boolean',
    ];

    /**
     * Find MSIC by code or description
     *
     * @param string $identifier MSIC code or description
     * @param bool $isSandbox Whether to search in sandbox or production
     * @return NetSuiteMSIC|null
     */
    public static function findByIdentifier(string $identifier, bool $isSandbox = true): ?self
    {
        $identifier = trim($identifier);

        // Try exact MSIC code match (with or without leading zeros)
        $msic = self::where('is_sandbox', $isSandbox)
            ->where('msic_code', $identifier)
            ->first();

        if ($msic) {
            return $msic;
        }

        // Try matching the refName format (e.g., "00000 : NOT APPLICABLE")
        $msic = self::where('is_sandbox', $isSandbox)
            ->where('ref_name', $identifier)
            ->first();

        if ($msic) {
            return $msic;
        }

        // Try case-insensitive refName match
        $msic = self::where('is_sandbox', $isSandbox)
            ->whereRaw('UPPER(ref_name) = ?', [strtoupper($identifier)])
            ->first();

        if ($msic) {
            return $msic;
        }

        // Try partial match on description
        return self::where('is_sandbox', $isSandbox)
            ->where(function ($query) use ($identifier) {
                $query->whereRaw('UPPER(description) LIKE ?', ['%' . strtoupper($identifier) . '%'])
                    ->orWhereRaw('UPPER(ref_name) LIKE ?', ['%' . strtoupper($identifier) . '%']);
            })
            ->first();
    }

    /**
     * Get default "NOT APPLICABLE" MSIC
     *
     * @param bool $isSandbox
     * @return NetSuiteMSIC|null
     */
    public static function getDefaultNotApplicable(bool $isSandbox = true): ?self
    {
        return self::where('is_sandbox', $isSandbox)
            ->where('msic_code', '00000')
            ->first();
    }
}
