<?php

namespace App\Services;

use App\Models\NetSuiteAccount;
use App\Models\NetSuiteCurrency;
use App\Models\NetSuiteDepartment;
use App\Models\NetSuiteLocation;
use App\Models\NetSuiteVendor;
use Illuminate\Support\Facades\Log;

class BillService
{
    protected $netSuiteService;

    public function __construct(NetSuiteService $netSuiteService)
    {
        $this->netSuiteService = $netSuiteService;
    }

    /**
     * Create Vendor Bill from array data
     */
    public function createFromArray(array $data)
    {
        try {
            $service = $this->netSuiteService->getService();
            $isSandbox = config('netsuite.environment') === 'sandbox';
            
            // Create VendorBill object
            $bill = new \VendorBill();
            
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
            
            $bill->entity = new \RecordRef();
            $bill->entity->internalId = $vendor->netsuite_id;
            $bill->entity->type = 'vendor';
            
            // Set memo
            if (isset($data['memo'])) {
                $bill->memo = $data['memo'];
            }
            
            // Set transaction ID (tranId) - this maps to "Reference No." field
            // If not provided, will be set later with dummy value
            if (isset($data['tran_id']) && !empty($data['tran_id'])) {
                $bill->tranId = $data['tran_id'];
            } elseif (isset($data['reference_no']) && !empty($data['reference_no'])) {
                // Allow reference_no to set tranId
                $bill->tranId = $data['reference_no'];
            }
            
            // Note: Location and Department cannot be set at VendorBill header level during creation
            // They must be set on individual line items (expenses or items) instead
            
            // Set currency
            if (isset($data['currency_id'])) {
                $currency = NetSuiteCurrency::where('netsuite_id', $data['currency_id'])
                    ->where('is_sandbox', $isSandbox)
                    ->first();
                
                if ($currency) {
                    $bill->currency = new \RecordRef();
                    $bill->currency->internalId = $currency->netsuite_id;
                }
            }
            
            // Set transaction date
            // NetSuite requires dates with time component (ISO 8601 format)
            if (isset($data['trandate'])) {
                // If already in ISO format, use as-is, otherwise convert
                if (strpos($data['trandate'], 'T') !== false) {
                    $bill->tranDate = $data['trandate'];
                } else {
                    // Convert YYYY-MM-DD to YYYY-MM-DDTHH:MM:SS
                    $bill->tranDate = $data['trandate'] . 'T00:00:00';
                }
            } else {
                // Default to today with time component
                $bill->tranDate = date('Y-m-d\TH:i:s');
            }
            
            // Set due date
            if (isset($data['duedate'])) {
                // If already in ISO format, use as-is, otherwise convert
                if (strpos($data['duedate'], 'T') !== false) {
                    $bill->dueDate = $data['duedate'];
                } else {
                    // Convert YYYY-MM-DD to YYYY-MM-DDTHH:MM:SS
                    $bill->dueDate = $data['duedate'] . 'T00:00:00';
                }
            }
            
            // Set item list (for inventory/non-inventory items)
            if (isset($data['items']) && is_array($data['items']) && count($data['items']) > 0) {
                $bill->itemList = new \VendorBillItemList();
                $bill->itemList->replaceAll = true;
                
                $billItems = [];
                $lineNumber = 1;
                
                foreach ($data['items'] as $itemData) {
                    if (!isset($itemData['item_id'])) {
                        Log::warning("Item missing item_id, skipping");
                        continue;
                    }
                    
                    $billItem = new \VendorBillItem();
                    $billItem->line = $lineNumber++;
                    $billItem->item = new \RecordRef();
                    $billItem->item->internalId = $itemData['item_id'];
                    
                    if (isset($itemData['quantity'])) {
                        $billItem->quantity = (float) $itemData['quantity'];
                    }
                    
                    if (isset($itemData['rate'])) {
                        $billItem->rate = (float) $itemData['rate'];
                    }
                    
                    if (isset($itemData['description'])) {
                        $billItem->description = $itemData['description'];
                    }
                    
                    if (isset($itemData['department_id'])) {
                        $dept = NetSuiteDepartment::where('netsuite_id', $itemData['department_id'])
                            ->where('is_sandbox', $isSandbox)
                            ->first();
                        if ($dept) {
                            $billItem->department = new \RecordRef();
                            $billItem->department->internalId = $dept->netsuite_id;
                        }
                    }
                    
                    if (isset($itemData['location_id'])) {
                        $loc = NetSuiteLocation::where('netsuite_id', $itemData['location_id'])
                            ->where('is_sandbox', $isSandbox)
                            ->first();
                        if ($loc) {
                            $billItem->location = new \RecordRef();
                            $billItem->location->internalId = $loc->netsuite_id;
                        }
                    }
                    
                    $billItems[] = $billItem;
                }
                
                if (count($billItems) > 0) {
                    $bill->itemList->item = $billItems;
                }
            }
            
            // Set expense list (for items that don't exist in NetSuite)
            if (isset($data['expenses']) && is_array($data['expenses']) && count($data['expenses']) > 0) {
                $bill->expenseList = new \VendorBillExpenseList();
                $bill->expenseList->replaceAll = true;
                
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
                    
                    $exp = new \VendorBillExpense();
                    $exp->line = $lineNumber++;
                    $exp->account = new \RecordRef();
                    $exp->account->internalId = $account->netsuite_id;
                    $exp->account->type = 'account';
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
                    $bill->expenseList->expense = $expenses;
                }
            }
            
            // Validate we have at least one line item (either items or expenses)
            if ((!isset($bill->itemList) || empty($bill->itemList->item)) &&
                (!isset($bill->expenseList) || empty($bill->expenseList->expense))) {
                throw new \Exception('At least one item or expense line is required');
            }
            
            // Build custom fields list
            $customFields = [];
            
            // Supervisor field (required)
            $supervisorId = $data['supervisor_id'] ?? '3467'; // Default to 3467
            $cf = new \SelectCustomFieldRef();
            $cf->scriptId = 'custbody_itg_supervisor';
            $cf->value = new \ListOrRecordRef();
            $cf->value->internalId = (string) $supervisorId;
            $customFields[] = $cf;
            
            // Reference No. appears to map to tranId (transaction ID) field
            // If tranId is not set, leave it blank to let NetSuite auto-generate the document number
            // Only set it if explicitly provided in the data
            if (isset($bill->tranId) && !empty($bill->tranId)) {
                Log::info("Using provided tranId (Reference No.)", ['tranId' => $bill->tranId]);
            } else {
                Log::info("Leaving tranId blank - NetSuite will auto-generate document number");
            }
            
            // Set custom field list if we have any fields
            if (!empty($customFields)) {
                $bill->customFieldList = new \CustomFieldList();
                $bill->customFieldList->customField = $customFields;
            }
            
            // Create the Bill
            $request = new \AddRequest();
            $request->record = $bill;
            
            $addResponse = $service->add($request);
            
            if (!$addResponse->writeResponse->status->isSuccess) {
                $errorMessage = 'Bill creation failed: ';
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
            
            // Get the created Bill to retrieve tranId
            $internalId = $addResponse->writeResponse->baseRef->internalId;
            $tranId = null;
            
            try {
                $getRequest = new \GetRequest();
                $getRequest->baseRef = new \RecordRef();
                $getRequest->baseRef->internalId = $internalId;
                $getRequest->baseRef->type = "vendorBill";
                
                $getResponse = $service->get($getRequest);
                if ($getResponse->readResponse->status->isSuccess && isset($getResponse->readResponse->record->tranId)) {
                    $tranId = $getResponse->readResponse->record->tranId;
                }
            } catch (\Exception $e) {
                Log::warning('Could not retrieve tranId for created Bill: ' . $e->getMessage());
                // Fallback to baseRef->name if available
                $tranId = $addResponse->writeResponse->baseRef->name ?? null;
            }
            
            return [
                'success' => true,
                'internal_id' => $internalId,
                'transaction_id' => $tranId,
            ];
            
        } catch (\SoapFault $e) {
            Log::error('Vendor Bill Creation SOAP Error: ' . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'netsuite_response' => json_encode([
                    'fault_code' => $e->faultcode ?? '',
                    'fault_string' => $e->faultstring ?? '',
                ]),
            ];
        } catch (\Exception $e) {
            Log::error('Vendor Bill Creation Error: ' . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'netsuite_response' => '',
            ];
        }
    }
}

