<?php

namespace App\Console\Commands\NetSuite;

use App\Services\GoogleSheetsService;
use App\Services\KissflowService;
use Illuminate\Console\Command;

class SyncKissflowDataToSheets extends Command
{
    protected $signature = 'kissflow:sync-data-to-sheets {--sheet=KF ID : Sheet name to read Kissflow IDs from}';
    protected $description = 'Sync Kissflow data to Google Sheets: Read Kissflow IDs from sheet column B, fetch data, and populate PO and Items sheets';

    public function handle(GoogleSheetsService $sheetsService, KissflowService $kissflowService)
    {
        $this->info('Starting Kissflow data sync to Google Sheets...');

        try {
            $sourceSheetName = $this->option('sheet');
            
            // Read source sheet (KF ID sheet)
            $this->info("Reading Kissflow IDs from sheet '{$sourceSheetName}'...");
            $rows = $sheetsService->readSheet($sourceSheetName);
            
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
            
            $poRows = [];
            $itemRows = [];
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
                    
                    // Fetch full item data from Kissflow
                    $kissflowData = $kissflowService->getItemById($kissflowId);
                    
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
                    
                    // Map to PO sheet
                    $poRow = $this->mapToPORow($kissflowData);
                    if ($poRow) {
                        $poRows[] = $poRow;
                    }
                    
                    // Map to Items sheet (from array)
                    $items = $this->mapToItemsRows($kissflowData);
                    
                    if (empty($items)) {
                        $this->warn("  ⚠ No items found in Table::Model_DSakzWikms");
                        $this->line("  → Check logs for available keys in the response");
                    } else {
                        foreach ($items as $itemRow) {
                            $itemRows[] = $itemRow;
                        }
                    }
                    
                    $this->line("  ✓ Mapped data: 1 PO row, " . count($items) . " item row(s)");
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
            
            // Write to PO sheet
            if (!empty($poRows)) {
                $this->info("Writing " . count($poRows) . " row(s) to 'PO' sheet...");
                
                // Read existing PO sheet to get headers
                $poSheetRows = $sheetsService->readSheet('PO');
                $poHeaders = !empty($poSheetRows) ? array_map('trim', $poSheetRows[0]) : [];
                
                // Define expected headers for PO sheet
                $expectedPOHeaders = ['ID', 'Name', 'Budget Code', 'Subcode', 'Location', 'Vendor', 'Timestamp', 'PO', 'Currency'];
                
                // Check if headers exist, if not, add them
                if (empty($poHeaders)) {
                    $this->info("  Adding headers to PO sheet...");
                    $sheetsService->appendToSheet('PO', [$expectedPOHeaders]);
                    $poHeaders = $expectedPOHeaders;
                }
                
                // Create header map
                $poHeaderMap = array_flip($poHeaders);
                
                // Prepare data rows with proper column mapping
                $poDataToWrite = [];
                foreach ($poRows as $poRow) {
                    $row = [];
                    foreach ($expectedPOHeaders as $header) {
                        $row[] = $poRow[$header] ?? '';
                    }
                    $poDataToWrite[] = $row;
                }
                
                $sheetsService->appendToSheet('PO', $poDataToWrite);
                $this->info("  ✓ Written " . count($poRows) . " row(s) to PO sheet");
            }
            
            // Write to Items sheet
            if (!empty($itemRows)) {
                $this->info("Writing " . count($itemRows) . " row(s) to 'Items' sheet...");
                
                // Read existing Items sheet to get headers
                $itemsSheetRows = $sheetsService->readSheet('Items');
                $itemsHeaders = !empty($itemsSheetRows) ? array_map('trim', $itemsSheetRows[0]) : [];
                
                // Define expected headers for Items sheet
                $expectedItemsHeaders = ['ID', 'EPR', 'Name', 'Quantity', 'Unit Price', 'Reference'];
                
                // Check if headers exist, if not, add them
                if (empty($itemsHeaders)) {
                    $this->info("  Adding headers to Items sheet...");
                    $sheetsService->appendToSheet('Items', [$expectedItemsHeaders]);
                    $itemsHeaders = $expectedItemsHeaders;
                }
                
                // Prepare data rows with proper column mapping
                $itemsDataToWrite = [];
                foreach ($itemRows as $itemRow) {
                    $row = [];
                    foreach ($expectedItemsHeaders as $header) {
                        $row[] = $itemRow[$header] ?? '';
                    }
                    $itemsDataToWrite[] = $row;
                }
                
                $sheetsService->appendToSheet('Items', $itemsDataToWrite);
                $this->info("  ✓ Written " . count($itemRows) . " row(s) to Items sheet");
            }
            
            $this->newLine();
            
            // Show summary
            $this->info("Summary:");
            $this->info("  Total rows processed: " . $totalRows);
            $this->info("  Successfully synced: " . $successCount);
            $this->info("  PO rows created: " . count($poRows));
            $this->info("  Item rows created: " . count($itemRows));
            $this->info("  Skipped (empty): " . $skippedCount);
            $this->info("  Errors: " . $errorCount);
            $this->newLine();
            
            $this->info("Sync completed!");
            
            return Command::SUCCESS;
            
        } catch (\Exception $e) {
            $this->error('Error syncing Kissflow data: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }

    /**
     * Map Kissflow data to PO sheet row
     */
    private function mapToPORow($kissflowData)
    {
        $row = [];
        
        // ePR_Form_Number : ID
        $row['ID'] = $this->getNestedValue($kissflowData, 'ePR_Form_Number', '');
        
        // Use ID as Name if Name column is expected
        $row['Name'] = $row['ID'];
        
        // GL_Code_1['Name'] : Budget Code
        $row['Budget Code'] = $this->getNestedValue($kissflowData, 'GL_Code_1.Name', '');
        
        // Budget_Subcode_1['SubCode'] : Subcode
        $row['Subcode'] = $this->getNestedValue($kissflowData, 'Budget_Subcode_1.SubCode', '');
        
        // Delivery_Location mapping: 'Whole School' -> 3, 'Secondary' -> 2, 'Primary' -> 1
        $deliveryLocation = $this->getNestedValue($kissflowData, 'Delivery_Location', '');
        $row['Location'] = $this->mapDeliveryLocation($deliveryLocation);
        
        // Supplier_Company_Name : Vendor
        $row['Vendor'] = $this->getNestedValue($kissflowData, 'Supplier_Company_Name', '');
        
        // Table::Untitled_Table['_created_at'] : Timestamp
        // Table::Untitled_Table might be an array, get first item's _created_at
        $untitledTable = $this->getNestedValue($kissflowData, 'Table::Untitled_Table', null);
        if (is_array($untitledTable) && !empty($untitledTable)) {
            // Get first item's _created_at
            $firstItem = reset($untitledTable);
            $createdAt = is_array($firstItem) ? ($firstItem['_created_at'] ?? '') : '';
        } else {
            $createdAt = $this->getNestedValue($kissflowData, 'Table::Untitled_Table._created_at', '');
        }
        
        if (!empty($createdAt)) {
            // Format timestamp if needed
            $row['Timestamp'] = $this->formatTimestamp($createdAt);
        } else {
            $row['Timestamp'] = '';
        }
        
        // PO_Number : PO
        $row['PO'] = $this->getNestedValue($kissflowData, 'PO_Number', '');
        
        // Supplier_Currency : Currency
        $row['Currency'] = $this->getNestedValue($kissflowData, 'Supplier_Currency', '');
        
        return $row;
    }

    /**
     * Map Kissflow data to Items sheet rows (from array)
     */
    private function mapToItemsRows($kissflowData)
    {
        $rows = [];
        
        // Get the EPR number for linking
        $eprNumber = $this->getNestedValue($kissflowData, 'ePR_Form_Number', '');
        
        // Table::Model_DSakzWikms is an array containing items
        // Try multiple ways to access it
        $itemsArray = null;
        
        // Try direct access with Table:: prefix
        if (isset($kissflowData['Table::Model_DSakzWikms'])) {
            $itemsArray = $kissflowData['Table::Model_DSakzWikms'];
        }
        // Try without Table:: prefix
        elseif (isset($kissflowData['Model_DSakzWikms'])) {
            $itemsArray = $kissflowData['Model_DSakzWikms'];
        }
        // Try using the nested value function
        else {
            $itemsArray = $this->getNestedValue($kissflowData, 'Table::Model_DSakzWikms', null);
        }
        
        // Log for debugging
        if ($this->option('verbose')) {
            $this->line("  → Looking for Table::Model_DSakzWikms");
            $this->line("  → Available keys: " . (is_array($kissflowData) ? implode(', ', array_keys($kissflowData)) : 'not_array'));
            $this->line("  → Items array found: " . (is_array($itemsArray) ? 'yes (' . count($itemsArray) . ' items)' : 'no'));
        }
        
        if (!is_array($itemsArray) || empty($itemsArray)) {
            \Log::warning('Kissflow Items Array Not Found', [
                'available_keys' => is_array($kissflowData) ? array_keys($kissflowData) : 'not_array',
                'items_array_type' => gettype($itemsArray),
                'items_array_count' => is_array($itemsArray) ? count($itemsArray) : 0
            ]);
            return $rows;
        }
        
        foreach ($itemsArray as $index => $item) {
            $row = [];
            
            // ['Hidden_Table_EPR_Number']_{randomizeid} : ID
            // Generate unique ID: EPR_Number + random ID
            $hiddenEprNumber = $this->getNestedValue($item, 'Hidden_Table_EPR_Number', '');
            $randomId = uniqid();
            $row['ID'] = !empty($hiddenEprNumber) ? "{$hiddenEprNumber}_{$randomId}" : "{$eprNumber}_{$randomId}";
            
            // ['Hidden_Table_EPR_Number'] : EPR
            $row['EPR'] = !empty($hiddenEprNumber) ? $hiddenEprNumber : $eprNumber;
            
            // ['RFQ_Description'] : Name
            $row['Name'] = $this->getNestedValue($item, 'RFQ_Description', '');
            
            // ['RFQ_Quantity'] : Quantity
            $row['Quantity'] = $this->getNestedValue($item, 'RFQ_Quantity', '1');
            
            // ['RFQ_Unit_Price'] : Unit Price
            $row['Unit Price'] = $this->getNestedValue($item, 'RFQ_Unit_Price', '0');
            
            // ['KLASS_Item_Code'] : Reference
            $row['Reference'] = $this->getNestedValue($item, 'KLASS_Item_Code', '');

            // discount
            $row['Discount'] = $this->getNestedValue($item, 'Enter_Discount_Amount', '0');
            
            $rows[] = $row;
        }
        
        return $rows;
    }

    /**
     * Map delivery location to numeric value
     */
    private function mapDeliveryLocation($location)
    {
        $location = trim($location);
        
        if (stripos($location, 'Whole School') !== false) {
            return '3';
        } elseif (stripos($location, 'Secondary') !== false) {
            return '2';
        } elseif (stripos($location, 'Primary') !== false) {
            return '1';
        }
        
        return $location; // Return original if no match
    }

    /**
     * Get nested value from array using dot notation or array notation
     * Handles paths like: 'GL_Code_1.Name', 'Table::Model_DSakzWikms', etc.
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
}

