<?php

namespace App\Console\Commands\NetSuite;

use App\Models\NetSuiteItem;
use App\Services\GoogleSheetsService;
use App\Services\NetSuiteService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class UpdatePOItemPricesAfterDiscount extends Command
{
    protected $signature = 'netsuite:update-po-prices-after-discount {--dry-run : Show what would be changed without actually updating} {--po= : Update specific PO only (by transaction ID)}';
    protected $description = 'Update PO item unit prices in NetSuite after applying discount. Calculates: (unit_price * quantity - discount) / quantity';

    public function handle(GoogleSheetsService $sheetsService, NetSuiteService $netSuiteService)
    {
        $this->info('Starting PO item price update after discount...');
        $isDryRun = $this->option('dry-run');
        $specificPO = $this->option('po');
        $isSandbox = config('netsuite.environment') === 'sandbox';

        if ($isDryRun) {
            $this->warn('DRY RUN MODE - No changes will be made to NetSuite');
        }

        if ($specificPO) {
            $this->info("Updating only PO: {$specificPO}");
        }

        try {
            // Read Synced sheet
            $this->info('Reading Synced sheet...');
            $syncedRows = $sheetsService->readSheet('Synced');

            if (empty($syncedRows) || count($syncedRows) < 2) {
                $this->warn('No data found in Synced sheet (need at least header + 1 row)');
                return Command::SUCCESS;
            }

            // Get headers from Synced sheet
            $syncedHeaders = array_map('trim', $syncedRows[0]);
            $syncedHeaderMap = array_flip($syncedHeaders);

            // Find PO and ID columns in Synced sheet
            $poColumn = null;
            foreach (['PO', 'Transaction ID', 'TranId', 'tranId'] as $col) {
                if (isset($syncedHeaderMap[$col])) {
                    $poColumn = $col;
                    break;
                }
            }

            if (!$poColumn) {
                $this->error("Could not find PO column in Synced sheet. Expected one of: PO, Transaction ID, TranId");
                return Command::FAILURE;
            }

            // Find ID column (EPR ID from original sheet)
            $idColumn = null;
            foreach (['ID', 'EPR', 'EPR ID'] as $col) {
                if (isset($syncedHeaderMap[$col])) {
                    $idColumn = $col;
                    break;
                }
            }

            if (!$idColumn) {
                $this->error("Could not find ID column in Synced sheet. Expected one of: ID, EPR, EPR ID");
                return Command::FAILURE;
            }

            $this->info("Using PO column: '{$poColumn}' and ID column: '{$idColumn}'");

            // Read Items sheet
            $this->info('Reading Items sheet...');
            $itemRows = $sheetsService->readSheet('Items');

            if (empty($itemRows) || count($itemRows) < 2) {
                $this->warn('No data found in Items sheet');
                return Command::SUCCESS;
            }

            $itemHeaders = array_map('trim', $itemRows[0]);
            $itemHeaderMap = array_flip($itemHeaders);

            // Find required columns
            $eprColumn = null;
            foreach (['EPR', 'ID', 'EPR ID'] as $col) {
                if (isset($itemHeaderMap[$col])) {
                    $eprColumn = $col;
                    break;
                }
            }

            $nameColumn = null;
            foreach (['Name', 'Item Name', 'Description'] as $col) {
                if (isset($itemHeaderMap[$col])) {
                    $nameColumn = $col;
                    break;
                }
            }

            $quantityColumn = null;
            foreach (['Quantity', 'Qty', 'QTY'] as $col) {
                if (isset($itemHeaderMap[$col])) {
                    $quantityColumn = $col;
                    break;
                }
            }

            $unitPriceColumn = null;
            foreach (['Unit Price', 'Price', 'Rate', 'Unit Cost'] as $col) {
                if (isset($itemHeaderMap[$col])) {
                    $unitPriceColumn = $col;
                    break;
                }
            }

            $discountColumn = null;
            // Column G is index 6 (0-based)
            if (isset($itemHeaders[6])) {
                $discountColumn = $itemHeaders[6];
            } else {
                // Try to find by name
                foreach (['Discount', 'Enter_Discount_Amount', 'Discount Amount'] as $col) {
                    if (isset($itemHeaderMap[$col])) {
                        $discountColumn = $col;
                        break;
                    }
                }
            }

            // Validate required columns
            if (!$eprColumn) {
                $this->error("EPR column not found in Items sheet. Expected one of: EPR, ID, EPR ID");
                return Command::FAILURE;
            }

            if (!$nameColumn) {
                $this->error("Name column not found in Items sheet. Expected one of: Name, Item Name, Description");
                return Command::FAILURE;
            }

            if (!$quantityColumn) {
                $this->error("Quantity column not found in Items sheet. Expected one of: Quantity, Qty, QTY");
                return Command::FAILURE;
            }

            if (!$unitPriceColumn) {
                $this->error("Unit Price column not found in Items sheet. Expected one of: Unit Price, Price, Rate, Unit Cost");
                return Command::FAILURE;
            }

            if (!$discountColumn) {
                $this->error("Discount column not found in Items sheet (expected column G or named 'Discount')");
                return Command::FAILURE;
            }

            $this->info("Using columns - EPR: '{$eprColumn}', Name: '{$nameColumn}', Qty: '{$quantityColumn}', Unit Price: '{$unitPriceColumn}', Discount: '{$discountColumn}'");

            // Group items by EPR
            $this->info('Grouping items by EPR...');
            $itemsByEPR = [];

            for ($i = 1; $i < count($itemRows); $i++) {
                $row = $itemRows[$i];
                if (empty($row) || !isset($row[$itemHeaderMap[$eprColumn]])) {
                    continue;
                }

                $eprId = trim($row[$itemHeaderMap[$eprColumn]]);
                if (empty($eprId)) {
                    continue;
                }

                if (!isset($itemsByEPR[$eprId])) {
                    $itemsByEPR[$eprId] = [];
                }

                $itemName = trim($row[$itemHeaderMap[$nameColumn]] ?? '');
                $quantity = isset($row[$itemHeaderMap[$quantityColumn]]) ? trim($row[$itemHeaderMap[$quantityColumn]]) : '';
                $unitPrice = isset($row[$itemHeaderMap[$unitPriceColumn]]) ? trim($row[$itemHeaderMap[$unitPriceColumn]]) : '';
                $discount = isset($row[$itemHeaderMap[$discountColumn]]) ? trim($row[$itemHeaderMap[$discountColumn]]) : '';

                // Skip if name is empty
                if (empty($itemName)) {
                    continue;
                }

                // Parse values as floats
                $quantityFloat = !empty($quantity) ? $this->parseNumeric($quantity) : 1.0;
                $unitPriceFloat = !empty($unitPrice) ? $this->parseNumeric($unitPrice) : 0.0;
                $discountFloat = !empty($discount) ? $this->parseNumeric($discount) : 0.0;

                // Skip items with zero discount to save time
                if (abs($discountFloat) < 0.01) {
                    continue;
                }

                // Calculate adjusted unit price: (unit_price * quantity - discount) / quantity
                $totalBeforeDiscount = $unitPriceFloat * $quantityFloat;
                $totalAfterDiscount = $totalBeforeDiscount - $discountFloat;
                $adjustedUnitPrice = $quantityFloat > 0 ? ($totalAfterDiscount / $quantityFloat) : $unitPriceFloat;

                $itemsByEPR[$eprId][] = [
                    'name' => $itemName,
                    'quantity' => $quantityFloat,
                    'unit_price' => $unitPriceFloat,
                    'discount' => $discountFloat,
                    'adjusted_unit_price' => $adjustedUnitPrice,
                    'total_before_discount' => $totalBeforeDiscount,
                    'total_after_discount' => $totalAfterDiscount,
                ];
            }

            $totalItemsWithDiscount = array_sum(array_map('count', $itemsByEPR));
            $this->info("Found " . count($itemsByEPR) . " unique EPR(s) with " . $totalItemsWithDiscount . " item(s) that have discounts (skipped items with zero discount)");

            // Process Synced sheet rows
            $dataRows = array_slice($syncedRows, 1);
            $totalRows = count($dataRows);
            $progressBar = $this->output->createProgressBar($totalRows);
            $progressBar->start();

            $updatedCount = 0;
            $skippedCount = 0;
            $errorCount = 0;

            foreach ($dataRows as $index => $row) {
                $rowNumber = $index + 2;

                if (empty($row) || !isset($row[$syncedHeaderMap[$poColumn]])) {
                    $skippedCount++;
                    $progressBar->advance();
                    continue;
                }

                $poTranId = trim($row[$syncedHeaderMap[$poColumn]] ?? '');
                $eprId = trim($row[$syncedHeaderMap[$idColumn]] ?? '');

                if (empty($poTranId)) {
                    $skippedCount++;
                    $progressBar->advance();
                    continue;
                }

                // If specific PO requested, skip others
                if ($specificPO && $poTranId !== $specificPO) {
                    $skippedCount++;
                    $progressBar->advance();
                    continue;
                }

                // Check if we have items for this EPR (only items with non-zero discount)
                if (empty($eprId) || !isset($itemsByEPR[$eprId]) || empty($itemsByEPR[$eprId])) {
                    $this->newLine();
                    $this->line("  Row {$rowNumber}: PO '{$poTranId}' (EPR: '{$eprId}') - No items with discount found, skipping");
                    $skippedCount++;
                    $progressBar->advance();
                    continue;
                }

                $sheetItems = $itemsByEPR[$eprId];

                $this->newLine();
                $this->info("  Row {$rowNumber}: PO '{$poTranId}' (EPR: '{$eprId}') - Found " . count($sheetItems) . " item(s) with discount in Items sheet");
                
                // Show sheet items with discounts for debugging
                if ($this->option('verbose')) {
                    foreach ($sheetItems as $idx => $si) {
                        $this->line("    Sheet item " . ($idx + 1) . ": '{$si['name']}' - Unit Price: {$si['unit_price']}, Qty: {$si['quantity']}, Discount: {$si['discount']}, Adjusted: {$si['adjusted_unit_price']}");
                    }
                }

                try {
                    // Get PO from NetSuite
                    $poRecord = $netSuiteService->getPurchaseOrderByTranId($poTranId);

                    if (!$poRecord) {
                        $this->error("  ✗ Could not find PO '{$poTranId}' in NetSuite");
                        $errorCount++;
                        $progressBar->advance();
                        continue;
                    }

                    $internalId = $poRecord->internalId ?? null;
                    if (!$internalId) {
                        $this->error("  ✗ PO '{$poTranId}' missing internalId");
                        $errorCount++;
                        $progressBar->advance();
                        continue;
                    }

                    // Get existing item list from PO
                    $existingItems = [];
                    if (isset($poRecord->itemList) && isset($poRecord->itemList->item)) {
                        $existingItems = is_array($poRecord->itemList->item)
                            ? $poRecord->itemList->item
                            : [$poRecord->itemList->item];
                    }

                    // Get existing expense list from PO
                    $existingExpenses = [];
                    if (isset($poRecord->expenseList) && isset($poRecord->expenseList->expense)) {
                        $existingExpenses = is_array($poRecord->expenseList->expense)
                            ? $poRecord->expenseList->expense
                            : [$poRecord->expenseList->expense];
                    }

                    if (empty($existingItems) && empty($existingExpenses)) {
                        $this->warn("  ⚠ PO '{$poTranId}' has no items or expenses in NetSuite");
                        $skippedCount++;
                        $progressBar->advance();
                        continue;
                    }

                    // Match sheet items with NetSuite items and expenses
                    $updatedItems = [];
                    $updatedExpenses = [];
                    $comparisons = [];
                    $expenseComparisons = [];
                    $changesDetected = false;
                    $lineNumber = 0;

                    foreach ($existingItems as $existingItem) {
                        $lineNumber++;
                        $netsuiteItemId = $existingItem->item->internalId ?? null;
                        $netsuiteItemName = $existingItem->item->name ?? '';
                        $netsuiteDescription = $existingItem->description ?? '';
                        $currentQuantity = $existingItem->quantity ?? 0;
                        $currentRate = $existingItem->rate ?? 0;

                        // Try to find matching item in sheet
                        $matchedSheetItem = null;
                        $matchReason = '';

                        // Get NetSuite item from database for better matching
                        $netsuiteItem = null;
                        if ($netsuiteItemId) {
                            $netsuiteItem = NetSuiteItem::where('netsuite_id', $netsuiteItemId)
                                ->where('is_sandbox', $isSandbox)
                                ->first();
                        }

                        // Try multiple matching strategies - prioritize Description field
                        foreach ($sheetItems as $sheetItem) {
                            $sheetItemName = trim($sheetItem['name']);
                            $netSuiteDescriptionTrimmed = trim($netsuiteDescription);
                            $netSuiteItemNameTrimmed = trim($netsuiteItemName);

                            // Strategy 1: Match by Description (primary method)
                            if (!empty($netSuiteDescriptionTrimmed)) {
                                // Exact description match
                                if (strcasecmp($netSuiteDescriptionTrimmed, $sheetItemName) === 0) {
                                    $matchedSheetItem = $sheetItem;
                                    $matchReason = 'exact description match';
                                    break;
                                }
                                
                                // Description contains sheet name
                                if (stripos($netSuiteDescriptionTrimmed, $sheetItemName) !== false) {
                                    $matchedSheetItem = $sheetItem;
                                    $matchReason = 'description contains sheet name';
                                    break;
                                }
                                
                                // Sheet name contains description
                                if (stripos($sheetItemName, $netSuiteDescriptionTrimmed) !== false) {
                                    $matchedSheetItem = $sheetItem;
                                    $matchReason = 'sheet name contains description';
                                    break;
                                }
                            }

                            // Strategy 2: Fallback to item name matching (if description didn't match)
                            if (!$matchedSheetItem) {
                                // Exact name match (case-insensitive)
                                if (strcasecmp($netSuiteItemNameTrimmed, $sheetItemName) === 0) {
                                    $matchedSheetItem = $sheetItem;
                                    $matchReason = 'exact name match';
                                    break;
                                }

                                // NetSuite name contains sheet name
                                if (stripos($netSuiteItemNameTrimmed, $sheetItemName) !== false) {
                                    $matchedSheetItem = $sheetItem;
                                    $matchReason = 'NS name contains sheet name';
                                    break;
                                }

                                // Sheet name contains NetSuite name
                                if (stripos($sheetItemName, $netSuiteItemNameTrimmed) !== false) {
                                    $matchedSheetItem = $sheetItem;
                                    $matchReason = 'sheet name contains NS name';
                                    break;
                                }

                                // Strategy 3: Match using database item name if available
                                if ($netsuiteItem) {
                                    $dbItemName = trim($netsuiteItem->name ?? '');
                                    if (!empty($dbItemName)) {
                                        if (strcasecmp($dbItemName, $sheetItemName) === 0) {
                                            $matchedSheetItem = $sheetItem;
                                            $matchReason = 'exact DB name match';
                                            break;
                                        }
                                        if (stripos($dbItemName, $sheetItemName) !== false) {
                                            $matchedSheetItem = $sheetItem;
                                            $matchReason = 'DB name contains sheet name';
                                            break;
                                        }
                                        if (stripos($sheetItemName, $dbItemName) !== false) {
                                            $matchedSheetItem = $sheetItem;
                                            $matchReason = 'sheet name contains DB name';
                                            break;
                                        }
                                    }
                                }
                            }
                        }

                        // Debug: Show what we're trying to match if verbose
                        if (!$matchedSheetItem && $this->option('verbose')) {
                            $descInfo = !empty($netsuiteDescription) ? " (Description: '{$netsuiteDescription}')" : '';
                            $this->line("      ⚠ No match for NetSuite item: '{$netsuiteItemName}'{$descInfo}");
                            $this->line("      Available sheet items:");
                            foreach ($sheetItems as $idx => $si) {
                                $this->line("        " . ($idx + 1) . ". '{$si['name']}' (Discount: {$si['discount']})");
                            }
                        }

                        // Create updated item
                        $poi = new \PurchaseOrderItem();
                        $poi->line = $lineNumber;
                        $poi->item = $existingItem->item;

                        if ($matchedSheetItem) {
                            // Use adjusted unit price after discount
                            $newQuantity = $matchedSheetItem['quantity'];
                            $newRate = $matchedSheetItem['adjusted_unit_price'];

                            $poi->quantity = $newQuantity;
                            $poi->rate = $newRate;

                            // Check if rate changed
                            $rateChanged = abs($currentRate - $newRate) > 0.01;
                            $qtyChanged = abs($currentQuantity - $newQuantity) > 0.01;

                            if ($rateChanged || $qtyChanged) {
                                $changesDetected = true;
                            }

                            $comparisons[] = [
                                'item_name' => $netsuiteItemName,
                                'current_qty' => $currentQuantity,
                                'new_qty' => $newQuantity,
                                'qty_changed' => $qtyChanged,
                                'current_rate' => $currentRate,
                                'new_rate' => $newRate,
                                'rate_changed' => $rateChanged,
                                'original_unit_price' => $matchedSheetItem['unit_price'],
                                'discount' => $matchedSheetItem['discount'],
                                'total_before' => $matchedSheetItem['total_before_discount'],
                                'total_after' => $matchedSheetItem['total_after_discount'],
                                'match_reason' => $matchReason,
                            ];
                        } else {
                            // No match in Items sheet - keep existing NetSuite values
                            $poi->quantity = $currentQuantity;
                            $poi->rate = $currentRate;

                            $comparisons[] = [
                                'item_name' => $netsuiteItemName,
                                'current_qty' => $currentQuantity,
                                'new_qty' => $currentQuantity,
                                'qty_changed' => false,
                                'current_rate' => $currentRate,
                                'new_rate' => $currentRate,
                                'rate_changed' => false,
                                'no_match' => true,
                            ];
                        }

                        // Preserve other fields
                        if (isset($existingItem->description)) $poi->description = $existingItem->description;
                        if (isset($existingItem->department)) $poi->department = $existingItem->department;
                        if (isset($existingItem->location)) $poi->location = $existingItem->location;

                        $updatedItems[] = $poi;
                    }

                    // Process expenses
                    $expenseLineNumber = 0;
                    foreach ($existingExpenses as $existingExpense) {
                        $expenseLineNumber++;
                        $currentMemo = $existingExpense->memo ?? '';
                        $currentAmount = $existingExpense->amount ?? 0;
                        $expenseAccountName = $existingExpense->account->name ?? 'Unknown';

                        // Try to find matching expense in sheet
                        $matchedSheetItem = null;
                        $matchReason = '';

                        // Remove quantity prefix from memo if present (format: "x unit - memo")
                        $memoWithoutPrefix = preg_replace('/^\d+(\.\d+)?\s+unit\s+-\s+/i', '', $currentMemo);

                        // Try multiple matching strategies
                        foreach ($sheetItems as $sheetItem) {
                            $sheetItemName = trim($sheetItem['name']);

                            // Strategy 1: Memo contains sheet name
                            if (stripos($memoWithoutPrefix, $sheetItemName) !== false ||
                                stripos($sheetItemName, $memoWithoutPrefix) !== false) {
                                $matchedSheetItem = $sheetItem;
                                $matchReason = 'memo/name match';
                                break;
                            }

                            // Strategy 2: Original memo contains sheet name
                            if (stripos($currentMemo, $sheetItemName) !== false ||
                                stripos($sheetItemName, $currentMemo) !== false) {
                                $matchedSheetItem = $sheetItem;
                                $matchReason = 'memo/name match';
                                break;
                            }
                        }

                        // Create updated expense
                        $updatedExpense = clone $existingExpense;

                        if ($matchedSheetItem) {
                            // For expenses: use adjusted amount from sheet (unit_price * quantity - discount)
                            // This matches the item calculation but expenses use amount instead of rate
                            $newAmount = $matchedSheetItem['total_after_discount'];
                            
                            // Ensure non-negative
                            if ($newAmount < 0) {
                                $newAmount = 0;
                            }

                            $updatedExpense->amount = $newAmount;

                            // Check if amount changed
                            $amountChanged = abs($currentAmount - $newAmount) > 0.01;

                            if ($amountChanged) {
                                $changesDetected = true;
                            }

                            $expenseComparisons[] = [
                                'account' => $expenseAccountName,
                                'memo' => substr($currentMemo, 0, 40),
                                'current_amount' => $currentAmount,
                                'new_amount' => $newAmount,
                                'amount_changed' => $amountChanged,
                                'original_unit_price' => $matchedSheetItem['unit_price'],
                                'quantity' => $matchedSheetItem['quantity'],
                                'discount' => $matchedSheetItem['discount'],
                                'total_before' => $matchedSheetItem['total_before_discount'],
                                'total_after' => $matchedSheetItem['total_after_discount'],
                                'match_reason' => $matchReason,
                            ];
                        } else {
                            // No match - keep existing amount
                            $expenseComparisons[] = [
                                'account' => $expenseAccountName,
                                'memo' => substr($currentMemo, 0, 40),
                                'current_amount' => $currentAmount,
                                'new_amount' => $currentAmount,
                                'amount_changed' => false,
                                'no_match' => true,
                            ];
                        }

                        // Preserve other fields
                        if (isset($existingExpense->department)) $updatedExpense->department = $existingExpense->department;
                        if (isset($existingExpense->location)) $updatedExpense->location = $existingExpense->location;

                        $updatedExpenses[] = $updatedExpense;
                    }

                    // Display comparison table for items
                    if (!empty($comparisons)) {
                        $this->newLine();
                        $this->info("  Comparison - Items (NetSuite vs Adjusted Prices):");
                        $this->table(
                            ['Item Name', 'Current Rate', 'New Rate', 'Original Price', 'Discount', 'Total Before', 'Total After', 'Status'],
                            array_map(function($comp) {
                                $status = [];
                                if (isset($comp['no_match'])) {
                                    $status[] = '❌ NO MATCH';
                                } else {
                                    if ($comp['rate_changed']) $status[] = '💰 Rate';
                                    if ($comp['qty_changed']) $status[] = '📊 Qty';
                                    if (!$comp['rate_changed'] && !$comp['qty_changed']) $status[] = '✓ OK';
                                    if (isset($comp['match_reason'])) {
                                        $status[] = "({$comp['match_reason']})";
                                    }
                                }

                                return [
                                    substr($comp['item_name'], 0, 30),
                                    number_format($comp['current_rate'], 2),
                                    number_format($comp['new_rate'], 2),
                                    isset($comp['original_unit_price']) ? number_format($comp['original_unit_price'], 2) : '-',
                                    isset($comp['discount']) ? number_format($comp['discount'], 2) : '-',
                                    isset($comp['total_before']) ? number_format($comp['total_before'], 2) : '-',
                                    isset($comp['total_after']) ? number_format($comp['total_after'], 2) : '-',
                                    implode(' ', $status),
                                ];
                            }, $comparisons)
                        );
                    }

                    // Display comparison table for expenses
                    if (!empty($expenseComparisons)) {
                        $this->newLine();
                        $this->info("  Comparison - Expenses (NetSuite vs Adjusted Amounts):");
                        $this->table(
                            ['Account', 'Memo', 'Current Amount', 'New Amount', 'Unit Price', 'Qty', 'Discount', 'Total Before', 'Total After', 'Status'],
                            array_map(function($comp) {
                                $status = [];
                                if (isset($comp['no_match'])) {
                                    $status[] = '❌ NO MATCH';
                                } else {
                                    if ($comp['amount_changed']) $status[] = '💰 Amount';
                                    if (!$comp['amount_changed']) $status[] = '✓ OK';
                                    if (isset($comp['match_reason'])) {
                                        $status[] = "({$comp['match_reason']})";
                                    }
                                }

                                return [
                                    substr($comp['account'], 0, 25),
                                    substr($comp['memo'], 0, 30),
                                    number_format($comp['current_amount'], 2),
                                    number_format($comp['new_amount'], 2),
                                    isset($comp['original_unit_price']) ? number_format($comp['original_unit_price'], 2) : '-',
                                    isset($comp['quantity']) ? number_format($comp['quantity'], 2) : '-',
                                    isset($comp['discount']) ? number_format($comp['discount'], 2) : '-',
                                    isset($comp['total_before']) ? number_format($comp['total_before'], 2) : '-',
                                    isset($comp['total_after']) ? number_format($comp['total_after'], 2) : '-',
                                    implode(' ', $status),
                                ];
                            }, $expenseComparisons)
                        );
                    }

                    // Skip if no changes detected
                    if (!$changesDetected) {
                        $this->info("  → No changes needed, all rates/amounts already match adjusted prices");
                        $skippedCount++;
                        $progressBar->advance();
                        continue;
                    }

                    $itemChangesCount = count(array_filter($comparisons, function($c) {
                        return ($c['rate_changed'] ?? false) || ($c['qty_changed'] ?? false);
                    }));
                    
                    $expenseChangesCount = count(array_filter($expenseComparisons, function($c) {
                        return ($c['amount_changed'] ?? false);
                    }));

                    $totalChanges = $itemChangesCount + $expenseChangesCount;
                    if ($totalChanges > 0) {
                        $parts = [];
                        if ($itemChangesCount > 0) $parts[] = "{$itemChangesCount} item(s)";
                        if ($expenseChangesCount > 0) $parts[] = "{$expenseChangesCount} expense(s)";
                        $this->info("  → Changes detected: " . implode(" and ", $parts) . " will be updated");
                    }

                    if ($isDryRun) {
                        $this->warn("  [DRY RUN] Would update PO '{$poTranId}' with adjusted prices");
                        $updatedCount++;
                        $progressBar->advance();
                        continue;
                    }

                    // Update PO in NetSuite
                    $service = $netSuiteService->getService();

                    // Preserve critical fields
                    $entityRef = $poRecord->entity ?? null;

                    // Set updated item list
                    if (!empty($updatedItems)) {
                        $poRecord->itemList = new \PurchaseOrderItemList();
                        $poRecord->itemList->replaceAll = true;
                        $poRecord->itemList->item = $updatedItems;
                    }

                    // Set updated expense list
                    if (!empty($updatedExpenses)) {
                        $poRecord->expenseList = new \PurchaseOrderExpenseList();
                        $poRecord->expenseList->replaceAll = true;
                        $poRecord->expenseList->expense = $updatedExpenses;
                    }

                    // Ensure internalId is set
                    $poRecord->internalId = (string)$internalId;

                    // Ensure entity is preserved
                    if ($entityRef) {
                        $poRecord->entity = $entityRef;
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
                        $this->error("  ✗ {$errorMsg}");
                        $errorCount++;
                        $progressBar->advance();
                        continue;
                    }

                    $this->info("  ✓ Successfully updated PO '{$poTranId}'");
                    $updatedCount++;

                } catch (\Exception $e) {
                    $this->error("  ✗ Error processing PO '{$poTranId}': " . $e->getMessage());
                    Log::error('PO price update error', [
                        'po' => $poTranId,
                        'epr' => $eprId,
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString()
                    ]);
                    $errorCount++;
                }

                $progressBar->advance();
            }

            $progressBar->finish();
            $this->newLine(2);

            // Show summary
            $this->info("Summary:");
            $this->info("  Total POs processed: " . $totalRows);
            $this->info("  Successfully updated: " . $updatedCount);
            $this->info("  Skipped: " . $skippedCount);
            $this->info("  Errors: " . $errorCount);

            $this->info("Update completed!");

            return Command::SUCCESS;

        } catch (\Exception $e) {
            $this->error('Error updating PO prices: ' . $e->getMessage());
            Log::error('PO price update command error: ' . $e->getMessage());
            return Command::FAILURE;
        }
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
}

