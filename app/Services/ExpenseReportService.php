<?php

namespace App\Services;

use App\Models\NetSuiteAccount;
use App\Models\NetSuiteCurrency;
use App\Models\NetSuiteDepartment;
use App\Models\NetSuiteLocation;
use Illuminate\Support\Facades\Log;

class ExpenseReportService
{
    protected $netSuiteService;

    public function __construct(NetSuiteService $netSuiteService)
    {
        $this->netSuiteService = $netSuiteService;
    }

    /**
     * Create Expense Report from array data
     */
    public function createFromArray(array $data)
    {
        try {
            $service = $this->netSuiteService->getService();
            $isSandbox = config('netsuite.environment') === 'sandbox';
            
            // Create ExpenseReport object
            $expenseReport = new \ExpenseReport();
            
            // Set entity (employee) - REQUIRED
            if (!isset($data['employee_id'])) {
                throw new \Exception('Employee ID is required');
            }
            
            $expenseReport->entity = new \RecordRef();
            $expenseReport->entity->internalId = $data['employee_id'];
            $expenseReport->entity->type = 'employee';
            
            // Set memo
            if (isset($data['memo'])) {
                $expenseReport->memo = $data['memo'];
            }
            
            // Set transaction ID (tranId)
            if (isset($data['tran_id']) && !empty($data['tran_id'])) {
                $expenseReport->tranId = $data['tran_id'];
            } elseif (isset($data['reference_no']) && !empty($data['reference_no'])) {
                $expenseReport->tranId = $data['reference_no'];
            }
            
            // Set currency (REQUIRED for ExpenseReport)
            if (!isset($data['currency_id'])) {
                throw new \Exception('Currency ID is required for Expense Report');
            }
            
            $currency = NetSuiteCurrency::where('netsuite_id', $data['currency_id'])
                ->where('is_sandbox', $isSandbox)
                ->first();
            
            if (!$currency) {
                throw new \Exception("Currency with ID {$data['currency_id']} not found");
            }
            
            // Set currency on header (type is not required, can be empty)
            $expenseReport->expenseReportCurrency = new \RecordRef();
            $expenseReport->expenseReportCurrency->internalId = $currency->netsuite_id;
            // Note: type is not required for currency on ExpenseReport (can be empty/null)
            
            // Set transaction date
            // NetSuite requires dates with time component (ISO 8601 format)
            if (isset($data['trandate'])) {
                // If already in ISO format, use as-is, otherwise convert
                if (strpos($data['trandate'], 'T') !== false) {
                    $expenseReport->tranDate = $data['trandate'];
                } else {
                    // Convert YYYY-MM-DD to YYYY-MM-DDTHH:MM:SS
                    $expenseReport->tranDate = $data['trandate'] . 'T00:00:00';
                }
            } else {
                // Default to today with time component
                $expenseReport->tranDate = date('Y-m-d\TH:i:s');
            }
            
            // Set due date (optional)
            if (isset($data['duedate'])) {
                // If already in ISO format, use as-is, otherwise convert
                if (strpos($data['duedate'], 'T') !== false) {
                    $expenseReport->dueDate = $data['duedate'];
                } else {
                    // Convert YYYY-MM-DD to YYYY-MM-DDTHH:MM:SS
                    $expenseReport->dueDate = $data['duedate'] . 'T00:00:00';
                }
            }
            
            // Note: Location and Department cannot be set at ExpenseReport header level during creation
            // They must be set on individual expense lines instead
            
            // Set expense list - REQUIRED
            if (!isset($data['expenses']) || !is_array($data['expenses']) || count($data['expenses']) === 0) {
                throw new \Exception('At least one expense line is required');
            }
            
            $expenseReport->expenseList = new \ExpenseReportExpenseList();
            $expenseReport->expenseList->replaceAll = true;
            
            $expenses = [];
            $lineNumber = 1;
            
            foreach ($data['expenses'] as $expenseData) {
                if (!isset($expenseData['category_id']) || !isset($expenseData['amount']) || !isset($expenseData['expense_date'])) {
                    Log::warning("Expense missing required fields (category_id, amount, expense_date), skipping");
                    continue;
                }
                
                $exp = new \ExpenseReportExpense();
                $exp->line = $lineNumber++;
                
                // Set category (required)
                $exp->category = new \RecordRef();
                $exp->category->internalId = $expenseData['category_id'];
                $exp->category->type = 'expenseCategory';
                
                // Set amount (required)
                $exp->amount = (float) $expenseData['amount'];
                
                // Set expense date (required)
                $expenseDate = $expenseData['expense_date'];
                if (strpos($expenseDate, 'T') !== false) {
                    $exp->expenseDate = $expenseDate;
                } else {
                    // Convert YYYY-MM-DD to YYYY-MM-DDTHH:MM:SS
                    $exp->expenseDate = $expenseDate . 'T00:00:00';
                }
                
                // Set expense account (optional but commonly used)
                if (isset($expenseData['expense_account_id'])) {
                    $account = NetSuiteAccount::where('netsuite_id', $expenseData['expense_account_id'])
                        ->where('is_sandbox', $isSandbox)
                        ->first();
                    
                    if ($account) {
                        $exp->expenseAccount = new \RecordRef();
                        $exp->expenseAccount->internalId = $account->netsuite_id;
                        $exp->expenseAccount->type = 'account';
                    }
                }
                
                // Set memo
                if (isset($expenseData['memo'])) {
                    $exp->memo = $expenseData['memo'];
                }
                
                // Set department (optional)
                if (isset($expenseData['department_id'])) {
                    $dept = NetSuiteDepartment::where('netsuite_id', $expenseData['department_id'])
                        ->where('is_sandbox', $isSandbox)
                        ->first();
                    if ($dept) {
                        $exp->department = new \RecordRef();
                        $exp->department->internalId = $dept->netsuite_id;
                    }
                }
                
                // Set location (optional)
                if (isset($expenseData['location_id'])) {
                    $loc = NetSuiteLocation::where('netsuite_id', $expenseData['location_id'])
                        ->where('is_sandbox', $isSandbox)
                        ->first();
                    if ($loc) {
                        $exp->location = new \RecordRef();
                        $exp->location->internalId = $loc->netsuite_id;
                    }
                }
                
                // Set tax code (optional)
                if (isset($expenseData['tax_code_id'])) {
                    $exp->taxCode = new \RecordRef();
                    $exp->taxCode->internalId = $expenseData['tax_code_id'];
                }
                
                // Set quantity and rate (optional, alternative to amount)
                if (isset($expenseData['quantity'])) {
                    $exp->quantity = (float) $expenseData['quantity'];
                }
                
                if (isset($expenseData['rate'])) {
                    $exp->rate = (float) $expenseData['rate'];
                }
                
                // Set customer (optional, for billable expenses)
                if (isset($expenseData['customer_id'])) {
                    $exp->customer = new \RecordRef();
                    $exp->customer->internalId = $expenseData['customer_id'];
                    $exp->customer->type = 'customer';
                }
                
                // Set isBillable (optional)
                if (isset($expenseData['is_billable'])) {
                    $exp->isBillable = (bool) $expenseData['is_billable'];
                }
                
                // Set currency on expense line (required - matches header currency)
                // Use the same currency as the header
                if (isset($data['currency_id'])) {
                    $exp->currency = new \RecordRef();
                    $exp->currency->internalId = $data['currency_id'];
                    // Note: type is not required for currency on expense lines (can be empty/null)
                }
                
                $expenses[] = $exp;
            }
            
            if (count($expenses) === 0) {
                throw new \Exception('No valid expense lines found');
            }
            
            $expenseReport->expenseList->expense = $expenses;
            
            // Set transaction ID if not already set
            if (!isset($expenseReport->tranId) || empty($expenseReport->tranId)) {
                $dummyTranId = $data['tran_id'] ?? $data['reference_no'] ?? 'DUMMY-EXP-' . date('YmdHis');
                $expenseReport->tranId = (string) $dummyTranId;
                Log::info("Setting tranId for Expense Report", ['tranId' => $expenseReport->tranId]);
            }
            
            // Build custom fields list
            $customFields = [];
            
            // Supervisor field (required) - always 3467
            $supervisorId = $data['supervisor_id'] ?? '3467';
            $cf = new \SelectCustomFieldRef();
            $cf->scriptId = 'custbody_itg_supervisor';
            $cf->value = new \ListOrRecordRef();
            $cf->value->internalId = (string) $supervisorId;
            $customFields[] = $cf;
            
            // Payment Request Reference field (required) - based on custbody_assa_pr_reference
            // Set to a dummy value if not provided
            $prReference = $data['payment_request_reference'] ?? $data['pr_reference'] ?? 'DUMMY-PR-' . date('YmdHis');
            $cfPr = new \StringCustomFieldRef();
            $cfPr->scriptId = 'custbody_assa_pr_reference';
            $cfPr->value = (string) $prReference;
            $customFields[] = $cfPr;
            
            // Set custom field list if we have any fields
            if (!empty($customFields)) {
                $expenseReport->customFieldList = new \CustomFieldList();
                $expenseReport->customFieldList->customField = $customFields;
            }
            
            // Create the Expense Report
            $request = new \AddRequest();
            $request->record = $expenseReport;
            
            $addResponse = $service->add($request);
            
            if (!$addResponse->writeResponse->status->isSuccess) {
                $errorMessage = 'Expense Report creation failed: ';
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
            
            // Get the created Expense Report to retrieve tranId
            $internalId = $addResponse->writeResponse->baseRef->internalId;
            $tranId = null;
            
            try {
                $getRequest = new \GetRequest();
                $getRequest->baseRef = new \RecordRef();
                $getRequest->baseRef->internalId = $internalId;
                $getRequest->baseRef->type = "expenseReport";
                
                $getResponse = $service->get($getRequest);
                if ($getResponse->readResponse->status->isSuccess && isset($getResponse->readResponse->record->tranId)) {
                    $tranId = $getResponse->readResponse->record->tranId;
                }
            } catch (\Exception $e) {
                Log::warning('Could not retrieve tranId for created Expense Report: ' . $e->getMessage());
                // Fallback to baseRef->name if available
                $tranId = $addResponse->writeResponse->baseRef->name ?? null;
            }
            
            return [
                'success' => true,
                'internal_id' => $internalId,
                'transaction_id' => $tranId,
            ];
            
        } catch (\SoapFault $e) {
            Log::error('Expense Report Creation SOAP Error: ' . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'netsuite_response' => json_encode([
                    'fault_code' => $e->faultcode ?? '',
                    'fault_string' => $e->faultstring ?? '',
                ]),
            ];
        } catch (\Exception $e) {
            Log::error('Expense Report Creation Error: ' . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'netsuite_response' => '',
            ];
        }
    }
}

