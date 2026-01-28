<?php

namespace App\Console\Commands\NetSuite;

use App\Services\NetSuiteRestService;
use Illuminate\Console\Command;

class TestPushVendor extends Command
{
    protected $signature = 'netsuite:test-push-vendor {--malaysian : Create Malaysian vendor} {--non-malaysian : Create non-Malaysian vendor}';
    protected $description = 'Test vendor creation with dummy data (no Google Sheets)';

    public function handle(NetSuiteRestService $netSuiteRestService)
    {
        $isMalaysian = $this->option('malaysian') || !$this->option('non-malaysian');
        
        $this->info('Testing vendor creation with dummy data...');
        $this->info('Environment: ' . config('netsuite.environment', 'sandbox'));
        $this->info('Vendor Type: ' . ($isMalaysian ? 'Malaysian' : 'Non-Malaysian'));
        $this->newLine();

        // Create dummy vendor data
        if ($isMalaysian) {
            $vendorData = $this->getMalaysianDummyData();
        } else {
            $vendorData = $this->getNonMalaysianDummyData();
        }

        // Display the data that will be sent
        $this->info('Vendor Data to be sent:');
        $this->line(json_encode($vendorData, JSON_PRETTY_PRINT));
        $this->newLine();

        // Confirm before creating
        if (!$this->confirm('Do you want to create this vendor in NetSuite?')) {
            $this->info('Cancelled.');
            return Command::SUCCESS;
        }

        // Create vendor
        try {
            $this->info('Creating vendor via REST API...');
            $result = $netSuiteRestService->createVendor($vendorData);

            if ($result['success']) {
                $this->info('✓ Vendor created successfully!');
                $this->line("Internal ID: {$result['internal_id']}");
                $this->line("Entity ID: {$result['entity_id']}");
            } else {
                $this->error('✗ Failed to create vendor');
                $this->line("Error: {$result['error']}");
                $this->newLine();
                $this->line('NetSuite Response:');
                $this->line($result['netsuite_response']);
            }

            return $result['success'] ? Command::SUCCESS : Command::FAILURE;
        } catch (\Exception $e) {
            $this->error('Exception: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }

    protected function getMalaysianDummyData()
    {
        return [
            'company_name' => 'Test Malaysian Vendor Sdn Bhd',
            'entity_id' => 'TEST_MY_' . time(),
            'email' => 'test@malaysian-vendor.com',
            'phone' => '+60123456789',
            'is_inactive' => false,
            
            // Address
            'address_1' => '123 Jalan Test',
            'address_2' => 'Unit 5-01',
            'city' => 'Kuala Lumpur',
            'state' => 'Selangor',
            'zip' => '50000',
            'country' => '_malaysia',
            
            // Currency
            'currency_id' => '1', // MYR
            
            // TIN/E-Invoicing fields
            'tin_no' => 'C12345678901234',
            'tin_registered_name' => 'Test Malaysian Vendor Sdn Bhd',
            'tin_sst_register_no' => 'A01-2345-67890123',
            'tin_msic_id' => '1', // 00000 : NOT APPLICABLE
            'tin_address_line1' => '123 Jalan Test',
            'tin_city_name' => 'Kuala Lumpur',
            'tin_country_code_id' => '158', // MYS : MALAYSIA
            'tin_state_code_id' => '218', // 17 : Not Applicable
            'tin_identification_code' => '0',
            'tin_id_type_id' => '2', // BRN : Business Registration No.
            'tin_tourism_tax' => 'T123456789'
        ];
    }

    protected function getNonMalaysianDummyData()
    {
        return [
            'company_name' => 'Test US Vendor Inc',
            'entity_id' => 'TEST_US_' . time(),
            'email' => 'test@us-vendor.com',
            'phone' => '+15551234567',
            'is_inactive' => false,
            
            // Address
            'address_1' => '123 Main Street',
            'address_2' => 'Suite 100',
            'city' => 'New York',
            'state' => 'NY',
            'zip' => '10001',
            'country' => '_unitedStates',
            
            // Currency
            'currency_id' => '2', // USD
            
            // TIN/E-Invoicing fields (non-Malaysian defaults)
            'tin_no' => 'EI000000000030',
            'tin_registered_name' => 'Test US Vendor Inc',
            'tin_sst_register_no' => 'NA',
            'tin_msic_id' => '1', // 00000 : NOT APPLICABLE
            'tin_address_line1' => '123 Main Street',
            'tin_city_name' => 'Not Applicable',
            'tin_country_code_id' => '239', // USA : UNITED STATES
            'tin_state_code_id' => '218', // 17 : Not Applicable
            'tin_identification_code' => '000000',
            'tin_id_type_id' => '2', // BRN : Business Registration No.
        ];
    }
}
