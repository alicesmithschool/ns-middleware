<?php

namespace App\Console\Commands\NetSuite;

use App\Services\NetSuiteService;
use Illuminate\Console\Command;

class ShowBill extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'netsuite:show-bill {internal_id : NetSuite internal ID of the Vendor Bill}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Show details and custom fields for a NetSuite Vendor Bill by internal ID';

    /**
     * Execute the console command.
     */
    public function handle(NetSuiteService $netSuiteService)
    {
        $internalId = $this->argument('internal_id');
        $this->info("Fetching Vendor Bill with internal ID: {$internalId}");

        try {
            $service = $netSuiteService->getService();
            
            $getRequest = new \GetRequest();
            $getRequest->baseRef = new \RecordRef();
            $getRequest->baseRef->internalId = $internalId;
            $getRequest->baseRef->type = "vendorBill";
            
            $getResponse = $service->get($getRequest);
            
            if (!$getResponse->readResponse->status->isSuccess) {
                $this->error("Vendor Bill with internal ID {$internalId} not found or could not be retrieved.");
                return Command::FAILURE;
            }
            
            $bill = $getResponse->readResponse->record;
            
            $this->line('');
            $this->info("Bill Summary:");
            $this->line("  Internal ID: " . ($bill->internalId ?? '(unknown)'));
            $this->line("  Tran ID: " . ($bill->tranId ?? '(unknown)'));
            $this->line("  Memo: " . ($bill->memo ?? ''));
            $this->line("  Date: " . ($bill->tranDate ?? '(unknown)'));
            $this->line("  Due Date: " . ($bill->dueDate ?? '(unknown)'));
            
            // Show custom fields
            $this->line('');
            $this->info("Custom Fields:");
            if (isset($bill->customFieldList) && isset($bill->customFieldList->customField)) {
                $customFields = is_array($bill->customFieldList->customField) 
                    ? $bill->customFieldList->customField 
                    : [$bill->customFieldList->customField];
                
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
                    
                    // Highlight Reference No. field
                    if (stripos($scriptId, 'reference') !== false || stripos($scriptId, 'ref') !== false) {
                        $this->line("    ⭐ This might be the Reference No. field!");
                    }
                }
            } else {
                $this->line("  (no custom fields)");
            }
            
            // Show expense lines
            $this->line('');
            $this->info("Expense Lines (expenseList):");
            if (isset($bill->expenseList) && isset($bill->expenseList->expense) && !empty($bill->expenseList->expense)) {
                $expenses = is_array($bill->expenseList->expense) ? $bill->expenseList->expense : [$bill->expenseList->expense];
                foreach ($expenses as $idx => $line) {
                    $lineNum = $idx + 1;
                    $accountId = isset($line->account) && isset($line->account->internalId) ? $line->account->internalId : '(no account)';
                    $amount = $line->amount ?? '';
                    $memo = $line->memo ?? '';
                    $this->line("  [Expense {$lineNum}] accountId={$accountId}, amount={$amount}, memo=\"{$memo}\"");
                }
            } else {
                $this->line("  (no expenseList lines)");
            }
            
            // Show item lines
            $this->line('');
            $this->info("Item Lines (itemList):");
            if (isset($bill->itemList) && isset($bill->itemList->item) && !empty($bill->itemList->item)) {
                $items = is_array($bill->itemList->item) ? $bill->itemList->item : [$bill->itemList->item];
                foreach ($items as $idx => $line) {
                    $lineNum = $idx + 1;
                    $itemId = isset($line->item) && isset($line->item->internalId) ? $line->item->internalId : '(no item ref)';
                    $rate = $line->rate ?? '';
                    $qty = $line->quantity ?? '';
                    $amount = $line->amount ?? '';
                    $desc = $line->description ?? '';
                    $this->line("  [Item {$lineNum}] itemId={$itemId}, qty={$qty}, rate={$rate}, amount={$amount}, desc=\"{$desc}\"");
                }
            } else {
                $this->line("  (no itemList lines)");
            }
            
            $this->line('');
            $this->info('Done.');
            return Command::SUCCESS;
            
        } catch (\Exception $e) {
            $this->error("Error: " . $e->getMessage());
            return Command::FAILURE;
        }
    }
}




