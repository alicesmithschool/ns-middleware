<?php

namespace App\Console\Commands\NetSuite;

use App\Models\NetSuiteDepartment;
use App\Services\NetSuiteService;
use Illuminate\Console\Command;

class SyncDepartments extends Command
{
    protected $signature = 'netsuite:sync-departments';
    protected $description = 'Sync departments from NetSuite to local database';

    public function handle(NetSuiteService $netSuiteService)
    {
        $this->info('Starting department sync...');

        try {
            $departments = $netSuiteService->searchDepartments();
            
            if (empty($departments)) {
                $this->warn('No departments found in NetSuite.');
                return Command::SUCCESS;
            }

            $departments = is_array($departments) ? $departments : [$departments];
            $count = 0;
            $updated = 0;
            $created = 0;

            $isSandbox = config('netsuite.environment') === 'sandbox';
            
            foreach ($departments as $dept) {
                $count++;
                
                $data = [
                    'netsuite_id' => (string) $dept->internalId,
                    'name' => $dept->name ?? 'Unknown',
                    'is_inactive' => $dept->isInactive ?? false,
                    'is_sandbox' => $isSandbox,
                ];

                $department = NetSuiteDepartment::updateOrCreate(
                    ['netsuite_id' => $data['netsuite_id'], 'is_sandbox' => $isSandbox],
                    $data
                );

                if ($department->wasRecentlyCreated) {
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
            $this->error('Error syncing departments: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }
}

