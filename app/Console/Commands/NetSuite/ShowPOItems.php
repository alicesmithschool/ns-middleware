<?php

namespace App\Console\Commands\NetSuite;

use App\Services\NetSuiteService;
use Illuminate\Console\Command;

class ShowPOItems extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'netsuite:show-po-items {internal_id : NetSuite internal ID of the Purchase Order}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Show item and expense lines for a NetSuite Purchase Order by internal ID';

    /**
     * Execute the console command.
     */
    public function handle(NetSuiteService $netSuiteService)
    {
        $internalId = $this->argument('internal_id');
        $this->info("Fetching Purchase Order with internal ID: {$internalId}");

        $po = $netSuiteService->getPurchaseOrderByInternalId($internalId);

        if (!$po) {
            $this->error("Purchase Order with internal ID {$internalId} not found or could not be retrieved.");
            return Command::FAILURE;
        }

        $this->line('');
        $this->info("PO Summary:");
        $this->line("  Tran ID: " . ($po->tranId ?? '(unknown)'));
        $this->line("  Memo: " . ($po->memo ?? ''));
        $this->line("  Date: " . ($po->tranDate ?? '(unknown)'));

        // Show item lines
        $this->line('');
        $this->info("Item Lines (itemList):");
        if (isset($po->itemList) && isset($po->itemList->item) && !empty($po->itemList->item)) {
            $items = is_array($po->itemList->item) ? $po->itemList->item : [$po->itemList->item];
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

        // Show expense lines
        $this->line('');
        $this->info("Expense Lines (expenseList):");
        if (isset($po->expenseList) && isset($po->expenseList->expense) && !empty($po->expenseList->expense)) {
            $expenses = is_array($po->expenseList->expense) ? $po->expenseList->expense : [$po->expenseList->expense];
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

        $this->line('');
        $this->info('Done.');
        return Command::SUCCESS;
    }
}
