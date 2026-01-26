<?php

namespace App\Console\Commands\NetSuite;

use App\Services\GoogleSheetsService;
use App\Services\KissflowService;
use Illuminate\Console\Command;

class SyncKissflowExpenseIds extends Command
{
    protected $signature = 'kissflow:sync-expense-ids {--sheet=KF ID : Sheet name to read Kissflow IDs from}';
    protected $description = 'Sync Kissflow Payment Request data to Google Sheets: Read Kissflow IDs from sheet column B, fetch data, and populate PR and Line Item sheets';

    public function handle(GoogleSheetsService $sheetsService, KissflowService $kissflowService)
    {
        $this->info('Starting Kissflow Expense ID sync to Google Sheets...');

        // Get expense spreadsheet ID based on environment
        $environment = config('netsuite.environment', 'sandbox');
        $isSandbox = $environment === 'sandbox';
        
        $spreadsheetId = $this->getExpenseSpreadsheetId($environment);
        $this->info("Using Expense spreadsheet ID: {$spreadsheetId}");
        $this->info("NetSuite Environment: {$environment}");
        
        $expenseSheetsService = new GoogleSheetsService($spreadsheetId);

        try {
            $sourceSheetName = $this->option('sheet');
            
            // Read source sheet (KF ID sheet)
            $this->info("Reading Kissflow IDs from sheet '{$sourceSheetName}' column B...");
            $rows = $expenseSheetsService->readSheet($sourceSheetName);
            
            if (empty($rows) || count($rows) < 2) {
                $this->warn('No data found in sheet (need at least header + 1 row)');
                return Command::SUCCESS;
            }
            
            // Get headers (first row)
            $headers = array_map('trim', $rows[0]);
            
            // Column B (index 1) contains Kissflow IDs
            $kissflowIdColumnIndex = 1;
            
            $this->info("Reading Kissflow IDs from column B...");
            $this->line("  Sheet headers: " . implode(', ', $headers));
            $this->line("  Total rows (including header): " . count($rows));
            $this->line("  Data rows: " . (count($rows) - 1));
            
            // Process data rows (skip header)
            $dataRows = array_slice($rows, 1);
            $totalRows = count($dataRows);
            $progressBar = $this->output->createProgressBar($totalRows);
            $progressBar->start();
            
            $prRows = [];
            $lineItemRows = [];
            $successCount = 0;
            $errorCount = 0;
            $skippedCount = 0;
            
            foreach ($dataRows as $index => $row) {
                // Get Kissflow ID from column B
                $kissflowId = isset($row[$kissflowIdColumnIndex]) ? trim($row[$kissflowIdColumnIndex]) : '';
                
                if (empty($kissflowId)) {
                    if ($this->option('verbose')) {
                        $this->line("\n  Row " . ($index + 2) . ": Empty Kissflow ID, skipping");
                    }
                    $skippedCount++;
                    $progressBar->advance();
                    continue;
                }
                
                if ($this->option('verbose')) {
                    $this->line("\n  Row " . ($index + 2) . ": Found Kissflow ID: {$kissflowId}");
                }
                
                try {
                    $this->line("\n  Fetching data for Kissflow ID: {$kissflowId}");
                    
                    // Fetch full item data from Kissflow Payment Request
                    $kissflowData = $kissflowService->getPaymentRequestById($kissflowId);
                    
                    if (!$kissflowData) {
                        $this->warn("  ✗ No data found for Kissflow ID: {$kissflowId}");
                        $this->line("  → Check logs for detailed response structure");
                        $this->line("  → Verify the Kissflow ID is correct and the API endpoint is accessible");
                        $errorCount++;
                        $progressBar->advance();
                        continue;
                    }
                    
                    // Debug: Show what data we got
                    if ($this->option('verbose')) {
                        $this->line("  → Data keys: " . (is_array($kissflowData) ? implode(', ', array_keys($kissflowData)) : 'not_array'));
                    }
                    
                    // Check Payment_Type - only process if it's "Non-Staff Payment"
                    $paymentType = $this->getNestedValue($kissflowData, 'Payment_Type', '');
                    if (trim($paymentType) !== 'Non-Staff Payment') {
                        $this->line("  ⚠ Skipping: Payment_Type is '{$paymentType}' (expected 'Non-Staff Payment')");
                        $skippedCount++;
                        $progressBar->advance();
                        continue;
                    }
                    
                    // Get PAY_Form_Number from header (PR ID)
                    $payFormNumber = $this->getNestedValue($kissflowData, 'PAY_Form_Number', '');
                    $completedAt = $this->getNestedValue($kissflowData, '_completed_at', '');
                    
                    // Get Finance_Processing_Table
                    $financeProcessingTable = $this->getFinanceProcessingTable($kissflowData);
                    
                    if (empty($financeProcessingTable) || !is_array($financeProcessingTable)) {
                        $this->warn("  ⚠ No Finance_Processing_Table found for Kissflow ID: {$kissflowId}");
                        $progressBar->advance();
                        continue;
                    }
                    
                    // Group by unique vendor (Actual_Payee > Supplier_Name)
                    $vendorsData = [];
                    foreach ($financeProcessingTable as $item) {
                        $vendor = $this->getNestedValue($item, 'Actual_Payee.Supplier_Name', '')
                            ?: $this->getNestedValue($item, 'Actual_Payee', '')
                            ?: $this->getNestedValue($item, 'Supplier_Name', '');
                        
                        if (empty($vendor)) {
                            continue;
                        }
                        
                        // Use vendor as key to group items
                        if (!isset($vendorsData[$vendor])) {
                            $vendorsData[$vendor] = [];
                        }
                        $vendorsData[$vendor][] = $item;
                    }
                    
                    // Create PR rows (one per unique vendor)
                    foreach ($vendorsData as $vendor => $items) {
                        $prRow = [
                            'PR ID' => $payFormNumber,
                            'Payee/Vendor' => $vendor,
                            'Timestamp' => $this->formatTimestamp($completedAt),
                        ];
                        $prRows[] = $prRow;
                        
                        // Create line items for this vendor
                        foreach ($items as $item) {
                            $lineItemRow = [
                                'PR ID' => $payFormNumber,
                                'Payment Reference' => $this->getNestedValue($item, 'Description', ''),
                                'Memo' => $this->getNestedValue($item, 'Document_Reference', ''),
                                'Price' => $this->getNestedValue($item, 'Amount_1', '0'),
                                'Currency' => $this->getNestedValue($item, 'Currency_1', ''),
                                'Budget Code' => $this->getNestedValue($item, 'Budget_Code_4', ''),
                                'Subcode' => $this->getNestedValue($item, 'Subcode.SubCode', ''),
                            ];
                            $lineItemRows[] = $lineItemRow;
                        }
                    }
                    
                    $vendorCount = count($vendorsData);
                    $totalLineItemsForThisPR = array_sum(array_map('count', $vendorsData));
                    $this->line("  ✓ Mapped data: {$vendorCount} PR row(s), {$totalLineItemsForThisPR} line item row(s)");
                    $successCount++;
                    
                    // Small delay to avoid rate limiting
                    usleep(200000); // 0.2 seconds
                    
                } catch (\Exception $e) {
                    $this->line("\n  ✗ Error processing Kissflow ID {$kissflowId}: " . $e->getMessage());
                    $errorCount++;
                }
                
                $progressBar->advance();
            }
            
            $progressBar->finish();
            $this->newLine(2);
            
            // Write to PR sheet
            if (!empty($prRows)) {
                $this->info("Writing " . count($prRows) . " row(s) to 'PR' sheet...");
                
                // Read existing PR sheet to get headers
                $prSheetRows = $expenseSheetsService->readSheet('PR');
                $prHeaders = !empty($prSheetRows) ? array_map('trim', $prSheetRows[0]) : [];
                
                // Define expected headers for PR sheet
                $expectedPRHeaders = ['PR ID', 'Payee/Vendor', 'Timestamp'];
                
                // Check if headers exist, if not, add them
                if (empty($prHeaders)) {
                    $this->info("  Adding headers to PR sheet...");
                    $expenseSheetsService->appendToSheet('PR', [$expectedPRHeaders]);
                    $prHeaders = $expectedPRHeaders;
                }
                
                // Prepare data rows with proper column mapping
                $prDataToWrite = [];
                foreach ($prRows as $prRow) {
                    $row = [];
                    foreach ($expectedPRHeaders as $header) {
                        $row[] = $prRow[$header] ?? '';
                    }
                    $prDataToWrite[] = $row;
                }
                
                $expenseSheetsService->appendToSheet('PR', $prDataToWrite);
                $this->info("  ✓ Written " . count($prRows) . " row(s) to PR sheet");
            }
            
            // Write to Line Item sheet
            if (!empty($lineItemRows)) {
                $this->info("Writing " . count($lineItemRows) . " row(s) to 'Line Item' sheet...");
                
                // Read existing Line Item sheet to get headers
                $lineItemSheetRows = $expenseSheetsService->readSheet('Line Item');
                $lineItemHeaders = !empty($lineItemSheetRows) ? array_map('trim', $lineItemSheetRows[0]) : [];
                
                // Define expected headers for Line Item sheet
                $expectedLineItemHeaders = ['PR ID', 'Payment Reference', 'Memo', 'Price', 'Currency', 'Budget Code', 'Subcode'];
                
                // Check if headers exist, if not, add them
                if (empty($lineItemHeaders)) {
                    $this->info("  Adding headers to Line Item sheet...");
                    $expenseSheetsService->appendToSheet('Line Item', [$expectedLineItemHeaders]);
                    $lineItemHeaders = $expectedLineItemHeaders;
                }
                
                // Prepare data rows with proper column mapping
                $lineItemDataToWrite = [];
                foreach ($lineItemRows as $lineItemRow) {
                    $row = [];
                    foreach ($expectedLineItemHeaders as $header) {
                        $row[] = $lineItemRow[$header] ?? '';
                    }
                    $lineItemDataToWrite[] = $row;
                }
                
                $expenseSheetsService->appendToSheet('Line Item', $lineItemDataToWrite);
                $this->info("  ✓ Written " . count($lineItemRows) . " row(s) to Line Item sheet");
            }
            
            $this->newLine();
            
            // Show summary
            $this->info("Summary:");
            $this->info("  Total rows processed: " . $totalRows);
            $this->info("  Successfully synced: " . $successCount);
            $this->info("  PR rows created: " . count($prRows));
            $this->info("  Line Item rows created: " . count($lineItemRows));
            $this->info("  Skipped (empty): " . $skippedCount);
            $this->info("  Errors: " . $errorCount);
            $this->newLine();
            
            $this->info("Sync completed!");
            
            return Command::SUCCESS;
            
        } catch (\Exception $e) {
            $this->error('Error syncing Kissflow Expense IDs: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }

    /**
     * Get Finance_Processing_Table from Kissflow data
     * Tries multiple possible field names
     */
    private function getFinanceProcessingTable($kissflowData)
    {
        // Try direct access with Table:: prefix
        if (isset($kissflowData['Table::Finance_Processing_Table'])) {
            return $kissflowData['Table::Finance_Processing_Table'];
        }
        // Try without Table:: prefix
        if (isset($kissflowData['Finance_Processing_Table'])) {
            return $kissflowData['Finance_Processing_Table'];
        }
        // Try case-insensitive match
        if (is_array($kissflowData)) {
            foreach ($kissflowData as $key => $value) {
                if (stripos($key, 'Finance_Processing_Table') !== false || stripos($key, 'Finance Processing Table') !== false) {
                    return $value;
                }
            }
        }
        // Try using nested value function
        $table = $this->getNestedValue($kissflowData, 'Table::Finance_Processing_Table', null);
        if ($table) {
            return $table;
        }
        return $this->getNestedValue($kissflowData, 'Finance_Processing_Table', null);
    }

    /**
     * Get nested value from array using dot notation or array notation
     * Handles paths like: 'Actual_Payee.Supplier_Name', 'Subcode.SubCode', etc.
     */
    private function getNestedValue($data, $path, $default = null)
    {
        if (empty($path)) {
            return $default;
        }
        
        // First, try direct access with the full path (for Table:: keys)
        if (is_array($data) && isset($data[$path])) {
            return $data[$path];
        }
        
        // Handle Table:: prefix (special Kissflow notation)
        $originalPath = $path;
        if (strpos($path, 'Table::') === 0) {
            // Try with the full Table:: prefix
            if (is_array($data) && isset($data[$path])) {
                return $data[$path];
            }
            // Try case-insensitive match with Table:: prefix
            if (is_array($data)) {
                foreach ($data as $k => $v) {
                    if (strcasecmp($k, $path) === 0) {
                        return $v;
                    }
                }
            }
            // Remove Table:: prefix and try again
            $path = str_replace('Table::', '', $path);
        }
        
        // Split by dot notation
        $keys = explode('.', $path);
        $value = $data;
        
        foreach ($keys as $key) {
            // Handle array bracket notation like ['Name'] or ['SubCode']
            $key = trim($key, "[]'\"");
            
            if (is_array($value)) {
                // Try exact key match first
                if (isset($value[$key])) {
                    $value = $value[$key];
                } 
                // Try case-insensitive match
                elseif (is_string($key)) {
                    $found = false;
                    foreach ($value as $k => $v) {
                        if (strcasecmp($k, $key) === 0) {
                            $value = $v;
                            $found = true;
                            break;
                        }
                    }
                    if (!$found) {
                        return $default;
                    }
                } else {
                    return $default;
                }
            } else {
                return $default;
            }
        }
        
        return $value;
    }

    /**
     * Format timestamp
     */
    private function formatTimestamp($timestamp)
    {
        if (empty($timestamp)) {
            return date('Y-m-d H:i:s');
        }
        
        // Try to parse and format
        try {
            $date = new \DateTime($timestamp);
            return $date->format('Y-m-d H:i:s');
        } catch (\Exception $e) {
            return $timestamp; // Return as-is if parsing fails
        }
    }

    /**
     * Get expense spreadsheet ID based on environment
     */
    protected function getExpenseSpreadsheetId(string $environment): string
    {
        if ($environment === 'production') {
            $id = config('google-sheets.prod_expense_spreadsheet_id');
        } else {
            $id = config('google-sheets.expense_spreadsheet_id');
        }

        if (empty($id)) {
            $envVar = $environment === 'production' ? 'PROD_EXPENSE_SHEET_ID' : 'SANDBOX_EXPENSE_SHEET_ID';
            throw new \Exception("Expense Spreadsheet ID not configured. Set {$envVar} in .env");
        }

        return $id;
    }
}


