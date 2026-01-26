<?php

namespace App\Console\Commands\NetSuite;

use App\Models\NetSuiteAccount;
use App\Models\NetSuiteCurrency;
use App\Models\NetSuiteDepartment;
use App\Models\NetSuiteItem;
use App\Models\NetSuiteLocation;
use App\Models\NetSuiteVendor;
use App\Services\GoogleSheetsService;
use App\Services\NetSuiteService;
use App\Services\PurchaseOrderService;
use Illuminate\Console\Command;

class SyncPOsFromSheets extends Command
{
    protected $signature = 'netsuite:sync-pos-from-sheets {--force : Force sync even if PO column has value}';
    protected $description = 'Sync Purchase Orders from Google Sheets to NetSuite';

    public function handle(GoogleSheetsService $sheetsService, PurchaseOrderService $poService, NetSuiteService $netSuiteService)
    {
        $this->info('Starting PO sync from Google Sheets...');

        // Normalize subcode to match NetSuite accounts
        $this->info('Normalizing subcodes to match NetSuite accounts...');
        $this->call('netsuite:normalize-po-subcodes');

        try {
            // Read PO sheet
            $this->info('Reading PO sheet...');
            $poRows = $sheetsService->readSheet('PO');
            
            if (empty($poRows) || count($poRows) < 2) {
                $this->warn('No PO data found in sheet (need at least header + 1 row)');
                return Command::SUCCESS;
            }
            
            // Get headers (first row)
            $headers = array_map('trim', $poRows[0]);
            $headerMap = array_flip($headers);

            // Debug: Show all detected headers
            $this->info('PO sheet headers found: ' . implode(', ', $headers));

            // Check for Timestamp header
            $hasTimestamp = false;
            foreach ($headers as $header) {
                if (strcasecmp($header, 'Timestamp') === 0) {
                    $hasTimestamp = true;
                    $this->info("✓ Timestamp header found at column " . ($headerMap[$header] + 1) . " (column " . chr(65 + $headerMap[$header]) . ")");
                    break;
                }
            }
            if (!$hasTimestamp) {
                $this->warn("⚠ Timestamp header not found - will use current date/time for error rows");
            }

            // Validate required headers
            $requiredHeaders = ['ID', 'Budget Code', 'Subcode', 'Location', 'Vendor', 'Currency'];
            foreach ($requiredHeaders as $header) {
                if (!isset($headerMap[$header])) {
                    $this->error("Required header '{$header}' not found in PO sheet");
                    return Command::FAILURE;
                }
            }
            
            // Read Items sheet
            $this->info('Reading Items sheet...');
            $itemRows = $sheetsService->readSheet('Items');
            
            if (empty($itemRows) || count($itemRows) < 2) {
                $this->warn('No item data found in Items sheet');
                return Command::SUCCESS;
            }
            
            $itemHeaders = array_map('trim', $itemRows[0]);
            $itemHeaderMap = array_flip($itemHeaders);

            // Debug: Show all column headers
            $this->info('Items sheet columns found: ' . implode(', ', $itemHeaders));

            // Find EPR column
            $eprColumn = null;
            foreach (['EPR', 'ID', 'EPR ID'] as $col) {
                if (isset($itemHeaderMap[$col])) {
                    $eprColumn = $col;
                    break;
                }
            }

            // Find Name column
            $nameColumn = null;
            foreach (['Name', 'Item Name', 'Description'] as $col) {
                if (isset($itemHeaderMap[$col])) {
                    $nameColumn = $col;
                    break;
                }
            }

            // Find Quantity column
            $quantityColumn = null;
            foreach (['Quantity', 'Qty', 'QTY'] as $col) {
                if (isset($itemHeaderMap[$col])) {
                    $quantityColumn = $col;
                    break;
                }
            }

            // Find Unit Price column
            $unitPriceColumn = null;
            foreach (['Unit Price', 'Price', 'Rate', 'Unit Cost'] as $col) {
                if (isset($itemHeaderMap[$col])) {
                    $unitPriceColumn = $col;
                    break;
                }
            }

            // Validate required columns
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

            // Check if Item Number/Reference column exists (optional)
            $itemNumberColumn = null;
            foreach (['Item Number', 'Item', 'Reference'] as $col) {
                if (isset($itemHeaderMap[$col])) {
                    $itemNumberColumn = $col;
                    break;
                }
            }
            
            // Find Discount column (column G is index 6)
            $discountColumn = null;
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
            
            if ($discountColumn) {
                $this->info("Using Discount column: '{$discountColumn}' (will apply discount if > 0)");
            } else {
                $this->info("No Discount column found - will use unit price as-is");
            }
            
            // Group items by EPR (PO ID)
            $itemsByPO = [];
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

                if (!isset($itemsByPO[$eprId])) {
                    $itemsByPO[$eprId] = [];
                }

                // Read quantity and unit price with proper parsing
                $quantityRaw = $row[$itemHeaderMap[$quantityColumn]] ?? '';
                $unitPriceRaw = $row[$itemHeaderMap[$unitPriceColumn]] ?? '';

                // Clean and parse values (remove commas, spaces)
                $quantity = !empty($quantityRaw) ? (float) str_replace(',', '', trim($quantityRaw)) : 1.0;
                $unitPrice = !empty($unitPriceRaw) ? (float) str_replace(',', '', trim($unitPriceRaw)) : 0.0;

                // Read discount from column G (index 6) if available
                $discount = 0.0;
                if ($discountColumn && isset($itemHeaderMap[$discountColumn]) && isset($row[$itemHeaderMap[$discountColumn]])) {
                    $discountRaw = $row[$itemHeaderMap[$discountColumn]] ?? '';
                    $discount = !empty($discountRaw) ? (float) str_replace(',', '', trim($discountRaw)) : 0.0;
                }

                // Apply discount if > 0: adjust unit price = (unit_price * quantity - discount) / quantity
                $originalUnitPrice = $unitPrice;
                if ($discount > 0.01 && $quantity > 0) {
                    $totalBeforeDiscount = $unitPrice * $quantity;
                    $totalAfterDiscount = $totalBeforeDiscount - $discount;
                    $unitPrice = $totalAfterDiscount / $quantity;
                    
                    // Ensure non-negative
                    if ($unitPrice < 0) {
                        $unitPrice = 0;
                    }
                }

                $itemData = [
                    'name' => $row[$itemHeaderMap[$nameColumn]] ?? '',
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'amount' => $quantity * $unitPrice,
                    'original_unit_price' => $originalUnitPrice,
                    'discount' => $discount,
                ];

                // Add item number/reference if available
                if ($itemNumberColumn && isset($row[$itemHeaderMap[$itemNumberColumn]])) {
                    $itemData['item_number'] = trim($row[$itemHeaderMap[$itemNumberColumn]]);
                }

                // Debug: Show first few items to verify parsing
                if (!$debugSampleShown && count($itemsByPO) <= 2) {
                    $discountInfo = $discount > 0.01 ? " (Discount: {$discount}, Adjusted Price: {$originalUnitPrice} → {$unitPrice})" : "";
                    $this->line("  Sample: EPR '{$eprId}' → Item '{$itemData['name']}' (Qty: {$quantityRaw} → {$quantity}, Price: {$unitPriceRaw} → {$unitPrice}){$discountInfo}");
                    if (count($itemsByPO) == 2) {
                        $debugSampleShown = true;
                    }
                }

                $itemsByPO[$eprId][] = $itemData;
            }
            
            $isSandbox = config('netsuite.environment') === 'sandbox';
            $environment = $isSandbox ? 'sandbox' : 'production';
            $syncedRows = [];
            $syncedRowIndices = []; // Track row indices (1-based, including header) for deletion
            $errorRows = [];
            
            // Process each PO
            $this->info("Processing POs in {$environment} environment...");
            $skippedCount = 0;
            $emptyCount = 0;
            $alreadySyncedCount = 0;
            
            // Process each PO with row index tracking
            $dataRows = array_slice($poRows, 1);
            $totalRows = count($dataRows);
            $progressBar = $this->output->createProgressBar($totalRows);
            $progressBar->start();

        // Preload PO items mapping (account_number -> item_number/name)
        $poItemsMap = $this->loadPoItemsMap();
            
            foreach ($dataRows as $index => $row) {
                // Calculate sheet row number (index + 2: 0-based array index + 1 for header + 1 for 1-based sheet)
                $sheetRowNumber = $index + 2;
                
                if (empty($row) || !isset($row[$headerMap['ID']])) {
                    $emptyCount++;
                    $progressBar->advance();
                    continue;
                }
                
                $poId = trim($row[$headerMap['ID']]);
                if (empty($poId)) {
                    $emptyCount++;
                    $progressBar->advance();
                    continue;
                }
                
                // Check if PO already exists in NetSuite
                // Check by document number (tranId) first, then by memo
                $existingTranId = isset($headerMap['PO']) && isset($row[$headerMap['PO']]) 
                    ? trim($row[$headerMap['PO']] ?? '') 
                    : '';
                
                $poExists = false;
                $existingPoInfo = null;
                
                if (!$this->option('force')) {
                    // Check by document number (tranId) first if PO column has a value
                    if (!empty($existingTranId)) {
                        $this->line("  Checking if PO with Transaction ID (document number) '{$existingTranId}' exists in NetSuite ({$environment})...");
                        if ($netSuiteService->purchaseOrderExistsByTranId($existingTranId)) {
                            $poExists = true;
                            $existingPoInfo = "Transaction ID (document number) '{$existingTranId}' in {$environment}";
                        } else {
                            $this->line("  ✓ Transaction ID (document number) '{$existingTranId}' not found in NetSuite ({$environment})");
                        }
                    }
                    
                    // Also check if PO ID exists as a document number (tranId) in NetSuite
                    if (!$poExists) {
                        $this->line("  Checking if PO ID '{$poId}' exists as document number in NetSuite ({$environment})...");
                        if ($netSuiteService->purchaseOrderExistsByTranId($poId)) {
                            $poExists = true;
                            $existingPoInfo = "document number '{$poId}' in {$environment}";
                        } else {
                            $this->line("  ✓ PO ID '{$poId}' not found as document number in NetSuite ({$environment})");
                        }
                    }
                    
                    // Then check by memo (fallback - memo contains the sheet PO ID, which we set when creating)
                    if (!$poExists) {
                        $this->line("  Checking if PO with memo '{$poId}' exists in NetSuite ({$environment})...");
                        if ($netSuiteService->purchaseOrderExistsByMemo($poId)) {
                            $poExists = true;
                            $existingPoInfo = "memo '{$poId}' in {$environment}";
                        } else {
                            $this->line("  ✓ PO with memo '{$poId}' not found in NetSuite ({$environment})");
                        }
                    }
                    
                    // If PO exists, skip and add to Errors sheet
                    if ($poExists) {
                        $this->warn("  ✗ PO already exists in NetSuite (found by {$existingPoInfo}). Skipping.");

                        // Get timestamp from sheet or use current time
                        $timestamp = $this->getTimestampFromRow($row, $headerMap);
                        $this->line("  Using timestamp: {$timestamp}");

                        // Add to Errors sheet with appropriate message
                        $errorRow = [
                            $timestamp, // Timestamp
                            $poId, // PO_ID
                            "PO already exists in NetSuite (found by {$existingPoInfo})", // Error_Message
                            '', // NetSuite_Response
                            $row[$headerMap['ID']] ?? '', // ID
                            $row[$headerMap['Name']] ?? '', // Name
                            $row[$headerMap['Budget Code']] ?? '', // Budget Code
                            $row[$headerMap['Subcode']] ?? '', // Subcode
                            $row[$headerMap['Location']] ?? '', // Location
                            $row[$headerMap['Vendor']] ?? '', // Vendor
                        ];
                        $errorRows[] = $errorRow;
                        $alreadySyncedCount++;
                        $progressBar->advance();
                        continue; // Continue to next row
                    } else {
                        $this->line("  ✓ No existing PO found, proceeding with creation...");
                    }
                } else {
                    // Force mode
                    if (!empty($existingTranId)) {
                        $this->newLine();
                        $this->warn("  Force mode: Re-syncing PO {$poId} (existing Transaction ID: {$existingTranId})");
                    }
                }
                
                // Debug: Log that we're processing this PO
                $this->newLine();
                $this->line("Processing PO: {$poId}");
                
                try {
                    // Get department (Budget Code) - fuzzy search
                    $budgetCode = trim($row[$headerMap['Budget Code']] ?? '');
                    $this->line("  Looking up Department: {$budgetCode}");
                    
                    // Try exact match first
                    $department = NetSuiteDepartment::where('name', $budgetCode)
                        ->where('is_sandbox', $isSandbox)
                        ->first();
                    
                    // If not found, try fuzzy match (starts with budget code)
                    if (!$department) {
                        // Remove trailing numbers/dashes for fuzzy matching (e.g., "JB-C030-26" -> "JB-C030")
                        $budgetCodePrefix = preg_replace('/[-_]\d+$/', '', $budgetCode);
                        $budgetCodePrefix = rtrim($budgetCodePrefix, '-_');
                        
                        $department = NetSuiteDepartment::where('name', 'like', "{$budgetCodePrefix}%")
                            ->where('is_sandbox', $isSandbox)
                            ->first();
                    }
                    
                    // If still not found, try contains match
                    if (!$department) {
                        $department = NetSuiteDepartment::where('name', 'like', "%{$budgetCodePrefix}%")
                            ->where('is_sandbox', $isSandbox)
                            ->first();
                    }
                    
                    if (!$department) {
                        $this->warn("  ✗ Department '{$budgetCode}' not found (tried exact, prefix, and contains match)");
                        throw new \Exception("Department '{$budgetCode}' not found");
                    }
                    $this->line("  ✓ Department found: {$department->name} (ID: {$department->netsuite_id})");
                    
                    // Get account (Subcode) - fuzzy search by name or account number
                    $subcode = trim($row[$headerMap['Subcode']] ?? '');
                    $this->line("  Looking up Account: {$subcode}");
                    
                    // Try exact match by name first
                    $account = NetSuiteAccount::where('name', $subcode)
                        ->where('is_sandbox', $isSandbox)
                        ->first();
                    
                    // Try by account number
                    if (!$account) {
                        $account = NetSuiteAccount::where('account_number', $subcode)
                            ->where('is_sandbox', $isSandbox)
                            ->first();
                    }
                    
                    // Try fuzzy match - name starts with subcode
                    if (!$account) {
                        $account = NetSuiteAccount::where('name', 'like', "{$subcode}%")
                            ->where('is_sandbox', $isSandbox)
                            ->first();
                    }
                    
                    // Try fuzzy match - name contains subcode
                    if (!$account) {
                        $account = NetSuiteAccount::where('name', 'like', "%{$subcode}%")
                            ->where('is_sandbox', $isSandbox)
                            ->first();
                    }
                    
                    if (!$account) {
                        $this->warn("  ✗ Account '{$subcode}' not found (tried name, account_number, and fuzzy match)");
                        throw new \Exception("Account '{$subcode}' not found");
                    }
                    $this->line("  ✓ Account found: {$account->name} (ID: {$account->netsuite_id})");
                    
                    // Get location
                    $locationName = trim($row[$headerMap['Location']] ?? '');
                    $location = NetSuiteLocation::where('name', $locationName)
                        ->where('is_sandbox', $isSandbox)
                        ->first();
                    
                    if (!$location) {
                        // Try to find by ID if location is a number
                        if (is_numeric($locationName)) {
                            $location = NetSuiteLocation::where('netsuite_id', $locationName)
                                ->where('is_sandbox', $isSandbox)
                                ->first();
                        }
                    }
                    
                    if (!$location) {
                        throw new \Exception("Location '{$locationName}' not found");
                    }
                    
                    // Get vendor with improved fuzzy search
                    $vendorName = trim($row[$headerMap['Vendor']] ?? '');
                    $this->line("  Looking up Vendor: {$vendorName}");
                    $vendor = $this->findVendorFuzzy($vendorName, $isSandbox);
                    
                    if (!$vendor) {
                        $this->warn("  ✗ Vendor '{$vendorName}' not found (tried exact, contains, and fuzzy match)");
                        throw new \Exception("Vendor '{$vendorName}' not found");
                    }
                    $this->line("  ✓ Vendor found: {$vendor->name} (ID: {$vendor->netsuite_id})");
                    
                    // Get currency - use stored currency_code from database
                    $currencyCode = trim($row[$headerMap['Currency']] ?? '');
                    $currency = null;
                    
                    if (!empty($currencyCode)) {
                        $this->line("  Looking up Currency: {$currencyCode}");
                        
                        // Normalize currency code (uppercase)
                        $normalizedCode = strtoupper(trim($currencyCode));
                        
                        // First, try exact match by currency_code (this is what we want - use the database!)
                        $currency = NetSuiteCurrency::where('currency_code', $normalizedCode)
                            ->where('is_sandbox', $isSandbox)
                            ->whereNotNull('currency_code')
                            ->first();
                        
                        // Try case-insensitive match by currency_code
                        if (!$currency) {
                            $currency = NetSuiteCurrency::whereRaw('UPPER(currency_code) = ?', [$normalizedCode])
                                ->where('is_sandbox', $isSandbox)
                                ->whereNotNull('currency_code')
                                ->first();
                        }
                        
                        // Fallback: try exact match by name (in case currency_code not populated yet)
                        if (!$currency) {
                            $currency = NetSuiteCurrency::where('name', $currencyCode)
                                ->where('is_sandbox', $isSandbox)
                                ->first();
                        }
                        
                        // Last resort: fuzzy match by name (only if currency_code not in DB yet)
                        if (!$currency) {
                            $searchTerms = [];
                            
                            // Map common currency codes to search terms
                            if (stripos($normalizedCode, 'GBP') !== false || stripos($currencyCode, 'POUND') !== false || stripos($currencyCode, 'STERLING') !== false) {
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
                                $currency = NetSuiteCurrency::where(function($query) use ($searchTerms) {
                                    foreach ($searchTerms as $term) {
                                        $query->orWhere('name', 'like', "%{$term}%");
                                    }
                                })
                                ->where('is_sandbox', $isSandbox)
                                ->first();
                            }
                        }
                        
                        if (!$currency) {
                            $this->warn("  ✗ Currency '{$currencyCode}' not found");
                            throw new \Exception("Currency '{$currencyCode}' not found. Please run 'php artisan netsuite:sync-currencies' to update currency codes in database.");
                        }
                        
                        $currencyCodeDisplay = $currency->currency_code ? "({$currency->currency_code})" : "(no code)";
                        $this->line("  ✓ Currency found: {$currency->name} {$currencyCodeDisplay} (ID: {$currency->netsuite_id})");
                        
                        // Validate vendor supports this currency
                        if ($vendor->supported_currencies && is_array($vendor->supported_currencies) && count($vendor->supported_currencies) > 0) {
                            // Check both by ID and by name (since structure might vary)
                            $vendorCurrencyIds = [];
                            $vendorCurrencyNames = [];
                            
                            foreach ($vendor->supported_currencies as $vc) {
                                if (isset($vc['id'])) {
                                    $vendorCurrencyIds[] = (string) $vc['id'];
                                } elseif (isset($vc['internalId'])) {
                                    $vendorCurrencyIds[] = (string) $vc['internalId'];
                                }
                                
                                if (isset($vc['name'])) {
                                    $vendorCurrencyNames[] = strtolower($vc['name']);
                                }
                            }
                            
                            $currencyIdMatch = in_array((string) $currency->netsuite_id, $vendorCurrencyIds);
                            $currencyNameMatch = in_array(strtolower($currency->name), $vendorCurrencyNames);
                            
                            if (!$currencyIdMatch && !$currencyNameMatch) {
                                $supportedNames = array_column($vendor->supported_currencies, 'name');
                                $this->warn("  ⚠ Warning: Vendor '{$vendor->name}' may not support currency '{$currency->name}'");
                                $this->warn("    Vendor supported currencies: " . implode(', ', $supportedNames));
                            } else {
                                $this->line("  ✓ Vendor supports this currency");
                            }
                        } else {
                            $this->line("  ℹ No currency restrictions found for vendor");
                        }
                    } else {
                        $this->line("  No currency specified, using vendor default");
                    }
                    
                    // Get items for this PO
                    $items = $itemsByPO[$poId] ?? [];
                    if (empty($items)) {
                        throw new \Exception("No items found for PO {$poId}");
                    }
                    
                    // Separate items into itemList (items that exist) and expenseList (items that don't exist)
                    $itemList = [];
                    $expenses = [];
                    $lineNumber = 1;
                    
                    foreach ($items as $item) {
                        $itemNumber = $item['item_number'] ?? null;
                        $netsuiteItem = null;
                        
                        // If item has a reference number, check if it exists in NetSuite
                        if (!empty($itemNumber)) {
                            $this->line("  Checking if item '{$itemNumber}' exists in NetSuite...");
                            $netsuiteItem = NetSuiteItem::where('item_number', $itemNumber)
                                ->where('is_sandbox', $isSandbox)
                                ->where('is_inactive', false)
                                ->first();
                            
                            if ($netsuiteItem) {
                                // Check if this is the excluded item
                                if ($netsuiteItem->item_number === 'Teaching Materials_Sales') {
                                    $this->line("  ✗ Item '{$itemNumber}' is excluded (Teaching Materials_Sales), adding to expenseList");
                                    // Don't add to itemList, let it fall through to expense logic
                                    $netsuiteItem = null;
                                } else {
                                    $this->line("  ✓ Item '{$itemNumber}' found (ID: {$netsuiteItem->netsuite_id}), adding to itemList");
                                    // Add to itemList
                                    $itemList[] = [
                                        'item_id' => $netsuiteItem->netsuite_id,
                                        'quantity' => $item['quantity'],
                                        'rate' => $item['unit_price'],
                                        'description' => $item['name'],
                                        'department_id' => $department->netsuite_id,
                                        'location_id' => $location->netsuite_id,
                                    ];
                                    continue; // Skip adding to expenses
                                }
                            }

                            if (!$netsuiteItem) {
                                $this->line("  ✗ Item '{$itemNumber}' not found, adding to expenseList");
                            }
                        }

                        // Fallback: If subcode maps to an item (from po_items.json), try to use it as item
                        if (!$netsuiteItem && !empty($account->account_number) && isset($poItemsMap[$account->account_number])) {
                            $mappedItemNumber = $poItemsMap[$account->account_number];
                            $this->line("  Attempting mapped item for subcode {$account->account_number}: '{$mappedItemNumber}'");
                            // Try by item_number like (exclude Teaching Materials_Sales)
                            $netsuiteItem = NetSuiteItem::where('item_number', 'like', "%{$mappedItemNumber}%")
                                ->where('item_number', '!=', 'Teaching Materials_Sales')
                                ->where('is_sandbox', $isSandbox)
                                ->where('is_inactive', false)
                                ->first();

                            // Fallback: try by name (non-inventory items often matched by name)
                            if (!$netsuiteItem) {
                                $netsuiteItem = NetSuiteItem::where('name', 'like', "%{$mappedItemNumber}%")
                                    ->where('item_number', '!=', 'Teaching Materials_Sales')
                                    ->where('is_sandbox', $isSandbox)
                                    ->where('is_inactive', false)
                                    ->first();
                            }

                            // Fallback: try non-inventory-only scope (item_type contains 'noninventory')
                            if (!$netsuiteItem) {
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

                            if ($netsuiteItem) {
                                // Check if this is the excluded item
                                if ($netsuiteItem->item_number === 'Teaching Materials_Sales') {
                                    $this->line("  ✗ Mapped item '{$mappedItemNumber}' is excluded (Teaching Materials_Sales), will fall back to expense");
                                    // Don't add to itemList, let it fall through to expense logic
                                    $netsuiteItem = null;
                                } else {
                                    $this->line("  ✓ Mapped item '{$mappedItemNumber}' found (ID: {$netsuiteItem->netsuite_id}), adding to itemList");
                                    $itemList[] = [
                                        'item_id' => $netsuiteItem->netsuite_id,
                                        'quantity' => $item['quantity'],
                                        'rate' => $item['unit_price'],
                                        'description' => $item['name'],
                                        'department_id' => $department->netsuite_id,
                                        'location_id' => $location->netsuite_id,
                                    ];
                                    continue; // Skip adding to expenses
                                }
                            }

                            if (!$netsuiteItem) {
                                $this->line("  ✗ Mapped item '{$mappedItemNumber}' not found, will fall back to expense");
                            }
                        }
                        
                        // Item doesn't exist or has no reference - add to expense list
                        $expenses[] = [
                            'account_id' => $account->netsuite_id,
                            'amount' => $item['amount'],
                            'memo' => $item['quantity'] . ' unit - ' . $item['name'],
                            'department_id' => $department->netsuite_id,
                            'location_id' => $location->netsuite_id,
                        ];
                    }
                    
                    // Validate we have at least one line item
                    if (empty($itemList) && empty($expenses)) {
                        throw new \Exception("No valid items or expenses found for PO {$poId}");
                    }
                    
                    if (!empty($itemList)) {
                        $this->line("  ✓ " . count($itemList) . " item(s) will be added to itemList");
                    }
                    if (!empty($expenses)) {
                        $this->line("  ✓ " . count($expenses) . " item(s) will be added to expenseList");
                    }
                    
                    // Build PO data
                    $poData = [
                        'vendor_id' => $vendor->netsuite_id,
                        'memo' => $poId,
                        'location_id' => $location->netsuite_id,
                        'department_id' => $department->netsuite_id,
                        'supervisor_id' => '3467', // Default supervisor
                    ];
                    
                    // Add itemList if we have items
                    if (!empty($itemList)) {
                        $poData['items'] = $itemList;
                    }
                    
                    // Add expenseList if we have expenses
                    if (!empty($expenses)) {
                        $poData['expenses'] = $expenses;
                    }
                    
                    // If PO column has a tranId value, try to use it (requires manual numbering in NetSuite)
                    if (!empty($existingTranId)) {
                        $poData['tran_id'] = $existingTranId;
                        $this->line("  Attempting to use Transaction ID (tranId) '{$existingTranId}' from PO column");
                        $this->line("  Note: This only works if NetSuite has manual numbering enabled for Purchase Orders");
                    }
                    
                    if ($currency) {
                        $poData['currency_id'] = $currency->netsuite_id;
                    }
                    
                    // Create PO in NetSuite
                    $this->line("  Creating PO in NetSuite...");
                    $result = $poService->createFromArray($poData);
                    
                    if ($result['success']) {
                        $tranId = $result['transaction_id'] ?? null;
                        $internalId = $result['internal_id'] ?? null;
                        $this->line("  ✓ PO created successfully! Internal ID: {$internalId}" . ($tranId ? ", Transaction ID (tranId): {$tranId}" : ""));
                        // Mark as synced - add to synced rows
                        $syncedRow = $row;
                        // Update PO column with Transaction ID (tranId)
                        if (isset($headerMap['PO'])) {
                            $syncedRow[$headerMap['PO']] = $tranId ?? $internalId;
                        }
                        $syncedRows[] = $syncedRow;
                        $syncedRowIndices[] = $sheetRowNumber; // Track row index for deletion
                    } else {
                        // Get timestamp from sheet or use current time
                        $timestamp = $this->getTimestampFromRow($row, $headerMap);

                        // Build error row for Errors sheet
                        $errorRow = [
                            $timestamp, // Timestamp
                            $poId, // PO_ID
                            $result['error'] ?? 'Unknown error', // Error_Message
                            $result['netsuite_response'] ?? '', // NetSuite_Response
                            $row[$headerMap['ID']] ?? '', // ID
                            $row[$headerMap['Name']] ?? '', // Name
                            $row[$headerMap['Budget Code']] ?? '', // Budget Code
                            $row[$headerMap['Subcode']] ?? '', // Subcode
                            $row[$headerMap['Location']] ?? '', // Location
                            $row[$headerMap['Vendor']] ?? '', // Vendor
                        ];
                        $errorRows[] = $errorRow;

                        $this->newLine();
                        $this->warn("Failed to create PO {$poId}: " . ($result['error'] ?? 'Unknown error'));
                        $this->line("  Using timestamp: {$timestamp}");
                    }
                    
                } catch (\Exception $e) {
                    // Get timestamp from sheet or use current time
                    $timestamp = $this->getTimestampFromRow($row, $headerMap);

                    // Build error row for Errors sheet
                    $errorRow = [
                        $timestamp, // Timestamp
                        $poId, // PO_ID
                        $e->getMessage(), // Error_Message
                        '', // NetSuite_Response (not available for exceptions)
                        $row[$headerMap['ID']] ?? '', // ID
                        $row[$headerMap['Name']] ?? '', // Name
                        $row[$headerMap['Budget Code']] ?? '', // Budget Code
                        $row[$headerMap['Subcode']] ?? '', // Subcode
                        $row[$headerMap['Location']] ?? '', // Location
                        $row[$headerMap['Vendor']] ?? '', // Vendor
                    ];
                    $errorRows[] = $errorRow;

                    $this->newLine();
                    $this->warn("Error processing PO {$poId}: " . $e->getMessage());
                    $this->line("  Using timestamp: {$timestamp}");
                }
                
                $progressBar->advance();
            }
            
            $progressBar->finish();
            
            $this->newLine(2);
            
            // Show summary
            $this->info("Summary:");
            $this->info("  Total rows in sheet: " . (count($poRows) - 1));
            $this->info("  Empty/invalid rows: " . $emptyCount);
            $this->info("  Already synced (has PO): " . $alreadySyncedCount);
            $this->info("  Attempted to sync: " . (count($poRows) - 1 - $emptyCount - $alreadySyncedCount));
            $this->info("  Successfully synced: " . count($syncedRows));
            $this->info("  Errors: " . count($errorRows));
            $this->newLine();
            
            // Move synced rows to 'Synced' sheet
            if (!empty($syncedRows)) {
                $this->info("Moving " . count($syncedRows) . " synced PO(s) to 'Synced' sheet...");
                
                // Get headers for synced sheet
                $syncedData = [array_keys($headerMap)]; // Headers
                foreach ($syncedRows as $row) {
                    $syncedData[] = $row;
                }
                
                // Append to Synced sheet
                $sheetsService->appendToSheet('Synced', $syncedData);
                
                $this->info("✓ Synced " . count($syncedRows) . " PO(s)");
                
                // Delete synced rows from original PO sheet (delete from bottom to top to avoid index shifting)
                if (!empty($syncedRowIndices)) {
                    $this->info("Removing " . count($syncedRowIndices) . " synced PO(s) from 'PO' sheet...");
                    
                    // Sort indices in descending order (bottom to top) to avoid index shifting
                    rsort($syncedRowIndices);
                    
                    foreach ($syncedRowIndices as $rowIndex) {
                        try {
                            $sheetsService->deleteRows('PO', $rowIndex, 1);
                            // Small delay to avoid rate limiting
                            usleep(100000); // 0.1 seconds
                        } catch (\Exception $e) {
                            $this->warn("  Failed to delete row {$rowIndex} from PO sheet: " . $e->getMessage());
                            Log::error("Failed to delete row {$rowIndex} from PO sheet: " . $e->getMessage());
                        }
                    }
                    
                    $this->info("✓ Removed " . count($syncedRowIndices) . " row(s) from 'PO' sheet");
                }
            }
            
            // Append errors to 'Errors' sheet
            if (!empty($errorRows)) {
                $this->info("Appending " . count($errorRows) . " error(s) to 'Errors' sheet...");
                
                // Error sheet headers
                $errorHeaders = [
                    'Timestamp',
                    'PO_ID',
                    'Error_Message',
                    'NetSuite_Response',
                    'ID',
                    'Name',
                    'Budget Code',
                    'Subcode',
                    'Location',
                    'Vendor'
                ];
                
                $errorData = [$errorHeaders];
                foreach ($errorRows as $errorRow) {
                    $errorData[] = $errorRow;
                }
                
                // Append to Errors sheet
                $sheetsService->appendToSheet('Errors', $errorData);
                
                $this->warn("✗ " . count($errorRows) . " PO(s) failed - see 'Errors' sheet");
            }
            
            $this->info("Sync completed!");
            
            return Command::SUCCESS;
            
        } catch (\Exception $e) {
            $this->error('Error syncing POs: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }

    /**
     * Get timestamp from row data if 'Timestamp' header exists, otherwise use current time
     *
     * @param array $row The row data from the sheet
     * @param array $headerMap The header map (flipped array of headers)
     * @return string Timestamp value
     */
    protected function getTimestampFromRow(array $row, array $headerMap): string
    {
        // Try multiple possible timestamp header names (case-insensitive)
        $possibleHeaders = ['Timestamp', 'timestamp', 'TIMESTAMP', 'Time', 'Date', 'DateTime', 'Created'];

        foreach ($possibleHeaders as $headerName) {
            // Try exact match first
            if (isset($headerMap[$headerName])) {
                $timestamp = trim($row[$headerMap[$headerName]] ?? '');
                if (!empty($timestamp)) {
                    return $timestamp;
                }
            }

            // Try case-insensitive match
            foreach ($headerMap as $header => $index) {
                if (strcasecmp($header, $headerName) === 0) {
                    $timestamp = trim($row[$index] ?? '');
                    if (!empty($timestamp)) {
                        return $timestamp;
                    }
                }
            }
        }

        // Fallback to current time if no timestamp header or empty value
        return date('Y-m-d H:i:s');
    }

    /**
     * Load PO items mapping (account_number -> item_number/name) from po_items.json
     */
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

    /**
     * Find vendor using fuzzy search with multiple matching strategies
     *
     * @param string $vendorName The vendor name to search for
     * @param bool $isSandbox Whether to search in sandbox or production
     * @return NetSuiteVendor|null The found vendor or null
     */
    protected function findVendorFuzzy(string $vendorName, bool $isSandbox)
    {
        $vendorName = trim($vendorName);
        if (empty($vendorName)) {
            return null;
        }

        // Strategy 1: Exact match
        $vendor = NetSuiteVendor::where('name', $vendorName)
            ->where('is_sandbox', $isSandbox)
            ->first();
        
        if ($vendor) {
            return $vendor;
        }

        // Strategy 2: Case-insensitive exact match
        $vendor = NetSuiteVendor::whereRaw('UPPER(name) = ?', [strtoupper($vendorName)])
            ->where('is_sandbox', $isSandbox)
            ->first();
        
        if ($vendor) {
            return $vendor;
        }

        // Strategy 3: Contains match (current behavior)
        $vendor = NetSuiteVendor::where('name', 'like', "%{$vendorName}%")
            ->where('is_sandbox', $isSandbox)
            ->first();
        
        if ($vendor) {
            return $vendor;
        }

        // Strategy 4: Reverse contains - vendor name contains search term
        // e.g., "AMAZON.COM" in DB matches "AMAZON.COM (US)" in sheet
        // Use database-specific concatenation (SQLite uses ||, MySQL uses CONCAT)
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

        // Strategy 5: Normalize by removing common suffixes/prefixes in parentheses
        // Remove text in parentheses like "(US)", "(UK)", etc.
        $normalizedName = preg_replace('/\s*\([^)]*\)\s*/', '', $vendorName);
        $normalizedName = trim($normalizedName);
        
        if ($normalizedName !== $vendorName && !empty($normalizedName)) {
            // Try exact match with normalized name
            $vendor = NetSuiteVendor::where('name', $normalizedName)
                ->where('is_sandbox', $isSandbox)
                ->first();
            
            if ($vendor) {
                return $vendor;
            }
            
            // Try case-insensitive match with normalized name
            $vendor = NetSuiteVendor::whereRaw('UPPER(name) = ?', [strtoupper($normalizedName)])
                ->where('is_sandbox', $isSandbox)
                ->first();
            
            if ($vendor) {
                return $vendor;
            }
            
            // Try contains match with normalized name
            $vendor = NetSuiteVendor::where('name', 'like', "%{$normalizedName}%")
                ->where('is_sandbox', $isSandbox)
                ->first();
            
            if ($vendor) {
                return $vendor;
            }
            
            // Try reverse contains with normalized name
            $vendor = NetSuiteVendor::whereRaw("? LIKE {$concatSql}", [$normalizedName])
                ->where('is_sandbox', $isSandbox)
                ->first();
            
            if ($vendor) {
                return $vendor;
            }
        }

        // Strategy 6: Word-based fuzzy matching
        // Split by spaces and try to match key words
        $words = preg_split('/[\s\-_]+/', $normalizedName ?: $vendorName);
        $words = array_filter($words, function($word) {
            return strlen($word) > 2; // Only use words longer than 2 characters
        });
        
        if (count($words) > 0) {
            // Try matching the longest word (likely the main company name)
            usort($words, function($a, $b) {
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
}

