<?php

namespace App\Console\Commands\NetSuite;

use App\Services\GoogleSheetsService;
use App\Services\KissflowService;
use Illuminate\Console\Command;

class SyncKissflowIds extends Command
{
    protected $signature = 'kissflow:sync-ids {--sheet=PO : Sheet name to read from} {--skip-empty : Skip rows that already have a Kissflow ID in column B}';
    protected $description = 'Sync Kissflow IDs from sheet: Read PO IDs from column A (KF ID), search Kissflow, and populate column B with Kissflow ID';

    public function handle(GoogleSheetsService $sheetsService, KissflowService $kissflowService)
    {
        $this->info('Starting Kissflow ID sync from Google Sheets...');

        try {
            $sheetName = $this->option('sheet');
            
            // Read sheet
            $this->info("Reading sheet '{$sheetName}'...");
            $rows = $sheetsService->readSheet($sheetName);
            
            if (empty($rows) || count($rows) < 2) {
                $this->warn('No data found in sheet (need at least header + 1 row)');
                return Command::SUCCESS;
            }
            
            // Get headers (first row)
            $headers = array_map('trim', $rows[0]);
            $headerMap = array_flip($headers);
            
            // Find column A (first column) - this should be "KF ID" or the first column
            $kfIdColumnIndex = 0; // Column A is index 0
            $kfIdColumnName = $headers[0] ?? 'KF ID';
            
            // Find column B (second column) - this is where we'll write the Kissflow ID
            $kissflowIdColumnIndex = 1; // Column B is index 1
            $kissflowIdColumnName = $headers[1] ?? 'Kissflow ID';
            
            $this->info("Column A ({$kfIdColumnName}): PO IDs to search");
            $this->info("Column B ({$kissflowIdColumnName}): Kissflow IDs will be written here");
            
            // Process data rows (skip header)
            $dataRows = array_slice($rows, 1);
            $totalRows = count($dataRows);
            $progressBar = $this->output->createProgressBar($totalRows);
            $progressBar->start();
            
            $successCount = 0;
            $skippedCount = 0;
            $errorCount = 0;
            $notFoundCount = 0;
            
            foreach ($dataRows as $index => $row) {
                // Calculate sheet row number (index + 2: 0-based array index + 1 for header + 1 for 1-based sheet)
                $sheetRowNumber = $index + 2;
                
                // Get PO ID from column A
                $poId = isset($row[$kfIdColumnIndex]) ? trim($row[$kfIdColumnIndex]) : '';
                
                if (empty($poId)) {
                    $skippedCount++;
                    $progressBar->advance();
                    continue;
                }
                
                // Check if column B already has a value (if --skip-empty is set)
                if ($this->option('skip-empty')) {
                    $existingKissflowId = isset($row[$kissflowIdColumnIndex]) ? trim($row[$kissflowIdColumnIndex] ?? '') : '';
                    if (!empty($existingKissflowId)) {
                        $skippedCount++;
                        $progressBar->advance();
                        continue;
                    }
                }
                
                try {
                    // Search Kissflow for this PO ID
                    $this->line("\n  Searching Kissflow for PO: {$poId}");
                    $kissflowId = $kissflowService->getKissflowId($poId);
                    
                    if ($kissflowId) {
                        // Update column B with the Kissflow ID
                        // Convert row number to column letter (B = column 2)
                        $cellAddress = $this->numberToColumn($kissflowIdColumnIndex + 1) . $sheetRowNumber;
                        $sheetsService->updateCell($sheetName, $cellAddress, $kissflowId);
                        
                        $this->line("  ✓ Found Kissflow ID: {$kissflowId}");
                        $successCount++;
                        
                        // Small delay to avoid rate limiting
                        usleep(200000); // 0.2 seconds
                    } else {
                        $this->line("  ✗ No Kissflow ID found for PO: {$poId}");
                        $notFoundCount++;
                    }
                } catch (\Exception $e) {
                    $this->line("\n  ✗ Error processing PO {$poId}: " . $e->getMessage());
                    $errorCount++;
                }
                
                $progressBar->advance();
            }
            
            $progressBar->finish();
            $this->newLine(2);
            
            // Show summary
            $this->info("Summary:");
            $this->info("  Total rows processed: " . $totalRows);
            $this->info("  Successfully synced: " . $successCount);
            $this->info("  Not found in Kissflow: " . $notFoundCount);
            $this->info("  Skipped (empty/already has ID): " . $skippedCount);
            $this->info("  Errors: " . $errorCount);
            $this->newLine();
            
            $this->info("Sync completed!");
            
            return Command::SUCCESS;
            
        } catch (\Exception $e) {
            $this->error('Error syncing Kissflow IDs: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }

    /**
     * Convert column number to letter (1 = A, 2 = B, etc.)
     */
    private function numberToColumn($number)
    {
        $column = '';
        while ($number > 0) {
            $remainder = ($number - 1) % 26;
            $column = chr(65 + $remainder) . $column;
            $number = intval(($number - $remainder) / 26);
        }
        return $column;
    }
}

