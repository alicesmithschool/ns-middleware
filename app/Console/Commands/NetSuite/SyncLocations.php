<?php

namespace App\Console\Commands\NetSuite;

use App\Models\NetSuiteLocation;
use App\Services\NetSuiteService;
use Illuminate\Console\Command;

class SyncLocations extends Command
{
    protected $signature = 'netsuite:sync-locations';
    protected $description = 'Sync locations from NetSuite to local database';

    public function handle(NetSuiteService $netSuiteService)
    {
        $this->info('Starting location sync...');

        try {
            $locations = $netSuiteService->searchLocations();
            
            if (empty($locations)) {
                $this->warn('No locations found in NetSuite.');
                return Command::SUCCESS;
            }

            $locations = is_array($locations) ? $locations : [$locations];
            $count = 0;
            $updated = 0;
            $created = 0;
            $isSandbox = config('netsuite.environment') === 'sandbox';

            foreach ($locations as $location) {
                $count++;
                
                $data = [
                    'netsuite_id' => (string) $location->internalId,
                    'name' => $location->name ?? 'Unknown',
                    'location_type' => $location->locationType ?? null,
                    'is_inactive' => $location->isInactive ?? false,
                    'is_sandbox' => $isSandbox,
                ];

                $locationModel = NetSuiteLocation::updateOrCreate(
                    ['netsuite_id' => $data['netsuite_id'], 'is_sandbox' => $isSandbox],
                    $data
                );

                if ($locationModel->wasRecentlyCreated) {
                    $created++;
                } else {
                    $updated++;
                }
            }

            $this->info("Sync completed!");
            $this->info("Total processed: {$count}");
            $this->info("Created: {$created}");
            $this->info("Updated: {$updated}");

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error('Error syncing locations: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }
}

