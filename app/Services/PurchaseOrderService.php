<?php

namespace App\Services;

use App\Models\NetSuiteAccount;
use App\Models\NetSuiteCountry;
use App\Models\NetSuiteCurrency;
use App\Models\NetSuiteDepartment;
use App\Models\NetSuiteLocation;
use App\Models\NetSuiteVendor;
use Illuminate\Support\Facades\Log;

class PurchaseOrderService
{
    protected $netSuiteService;

    public function __construct(NetSuiteService $netSuiteService)
    {
        $this->netSuiteService = $netSuiteService;
    }

    /**
     * Create Purchase Order from array data
     */
    public function createFromArray(array $data)
    {
        try {
            $service = $this->netSuiteService->getService();
            $isSandbox = config('netsuite.environment') === 'sandbox';
            
            // Create PurchaseOrder object
            $po = new \PurchaseOrder();
            
            // Set entity (vendor) - REQUIRED
            if (!isset($data['vendor_id'])) {
                throw new \Exception('Vendor ID is required');
            }
            
            $vendor = NetSuiteVendor::where('netsuite_id', $data['vendor_id'])
                ->where('is_sandbox', $isSandbox)
                ->first();
            
            if (!$vendor) {
                throw new \Exception("Vendor with ID {$data['vendor_id']} not found");
            }
            
            $po->entity = new \RecordRef();
            $po->entity->internalId = $vendor->netsuite_id;
            
            // Set memo
            if (isset($data['memo'])) {
                $po->memo = $data['memo'];
            }
            
            // Set transaction ID (tranId) if provided
            // Note: This only works if NetSuite has manual numbering enabled for Purchase Orders
            // If auto-numbering is enabled, NetSuite will ignore this and generate its own number
            if (isset($data['tran_id']) && !empty($data['tran_id'])) {
                $po->tranId = $data['tran_id'];
            }
            
            // Set location
            if (isset($data['location_id'])) {
                $location = NetSuiteLocation::where('netsuite_id', $data['location_id'])
                    ->where('is_sandbox', $isSandbox)
                    ->first();
                
                if ($location) {
                    $po->location = new \RecordRef();
                    $po->location->internalId = $location->netsuite_id;
                }
            }
            
            // Set department
            if (isset($data['department_id'])) {
                $department = NetSuiteDepartment::where('netsuite_id', $data['department_id'])
                    ->where('is_sandbox', $isSandbox)
                    ->first();
                
                if ($department) {
                    $po->department = new \RecordRef();
                    $po->department->internalId = $department->netsuite_id;
                }
            }
            
            // Set currency
            if (isset($data['currency_id'])) {
                $currency = NetSuiteCurrency::where('netsuite_id', $data['currency_id'])
                    ->where('is_sandbox', $isSandbox)
                    ->first();
                
                if ($currency) {
                    $po->currency = new \RecordRef();
                    $po->currency->internalId = $currency->netsuite_id;
                }
            }
            
            // Set item list (for inventory/non-inventory items)
            if (isset($data['items']) && is_array($data['items']) && count($data['items']) > 0) {
                $po->itemList = new \PurchaseOrderItemList();
                $po->itemList->replaceAll = true;
                
                $poItems = [];
                $lineNumber = 1;
                
                foreach ($data['items'] as $itemData) {
                    if (!isset($itemData['item_id'])) {
                        Log::warning("Item missing item_id, skipping");
                        continue;
                    }
                    
                    $poi = new \PurchaseOrderItem();
                    $poi->line = $lineNumber++;
                    $poi->item = new \RecordRef();
                    $poi->item->internalId = $itemData['item_id'];
                    
                    if (isset($itemData['quantity'])) {
                        $poi->quantity = (float) $itemData['quantity'];
                    }
                    
                    if (isset($itemData['rate'])) {
                        $poi->rate = (float) $itemData['rate'];
                    }
                    
                    if (isset($itemData['description'])) {
                        $poi->description = $itemData['description'];
                    }
                    
                    if (isset($itemData['department_id'])) {
                        $dept = NetSuiteDepartment::where('netsuite_id', $itemData['department_id'])
                            ->where('is_sandbox', $isSandbox)
                            ->first();
                        if ($dept) {
                            $poi->department = new \RecordRef();
                            $poi->department->internalId = $dept->netsuite_id;
                        }
                    }
                    
                    if (isset($itemData['location_id'])) {
                        $loc = NetSuiteLocation::where('netsuite_id', $itemData['location_id'])
                            ->where('is_sandbox', $isSandbox)
                            ->first();
                        if ($loc) {
                            $poi->location = new \RecordRef();
                            $poi->location->internalId = $loc->netsuite_id;
                        }
                    }
                    
                    $poItems[] = $poi;
                }
                
                if (count($poItems) > 0) {
                    $po->itemList->item = $poItems;
                }
            }
            
            // Set expense list (for items that don't exist in NetSuite)
            if (isset($data['expenses']) && is_array($data['expenses']) && count($data['expenses']) > 0) {
                $po->expenseList = new \PurchaseOrderExpenseList();
                $po->expenseList->replaceAll = true;
                
                $expenses = [];
                $lineNumber = 1;
                
                foreach ($data['expenses'] as $expenseData) {
                    if (!isset($expenseData['account_id']) || !isset($expenseData['amount'])) {
                        continue; // Skip invalid expenses
                    }
                    
                    $account = NetSuiteAccount::where('netsuite_id', $expenseData['account_id'])
                        ->where('is_sandbox', $isSandbox)
                        ->first();
                    
                    if (!$account) {
                        Log::warning("Account {$expenseData['account_id']} not found, skipping expense line");
                        continue;
                    }
                    
                    $exp = new \PurchaseOrderExpense();
                    $exp->line = $lineNumber++;
                    $exp->account = new \RecordRef();
                    $exp->account->internalId = $account->netsuite_id;
                    $exp->amount = (float) $expenseData['amount'];
                    
                    if (isset($expenseData['memo'])) {
                        $exp->memo = $expenseData['memo'];
                    }
                    
                    if (isset($expenseData['department_id'])) {
                        $dept = NetSuiteDepartment::where('netsuite_id', $expenseData['department_id'])
                            ->where('is_sandbox', $isSandbox)
                            ->first();
                        if ($dept) {
                            $exp->department = new \RecordRef();
                            $exp->department->internalId = $dept->netsuite_id;
                        }
                    }
                    
                    if (isset($expenseData['location_id'])) {
                        $loc = NetSuiteLocation::where('netsuite_id', $expenseData['location_id'])
                            ->where('is_sandbox', $isSandbox)
                            ->first();
                        if ($loc) {
                            $exp->location = new \RecordRef();
                            $exp->location->internalId = $loc->netsuite_id;
                        }
                    }
                    
                    if (isset($expenseData['tax_code_id'])) {
                        $exp->taxCode = new \RecordRef();
                        $exp->taxCode->internalId = $expenseData['tax_code_id'];
                    }
                    
                    $expenses[] = $exp;
                }
                
                if (count($expenses) > 0) {
                    $po->expenseList->expense = $expenses;
                }
            }
            
            // Validate we have at least one line item (either items or expenses)
            if ((!isset($po->itemList) || empty($po->itemList->item)) &&
                (!isset($po->expenseList) || empty($po->expenseList->expense))) {
                throw new \Exception('At least one item or expense line is required');
            }

            // Build custom fields list
            $customFields = [];

            // Supervisor field
            if (isset($data['supervisor_id'])) {
                $cf = new \SelectCustomFieldRef();
                $cf->scriptId = 'custbody_itg_supervisor';
                $cf->value = new \ListOrRecordRef();
                $cf->value->internalId = $data['supervisor_id'];
                $customFields[] = $cf;
            }

            // E-Invoicing fields - auto-populate from vendor data
            $einvFields = $this->getEInvoicingFieldsForPO($vendor, $data, $isSandbox);
            $customFields = array_merge($customFields, $einvFields);

            // Set custom field list if we have any fields
            if (!empty($customFields)) {
                $po->customFieldList = new \CustomFieldList();
                $po->customFieldList->customField = $customFields;
            }
            
            // Create the PO
            $request = new \AddRequest();
            $request->record = $po;
            
            $addResponse = $service->add($request);
            
            if (!$addResponse->writeResponse->status->isSuccess) {
                $errorMessage = 'PO creation failed: ';
                $netSuiteResponse = '';
                
                if (isset($addResponse->writeResponse->status->statusDetail)) {
                    $details = is_array($addResponse->writeResponse->status->statusDetail) 
                        ? $addResponse->writeResponse->status->statusDetail 
                        : [$addResponse->writeResponse->status->statusDetail];
                    
                    $responseParts = [];
                    foreach ($details as $detail) {
                        $errorMessage .= $detail->message . '; ';
                        $responseParts[] = [
                            'code' => $detail->code ?? '',
                            'message' => $detail->message ?? '',
                        ];
                    }
                    $netSuiteResponse = json_encode($responseParts);
                }
                
                return [
                    'success' => false,
                    'error' => trim($errorMessage),
                    'netsuite_response' => $netSuiteResponse,
                ];
            }
            
            // Get the created PO to retrieve tranId
            $internalId = $addResponse->writeResponse->baseRef->internalId;
            $tranId = null;
            
            try {
                $getRequest = new \GetRequest();
                $getRequest->baseRef = new \RecordRef();
                $getRequest->baseRef->internalId = $internalId;
                $getRequest->baseRef->type = "purchaseOrder";
                
                $getResponse = $service->get($getRequest);
                if ($getResponse->readResponse->status->isSuccess && isset($getResponse->readResponse->record->tranId)) {
                    $tranId = $getResponse->readResponse->record->tranId;
                }
            } catch (\Exception $e) {
                Log::warning('Could not retrieve tranId for created PO: ' . $e->getMessage());
                // Fallback to baseRef->name if available
                $tranId = $addResponse->writeResponse->baseRef->name ?? null;
            }
            
            return [
                'success' => true,
                'internal_id' => $internalId,
                'transaction_id' => $tranId,
            ];
            
        } catch (\SoapFault $e) {
            Log::error('Purchase Order Creation SOAP Error: ' . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'netsuite_response' => json_encode([
                    'fault_code' => $e->faultcode ?? '',
                    'fault_string' => $e->faultstring ?? '',
                ]),
            ];
        } catch (\Exception $e) {
            Log::error('Purchase Order Creation Error: ' . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'netsuite_response' => '',
            ];
        }
    }

    /**
     * Get e-invoicing custom fields for Purchase Order
     *
     * @param NetSuiteVendor $vendor
     * @param array $data
     * @param bool $isSandbox
     * @return array Array of custom field objects
     */
    protected function getEInvoicingFieldsForPO($vendor, $data, $isSandbox)
    {
        $customFields = [];

        // Get vendor's full NetSuite record to access custom fields
        $vendorRecord = $this->netSuiteService->getVendor($vendor->netsuite_id);

        // Determine if vendor is Malaysian
        $isMalaysian = false;
        $vendorCountry = '';

        if (isset($vendorRecord->addressbookList) && isset($vendorRecord->addressbookList->addressbook)) {
            $addressbooks = is_array($vendorRecord->addressbookList->addressbook)
                ? $vendorRecord->addressbookList->addressbook
                : [$vendorRecord->addressbookList->addressbook];

            foreach ($addressbooks as $addressbook) {
                if (isset($addressbook->addressbookAddress->country)) {
                    $vendorCountry = $addressbook->addressbookAddress->country;
                    $isMalaysian = in_array(strtoupper($vendorCountry), ['_MALAYSIA', 'MALAYSIA', 'MY', 'MYS']);
                    break;
                }
            }
        }

        // Get company name from vendor
        $companyName = $vendorRecord->companyName ?? $vendor->company_name ?? '';

        // Extract e-invoicing data from vendor custom fields if available
        $vendorTinNo = null;
        $vendorSstNo = null;
        $vendorMsicCode = null;
        $vendorAddress = null;
        $vendorCity = null;
        $vendorCountryCode = null;
        $vendorState = null;
        $vendorIdCode = null;
        $vendorIdType = null;

        if (isset($vendorRecord->customFieldList) && isset($vendorRecord->customFieldList->customField)) {
            $vendorCustomFields = is_array($vendorRecord->customFieldList->customField)
                ? $vendorRecord->customFieldList->customField
                : [$vendorRecord->customFieldList->customField];

            foreach ($vendorCustomFields as $field) {
                if (!isset($field->scriptId)) continue;

                switch ($field->scriptId) {
                    case 'custentity_einv_tin_no':
                        $vendorTinNo = $field->value ?? null;
                        break;
                    case 'custentity_einv_sst_register_no':
                        $vendorSstNo = $field->value ?? null;
                        break;
                    case 'custentity_einv_msic_code':
                        $vendorMsicCode = $field->value ?? null;
                        break;
                    case 'custentity_einv_address_line1':
                        $vendorAddress = $field->value ?? null;
                        break;
                    case 'custentity_einv_city_name':
                        $vendorCity = $field->value ?? null;
                        break;
                    case 'custentity_einv_country_code':
                        $vendorCountryCode = $field->value ?? null;
                        break;
                    case 'custentity_einv_state_code':
                        $vendorState = $field->value ?? null;
                        break;
                    case 'custentity_einv_identification_code':
                        $vendorIdCode = $field->value ?? null;
                        break;
                    case 'custentity_einv_identification_type':
                        $vendorIdType = $field->value ?? null;
                        break;
                }
            }
        }

        // Fallback to address data if not in custom fields
        if (!$vendorAddress && isset($vendorRecord->addressbookList->addressbook)) {
            $addressbooks = is_array($vendorRecord->addressbookList->addressbook)
                ? $vendorRecord->addressbookList->addressbook
                : [$vendorRecord->addressbookList->addressbook];

            foreach ($addressbooks as $addressbook) {
                if (isset($addressbook->addressbookAddress)) {
                    $addr = $addressbook->addressbookAddress;
                    if (!$vendorAddress && isset($addr->addr1)) {
                        $vendorAddress = $addr->addr1;
                    }
                    if (!$vendorCity && isset($addr->city)) {
                        $vendorCity = $addr->city;
                    }
                    if (!$vendorState && isset($addr->state)) {
                        $vendorState = $addr->state;
                    }
                    if (!$vendorCountryCode && isset($addr->country)) {
                        // Convert NetSuite country to ISO code
                        $country = NetSuiteCountry::where('country_code', $addr->country)
                            ->where('is_sandbox', $isSandbox)
                            ->first();
                        if ($country && $country->iso_code_2) {
                            $vendorCountryCode = $country->iso_code_2;
                        }
                    }
                    break;
                }
            }
        }

        // (EInv)TIN No - REQUIRED
        $tinNo = $vendorTinNo ?? ($isMalaysian ? null : 'EI000000000030');
        if ($tinNo) {
            $cf = new \StringCustomFieldRef();
            $cf->scriptId = 'custbody__eiv_tin_no';
            $cf->value = $tinNo;
            $customFields[] = $cf;
        }

        // (EInv)Registered Name - REQUIRED
        if ($companyName) {
            $cf = new \StringCustomFieldRef();
            $cf->scriptId = 'custbody__eiv_tin_registeredname';
            $cf->value = $companyName;
            $customFields[] = $cf;
        }

        // (EInv)SST Register No - REQUIRED
        $sstNo = $vendorSstNo ?? '0';
        $cf = new \StringCustomFieldRef();
        $cf->scriptId = 'custbody__eiv_tin_sstregisterno';
        $cf->value = $sstNo;
        $customFields[] = $cf;

        // (EInv)MSIC Code - REQUIRED (Select field, need internal ID)
        // For now, use default "00000 : NOT APPLICABLE" which has internalId = 1
        $cf = new \SelectCustomFieldRef();
        $cf->scriptId = 'custbody__eiv_tin_msic';
        $cf->value = new \ListOrRecordRef();
        $cf->value->internalId = '1'; // Default: 00000 : NOT APPLICABLE
        $customFields[] = $cf;

        // (EInv)Address Line1 - REQUIRED
        $address = $vendorAddress ?? '-';
        $cf = new \StringCustomFieldRef();
        $cf->scriptId = 'custbody__eiv_tin_addrline1';
        $cf->value = $address;
        $customFields[] = $cf;

        // (EInv)City Name - REQUIRED
        $city = $vendorCity ?? '-';
        $cf = new \StringCustomFieldRef();
        $cf->scriptId = 'custbody__eiv_tin_cityname';
        $cf->value = $city;
        $customFields[] = $cf;

        // (EInv)Country Code - REQUIRED (Select field)
        // Default to Malaysia (MY) if not specified
        // You'll need to map ISO codes to NetSuite's internal IDs
        // For now, using a placeholder - you may need to adjust based on your NetSuite setup
        $cf = new \SelectCustomFieldRef();
        $cf->scriptId = 'custbody__eiv_tin_countrycode';
        $cf->value = new \ListOrRecordRef();
        // This needs the internal ID from NetSuite's country list
        // Example: Malaysia might be ID 80, you'll need to map this
        $cf->value->internalId = '80'; // Placeholder - adjust based on actual NetSuite IDs
        $customFields[] = $cf;

        // (EInv)State Code - REQUIRED (Select field)
        // Default to "Not Applicable"
        $cf = new \SelectCustomFieldRef();
        $cf->scriptId = 'custbody__eiv_tin_statecode';
        $cf->value = new \ListOrRecordRef();
        $cf->value->internalId = '218'; // 17 : Not Applicable (from logs)
        $customFields[] = $cf;

        // (EInv)Identification Code - REQUIRED
        $idCode = $vendorIdCode ?? '-';
        $cf = new \StringCustomFieldRef();
        $cf->scriptId = 'custbody__eiv_tin_id';
        $cf->value = $idCode;
        $customFields[] = $cf;

        // (EInv)Identification Type - REQUIRED (Select field)
        // Default to BRN (Business Registration Number)
        $cf = new \SelectCustomFieldRef();
        $cf->scriptId = 'custbody__eiv_tin_idtype';
        $cf->value = new \ListOrRecordRef();
        $cf->value->internalId = '2'; // BRN : Business Registration No. (from logs)
        $customFields[] = $cf;

        // Boolean fields - set to false by default
        $booleanFields = [
            'custbody__eiv_iseinvoice',
            'custbody__eiv_einvconmark',
            'custbody__eiv_issubmitted',
            'custbody__eiv_isdebitnote',
        ];

        foreach ($booleanFields as $scriptId) {
            $cf = new \BooleanCustomFieldRef();
            $cf->scriptId = $scriptId;
            $cf->value = false;
            $customFields[] = $cf;
        }

        Log::info('E-Invoicing fields set for PO', [
            'vendor_id' => $vendor->netsuite_id,
            'company_name' => $companyName,
            'is_malaysian' => $isMalaysian,
            'tin_no' => $tinNo,
            'sst_no' => $sstNo,
            'address' => $address,
            'city' => $city,
            'id_code' => $idCode,
        ]);

        return $customFields;
    }
}

