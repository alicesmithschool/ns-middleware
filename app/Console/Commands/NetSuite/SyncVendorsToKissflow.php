<?php

namespace App\Console\Commands\NetSuite;

use App\Models\NetSuiteVendor;
use App\Services\KissflowService;
use Illuminate\Console\Command;

class SyncVendorsToKissflow extends Command
{
    protected $signature = 'netsuite:sync-vendors-to-kissflow {--sandbox : Sync sandbox vendors only} {--production : Sync production vendors only} {--all : Sync both sandbox and production vendors (default: sync based on NETSUITE_ENVIRONMENT)}';
    
    protected $description = 'Sync vendors from local database to KISSFLOW endpoints';

    public function handle(KissflowService $kissflowService)
    {
        $this->info('Starting vendor sync to KISSFLOW...');
        $this->newLine();

        // Determine which vendors to sync
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

        // Sync sandbox vendors
        if ($syncSandbox) {
            $this->info('=== Syncing Sandbox Vendors ===');
            $result = $this->syncVendors($kissflowService, true);
            $totalSynced += $result['synced'];
            $totalErrors += $result['errors'];
            $this->newLine();
        }

        // Sync production vendors
        if ($syncProduction) {
            $this->info('=== Syncing Production Vendors ===');
            $result = $this->syncVendors($kissflowService, false);
            $totalSynced += $result['synced'];
            $totalErrors += $result['errors'];
            $this->newLine();
        }

        $this->info('=== Sync Summary ===');
        $this->info("Total vendors synced: {$totalSynced}");
        if ($totalErrors > 0) {
            $this->warn("Total errors: {$totalErrors}");
        }

        return $totalErrors > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    protected function syncVendors(KissflowService $kissflowService, bool $isSandbox): array
    {
        $environment = $isSandbox ? 'sandbox' : 'production';
        $this->info("Environment: {$environment}");

        // Fetch vendors from local database
        $vendors = NetSuiteVendor::where('is_sandbox', $isSandbox)->get();

        if ($vendors->isEmpty()) {
            $this->warn("No vendors found in local database for {$environment} environment.");
            return ['synced' => 0, 'errors' => 0];
        }

        $this->info("Found {$vendors->count()} vendors in local database.");
        $this->newLine();

        // Transform vendors to format expected by KissflowService
        $kissflowVendors = [];
        $progressBar = $this->output->createProgressBar($vendors->count());
        $progressBar->start();

        foreach ($vendors as $vendor) {
            // Transform vendor data to match KissflowService expectations
            $kissflowVendors[] = [
                'internal_id' => $vendor->netsuite_id,
                'entity_id' => $vendor->entity_id ?? '',
                'company_name' => $vendor->name ?? '',
                'email' => $vendor->email ?? '',
                'phone' => $vendor->phone ?? '',
                'is_inactive' => $vendor->is_inactive ?? false,
            ];

            $progressBar->advance();
        }

        $progressBar->finish();
        $this->newLine(2);

        // Split vendors into batches of 500 (KISSFLOW limit)
        $batchSize = 500;
        $vendorBatches = array_chunk($kissflowVendors, $batchSize);
        $totalBatches = count($vendorBatches);
        
        $this->info("Pushing {$vendors->count()} vendors to KISSFLOW {$environment} endpoint in {$totalBatches} batch(es) of up to {$batchSize} vendors...");
        $this->newLine();
        
        $totalSynced = 0;
        $totalErrors = 0;
        
        foreach ($vendorBatches as $batchIndex => $batch) {
            $batchNumber = $batchIndex + 1;
            $batchCount = count($batch);
            $this->info("Processing batch {$batchNumber}/{$totalBatches} ({$batchCount} vendors)...");
            
            try {
                $result = $kissflowService->pushVendorsBatch($batch, $isSandbox);
                
                if ($result['success']) {
                    $this->info("  ✓ Successfully pushed {$result['count']} vendors to KISSFLOW.");
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
            $this->info("✓ Successfully synced all {$totalSynced} vendors to KISSFLOW.");
        }
        
        return ['synced' => $totalSynced, 'errors' => $totalErrors];
    }
}

