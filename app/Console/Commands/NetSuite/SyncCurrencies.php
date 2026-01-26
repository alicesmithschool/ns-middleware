<?php

namespace App\Console\Commands\NetSuite;

use App\Models\NetSuiteCurrency;
use App\Services\NetSuiteRestService;
use Illuminate\Console\Command;

class SyncCurrencies extends Command
{
    protected $signature = 'netsuite:sync-currencies';
    protected $description = 'Sync currencies from NetSuite REST API and store in database';

    public function handle(NetSuiteRestService $restService)
    {
        $this->info('Starting currency sync from NetSuite REST API...');
        $isSandbox = config('netsuite.environment') === 'sandbox';
        
        try {
            // Fetch currencies from REST API
            $currencies = $restService->fetchCurrencies();
            
            if (empty($currencies)) {
                $this->warn('No currencies found in NetSuite.');
                return Command::SUCCESS;
            }

            $this->info("Found " . count($currencies) . " currencies");

            $created = 0;
            $updated = 0;
            $codesUpdated = 0;

            $this->withProgressBar($currencies, function ($currency, $key) use (&$created, &$updated, &$codesUpdated, $isSandbox) {
                // Extract currency data from REST API response
                // Structure: id, refName, symbol, name, exchangeRate
                $currencyId = (string) ($currency['id'] ?? $key);
                $currencyName = $currency['refName'] ?? $currency['name'] ?? 'Unknown';
                $currencyCode = $currency['symbol'] ?? null; // This is the ISO code!
                $exchangeRate = isset($currency['exchangeRate']) ? (float) $currency['exchangeRate'] : null;
                
                $data = [
                    'netsuite_id' => $currencyId,
                    'name' => $currencyName,
                    'symbol' => $currencyCode, // Store symbol
                    'currency_code' => $currencyCode, // Store ISO code (from symbol field)
                    'exchange_rate' => $exchangeRate,
                    'is_base_currency' => false, // Can be determined if exchangeRate is 1
                    'is_inactive' => false,
                    'is_sandbox' => $isSandbox,
                ];
                
                // Check if base currency (exchange rate = 1)
                if ($exchangeRate == 1) {
                    $data['is_base_currency'] = true;
                }
                
                $existing = NetSuiteCurrency::where('netsuite_id', $currencyId)
                    ->where('is_sandbox', $isSandbox)
                    ->first();
                
                // If currency exists but doesn't have a code, update it
                if ($existing && !$existing->currency_code && $currencyCode) {
                    $existing->currency_code = $currencyCode;
                    $existing->save();
                    $codesUpdated++;
                }
                
                $currencyModel = NetSuiteCurrency::updateOrCreate(
                    ['netsuite_id' => $currencyId, 'is_sandbox' => $isSandbox],
                    $data
                );

                if ($currencyModel->wasRecentlyCreated) {
                    $created++;
                } else {
                    // Always update currency_code if we have a better one
                    if ($currencyCode && (!$currencyModel->currency_code || $currencyModel->currency_code !== $currencyCode)) {
                        $currencyModel->currency_code = $currencyCode;
                        $currencyModel->save();
                        if (!$existing || $existing->currency_code) {
                            $codesUpdated++;
                        }
                    }
                    $updated++;
                }
            });

            $this->newLine(2);
            $this->info("Sync completed!");
            $this->info("Created: {$created}");
            $this->info("Updated: {$updated}");
            if ($codesUpdated > 0) {
                $this->info("Currency codes updated: {$codesUpdated}");
            }

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error('Error syncing currencies: ' . $e->getMessage());
            $this->warn('Make sure you have set NETSUITE_REST_CONSUMER_KEY, NETSUITE_REST_CERTIFICATE_KID, and NETSUITE_REST_CERTIFICATE_PRIVATE_KEY in .env');
            return Command::FAILURE;
        }
    }
}
