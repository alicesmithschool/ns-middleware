<?php

namespace App\Console\Commands\NetSuite;

use App\Models\NetSuiteAccount;
use App\Services\NetSuiteService;
use Illuminate\Console\Command;

class SyncAccounts extends Command
{
    protected $signature = 'netsuite:sync-accounts';
    protected $description = 'Sync accounts from NetSuite to local database';

    public function handle(NetSuiteService $netSuiteService)
    {
        $this->info('Starting account sync...');

        try {
            $accounts = $netSuiteService->searchAccounts();
            
            if (empty($accounts)) {
                $this->warn('No accounts found in NetSuite.');
                return Command::SUCCESS;
            }

            $accounts = is_array($accounts) ? $accounts : [$accounts];
            $count = 0;
            $updated = 0;
            $created = 0;
            $isSandbox = config('netsuite.environment') === 'sandbox';

            foreach ($accounts as $account) {
                $count++;
                
                $data = [
                    'netsuite_id' => (string) $account->internalId,
                    'name' => $account->acctName ?? $account->name ?? 'Unknown',
                    'account_type' => $account->acctType ?? $account->type ?? null,
                    'account_number' => $account->acctNumber ?? null,
                    'is_inactive' => $account->isInactive ?? false,
                    'is_sandbox' => $isSandbox,
                ];

                $accountModel = NetSuiteAccount::updateOrCreate(
                    ['netsuite_id' => $data['netsuite_id'], 'is_sandbox' => $isSandbox],
                    $data
                );

                if ($accountModel->wasRecentlyCreated) {
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
            $this->error('Error syncing accounts: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }
}

