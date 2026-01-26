<?php

namespace App\Console\Commands\NetSuite;

use App\Models\NetSuiteAccount;
use App\Models\NetSuiteCurrency;
use App\Models\NetSuiteDepartment;
use App\Models\NetSuiteEmployee;
use App\Models\NetSuiteExpenseCategory;
use App\Models\NetSuiteLocation;
use App\Services\ExpenseReportService;
use Illuminate\Console\Command;

class PushExpenseReport extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'netsuite:push-expense-report 
                            {--employee-id= : Employee NetSuite ID (if not provided, will use default: 3467)}
                            {--category-id= : Expense Category NetSuite ID (if not provided, will use first category from database)}
                            {--account-id= : Expense Account NetSuite ID (if not provided, will use first account from database)}
                            {--dry-run : Show what would be created without actually creating the expense report}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Push an Expense Report to NetSuite using dummy data';

    /**
     * Execute the console command.
     */
    public function handle(ExpenseReportService $expenseReportService)
    {
        $this->info('Creating Expense Report with dummy data...');
        
        $isSandbox = config('netsuite.environment') === 'sandbox';
        $this->info("Environment: " . ($isSandbox ? 'Sandbox' : 'Production'));
        
        // Get employee ID
        $employeeId = $this->option('employee-id');
        if (!$employeeId) {
            // Find first valid employee (positive ID, not inactive)
            $employee = NetSuiteEmployee::where('is_sandbox', $isSandbox)
                ->where('is_inactive', false)
                ->whereRaw('CAST(netsuite_id AS INTEGER) > 0')
                ->first();
            
            if (!$employee) {
                $this->error('No valid employee found in database. Please sync employees first or provide --employee-id option.');
                $this->line('');
                $this->line('To sync employees, run: php artisan netsuite:sync-employees');
                $this->line('');
                $this->line('Or provide employee ID: --employee-id=3467');
                return Command::FAILURE;
            }
            $employeeId = $employee->netsuite_id;
            $this->info("Using employee ID: {$employeeId} - {$employee->name} (from database)");
        } else {
            // Validate employee ID is positive
            if ((int)$employeeId <= 0) {
                $this->error("Invalid employee ID: {$employeeId}. NetSuite IDs must be positive numbers.");
                return Command::FAILURE;
            }
            $employee = NetSuiteEmployee::where('netsuite_id', $employeeId)
                ->where('is_sandbox', $isSandbox)
                ->first();
            if (!$employee) {
                $this->warn("Employee ID {$employeeId} not found in database for current environment.");
                $this->line('But will try to use it anyway...');
            } else {
                $this->info("Using employee ID: {$employeeId} - {$employee->name} (from option)");
            }
        }
        
        // Get expense category ID
        $categoryId = $this->option('category-id');
        if (!$categoryId) {
            // Find first valid expense category (positive ID, not inactive)
            $category = NetSuiteExpenseCategory::where('is_sandbox', $isSandbox)
                ->where('is_inactive', false)
                ->whereRaw('CAST(netsuite_id AS INTEGER) > 0')
                ->first();
            
            if (!$category) {
                $this->error('No valid expense category found in database. Please sync expense categories first or provide --category-id option.');
                $this->line('');
                $this->line('To sync expense categories, run: php artisan netsuite:sync-expense-categories');
                return Command::FAILURE;
            }
            $categoryId = $category->netsuite_id;
            $this->info("Using expense category ID: {$categoryId} - {$category->name} (from database)");
        } else {
            // Validate category ID is positive
            if ((int)$categoryId <= 0) {
                $this->error("Invalid category ID: {$categoryId}. NetSuite IDs must be positive numbers.");
                return Command::FAILURE;
            }
            $category = NetSuiteExpenseCategory::where('netsuite_id', $categoryId)
                ->where('is_sandbox', $isSandbox)
                ->first();
            if (!$category) {
                $this->warn("Expense category ID {$categoryId} not found in database for current environment.");
                $this->line('But will try to use it anyway...');
            } else {
                $this->info("Using expense category ID: {$categoryId} - {$category->name} (from option)");
            }
        }
        
        // Get account ID for expense line (optional)
        $accountId = $this->option('account-id');
        if (!$accountId) {
            // Find first valid expense account (positive ID, prefer expense accounts)
            // Try to find an account with "expense" in the name or account number starting with 6 (typical expense accounts)
            $account = NetSuiteAccount::where('is_sandbox', $isSandbox)
                ->whereRaw('CAST(netsuite_id AS INTEGER) > 0')
                ->where(function($query) {
                    $query->where('name', 'like', '%expense%')
                          ->orWhere('account_number', 'like', '6%')
                          ->orWhere('account_number', 'like', '7%');
                })
                ->first();
            
            // If no expense account found, try any valid account
            if (!$account) {
                $account = NetSuiteAccount::where('is_sandbox', $isSandbox)
                    ->whereRaw('CAST(netsuite_id AS INTEGER) > 0')
                    ->first();
            }
            
            if ($account) {
                $accountId = $account->netsuite_id;
                $accountName = $account->name ?? $account->account_number ?? 'Unknown';
                $this->info("Using account ID: {$accountId} - {$accountName} (from database)");
            } else {
                $this->warn('No valid account found in database. Expense lines will not have expense account set.');
                $this->line('To sync accounts, run: php artisan netsuite:sync-accounts');
            }
        } else {
            // Validate account ID is positive
            if ((int)$accountId <= 0) {
                $this->error("Invalid account ID: {$accountId}. NetSuite IDs must be positive numbers.");
                return Command::FAILURE;
            }
            $account = NetSuiteAccount::where('netsuite_id', $accountId)
                ->where('is_sandbox', $isSandbox)
                ->first();
            if (!$account) {
                $this->warn("Account ID {$accountId} not found in database for current environment.");
                $this->line('But will try to use it anyway...');
            } else {
                $accountName = $account->name ?? $account->account_number ?? 'Unknown';
                $this->info("Using account ID: {$accountId} - {$accountName} (from option)");
            }
        }
        
        // Get location (optional)
        $location = NetSuiteLocation::where('is_sandbox', $isSandbox)->first();
        $locationId = $location ? $location->netsuite_id : null;
        
        // Get department (optional)
        $department = NetSuiteDepartment::where('is_sandbox', $isSandbox)->first();
        $departmentId = $department ? $department->netsuite_id : null;
        
        // Get currency (REQUIRED for ExpenseReport)
        $currency = NetSuiteCurrency::where('is_sandbox', $isSandbox)->first();
        if (!$currency) {
            $this->error('No currency found in database. Please sync currencies first.');
            $this->line('');
            $this->line('To sync currencies, run: php artisan netsuite:sync-currencies');
            return Command::FAILURE;
        }
        $currencyId = $currency->netsuite_id;
        $this->info("Using currency ID: {$currencyId} - {$currency->name} (from database)");
        
        // Build dummy expense report data
        // Note: Dates will be formatted with time component in ExpenseReportService
        $expenseReportData = [
            'employee_id' => $employeeId,
            'memo' => 'Test Expense Report created via API - Dummy Data',
            'trandate' => date('Y-m-d'), // Today's date (will be converted to ISO format)
            'tran_id' => 'DUMMY-EXP-' . date('YmdHis'), // Dummy reference number
            'currency_id' => $currencyId, // Currency is required for ExpenseReport
        ];
        
        // Note: Location and Department cannot be set at ExpenseReport header level during creation
        // They will be set on individual expense lines instead
        
        // Add expense lines (dummy data)
        $expenseReportData['expenses'] = [
            [
                'category_id' => $categoryId,
                'amount' => 150.00,
                'expense_date' => date('Y-m-d', strtotime('-2 days')), // 2 days ago
                'memo' => 'Dummy expense line 1 - Business Travel',
                'expense_account_id' => $accountId,
                'department_id' => $departmentId,
                'location_id' => $locationId,
            ],
            [
                'category_id' => $categoryId,
                'amount' => 75.50,
                'expense_date' => date('Y-m-d', strtotime('-1 day')), // Yesterday
                'memo' => 'Dummy expense line 2 - Meals',
                'expense_account_id' => $accountId,
                'department_id' => $departmentId,
                'location_id' => $locationId,
            ],
            [
                'category_id' => $categoryId,
                'amount' => 45.00,
                'expense_date' => date('Y-m-d'), // Today
                'memo' => 'Dummy expense line 3 - Office Supplies',
                'expense_account_id' => $accountId,
                'department_id' => $departmentId,
                'location_id' => $locationId,
            ],
        ];
        
        // Display what will be created
        $this->info('');
        $this->info('Expense Report Data to be created:');
        $this->line('  Employee ID: ' . $expenseReportData['employee_id']);
        $this->line('  Memo: ' . $expenseReportData['memo']);
        $this->line('  Transaction Date: ' . $expenseReportData['trandate']);
        $this->line('  Transaction ID: ' . $expenseReportData['tran_id']);
        
        if (isset($expenseReportData['currency_id'])) {
            $this->line('  Currency ID: ' . $expenseReportData['currency_id']);
        }
        
        // Note: Location and Department are set on expense/item lines, not at expense report header level
        if ($locationId || $departmentId) {
            $this->line('  Location/Department: Set on individual expense lines');
        }
        
        if (isset($expenseReportData['expenses'])) {
            $this->line('  Expense Lines: ' . count($expenseReportData['expenses']));
            foreach ($expenseReportData['expenses'] as $idx => $expense) {
                $lineNum = $idx + 1;
                $this->line("    Line {$lineNum}: Category={$expense['category_id']}, Amount={$expense['amount']}, Date={$expense['expense_date']}, Memo=\"{$expense['memo']}\"");
            }
        }
        
        // Dry run mode
        if ($this->option('dry-run')) {
            $this->info('');
            $this->warn('DRY RUN MODE - Expense Report will NOT be created');
            $this->info('Remove --dry-run flag to actually create the expense report');
            return Command::SUCCESS;
        }
        
        // Create the expense report
        $this->info('');
        $this->info('Creating expense report in NetSuite...');
        
        $result = $expenseReportService->createFromArray($expenseReportData);
        
        if ($result['success']) {
            $this->info('');
            $this->info('✓ Expense Report created successfully!');
            $this->line('  Internal ID: ' . $result['internal_id']);
            $this->line('  Transaction ID: ' . ($result['transaction_id'] ?? 'N/A'));
            return Command::SUCCESS;
        } else {
            $this->error('');
            $this->error('✗ Expense Report creation failed!');
            $this->error('  Error: ' . $result['error']);
            
            if (isset($result['netsuite_response']) && !empty($result['netsuite_response'])) {
                $this->line('');
                $this->warn('NetSuite Response:');
                $responseData = json_decode($result['netsuite_response'], true);
                if ($responseData) {
                    $this->line(json_encode($responseData, JSON_PRETTY_PRINT));
                } else {
                    $this->line($result['netsuite_response']);
                }
            }
            
            return Command::FAILURE;
        }
    }
}

