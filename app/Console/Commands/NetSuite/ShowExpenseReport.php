<?php

namespace App\Console\Commands\NetSuite;

use App\Services\NetSuiteService;
use Illuminate\Console\Command;

class ShowExpenseReport extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'netsuite:show-expense-report {internal_id : NetSuite internal ID of the Expense Report}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Show details of a NetSuite Expense Report by internal ID, including how currency is attached';

    /**
     * Execute the console command.
     */
    public function handle(NetSuiteService $netSuiteService)
    {
        $internalId = $this->argument('internal_id');
        $this->info("Fetching Expense Report with internal ID: {$internalId}");

        try {
            $service = $netSuiteService->getService();
            
            $getRequest = new \GetRequest();
            $getRequest->baseRef = new \RecordRef();
            $getRequest->baseRef->internalId = $internalId;
            $getRequest->baseRef->type = "expenseReport";
            
            $getResponse = $service->get($getRequest);
            
            if (!$getResponse->readResponse->status->isSuccess) {
                $this->error("Expense Report with internal ID {$internalId} not found or could not be retrieved.");
                if (isset($getResponse->readResponse->status->statusDetail)) {
                    $details = is_array($getResponse->readResponse->status->statusDetail) 
                        ? $getResponse->readResponse->status->statusDetail 
                        : [$getResponse->readResponse->status->statusDetail];
                    foreach ($details as $detail) {
                        $this->error("  " . ($detail->message ?? 'Unknown error'));
                    }
                }
                return Command::FAILURE;
            }
            
            $expenseReport = $getResponse->readResponse->record;
            
            $this->line('');
            $this->info("=== Expense Report Summary ===");
            $this->line("  Internal ID: " . ($expenseReport->internalId ?? '(unknown)'));
            $this->line("  Tran ID: " . ($expenseReport->tranId ?? '(unknown)'));
            $this->line("  Memo: " . ($expenseReport->memo ?? ''));
            $this->line("  Date: " . ($expenseReport->tranDate ?? '(unknown)'));
            $this->line("  Due Date: " . ($expenseReport->dueDate ?? '(unknown)'));
            
            // Show entity (employee)
            $this->line('');
            $this->info("=== Entity (Employee) ===");
            if (isset($expenseReport->entity)) {
                $this->line("  Internal ID: " . ($expenseReport->entity->internalId ?? '(unknown)'));
                $this->line("  Type: " . ($expenseReport->entity->type ?? '(unknown)'));
                $this->line("  Name: " . ($expenseReport->entity->name ?? '(unknown)'));
            } else {
                $this->line("  (not set)");
            }
            
            // Show currency - THIS IS WHAT WE'RE LOOKING FOR
            $this->line('');
            $this->info("=== Currency (expenseReportCurrency) ===");
            if (isset($expenseReport->expenseReportCurrency)) {
                $currency = $expenseReport->expenseReportCurrency;
                $this->line("  Internal ID: " . ($currency->internalId ?? '(unknown)'));
                $this->line("  Type: " . ($currency->type ?? '(NOT SET - this might be the issue!)'));
                $this->line("  Name: " . ($currency->name ?? '(unknown)'));
                $this->line("  Full object: " . json_encode([
                    'internalId' => $currency->internalId ?? null,
                    'type' => $currency->type ?? null,
                    'name' => $currency->name ?? null,
                ], JSON_PRETTY_PRINT));
            } else {
                $this->warn("  ⚠️  Currency is NOT SET on this expense report!");
            }
            
            // Show exchange rate if present
            if (isset($expenseReport->expenseReportExchangeRate)) {
                $this->line("  Exchange Rate: " . $expenseReport->expenseReportExchangeRate);
            }
            
            // Show expense lines
            $this->line('');
            $this->info("=== Expense Lines (expenseList) ===");
            if (isset($expenseReport->expenseList) && isset($expenseReport->expenseList->expense) && !empty($expenseReport->expenseList->expense)) {
                $expenses = is_array($expenseReport->expenseList->expense) 
                    ? $expenseReport->expenseList->expense 
                    : [$expenseReport->expenseList->expense];
                
                foreach ($expenses as $idx => $line) {
                    $lineNum = $idx + 1;
                    $this->line("");
                    $this->line("  [Expense Line {$lineNum}]");
                    $this->line("    Line Number: " . ($line->line ?? '(unknown)'));
                    $this->line("    Category ID: " . (isset($line->category) && isset($line->category->internalId) ? $line->category->internalId : '(no category)'));
                    $this->line("    Category Type: " . (isset($line->category) && isset($line->category->type) ? $line->category->type : '(not set)'));
                    $this->line("    Amount: " . ($line->amount ?? '(unknown)'));
                    $this->line("    Expense Date: " . ($line->expenseDate ?? '(unknown)'));
                    $this->line("    Memo: " . ($line->memo ?? ''));
                    
                    // Check if currency is set on expense line
                    if (isset($line->currency)) {
                        $this->line("    Currency on Line: " . ($line->currency->internalId ?? '(unknown)') . " (type: " . ($line->currency->type ?? 'not set') . ")");
                    }
                    
                    if (isset($line->expenseAccount)) {
                        $this->line("    Expense Account ID: " . ($line->expenseAccount->internalId ?? '(unknown)'));
                        $this->line("    Expense Account Type: " . ($line->expenseAccount->type ?? '(not set)'));
                    }
                    
                    if (isset($line->department)) {
                        $this->line("    Department ID: " . ($line->department->internalId ?? '(unknown)'));
                    }
                    
                    if (isset($line->location)) {
                        $this->line("    Location ID: " . ($line->location->internalId ?? '(unknown)'));
                    }
                }
            } else {
                $this->line("  (no expenseList lines)");
            }
            
            // Show custom fields
            $this->line('');
            $this->info("=== Custom Fields ===");
            if (isset($expenseReport->customFieldList) && isset($expenseReport->customFieldList->customField)) {
                $customFields = is_array($expenseReport->customFieldList->customField) 
                    ? $expenseReport->customFieldList->customField 
                    : [$expenseReport->customFieldList->customField];
                
                foreach ($customFields as $field) {
                    $scriptId = $field->scriptId ?? 'unknown';
                    $value = '';
                    
                    if (isset($field->value)) {
                        if (is_object($field->value)) {
                            if (isset($field->value->internalId)) {
                                $value = "ID: " . $field->value->internalId;
                                if (isset($field->value->name)) {
                                    $value .= " (" . $field->value->name . ")";
                                }
                            } else {
                                $value = json_encode($field->value);
                            }
                        } else {
                            $value = (string) $field->value;
                        }
                    }
                    
                    $this->line("  [{$scriptId}] = {$value}");
                }
            } else {
                $this->line("  (no custom fields)");
            }
            
            // Dump raw object structure for debugging
            $this->line('');
            $this->info("=== Raw Currency Object Structure ===");
            if (isset($expenseReport->expenseReportCurrency)) {
                $this->line(print_r($expenseReport->expenseReportCurrency, true));
            } else {
                $this->line("Currency object not present");
            }
            
            $this->line('');
            $this->info('Done.');
            return Command::SUCCESS;
            
        } catch (\Exception $e) {
            $this->error("Error: " . $e->getMessage());
            $this->error("Stack trace: " . $e->getTraceAsString());
            return Command::FAILURE;
        }
    }
}


