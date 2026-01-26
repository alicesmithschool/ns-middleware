<?php

namespace App\Console\Commands\NetSuite;

use App\Services\GoogleSheetsService;
use App\Services\KissflowService;
use Illuminate\Console\Command;

class SyncKissflowExpenseToSheets extends Command
{
    protected $signature = 'kissflow:sync-expense-to-sheets {--sheet=KF ID : Sheet name to read Kissflow IDs from}';
    protected $description = 'Sync Kissflow Payment Request data to Google Sheets: Read Kissflow IDs from sheet column B, fetch data, and populate PR and Line Item sheets';

    public function handle(GoogleSheetsService $sheetsService, KissflowService $kissflowService)
    {
        $this->info('Starting Kissflow Expense data sync to Google Sheets...');

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
            $this->info("Reading Kissflow IDs from sheet '{$sourceSheetName}'...");
            $rows = $expenseSheetsService->readSheet($sourceSheetName);
            
            if (empty($rows) || count($rows) < 2) {
                $this->warn('No data found in sheet (need at least header + 1 row)');
                return Command::SUCCESS;
            }
            
            // Get headers (first row)
            $headers = array_map('trim', $rows[0]);
            
            // Column A (index 0) contains PR IDs
            $prIdColumnIndex = 0;
            // Column B (index 1) is where we'll write Kissflow IDs
            $kissflowIdColumnIndex = 1;
            
            $this->info("Reading PR IDs from column A...");
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
            $notFoundCount = 0;
            
            foreach ($dataRows as $index => $row) {
                // Calculate sheet row number (index + 2: 0-based array index + 1 for header + 1 for 1-based sheet)
                $sheetRowNumber = $index + 2;
                
                // Get PR ID from column A
                $prId = isset($row[$prIdColumnIndex]) ? trim($row[$prIdColumnIndex]) : '';
                
                if (empty($prId)) {
                    if ($this->option('verbose')) {
                        $this->line("\n  Row {$sheetRowNumber}: Empty PR ID, skipping");
                    }
                    $skippedCount++;
                    $progressBar->advance();
                    continue;
                }
                
                // Check if column B already has a Kissflow ID
                $existingKissflowId = isset($row[$kissflowIdColumnIndex]) ? trim($row[$kissflowIdColumnIndex] ?? '') : '';
                $kissflowId = null;
                
                if (!empty($existingKissflowId)) {
                    // Use existing Kissflow ID
                    $kissflowId = $existingKissflowId;
                    if ($this->option('verbose')) {
                        $this->line("\n  Row {$sheetRowNumber}: Found existing Kissflow ID: {$kissflowId}");
                    }
                } else {
                    // Search Kissflow for PR ID
                    try {
                        $this->line("\n  Row {$sheetRowNumber}: Searching Kissflow for PR ID: {$prId}");
                        $searchResult = $kissflowService->searchByPRNumber($prId);
                        
                        if ($searchResult && !empty($searchResult['id'])) {
                            $kissflowId = $searchResult['id'];
                            // Update column B with the Kissflow ID
                            $cellAddress = $this->numberToColumn($kissflowIdColumnIndex + 1) . $sheetRowNumber;
                            $expenseSheetsService->updateCell($sourceSheetName, $cellAddress, $kissflowId);
                            $this->line("  ✓ Found and saved Kissflow ID: {$kissflowId}");
                            
                            // Small delay to avoid rate limiting
                            usleep(200000); // 0.2 seconds
                        } else {
                            $this->warn("  ✗ No Kissflow ID found for PR ID: {$prId}");
                            $notFoundCount++;
                            $progressBar->advance();
                            continue;
                        }
                    } catch (\Exception $e) {
                        $this->line("  ✗ Error searching Kissflow for PR {$prId}: " . $e->getMessage());
                        $errorCount++;
                        $progressBar->advance();
                        continue;
                    }
                }
                
                if (empty($kissflowId)) {
                    $skippedCount++;
                    $progressBar->advance();
                    continue;
                }
                
                try {
                    $this->line("  Fetching data for Kissflow ID: {$kissflowId}");
                    
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
                    
                    // Map to PR sheet
                    $prRow = $this->mapToPRRow($kissflowData);
                    if ($prRow) {
                        $prRows[] = $prRow;
                    }
                    
                    // Map to Line Item sheet
                    $lineItems = $this->mapToLineItemRows($kissflowData);
                    
                    if (empty($lineItems)) {
                        $this->warn("  ⚠ No line items found");
                        $this->line("  → Check logs for available keys in the response");
                    } else {
                        foreach ($lineItems as $lineItemRow) {
                            $lineItemRows[] = $lineItemRow;
                        }
                    }
                    
                    $this->line("  ✓ Mapped data: 1 PR row, " . count($lineItems) . " line item row(s)");
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
            $this->info("  Not found in Kissflow: " . $notFoundCount);
            $this->info("  Skipped (empty): " . $skippedCount);
            $this->info("  Errors: " . $errorCount);
            $this->newLine();
            
            $this->info("Sync completed!");
            
            return Command::SUCCESS;
            
        } catch (\Exception $e) {
            $this->error('Error syncing Kissflow Expense data: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }

    /**
     * Map Kissflow Payment Request data to PR sheet row
     */
    private function mapToPRRow($kissflowData)
    {
        $row = [];
        
        // PAY_Form_Number : PR ID
        $row['PR ID'] = $this->getNestedValue($kissflowData, 'PAY_Form_Number', '');
        
        // Payee/Vendor - you may need to adjust this field name based on actual Kissflow structure
        $row['Payee/Vendor'] = $this->getNestedValue($kissflowData, 'Payee_Name', '') 
            ?: $this->getNestedValue($kissflowData, 'Supplier_Company_Name', '')
            ?: $this->getNestedValue($kissflowData, 'Vendor', '');
        
        // Timestamp - use created date or transaction date
        $timestamp = $this->getNestedValue($kissflowData, '_created_at', '')
            ?: $this->getNestedValue($kissflowData, 'Transaction_Date', '')
            ?: $this->getNestedValue($kissflowData, 'Date', '');
        
        if (!empty($timestamp)) {
            $row['Timestamp'] = $this->formatTimestamp($timestamp);
        } else {
            $row['Timestamp'] = date('Y-m-d H:i:s');
        }
        
        return $row;
    }

    /**
     * Map Kissflow Payment Request data to Line Item sheet rows
     */
    private function mapToLineItemRows($kissflowData)
    {
        $rows = [];
        
        // Get the PR ID for linking
        $prId = $this->getNestedValue($kissflowData, 'PAY_Form_Number', '');
        
        // Find line items table - adjust field name based on actual Kissflow structure
        // Try common table field names
        $lineItemsArray = null;
        
        // Try direct access with Table:: prefix
        if (isset($kissflowData['Table::Line_Items'])) {
            $lineItemsArray = $kissflowData['Table::Line_Items'];
        }
        // Try without Table:: prefix
        elseif (isset($kissflowData['Line_Items'])) {
            $lineItemsArray = $kissflowData['Line_Items'];
        }
        // Try using the nested value function
        else {
            $lineItemsArray = $this->getNestedValue($kissflowData, 'Table::Line_Items', null);
            if (!$lineItemsArray) {
                $lineItemsArray = $this->getNestedValue($kissflowData, 'Line_Items', null);
            }
        }
        
        // Log for debugging
        if ($this->option('verbose')) {
            $this->line("  → Looking for Line Items table");
            $this->line("  → Available keys: " . (is_array($kissflowData) ? implode(', ', array_keys($kissflowData)) : 'not_array'));
            $this->line("  → Line items array found: " . (is_array($lineItemsArray) ? 'yes (' . count($lineItemsArray) . ' items)' : 'no'));
        }
        
        if (!is_array($lineItemsArray) || empty($lineItemsArray)) {
            \Log::warning('Kissflow Line Items Array Not Found', [
                'available_keys' => is_array($kissflowData) ? array_keys($kissflowData) : 'not_array',
                'line_items_array_type' => gettype($lineItemsArray),
                'line_items_array_count' => is_array($lineItemsArray) ? count($lineItemsArray) : 0
            ]);
            return $rows;
        }
        
        foreach ($lineItemsArray as $index => $item) {
            $row = [];
            
            // PR ID for linking
            $row['PR ID'] = $prId;
            
            // Payment Reference - adjust field name based on actual Kissflow structure
            $row['Payment Reference'] = $this->getNestedValue($item, 'Payment_Reference', '')
                ?: $this->getNestedValue($item, 'Reference', '')
                ?: $this->getNestedValue($item, 'Description', '');
            
            // Memo
            $row['Memo'] = $this->getNestedValue($item, 'Memo', '')
                ?: $this->getNestedValue($item, 'Description', '')
                ?: $row['Payment Reference'];
            
            // Price/Rate
            $row['Price'] = $this->getNestedValue($item, 'Price', '')
                ?: $this->getNestedValue($item, 'Amount', '')
                ?: $this->getNestedValue($item, 'Rate', '0');
            
            // Currency
            $row['Currency'] = $this->getNestedValue($item, 'Currency', '')
                ?: $this->getNestedValue($kissflowData, 'Currency', '');
            
            // Budget Code
            $row['Budget Code'] = $this->getNestedValue($item, 'Budget_Code', '')
                ?: $this->getNestedValue($item, 'GL_Code.Name', '')
                ?: $this->getNestedValue($kissflowData, 'Budget_Code', '');
            
            // Subcode
            $row['Subcode'] = $this->getNestedValue($item, 'Subcode', '')
                ?: $this->getNestedValue($item, 'Account_Number', '')
                ?: $this->getNestedValue($item, 'Budget_Subcode.SubCode', '');
            
            $rows[] = $row;
        }
        
        return $rows;
    }

    /**
     * Get nested value from array using dot notation or array notation
     * Handles paths like: 'GL_Code_1.Name', 'Table::Line_Items', etc.
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
        // Try with Table:: prefix first, then without
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
            return '';
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


