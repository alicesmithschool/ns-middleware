<?php

namespace App\Console\Commands\NetSuite;

use App\Services\GoogleSheetsService;
use App\Services\KissflowService;
use Illuminate\Console\Command;

class SyncPaymentTypeToBillSheet extends Command
{
    protected $signature = 'kissflow:sync-payment-type-to-bill {--dry-run : Show what would be updated without making changes}';
    protected $description = 'Sync Payment_Type from Kissflow to Bill spreadsheet column D. Reads PR Numbers from PR sheet and fetches Payment_Type from Kissflow';

    public function handle(GoogleSheetsService $sheetsService, KissflowService $kissflowService)
    {
        $isDryRun = $this->option('dry-run');
        
        if ($isDryRun) {
            $this->info('DRY RUN MODE: No changes will be made');
        }
        
        $this->info('Starting Payment_Type sync to Bill spreadsheet...');

        // Get bill spreadsheet ID based on environment
        $environment = config('netsuite.environment', 'sandbox');
        $isSandbox = $environment === 'sandbox';
        
        $spreadsheetId = $this->getBillSpreadsheetId($environment);
        $this->info("Using Bill spreadsheet ID: {$spreadsheetId}");
        $this->info("NetSuite Environment: {$environment}");
        
        $billSheetsService = new GoogleSheetsService($spreadsheetId);

        try {
            // Read PR sheet
            $this->info('Reading PR sheet...');
            $prRows = $billSheetsService->readSheet('PR');
            
            if (empty($prRows) || count($prRows) < 2) {
                $this->warn('No data found in PR sheet (need at least header + 1 row)');
                return Command::SUCCESS;
            }
            
            // Get headers
            $headers = array_map('trim', $prRows[0]);
            $headerMap = array_flip($headers);
            
            // Find PR ID column
            $prIdColumnIndex = null;
            foreach (['PR ID', 'PR', 'EPR', 'ID'] as $col) {
                if (isset($headerMap[$col])) {
                    $prIdColumnIndex = $headerMap[$col];
                    break;
                }
            }
            
            if ($prIdColumnIndex === null) {
                $this->error("PR ID column not found in PR sheet. Expected one of: PR ID, PR, EPR, ID");
                $this->error("Available columns: " . implode(', ', $headers));
                return Command::FAILURE;
            }
            
            $this->info("Using PR ID column: column " . ($prIdColumnIndex + 1));
            $this->info("Sheet headers: " . implode(', ', $headers));
            
            // Column D is index 3 (0-based)
            $paymentTypeColumnIndex = 3;
            $columnLetter = $this->columnIndexToLetter($paymentTypeColumnIndex);
            
            $this->info("Will populate Payment_Type in column {$columnLetter} (index {$paymentTypeColumnIndex})");
            
            // Process data rows (skip header)
            $dataRows = array_slice($prRows, 1);
            $totalRows = count($dataRows);
            $progressBar = $this->output->createProgressBar($totalRows);
            $progressBar->start();
            
            $updates = []; // Array of ['row' => rowNumber, 'value' => paymentType]
            $successCount = 0;
            $errorCount = 0;
            $skippedCount = 0;
            
            foreach ($dataRows as $index => $row) {
                $rowNumber = $index + 2; // 1-based row number (header is row 1)
                
                // Get PR ID from the PR ID column
                $prId = isset($row[$prIdColumnIndex]) ? trim($row[$prIdColumnIndex]) : '';
                
                if (empty($prId)) {
                    if ($this->option('verbose')) {
                        $this->line("\n  Row {$rowNumber}: Empty PR ID, skipping");
                    }
                    $skippedCount++;
                    $progressBar->advance();
                    continue;
                }
                
                // Check if Payment_Type already exists in column D
                $existingPaymentType = isset($row[$paymentTypeColumnIndex]) ? trim($row[$paymentTypeColumnIndex]) : '';
                if (!empty($existingPaymentType) && !$isDryRun) {
                    if ($this->option('verbose')) {
                        $this->line("\n  Row {$rowNumber}: PR '{$prId}' already has Payment_Type: '{$existingPaymentType}', skipping");
                    }
                    $skippedCount++;
                    $progressBar->advance();
                    continue;
                }
                
                try {
                    $this->line("\n  Row {$rowNumber}: Processing PR '{$prId}'");
                    
                    // Search for Payment Request by PR Number
                    $searchResult = $kissflowService->searchPaymentRequestByPRNumber($prId);
                    
                    if (!$searchResult || !isset($searchResult['id'])) {
                        $this->warn("    ✗ No Payment Request found for PR: {$prId}");
                        $errorCount++;
                        $progressBar->advance();
                        continue;
                    }
                    
                    $kissflowId = $searchResult['id'];
                    $this->line("    → Found Kissflow ID: {$kissflowId}");
                    
                    // Get Payment_Type from search result data or fetch full data
                    $paymentType = null;
                    if (isset($searchResult['data']['Payment_Type'])) {
                        $paymentType = trim($searchResult['data']['Payment_Type']);
                    } else {
                        // Fetch full data if Payment_Type not in search result
                        $kissflowData = $kissflowService->getPaymentRequestById($kissflowId);
                        if ($kissflowData && isset($kissflowData['Payment_Type'])) {
                            $paymentType = trim($kissflowData['Payment_Type']);
                        }
                    }
                    
                    if (empty($paymentType)) {
                        $this->warn("    ⚠ Payment_Type not found for PR: {$prId}");
                        $errorCount++;
                        $progressBar->advance();
                        continue;
                    }
                    
                    $updates[] = [
                        'row' => $rowNumber,
                        'value' => $paymentType,
                        'pr_id' => $prId,
                    ];
                    
                    $this->line("    ✓ Payment_Type: '{$paymentType}'");
                    $successCount++;
                    
                    // Small delay to avoid rate limiting
                    usleep(200000); // 0.2 seconds
                    
                } catch (\Exception $e) {
                    $this->line("\n    ✗ Error processing PR {$prId}: " . $e->getMessage());
                    $errorCount++;
                }
                
                $progressBar->advance();
            }
            
            $progressBar->finish();
            $this->newLine(2);
            
            // Show summary
            $this->info("Summary:");
            $this->info("  Total rows processed: " . $totalRows);
            $this->info("  Successfully found: " . $successCount);
            $this->info("  Updates to apply: " . count($updates));
            $this->info("  Skipped (empty/already set): " . $skippedCount);
            $this->info("  Errors: " . $errorCount);
            
            // Show preview of updates
            if (!empty($updates) && $this->option('verbose')) {
                $this->newLine();
                $this->info("Preview of updates (first 10):");
                foreach (array_slice($updates, 0, 10) as $update) {
                    $this->line("  Row {$update['row']}: PR '{$update['pr_id']}' → Payment_Type: '{$update['value']}'");
                }
                if (count($updates) > 10) {
                    $this->line("  ... and " . (count($updates) - 10) . " more");
                }
            }
            
            // Apply updates if not dry run
            if (!$isDryRun && !empty($updates)) {
                $this->newLine();
                $this->info("Applying updates to PR sheet column {$columnLetter}...");
                
                // Sort by row number for contiguous updates where possible
                usort($updates, function($a, $b) {
                    return $a['row'] - $b['row'];
                });
                
                // Group contiguous rows for batch update
                $contiguousRanges = [];
                $currentRange = null;
                
                foreach ($updates as $update) {
                    if ($currentRange === null) {
                        $currentRange = [
                            'start_row' => $update['row'],
                            'end_row' => $update['row'],
                            'values' => [$update['value']]
                        ];
                    } elseif ($update['row'] === $currentRange['end_row'] + 1) {
                        // Contiguous, add to current range
                        $currentRange['end_row'] = $update['row'];
                        $currentRange['values'][] = $update['value'];
                    } else {
                        // Not contiguous, save current range and start new one
                        $contiguousRanges[] = $currentRange;
                        $currentRange = [
                            'start_row' => $update['row'],
                            'end_row' => $update['row'],
                            'values' => [$update['value']]
                        ];
                    }
                }
                if ($currentRange !== null) {
                    $contiguousRanges[] = $currentRange;
                }
                
                $this->info("  Grouped into " . count($contiguousRanges) . " contiguous range(s) for batch update");
                
                // Update each contiguous range (single API call per range)
                $updatedCount = 0;
                foreach ($contiguousRanges as $rangeIndex => $range) {
                    $rangeStr = "{$columnLetter}{$range['start_row']}:{$columnLetter}{$range['end_row']}";
                    $values = array_map(function($v) { return [$v]; }, $range['values']);
                    
                    try {
                        $billSheetsService->updateRange('PR', $rangeStr, $values);
                        $updatedCount += count($range['values']);
                        
                        if ($this->option('verbose')) {
                            $this->line("  ✓ Updated range {$rangeStr} (" . count($range['values']) . " cell(s))");
                        }
                    } catch (\Exception $e) {
                        $this->error("  ✗ Error updating range {$rangeStr}: " . $e->getMessage());
                    }
                    
                    // Small delay between ranges to avoid rate limiting
                    if ($rangeIndex < count($contiguousRanges) - 1) {
                        usleep(100000); // 0.1 seconds
                    }
                }
                
                $this->newLine();
                $this->info("✓ Successfully updated {$updatedCount} cell(s) in column {$columnLetter}");
            } elseif ($isDryRun && !empty($updates)) {
                $this->newLine();
                $this->warn("DRY RUN: " . count($updates) . " cell(s) would be updated in column {$columnLetter} (use without --dry-run to apply changes)");
            }
            
            $this->info("Sync completed!");
            
            return Command::SUCCESS;
            
        } catch (\Exception $e) {
            $this->error('Error syncing Payment_Type: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }

    /**
     * Get bill spreadsheet ID based on environment
     */
    protected function getBillSpreadsheetId(string $environment): string
    {
        if ($environment === 'production') {
            $id = config('google-sheets.prod_bill_spreadsheet_id');
        } else {
            $id = config('google-sheets.bill_spreadsheet_id');
        }

        if (empty($id)) {
            $envVar = $environment === 'production' ? 'PROD_BILL_SHEET_ID' : 'SANDBOX_BILL_SHEET_ID';
            throw new \Exception("Bill Spreadsheet ID not configured. Set {$envVar} in .env");
        }

        return $id;
    }

    /**
     * Convert 0-based column index to Excel column letter (A, B, C, ..., Z, AA, AB, ...)
     *
     * @param int $index 0-based column index
     * @return string Column letter
     */
    protected function columnIndexToLetter(int $index): string
    {
        $letter = '';
        $index++; // Convert to 1-based
        
        while ($index > 0) {
            $index--;
            $letter = chr(65 + ($index % 26)) . $letter;
            $index = intval($index / 26);
        }
        
        return $letter;
    }
}

