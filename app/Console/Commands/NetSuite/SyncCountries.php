<?php

namespace App\Console\Commands\NetSuite;

use App\Models\NetSuiteCountry;
use App\Services\NetSuiteRestService;
use Illuminate\Console\Command;

class SyncCountries extends Command
{
    protected $signature = 'netsuite:sync-countries';
    protected $description = 'Sync countries from NetSuite REST API and store in database';

    public function handle(NetSuiteRestService $restService)
    {
        $this->info('Starting country sync from NetSuite REST API...');
        $environment = config('netsuite.environment', 'sandbox');
        $isSandbox = $environment === 'sandbox';

        $this->info("Environment: {$environment}");
        $this->newLine();

        try {
            // Fetch countries from REST API (tries SuiteQL first, falls back to hardcoded list)
            $countries = $restService->fetchCountries();

            if (empty($countries)) {
                $this->warn('No countries found.');
                return Command::SUCCESS;
            }

            $this->info("Found " . count($countries) . " countries");
            $this->newLine();

            $created = 0;
            $updated = 0;

            $this->withProgressBar($countries, function ($country) use (&$created, &$updated, $isSandbox) {
                // Extract country data from response
                // Structure: id, country_code, name, iso_code_2, iso_code_3
                $countryId = (string) ($country['id'] ?? '');
                $countryCode = $country['country_code'] ?? '';
                $countryName = $country['name'] ?? '';
                $isoCode2 = $country['iso_code_2'] ?? null;
                $isoCode3 = $country['iso_code_3'] ?? null;

                // Skip if missing required fields
                if (empty($countryId) || empty($countryCode) || empty($countryName)) {
                    return;
                }

                $data = [
                    'netsuite_id' => $countryId,
                    'country_code' => $countryCode,
                    'name' => $countryName,
                    'iso_code_2' => $isoCode2,
                    'iso_code_3' => $isoCode3,
                    'is_sandbox' => $isSandbox,
                ];

                $countryModel = NetSuiteCountry::updateOrCreate(
                    ['netsuite_id' => $countryId, 'is_sandbox' => $isSandbox],
                    $data
                );

                if ($countryModel->wasRecentlyCreated) {
                    $created++;
                } else {
                    $updated++;
                }
            });

            $this->newLine(2);
            $this->info("Sync completed!");
            $this->info("Created: {$created}");
            $this->info("Updated: {$updated}");
            $this->newLine();

            // Show some example mappings
            $this->comment('Example country mappings:');
            $examples = NetSuiteCountry::where('is_sandbox', $isSandbox)
                ->whereIn('iso_code_2', ['SG', 'MY', 'US', 'GB', 'AU'])
                ->get();

            foreach ($examples as $example) {
                $this->line("  {$example->iso_code_3} / {$example->iso_code_2} → {$example->country_code} ({$example->name})");
            }

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error('Error syncing countries: ' . $e->getMessage());
            $this->error($e->getTraceAsString());
            $this->newLine();
            $this->warn('Make sure you have set NETSUITE_REST_CONSUMER_KEY, NETSUITE_REST_CERTIFICATE_KID, and NETSUITE_REST_CERTIFICATE_PRIVATE_KEY in .env');
            return Command::FAILURE;
        }
    }
}
