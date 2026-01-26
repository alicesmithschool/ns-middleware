<?php

namespace App\Console\Commands\NetSuite;

use App\Models\NetSuiteAccount;
use App\Models\NetSuiteDepartment;
use App\Models\NetSuiteItem;
use App\Models\NetSuiteLocation;
use App\Services\GoogleSheetsService;
use App\Services\NetSuiteService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class UpdatePOExpensesToItems extends Command
{
    protected $signature = 'netsuite:update-po-expenses-to-items {--dry-run : Show what would be changed without actually updating}';
    protected $description = 'Update POs from Synced sheet by converting expenses to items based on po_items.json mapping';

    public function handle(GoogleSheetsService $sheetsService, NetSuiteService $netSuiteService)
    {
        $this->info('Starting PO expense-to-item conversion...');
        $isDryRun = $this->option('dry-run');
        $isSandbox = config('netsuite.environment') === 'sandbox';
        
        if ($isDryRun) {
            $this->warn('DRY RUN MODE - No changes will be made to NetSuite');
        }

        try {
            // Load po_items.json mapping
            $this->info('Loading po_items.json mapping...');
            $poItemsPath = base_path('po_items.json');
            if (!file_exists($poItemsPath)) {
                $this->error("po_items.json not found at: {$poItemsPath}");
                return Command::FAILURE;
            }
            
            $poItemsData = json_decode(file_get_contents($poItemsPath), true);
            if (!$poItemsData) {
                $this->error('Failed to parse po_items.json');
                return Command::FAILURE;
            }
            
            // Create mapping: account_number -> name
            $poItemsMap = [];
            foreach ($poItemsData as $item) {
                if (isset($item['account_number']) && isset($item['name'])) {
                    $poItemsMap[$item['account_number']] = $item['name'];
                }
            }
            $this->info("Loaded " . count($poItemsMap) . " account mappings from po_items.json");

            // Read Synced sheet
            $this->info('Reading Synced sheet...');
            $syncedRows = $sheetsService->readSheet('Synced');
            
            if (empty($syncedRows) || count($syncedRows) < 2) {
                $this->warn('No data found in Synced sheet (need at least header + 1 row)');
                return Command::SUCCESS;
            }
            
            // Get headers
            $headers = array_map('trim', $syncedRows[0]);
            $headerMap = array_flip($headers);
            
            // Find PO column (could be 'PO' or 'Transaction ID' or similar)
            $poColumn = null;
            foreach (['PO', 'Transaction ID', 'TranId', 'tranId'] as $col) {
                if (isset($headerMap[$col])) {
                    $poColumn = $col;
                    break;
                }
            }
            
            if (!$poColumn) {
                $this->error("Could not find PO column in Synced sheet. Expected one of: PO, Transaction ID, TranId");
                return Command::FAILURE;
            }
            
            $poColumnIndex = $headerMap[$poColumn];
            
            // Process each row
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
                
                if (empty($row) || !isset($row[$poColumnIndex])) {
                    $skippedCount++;
                    $progressBar->advance();
                    continue;
                }
                
                $poTranId = trim($row[$poColumnIndex] ?? '');
                if (empty($poTranId)) {
                    $skippedCount++;
                    $progressBar->advance();
                    continue;
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
                    
                    // Check if PO has expense list
                    if (!isset($poRecord->expenseList) || !isset($poRecord->expenseList->expense)) {
                        // No expenses to convert
                        $skippedCount++;
                        $progressBar->advance();
                        continue;
                    }
                    
                    $expenses = $poRecord->expenseList->expense;
                    if (!is_array($expenses)) {
                        $expenses = [$expenses];
                    }
                    
                    // Get existing item list
                    $existingItems = [];
                    if (isset($poRecord->itemList) && isset($poRecord->itemList->item)) {
                        $existingItems = is_array($poRecord->itemList->item) 
                            ? $poRecord->itemList->item 
                            : [$poRecord->itemList->item];
                    }
                    
                    // Find expenses that should be converted to items
                    $expensesToConvert = [];
                    $expensesToKeep = [];
                    
                    foreach ($expenses as $expense) {
                        $accountId = $expense->account->internalId ?? null;
                        if (!$accountId) {
                            $expensesToKeep[] = $expense;
                            continue;
                        }
                        
                        // Get account from database
                        $account = NetSuiteAccount::where('netsuite_id', (string)$accountId)
                            ->where('is_sandbox', $isSandbox)
                            ->first();
                        
                        if (!$account || !isset($poItemsMap[$account->account_number])) {
                            // Not in po_items.json, keep as expense
                            $expensesToKeep[] = $expense;
                            continue;
                        }
                        
                        // This expense should be converted to an item
                        $mappedItemName = $poItemsMap[$account->account_number];

                        // Find the item in NetSuite (exclude Teaching Materials_Sales)
                        $netsuiteItem = NetSuiteItem::where('name', 'like', "%{$mappedItemName}%")
                            ->where('item_number', '!=', 'Teaching Materials_Sales')
                            ->where('is_sandbox', $isSandbox)
                            ->where('is_inactive', false)
                            ->first();

                        // Also try by item_number
                        if (!$netsuiteItem) {
                            $netsuiteItem = NetSuiteItem::where('item_number', 'like', "%{$mappedItemName}%")
                                ->where('item_number', '!=', 'Teaching Materials_Sales')
                                ->where('is_sandbox', $isSandbox)
                                ->where('is_inactive', false)
                                ->first();
                        }

                        // Try non-inventory items specifically
                        if (!$netsuiteItem) {
                            $netsuiteItem = NetSuiteItem::where(function($query) use ($mappedItemName) {
                                $query->where('name', 'like', "%{$mappedItemName}%")
                                      ->orWhere('item_number', 'like', "%{$mappedItemName}%");
                            })
                            ->where('item_number', '!=', 'Teaching Materials_Sales')
                            ->where('item_type', 'like', '%noninventory%')
                            ->where('is_sandbox', $isSandbox)
                            ->where('is_inactive', false)
                            ->first();
                        }
                        
                        if ($netsuiteItem) {
                            // Check if this is the excluded item
                            if ($netsuiteItem->item_number === 'Teaching Materials_Sales') {
                                $this->newLine();
                                $this->warn("  Row {$rowNumber}: PO '{$poTranId}' - Item '{$netsuiteItem->name}' is excluded (Teaching Materials_Sales), keeping as expense");
                                $expensesToKeep[] = $expense;
                            } else {
                                $expensesToConvert[] = [
                                    'expense' => $expense,
                                    'item' => $netsuiteItem,
                                    'account' => $account,
                                    'mapped_name' => $mappedItemName
                                ];
                            }
                        } else {
                            $this->newLine();
                            $this->warn("  Row {$rowNumber}: PO '{$poTranId}' - Could not find item for account '{$account->account_number}' ({$mappedItemName}), keeping as expense");
                            $expensesToKeep[] = $expense;
                        }
                    }
                    
                    // If no expenses to convert, skip
                    if (empty($expensesToConvert)) {
                        $skippedCount++;
                        $progressBar->advance();
                        continue;
                    }
                    
                    $this->newLine();
                    $this->info("  Row {$rowNumber}: PO '{$poTranId}' - Converting " . count($expensesToConvert) . " expense(s) to item(s)");
                    
                    if ($isDryRun) {
                        foreach ($expensesToConvert as $conv) {
                            $this->line("    Would convert: Account {$conv['account']->account_number} ({$conv['mapped_name']}) → Item {$conv['item']->netsuite_id} ({$conv['item']->name})");
                        }
                        $updatedCount++;
                        $progressBar->advance();
                        continue;
                    }
                    
                    // Build new item list (existing items + converted items)
                    $newItems = [];
                    $lineNumber = 1;
                    
                    // Add existing items
                    foreach ($existingItems as $existingItem) {
                        $poi = new \PurchaseOrderItem();
                        $poi->line = $lineNumber++;
                        $poi->item = $existingItem->item;
                        if (isset($existingItem->quantity)) $poi->quantity = $existingItem->quantity;
                        if (isset($existingItem->rate)) $poi->rate = $existingItem->rate;
                        if (isset($existingItem->description)) $poi->description = $existingItem->description;
                        if (isset($existingItem->department)) $poi->department = $existingItem->department;
                        if (isset($existingItem->location)) $poi->location = $existingItem->location;
                        $newItems[] = $poi;
                    }
                    
                    // Add converted items
                    foreach ($expensesToConvert as $conv) {
                        $expense = $conv['expense'];
                        $item = $conv['item'];
                        
                        $poi = new \PurchaseOrderItem();
                        $poi->line = $lineNumber++;
                        $poi->item = new \RecordRef();
                        $poi->item->internalId = $item->netsuite_id;
                        
                        // Calculate quantity from amount/rate if rate exists
                        if (isset($expense->amount)) {
                            $amount = (float)$expense->amount;
                            // Try to get rate from item or use 1
                            $rate = isset($expense->rate) ? (float)$expense->rate : ($item->base_price ?? 1.0);
                            if ($rate > 0) {
                                $poi->quantity = $amount / $rate;
                            } else {
                                $poi->quantity = 1;
                            }
                            $poi->rate = $rate > 0 ? $rate : $amount;
                        } else {
                            $poi->quantity = 1;
                            $poi->rate = $item->base_price ?? 0;
                        }
                        
                        if (isset($expense->memo)) {
                            $poi->description = $expense->memo;
                        }
                        
                        if (isset($expense->department)) {
                            $poi->department = $expense->department;
                        }
                        
                        if (isset($expense->location)) {
                            $poi->location = $expense->location;
                        }
                        
                        $newItems[] = $poi;
                    }
                    
                    // Update PO
                    $service = $netSuiteService->getService();
                    
                    // Verify internalId exists (required for update)
                    $internalId = $poRecord->internalId ?? null;
                    if (!$internalId) {
                        $this->newLine();
                        $this->warn("  Row {$rowNumber}: PO '{$poTranId}' missing internalId, skipping update");
                        $skippedCount++;
                        $progressBar->advance();
                        continue;
                    }
                    
                    $this->line("    PO Internal ID: {$internalId}");
                    
                    // Store critical fields that must be preserved
                    $entityRef = $poRecord->entity ?? null;
                    
                    // Set item list
                    $poRecord->itemList = new \PurchaseOrderItemList();
                    $poRecord->itemList->replaceAll = true;
                    $poRecord->itemList->item = $newItems;
                    
                    // Set expense list (only keep expenses that weren't converted)
                    if (!empty($expensesToKeep)) {
                        $poRecord->expenseList = new \PurchaseOrderExpenseList();
                        $poRecord->expenseList->replaceAll = true;
                        $poRecord->expenseList->expense = $expensesToKeep;
                    } else {
                        // No expenses left - set empty expense list
                        $poRecord->expenseList = new \PurchaseOrderExpenseList();
                        $poRecord->expenseList->replaceAll = true;
                        $poRecord->expenseList->expense = [];
                    }
                    
                    // Ensure internalId is explicitly set (critical for update, not create)
                    $poRecord->internalId = (string)$internalId;
                    
                    // Ensure entity is preserved (required field)
                    if ($entityRef) {
                        $poRecord->entity = $entityRef;
                    }
                    
                    // Ensure internalId is set on the record (critical for update)
                    // Don't modify other existing fields - preserve entity, location, department, etc.
                    
                    // Set preferences to allow updating (ignoreReadOnlyFields = true)
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
            $this->error('Error updating PO expenses to items: ' . $e->getMessage());
            Log::error('PO expense-to-item update error: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }
}
