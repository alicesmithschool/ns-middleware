<?php

namespace App\Console\Commands\NetSuite;

use App\Services\GoogleSheetsService;
use App\Services\KissflowService;
use Illuminate\Console\Command;

class SyncAfterDiscountToItems extends Command
{
    protected $signature = 'kissflow:sync-after-discount {--dry-run : Show what would be updated without making changes}';
    protected $description = 'Sync Enter_Discount_Amount from Kissflow to Items sheet column G. Finds PO via EPR and populates item discount';

    public function handle(GoogleSheetsService $sheetsService, KissflowService $kissflowService)
    {
        $isDryRun = $this->option('dry-run');
        
        if ($isDryRun) {
            $this->info('DRY RUN MODE: No changes will be made');
        }
        
        $this->info('Starting discount value sync to Items sheet...');

        try {
            // Read Items sheet
            $this->info('Reading Items sheet...');
            $itemRows = $sheetsService->readSheet('Items');
            
            if (empty($itemRows) || count($itemRows) < 2) {
                $this->warn('No data found in Items sheet (need at least header + 1 row)');
                return Command::SUCCESS;
            }
            
            // Get headers
            $headers = array_map('trim', $itemRows[0]);
            $headerMap = array_flip($headers);
            
            // Find required columns
            $eprColumnIndex = null;
            foreach (['EPR', 'ID', 'EPR ID'] as $col) {
                if (isset($headerMap[$col])) {
                    $eprColumnIndex = $headerMap[$col];
                    break;
                }
            }
            
            $nameColumnIndex = null;
            foreach (['Name', 'Item Name', 'Description'] as $col) {
                if (isset($headerMap[$col])) {
                    $nameColumnIndex = $headerMap[$col];
                    break;
                }
            }
            
            $quantityColumnIndex = null;
            foreach (['Quantity', 'Qty', 'QTY'] as $col) {
                if (isset($headerMap[$col])) {
                    $quantityColumnIndex = $headerMap[$col];
                    break;
                }
            }
            
            $unitPriceColumnIndex = null;
            foreach (['Unit Price', 'Price', 'Rate', 'Unit Cost'] as $col) {
                if (isset($headerMap[$col])) {
                    $unitPriceColumnIndex = $headerMap[$col];
                    break;
                }
            }
            
            // Validate required columns
            if ($eprColumnIndex === null) {
                $this->error("EPR column not found in Items sheet. Expected one of: EPR, ID, EPR ID");
                $this->error("Available columns: " . implode(', ', $headers));
                return Command::FAILURE;
            }
            
            if ($nameColumnIndex === null) {
                $this->error("Name column not found in Items sheet. Expected one of: Name, Item Name, Description");
                return Command::FAILURE;
            }
            
            if ($quantityColumnIndex === null) {
                $this->error("Quantity column not found in Items sheet. Expected one of: Quantity, Qty, QTY");
                return Command::FAILURE;
            }
            
            if ($unitPriceColumnIndex === null) {
                $this->error("Unit Price column not found in Items sheet. Expected one of: Unit Price, Price, Rate, Unit Cost");
                return Command::FAILURE;
            }
            
            $this->info("Using columns - EPR: column " . ($eprColumnIndex + 1) . ", Name: column " . ($nameColumnIndex + 1) . ", Quantity: column " . ($quantityColumnIndex + 1) . ", Unit Price: column " . ($unitPriceColumnIndex + 1));
            
            // Group items by EPR
            $this->info('Grouping items by EPR...');
            $itemsByEPR = [];
            $itemRowNumbers = []; // Track row numbers for each item
            
            for ($i = 1; $i < count($itemRows); $i++) {
                $row = $itemRows[$i];
                if (empty($row) || !isset($row[$eprColumnIndex])) {
                    continue;
                }
                
                $epr = trim($row[$eprColumnIndex] ?? '');
                if (empty($epr)) {
                    continue;
                }
                
                if (!isset($itemsByEPR[$epr])) {
                    $itemsByEPR[$epr] = [];
                }
                
                $itemData = [
                    'row_number' => $i + 1, // 1-based row number
                    'name' => trim($row[$nameColumnIndex] ?? ''),
                    'quantity' => $this->parseNumeric($row[$quantityColumnIndex] ?? '0'),
                    'unit_price' => $this->parseNumeric($row[$unitPriceColumnIndex] ?? '0'),
                ];
                
                $itemsByEPR[$epr][] = $itemData;
                $itemRowNumbers[$epr][] = $i + 1;
            }
            
            $this->info("Found " . count($itemsByEPR) . " unique EPR(s) with " . array_sum(array_map('count', $itemsByEPR)) . " total item(s)");
            
            // Process each EPR
            $updates = []; // Array of ['row' => rowNumber, 'value' => afterDiscountValue]
            $successCount = 0;
            $errorCount = 0;
            $skippedCount = 0;
            
            $progressBar = $this->output->createProgressBar(count($itemsByEPR));
            $progressBar->start();
            
            foreach ($itemsByEPR as $epr => $items) {
                try {
                    $this->newLine();
                    $this->line("  Processing EPR: {$epr} (" . count($items) . " item(s))");
                    
                    // Search for Kissflow ID by EPR
                    $searchResult = $kissflowService->searchByEPRNumber($epr);
                    
                    if (!$searchResult || !isset($searchResult['id'])) {
                        $this->warn("    ✗ No Kissflow ID found for EPR: {$epr}");
                        $errorCount++;
                        $progressBar->advance();
                        continue;
                    }
                    
                    $kissflowId = $searchResult['id'];
                    $this->line("    → Found Kissflow ID: {$kissflowId}");
                    
                    // Fetch full Kissflow data
                    $kissflowData = $kissflowService->getItemById($kissflowId);
                    
                    if (!$kissflowData) {
                        $this->warn("    ✗ No data found for Kissflow ID: {$kissflowId}");
                        $errorCount++;
                        $progressBar->advance();
                        continue;
                    }
                    
                    // Get items array from Kissflow data
                    $kissflowItemsArray = $this->getKissflowItemsArray($kissflowData);
                    
                    if (empty($kissflowItemsArray)) {
                        $this->warn("    ⚠ No items found in Kissflow data for EPR: {$epr}");
                        $skippedCount++;
                        $progressBar->advance();
                        continue;
                    }
                    
                    $this->line("    → Found " . count($kissflowItemsArray) . " item(s) in Kissflow data");
                    
                    // Match sheet items with Kissflow items and get discount
                    foreach ($items as $sheetItem) {
                        $matched = false;
                        
                        // Try to match by name
                        foreach ($kissflowItemsArray as $kissflowItem) {
                            $kissflowName = $this->getNestedValue($kissflowItem, 'RFQ_Description', '');
                            
                            // Match if names are similar (case-insensitive, trimmed)
                            if (strcasecmp(trim($sheetItem['name']), trim($kissflowName)) === 0) {
                                $matched = true;
                                
                                // Get Enter_Discount_Amount from Kissflow (item-specific discount)
                                $discountRaw = $this->getNestedValue($kissflowItem, 'Enter_Discount_Amount', '0');
                                
                                // Check if discount is a percentage (contains %)
                                $discount = $this->parseDiscount($discountRaw, $sheetItem['unit_price'], $sheetItem['quantity']);
                                
                                $updates[] = [
                                    'row' => $sheetItem['row_number'],
                                    'value' => $discount,
                                    'epr' => $epr,
                                    'name' => $sheetItem['name'],
                                    'discount' => $discount,
                                    'discount_raw' => $discountRaw,
                                ];
                                
                                $discountDisplay = is_numeric($discountRaw) && strpos((string)$discountRaw, '%') === false 
                                    ? $discount 
                                    : "{$discountRaw} → {$discount}";
                                $this->line("      ✓ Row {$sheetItem['row_number']}: '{$sheetItem['name']}' → Discount: {$discountDisplay}");
                                break;
                            }
                        }
                        
                        if (!$matched) {
                            $this->warn("      ⚠ Row {$sheetItem['row_number']}: '{$sheetItem['name']}' - No matching item found in Kissflow data");
                            $skippedCount++;
                        }
                    }
                    
                    $successCount++;
                    
                    // Small delay to avoid rate limiting
                    usleep(200000); // 0.2 seconds
                    
                } catch (\Exception $e) {
                    $this->line("\n    ✗ Error processing EPR {$epr}: " . $e->getMessage());
                    $errorCount++;
                }
                
                $progressBar->advance();
            }
            
            $progressBar->finish();
            $this->newLine(2);
            
            // Show summary
            $this->info("Summary:");
            $this->info("  EPRs processed: " . count($itemsByEPR));
            $this->info("  Successfully matched: " . $successCount);
            $this->info("  Items to update: " . count($updates));
            $this->info("  Skipped: " . $skippedCount);
            $this->info("  Errors: " . $errorCount);
            
            // Show preview of updates
            if (!empty($updates) && $this->option('verbose')) {
                $this->newLine();
                $this->info("Preview of updates (first 10):");
                foreach (array_slice($updates, 0, 10) as $update) {
                    $this->line("  Row {$update['row']}: '{$update['name']}' → {$update['value']}");
                }
                if (count($updates) > 10) {
                    $this->line("  ... and " . (count($updates) - 10) . " more");
                }
            }
            
            // Apply updates if not dry run
            if (!$isDryRun && !empty($updates)) {
                $this->newLine();
                $this->info("Applying updates to Items sheet column G...");
                
                // Column G is index 6 (0-based) or 7 (1-based)
                $columnLetter = $this->columnIndexToLetter(6);
                
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
                        $sheetsService->updateRange('Items', $rangeStr, $values);
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
                $this->info("✓ Successfully updated {$updatedCount} cell(s) in column G");
            } elseif ($isDryRun && !empty($updates)) {
                $this->newLine();
                $this->warn("DRY RUN: " . count($updates) . " cell(s) would be updated in column G (use without --dry-run to apply changes)");
            }
            
            $this->info("Sync completed!");
            
            return Command::SUCCESS;
            
        } catch (\Exception $e) {
            $this->error('Error syncing discount values: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }

    /**
     * Get items array from Kissflow data
     */
    private function getKissflowItemsArray($kissflowData)
    {
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
        
        if (is_array($itemsArray) && !empty($itemsArray)) {
            return $itemsArray;
        }
        
        return [];
    }

    /**
     * Get nested value from array using dot notation or array notation
     * Handles paths like: 'RFQ_Description', 'Enter_Discount_Amount', etc.
     */
    private function getNestedValue($data, $path, $default = null)
    {
        if (empty($path)) {
            return $default;
        }
        
        // First, try direct access with the full path
        if (is_array($data) && isset($data[$path])) {
            return $data[$path];
        }
        
        // Handle Table:: prefix (special Kissflow notation)
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
            // Handle array bracket notation
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
     * Parse discount value, handling both absolute amounts and percentages
     * 
     * @param mixed $discountValue The discount value from Kissflow (could be "20%", "20", etc.)
     * @param float $unitPrice The unit price from the sheet
     * @param float $quantity The quantity from the sheet
     * @return float The actual discount amount (not percentage)
     */
    private function parseDiscount($discountValue, $unitPrice, $quantity)
    {
        $discountStr = trim((string) $discountValue);
        
        // Check if it's a percentage (contains %)
        if (stripos($discountStr, '%') !== false) {
            // Extract the percentage number
            $percentageStr = str_replace('%', '', $discountStr);
            $percentageStr = str_replace(',', '', $percentageStr);
            $percentageStr = preg_replace('/[^0-9.-]/', '', $percentageStr);
            
            if (!empty($percentageStr) && is_numeric($percentageStr)) {
                $percentage = (float) $percentageStr;
                
                // Calculate actual discount: (unit_price * quantity) * (percentage / 100)
                $totalBeforeDiscount = $unitPrice * $quantity;
                $actualDiscount = $totalBeforeDiscount * ($percentage / 100);
                
                return $actualDiscount;
            }
        }
        
        // Not a percentage, parse as absolute amount
        return $this->parseNumeric($discountValue);
    }

    /**
     * Parse numeric value from string, handling commas and other formatting
     */
    private function parseNumeric($value)
    {
        if (is_numeric($value)) {
            return (float) $value;
        }
        
        // Remove commas and other formatting
        $cleaned = str_replace(',', '', trim((string) $value));
        $cleaned = preg_replace('/[^0-9.-]/', '', $cleaned);
        
        if (empty($cleaned) || !is_numeric($cleaned)) {
            return 0.0;
        }
        
        return (float) $cleaned;
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

