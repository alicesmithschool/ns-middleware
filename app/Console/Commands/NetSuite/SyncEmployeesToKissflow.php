<?php

namespace App\Console\Commands\NetSuite;

use App\Models\NetSuiteEmployee;
use App\Services\KissflowService;
use Illuminate\Console\Command;

class SyncEmployeesToKissflow extends Command
{
    protected $signature = 'netsuite:sync-employees-to-kissflow 
                            {--sandbox : Sync sandbox employees only} 
                            {--production : Sync production employees only} 
                            {--all : Sync both sandbox and production employees (default: sync based on NETSUITE_ENVIRONMENT)}';

    protected $description = 'Sync employees from local database to Kissflow dataset NetSuite_Employee in batches of 500';

    public function handle(KissflowService $kissflowService)
    {
        $this->info('Starting employee sync to Kissflow...');
        $this->newLine();

        // Determine which employees to sync
        $syncSandbox = $this->option('sandbox');
        $syncProduction = $this->option('production');
        $syncAll = $this->option('all');

        if ($syncSandbox && $syncProduction) {
            $this->error('Cannot specify both --sandbox and --production. Use --all to sync both, or omit flags to sync based on NETSUITE_ENVIRONMENT.');
            return Command::FAILURE;
        }

        // If no flags specified, use environment config
        if (!$syncSandbox && !$syncProduction && !$syncAll) {
            $isSandbox = config('netsuite.environment') === 'sandbox';
            $syncSandbox = $isSandbox;
            $syncProduction = !$isSandbox;
        } elseif ($syncAll) {
            $syncSandbox = true;
            $syncProduction = true;
        }

        $totalSynced = 0;
        $totalErrors = 0;

        if ($syncSandbox) {
            $this->info('=== Syncing Sandbox Employees ===');
            $result = $this->syncEmployees($kissflowService, true);
            $totalSynced += $result['synced'];
            $totalErrors += $result['errors'];
            $this->newLine();
        }

        if ($syncProduction) {
            $this->info('=== Syncing Production Employees ===');
            $result = $this->syncEmployees($kissflowService, false);
            $totalSynced += $result['synced'];
            $totalErrors += $result['errors'];
            $this->newLine();
        }

        $this->info('=== Sync Summary ===');
        $this->info("Total employees synced: {$totalSynced}");
        if ($totalErrors > 0) {
            $this->warn("Total errors: {$totalErrors}");
        }

        return $totalErrors > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    protected function syncEmployees(KissflowService $kissflowService, bool $isSandbox): array
    {
        $environment = $isSandbox ? 'sandbox' : 'production';
        $this->info("Environment: {$environment}");

        $employees = NetSuiteEmployee::where('is_sandbox', $isSandbox)->get();

        if ($employees->isEmpty()) {
            $this->warn("No employees found in local database for {$environment} environment. Run netsuite:sync-employees first.");
            return ['synced' => 0, 'errors' => 0];
        }

        $this->info("Found {$employees->count()} employees in local database.");
        $this->newLine();

        // Prepare employees for Kissflow (only NetSuite ID and name)
        $kissflowEmployees = [];
        $progressBar = $this->output->createProgressBar($employees->count());
        $progressBar->start();

        foreach ($employees as $employee) {
            if (empty($employee->netsuite_id)) {
                $progressBar->advance();
                continue;
            }

            $kissflowEmployees[] = [
                'netsuite_id' => (string) $employee->netsuite_id,
                'name' => $employee->name ?? '',
            ];

            $progressBar->advance();
        }

        $progressBar->finish();
        $this->newLine(2);

        if (empty($kissflowEmployees)) {
            $this->warn("No employees with NetSuite IDs available for {$environment} environment.");
            return ['synced' => 0, 'errors' => 0];
        }

        // Split employees into batches of 500
        $batchSize = 500;
        $employeeBatches = array_chunk($kissflowEmployees, $batchSize);
        $totalBatches = count($employeeBatches);

        $this->info("Pushing {$employees->count()} employees to Kissflow {$environment} endpoint in {$totalBatches} batch(es) of up to {$batchSize} employees...");
        $this->newLine();

        $totalSynced = 0;
        $totalErrors = 0;

        foreach ($employeeBatches as $batchIndex => $batch) {
            $batchNumber = $batchIndex + 1;
            $batchCount = count($batch);
            $this->info("Processing batch {$batchNumber}/{$totalBatches} ({$batchCount} employees)...");

            try {
                $result = $kissflowService->pushEmployeesBatch($batch, $isSandbox);

                if ($result['success']) {
                    $this->info("  ✓ Successfully pushed {$result['count']} employees to Kissflow.");
                    $totalSynced += $result['count'];
                } else {
                    $this->error("  ✗ Failed to push batch {$batchNumber}: " . ($result['error'] ?? 'Unknown error'));
                    $totalErrors += $batchCount;
                }
            } catch (\Exception $e) {
                $this->error("  ✗ Error pushing batch {$batchNumber}: " . $e->getMessage());
                $totalErrors += $batchCount;
            }

            // Small delay between batches to avoid rate limiting
            if ($batchIndex < $totalBatches - 1) {
                usleep(500000); // 0.5 second delay between batches
            }
        }

        $this->newLine();
        if ($totalErrors > 0) {
            $this->warn("Completed with errors: {$totalSynced} synced, {$totalErrors} failed");
        } else {
            $this->info("✓ Successfully synced all {$totalSynced} employees to Kissflow.");
        }

        return ['synced' => $totalSynced, 'errors' => $totalErrors];
    }
}



