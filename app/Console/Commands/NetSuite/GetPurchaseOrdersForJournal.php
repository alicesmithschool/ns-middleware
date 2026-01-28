<?php

namespace App\Console\Commands\NetSuite;

use App\Models\JournalPurchaseOrder;
use App\Models\NetSuiteCurrency;
use App\Services\GoogleSheetsService;
use App\Services\NetSuiteRestService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class GetPurchaseOrdersForJournal extends Command
{
    protected $signature = 'journal:get-ns-po 
                            {--from= : Start date for PO search (YYYY-MM-DD)}
                            {--to= : End date for PO search (YYYY-MM-DD)}
                            {--limit=1000 : Maximum number of POs to fetch}
                            {--append : Append to existing sheet data instead of clearing}
                            {--force-refresh : Ignore cache and re-fetch all POs}
                            {--clear-cache : Clear cached POs before running}';
    
    protected $description = 'Pull Purchase Orders from NetSuite and export to Journal Entries Google Sheet (with database caching)';
    
    /**
     * Cache for item details to avoid redundant API calls
     * @var array
     */
    protected $itemCache = [];

    public function handle(NetSuiteRestService $netSuiteService)
    {
        $this->info('Starting Purchase Order extraction for Journal Entries...');

        // Get journal entries spreadsheet ID from env
        $spreadsheetId = env('JOURNAL_ENTRIES_SPREADSHEET_ID');
        
        if (empty($spreadsheetId)) {
            $this->error('JOURNAL_ENTRIES_SPREADSHEET_ID not set in .env file');
            return Command::FAILURE;
        }

        $environment = config('netsuite.environment', 'sandbox');
        $this->info("Using Journal Entries spreadsheet ID: {$spreadsheetId}");
        $this->info("NetSuite Environment: {$environment}");
        $this->newLine();

        try {
            // Clear cache if requested
            if ($this->option('clear-cache')) {
                $this->info('Clearing cached POs...');
                $deletedCount = JournalPurchaseOrder::truncate();
                $this->info("✓ Cache cleared");
                $this->newLine();
            }

            // Initialize Google Sheets service with journal entries spreadsheet
            $sheetsService = new GoogleSheetsService($spreadsheetId);

            // Build query for Purchase Orders
            $fromDate = $this->option('from');
            $toDate = $this->option('to');
            $limit = (int) $this->option('limit');
            $forceRefresh = $this->option('force-refresh');

            // Check for already-processed POs in database with their last modified dates
            $cachedPos = [];
            if (!$forceRefresh) {
                $cachedPos = JournalPurchaseOrder::select('po_id', 'netsuite_last_modified')
                    ->get()
                    ->keyBy('po_id')
                    ->toArray();
                if (count($cachedPos) > 0) {
                    $this->info("Found " . count($cachedPos) . " cached PO(s) in database");
                }
            }

            $this->info('Fetching Purchase Orders from NetSuite...');
            $allPurchaseOrders = $this->fetchPurchaseOrders($netSuiteService, $fromDate, $toDate, $limit);
            
            if (empty($allPurchaseOrders)) {
                $this->warn('No Purchase Orders found matching the criteria.');
                return Command::SUCCESS;
            }

            // Filter out already-processed POs (or include all if force-refresh)
            $purchaseOrders = array_filter($allPurchaseOrders, function($po) use ($cachedPos, $forceRefresh) {
                return $forceRefresh || !isset($cachedPos[$po['id']]);
            });

            $this->info("Found " . count($allPurchaseOrders) . " Purchase Order(s) total");
            
            if (count($purchaseOrders) === 0) {
                $this->info("✓ All POs already cached - nothing new to process");
                $this->newLine();
                return Command::SUCCESS;
            }
            
            $this->info("Processing " . count($purchaseOrders) . " new/uncached PO(s)");
            $this->newLine();

            // Fetch full PO details and transform to journal entry format
            $this->info('Fetching full PO details and transforming to Journal Entry format...');
            $progressBar = $this->output->createProgressBar(count($purchaseOrders));
            $progressBar->start();

            $journalRows = [];
            foreach ($purchaseOrders as $po) {
                try {
                    // Fetch full PO details including expense lines
                    $poId = $po['id'];
                    $fullPO = $netSuiteService->getPurchaseOrder($poId);
                    
                    // Transform PO with expense line details
                    $rows = $this->transformPOToJournalEntries($fullPO, $po, $netSuiteService);
                    $journalRows = array_merge($journalRows, $rows);
                    
                    // Save to database cache with NetSuite last modified date
                    if (!empty($rows)) {
                        $netsuiteLastModified = null;
                        if (isset($fullPO['lastModifiedDate'])) {
                            try {
                                $netsuiteLastModified = new \DateTime($fullPO['lastModifiedDate']);
                            } catch (\Exception $e) {
                                Log::warning("Could not parse lastModifiedDate", ['date' => $fullPO['lastModifiedDate']]);
                            }
                        }
                        
                        foreach ($rows as $row) {
                            JournalPurchaseOrder::updateOrCreate(
                                ['po_id' => $po['id']],
                                [
                                    'tran_id' => $row['transaction_id'],
                                    'transaction_date' => $row['transaction_date'],
                                    'department_code' => $row['department_code'],
                                    'subcode' => $row['subcode'],
                                    'amount' => $row['currency_amount'],
                                    'currency_code' => $row['currency_code'],
                                    'memo' => $row['description'],
                                    'full_data' => $row,
                                    'netsuite_last_modified' => $netsuiteLastModified,
                                    'processed_at' => now(),
                                ]
                            );
                        }
                    }
                } catch (\Exception $e) {
                    $this->newLine();
                    $this->warn("  Failed to process PO ID {$poId}: " . $e->getMessage());
                    Log::warning("Failed to process PO {$poId}", [
                        'error' => $e->getMessage()
                    ]);
                }
                $progressBar->advance();
            }

            $progressBar->finish();
            $this->newLine(2);

            $this->info("Transformed into " . count($journalRows) . " journal entry line(s)");
            
            // Show cache stats
            $cachedItems = count($this->itemCache);
            if ($cachedItems > 0) {
                $this->info("  Items cached: {$cachedItems} (reduced API calls)");
            }

            // Prepare data for sheet (without headers - row 1 is frozen with user's headers)
            $sheetData = [];
            foreach ($journalRows as $row) {
                $sheetData[] = [
                    $row['department_code'] ?? '',
                    $row['subcode'] ?? '',
                    $row['transaction_date'] ?? '',
                    $row['transaction_id'] ?? '',
                    $row['transaction_type'] ?? '',
                    $row['external_reference'] ?? '',
                    $row['description'] ?? '',
                    $row['financial_year'] ?? '',
                    $row['period_number'] ?? '',
                    $row['period_name'] ?? '',
                    $row['myr_amount'] ?? '',
                    $row['currency_amount'] ?? '',
                    $row['currency_code'] ?? '',
                    $row['exchange_rate'] ?? '',
                    $row['finance_staff'] ?? ''
                ];
            }

            // Write to Google Sheets 'PO' tab
            $sheetName = 'PO';
            
            // Determine if we should append or clear
            $shouldAppend = $this->option('append') || count($cachedPos) > 0;
            
            if (!$shouldAppend) {
                $this->info("Clearing existing data in '{$sheetName}' sheet (preserving row 1)...");
                // Clear from row 2 onwards instead of entire sheet
                $sheetsService->clearRange($sheetName, 'A2:O');
                $this->info("Writing " . count($journalRows) . " row(s) to '{$sheetName}' sheet starting from row 2...");
                // Write starting from A2 to preserve row 1
                $sheetsService->updateRange($sheetName, 'A2', $sheetData);
            } else {
                $this->info("Appending " . count($journalRows) . " new row(s) to '{$sheetName}' sheet...");
                $sheetsService->appendToSheet($sheetName, $sheetData);
            }
            
            $this->info("✓ Successfully written to '{$sheetName}' sheet");

            $this->newLine();
            $this->info("Summary:");
            $this->info("  Purchase Orders fetched: " . count($purchaseOrders));
            $this->info("  Journal entries created: " . count($journalRows));
            $this->info("  Spreadsheet: {$spreadsheetId}");
            $this->newLine();

            $this->info("Export completed successfully!");
            return Command::SUCCESS;

        } catch (\Exception $e) {
            $this->error('Error exporting Purchase Orders: ' . $e->getMessage());
            Log::error('PO to Journal export error: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
            return Command::FAILURE;
        }
    }

    /**
     * Fetch Purchase Orders from NetSuite using SuiteQL
     */
    protected function fetchPurchaseOrders(NetSuiteRestService $netSuiteService, $fromDate = null, $toDate = null, $limit = 1000)
    {
        $fields = [
            'id',
            'tranid',
            'trandate',
            'entity',
            'memo',
            'foreigntotal AS total',
            'currency',
            'exchangerate',
            'status'
        ];

        // Build WHERE clause
        // NetSuite SuiteQL uses BUILTIN.DF function for date formatting
        $whereConditions = [];
        
        if ($fromDate) {
            // Use TO_DATE with MM/DD/YYYY format
            $date = new \DateTime($fromDate);
            $formattedDate = $date->format('n/j/Y'); // No leading zeros
            $whereConditions[] = "trandate >= TO_DATE('{$formattedDate}', 'MM/DD/YYYY')";
        }
        
        if ($toDate) {
            // Use TO_DATE with MM/DD/YYYY format
            $date = new \DateTime($toDate);
            $formattedDate = $date->format('n/j/Y'); // No leading zeros
            $whereConditions[] = "trandate <= TO_DATE('{$formattedDate}', 'MM/DD/YYYY')";
        }

        $where = !empty($whereConditions) ? implode(' AND ', $whereConditions) : null;

        // Use SuiteQL to search for Purchase Orders
        return $this->searchPurchaseOrders($netSuiteService, $fields, $where, $limit);
    }

    /**
     * Search Purchase Orders using SuiteQL (delegates to NetSuiteRestService)
     */
    protected function searchPurchaseOrders(NetSuiteRestService $netSuiteService, $fields = null, $where = null, $limit = 1000)
    {
        return $netSuiteService->searchPurchaseOrders($fields, $where, $limit);
    }

    /**
     * Transform a Purchase Order into journal entry rows
     * Each PO expense line becomes a separate journal entry row
     * 
     * @param array $fullPO Full PO details from REST Record API
     * @param array $basicPO Basic PO info from SuiteQL
     */
    protected function transformPOToJournalEntries($fullPO, $basicPO, NetSuiteRestService $netSuiteService)
    {
        $rows = [];
        
        // Get PO header details
        $poId = $fullPO['tranId'] ?? $basicPO['tranid'] ?? '';
        $tranDate = $fullPO['tranDate'] ?? $basicPO['trandate'] ?? '';
        $exchangeRate = $fullPO['exchangeRate'] ?? $basicPO['exchangerate'] ?? 1;
        
        // Get currency - try multiple sources
        // From full PO (REST Record API) - uses 'id' not 'internalId'
        $currencyId = $fullPO['currency']['id'] ?? $fullPO['currency']['internalId'] ?? null;
        $currencyName = $fullPO['currency']['refName'] ?? $fullPO['currencyName'] ?? '';
        
        // If not found, try from basic PO (SuiteQL) - this is a numeric value
        if (empty($currencyId) && !empty($basicPO['currency'])) {
            $currencyId = $basicPO['currency'];
        }
        
        $currencyCode = $this->getCurrencyCode($currencyId, $currencyName);
        
        $memo = $fullPO['memo'] ?? $basicPO['memo'] ?? '';
        
        // Parse transaction date and calculate financial periods
        list($transactionDate, $financialYear, $periodNumber, $periodName) = 
            $this->calculateFinancialPeriods($tranDate);
        
        // Get first line from the PO (try both expense and item lists)
        // REST Record API returns these in a nested structure with 'items' array
        $firstLine = null;
        $lineType = '';
        
        // Check for expense lines - use first one only
        if (isset($fullPO['expense']['items']) && !empty($fullPO['expense']['items'])) {
            $firstLine = $fullPO['expense']['items'][0];
            $lineType = 'expense';
        }
        // Check for item lines - use first one only
        elseif (isset($fullPO['item']['items']) && !empty($fullPO['item']['items'])) {
            $firstLine = $fullPO['item']['items'][0];
            $lineType = 'item';
        }
        
        // If no lines found, create one row with PO total (no department/account codes available)
        if (empty($firstLine)) {
            Log::warning("PO has no expense or item lines", ['po_id' => $poId]);
            $rows[] = [
                'department_code' => '',
                'subcode' => '',
                'transaction_date' => $transactionDate,
                'transaction_id' => $poId,
                'transaction_type' => 'Purchase Order',
                'external_reference' => $poId,
                'description' => $memo ?: "PO {$poId} (no line items)",
                'financial_year' => $financialYear,
                'period_number' => $periodNumber,
                'period_name' => $periodName,
                'myr_amount' => $this->calculateMYRAmount($fullPO['total'] ?? 0, $exchangeRate),
                'currency_amount' => $fullPO['total'] ?? 0,
                'currency_code' => $currencyCode,
                'exchange_rate' => $exchangeRate,
                'finance_staff' => ''
            ];
            return $rows;
        }
        
        // Extract budget code (department) from first line - use refName and extract prefix
        $departmentRefName = $firstLine['department']['refName'] ?? '';
        $departmentCode = $this->extractDepartmentPrefix($departmentRefName);
        
        // Extract data based on line type
        if ($lineType === 'expense') {
            // For expense lines, account might be directly available
            $accountCode = $firstLine['account']['refName'] ?? '';
            $amount = $fullPO['total'] ?? 0; // Use PO total, not line amount
            $lineMemo = $firstLine['memo'] ?? $memo;
        } else {
            // Item line - need to fetch item details to get GL account
            $itemId = $firstLine['item']['id'] ?? null;
            $accountCode = '';
            
            if ($itemId) {
                // Check cache first to avoid redundant API calls
                if (isset($this->itemCache[$itemId])) {
                    $itemDetails = $this->itemCache[$itemId];
                } else {
                    // Fetch full item details to get expense account
                    try {
                        $itemDetails = $netSuiteService->getItem($itemId, 'noninventorypurchaseitem');
                        // Cache the item details
                        $this->itemCache[$itemId] = $itemDetails;
                    } catch (\Exception $e) {
                        Log::warning("Failed to fetch item details for item {$itemId}", [
                            'po_id' => $poId,
                            'error' => $e->getMessage()
                        ]);
                        $itemDetails = null;
                        // Cache null to avoid retrying failed items
                        $this->itemCache[$itemId] = null;
                    }
                }
                
                if ($itemDetails && isset($itemDetails['expenseAccount']['refName'])) {
                    $accountCode = $itemDetails['expenseAccount']['refName'];
                }
            }
            
            $amount = $fullPO['total'] ?? 0; // Use PO total, not line amount
            $lineMemo = $firstLine['description'] ?? $memo;
        }
        
        // Create single row per PO using first line's department/account
        $row = [
            'department_code' => $departmentCode,
            'subcode' => $this->extractAccountNumber($accountCode),
            'transaction_date' => $transactionDate,
            'transaction_id' => $poId,
            'transaction_type' => 'Purchase Order',
            'external_reference' => $poId,
            'description' => $lineMemo ?: "PO {$poId}",
            'financial_year' => $financialYear,
            'period_number' => $periodNumber,
            'period_name' => $periodName,
            'myr_amount' => $this->calculateMYRAmount($amount, $exchangeRate),
            'currency_amount' => $amount,
            'currency_code' => $currencyCode,
            'exchange_rate' => $exchangeRate,
            'finance_staff' => ''
        ];
        
        $rows[] = $row;
        
        return $rows;
    }
    
    /**
     * Calculate financial periods from transaction date
     */
    protected function calculateFinancialPeriods($tranDate)
    {
        $transactionDate = '';
        $financialYear = '';
        $periodNumber = '';
        $periodName = '';
        
        if (!empty($tranDate)) {
            try {
                $date = new \DateTime($tranDate);
                $transactionDate = $date->format('Y-m-d');
                
                // Calculate financial year and period
                // Financial year starts in September
                $year = (int) $date->format('Y');
                $month = (int) $date->format('n');
                
                // If month is Jan-Aug, financial year is previous year/current year
                // If month is Sep-Dec, financial year is current year/next year
                if ($month < 9) {
                    $financialYear = ($year - 1) . '/' . $year;
                } else {
                    $financialYear = $year . '/' . ($year + 1);
                }
                
                // Calculate period number (September = 1)
                if ($month >= 9) {
                    $periodNumber = $month - 8; // Sep=1, Oct=2, Nov=3, Dec=4
                } else {
                    $periodNumber = $month + 4; // Jan=5, Feb=6, ..., Aug=12
                }
                
                // Period name
                $periodName = $date->format('F');
                
            } catch (\Exception $e) {
                Log::warning("Failed to parse transaction date: {$tranDate}");
            }
        }
        
        return [$transactionDate, $financialYear, $periodNumber, $periodName];
    }

    /**
     * Extract department code (Budget Code) from department reference
     * Example: "JB-C030 Library Capex" -> "JB-C030"
     */
    protected function extractDepartmentCode($department)
    {
        if (empty($department)) {
            return '';
        }
        
        // If it's a string like "JB-C030 Library Capex", extract the code
        if (preg_match('/^([A-Z0-9-]+)\s/', $department, $matches)) {
            return $matches[1];
        }
        
        return $department;
    }
    
    /**
     * Extract account code (GL Code/Subcode) from account reference
     * Example: "10150 Property, plant & equipment : School Equipment" -> "10150"
     */
    protected function extractAccountCode($account)
    {
        if (empty($account)) {
            return '';
        }
        
        // If it's a string like "10150 Property...", extract the numeric code
        if (preg_match('/^(\d+)\s/', $account, $matches)) {
            return $matches[1];
        }
        
        return $account;
    }

    /**
     * Extract department prefix in XX-XXXX format
     * Examples:
     *   "JB-Admin Office : 3516" -> "JB-3516"
     *   "JB-C030 Library Capex" -> "JB-C030"
     *   "EP-R035 SEASAC Terr" -> "EP-R035"
     *   "WS-OC07 ICT Capex" -> "WS-OC07"
     *   "JB-0871" -> "JB-0871"
     */
    protected function extractDepartmentPrefix($departmentRefName)
    {
        if (empty($departmentRefName)) {
            return '';
        }
        
        // Try to extract pattern: XX-XXXX with alphanumeric code
        // Examples: "JB-C030 Library Capex" -> "JB-C030", "EP-R035 SEASAC Terr" -> "EP-R035"
        if (preg_match('/^([A-Z]{2,3}-[A-Z0-9]+)(?:\s|:|$)/', $departmentRefName, $matches)) {
            return $matches[1];
        }
        
        // Fallback: Try to extract pattern: XX-Something : XXXX -> XX-XXXX
        // Example: "JB-Admin Office : 3516" -> "JB-3516"
        if (preg_match('/^([A-Z]+)-[^:]+:\s*(\d+)/', $departmentRefName, $matches)) {
            return $matches[1] . '-' . $matches[2];
        }
        
        // If no pattern matches, return the part before colon, space, or the whole string
        if (strpos($departmentRefName, ':') !== false) {
            return trim(explode(':', $departmentRefName)[0]);
        }
        
        if (strpos($departmentRefName, ' ') !== false) {
            return trim(explode(' ', $departmentRefName)[0]);
        }
        
        return $departmentRefName;
    }

    /**
     * Extract account number from account code/name
     * Extracts only the leading numbers
     * Example: "88000 Teaching Resources : Teaching Materials" -> "88000"
     */
    protected function extractAccountNumber($accountCode)
    {
        if (empty($accountCode)) {
            return '';
        }
        
        // Extract leading numbers only
        if (preg_match('/^(\d+)/', $accountCode, $matches)) {
            return $matches[1];
        }
        
        return '';
    }

    /**
     * Get currency code from NetSuite currency reference
     * Uses NetSuiteCurrency database table for accurate mapping
     * 
     * @param string|int|null $currencyId NetSuite internal currency ID
     * @param string|null $currencyName Currency name
     * @return string Currency code (e.g., 'USD', 'MYR')
     */
    protected function getCurrencyCode($currencyId = null, $currencyName = null)
    {
        $environment = config('netsuite.environment', 'sandbox');
        $isSandbox = $environment === 'sandbox';
        
        // Try to find by NetSuite internal ID first (most accurate)
        if (!empty($currencyId)) {
            $currency = NetSuiteCurrency::where('netsuite_id', $currencyId)
                ->where('is_sandbox', $isSandbox)
                ->first();
                
            if ($currency) {
                return $currency->currency_code;
            }
        }
        
        // Try to find by currency name
        if (!empty($currencyName)) {
            $currency = NetSuiteCurrency::where('name', $currencyName)
                ->where('is_sandbox', $isSandbox)
                ->first();
                
            if ($currency) {
                return $currency->currency_code;
            }
            
            // Try case-insensitive search
            $currency = NetSuiteCurrency::whereRaw('LOWER(name) = ?', [strtolower($currencyName)])
                ->where('is_sandbox', $isSandbox)
                ->first();
                
            if ($currency) {
                return $currency->currency_code;
            }
        }
        
        // If currency name is already a code (3 letters), return as-is
        if (!empty($currencyName) && strlen($currencyName) <= 3 && ctype_upper($currencyName)) {
            return $currencyName;
        }
        
        // Default to MYR if not found
        Log::warning("Currency not found in database", [
            'currency_id' => $currencyId,
            'currency_name' => $currencyName,
            'environment' => $environment
        ]);
        
        return 'MYR';
    }

    /**
     * Calculate MYR amount from currency amount and exchange rate
     */
    protected function calculateMYRAmount($amount, $exchangeRate)
    {
        if (empty($amount) || empty($exchangeRate)) {
            return 0;
        }
        
        return round($amount * $exchangeRate, 2);
    }
}
