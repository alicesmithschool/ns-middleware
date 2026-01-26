<?php

namespace App\Console\Commands\NetSuite;

use App\Services\NetSuiteService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class UpdatePODates extends Command
{
    protected $signature = 'netsuite:update-po-dates {--dry-run : Show what would be changed without actually updating} {--po= : Update specific PO only} {--force : Force update even if date already matches}';
    protected $description = 'Update PO transaction dates from po_dates.json file (dates applied in GMT+8 timezone)';

    public function handle(NetSuiteService $netSuiteService)
    {
        $this->info('Starting PO date update from po_dates.json...');
        $isDryRun = $this->option('dry-run');
        $specificPO = $this->option('po');
        $forceUpdate = $this->option('force');

        // Show current environment
        $environment = config('netsuite.environment', 'sandbox');
        $this->info("NetSuite Environment: {$environment}");
        $this->info("Timezone: GMT+8 (Asia/Singapore)");

        if ($isDryRun) {
            $this->warn('DRY RUN MODE - No changes will be made to NetSuite');
        }

        if ($forceUpdate) {
            $this->info('FORCE MODE - Will update even if date already matches');
        }

        if ($specificPO) {
            $this->info("Updating only PO: {$specificPO}");
        }

        try {
            // Load po_dates.json
            $poDatePath = base_path('po_dates.json');
            if (!file_exists($poDatePath)) {
                $this->error("po_dates.json not found at: {$poDatePath}");
                return Command::FAILURE;
            }

            $poDates = json_decode(file_get_contents($poDatePath), true);
            if (!$poDates) {
                $this->error('Failed to parse po_dates.json');
                return Command::FAILURE;
            }

            $this->info("Loaded " . count($poDates) . " PO date(s) from po_dates.json");

            // Filter to specific PO if requested
            if ($specificPO) {
                if (isset($poDates[$specificPO])) {
                    $poDates = [$specificPO => $poDates[$specificPO]];
                } else {
                    $this->error("PO '{$specificPO}' not found in po_dates.json");
                    return Command::FAILURE;
                }
            }

            $totalPOs = count($poDates);
            $updatedCount = 0;
            $skippedCount = 0;
            $errorCount = 0;

            $this->info("Processing {$totalPOs} PO(s)...");
            $progressBar = $this->output->createProgressBar($totalPOs);
            $progressBar->start();

            foreach ($poDates as $poTranId => $newDate) {
                try {
                    // Validate date format
                    $dateObj = \DateTime::createFromFormat('Y-m-d', $newDate);
                    if (!$dateObj || $dateObj->format('Y-m-d') !== $newDate) {
                        $this->newLine();
                        $this->error("  PO '{$poTranId}': Invalid date format '{$newDate}' (expected YYYY-MM-DD)");
                        $errorCount++;
                        $progressBar->advance();
                        continue;
                    }

                    // Get PO from NetSuite
                    $this->newLine();
                    $this->info("  Processing PO '{$poTranId}'...");
                    $this->line("    Searching NetSuite for PO with Transaction ID (tranId): '{$poTranId}'");

                    $poRecord = $netSuiteService->getPurchaseOrderByTranId($poTranId);

                    if (!$poRecord) {
                        $this->warn("  ✗ PO '{$poTranId}' not found in NetSuite ({$environment})");
                        $this->line("    Make sure:");
                        $this->line("      - The PO exists in {$environment} environment");
                        $this->line("      - The Transaction ID (tranId) is exactly '{$poTranId}' (case-sensitive)");
                        $this->line("      - The PO is a Purchase Order (not another transaction type)");
                        $skippedCount++;
                        $progressBar->advance();
                        continue;
                    }

                    $this->line("    ✓ Found PO in NetSuite");

                    $internalId = $poRecord->internalId ?? null;
                    if (!$internalId) {
                        $this->warn("  PO '{$poTranId}' missing internalId, skipping");
                        $skippedCount++;
                        $progressBar->advance();
                        continue;
                    }

                    // Get current date
                    $currentDate = $poRecord->tranDate ?? null;
                    $currentDateStr = 'Not set';
                    if ($currentDate) {
                        // Handle both DateTime string and timestamp
                        if (is_string($currentDate)) {
                            $currentDateStr = date('Y-m-d', strtotime($currentDate));
                        } elseif (is_numeric($currentDate)) {
                            $currentDateStr = date('Y-m-d', $currentDate);
                        }
                    }

                    // Check if date needs updating (skip only if not forced)
                    if ($currentDateStr === $newDate && !$forceUpdate) {
                        $this->line("  ✓ PO '{$poTranId}' already has date {$newDate}, skipping (use --force to update anyway)");
                        $skippedCount++;
                        $progressBar->advance();
                        continue;
                    }

                    $this->info("  PO '{$poTranId}' (ID: {$internalId})");
                    $this->line("    Current date: {$currentDateStr}");
                    $this->line("    New date:     {$newDate} (GMT+8)");

                    if ($currentDateStr === $newDate && $forceUpdate) {
                        $this->line("    Date already matches, but forcing update due to --force flag");
                    }

                    if ($isDryRun) {
                        $this->warn("  [DRY RUN] Would update PO '{$poTranId}' date from {$currentDateStr} to {$newDate}");
                        $updatedCount++;
                        $progressBar->advance();
                        continue;
                    }

                    // Update PO date
                    $service = $netSuiteService->getService();

                    // Set the new date in GMT+8 timezone (Asia/Singapore)
                    // NetSuite expects DateTime format with timezone offset
                    // Convert YYYY-MM-DD to YYYY-MM-DDT00:00:00+08:00 (ISO 8601 with GMT+8)
                    $poRecord->tranDate = $newDate . 'T00:00:00+08:00';

                    // Ensure internalId is set for update
                    $poRecord->internalId = (string)$internalId;

                    // Preserve entity (vendor) - required field
                    if (!isset($poRecord->entity) || !$poRecord->entity) {
                        $this->error("  PO '{$poTranId}' missing entity (vendor), cannot update");
                        $errorCount++;
                        $progressBar->advance();
                        continue;
                    }

                    // Set preferences to allow updating
                    $service->setPreferences(false, false, false, true);

                    $updateRequest = new \UpdateRequest();
                    $updateRequest->record = $poRecord;

                    $updateResponse = $service->update($updateRequest);

                    if (!$updateResponse->writeResponse->status->isSuccess) {
                        $errorMsg = 'Update failed: ';
                        if (isset($updateResponse->writeResponse->status->statusDetail)) {
                            $details = is_array($updateResponse->writeResponse->status->statusDetail)
                                ? $updateResponse->writeResponse->status->statusDetail
                                : [$updateResponse->writeResponse->status->statusDetail];

                            foreach ($details as $detail) {
                                $errorMsg .= $detail->message . '; ';
                            }
                        }
                        $this->error("  Failed to update PO '{$poTranId}': {$errorMsg}");
                        $errorCount++;
                    } else {
                        $this->line("  ✓ Successfully updated PO '{$poTranId}' date to {$newDate}");
                        $updatedCount++;
                    }

                } catch (\Exception $e) {
                    $this->newLine();
                    $this->error("  Error processing PO '{$poTranId}': " . $e->getMessage());
                    Log::error("Error updating PO date {$poTranId}: " . $e->getMessage());
                    $errorCount++;
                }

                $progressBar->advance();
            }

            $progressBar->finish();
            $this->newLine(2);

            $this->info("Update completed!");
            $this->info("Total POs processed: {$totalPOs}");
            $this->info("Updated: {$updatedCount}");
            $this->info("Skipped: {$skippedCount}");
            $this->info("Errors: {$errorCount}");

            if ($skippedCount > 0 || $errorCount > 0) {
                $this->newLine();
                $this->warn("Troubleshooting tips:");
                $this->line("  1. Verify you're using the correct environment (currently: {$environment})");
                $this->line("  2. Check the logs for detailed error messages: php artisan pail");
                $this->line("  3. Use 'netsuite:show-po-items {internal_id}' to verify a PO's tranId");
                $this->line("  4. The tranId in po_dates.json must EXACTLY match the Transaction ID in NetSuite");
                $this->line("  5. Make sure the PO has been created and synced to {$environment}");
            }

            return Command::SUCCESS;

        } catch (\Exception $e) {
            $this->error('Error updating PO dates: ' . $e->getMessage());
            Log::error('PO date update error: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }
}
