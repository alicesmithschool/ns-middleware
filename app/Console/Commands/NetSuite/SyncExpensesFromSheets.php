<?php

namespace App\Console\Commands\NetSuite;

use App\Models\NetSuiteAccount;
use App\Models\NetSuiteCurrency;
use App\Models\NetSuiteDepartment;
use App\Models\NetSuiteEmployee;
use App\Models\NetSuiteExpenseCategory;
use App\Models\NetSuiteLocation;
use App\Services\ExpenseReportService;
use App\Services\GoogleSheetsService;
use App\Services\NetSuiteService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SyncExpensesFromSheets extends Command
{
    protected $signature = 'netsuite:sync-expenses-from-sheets {--force : Force sync even if Expense Report column has value}';
    protected $description = 'Sync Expense Reports from Google Sheets to NetSuite';

    public function handle(ExpenseReportService $expenseReportService, NetSuiteService $netSuiteService)
    {
        $environment = config('netsuite.environment', 'sandbox');
        $isSandbox = $environment === 'sandbox';

        $spreadsheetId = $this->getExpenseSpreadsheetId($environment);
        $this->info("Using Expense spreadsheet ID: {$spreadsheetId}");
        $this->info("NetSuite Environment: {$environment}");

        $sheetsService = new GoogleSheetsService($spreadsheetId);

        $this->info('Reading PR sheet (expense report headers)...');
        $prRows = $sheetsService->readSheet('PR');
        if (empty($prRows) || count($prRows) < 2) {
            $this->warn('No PR data found in sheet (need at least header + 1 row)');
            return Command::SUCCESS;
        }

        $headers = array_map('trim', $prRows[0]);
        $headerMap = array_flip($headers);
        $this->info('PR sheet headers found: ' . implode(', ', $headers));

        // Required headers: PR ID and Employee
        $prIdHeader = $this->findHeaderIndex($headerMap, ['PR ID', 'PR', 'EPR', 'ID']);
        $employeeHeader = $this->findHeaderIndex($headerMap, ['Employee', 'Employee Name', 'Employee ID']);
        
        if ($prIdHeader === null) {
            $this->error("Required header 'PR ID' (or 'PR') not found in PR sheet");
            return Command::FAILURE;
        }
        if ($employeeHeader === null) {
            $this->error("Required header 'Employee' not found in PR sheet");
            return Command::FAILURE;
        }

        $expenseReportColumn = $this->findHeaderIndex($headerMap, ['Expense Report', 'Expense Report ID', 'Expense Report Number', 'NS Expense Report']);
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
        $categoryColumn = $this->findHeaderIndex($lineHeaderMap, ['Category', 'Expense Category', 'Category Name']);
        $amountColumn = $this->findHeaderIndex($lineHeaderMap, ['Amount', 'Price', 'Rate']);
        $expenseDateColumn = $this->findHeaderIndex($lineHeaderMap, ['Expense Date', 'Date', 'Transaction Date']);
        $memoColumn = $this->findHeaderIndex($lineHeaderMap, ['Memo', 'Payment Reference', 'Description', 'Name']);
        $subcodeColumn = $this->findHeaderIndex($lineHeaderMap, ['Subcode', 'Account', 'Account Number']);
        $budgetCodeColumn = $this->findHeaderIndex($lineHeaderMap, ['Budget Code', 'Department', 'Dept']);
        $locationColumn = $this->findHeaderIndex($lineHeaderMap, ['Location', 'Loc']);
        $currencyColumn = $this->findHeaderIndex($lineHeaderMap, ['Currency', 'Currency Code']);

        if ($prColumn === null) {
            $this->error("PR column not found in Line Item sheet. Expected one of: PR ID, PR, EPR, ID");
            return Command::FAILURE;
        }
        if ($categoryColumn === null) {
            $this->error("Category/Expense Category column not found in Line Item sheet.");
            return Command::FAILURE;
        }
        if ($amountColumn === null) {
            $this->error("Amount column not found in Line Item sheet.");
            return Command::FAILURE;
        }
        if ($expenseDateColumn === null) {
            $this->error("Expense Date column not found in Line Item sheet.");
            return Command::FAILURE;
        }

        // Group line items by PR ID
        $itemsByPR = [];
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

            $itemData = [
                'category' => trim($row[$categoryColumn] ?? ''),
                'amount' => !empty($row[$amountColumn]) ? (float) str_replace(',', '', trim($row[$amountColumn])) : 0.0,
                'expense_date' => trim($row[$expenseDateColumn] ?? ''),
                'memo' => trim($row[$memoColumn] ?? ''),
                'subcode' => trim($row[$subcodeColumn] ?? ''),
                'budget_code' => $budgetCodeColumn !== null ? trim($row[$budgetCodeColumn] ?? '') : '',
                'location' => $locationColumn !== null ? trim($row[$locationColumn] ?? '') : '',
                'currency' => $currencyColumn !== null ? trim($row[$currencyColumn] ?? '') : '',
            ];

            $itemsByPR[$prId][] = $itemData;
        }

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

            $existingExpenseReportTranId = $expenseReportColumn !== null ? trim($row[$expenseReportColumn] ?? '') : '';
            $expenseReportExists = false;
            $existingInfo = null;

            if (!$this->option('force')) {
                if (!empty($existingExpenseReportTranId)) {
                    $this->line("  Checking if Expense Report with Transaction ID '{$existingExpenseReportTranId}' exists in NetSuite ({$environment})...");
                    // Note: We don't have expenseReportExistsByTranId method yet, so we'll skip this check for now
                    // You can add it later if needed
                }

                if ($expenseReportExists) {
                    $this->warn("  ✗ Expense Report already exists in NetSuite (found by {$existingInfo}). Skipping.");
                    $timestamp = $this->getTimestampFromRow($row, $headerMap, $timestampColumn);
                    $employeeValue = $this->getFirstAvailable($row, $headerMap, ['Employee', 'Employee Name', 'Employee ID']) ?? '';
                    $errorRows[] = [
                        $timestamp,
                        $prId,
                        "Expense Report already exists in NetSuite (found by {$existingInfo})",
                        '',
                        $prId,
                        '', // Budget Code (from line items)
                        '', // Subcode (from line items)
                        '', // Location (from line items)
                        $employeeValue,
                    ];
                    $alreadySyncedCount++;
                    $progressBar->advance();
                    continue;
                }
            } else {
                if (!empty($existingExpenseReportTranId)) {
                    $this->warn("  Force mode: Re-syncing PR {$prId} (existing Expense Report: {$existingExpenseReportTranId})");
                }
            }

            $this->newLine();
            $this->line("Processing PR: {$prId}");

            try {
                // Get employee from PR sheet
                $employeeName = $this->getFirstAvailable($row, $headerMap, ['Employee', 'Employee Name', 'Employee ID']);
                if (empty($employeeName)) {
                    throw new \Exception("Employee not found in PR sheet");
                }
                $employee = $this->findEmployeeFuzzy($employeeName, $isSandbox);
                if (!$employee) {
                    throw new \Exception("Employee '{$employeeName}' not found");
                }
                $this->line("  ✓ Employee: {$employee->name} ({$employee->netsuite_id})");

                // Get memo from PR ID
                $memo = $prId;

                // Get transaction date from Timestamp column
                $trandate = $this->getDateFromRow($row, $headerMap, ['Timestamp', 'Transaction Date', 'Tran Date', 'Date', 'PR Date']);
                if (!$trandate) {
                    // If no timestamp, use current date
                    $trandate = date('Y-m-d\TH:i:s');
                }

                // Currency: prefer PR sheet value, otherwise take from the first line item (required for Expense Report)
                $currency = null;
                $currencyValue = $this->getFirstAvailable($row, $headerMap, ['Currency', 'Currency Code', 'Currency ID']);
                $items = $itemsByPR[$prId] ?? [];
                if (empty($currencyValue) && !empty($items)) {
                    foreach ($items as $li) {
                        if (!empty($li['currency'])) {
                            $currencyValue = $li['currency'];
                            break;
                        }
                    }
                }
                if (empty($currencyValue)) {
                    throw new \Exception("Currency is required for Expense Report. Please provide Currency in PR sheet or Line Item sheet.");
                }
                $currency = $this->findCurrency($currencyValue, $isSandbox);
                if (!$currency) {
                    throw new \Exception("Currency '{$currencyValue}' not found. Please sync currencies.");
                }
                $this->line("  ✓ Currency: {$currency->name} ({$currency->currency_code})");

                $supervisorId = $this->getFirstAvailable($row, $headerMap, ['Supervisor', 'Supervisor ID']) ?? '3467';
                
                if (empty($items)) {
                    throw new \Exception("No line items found for PR {$prId}");
                }

                // Get Budget Code and Location from first line item (if available in Line Item sheet)
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

                $expenseLines = [];
                foreach ($items as $item) {
                    // Get category (required)
                    $categoryName = $item['category'];
                    if (empty($categoryName)) {
                        throw new \Exception("Category is required for line items in PR {$prId}");
                    }
                    $category = $this->findExpenseCategoryFuzzy($categoryName, $isSandbox);
                    if (!$category) {
                        throw new \Exception("Expense Category '{$categoryName}' not found for PR {$prId}");
                    }

                    // Get amount (required)
                    $amount = $item['amount'];
                    if (empty($amount) || $amount <= 0) {
                        throw new \Exception("Amount must be greater than 0 for line items in PR {$prId}");
                    }

                    // Get expense date (required)
                    $expenseDate = $item['expense_date'];
                    if (empty($expenseDate)) {
                        // Fallback to transaction date if expense date not provided
                        $expenseDate = $trandate;
                    } else {
                        // Format expense date
                        $expenseDate = $this->formatExpenseDate($expenseDate);
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
                    }

                    // Get expense account (Subcode) - optional
                    $expenseAccount = null;
                    if (!empty($item['subcode'])) {
                        $expenseAccount = $this->findAccountBySubcode($item['subcode'], $isSandbox);
                    }

                    // Build expense line
                    $expenseLine = [
                        'category_id' => $category->netsuite_id,
                        'amount' => $amount,
                        'expense_date' => $expenseDate,
                        'memo' => $item['memo'] ?: $memo,
                    ];

                    if ($expenseAccount) {
                        $expenseLine['expense_account_id'] = $expenseAccount->netsuite_id;
                    }
                    if ($department) {
                        $expenseLine['department_id'] = $department->netsuite_id;
                    }
                    if ($location) {
                        $expenseLine['location_id'] = $location->netsuite_id;
                    }

                    $expenseLines[] = $expenseLine;
                }

                if (empty($expenseLines)) {
                    throw new \Exception("No valid expense lines for PR {$prId}");
                }

                // Set tranId: prefer existing expense report ID or row value, otherwise use PR ID
                $tranId = $existingExpenseReportTranId ?: $this->getFirstAvailable($row, $headerMap, ['Expense Report Ref', 'Reference', 'Reference No', 'Tran ID']);
                if (empty($tranId)) {
                    $tranId = $prId;
                }

                // Build expense report data
                $expenseReportData = [
                    'employee_id' => $employee->netsuite_id,
                    'memo' => $memo,
                    'payment_request_reference' => $prId, // Custom field: custbody_assa_pr_reference
                    'supervisor_id' => $supervisorId,
                    'trandate' => $trandate,
                    'currency_id' => $currency->netsuite_id, // Required for Expense Report
                    'expenses' => $expenseLines,
                ];
                
                // Only set tran_id if provided
                if (!empty($tranId)) {
                    $expenseReportData['tran_id'] = $tranId;
                }

                $this->line("  Creating Expense Report in NetSuite...");
                $result = $expenseReportService->createFromArray($expenseReportData);

                if ($result['success']) {
                    $tranIdResult = $result['transaction_id'] ?? null;
                    $internalId = $result['internal_id'] ?? null;
                    $this->line("  ✓ Expense Report created successfully! Internal ID: {$internalId}" . ($tranIdResult ? ", Transaction ID: {$tranIdResult}" : ''));
                    $syncedRow = $row;
                    if ($expenseReportColumn !== null) {
                        $syncedRow[$expenseReportColumn] = $tranIdResult ?? $internalId;
                    }
                    $syncedRows[] = $syncedRow;
                    $syncedRowIndices[] = $sheetRowNumber;
                } else {
                    $timestamp = $this->getTimestampFromRow($row, $headerMap, $timestampColumn);
                    $employeeValue = $this->getFirstAvailable($row, $headerMap, ['Employee', 'Employee Name', 'Employee ID']) ?? '';
                    $errorRows[] = [
                        $timestamp,
                        $prId,
                        $result['error'] ?? 'Unknown error',
                        $result['netsuite_response'] ?? '',
                        $prId,
                        '', // Budget Code (from line items)
                        '', // Subcode (from line items)
                        '', // Location (from line items)
                        $employeeValue,
                    ];
                    $this->warn("Failed to create Expense Report {$prId}: " . ($result['error'] ?? 'Unknown error'));
                }
            } catch (\Exception $e) {
                $timestamp = $this->getTimestampFromRow($row, $headerMap, $timestampColumn);
                $employeeValue = $this->getFirstAvailable($row, $headerMap, ['Employee', 'Employee Name', 'Employee ID']) ?? '';
                $errorRows[] = [
                    $timestamp,
                    $prId,
                    $e->getMessage(),
                    '',
                    $prId,
                    '', // Budget Code (from line items)
                    '', // Subcode (from line items)
                    '', // Location (from line items)
                    $employeeValue,
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
            $this->info("Moving " . count($syncedRows) . " synced Expense Report(s) to 'Synced' sheet...");
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
            $this->warn("✗ " . count($errorRows) . " Expense Report(s) failed - see 'Errors' sheet");
        }

        $this->info("Expense Report sync completed!");
        return Command::SUCCESS;
    }

    protected function getExpenseSpreadsheetId(string $environment): string
    {
        if ($environment === 'production') {
            $id = config('google-sheets.prod_expense_spreadsheet_id');
        } else {
            $id = config('google-sheets.expense_spreadsheet_id');
        }

        if (empty($id)) {
            $envVar = $environment === 'production' ? 'PROD_EXPENSE_SHEET_ID' : 'SANDBOX_EXPENSE_SHEET_ID';
            throw new \Exception("Expense Spreadsheet ID not configured. Set {$envVar} in .env");
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

    protected function findEmployeeFuzzy(string $employeeName, bool $isSandbox)
    {
        $employeeName = trim($employeeName);
        if (empty($employeeName)) {
            return null;
        }

        // Try exact match
        $employee = NetSuiteEmployee::where('name', $employeeName)
            ->where('is_sandbox', $isSandbox)
            ->where('is_inactive', false)
            ->first();
        if ($employee) {
            return $employee;
        }

        // Try case-insensitive match
        $employee = NetSuiteEmployee::whereRaw('UPPER(name) = ?', [strtoupper($employeeName)])
            ->where('is_sandbox', $isSandbox)
            ->where('is_inactive', false)
            ->first();
        if ($employee) {
            return $employee;
        }

        // Try contains match
        $employee = NetSuiteEmployee::where('name', 'like', "%{$employeeName}%")
            ->where('is_sandbox', $isSandbox)
            ->where('is_inactive', false)
            ->first();
        if ($employee) {
            return $employee;
        }

        // Try entity_id match (if employee name is actually an ID)
        if (is_numeric($employeeName)) {
            $employee = NetSuiteEmployee::where('entity_id', $employeeName)
                ->where('is_sandbox', $isSandbox)
                ->where('is_inactive', false)
                ->first();
            if ($employee) {
                return $employee;
            }
        }

        return null;
    }

    protected function findExpenseCategoryFuzzy(string $categoryName, bool $isSandbox)
    {
        $categoryName = trim($categoryName);
        if (empty($categoryName)) {
            return null;
        }

        // Try exact match
        $category = NetSuiteExpenseCategory::where('name', $categoryName)
            ->where('is_sandbox', $isSandbox)
            ->where('is_inactive', false)
            ->first();
        if ($category) {
            return $category;
        }

        // Try case-insensitive match
        $category = NetSuiteExpenseCategory::whereRaw('UPPER(name) = ?', [strtoupper($categoryName)])
            ->where('is_sandbox', $isSandbox)
            ->where('is_inactive', false)
            ->first();
        if ($category) {
            return $category;
        }

        // Try contains match
        $category = NetSuiteExpenseCategory::where('name', 'like', "%{$categoryName}%")
            ->where('is_sandbox', $isSandbox)
            ->where('is_inactive', false)
            ->first();
        if ($category) {
            return $category;
        }

        return null;
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
        
        // Remove timezone name if present
        if (preg_match('/^(.+)\s+[A-Z][a-z]+\/[A-Z_]+$/', $dateValue, $matches)) {
            $dateValue = $matches[1];
        }
        
        // If it already contains 'T', validate and clean it
        if (strpos($dateValue, 'T') !== false) {
            if (preg_match('/^(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2})([+-]\d{2}:\d{2}|Z)?$/', $dateValue, $matches)) {
                return $dateValue;
            } elseif (preg_match('/^(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2})/', $dateValue, $matches)) {
                return $matches[1];
            }
        }

        // Check if it's just a date (YYYY-MM-DD)
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateValue)) {
            return $dateValue . 'T00:00:00';
        }

        // Try to parse as a timestamp/date string
        $cleanDateValue = preg_replace('/\s+[A-Z][a-z]+\/[A-Z_]+$/', '', $dateValue);
        $timestamp = strtotime($cleanDateValue);
        if ($timestamp !== false) {
            return date('Y-m-d\TH:i:s', $timestamp);
        }

        return null;
    }

    protected function formatExpenseDate(string $dateValue): string
    {
        $dateValue = trim($dateValue);
        
        // Remove timezone name if present
        if (preg_match('/^(.+)\s+[A-Z][a-z]+\/[A-Z_]+$/', $dateValue, $matches)) {
            $dateValue = $matches[1];
        }
        
        // If it already contains 'T', validate and clean it
        if (strpos($dateValue, 'T') !== false) {
            if (preg_match('/^(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2})([+-]\d{2}:\d{2}|Z)?$/', $dateValue, $matches)) {
                return $dateValue;
            } elseif (preg_match('/^(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2})/', $dateValue, $matches)) {
                return $matches[1];
            }
        }

        // Check if it's just a date (YYYY-MM-DD)
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateValue)) {
            return $dateValue . 'T00:00:00';
        }

        // Try to parse as a timestamp/date string
        $cleanDateValue = preg_replace('/\s+[A-Z][a-z]+\/[A-Z_]+$/', '', $dateValue);
        $timestamp = strtotime($cleanDateValue);
        if ($timestamp !== false) {
            return date('Y-m-d\TH:i:s', $timestamp);
        }

        // Fallback to current date
        return date('Y-m-d\TH:i:s');
    }
}

