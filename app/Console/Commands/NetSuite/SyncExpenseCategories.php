<?php

namespace App\Console\Commands\NetSuite;

use App\Models\NetSuiteExpenseCategory;
use App\Services\NetSuiteService;
use Illuminate\Console\Command;

class SyncExpenseCategories extends Command
{
    protected $signature = 'netsuite:sync-expense-categories';
    protected $description = 'Sync expense categories from NetSuite to local database';

    public function handle(NetSuiteService $netSuiteService)
    {
        $this->info('Starting expense category sync...');

        try {
            $categories = $netSuiteService->searchExpenseCategories();
            
            if (empty($categories)) {
                $this->warn('No expense categories found in NetSuite.');
                return Command::SUCCESS;
            }

            $categories = is_array($categories) ? $categories : [$categories];
            $count = 0;
            $updated = 0;
            $created = 0;
            $isSandbox = config('netsuite.environment') === 'sandbox';

            $this->info("Found " . count($categories) . " expense categories. Syncing...");

            foreach ($categories as $category) {
                $count++;
                
                $data = [
                    'netsuite_id' => (string) $category->internalId,
                    'name' => $category->name ?? 'Unknown',
                    'description' => $category->description ?? null,
                    'is_inactive' => $category->isInactive ?? false,
                    'is_sandbox' => $isSandbox,
                ];

                $categoryModel = NetSuiteExpenseCategory::updateOrCreate(
                    ['netsuite_id' => $data['netsuite_id'], 'is_sandbox' => $isSandbox],
                    $data
                );

                if ($categoryModel->wasRecentlyCreated) {
                    $created++;
                } else {
                    $updated++;
                }
            }

            $this->info("Sync completed!");
            $this->info("Total processed: {$count}");
            $this->info("Created: {$created}");
            $this->info("Updated: {$updated}");

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error('Error syncing expense categories: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }
}


