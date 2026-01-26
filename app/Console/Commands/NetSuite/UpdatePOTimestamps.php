<?php

namespace App\Console\Commands\NetSuite;

use App\Services\GoogleSheetsService;
use App\Services\NetSuiteService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class UpdatePOTimestamps extends Command
{
    protected $signature = 'netsuite:update-po-timestamps';
    protected $description = 'Update Purchase Order timestamps from Synced sheet';

    public function handle(GoogleSheetsService $sheetsService, NetSuiteService $netSuiteService)
    {
        $this->info('Starting PO timestamp update from Synced sheet...');
        
        $environment = config('netsuite.environment', 'sandbox');
        $this->info("Environment: {$environment}");

        try {
            // Read Synced sheet
            $this->info('Reading Synced sheet...');
            $rows = $sheetsService->readSheet('Synced');
            
            if (empty($rows) || count($rows) < 2) {
                $this->warn('No data found in Synced sheet (need at least header + 1 row)');
                return Command::SUCCESS;
            }
            
            // Get headers (first row)
            $headers = array_map('trim', $rows[0]);
            $headerMap = array_flip($headers);
            
            // Validate required headers
            if (!isset($headerMap['Timestamp'])) {
                $this->error("Required header 'Timestamp' not found in Synced sheet");
                return Command::FAILURE;
            }
            
            if (!isset($headerMap['PO'])) {
                $this->error("Required header 'PO' not found in Synced sheet");
                return Command::FAILURE;
            }
            
            $timestampColumn = $headerMap['Timestamp'];
            $poColumn = $headerMap['PO'];
            
            // Process each row
            $dataRows = array_slice($rows, 1);
            $totalRows = count($dataRows);
            $updatedCount = 0;
            $skippedCount = 0;
            $errorCount = 0;
            
            $this->info("Found {$totalRows} row(s) to process...");
            
            $progressBar = $this->output->createProgressBar($totalRows);
            $progressBar->start();
            
            foreach ($dataRows as $index => $row) {
                $rowNumber = $index + 2; // +2 because array is 0-based and we skip header
                
                try {
                    // Get PO (document number) and timestamp
                    $poNumber = trim($row[$poColumn] ?? '');
                    $timestamp = trim($row[$timestampColumn] ?? '');
                    
                    // Skip if PO is empty
                    if (empty($poNumber)) {
                        $skippedCount++;
                        $progressBar->advance();
                        continue;
                    }
                    
                    // Skip if timestamp is empty or "n/a" (case-insensitive) - just ignore silently
                    $timestampLower = strtolower($timestamp);
                    if (empty($timestamp) || $timestampLower === 'n/a' || $timestampLower === 'na') {
                        $skippedCount++;
                        $progressBar->advance();
                        continue;
                    }
                    
                    // Parse timestamp - try multiple formats
                    $dateTime = $this->parseTimestamp($timestamp);
                    if (!$dateTime) {
                        // Invalid format - skip silently
                        $skippedCount++;
                        $progressBar->advance();
                        continue;
                    }
                    
                    // Format date for NetSuite
                    // Set time to start of day to avoid timezone issues
                    $dateTime->setTime(0, 0, 0);
                    
                    // Format for comparison (YYYY-MM-DD)
                    $netSuiteDate = $dateTime->format('Y-m-d');
                    
                    // Format for NetSuite update (ISO 8601 with time: YYYY-MM-DDTHH:MM:SS)
                    // NetSuite error "Invalid dateTime format: 2025-12-17" indicates it needs time component
                    $netSuiteDateTime = $dateTime->format('Y-m-d\TH:i:s');
                    
                    // Get PO from NetSuite
                    $poRecord = $netSuiteService->getPurchaseOrderByTranId($poNumber);
                    
                    if (!$poRecord) {
                        $this->newLine();
                        $this->warn("  Row {$rowNumber}: PO '{$poNumber}' not found in NetSuite, skipping");
                        $skippedCount++;
                        $progressBar->advance();
                        continue;
                    }
                    
                    // Check if date needs updating
                    // NetSuite returns dates in different formats, normalize current date
                    $currentDate = null;
                    if (isset($poRecord->tranDate)) {
                        if (is_object($poRecord->tranDate) && isset($poRecord->tranDate->date)) {
                            // It's a Date object with date property
                            $currentDate = $poRecord->tranDate->date;
                        } elseif (is_string($poRecord->tranDate)) {
                            $currentDate = $poRecord->tranDate;
                        }
                    }
                    
                    // Normalize current date to Y-m-d format for comparison
                    $currentDateNormalized = null;
                    if ($currentDate) {
                        try {
                            $currentDateObj = new \DateTime($currentDate);
                            $currentDateNormalized = $currentDateObj->format('Y-m-d');
                        } catch (\Exception $e) {
                            // If parsing fails, just use as-is
                            $currentDateNormalized = $currentDate;
                        }
                    }
                    
                    if ($currentDateNormalized === $netSuiteDate) {
                        // Already up to date, skip
                        $skippedCount++;
                        $progressBar->advance();
                        continue;
                    }
                    
                    // Update the PO
                    $service = $netSuiteService->getService();
                    
                    // NetSuite error "Invalid dateTime format: 2025-12-17" suggests it expects DateTime with time
                    // Try unsetting first, then setting with ISO 8601 format that includes time component
                    unset($poRecord->tranDate);
                    
                    // Set date with time component (already formatted above)
                    $poRecord->tranDate = $netSuiteDateTime;
                    
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
                        
                        $this->newLine();
                        $this->error("  Row {$rowNumber}: Failed to update PO '{$poNumber}': " . trim($errorMsg));
                        $errorCount++;
                        Log::error("Failed to update PO {$poNumber}: " . trim($errorMsg));
                    } else {
                        $updatedCount++;
                        // Small delay to avoid rate limiting
                        usleep(200000); // 0.2 seconds
                    }
                    
                } catch (\Exception $e) {
                    $this->newLine();
                    $this->error("  Row {$rowNumber}: Error processing: " . $e->getMessage());
                    $errorCount++;
                    Log::error("Error updating PO timestamp at row {$rowNumber}: " . $e->getMessage());
                }
                
                $progressBar->advance();
            }
            
            $progressBar->finish();
            $this->newLine(2);
            
            // Show summary
            $this->info("Summary:");
            $this->info("  Total rows: {$totalRows}");
            $this->info("  Updated: {$updatedCount}");
            $this->info("  Skipped: {$skippedCount}");
            $this->info("  Errors: {$errorCount}");
            
            return Command::SUCCESS;
            
        } catch (\Exception $e) {
            $this->error('Error updating PO timestamps: ' . $e->getMessage());
            Log::error('Error updating PO timestamps: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }
    
    /**
     * Parse timestamp string to DateTime object
     * Supports multiple formats
     */
    private function parseTimestamp($timestamp)
    {
        // Common formats to try
        $formats = [
            'Y-m-d H:i:s',           // 2024-01-15 14:30:00
            'Y-m-d H:i:s.u',         // 2024-01-15 14:30:00.000000
            'Y-m-d H:i',             // 2024-01-15 14:30
            'Y-m-d',                 // 2024-01-15
            'd/m/Y H:i:s',           // 15/01/2024 14:30:00
            'd/m/Y H:i',             // 15/01/2024 14:30
            'd/m/Y',                 // 15/01/2024
            'm/d/Y H:i:s',           // 01/15/2024 14:30:00
            'm/d/Y H:i',             // 01/15/2024 14:30
            'm/d/Y',                 // 01/15/2024
            'Y-m-d\TH:i:s',          // ISO 8601: 2024-01-15T14:30:00
            'Y-m-d\TH:i:s.u',        // ISO 8601 with microseconds
            'Y-m-d\TH:i:sP',         // ISO 8601 with timezone
            'Y-m-d\TH:i:s.uP',       // ISO 8601 with microseconds and timezone
        ];
        
        foreach ($formats as $format) {
            $date = \DateTime::createFromFormat($format, $timestamp);
            if ($date !== false) {
                return $date;
            }
        }
        
        // Try strtotime as fallback
        $timestampUnix = strtotime($timestamp);
        if ($timestampUnix !== false) {
            $date = new \DateTime();
            $date->setTimestamp($timestampUnix);
            return $date;
        }
        
        return null;
    }
}
