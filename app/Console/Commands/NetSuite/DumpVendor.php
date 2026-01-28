<?php

namespace App\Console\Commands\NetSuite;

use App\Services\NetSuiteRestService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class DumpVendor extends Command
{
    protected $signature = 'netsuite:dump-vendor {--id= : Vendor internal ID} {--entity-id= : Vendor entity ID} {--output= : Output file path (default: vendor_reference.json)}';
    protected $description = 'Fetch one vendor from NetSuite with complete data including all custom fields and save to JSON file';

    public function handle(NetSuiteRestService $netSuiteRestService)
    {
        $vendorId = $this->option('id');
        $entityId = $this->option('entity-id');
        $outputPath = $this->option('output') ?: 'vendor_reference.json';

        try {
            // If no ID provided, search for first vendor
            if (!$vendorId && !$entityId) {
                $this->info('No vendor ID provided. Searching for first vendor...');
                $vendors = $netSuiteRestService->searchVendors(['id'], null, 1);
                
                if (empty($vendors)) {
                    $this->error('No vendors found in NetSuite.');
                    return Command::FAILURE;
                }
                
                $vendorId = $vendors[0]['id'];
                $this->info("Found vendor with ID: {$vendorId}");
            }

            // If entity ID provided, search for vendor by entity ID
            if ($entityId && !$vendorId) {
                $this->info("Searching for vendor with entity ID: {$entityId}...");
                $vendors = $netSuiteRestService->searchVendors(['id'], "entityid = '{$entityId}'", 1);
                
                if (empty($vendors)) {
                    $this->error("No vendor found with entity ID: {$entityId}");
                    return Command::FAILURE;
                }
                
                $vendorId = $vendors[0]['id'];
                $this->info("Found vendor with internal ID: {$vendorId}");
            }

            // Fetch complete vendor record
            $this->info("Fetching complete vendor data for ID: {$vendorId}...");
            $vendor = $netSuiteRestService->getVendor($vendorId);

            // Extract custom fields for easier reference
            $customFields = [];
            foreach ($vendor as $key => $value) {
                if (preg_match('/^(custentity_|custbody_|custitem_|custrecord_|cseg_|custcol_)/', $key)) {
                    $customFields[$key] = $value;
                }
            }
            
            // Add customFields section to vendor data for easier reference
            $vendor['_customFields'] = $customFields;
            $vendor['_customFieldsCount'] = count($customFields);

            // Save to JSON file
            $outputFile = base_path($outputPath);
            $jsonData = json_encode($vendor, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            
            File::put($outputFile, $jsonData);

            $this->info("Vendor data saved to: {$outputFile}");
            $this->info("Vendor ID: " . ($vendor['id'] ?? 'N/A'));
            $this->info("Entity ID: " . ($vendor['entityId'] ?? 'N/A'));
            $this->info("Company Name: " . ($vendor['companyName'] ?? 'N/A'));
            
            // Use the extracted custom fields
            $customFields = $vendor['_customFields'] ?? [];
            $customFieldsCount = count($customFields);
            $this->info("Custom Fields: {$customFieldsCount}");

            // Display custom field keys
            if ($customFieldsCount > 0) {
                $this->newLine();
                $this->comment('Custom Field Keys:');
                foreach (array_keys($customFields) as $key) {
                    $this->line("  - {$key}");
                }
            }

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error('Error fetching vendor: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }
}
