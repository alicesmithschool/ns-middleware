<?php

namespace App\Console\Commands\NetSuite;

use App\Models\NetSuiteAccount;
use App\Models\NetSuiteCurrency;
use App\Models\NetSuiteDepartment;
use App\Models\NetSuiteLocation;
use App\Models\NetSuiteVendor;
use App\Services\BillService;
use Illuminate\Console\Command;

class PushBill extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'netsuite:push-bill 
                            {--vendor-id= : Vendor NetSuite ID (if not provided, will use first vendor from database)}
                            {--account-id= : Account NetSuite ID for expense line (if not provided, will use first account)}
                            {--reference-script-id= : Reference No. custom field script ID (default: custbody_reference_no)}
                            {--dry-run : Show what would be created without actually creating the bill}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Push a Vendor Bill to NetSuite using dummy data';

    /**
     * Execute the console command.
     */
    public function handle(BillService $billService)
    {
        $this->info('Creating Vendor Bill with dummy data...');
        
        $isSandbox = config('netsuite.environment') === 'sandbox';
        $this->info("Environment: " . ($isSandbox ? 'Sandbox' : 'Production'));
        
        // Get vendor ID
        $vendorId = $this->option('vendor-id');
        if (!$vendorId) {
            // Find first valid vendor (positive ID, not negative)
            $vendor = NetSuiteVendor::where('is_sandbox', $isSandbox)
                ->whereRaw('CAST(netsuite_id AS INTEGER) > 0')
                ->first();
            if (!$vendor) {
                $this->error('No valid vendor found in database. Please sync vendors first or provide --vendor-id option.');
                $this->line('');
                $this->line('To sync vendors, run: php artisan netsuite:sync-vendors --use-rest');
                return Command::FAILURE;
            }
            $vendorId = $vendor->netsuite_id;
            $this->info("Using vendor ID: {$vendorId} - {$vendor->name} (from database)");
        } else {
            // Validate vendor ID is positive
            if ((int)$vendorId <= 0) {
                $this->error("Invalid vendor ID: {$vendorId}. NetSuite IDs must be positive numbers.");
                return Command::FAILURE;
            }
            $vendor = NetSuiteVendor::where('netsuite_id', $vendorId)
                ->where('is_sandbox', $isSandbox)
                ->first();
            if (!$vendor) {
                $this->warn("Vendor ID {$vendorId} not found in database for current environment.");
                $this->line('But will try to use it anyway...');
            } else {
                $this->info("Using vendor ID: {$vendorId} - {$vendor->name} (from option)");
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
                $this->warn('No valid account found in database. Expense lines will be skipped.');
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
        
        // Get currency (optional)
        $currency = NetSuiteCurrency::where('is_sandbox', $isSandbox)->first();
        $currencyId = $currency ? $currency->netsuite_id : null;
        
        // Build dummy bill data
        // Note: Dates will be formatted with time component in BillService
        $billData = [
            'vendor_id' => $vendorId,
            'memo' => 'Test Bill created via API - Dummy Data',
            'trandate' => date('Y-m-d'), // Today's date (will be converted to ISO format)
            'duedate' => date('Y-m-d', strtotime('+30 days')), // 30 days from now (will be converted to ISO format)
            'supervisor_id' => '3467', // Default supervisor
            'tran_id' => 'DUMMY-REF-' . date('YmdHis'), // Dummy reference number (maps to Reference No. field, will be populated later)
        ];
        
        // Note: Location and Department cannot be set at VendorBill header level during creation
        // They will be set on individual expense/item lines instead
        
        if ($currencyId) {
            $billData['currency_id'] = $currencyId;
        }
        
        // Add expense lines (dummy data)
        if ($accountId) {
            $billData['expenses'] = [
                [
                    'account_id' => $accountId,
                    'amount' => 1000.00,
                    'memo' => 'Dummy expense line 1 - Office Supplies',
                    'department_id' => $departmentId,
                    'location_id' => $locationId,
                ],
                [
                    'account_id' => $accountId,
                    'amount' => 500.00,
                    'memo' => 'Dummy expense line 2 - Professional Services',
                    'department_id' => $departmentId,
                    'location_id' => $locationId,
                ],
            ];
        } else {
            $this->warn('');
            $this->warn('No account ID available - bill will be created without expense lines.');
            $this->warn('You can provide an account ID using: --account-id=58');
        }
        
        // Display what will be created
        $this->info('');
        $this->info('Bill Data to be created:');
        $this->line('  Vendor ID: ' . $billData['vendor_id']);
        $this->line('  Memo: ' . $billData['memo']);
        $this->line('  Transaction Date: ' . $billData['trandate']);
        $this->line('  Due Date: ' . $billData['duedate']);
        
        if (isset($billData['currency_id'])) {
            $this->line('  Currency ID: ' . $billData['currency_id']);
        }
        
        // Note: Location and Department are set on expense/item lines, not at bill header level
        if ($locationId || $departmentId) {
            $this->line('  Location/Department: Set on individual expense lines');
        }
        
        if (isset($billData['expenses'])) {
            $this->line('  Expense Lines: ' . count($billData['expenses']));
            foreach ($billData['expenses'] as $idx => $expense) {
                $lineNum = $idx + 1;
                $this->line("    Line {$lineNum}: Account={$expense['account_id']}, Amount={$expense['amount']}, Memo=\"{$expense['memo']}\"");
            }
        }
        
        // Dry run mode
        if ($this->option('dry-run')) {
            $this->info('');
            $this->warn('DRY RUN MODE - Bill will NOT be created');
            $this->info('Remove --dry-run flag to actually create the bill');
            return Command::SUCCESS;
        }
        
        // Create the bill
        $this->info('');
        $this->info('Creating bill in NetSuite...');
        
        $result = $billService->createFromArray($billData);
        
        if ($result['success']) {
            $this->info('');
            $this->info('✓ Bill created successfully!');
            $this->line('  Internal ID: ' . $result['internal_id']);
            $this->line('  Transaction ID: ' . ($result['transaction_id'] ?? 'N/A'));
            return Command::SUCCESS;
        } else {
            $this->error('');
            $this->error('✗ Bill creation failed!');
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

