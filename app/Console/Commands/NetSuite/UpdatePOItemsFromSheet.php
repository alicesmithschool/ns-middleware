<?php

namespace App\Console\Commands\NetSuite;

use App\Models\NetSuiteItem;
use App\Services\GoogleSheetsService;
use App\Services\NetSuiteService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class UpdatePOItemsFromSheet extends Command
{
    protected $signature = 'netsuite:update-po-items-from-sheet {--dry-run : Show what would be changed without actually updating} {--po= : Update specific PO only (by transaction ID)} {--force-position : Force match items by position when counts match}';
    protected $description = 'Update PO item quantities and rates in NetSuite from Items sheet data';

    public function handle(GoogleSheetsService $sheetsService, NetSuiteService $netSuiteService)
    {
        $this->info('Starting PO item update from Google Sheets...');
        $isDryRun = $this->option('dry-run');
        $specificPO = $this->option('po');
        $forcePosition = $this->option('force-position');
        $isSandbox = config('netsuite.environment') === 'sandbox';

        if ($isDryRun) {
            $this->warn('DRY RUN MODE - No changes will be made to NetSuite');
        }

        if ($specificPO) {
            $this->info("Updating only PO: {$specificPO}");
        }

        if ($forcePosition) {
            $this->warn('FORCE POSITION MODE - Will match items by position when counts match (ignores names)');
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

            // Debug: Show all column headers
            $this->info('Items sheet columns found: ' . implode(', ', $itemHeaders));

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

            $unitPriceColumn = null;
            foreach (['Unit Price', 'Price', 'Rate', 'Unit Cost'] as $col) {
                if (isset($itemHeaderMap[$col])) {
                    $unitPriceColumn = $col;
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

            if (!$unitPriceColumn) {
                $this->error("Unit Price column not found in Items sheet. Expected one of: Unit Price, Price, Rate, Unit Cost");
                return Command::FAILURE;
            }

            $this->info("Using columns - EPR: '{$eprColumn}', Name: '{$nameColumn}', Qty: '{$quantityColumn}', Price: '{$unitPriceColumn}'");

            // Check for Item Number/Reference column (optional)
            $itemNumberColumn = null;
            foreach (['Item Number', 'Item', 'Reference'] as $col) {
                if (isset($itemHeaderMap[$col])) {
                    $itemNumberColumn = $col;
                    break;
                }
            }

            // Group items by EPR ID
            $this->info('Grouping items by EPR ID...');
            $itemsByEPR = [];
            $debugSampleShown = false;

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

                $itemNumber = null;
                if ($itemNumberColumn && isset($row[$itemHeaderMap[$itemNumberColumn]])) {
                    $itemNumber = trim($row[$itemHeaderMap[$itemNumberColumn]]);
                }

                // Skip if name is empty
                if (empty($itemName)) {
                    continue;
                }

                // Parse quantity and unit price as floats
                $quantityFloat = !empty($quantity) ? (float)str_replace(',', '', $quantity) : 1.0;
                $unitPriceFloat = !empty($unitPrice) ? (float)str_replace(',', '', $unitPrice) : 0.0;

                $itemsByEPR[$eprId][] = [
                    'name' => $itemName,
                    'item_number' => $itemNumber,
                    'quantity' => $quantityFloat,
                    'unit_price' => $unitPriceFloat,
                    'raw_quantity' => $quantity,
                    'raw_unit_price' => $unitPrice,
                ];

                // Debug: Show first few items to verify parsing
                if (!$debugSampleShown && count($itemsByEPR) <= 2) {
                    $this->line("  Sample: EPR '{$eprId}' → Item '{$itemName}' (Qty: {$quantity} → {$quantityFloat}, Price: {$unitPrice} → {$unitPriceFloat})");
                    if (count($itemsByEPR) == 2) {
                        $debugSampleShown = true;
                    }
                }
            }

            $this->info('Found items for ' . count($itemsByEPR) . ' EPR ID(s)');

            // Debug: Show all EPR IDs found (first 10)
            $eprIds = array_keys($itemsByEPR);
            $sampleEPRs = array_slice($eprIds, 0, 10);
            $this->line('  Sample EPR IDs in Items sheet: ' . implode(', ', $sampleEPRs));
            if (count($eprIds) > 10) {
                $this->line('  ... and ' . (count($eprIds) - 10) . ' more');
            }

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

                // Debug: Show what we found in the Items sheet for this EPR
                $this->newLine();
                $this->info("  Row {$rowNumber}: PO '{$poTranId}' (EPR: '{$eprId}') - Found " . count($sheetItems) . " item(s) in Items sheet:");
                foreach ($sheetItems as $idx => $item) {
                    $this->line("    " . ($idx + 1) . ". {$item['name']} - Qty: {$item['quantity']}, Price: {$item['unit_price']}");
                }

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

                    // Get existing item list from PO
                    $existingItems = [];
                    if (isset($poRecord->itemList) && isset($poRecord->itemList->item)) {
                        $existingItems = is_array($poRecord->itemList->item)
                            ? $poRecord->itemList->item
                            : [$poRecord->itemList->item];
                    }

                    if (empty($existingItems)) {
                        $this->newLine();
                        $this->warn("  Row {$rowNumber}: PO '{$poTranId}' has no items, skipping");
                        $skippedCount++;
                        $progressBar->advance();
                        continue;
                    }

                    // Match sheet items with NetSuite items and prepare updates
                    $updatedItems = [];
                    $changesDetected = false;
                    $comparisons = [];
                    $lineNumber = 1;

                    // Check if we can use position-based matching
                    $usePositionMatch = false;
                    if (count($existingItems) === count($sheetItems)) {
                        // Auto-enable position matching when counts match
                        $usePositionMatch = true;
                        $this->newLine();
                        $this->info("  ✓ Auto position-based matching: " . count($existingItems) . " NetSuite item(s) = " . count($sheetItems) . " sheet item(s)");
                    } elseif ($forcePosition) {
                        $this->newLine();
                        $this->warn("  ⚠ Cannot use position matching: " . count($existingItems) . " NetSuite items ≠ " . count($sheetItems) . " sheet items");
                    }

                    foreach ($existingItems as $itemIndex => $existingItem) {
                        $itemId = $existingItem->item->internalId ?? null;
                        $itemName = $existingItem->item->name ?? 'Unknown';

                        if (!$itemId) {
                            // Keep item as-is if no ID
                            $poi = clone $existingItem;
                            $poi->line = $lineNumber++;
                            $updatedItems[] = $poi;
                            continue;
                        }

                        // Get item details from database
                        $netsuiteItem = NetSuiteItem::where('netsuite_id', (string)$itemId)
                            ->where('is_sandbox', $isSandbox)
                            ->first();

                        if (!$netsuiteItem) {
                            $this->newLine();
                            $this->warn("    NetSuite Item ID {$itemId} ('{$itemName}') not found in local database - run 'netsuite:sync-items' first");
                            // Keep item as-is if not in database
                            $poi = clone $existingItem;
                            $poi->line = $lineNumber++;
                            $updatedItems[] = $poi;
                            continue;
                        }

                        // Try to match with sheet items by name or item number
                        $matchedSheetItem = null;
                        $matchReason = null;

                        // Strategy 0: Position-based matching (auto when counts match, or --force-position)
                        if ($usePositionMatch && isset($sheetItems[$itemIndex])) {
                            $matchedSheetItem = $sheetItems[$itemIndex];
                            $matchReason = 'auto position #' . ($itemIndex + 1);
                        } else {
                            // Try name/item number based matching
                            foreach ($sheetItems as $sheetItem) {
                                // Try multiple matching strategies

                                // Strategy 1: Exact name match (case-insensitive)
                                if (strcasecmp($netsuiteItem->name, $sheetItem['name']) === 0) {
                                    $matchedSheetItem = $sheetItem;
                                    $matchReason = 'exact name match';
                                    break;
                                }

                                // Strategy 2: NetSuite name contains sheet name
                                if (stripos($netsuiteItem->name, $sheetItem['name']) !== false) {
                                    $matchedSheetItem = $sheetItem;
                                    $matchReason = 'NS name contains sheet name';
                                    break;
                                }

                                // Strategy 3: Sheet name contains NetSuite name
                                if (stripos($sheetItem['name'], $netsuiteItem->name) !== false) {
                                    $matchedSheetItem = $sheetItem;
                                    $matchReason = 'sheet name contains NS name';
                                    break;
                                }

                                // Strategy 4: Exact item number match
                                if (!empty($sheetItem['item_number']) &&
                                    !empty($netsuiteItem->item_number) &&
                                    strcasecmp($netsuiteItem->item_number, $sheetItem['item_number']) === 0) {
                                    $matchedSheetItem = $sheetItem;
                                    $matchReason = 'exact item number match';
                                    break;
                                }

                                // Strategy 5: Partial item number match
                                if (!empty($sheetItem['item_number']) &&
                                    !empty($netsuiteItem->item_number) &&
                                    (stripos($netsuiteItem->item_number, $sheetItem['item_number']) !== false ||
                                     stripos($sheetItem['item_number'], $netsuiteItem->item_number) !== false)) {
                                    $matchedSheetItem = $sheetItem;
                                    $matchReason = 'partial item number match';
                                    break;
                                }
                            }
                        }

                        // Debug matching
                        if (!$matchedSheetItem && count($sheetItems) > 0 && !$usePositionMatch) {
                            $this->newLine();
                            $this->warn("    ⚠ No match found for NetSuite item:");
                            $this->line("      NetSuite: ID={$netsuiteItem->netsuite_id}, Name='{$netsuiteItem->name}', ItemNum='{$netsuiteItem->item_number}'");
                            $this->line("      Available in sheet:");
                            foreach ($sheetItems as $idx => $si) {
                                $this->line("        " . ($idx + 1) . ". '{$si['name']}'" . ($si['item_number'] ? " (Item#: {$si['item_number']})" : ''));
                            }
                        }

                        // Get current values from NetSuite
                        $currentQuantity = $existingItem->quantity ?? 0;
                        $currentRate = $existingItem->rate ?? 0;

                        // Create updated item
                        $poi = new \PurchaseOrderItem();
                        $poi->line = $lineNumber++;
                        $poi->item = $existingItem->item;

                        if ($matchedSheetItem) {
                            // ALWAYS use Items sheet as source of truth
                            $newQuantity = $matchedSheetItem['quantity'];
                            $newRate = $matchedSheetItem['unit_price'];

                            $poi->quantity = $newQuantity;
                            $poi->rate = $newRate;

                            // Check if values changed and record comparison
                            $qtyChanged = abs($currentQuantity - $newQuantity) > 0.01;
                            $rateChanged = abs($currentRate - $newRate) > 0.01;

                            if ($qtyChanged || $rateChanged) {
                                $changesDetected = true;
                            }
                            $comparisons[] = [
                                'item_name' => $netsuiteItem->name,
                                'item_number' => $netsuiteItem->item_number,
                                'current_qty' => $currentQuantity,
                                'new_qty' => $newQuantity,
                                'qty_changed' => $qtyChanged,
                                'current_rate' => $currentRate,
                                'new_rate' => $newRate,
                                'rate_changed' => $rateChanged,
                                'match_reason' => $matchReason,
                            ];
                        } else {
                            // No match in Items sheet - keep existing NetSuite values
                            $poi->quantity = $currentQuantity;
                            $poi->rate = $currentRate;

                            $comparisons[] = [
                                'item_name' => $netsuiteItem->name,
                                'item_number' => $netsuiteItem->item_number,
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

                    // Display comparison table
                    $this->newLine();
                    $this->info("  Comparison (NetSuite vs Items Sheet):");

                    if (!empty($comparisons)) {
                        $this->table(
                            ['Item Name', 'Item #', 'Current Qty', 'New Qty', 'Current Rate', 'New Rate', 'Status'],
                            array_map(function($comp) {
                                $status = [];
                                if (isset($comp['no_match'])) {
                                    $status[] = '❌ NO MATCH';
                                } else {
                                    if ($comp['qty_changed']) $status[] = '📊 Qty';
                                    if ($comp['rate_changed']) $status[] = '💰 Rate';
                                    if (!$comp['qty_changed'] && !$comp['rate_changed']) $status[] = '✓ OK';
                                    if (isset($comp['match_reason'])) {
                                        $status[] = "({$comp['match_reason']})";
                                    }
                                }

                                return [
                                    substr($comp['item_name'], 0, 35),
                                    substr($comp['item_number'] ?? '', 0, 15),
                                    number_format($comp['current_qty'], 2),
                                    number_format($comp['new_qty'], 2),
                                    number_format($comp['current_rate'], 2),
                                    number_format($comp['new_rate'], 2),
                                    implode(' ', $status),
                                ];
                            }, $comparisons)
                        );
                    }

                    // Skip if no changes detected
                    if (!$changesDetected) {
                        $this->info("  → No changes needed, all items already match Items sheet");
                        $skippedCount++;
                        $progressBar->advance();
                        continue;
                    }

                    $changesCount = count(array_filter($comparisons, function($c) {
                        return ($c['qty_changed'] ?? false) || ($c['rate_changed'] ?? false);
                    }));

                    $this->info("  → Changes detected: {$changesCount} item(s) will be updated");

                    if ($isDryRun) {
                        $this->warn("  [DRY RUN] Would update PO '{$poTranId}' with the values shown above");
                        $updatedCount++;
                        $progressBar->advance();
                        continue;
                    }

                    // Update PO in NetSuite
                    $service = $netSuiteService->getService();

                    // Preserve critical fields
                    $entityRef = $poRecord->entity ?? null;

                    // Set updated item list
                    $poRecord->itemList = new \PurchaseOrderItemList();
                    $poRecord->itemList->replaceAll = true;
                    $poRecord->itemList->item = $updatedItems;

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
                    Log::error("Error updating PO {$poTranId}: " . $e->getMessage());
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
            $this->error('Error updating PO items from sheet: ' . $e->getMessage());
            Log::error('PO item update error: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }
}
