<?php

namespace App\Console\Commands\NetSuite;

use App\Models\NetSuiteAccount;
use App\Models\NetSuiteCurrency;
use App\Models\NetSuiteDepartment;
use App\Models\NetSuiteItem;
use App\Models\NetSuiteLocation;
use App\Models\NetSuiteVendor;
use App\Services\BillService;
use App\Services\GoogleSheetsService;
use App\Services\NetSuiteService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SyncBillsFromSheets extends Command
{
    protected $signature = 'netsuite:sync-bills-from-sheets {--force : Force sync even if Bill column has value}';
    protected $description = 'Sync Vendor Bills from Google Sheets to NetSuite';

    public function handle(BillService $billService, NetSuiteService $netSuiteService)
    {
        $environment = config('netsuite.environment', 'sandbox');
        $isSandbox = $environment === 'sandbox';

        $spreadsheetId = $this->getBillSpreadsheetId($environment);
        $this->info("Using Bill spreadsheet ID: {$spreadsheetId}");
        $this->info("NetSuite Environment: {$environment}");

        // Normalize subcodes first (reuse PO normalization logic)
        $this->info('Normalizing subcodes to match NetSuite accounts...');
        $this->call('netsuite:normalize-bill-subcodes');

        $sheetsService = new GoogleSheetsService($spreadsheetId);

        $this->info('Reading PR sheet (bill headers)...');
        $prRows = $sheetsService->readSheet('PR');
        if (empty($prRows) || count($prRows) < 2) {
            $this->warn('No PR data found in sheet (need at least header + 1 row)');
            return Command::SUCCESS;
        }

        $headers = array_map('trim', $prRows[0]);
        $headerMap = array_flip($headers);
        $this->info('PR sheet headers found: ' . implode(', ', $headers));

        // Required headers: PR ID (or PR) and Payee/Vendor (or Vendor)
        $prIdHeader = $this->findHeaderIndex($headerMap, ['PR ID', 'PR', 'EPR', 'ID']);
        $vendorHeader = $this->findHeaderIndex($headerMap, ['Payee/Vendor', 'Payee', 'Vendor']);
        
        if ($prIdHeader === null) {
            $this->error("Required header 'PR ID' (or 'PR') not found in PR sheet");
            return Command::FAILURE;
        }
        if ($vendorHeader === null) {
            $this->error("Required header 'Payee/Vendor' (or 'Vendor') not found in PR sheet");
            return Command::FAILURE;
        }

        $billColumn = $this->findHeaderIndex($headerMap, ['Bill', 'Bill ID', 'Bill Number', 'NS Bill']);
        $timestampColumn = $this->findHeaderIndex($headerMap, ['Timestamp', 'Date', 'Created']);

        $this->info('Reading Line Item sheet...');
        $lineRows = $sheetsService->readSheet('Line Item');
        if (empty($lineRows) || count($lineRows) < 2) {
            $this->warn('No line item data found in Line Item sheet');
            return Command::SUCCESS;
        }

        $lineHeaders = array_map('trim', $lineRows[0]);
        $lineHeaderMap = array_flip($lineHeaders);
        $this->info('Line Item sheet columns found: ' . implode(', ', $lineHeaders));

        $prColumn = $this->findHeaderIndex($lineHeaderMap, ['PR ID', 'PR', 'EPR', 'ID']);
        $subcodeColumn = $this->findHeaderIndex($lineHeaderMap, ['Subcode', 'Account', 'Account Number']);
        $budgetCodeColumn = $this->findHeaderIndex($lineHeaderMap, ['Budget Code', 'Department', 'Dept']);
        // Location is optional for bills; many sheets won't have it.
        $locationColumn = $this->findHeaderIndex($lineHeaderMap, ['Location', 'Loc']);
        // Line description/memo: prefer Payment Reference, then Memo, then Name/Description.
        $lineMemoColumn = $this->findHeaderIndex($lineHeaderMap, ['Payment Reference', 'Memo', 'Name', 'Description', 'Item Name']);
        // Price is used as the line rate/amount (qty defaults to 1).
        $priceColumn = $this->findHeaderIndex($lineHeaderMap, ['Price', 'Rate', 'Amount', 'Unit Price', 'Unit Cost']);
        // Currency can be per-line; we’ll take the first non-empty per PR as bill currency (optional).
        $currencyColumn = $this->findHeaderIndex($lineHeaderMap, ['Currency', 'Currency Code']);
        $itemNumberColumn = $this->findHeaderIndex($lineHeaderMap, ['Item Number', 'Item', 'Reference']);

        if ($prColumn === null) {
            $this->error("PR column not found in Line Item sheet. Expected one of: PR, EPR, ID");
            return Command::FAILURE;
        }
        if ($subcodeColumn === null) {
            $this->error("Subcode/Account column not found in Line Item sheet.");
            return Command::FAILURE;
        }
        if ($budgetCodeColumn === null) {
            $this->error("Budget Code column not found in Line Item sheet.");
            return Command::FAILURE;
        }
        if ($lineMemoColumn === null) {
            $this->error("Payment Reference/Memo column not found in Line Item sheet.");
            return Command::FAILURE;
        }
        if ($priceColumn === null) {
            $this->error("Price/Rate column not found in Line Item sheet.");
            return Command::FAILURE;
        }

        $itemsByPR = [];
        $debugSampleShown = false;
        for ($i = 1; $i < count($lineRows); $i++) {
            $row = $lineRows[$i];
            if (empty($row) || !isset($row[$prColumn])) {
                continue;
            }

            $prId = trim($row[$prColumn]);
            if (empty($prId)) {
                continue;
            }

            if (!isset($itemsByPR[$prId])) {
                $itemsByPR[$prId] = [];
            }

            $priceRaw = $row[$priceColumn] ?? '';
            $price = !empty($priceRaw) ? (float) str_replace(',', '', trim($priceRaw)) : 0.0;

            // Bills sheet: Price is treated as the line rate/amount. Quantity defaults to 1.
            $quantity = 1.0;
            $unitPrice = $price;

            $itemData = [
                'name' => $row[$lineMemoColumn] ?? '',
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'amount' => $quantity * $unitPrice,
                'subcode' => trim($row[$subcodeColumn] ?? ''),
                'budget_code' => $budgetCodeColumn !== null ? trim($row[$budgetCodeColumn] ?? '') : '',
                'location' => $locationColumn !== null ? trim($row[$locationColumn] ?? '') : '',
                'currency' => $currencyColumn !== null ? trim($row[$currencyColumn] ?? '') : '',
            ];

            if ($itemNumberColumn !== null && isset($row[$itemNumberColumn])) {
                $itemData['item_number'] = trim($row[$itemNumberColumn]);
            }

            if (!$debugSampleShown && count($itemsByPR) <= 2) {
                $this->line("  Sample: PR '{$prId}' → Line '{$itemData['name']}' (Price: {$priceRaw} → {$unitPrice})");
                if (count($itemsByPR) == 2) {
                    $debugSampleShown = true;
                }
            }

            $itemsByPR[$prId][] = $itemData;
        }

        $poItemsMap = $this->loadPoItemsMap();
        $syncedRows = [];
        $syncedRowIndices = [];
        $errorRows = [];
        $skippedCount = 0;
        $emptyCount = 0;
        $alreadySyncedCount = 0;

        $dataRows = array_slice($prRows, 1);
        $totalRows = count($dataRows);
        $progressBar = $this->output->createProgressBar($totalRows);
        $progressBar->start();

        foreach ($dataRows as $index => $row) {
            $sheetRowNumber = $index + 2;

            // Get PR ID from the mapped column
            $prIdValue = null;
            foreach (['PR ID', 'PR', 'EPR', 'ID'] as $header) {
                if (isset($headerMap[$header]) && isset($row[$headerMap[$header]])) {
                    $prIdValue = trim($row[$headerMap[$header]]);
                    if (!empty($prIdValue)) {
                        break;
                    }
                }
            }

            if (empty($prIdValue)) {
                $emptyCount++;
                $progressBar->advance();
                continue;
            }

            $prId = $prIdValue;

            $existingBillTranId = $billColumn !== null ? trim($row[$billColumn] ?? '') : '';
            $billExists = false;
            $existingInfo = null;

            if (!$this->option('force')) {
                if (!empty($existingBillTranId)) {
                    $this->line("  Checking if Bill with Transaction ID '{$existingBillTranId}' exists in NetSuite ({$environment})...");
                    if ($netSuiteService->vendorBillExistsByTranId($existingBillTranId)) {
                        $billExists = true;
                        $existingInfo = "Transaction ID '{$existingBillTranId}'";
                    }
                }

                if (!$billExists) {
                    $this->line("  Checking if Bill memo '{$prId}' exists in NetSuite ({$environment})...");
                    if ($netSuiteService->vendorBillExistsByMemo($prId)) {
                        $billExists = true;
                        $existingInfo = "memo '{$prId}'";
                    }
                }

                if ($billExists) {
                    $this->warn("  ✗ Bill already exists in NetSuite (found by {$existingInfo}). Skipping.");
                    $timestamp = $this->getTimestampFromRow($row, $headerMap, $timestampColumn);
                    $vendorValue = $this->getFirstAvailable($row, $headerMap, ['Payee/Vendor', 'Payee', 'Vendor']) ?? '';
                    $errorRows[] = [
                        $timestamp,
                        $prId,
                        "Bill already exists in NetSuite (found by {$existingInfo})",
                        '',
                        $prId,
                        '', // Budget Code (from line items)
                        '', // Subcode (from line items)
                        '', // Location (from line items)
                        $vendorValue,
                    ];
                    $alreadySyncedCount++;
                    $progressBar->advance();
                    continue;
                }
            } else {
                if (!empty($existingBillTranId)) {
                    $this->warn("  Force mode: Re-syncing PR {$prId} (existing Bill: {$existingBillTranId})");
                }
            }

            $this->newLine();
            $this->line("Processing PR: {$prId}");

            try {
                // Get vendor from PR sheet (Payee/Vendor or Vendor column)
                $vendorName = $this->getFirstAvailable($row, $headerMap, ['Payee/Vendor', 'Payee', 'Vendor']);
                if (empty($vendorName)) {
                    throw new \Exception("Vendor/Payee not found in PR sheet");
                }
                $vendor = $this->findVendorFuzzy($vendorName, $isSandbox);
                if (!$vendor) {
                    throw new \Exception("Vendor '{$vendorName}' not found");
                }
                $this->line("  ✓ Vendor: {$vendor->name} ({$vendor->netsuite_id})");

                // Get memo from PR ID
                $memo = $prId;

                // Get transaction date from Timestamp column
                $trandate = $this->getDateFromRow($row, $headerMap, ['Timestamp', 'Transaction Date', 'Tran Date', 'Date', 'PR Date']);
                if (!$trandate) {
                    // If no timestamp, use current date
                    $trandate = date('Y-m-d\TH:i:s');
                }

                // Currency: prefer PR sheet value, otherwise take from the first line item (optional)
                $currency = null;
                $currencyValue = $this->getFirstAvailable($row, $headerMap, ['Currency', 'Currency Code', 'Currency ID']);
                if (empty($currencyValue) && !empty($items)) {
                    foreach ($items as $li) {
                        if (!empty($li['currency'])) {
                            $currencyValue = $li['currency'];
                            break;
                        }
                    }
                }
                if (!empty($currencyValue)) {
                    $currency = $this->findCurrency($currencyValue, $isSandbox);
                    if (!$currency) {
                        throw new \Exception("Currency '{$currencyValue}' not found. Please sync currencies.");
                    }
                    $this->line("  ✓ Currency: {$currency->name} ({$currency->currency_code})");
                }

                $supervisorId = $this->getFirstAvailable($row, $headerMap, ['Supervisor', 'Supervisor ID']) ?? '3467';
                
                $items = $itemsByPR[$prId] ?? [];
                if (empty($items)) {
                    throw new \Exception("No line items found for PR {$prId}");
                }
                
                // Set tranId: prefer existing bill ID or row value, otherwise use PR ID as document number
                $tranId = $existingBillTranId ?: $this->getFirstAvailable($row, $headerMap, ['Bill Ref', 'Reference', 'Reference No', 'Tran ID']);
                if (empty($tranId)) {
                    $tranId = $prId;
                }
                
                $duedate = $this->getDateFromRow($row, $headerMap, ['Due Date', 'Payment Due', 'Payment Due Date']);

                // Get Budget Code and Location from first line item (if available in Line Item sheet)
                // These will be used as defaults for all line items
                $defaultBudgetCode = null;
                $defaultLocation = null;
                if (!empty($items)) {
                    $firstItem = $items[0];
                    if (!empty($firstItem['budget_code'])) {
                        $defaultBudgetCode = $firstItem['budget_code'];
                    }
                    if (!empty($firstItem['location'])) {
                        $defaultLocation = $firstItem['location'];
                    }
                }

                $itemList = [];
                $expenses = [];
                foreach ($items as $item) {
                    // Get subcode from line item (required)
                    $lineSubcode = $item['subcode'];
                    if (empty($lineSubcode)) {
                        throw new \Exception("Subcode is required for line items in PR {$prId}");
                    }
                    $lineAccount = $this->findAccountBySubcode($lineSubcode, $isSandbox);
                    if (!$lineAccount) {
                        throw new \Exception("Account '{$lineSubcode}' not found for PR {$prId}");
                    }

                    // Get Budget Code and Location from line item, or use defaults
                    $lineBudgetCode = !empty($item['budget_code']) ? $item['budget_code'] : $defaultBudgetCode;
                    $lineLocationName = !empty($item['location']) ? $item['location'] : $defaultLocation;

                    // Find department (Budget Code)
                    $department = null;
                    if (!empty($lineBudgetCode)) {
                        $budgetCodePrefix = preg_replace('/[-_]\d+$/', '', $lineBudgetCode);
                        $budgetCodePrefix = rtrim($budgetCodePrefix, '-_');
                        
                        $department = NetSuiteDepartment::where('name', $lineBudgetCode)
                            ->where('is_sandbox', $isSandbox)
                            ->first();
                        
                        if (!$department) {
                            $department = NetSuiteDepartment::where('name', 'like', "{$budgetCodePrefix}%")
                                ->where('is_sandbox', $isSandbox)
                                ->first();
                        }
                        
                        if (!$department) {
                            $department = NetSuiteDepartment::where('name', 'like', "%{$budgetCodePrefix}%")
                                ->where('is_sandbox', $isSandbox)
                                ->first();
                        }
                    }
                    
                    if (!$department) {
                        $budgetCodeDisplay = !empty($lineBudgetCode) ? $lineBudgetCode : 'not provided';
                        throw new \Exception("Department/Budget Code not found for PR {$prId}. Budget Code: {$budgetCodeDisplay}");
                    }

                    // Find location (optional)
                    $location = null;
                    if (!empty($lineLocationName)) {
                        $location = NetSuiteLocation::where('name', $lineLocationName)
                            ->where('is_sandbox', $isSandbox)
                            ->first();
                        if (!$location && is_numeric($lineLocationName)) {
                            $location = NetSuiteLocation::where('netsuite_id', $lineLocationName)
                                ->where('is_sandbox', $isSandbox)
                                ->first();
                        }
                        if (!$location) {
                            $this->warn("  ⚠ Location '{$lineLocationName}' not found for PR {$prId}; continuing without location on this line.");
                        }
                    }

                    // Check if this subcode maps to an item (same logic as PO)
                    $netsuiteItem = null;
                    if (!empty($item['item_number'])) {
                        $netsuiteItem = NetSuiteItem::where('item_number', $item['item_number'])
                            ->where('is_sandbox', $isSandbox)
                            ->where('is_inactive', false)
                            ->first();

                        if (!$netsuiteItem) {
                            $netsuiteItem = NetSuiteItem::where('item_number', 'like', '%' . $item['item_number'] . '%')
                                ->where('is_sandbox', $isSandbox)
                                ->where('is_inactive', false)
                                ->first();
                        }
                    }

                    // If subcode maps to an item (from po_items.json), try to use it as item
                    if (!$netsuiteItem && !empty($lineAccount->account_number) && isset($poItemsMap[$lineAccount->account_number])) {
                        $mappedItemNumber = $poItemsMap[$lineAccount->account_number];
                        $netsuiteItem = NetSuiteItem::where(function ($q) use ($mappedItemNumber) {
                                $q->where('item_number', 'like', "%{$mappedItemNumber}%")
                                    ->orWhere('name', 'like', "%{$mappedItemNumber}%");
                            })
                            ->where('item_number', '!=', 'Teaching Materials_Sales')
                            ->where(function ($q) {
                                $q->whereNull('item_type')
                                    ->orWhere('item_type', 'like', '%noninventory%');
                            })
                            ->where('is_sandbox', $isSandbox)
                            ->where('is_inactive', false)
                            ->first();
                    }

                    if ($netsuiteItem && $netsuiteItem->item_number === 'Teaching Materials_Sales') {
                        $netsuiteItem = null;
                    }

                    // If item found, add to itemList; otherwise add to expenses
                    if ($netsuiteItem) {
                        $line = [
                            'item_id' => $netsuiteItem->netsuite_id,
                            'quantity' => $item['quantity'],
                            'rate' => $item['unit_price'],
                            'description' => $item['name'],
                            'department_id' => $department->netsuite_id,
                        ];
                        if ($location) {
                            $line['location_id'] = $location->netsuite_id;
                        }
                        $itemList[] = $line;
                    } else {
                        $line = [
                            'account_id' => $lineAccount->netsuite_id,
                            'amount' => $item['amount'],
                            'memo' => $item['name'],
                            'department_id' => $department->netsuite_id,
                        ];
                        if ($location) {
                            $line['location_id'] = $location->netsuite_id;
                        }
                        $expenses[] = $line;
                    }
                }

                if (empty($itemList) && empty($expenses)) {
                    throw new \Exception("No valid item or expense lines for PR {$prId}");
                }

                $billData = [
                    'vendor_id' => $vendor->netsuite_id,
                    'memo' => $memo,
                    'supervisor_id' => $supervisorId,
                    'trandate' => $trandate,
                ];
                
                // Only set tran_id if provided (let NetSuite auto-generate if blank)
                if (!empty($tranId)) {
                    $billData['tran_id'] = $tranId;
                }
                
                // Only set duedate if provided
                if (!empty($duedate)) {
                    $billData['duedate'] = $duedate;
                }

                if (!empty($itemList)) {
                    $billData['items'] = $itemList;
                }
                if (!empty($expenses)) {
                    $billData['expenses'] = $expenses;
                }
                if ($currency) {
                    $billData['currency_id'] = $currency->netsuite_id;
                }

                $this->line("  Creating Bill in NetSuite...");
                $result = $billService->createFromArray($billData);

                if ($result['success']) {
                    $tranIdResult = $result['transaction_id'] ?? null;
                    $internalId = $result['internal_id'] ?? null;
                    $this->line("  ✓ Bill created successfully! Internal ID: {$internalId}" . ($tranIdResult ? ", Transaction ID: {$tranIdResult}" : ''));
                    $syncedRow = $row;
                    if ($billColumn !== null) {
                        $syncedRow[$billColumn] = $tranIdResult ?? $internalId;
                    }
                    $syncedRows[] = $syncedRow;
                    $syncedRowIndices[] = $sheetRowNumber;
                } else {
                    $timestamp = $this->getTimestampFromRow($row, $headerMap, $timestampColumn);
                    $vendorValue = $this->getFirstAvailable($row, $headerMap, ['Payee/Vendor', 'Payee', 'Vendor']) ?? '';
                    $errorRows[] = [
                        $timestamp,
                        $prId,
                        $result['error'] ?? 'Unknown error',
                        $result['netsuite_response'] ?? '',
                        $prId,
                        '', // Budget Code (from line items)
                        '', // Subcode (from line items)
                        '', // Location (from line items)
                        $vendorValue,
                    ];
                    $this->warn("Failed to create Bill {$prId}: " . ($result['error'] ?? 'Unknown error'));
                }
            } catch (\Exception $e) {
                $timestamp = $this->getTimestampFromRow($row, $headerMap, $timestampColumn);
                $vendorValue = $this->getFirstAvailable($row, $headerMap, ['Payee/Vendor', 'Payee', 'Vendor']) ?? '';
                $errorRows[] = [
                    $timestamp,
                    $prId,
                    $e->getMessage(),
                    '',
                    $prId,
                    '', // Budget Code (from line items)
                    '', // Subcode (from line items)
                    '', // Location (from line items)
                    $vendorValue,
                ];

                $this->warn("Error processing PR {$prId}: " . $e->getMessage());
            }

            $progressBar->advance();
        }

        $progressBar->finish();
        $this->newLine(2);

        $this->info("Summary:");
        $this->info("  Total rows in sheet: " . (count($prRows) - 1));
        $this->info("  Empty/invalid rows: " . $emptyCount);
        $this->info("  Already synced: " . $alreadySyncedCount);
        $this->info("  Attempted to sync: " . (count($prRows) - 1 - $emptyCount - $alreadySyncedCount));
        $this->info("  Successfully synced: " . count($syncedRows));
        $this->info("  Errors: " . count($errorRows));
        $this->newLine();

        if (!empty($syncedRows)) {
            $this->info("Moving " . count($syncedRows) . " synced Bill(s) to 'Synced' sheet...");
            // Append rows without header (header already exists in the sheet)
            $sheetsService->appendToSheet('Synced', $syncedRows);

            if (!empty($syncedRowIndices)) {
                rsort($syncedRowIndices);
                foreach ($syncedRowIndices as $rowIndex) {
                    try {
                        $sheetsService->deleteRows('PR', $rowIndex, 1);
                        usleep(100000);
                    } catch (\Exception $e) {
                        $this->warn("  Failed to delete row {$rowIndex} from PR sheet: " . $e->getMessage());
                        Log::error("Failed to delete row {$rowIndex} from PR sheet: " . $e->getMessage());
                    }
                }
                $this->info("✓ Removed " . count($syncedRowIndices) . " row(s) from 'PR' sheet");
            }
        }

        if (!empty($errorRows)) {
            $this->info("Appending " . count($errorRows) . " error(s) to 'Errors' sheet...");
            // Append rows without header (header already exists in the sheet)
            $sheetsService->appendToSheet('Errors', $errorRows);
            $this->warn("✗ " . count($errorRows) . " Bill(s) failed - see 'Errors' sheet");
        }

        $this->info("Bill sync completed!");
        return Command::SUCCESS;
    }

    protected function getBillSpreadsheetId(string $environment): string
    {
        if ($environment === 'production') {
            $id = config('google-sheets.prod_bill_spreadsheet_id');
        } else {
            $id = config('google-sheets.bill_spreadsheet_id');
        }

        if (empty($id)) {
            $envVar = $environment === 'production' ? 'PROD_BILL_SHEET_ID' : 'SANDBOX_BILL_SHEET_ID';
            throw new \Exception("Bill Spreadsheet ID not configured. Set {$envVar} in .env");
        }

        return $id;
    }

    protected function findHeaderIndex(array $headerMap, array $candidates): ?int
    {
        foreach ($candidates as $candidate) {
            foreach ($headerMap as $header => $idx) {
                if (strcasecmp($header, $candidate) === 0) {
                    return $idx;
                }
            }
        }
        return null;
    }

    protected function hasHeader(array $headerMap, string $name): bool
    {
        return $this->findHeaderIndex($headerMap, [$name]) !== null;
    }

    protected function getTimestampFromRow(array $row, array $headerMap, ?int $timestampColumn = null): string
    {
        if ($timestampColumn !== null && isset($row[$timestampColumn]) && !empty($row[$timestampColumn])) {
            return trim($row[$timestampColumn]);
        }

        $possibleHeaders = ['Timestamp', 'timestamp', 'Time', 'Date', 'Created'];
        foreach ($possibleHeaders as $headerName) {
            if (isset($headerMap[$headerName])) {
                $value = trim($row[$headerMap[$headerName]] ?? '');
                if (!empty($value)) {
                    return $value;
                }
            }
            foreach ($headerMap as $header => $index) {
                if (strcasecmp($header, $headerName) === 0) {
                    $value = trim($row[$index] ?? '');
                    if (!empty($value)) {
                        return $value;
                    }
                }
            }
        }

        return date('Y-m-d H:i:s');
    }

    protected function findVendorFuzzy(string $vendorName, bool $isSandbox)
    {
        $vendorName = trim($vendorName);
        if (empty($vendorName)) {
            return null;
        }

        $vendor = NetSuiteVendor::where('name', $vendorName)
            ->where('is_sandbox', $isSandbox)
            ->first();
        if ($vendor) {
            return $vendor;
        }

        $vendor = NetSuiteVendor::whereRaw('UPPER(name) = ?', [strtoupper($vendorName)])
            ->where('is_sandbox', $isSandbox)
            ->first();
        if ($vendor) {
            return $vendor;
        }

        $vendor = NetSuiteVendor::where('name', 'like', "%{$vendorName}%")
            ->where('is_sandbox', $isSandbox)
            ->first();
        if ($vendor) {
            return $vendor;
        }

        $driver = \DB::connection()->getDriverName();
        $concatSql = $driver === 'sqlite'
            ? "'%' || name || '%'"
            : 'CONCAT("%", name, "%")';
        $vendor = NetSuiteVendor::whereRaw("? LIKE {$concatSql}", [$vendorName])
            ->where('is_sandbox', $isSandbox)
            ->first();
        if ($vendor) {
            return $vendor;
        }

        $normalizedName = preg_replace('/\s*\([^)]*\)\s*/', '', $vendorName);
        $normalizedName = trim($normalizedName);
        if ($normalizedName !== $vendorName && !empty($normalizedName)) {
            $vendor = NetSuiteVendor::where('name', $normalizedName)
                ->where('is_sandbox', $isSandbox)
                ->first();
            if ($vendor) {
                return $vendor;
            }

            $vendor = NetSuiteVendor::whereRaw('UPPER(name) = ?', [strtoupper($normalizedName)])
                ->where('is_sandbox', $isSandbox)
                ->first();
            if ($vendor) {
                return $vendor;
            }

            $vendor = NetSuiteVendor::where('name', 'like', "%{$normalizedName}%")
                ->where('is_sandbox', $isSandbox)
                ->first();
            if ($vendor) {
                return $vendor;
            }

            $vendor = NetSuiteVendor::whereRaw("? LIKE {$concatSql}", [$normalizedName])
                ->where('is_sandbox', $isSandbox)
                ->first();
            if ($vendor) {
                return $vendor;
            }
        }

        $words = preg_split('/[\s\-_]+/', $normalizedName ?: $vendorName);
        $words = array_filter($words, function ($word) {
            return strlen($word) > 2;
        });

        if (count($words) > 0) {
            usort($words, function ($a, $b) {
                return strlen($b) - strlen($a);
            });
            foreach ($words as $word) {
                $vendor = NetSuiteVendor::where('name', 'like', "%{$word}%")
                    ->where('is_sandbox', $isSandbox)
                    ->first();
                if ($vendor) {
                    return $vendor;
                }
            }
        }

        return null;
    }

    protected function loadPoItemsMap(): array
    {
        try {
            $path = base_path('po_items.json');
            if (!file_exists($path)) {
                $this->warn("po_items.json not found at {$path}, skipping special item mapping.");
                return [];
            }

            $data = json_decode(file_get_contents($path), true);
            if (!is_array($data)) {
                $this->warn('po_items.json could not be parsed, skipping special item mapping.');
                return [];
            }

            $map = [];
            foreach ($data as $entry) {
                if (isset($entry['account_number']) && isset($entry['name'])) {
                    $map[trim((string) $entry['account_number'])] = trim((string) $entry['name']);
                }
            }

            return $map;
        } catch (\Exception $e) {
            $this->warn('Failed to load po_items.json: ' . $e->getMessage());
            return [];
        }
    }

    protected function findAccountBySubcode(string $subcode, bool $isSandbox): ?NetSuiteAccount
    {
        $subcode = trim($subcode);
        if (empty($subcode)) {
            return null;
        }

        $account = NetSuiteAccount::where('name', $subcode)
            ->where('is_sandbox', $isSandbox)
            ->first();

        if (!$account) {
            $account = NetSuiteAccount::where('account_number', $subcode)
                ->where('is_sandbox', $isSandbox)
                ->first();
        }

        if (!$account) {
            $account = NetSuiteAccount::where('name', 'like', "{$subcode}%")
                ->where('is_sandbox', $isSandbox)
                ->first();
        }

        if (!$account) {
            $account = NetSuiteAccount::where('name', 'like', "%{$subcode}%")
                ->where('is_sandbox', $isSandbox)
                ->first();
        }

        return $account;
    }

    protected function findCurrency(string $currencyCode, bool $isSandbox): ?NetSuiteCurrency
    {
        $normalizedCode = strtoupper(trim($currencyCode));

        $currency = NetSuiteCurrency::where('currency_code', $normalizedCode)
            ->where('is_sandbox', $isSandbox)
            ->whereNotNull('currency_code')
            ->first();

        if (!$currency) {
            $currency = NetSuiteCurrency::whereRaw('UPPER(currency_code) = ?', [$normalizedCode])
                ->where('is_sandbox', $isSandbox)
                ->whereNotNull('currency_code')
                ->first();
        }

        if (!$currency) {
            $currency = NetSuiteCurrency::where('name', $currencyCode)
                ->where('is_sandbox', $isSandbox)
                ->first();
        }

        if (!$currency) {
            $searchTerms = [];
            if (stripos($normalizedCode, 'GBP') !== false || stripos($currencyCode, 'POUND') !== false) {
                $searchTerms = ['Pound', 'Poundsterling', 'Pound Sterling', 'British', 'Great Britain'];
            } elseif (stripos($normalizedCode, 'USD') !== false || stripos($currencyCode, 'DOLLAR') !== false) {
                $searchTerms = ['Dollar', 'US Dollar'];
            } elseif (stripos($normalizedCode, 'EUR') !== false || stripos($currencyCode, 'EURO') !== false) {
                $searchTerms = ['Euro'];
            } elseif (stripos($normalizedCode, 'MYR') !== false || stripos($currencyCode, 'RINGGIT') !== false) {
                $searchTerms = ['Ringgit', 'Malaysian'];
            } elseif (stripos($normalizedCode, 'SGD') !== false || stripos($currencyCode, 'SINGAPORE') !== false) {
                $searchTerms = ['Singapore'];
            }

            if (!empty($searchTerms)) {
                $currency = NetSuiteCurrency::where(function ($query) use ($searchTerms) {
                        foreach ($searchTerms as $term) {
                            $query->orWhere('name', 'like', "%{$term}%");
                        }
                    })
                    ->where('is_sandbox', $isSandbox)
                    ->first();
            }
        }

        return $currency;
    }

    protected function getFirstAvailable(array $row, array $headerMap, array $candidates)
    {
        foreach ($candidates as $candidate) {
            foreach ($headerMap as $header => $idx) {
                if (strcasecmp($header, $candidate) === 0 && isset($row[$idx]) && trim($row[$idx]) !== '') {
                    return trim($row[$idx]);
                }
            }
        }
        return null;
    }

    protected function getDateFromRow(array $row, array $headerMap, array $candidates): ?string
    {
        $dateValue = $this->getFirstAvailable($row, $headerMap, $candidates);
        if (empty($dateValue)) {
            return null;
        }

        $dateValue = trim($dateValue);
        
        // Remove timezone name if present (e.g., "2026-01-19T07:45:33-08:00 Asia/Kuala_Lumpur" -> "2026-01-19T07:45:33-08:00")
        // Timezone names are typically after a space at the end
        if (preg_match('/^(.+)\s+[A-Z][a-z]+\/[A-Z_]+$/', $dateValue, $matches)) {
            $dateValue = $matches[1];
        }
        
        // If it already contains 'T', validate and clean it
        if (strpos($dateValue, 'T') !== false) {
            // Extract the datetime part and timezone offset if present
            // Format: YYYY-MM-DDTHH:MM:SS or YYYY-MM-DDTHH:MM:SS+HH:MM or YYYY-MM-DDTHH:MM:SS-HH:MM or YYYY-MM-DDTHH:MM:SSZ
            if (preg_match('/^(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2})([+-]\d{2}:\d{2}|Z)?$/', $dateValue, $matches)) {
                // Valid ISO 8601 format with optional timezone
                return $dateValue;
            } elseif (preg_match('/^(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2})/', $dateValue, $matches)) {
                // Has T but might have invalid trailing characters, extract just the valid part
                return $matches[1];
            }
            // If it has T but doesn't match patterns above, try to parse and reformat
        }

        // Check if it's just a date (YYYY-MM-DD)
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateValue)) {
            return $dateValue . 'T00:00:00';
        }

        // Try to parse as a timestamp/date string
        // First, try to remove any timezone info for strtotime
        $cleanDateValue = preg_replace('/\s+[A-Z][a-z]+\/[A-Z_]+$/', '', $dateValue);
        $timestamp = strtotime($cleanDateValue);
        if ($timestamp !== false) {
            // Return in ISO 8601 format without timezone (NetSuite can handle this)
            return date('Y-m-d\TH:i:s', $timestamp);
        }

        return null;
    }
}


