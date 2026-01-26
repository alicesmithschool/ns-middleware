<?php

namespace App\Console\Commands\NetSuite;

use App\Models\NetSuiteVendor;
use App\Services\NetSuiteService;
use App\Services\NetSuiteRestService;
use Illuminate\Console\Command;

class SyncVendors extends Command
{
    protected $signature = 'netsuite:sync-vendors {--use-rest : Use REST API instead of SOAP API}';
    protected $description = 'Sync vendors from NetSuite to local database';

    public function handle(NetSuiteService $netSuiteService, NetSuiteRestService $netSuiteRestService)
    {
        $useRest = $this->option('use-rest');
        
        if ($useRest) {
            return $this->syncVendorsRest($netSuiteRestService);
        } else {
            return $this->syncVendorsSoap($netSuiteService);
        }
    }

    /**
     * Sync vendors using SOAP API (original implementation)
     */
    protected function syncVendorsSoap(NetSuiteService $netSuiteService)
    {
        $this->info('Starting vendor sync using SOAP API...');

        try {
            $vendors = $netSuiteService->searchVendors();
            
            if (empty($vendors)) {
                $this->warn('No vendors found in NetSuite.');
                return Command::SUCCESS;
            }

            $vendors = is_array($vendors) ? $vendors : [$vendors];
            $count = 0;
            $updated = 0;
            $created = 0;
            $isSandbox = config('netsuite.environment') === 'sandbox';

            $this->withProgressBar($vendors, function ($vendor) use (&$count, &$created, &$updated, $netSuiteService, $isSandbox) {
                $count++;
                
                try {
                    // Start with basic vendor data from search
                    $data = [
                        'netsuite_id' => (string) $vendor->internalId,
                        'name' => $vendor->companyName ?? $vendor->entityId ?? 'Unknown',
                        'entity_id' => $vendor->entityId ?? null,
                        'email' => null,
                        'phone' => null,
                        'default_currency_id' => null,
                        'supported_currencies' => [],
                        'is_inactive' => $vendor->isInactive ?? false,
                        'is_sandbox' => $isSandbox,
                    ];
                    
                    // Try to get full vendor details including currency info (with retry)
                    $maxRetries = 3;
                    $retryCount = 0;
                    $fullVendor = null;
                    
                    while ($retryCount < $maxRetries) {
                        try {
                            $fullVendor = $netSuiteService->getVendor($vendor->internalId);
                            break; // Success, exit retry loop
                        } catch (\Exception $e) {
                            $retryCount++;
                            if ($retryCount < $maxRetries) {
                                // Wait before retry (exponential backoff)
                                usleep(500000 * $retryCount); // 0.5s, 1s, 1.5s
                            } else {
                                // Final retry failed, log but continue with basic data
                                $this->newLine();
                                $this->warn("Could not fetch full details for vendor {$vendor->internalId} after {$maxRetries} attempts. Using basic data.");
                            }
                        }
                    }
                    
                    // If we got full vendor details, update data
                    if ($fullVendor) {
                        $data['email'] = $fullVendor->email ?? null;
                        $data['phone'] = $fullVendor->phone ?? null;
                        $data['default_currency_id'] = isset($fullVendor->currency) ? (string) $fullVendor->currency->internalId : null;
                        
                        $supportedCurrencies = [];
                        if (isset($fullVendor->currencyList) && isset($fullVendor->currencyList->vendorCurrency)) {
                            $vendorCurrencies = is_array($fullVendor->currencyList->vendorCurrency) 
                                ? $fullVendor->currencyList->vendorCurrency 
                                : [$fullVendor->currencyList->vendorCurrency];
                            
                            foreach ($vendorCurrencies as $vc) {
                                $supportedCurrencies[] = [
                                    'id' => (string) $vc->currency->internalId,
                                    'name' => $vc->currency->name,
                                ];
                            }
                        }
                        $data['supported_currencies'] = $supportedCurrencies;
                    }
                    
                    // Add small delay between requests to avoid rate limiting
                    if ($count % 10 == 0) {
                        usleep(1000000); // 1 second delay every 10 vendors
                    } else {
                        usleep(200000); // 0.2 second delay between each vendor
                    }

                    $vendorModel = NetSuiteVendor::updateOrCreate(
                        ['netsuite_id' => $data['netsuite_id'], 'is_sandbox' => $isSandbox],
                        $data
                    );

                    if ($vendorModel->wasRecentlyCreated) {
                        $created++;
                    } else {
                        $updated++;
                    }
                } catch (\Exception $e) {
                    $this->newLine();
                    $this->warn("Error processing vendor {$vendor->internalId}: " . $e->getMessage());
                }
            });

            $this->newLine(2);
            $this->info("Sync completed!");
            $this->info("Total processed: {$count}");
            $this->info("Created: {$created}");
            $this->info("Updated: {$updated}");

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error('Error syncing vendors: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }

    /**
     * Sync vendors using REST API (faster, uses SuiteQL)
     */
    protected function syncVendorsRest(NetSuiteRestService $netSuiteRestService)
    {
        $this->info('Starting vendor sync using REST API...');

        try {
            // Use SuiteQL to get all vendors at once (much faster!)
            $this->info('Fetching vendors using SuiteQL...');
            $vendors = $netSuiteRestService->searchVendors();
            
            if (empty($vendors)) {
                $this->warn('No vendors found in NetSuite.');
                return Command::SUCCESS;
            }

            $count = 0;
            $updated = 0;
            $created = 0;
            $isSandbox = config('netsuite.environment') === 'sandbox';

            $this->info("Found " . count($vendors) . " vendors. Syncing to database...");
            
            $this->withProgressBar($vendors, function ($vendor) use (&$count, &$created, &$updated, $netSuiteRestService, $isSandbox) {
                $count++;
                
                try {
                    // Map REST API data to database format
                    $data = [
                        'netsuite_id' => (string) ($vendor['id'] ?? ''),
                        'name' => $vendor['companyname'] ?? $vendor['entityid'] ?? 'Unknown',
                        'entity_id' => $vendor['entityid'] ?? null,
                        'email' => $vendor['email'] ?? null,
                        'phone' => $vendor['phone'] ?? null,
                        'default_currency_id' => isset($vendor['currency']) ? (string) $vendor['currency'] : null,
                        'supported_currencies' => [], // SuiteQL doesn't return currency list, would need individual GET
                        'is_inactive' => isset($vendor['isinactive']) ? (bool) $vendor['isinactive'] : false,
                        'is_sandbox' => $isSandbox,
                    ];

                    $vendorModel = NetSuiteVendor::updateOrCreate(
                        ['netsuite_id' => $data['netsuite_id'], 'is_sandbox' => $isSandbox],
                        $data
                    );

                    if ($vendorModel->wasRecentlyCreated) {
                        $created++;
                    } else {
                        $updated++;
                    }
                } catch (\Exception $e) {
                    $this->newLine();
                    $this->warn("Error processing vendor " . ($vendor['id'] ?? 'unknown') . ": " . $e->getMessage());
                }
            });

            $this->newLine(2);
            $this->info("Sync completed!");
            $this->info("Total processed: {$count}");
            $this->info("Created: {$created}");
            $this->info("Updated: {$updated}");
            $this->comment("Note: REST API sync uses SuiteQL which is faster but doesn't include supported currencies. Use SOAP API if you need that data.");

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error('Error syncing vendors: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }
}
