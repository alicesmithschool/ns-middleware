<?php

namespace App\Console\Commands\NetSuite;

use App\Models\NetSuiteEmployee;
use App\Services\NetSuiteService;
use Illuminate\Console\Command;

class SyncEmployees extends Command
{
    protected $signature = 'netsuite:sync-employees';
    protected $description = 'Sync employees from NetSuite to local database';

    public function handle(NetSuiteService $netSuiteService)
    {
        $this->info('Starting employee sync...');
        $isSandbox = config('netsuite.environment') === 'sandbox';
        $this->info('Environment: ' . ($isSandbox ? 'Sandbox' : 'Production'));

        try {
            $employees = $netSuiteService->searchEmployees();
            
            if (empty($employees)) {
                $this->warn('No employees found in NetSuite.');
                return Command::SUCCESS;
            }

            $employees = is_array($employees) ? $employees : [$employees];
            $count = 0;
            $updated = 0;
            $created = 0;

            $this->info("Found " . count($employees) . " employees. Syncing...");

            $this->withProgressBar($employees, function ($employee) use (&$count, &$created, &$updated, $isSandbox) {
                $count++;
                
                // Only persist the required identifiers
                $data = [
                    'netsuite_id' => (string) $employee->internalId,
                    'name' => $employee->firstName ?? $employee->entityId ?? 'Unknown',
                    // Keep other columns empty so we only rely on ID + name
                    'entity_id' => null,
                    'email' => null,
                    'phone' => null,
                    'employee_type' => null,
                    'is_inactive' => (bool) ($employee->isInactive ?? false),
                    'is_sandbox' => $isSandbox,
                ];

                // Try to get a friendlier full name if present
                if (isset($employee->firstName) && isset($employee->lastName)) {
                    $data['name'] = trim($employee->firstName . ' ' . $employee->lastName);
                } elseif (isset($employee->lastName)) {
                    $data['name'] = $employee->lastName;
                }

                $employeeModel = NetSuiteEmployee::updateOrCreate(
                    ['netsuite_id' => $data['netsuite_id'], 'is_sandbox' => $isSandbox],
                    $data
                );

                if ($employeeModel->wasRecentlyCreated) {
                    $created++;
                } else {
                    $updated++;
                }
            });

            $this->info("Sync completed!");
            $this->info("Total processed: {$count}");
            $this->info("Created: {$created}");
            $this->info("Updated: {$updated}");

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error('Error syncing employees: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }
}

