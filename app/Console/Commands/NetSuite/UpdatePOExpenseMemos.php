<?php

namespace App\Console\Commands\NetSuite;

use App\Services\GoogleSheetsService;
use App\Services\NetSuiteService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class UpdatePOExpenseMemos extends Command
{
    protected $signature = 'netsuite:update-po-expense-memos {--dry-run : Show what would be changed without actually updating} {--po= : Update specific PO only (by transaction ID)}';
    protected $description = 'Update PO expense memos in NetSuite from Items sheet data - prepend quantity like "x unit - memo"';

    public function handle(GoogleSheetsService $sheetsService, NetSuiteService $netSuiteService)
    {
        $this->info('Starting PO expense memo update from Google Sheets...');
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

            // Find required columns with flexible matching
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

            // Validate required columns found
            if (!$eprColumn) {
                $this->error("EPR column not found in Items sheet. Expected one of: EPR, ID, EPR ID");
                $this->error("Available columns: " . implode(', ', $itemHeaders));
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

            $this->info("Using columns - EPR: '{$eprColumn}', Name: '{$nameColumn}', Qty: '{$quantityColumn}'");

            // Group items by EPR ID
            $this->info('Grouping items by EPR ID...');
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

                // Skip if name is empty
                if (empty($itemName)) {
                    continue;
                }

                // Parse quantity as float
                $quantityFloat = !empty($quantity) ? (float)str_replace(',', '', $quantity) : 1.0;

                $itemsByEPR[$eprId][] = [
                    'name' => $itemName,
                    'quantity' => $quantityFloat,
                    'raw_quantity' => $quantity,
                ];
            }

            $this->info('Found items for ' . count($itemsByEPR) . ' EPR ID(s)');

            // Process each PO from Synced sheet
            $dataRows = array_slice($syncedRows, 1);
            $totalRows = count($dataRows);
            $updatedCount = 0;
            $skippedCount = 0;
            $errorCount = 0;

            $this->info("Processing {$totalRows} PO(s) from Synced sheet...");
            $progressBar = $this->output->createProgressBar($totalRows);
            $progressBar->start();

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

                // Check if we have items for this EPR
                if (empty($eprId) || !isset($itemsByEPR[$eprId])) {
                    $this->newLine();
                    $this->warn("  Row {$rowNumber}: PO '{$poTranId}' (EPR: '{$eprId}') - No items found in Items sheet, skipping");
                    $skippedCount++;
                    $progressBar->advance();
                    continue;
                }

                $sheetItems = $itemsByEPR[$eprId];

                try {
                    // Get PO from NetSuite
                    $poRecord = $netSuiteService->getPurchaseOrderByTranId($poTranId);

                    if (!$poRecord) {
                        $this->newLine();
                        $this->warn("  Row {$rowNumber}: PO '{$poTranId}' not found in NetSuite, skipping");
                        $skippedCount++;
                        $progressBar->advance();
                        continue;
                    }

                    $internalId = $poRecord->internalId ?? null;
                    if (!$internalId) {
                        $this->newLine();
                        $this->warn("  Row {$rowNumber}: PO '{$poTranId}' missing internalId, skipping");
                        $skippedCount++;
                        $progressBar->advance();
                        continue;
                    }

                    // Check if PO has expense list
                    $hasExpenses = false;
                    $expenses = [];
                    if (isset($poRecord->expenseList) && isset($poRecord->expenseList->expense)) {
                        $expenses = is_array($poRecord->expenseList->expense)
                            ? $poRecord->expenseList->expense
                            : [$poRecord->expenseList->expense];
                        $hasExpenses = !empty($expenses);
                    }

                    // Check if PO has item list
                    $hasItems = false;
                    $existingItems = [];
                    if (isset($poRecord->itemList) && isset($poRecord->itemList->item)) {
                        $existingItems = is_array($poRecord->itemList->item)
                            ? $poRecord->itemList->item
                            : [$poRecord->itemList->item];
                        $hasItems = !empty($existingItems);
                    }

                    // If PO has no expenses, skip (we only update expenses)
                    if (!$hasExpenses) {
                        $this->newLine();
                        $this->info("  Row {$rowNumber}: PO '{$poTranId}' has no expenses (has items: " . ($hasItems ? 'yes' : 'no') . "), skipping");
                        $skippedCount++;
                        $progressBar->advance();
                        continue;
                    }

                    $this->newLine();
                    $this->info("  Row {$rowNumber}: PO '{$poTranId}' (EPR: '{$eprId}') - Found " . count($expenses) . " expense(s) and " . count($sheetItems) . " item(s) in Items sheet");

                    // Match expenses with sheet items and prepare updates
                    $updatedExpenses = [];
                    $changesDetected = false;
                    $comparisons = [];

                    foreach ($expenses as $expenseIndex => $expense) {
                        $currentMemo = $expense->memo ?? '';
                        $expenseAccountName = $expense->account->name ?? 'Unknown';

                        // Try to match expense with sheet item by memo/name
                        $matchedSheetItem = null;
                        $matchReason = null;

                        // Check if memo already has quantity prefix (format: "x unit - memo")
                        $alreadyHasPrefix = preg_match('/^\d+(\.\d+)?\s+unit\s+-\s+/i', $currentMemo);
                        $memoWithoutPrefix = $currentMemo;
                        
                        if ($alreadyHasPrefix) {
                            // Extract the memo part (after "x unit - ")
                            $memoWithoutPrefix = preg_replace('/^\d+(\.\d+)?\s+unit\s+-\s+/i', '', $currentMemo);
                        }

                        // Strategy 1: Match by memo (with or without prefix) containing item name
                        foreach ($sheetItems as $sheetItem) {
                            // Try matching with memo without prefix
                            if (stripos($memoWithoutPrefix, $sheetItem['name']) !== false || 
                                stripos($sheetItem['name'], $memoWithoutPrefix) !== false) {
                                $matchedSheetItem = $sheetItem;
                                $matchReason = 'memo/name match';
                                break;
                            }
                            
                            // Also try matching with original memo
                            if (stripos($currentMemo, $sheetItem['name']) !== false || 
                                stripos($sheetItem['name'], $currentMemo) !== false) {
                                $matchedSheetItem = $sheetItem;
                                $matchReason = 'memo/name match';
                                break;
                            }
                        }

                        // Strategy 2: Match by position if counts match
                        if (!$matchedSheetItem && count($expenses) === count($sheetItems)) {
                            if (isset($sheetItems[$expenseIndex])) {
                                $matchedSheetItem = $sheetItems[$expenseIndex];
                                $matchReason = 'position match';
                            }
                        }

                        // Create updated expense
                        $updatedExpense = clone $expense;

                        if ($matchedSheetItem) {
                            $quantity = $matchedSheetItem['quantity'];
                            
                            // Format: "x unit - memo" (prepend quantity to existing memo)
                            // Use memo without prefix if it already had one, otherwise use original memo
                            $memoToUse = $memoWithoutPrefix;
                            $expectedMemo = $quantity . ' unit - ' . $memoToUse;

                            if ($currentMemo !== $expectedMemo) {
                                $updatedExpense->memo = $expectedMemo;
                                $changesDetected = true;
                                
                                $comparisons[] = [
                                    'account' => $expenseAccountName,
                                    'current_memo' => $currentMemo,
                                    'new_memo' => $expectedMemo,
                                    'quantity' => $quantity,
                                    'match_reason' => $matchReason,
                                ];
                            } else {
                                $comparisons[] = [
                                    'account' => $expenseAccountName,
                                    'current_memo' => $currentMemo,
                                    'new_memo' => $currentMemo,
                                    'quantity' => $quantity,
                                    'match_reason' => 'already correct',
                                ];
                            }
                        } else {
                            // No match found - keep existing memo
                            $comparisons[] = [
                                'account' => $expenseAccountName,
                                'current_memo' => $currentMemo,
                                'new_memo' => $currentMemo,
                                'quantity' => null,
                                'match_reason' => 'no match found',
                            ];
                        }

                        $updatedExpenses[] = $updatedExpense;
                    }

                    // Display comparison table
                    $this->newLine();
                    $this->info("  Expense Memo Comparison:");

                    if (!empty($comparisons)) {
                        $this->table(
                            ['Account', 'Current Memo', 'New Memo', 'Quantity', 'Status'],
                            array_map(function($comp) {
                                $status = [];
                                if ($comp['match_reason'] === 'no match found') {
                                    $status[] = '❌ NO MATCH';
                                } elseif ($comp['match_reason'] === 'already correct') {
                                    $status[] = '✓ OK';
                                } else {
                                    if ($comp['current_memo'] !== $comp['new_memo']) {
                                        $status[] = '📝 UPDATE';
                                    } else {
                                        $status[] = '✓ OK';
                                    }
                                    $status[] = "({$comp['match_reason']})";
                                }

                                return [
                                    substr($comp['account'], 0, 30),
                                    substr($comp['current_memo'], 0, 40),
                                    substr($comp['new_memo'], 0, 40),
                                    $comp['quantity'] !== null ? number_format($comp['quantity'], 2) : 'N/A',
                                    implode(' ', $status),
                                ];
                            }, $comparisons)
                        );
                    }

                    // Skip if no changes detected
                    if (!$changesDetected) {
                        $this->info("  → No changes needed, all expense memos already correct");
                        $skippedCount++;
                        $progressBar->advance();
                        continue;
                    }

                    $changesCount = count(array_filter($comparisons, function($c) {
                        return $c['current_memo'] !== $c['new_memo'];
                    }));

                    $this->info("  → Changes detected: {$changesCount} expense memo(s) will be updated");

                    if ($isDryRun) {
                        $this->warn("  [DRY RUN] Would update PO '{$poTranId}' with the expense memos shown above");
                        $updatedCount++;
                        $progressBar->advance();
                        continue;
                    }

                    // Update PO in NetSuite
                    $service = $netSuiteService->getService();

                    // Preserve critical fields
                    $entityRef = $poRecord->entity ?? null;

                    // Set updated expense list
                    $poRecord->expenseList = new \PurchaseOrderExpenseList();
                    $poRecord->expenseList->replaceAll = true;
                    $poRecord->expenseList->expense = $updatedExpenses;

                    // Preserve item list if it exists
                    if ($hasItems) {
                        $poRecord->itemList = new \PurchaseOrderItemList();
                        $poRecord->itemList->replaceAll = true;
                        $poRecord->itemList->item = $existingItems;
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
                        $this->error("  Row {$rowNumber}: Failed to update PO '{$poTranId}': {$errorMsg}");
                        $errorCount++;
                    } else {
                        $this->line("  ✓ Successfully updated PO '{$poTranId}'");
                        $updatedCount++;
                    }

                } catch (\Exception $e) {
                    $this->newLine();
                    $this->error("  Row {$rowNumber}: Error processing PO '{$poTranId}': " . $e->getMessage());
                    Log::error("Error updating PO expense memos {$poTranId}: " . $e->getMessage());
                    $errorCount++;
                }

                $progressBar->advance();
            }

            $progressBar->finish();
            $this->newLine(2);

            $this->info("Update completed!");
            $this->info("Total rows processed: {$totalRows}");
            $this->info("Updated: {$updatedCount}");
            $this->info("Skipped: {$skippedCount}");
            $this->info("Errors: {$errorCount}");

            return Command::SUCCESS;

        } catch (\Exception $e) {
            $this->error('Error updating PO expense memos from sheet: ' . $e->getMessage());
            Log::error('PO expense memo update error: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }
}

