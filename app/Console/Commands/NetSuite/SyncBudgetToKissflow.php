<?php

namespace App\Console\Commands\NetSuite;

use App\Models\BudgetSyncSnapshot;
use App\Models\BudgetSyncTransaction;
use App\Services\GoogleSheetsService;
use App\Services\KissflowService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SyncBudgetToKissflow extends Command
{
    protected $signature = 'kissflow:sync-budget
                            {--month= : Period number/month to sync (1-12)}
                            {--year= : Financial year to sync (default: current year)}
                            {--sheet-id= : Override Google Sheets spreadsheet ID}
                            {--dry-run : Preview changes without updating Kissflow}
                            {--force : Force re-sync of already synced transactions}
                            {--import-only : Only import transactions to local DB, do not sync to Kissflow}';

    protected $description = 'Sync budget spent from Google Sheets (Others + PO) to Kissflow Budgets_01 dataset';

    // Column mapping for the spreadsheet
    protected $columnMap = [
        'department_code' => 'Department Code (BI)',
        'subcode' => 'Subcode (GL)',
        'transaction_date' => 'Transaction Date',
        'transaction_id' => 'Transaction ID',
        'transaction_type' => 'Transaction Type',
        'external_reference' => 'External Reference',
        'description' => 'Description',
        'financial_year' => 'Financial Year',
        'period_number' => 'Period Number',
        'period_name' => 'Period Name',
        'myr_amount' => 'MYR Amount',
        'currency_amount' => 'Currency Amount',
        'currency_code' => 'Currency Code',
        'exchange_rate' => 'ExchangeRate',
        'finance_staff' => 'Finance Staff',
        'invoice_id' => 'InvoiceID',
    ];

    public function handle(GoogleSheetsService $sheetsService, KissflowService $kissflowService)
    {
        $this->info('Starting Budget Sync to Kissflow...');
        $this->newLine();

        // Determine year and month
        $year = $this->option('year') ? (int) $this->option('year') : (int) date('Y');
        $month = $this->option('month') ? (int) $this->option('month') : null;

        if ($month === null) {
            $this->error('Please specify a month using --month=N (1-12)');
            return Command::FAILURE;
        }

        if ($month < 1 || $month > 12) {
            $this->error('Month must be between 1 and 12');
            return Command::FAILURE;
        }

        $monthName = date('F', mktime(0, 0, 0, $month, 1));
        $isDryRun = $this->option('dry-run');
        $isForce = $this->option('force');
        $importOnly = $this->option('import-only');

        $this->info("Configuration:");
        $this->info("  Financial Year: {$year}");
        $this->info("  Period: {$month} ({$monthName})");
        $this->info("  Mode: " . ($isDryRun ? 'DRY RUN' : ($importOnly ? 'IMPORT ONLY' : 'LIVE')));
        if ($isForce) {
            $this->warn("  Force mode: Will re-sync already synced transactions");
        }
        $this->newLine();

        try {
            // Override spreadsheet ID if provided
            if ($this->option('sheet-id')) {
                $sheetsService = new GoogleSheetsService($this->option('sheet-id'));
            }

            // Step 1: Import transactions from both sheets
            $this->info('=== Step 1: Importing Transactions ===');
            $othersCount = $this->importTransactionsFromSheet($sheetsService, 'Others', $year, $month);
            $poCount = $this->importTransactionsFromSheet($sheetsService, 'PO', $year, $month);
            
            $this->info("  Imported from 'Others': {$othersCount} transactions");
            $this->info("  Imported from 'PO': {$poCount} transactions");
            $this->newLine();

            if ($importOnly) {
                $this->info('Import-only mode. Skipping Kissflow sync.');
                return Command::SUCCESS;
            }

            // Step 2: Get unsynced transactions aggregated by department
            $this->info('=== Step 2: Aggregating Transactions ===');
            $query = BudgetSyncTransaction::forYear($year)->forPeriod($month);
            
            if (!$isForce) {
                $query->unsynced();
            }

            $aggregated = $query
                ->selectRaw('department_code, SUM(myr_amount) as total_amount, COUNT(*) as transaction_count, GROUP_CONCAT(id) as transaction_ids')
                ->groupBy('department_code')
                ->get();

            if ($aggregated->isEmpty()) {
                $this->warn('No unsynced transactions found for the specified period.');
                return Command::SUCCESS;
            }

            $this->info("  Found {$aggregated->count()} department(s) with transactions to sync:");
            foreach ($aggregated as $item) {
                $this->line("    - {$item->department_code}: " . number_format($item->total_amount, 2) . " MYR ({$item->transaction_count} transactions)");
            }
            $this->newLine();

            // Step 3: Fetch budget items from Kissflow
            $this->info('=== Step 3: Fetching Kissflow Budget Items ===');
            $budgetItems = $kissflowService->getBudgetItems();
            $this->info("  Retrieved " . count($budgetItems) . " budget items from Kissflow");

            // Build lookup map: Name -> item
            $budgetLookup = [];
            foreach ($budgetItems as $item) {
                $name = $item['Name'] ?? $item['name'] ?? null;
                if ($name) {
                    $budgetLookup[$name] = $item;
                }
            }
            $this->info("  Built lookup map with " . count($budgetLookup) . " entries");
            $this->newLine();

            // Step 4: Match and update
            $this->info('=== Step 4: Matching & Updating ===');
            $updatedCount = 0;
            $skippedCount = 0;
            $notFoundCount = 0;
            $errorCount = 0;

            $progressBar = $this->output->createProgressBar($aggregated->count());
            $progressBar->start();

            foreach ($aggregated as $item) {
                $departmentCode = $item->department_code;
                $totalAmount = (float) $item->total_amount;
                $transactionIds = explode(',', $item->transaction_ids);

                // Find matching Kissflow budget item
                if (!isset($budgetLookup[$departmentCode])) {
                    $this->newLine();
                    $this->warn("  ⚠ No Kissflow budget item found for: {$departmentCode}");
                    $notFoundCount++;
                    $progressBar->advance();
                    continue;
                }

                $kissflowItem = $budgetLookup[$departmentCode];
                $kissflowId = $kissflowItem['_id'] ?? null;
                $currentBudgetSpent = (float) ($kissflowItem['Budget_Spent'] ?? 0);

                if (!$kissflowId) {
                    $this->newLine();
                    $this->error("  ✗ No _id found for Kissflow item: {$departmentCode}");
                    $errorCount++;
                    $progressBar->advance();
                    continue;
                }

                // Calculate new Budget_Spent (accumulate)
                $newBudgetSpent = $currentBudgetSpent + $totalAmount;

                if ($isDryRun) {
                    $this->newLine();
                    $this->line("  [DRY RUN] {$departmentCode}:");
                    $this->line("    Current Budget_Spent: " . number_format($currentBudgetSpent, 2));
                    $this->line("    Amount to add: " . number_format($totalAmount, 2));
                    $this->line("    New Budget_Spent: " . number_format($newBudgetSpent, 2));
                    $updatedCount++;
                } else {
                    // Update Kissflow
                    $result = $kissflowService->updateBudgetSpent($kissflowId, $newBudgetSpent);

                    if ($result['success']) {
                        // Mark transactions as synced
                        BudgetSyncTransaction::whereIn('id', $transactionIds)
                            ->update([
                                'synced_at' => now(),
                                'kissflow_item_id' => $kissflowId,
                            ]);

                        // Create snapshot
                        BudgetSyncSnapshot::createSnapshot(
                            $departmentCode,
                            $kissflowId,
                            $year,
                            $month,
                            $monthName,
                            $currentBudgetSpent,
                            $totalAmount,
                            $newBudgetSpent,
                            $transactionIds
                        );

                        $updatedCount++;
                    } else {
                        $this->newLine();
                        $this->error("  ✗ Failed to update {$departmentCode}: " . ($result['error'] ?? 'Unknown error'));
                        $errorCount++;
                    }
                }

                $progressBar->advance();

                // Small delay to avoid rate limiting
                usleep(200000); // 0.2 seconds
            }

            $progressBar->finish();
            $this->newLine(2);

            // Summary
            $this->info('=== Sync Summary ===');
            $this->info("  Departments processed: {$aggregated->count()}");
            $this->info("  Successfully updated: {$updatedCount}");
            if ($notFoundCount > 0) {
                $this->warn("  Not found in Kissflow: {$notFoundCount}");
            }
            if ($skippedCount > 0) {
                $this->info("  Skipped (already synced): {$skippedCount}");
            }
            if ($errorCount > 0) {
                $this->error("  Errors: {$errorCount}");
            }

            if ($isDryRun) {
                $this->newLine();
                $this->warn('This was a DRY RUN. No changes were made to Kissflow.');
                $this->info('Run without --dry-run to apply changes.');
            }

            return $errorCount > 0 ? Command::FAILURE : Command::SUCCESS;

        } catch (\Exception $e) {
            $this->error('Error during budget sync: ' . $e->getMessage());
            Log::error('Budget Sync Error: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
            return Command::FAILURE;
        }
    }

    /**
     * Import transactions from a sheet into the local database
     */
    protected function importTransactionsFromSheet(
        GoogleSheetsService $sheetsService,
        string $sheetName,
        int $targetYear,
        int $targetMonth
    ): int {
        $this->line("  Reading '{$sheetName}' sheet...");

        $rows = $sheetsService->readSheet($sheetName);

        if (empty($rows) || count($rows) < 2) {
            $this->warn("  No data found in '{$sheetName}' sheet");
            return 0;
        }

        // Get headers and create column index map
        $headers = array_map('trim', $rows[0]);
        $columnIndexes = $this->mapColumnIndexes($headers);

        // Validate required columns
        $requiredColumns = ['department_code', 'transaction_id', 'financial_year', 'period_number', 'myr_amount'];
        foreach ($requiredColumns as $col) {
            if (!isset($columnIndexes[$col])) {
                $mappedName = $this->columnMap[$col] ?? $col;
                $this->error("  Missing required column '{$mappedName}' in '{$sheetName}' sheet");
                return 0;
            }
        }

        $dataRows = array_slice($rows, 1);
        $importedCount = 0;
        $skippedCount = 0;
        $filteredCount = 0;

        foreach ($dataRows as $rowIndex => $row) {
            // Get values using column indexes
            $transactionId = $this->getCellValue($row, $columnIndexes, 'transaction_id');
            $departmentCode = $this->getCellValue($row, $columnIndexes, 'department_code');
            $financialYear = (int) $this->getCellValue($row, $columnIndexes, 'financial_year');
            $periodNumber = (int) $this->getCellValue($row, $columnIndexes, 'period_number');
            $myrAmount = $this->parseAmount($this->getCellValue($row, $columnIndexes, 'myr_amount'));

            // Skip empty rows
            if (empty($transactionId) || empty($departmentCode)) {
                continue;
            }

            // Filter by year and month
            if ($financialYear !== $targetYear || $periodNumber !== $targetMonth) {
                $filteredCount++;
                continue;
            }

            // Check if already exists (deduplication)
            if (BudgetSyncTransaction::exists($transactionId, $sheetName)) {
                $skippedCount++;
                continue;
            }

            // Parse transaction date
            $transactionDate = $this->parseDate($this->getCellValue($row, $columnIndexes, 'transaction_date'));

            // Create transaction record
            try {
                BudgetSyncTransaction::create([
                    'transaction_id' => $transactionId,
                    'department_code' => $departmentCode,
                    'source_sheet' => $sheetName,
                    'subcode' => $this->getCellValue($row, $columnIndexes, 'subcode'),
                    'transaction_date' => $transactionDate,
                    'transaction_type' => $this->getCellValue($row, $columnIndexes, 'transaction_type'),
                    'external_reference' => $this->getCellValue($row, $columnIndexes, 'external_reference'),
                    'description' => $this->getCellValue($row, $columnIndexes, 'description'),
                    'financial_year' => $financialYear,
                    'period_number' => $periodNumber,
                    'period_name' => $this->getCellValue($row, $columnIndexes, 'period_name'),
                    'myr_amount' => $myrAmount,
                    'currency_amount' => $this->parseAmount($this->getCellValue($row, $columnIndexes, 'currency_amount')),
                    'currency_code' => $this->getCellValue($row, $columnIndexes, 'currency_code') ?: 'MYR',
                    'exchange_rate' => $this->parseAmount($this->getCellValue($row, $columnIndexes, 'exchange_rate')) ?: 1,
                    'finance_staff' => $this->getCellValue($row, $columnIndexes, 'finance_staff'),
                    'invoice_id' => $this->getCellValue($row, $columnIndexes, 'invoice_id'),
                ]);
                $importedCount++;
            } catch (\Exception $e) {
                Log::warning("Failed to import transaction {$transactionId}: " . $e->getMessage());
            }
        }

        if ($this->option('verbose')) {
            $this->line("    Total rows: " . count($dataRows));
            $this->line("    Filtered (different period): {$filteredCount}");
            $this->line("    Skipped (already imported): {$skippedCount}");
            $this->line("    Newly imported: {$importedCount}");
        }

        return $importedCount;
    }

    /**
     * Map column names to their indexes
     */
    protected function mapColumnIndexes(array $headers): array
    {
        $indexes = [];
        
        foreach ($this->columnMap as $key => $columnName) {
            $index = array_search($columnName, $headers);
            if ($index !== false) {
                $indexes[$key] = $index;
            }
        }

        return $indexes;
    }

    /**
     * Get cell value from row using column indexes
     */
    protected function getCellValue(array $row, array $columnIndexes, string $key): ?string
    {
        if (!isset($columnIndexes[$key])) {
            return null;
        }

        $index = $columnIndexes[$key];
        return isset($row[$index]) ? trim($row[$index]) : null;
    }

    /**
     * Parse amount string to float
     */
    protected function parseAmount(?string $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        // Remove commas and other formatting
        $cleaned = preg_replace('/[^\d.\-]/', '', $value);
        return $cleaned !== '' ? (float) $cleaned : null;
    }

    /**
     * Parse date string to Y-m-d format
     */
    protected function parseDate(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            // Try common formats
            $formats = ['d/m/Y', 'Y-m-d', 'm/d/Y', 'd-m-Y'];
            
            foreach ($formats as $format) {
                $date = \DateTime::createFromFormat($format, $value);
                if ($date !== false) {
                    return $date->format('Y-m-d');
                }
            }

            // Fallback to strtotime
            $timestamp = strtotime($value);
            if ($timestamp !== false) {
                return date('Y-m-d', $timestamp);
            }

            return null;
        } catch (\Exception $e) {
            return null;
        }
    }
}
