<?php

namespace App\Console\Commands\NetSuite;

use App\Models\NetSuiteItem;
use App\Services\NetSuiteService;
use Illuminate\Console\Command;

class SyncItems extends Command
{
    protected $signature = 'netsuite:sync-items';
    protected $description = 'Sync items from NetSuite to local database';

    public function handle(NetSuiteService $netSuiteService)
    {
        $this->info('Starting item sync...');

        try {
            $items = $netSuiteService->searchItems();
            
            if (empty($items)) {
                $this->warn('No items found in NetSuite.');
                return Command::SUCCESS;
            }

            $items = is_array($items) ? $items : [$items];
            $count = 0;
            $updated = 0;
            $created = 0;
            $isSandbox = config('netsuite.environment') === 'sandbox';

            $this->withProgressBar($items, function ($item) use (&$count, &$updated, &$created, $isSandbox) {
                $count++;
                
                // Extract item type
                $itemType = null;
                if (isset($item->itemType)) {
                    $itemType = is_object($item->itemType) ? $item->itemType->getValue() : $item->itemType;
                }
                
                // Extract base price/rate
                $basePrice = null;
                if (isset($item->basePrice)) {
                    $basePrice = (float) $item->basePrice;
                } elseif (isset($item->rate)) {
                    $basePrice = (float) $item->rate;
                }
                
                // Extract unit of measure
                $unitOfMeasure = null;
                if (isset($item->unitOfMeasure)) {
                    $unitOfMeasure = is_object($item->unitOfMeasure) 
                        ? ($item->unitOfMeasure->name ?? null) 
                        : $item->unitOfMeasure;
                }
                
                $data = [
                    'netsuite_id' => (string) $item->internalId,
                    'name' => $item->name ?? $item->displayName ?? 'Unknown',
                    'item_number' => $item->itemId ?? $item->itemNumber ?? null,
                    'item_type' => $itemType,
                    'description' => $item->description ?? null,
                    'base_price' => $basePrice,
                    'unit_of_measure' => $unitOfMeasure,
                    'is_inactive' => $item->isInactive ?? false,
                    'is_sandbox' => $isSandbox,
                ];

                $itemModel = NetSuiteItem::updateOrCreate(
                    ['netsuite_id' => $data['netsuite_id'], 'is_sandbox' => $isSandbox],
                    $data
                );

                if ($itemModel->wasRecentlyCreated) {
                    $created++;
                } else {
                    $updated++;
                }
            });

            $this->newLine(2);
            $this->info("Sync completed!");
            $this->info("Total processed: {$count}");
            $this->info("Created: {$created}");
            $this->info("Updated: {$updated}");

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error('Error syncing items: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }
}
