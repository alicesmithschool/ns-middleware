<?php

namespace App\Console\Commands\NetSuite;

use App\Services\NetSuiteService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ListRecentPOs extends Command
{
    protected $signature = 'netsuite:list-recent-pos {--limit=20 : Number of recent POs to fetch}';
    protected $description = 'List recent Purchase Orders with their Transaction IDs and Internal IDs';

    public function handle(NetSuiteService $netSuiteService)
    {
        $limit = (int) $this->option('limit');
        $environment = config('netsuite.environment', 'sandbox');

        $this->info("Listing {$limit} most recent Purchase Orders from {$environment}...");
        $this->newLine();

        try {
            $service = $netSuiteService->getService();

            // Search for recent POs
            $search = new \TransactionSearchBasic();
            $search->type = new \SearchEnumMultiSelectField();
            $search->type->operator = "anyOf";
            $search->type->searchValue = array("_purchaseOrder");

            $request = new \SearchRequest();
            $request->searchRecord = $search;

            $service->setSearchPreferences(false, $limit, true);
            $response = $service->search($request);

            if (!$response->searchResult->status->isSuccess) {
                $this->error('Failed to search for POs in NetSuite');
                return Command::FAILURE;
            }

            if ($response->searchResult->totalRecords == 0) {
                $this->warn("No Purchase Orders found in {$environment}");
                return Command::SUCCESS;
            }

            $this->info("Found {$response->searchResult->totalRecords} total PO(s)");
            $this->newLine();

            // Get the results
            $records = $response->searchResult->recordList->record;
            if (!is_array($records)) {
                $records = [$records];
            }

            // Display in table format
            $tableData = [];
            foreach ($records as $po) {
                $internalId = $po->internalId ?? 'N/A';
                $tranId = $po->tranId ?? 'N/A';
                $tranDate = 'N/A';

                if (isset($po->tranDate)) {
                    if (is_string($po->tranDate)) {
                        $tranDate = date('Y-m-d', strtotime($po->tranDate));
                    } elseif (is_numeric($po->tranDate)) {
                        $tranDate = date('Y-m-d', $po->tranDate);
                    }
                }

                $status = 'N/A';
                if (isset($po->status) && isset($po->status->name)) {
                    $status = $po->status->name;
                }

                $memo = $po->memo ?? '';
                if (strlen($memo) > 40) {
                    $memo = substr($memo, 0, 40) . '...';
                }

                $tableData[] = [
                    $internalId,
                    $tranId,
                    $tranDate,
                    $status,
                    $memo
                ];
            }

            $this->table(
                ['Internal ID', 'Transaction ID', 'Date', 'Status', 'Memo'],
                $tableData
            );

            $this->newLine();
            $this->info("To view PO details: php artisan netsuite:show-po-items {internal_id}");
            $this->info("To update PO date: Add Transaction ID to po_dates.json, then run: php artisan netsuite:update-po-dates");

            return Command::SUCCESS;

        } catch (\Exception $e) {
            $this->error('Error listing POs: ' . $e->getMessage());
            Log::error('List recent POs error: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }
}
