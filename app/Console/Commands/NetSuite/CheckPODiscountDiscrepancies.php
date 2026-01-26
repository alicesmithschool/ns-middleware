<?php

namespace App\Console\Commands\NetSuite;

use App\Models\NetSuiteItem;
use App\Services\GoogleSheetsService;
use App\Services\NetSuiteService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class CheckPODiscountDiscrepancies extends Command
{
    protected $signature = 'netsuite:check-po-discount-discrepancies {--po= : Check specific PO only (by transaction ID)} {--epr= : Check specific EPR only}';
    protected $description = 'Check for discrepancies between NetSuite PO items/expenses and Items sheet after discount values';

    public function handle(GoogleSheetsService $sheetsService, NetSuiteService $netSuiteService)
    {
        $specificPO = $this->option('po');
        $specificEPR = $this->option('epr');
        $isSandbox = config('netsuite.environment') === 'sandbox';

        $this->info('Checking PO discount discrepancies...');

        try {
            // Read Synced sheet
            $this->info('Reading Synced sheet...');
            $syncedRows = $sheetsService->readSheet('Synced');

            if (empty($syncedRows) || count($syncedRows) < 2) {
                $this->warn('No data found in Synced sheet');
                return Command::SUCCESS;
            }

            // Get headers from Synced sheet
            $syncedHeaders = array_map('trim', $syncedRows[0]);
            $syncedHeaderMap = array_flip($syncedHeaders);

            // Find PO and ID columns
            $poColumn = null;
            foreach (['PO', 'Transaction ID', 'TranId', 'tranId'] as $col) {
                if (isset($syncedHeaderMap[$col])) {
                    $poColumn = $col;
                    break;
                }
            }

            $idColumn = null;
            foreach (['ID', 'EPR', 'EPR ID'] as $col) {
                if (isset($syncedHeaderMap[$col])) {
                    $idColumn = $col;
                    break;
                }
            }

            if (!$poColumn || !$idColumn) {
                $this->error("Could not find required columns in Synced sheet");
                return Command::FAILURE;
            }

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
            $eprColumn = $this->findColumn($itemHeaderMap, ['EPR', 'ID', 'EPR ID']);
            $nameColumn = $this->findColumn($itemHeaderMap, ['Name', 'Item Name', 'Description']);
            $quantityColumn = $this->findColumn($itemHeaderMap, ['Quantity', 'Qty', 'QTY']);
            $unitPriceColumn = $this->findColumn($itemHeaderMap, ['Unit Price', 'Price', 'Rate', 'Unit Cost']);
            
            $discountColumn = null;
            if (isset($itemHeaders[6])) {
                $discountColumn = $itemHeaders[6];
            } else {
                $discountColumn = $this->findColumn($itemHeaderMap, ['Discount', 'Enter_Discount_Amount', 'Discount Amount']);
            }

            if (!$eprColumn || !$nameColumn || !$quantityColumn || !$unitPriceColumn || !$discountColumn) {
                $this->error("Could not find required columns in Items sheet");
                return Command::FAILURE;
            }

            // Group items by EPR
            $itemsByEPR = [];
            for ($i = 1; $i < count($itemRows); $i++) {
                $row = $itemRows[$i];
                if (empty($row) || !isset($row[$itemHeaderMap[$eprColumn]])) {
                    continue;
                }

                $eprId = trim($row[$itemHeaderMap[$eprColumn]] ?? '');
                if (empty($eprId)) {
                    continue;
                }

                if (!isset($itemsByEPR[$eprId])) {
                    $itemsByEPR[$eprId] = [];
                }

                $itemName = trim($row[$itemHeaderMap[$nameColumn]] ?? '');
                $quantity = $this->parseNumeric($row[$itemHeaderMap[$quantityColumn]] ?? '0');
                $unitPrice = $this->parseNumeric($row[$itemHeaderMap[$unitPriceColumn]] ?? '0');
                $discount = $this->parseNumeric($row[$itemHeaderMap[$discountColumn]] ?? '0');

                if (empty($itemName)) {
                    continue;
                }

                // Calculate adjusted unit price
                $totalBeforeDiscount = $unitPrice * $quantity;
                $totalAfterDiscount = $totalBeforeDiscount - $discount;
                $adjustedUnitPrice = $quantity > 0 ? ($totalAfterDiscount / $quantity) : $unitPrice;

                $itemsByEPR[$eprId][] = [
                    'name' => $itemName,
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'discount' => $discount,
                    'adjusted_unit_price' => $adjustedUnitPrice,
                    'total_before_discount' => $totalBeforeDiscount,
                    'total_after_discount' => $totalAfterDiscount,
                ];
            }

            $this->info("Found " . count($itemsByEPR) . " unique EPR(s) with items in Items sheet");

            // Process Synced sheet rows
            $dataRows = array_slice($syncedRows, 1);
            $totalRows = count($dataRows);
            $checkedCount = 0;
            $discrepancyCount = 0;

            foreach ($dataRows as $index => $row) {
                $rowNumber = $index + 2;

                if (empty($row) || !isset($row[$syncedHeaderMap[$poColumn]])) {
                    continue;
                }

                $poTranId = trim($row[$syncedHeaderMap[$poColumn]] ?? '');
                $eprId = trim($row[$syncedHeaderMap[$idColumn]] ?? '');

                if (empty($poTranId)) {
                    continue;
                }

                // Filter by specific PO or EPR if provided
                if ($specificPO && $poTranId !== $specificPO) {
                    continue;
                }

                if ($specificEPR && $eprId !== $specificEPR) {
                    continue;
                }

                // Check if we have items for this EPR
                if (empty($eprId) || !isset($itemsByEPR[$eprId]) || empty($itemsByEPR[$eprId])) {
                    continue;
                }

                $sheetItems = $itemsByEPR[$eprId];

                try {
                    // Get PO from NetSuite
                    $poRecord = $netSuiteService->getPurchaseOrderByTranId($poTranId);

                    if (!$poRecord) {
                        $this->warn("  Row {$rowNumber}: PO '{$poTranId}' not found in NetSuite");
                        continue;
                    }

                    $this->newLine();
                    $this->info("=== PO: {$poTranId} (EPR: {$eprId}) ===");

                    // Get items and expenses
                    $existingItems = [];
                    if (isset($poRecord->itemList) && isset($poRecord->itemList->item)) {
                        $existingItems = is_array($poRecord->itemList->item)
                            ? $poRecord->itemList->item
                            : [$poRecord->itemList->item];
                    }

                    $existingExpenses = [];
                    if (isset($poRecord->expenseList) && isset($poRecord->expenseList->expense)) {
                        $existingExpenses = is_array($poRecord->expenseList->expense)
                            ? $poRecord->expenseList->expense
                            : [$poRecord->expenseList->expense];
                    }

                    $itemDiscrepancies = [];
                    $expenseDiscrepancies = [];
                    $allItems = [];
                    $allExpenses = [];
                    $matchedSheetItems = []; // Track which sheet items were matched

                    $netsuiteItemTotal = 0;
                    $netsuiteExpenseTotal = 0;
                    $sheetItemTotal = 0;

                    // Check items
                    foreach ($existingItems as $existingItem) {
                        $netsuiteItemName = $existingItem->item->name ?? '';
                        $netsuiteDescription = $existingItem->description ?? '';
                        $currentQuantity = $existingItem->quantity ?? 0;
                        $currentRate = $existingItem->rate ?? 0;
                        $itemTotal = $currentQuantity * $currentRate;
                        $netsuiteItemTotal += $itemTotal;

                        // Try to match with sheet item
                        $matchedSheetItem = $this->matchItem($existingItem, $sheetItems, $netsuiteItemName, $netsuiteDescription, $isSandbox);

                        if ($matchedSheetItem) {
                            $matchedSheetItems[] = $matchedSheetItem; // Track matched items
                            $expectedRate = $matchedSheetItem['adjusted_unit_price'];
                            $expectedQuantity = $matchedSheetItem['quantity'];
                            $expectedTotal = $expectedQuantity * $expectedRate;
                            $sheetItemTotal += $matchedSheetItem['total_after_discount'];
                            
                            $rateDiff = abs($currentRate - $expectedRate);
                            $qtyDiff = abs($currentQuantity - $expectedQuantity);
                            $totalDiff = abs($itemTotal - $expectedTotal);

                            $allItems[] = [
                                'type' => 'item',
                                'name' => $netsuiteItemName,
                                'description' => $netsuiteDescription,
                                'current_rate' => $currentRate,
                                'expected_rate' => $expectedRate,
                                'rate_diff' => $rateDiff,
                                'current_qty' => $currentQuantity,
                                'expected_qty' => $expectedQuantity,
                                'qty_diff' => $qtyDiff,
                                'current_total' => $itemTotal,
                                'expected_total' => $expectedTotal,
                                'total_diff' => $totalDiff,
                                'sheet_unit_price' => $matchedSheetItem['unit_price'],
                                'discount' => $matchedSheetItem['discount'],
                                'matched' => true,
                                'has_discrepancy' => $rateDiff > 0.01 || $qtyDiff > 0.01,
                            ];

                            // Check for discrepancies (tolerance: 0.01)
                            if ($rateDiff > 0.01 || $qtyDiff > 0.01) {
                                $itemDiscrepancies[] = &$allItems[count($allItems) - 1];
                            }
                        } else {
                            // No match in sheet
                            $allItems[] = [
                                'type' => 'item',
                                'name' => $netsuiteItemName,
                                'description' => $netsuiteDescription,
                                'current_rate' => $currentRate,
                                'expected_rate' => null,
                                'rate_diff' => null,
                                'current_qty' => $currentQuantity,
                                'expected_qty' => null,
                                'qty_diff' => null,
                                'current_total' => $itemTotal,
                                'expected_total' => null,
                                'total_diff' => null,
                                'sheet_unit_price' => null,
                                'discount' => null,
                                'matched' => false,
                                'has_discrepancy' => true, // No match is a discrepancy
                            ];
                            $itemDiscrepancies[] = &$allItems[count($allItems) - 1];
                        }
                    }

                    // Check expenses
                    foreach ($existingExpenses as $existingExpense) {
                        $currentMemo = $existingExpense->memo ?? '';
                        $currentAmount = $existingExpense->amount ?? 0;
                        $expenseAccountName = $existingExpense->account->name ?? 'Unknown';
                        $netsuiteExpenseTotal += $currentAmount;

                        // Try to match with sheet item
                        $matchedSheetItem = $this->matchExpense($existingExpense, $sheetItems);

                        if ($matchedSheetItem) {
                            $matchedSheetItems[] = $matchedSheetItem; // Track matched items
                            $expectedAmount = $matchedSheetItem['total_after_discount'];
                            $sheetItemTotal += $expectedAmount;
                            $amountDiff = abs($currentAmount - $expectedAmount);

                            $allExpenses[] = [
                                'type' => 'expense',
                                'account' => $expenseAccountName,
                                'memo' => $currentMemo,
                                'current_amount' => $currentAmount,
                                'expected_amount' => $expectedAmount,
                                'amount_diff' => $amountDiff,
                                'sheet_unit_price' => $matchedSheetItem['unit_price'],
                                'quantity' => $matchedSheetItem['quantity'],
                                'discount' => $matchedSheetItem['discount'],
                                'matched' => true,
                                'has_discrepancy' => $amountDiff > 0.01,
                            ];

                            // Check for discrepancies (tolerance: 0.01)
                            if ($amountDiff > 0.01) {
                                $expenseDiscrepancies[] = &$allExpenses[count($allExpenses) - 1];
                            }
                        } else {
                            // No match in sheet
                            $allExpenses[] = [
                                'type' => 'expense',
                                'account' => $expenseAccountName,
                                'memo' => $currentMemo,
                                'current_amount' => $currentAmount,
                                'expected_amount' => null,
                                'amount_diff' => null,
                                'sheet_unit_price' => null,
                                'quantity' => null,
                                'discount' => null,
                                'matched' => false,
                                'has_discrepancy' => true, // No match is a discrepancy
                            ];
                            $expenseDiscrepancies[] = &$allExpenses[count($allExpenses) - 1];
                        }
                    }

                    // Find sheet items that weren't matched
                    $unmatchedSheetItems = [];
                    foreach ($sheetItems as $sheetItem) {
                        $found = false;
                        foreach ($matchedSheetItems as $matched) {
                            if ($matched === $sheetItem) {
                                $found = true;
                                break;
                            }
                        }
                        if (!$found) {
                            $unmatchedSheetItems[] = $sheetItem;
                            $sheetItemTotal += $sheetItem['total_after_discount'];
                        }
                    }

                    // Calculate totals
                    $netsuiteGrandTotal = $netsuiteItemTotal + $netsuiteExpenseTotal;
                    $sheetGrandTotal = array_sum(array_column($sheetItems, 'total_after_discount'));
                    $totalDiff = $netsuiteGrandTotal - $sheetGrandTotal;

                    // Display summary
                    $this->line("  NetSuite Totals:");
                    $this->line("    Items: " . number_format($netsuiteItemTotal, 2));
                    $this->line("    Expenses: " . number_format($netsuiteExpenseTotal, 2));
                    $this->line("    Grand Total: " . number_format($netsuiteGrandTotal, 2));
                    $this->line("  Sheet Totals (after discount):");
                    $this->line("    Grand Total: " . number_format($sheetGrandTotal, 2));
                    $this->line("  Difference: " . number_format($totalDiff, 2) . ($totalDiff > 0 ? " (NetSuite is higher)" : ($totalDiff < 0 ? " (Sheet is higher)" : " (Match!)")));

                    // Display all items
                    if (!empty($allItems)) {
                        $this->newLine();
                        $this->info("  All Items in NetSuite (" . count($allItems) . "):");
                        $this->table(
                            ['Name', 'Description', 'NS Rate', 'NS Qty', 'NS Total', 'Expected Rate', 'Expected Qty', 'Expected Total', 'Diff', 'Status'],
                            array_map(function($d) {
                                $status = $d['matched'] 
                                    ? ($d['has_discrepancy'] ? '⚠️ Diff' : '✓ Match')
                                    : '❌ No Match';
                                return [
                                    substr($d['name'], 0, 25),
                                    substr($d['description'] ?? '', 0, 30),
                                    number_format($d['current_rate'], 2),
                                    number_format($d['current_qty'], 2),
                                    number_format($d['current_total'], 2),
                                    $d['expected_rate'] !== null ? number_format($d['expected_rate'], 2) : '-',
                                    $d['expected_qty'] !== null ? number_format($d['expected_qty'], 2) : '-',
                                    $d['expected_total'] !== null ? number_format($d['expected_total'], 2) : '-',
                                    $d['total_diff'] !== null ? number_format($d['total_diff'], 2) : '-',
                                    $status,
                                ];
                            }, $allItems)
                        );
                    }

                    // Display all expenses
                    if (!empty($allExpenses)) {
                        $this->newLine();
                        $this->info("  All Expenses in NetSuite (" . count($allExpenses) . "):");
                        $this->table(
                            ['Account', 'Memo', 'NS Amount', 'Expected Amount', 'Diff', 'Status'],
                            array_map(function($d) {
                                $status = $d['matched'] 
                                    ? ($d['has_discrepancy'] ? '⚠️ Diff' : '✓ Match')
                                    : '❌ No Match';
                                return [
                                    substr($d['account'], 0, 25),
                                    substr($d['memo'], 0, 40),
                                    number_format($d['current_amount'], 2),
                                    $d['expected_amount'] !== null ? number_format($d['expected_amount'], 2) : '-',
                                    $d['amount_diff'] !== null ? number_format($d['amount_diff'], 2) : '-',
                                    $status,
                                ];
                            }, $allExpenses)
                        );
                    }

                    // Show unmatched sheet items
                    if (!empty($unmatchedSheetItems)) {
                        $this->newLine();
                        $this->warn("  ⚠ Sheet items with discounts that don't match NetSuite (" . count($unmatchedSheetItems) . "):");
                        $this->table(
                            ['Name', 'Unit Price', 'Qty', 'Discount', 'Total After Discount'],
                            array_map(function($d) {
                                return [
                                    substr($d['name'], 0, 40),
                                    number_format($d['unit_price'], 2),
                                    number_format($d['quantity'], 2),
                                    number_format($d['discount'], 2),
                                    number_format($d['total_after_discount'], 2),
                                ];
                            }, $unmatchedSheetItems)
                        );
                    }

                    // Display discrepancies summary
                    if (!empty($itemDiscrepancies) || !empty($expenseDiscrepancies)) {
                        $discrepancyCount++;
                        $this->newLine();
                        $this->warn("  ⚠ Discrepancies Summary:");
                        if (!empty($itemDiscrepancies)) {
                            $this->line("    Items: " . count($itemDiscrepancies) . " discrepancy(ies)");
                        }
                        if (!empty($expenseDiscrepancies)) {
                            $this->line("    Expenses: " . count($expenseDiscrepancies) . " discrepancy(ies)");
                        }
                    } else {
                        $this->newLine();
                        $this->info("  ✓ No discrepancies found - all values match!");
                    }

                    $checkedCount++;

                } catch (\Exception $e) {
                    $this->error("  ✗ Error checking PO '{$poTranId}': " . $e->getMessage());
                    Log::error('PO discrepancy check error', [
                        'po' => $poTranId,
                        'epr' => $eprId,
                        'error' => $e->getMessage()
                    ]);
                }
            }

            $this->newLine();
            $this->info("Summary:");
            $this->info("  POs checked: " . $checkedCount);
            $this->info("  POs with discrepancies: " . $discrepancyCount);

            return Command::SUCCESS;

        } catch (\Exception $e) {
            $this->error('Error checking discrepancies: ' . $e->getMessage());
            Log::error('PO discrepancy check command error: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }

    /**
     * Match NetSuite item with sheet item
     */
    private function matchItem($existingItem, $sheetItems, $netsuiteItemName, $netsuiteDescription, $isSandbox)
    {
        $netsuiteItemId = $existingItem->item->internalId ?? null;
        $netsuiteItem = null;
        
        if ($netsuiteItemId) {
            $netsuiteItem = NetSuiteItem::where('netsuite_id', $netsuiteItemId)
                ->where('is_sandbox', $isSandbox)
                ->first();
        }

        foreach ($sheetItems as $sheetItem) {
            $sheetItemName = trim($sheetItem['name']);
            $netSuiteDescriptionTrimmed = trim($netsuiteDescription);
            $netSuiteItemNameTrimmed = trim($netsuiteItemName);

            // Strategy 1: Match by Description (primary)
            if (!empty($netSuiteDescriptionTrimmed)) {
                if (strcasecmp($netSuiteDescriptionTrimmed, $sheetItemName) === 0 ||
                    stripos($netSuiteDescriptionTrimmed, $sheetItemName) !== false ||
                    stripos($sheetItemName, $netSuiteDescriptionTrimmed) !== false) {
                    return $sheetItem;
                }
            }

            // Strategy 2: Match by item name
            if (strcasecmp($netSuiteItemNameTrimmed, $sheetItemName) === 0 ||
                stripos($netSuiteItemNameTrimmed, $sheetItemName) !== false ||
                stripos($sheetItemName, $netSuiteItemNameTrimmed) !== false) {
                return $sheetItem;
            }

            // Strategy 3: Match by database item name
            if ($netsuiteItem) {
                $dbItemName = trim($netsuiteItem->name ?? '');
                if (!empty($dbItemName) && (
                    strcasecmp($dbItemName, $sheetItemName) === 0 ||
                    stripos($dbItemName, $sheetItemName) !== false ||
                    stripos($sheetItemName, $dbItemName) !== false
                )) {
                    return $sheetItem;
                }
            }
        }

        return null;
    }

    /**
     * Match NetSuite expense with sheet item
     */
    private function matchExpense($existingExpense, $sheetItems)
    {
        $currentMemo = $existingExpense->memo ?? '';
        $memoWithoutPrefix = preg_replace('/^\d+(\.\d+)?\s+unit\s+-\s+/i', '', $currentMemo);

        foreach ($sheetItems as $sheetItem) {
            $sheetItemName = trim($sheetItem['name']);

            if (stripos($memoWithoutPrefix, $sheetItemName) !== false ||
                stripos($sheetItemName, $memoWithoutPrefix) !== false ||
                stripos($currentMemo, $sheetItemName) !== false ||
                stripos($sheetItemName, $currentMemo) !== false) {
                return $sheetItem;
            }
        }

        return null;
    }

    /**
     * Find column by trying multiple possible names
     */
    private function findColumn($headerMap, $possibleNames)
    {
        foreach ($possibleNames as $name) {
            if (isset($headerMap[$name])) {
                return $name;
            }
        }
        return null;
    }

    /**
     * Parse numeric value from string
     */
    private function parseNumeric($value)
    {
        if (is_numeric($value)) {
            return (float) $value;
        }

        $cleaned = str_replace(',', '', trim((string) $value));
        $cleaned = preg_replace('/[^0-9.-]/', '', $cleaned);

        if (empty($cleaned) || !is_numeric($cleaned)) {
            return 0.0;
        }

        return (float) $cleaned;
    }
}

