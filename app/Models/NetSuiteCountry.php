<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NetSuiteCountry extends Model
{
    protected $table = 'netsuite_countries';

    protected $fillable = [
        'netsuite_id',
        'country_code',
        'name',
        'iso_code_2',
        'iso_code_3',
        'is_sandbox',
    ];

    protected $casts = [
        'is_sandbox' => 'boolean',
    ];

    /**
     * Find country by various identifier formats
     *
     * @param string $identifier Country identifier (ISO 2, ISO 3, name, or NetSuite code)
     * @param bool $isSandbox Whether to search in sandbox or production
     * @return NetSuiteCountry|null
     */
    public static function findByIdentifier(string $identifier, bool $isSandbox = true): ?self
    {
        $identifier = strtoupper(trim($identifier));

        // Try exact matches first
        $country = self::where('is_sandbox', $isSandbox)
            ->where(function ($query) use ($identifier) {
                $query->where('iso_code_2', $identifier)
                    ->orWhere('iso_code_3', $identifier)
                    ->orWhere('country_code', $identifier)
                    ->orWhereRaw('UPPER(name) = ?', [$identifier]);
            })
            ->first();

        if ($country) {
            return $country;
        }

        // Try partial match on name
        return self::where('is_sandbox', $isSandbox)
            ->whereRaw('UPPER(name) LIKE ?', ["%{$identifier}%"])
            ->first();
    }
}
