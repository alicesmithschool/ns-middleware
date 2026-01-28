<?php

namespace App\Console\Commands\NetSuite;

use App\Models\NetSuiteMSIC;
use App\Services\NetSuiteRestService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SyncMSICCodes extends Command
{
    protected $signature = 'netsuite:sync-msic-codes {--force : Force re-sync even if codes already exist}';
    protected $description = 'Sync MSIC codes from NetSuite to local database';

    public function handle(NetSuiteRestService $netSuiteRestService)
    {
        $environment = config('netsuite.environment', 'sandbox');
        $isSandbox = $environment === 'sandbox';

        $this->info('Syncing MSIC codes from NetSuite...');
        $this->info("Environment: {$environment}");
        $this->newLine();

        // Check if we already have MSIC codes
        $existingCount = NetSuiteMSIC::where('is_sandbox', $isSandbox)->count();

        if ($existingCount > 0 && !$this->option('force')) {
            $this->info("Found {$existingCount} existing MSIC codes for {$environment}.");
            
            if (!$this->confirm('Do you want to re-sync and replace existing codes?')) {
                $this->info('Sync cancelled.');
                return Command::SUCCESS;
            }
        }

        try {
            // Fetch MSIC codes from NetSuite
            $this->line('Fetching MSIC codes from NetSuite...');
            
            $results = $netSuiteRestService->fetchMSICCodes();

            if (empty($results)) {
                $this->warn('No MSIC codes found in NetSuite.');
                return Command::FAILURE;
            }

            $this->info("Found " . count($results) . " MSIC codes in NetSuite");
            $this->newLine();

            // Clear existing codes for this environment if force
            if ($this->option('force') && $existingCount > 0) {
                $this->line('Clearing existing MSIC codes...');
                NetSuiteMSIC::where('is_sandbox', $isSandbox)->delete();
            }

            // Process and store MSIC codes
            $bar = $this->output->createProgressBar(count($results));
            $bar->start();

            $synced = 0;
            $skipped = 0;
            $errors = 0;

            foreach ($results as $msicData) {
                try {
                    $netsuiteId = $msicData['id'] ?? null;
                    $name = $msicData['name'] ?? '';

                    if (!$netsuiteId || !$name) {
                        $skipped++;
                        $bar->advance();
                        continue;
                    }

                    // Parse the name format "00000 : NOT APPLICABLE"
                    if (preg_match('/^(\d+)\s*:\s*(.+)$/i', $name, $matches)) {
                        $msicCode = $matches[1];
                        $description = trim($matches[2]);
                    } else {
                        // If format doesn't match, use the whole name
                        $msicCode = $netsuiteId;
                        $description = $name;
                    }

                    NetSuiteMSIC::updateOrCreate(
                        [
                            'netsuite_id' => $netsuiteId,
                            'is_sandbox' => $isSandbox,
                        ],
                        [
                            'msic_code' => $msicCode,
                            'description' => $description,
                            'ref_name' => $name,
                        ]
                    );

                    $synced++;
                } catch (\Exception $e) {
                    $errors++;
                    Log::error('Error syncing MSIC code: ' . $e->getMessage(), [
                        'msic_data' => $msicData
                    ]);
                }

                $bar->advance();
            }

            $bar->finish();
            $this->newLine(2);

            // Summary
            $this->info("✓ Successfully synced {$synced} MSIC codes");
            if ($skipped > 0) {
                $this->warn("⚠ Skipped {$skipped} invalid codes");
            }
            if ($errors > 0) {
                $this->error("✗ {$errors} errors occurred");
            }

            $this->newLine();
            $this->line('Sample MSIC codes:');
            $samples = NetSuiteMSIC::where('is_sandbox', $isSandbox)
                ->orderBy('msic_code')
                ->limit(5)
                ->get();

            foreach ($samples as $sample) {
                $this->line("  {$sample->msic_code} - {$sample->description} (ID: {$sample->netsuite_id})");
            }

            return Command::SUCCESS;

        } catch (\Exception $e) {
            $this->error('Error syncing MSIC codes: ' . $e->getMessage());
            Log::error('MSIC sync error: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }
}
