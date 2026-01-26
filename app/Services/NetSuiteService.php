<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

class NetSuiteService
{
    protected $service;

    public function __construct()
    {
        // Set NetSuite constants from config
        if (!defined('NS_ENDPOINT')) {
            define('NS_ENDPOINT', config('netsuite.endpoint'));
            define('NS_HOST', config('netsuite.host'));
            define('NS_ACCOUNT', config('netsuite.account'));
            define('NS_CONSUMER_KEY', config('netsuite.consumer_key'));
            define('NS_CONSUMER_SECRET', config('netsuite.consumer_secret'));
            define('NS_TOKEN', config('netsuite.token'));
            define('NS_TOKEN_SECRET', config('netsuite.token_secret'));
        }

        // Load NetSuite classes
        require_once app_path('NetSuite/NetSuiteService.php');
        
        $this->service = new \NetSuiteService();
    }

    /**
     * Get the underlying NetSuite SOAP service
     */
    public function getService()
    {
        return $this->service;
    }

    /**
     * Search for departments
     */
    public function searchDepartments()
    {
        try {
            $search = new \DepartmentSearchBasic();
            $request = new \SearchRequest();
            $request->searchRecord = $search;
            
            $this->service->setSearchPreferences(false, 1000, true);
            $response = $this->service->search($request);
            
            if (!$response->searchResult->status->isSuccess) {
                throw new \Exception('Department search failed: ' . $this->getStatusMessage($response->searchResult->status));
            }
            
            return $response->searchResult->recordList->record ?? [];
        } catch (\Exception $e) {
            Log::error('NetSuite Department Search Error: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Search for accounts
     */
    public function searchAccounts()
    {
        try {
            $search = new \AccountSearchBasic();
            $request = new \SearchRequest();
            $request->searchRecord = $search;
            
            $this->service->setSearchPreferences(false, 1000, true);
            $response = $this->service->search($request);
            
            if (!$response->searchResult->status->isSuccess) {
                throw new \Exception('Account search failed: ' . $this->getStatusMessage($response->searchResult->status));
            }
            
            return $response->searchResult->recordList->record ?? [];
        } catch (\Exception $e) {
            Log::error('NetSuite Account Search Error: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Search for locations
     */
    public function searchLocations()
    {
        try {
            $search = new \LocationSearchBasic();
            $request = new \SearchRequest();
            $request->searchRecord = $search;
            
            $this->service->setSearchPreferences(false, 1000, true);
            $response = $this->service->search($request);
            
            if (!$response->searchResult->status->isSuccess) {
                throw new \Exception('Location search failed: ' . $this->getStatusMessage($response->searchResult->status));
            }
            
            return $response->searchResult->recordList->record ?? [];
        } catch (\Exception $e) {
            Log::error('NetSuite Location Search Error: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Search for expense categories
     */
    public function searchExpenseCategories()
    {
        try {
            $search = new \ExpenseCategorySearchBasic();
            $request = new \SearchRequest();
            $request->searchRecord = $search;
            
            $this->service->setSearchPreferences(false, 1000, true);
            $response = $this->service->search($request);
            
            if (!$response->searchResult->status->isSuccess) {
                throw new \Exception('Expense Category search failed: ' . $this->getStatusMessage($response->searchResult->status));
            }
            
            return $response->searchResult->recordList->record ?? [];
        } catch (\Exception $e) {
            Log::error('NetSuite Expense Category Search Error: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Search for employees
     */
    public function searchEmployees()
    {
        try {
            $search = new \EmployeeSearchBasic();
            $request = new \SearchRequest();
            $request->searchRecord = $search;
            
            $this->service->setSearchPreferences(false, 1000, true);
            $response = $this->service->search($request);
            
            if (!$response->searchResult->status->isSuccess) {
                throw new \Exception('Employee search failed: ' . $this->getStatusMessage($response->searchResult->status));
            }
            
            return $response->searchResult->recordList->record ?? [];
        } catch (\Exception $e) {
            Log::error('NetSuite Employee Search Error: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Search for vendors with pagination support
     */
    public function searchVendors($pageSize = 500, $fetchAll = true)
    {
        try {
            $search = new \VendorSearchBasic();
            $request = new \SearchRequest();
            $request->searchRecord = $search;
            
            // Use smaller page size to avoid timeout issues
            $this->service->setSearchPreferences(false, $pageSize, true);
            
            $response = $this->service->search($request);
            
            if (!$response->searchResult->status->isSuccess) {
                throw new \Exception('Vendor search failed: ' . $this->getStatusMessage($response->searchResult->status));
            }
            
            $allVendors = [];
            $firstPage = $response->searchResult->recordList->record ?? [];
            if (!empty($firstPage)) {
                $allVendors = is_array($firstPage) ? $firstPage : [$firstPage];
            }
            
            $totalRecords = $response->searchResult->totalRecords ?? 0;
            $currentPageSize = count($allVendors);
            
            // If fetchAll is true and there are more records, paginate
            if ($fetchAll && $totalRecords > $currentPageSize) {
                $searchId = $response->searchResult->searchId;
                
                // Calculate how many more pages we need
                $pagesNeeded = ceil(($totalRecords - $currentPageSize) / $pageSize);
                
                for ($page = 1; $page <= $pagesNeeded; $page++) {
                    try {
                        $searchMoreRequest = new \SearchMoreWithIdRequest();
                        $searchMoreRequest->searchId = $searchId;
                        $searchMoreRequest->pageIndex = $page + 1; // pageIndex is 1-based, and we already have page 1
                        
                        $moreResponse = $this->service->searchMoreWithId($searchMoreRequest);
                        
                        if ($moreResponse->searchResult->status->isSuccess) {
                            $moreRecords = $moreResponse->searchResult->recordList->record ?? [];
                            if (!empty($moreRecords)) {
                                $moreRecords = is_array($moreRecords) ? $moreRecords : [$moreRecords];
                                $allVendors = array_merge($allVendors, $moreRecords);
                            }
                        } else {
                            Log::warning("Failed to fetch page " . ($page + 1) . " of vendor search");
                            break; // Stop pagination if a page fails
                        }
                        
                        // Small delay between pages to avoid rate limiting
                        usleep(500000); // 0.5 second
                    } catch (\Exception $e) {
                        Log::warning("Error fetching page " . ($page + 1) . ": " . $e->getMessage());
                        break; // Stop pagination on error
                    }
                }
            }
            
            return $allVendors;
        } catch (\SoapFault $e) {
            Log::error('NetSuite Vendor Search SOAP Error: ' . $e->getMessage());
            // Retry with smaller page size
            if ($pageSize > 100) {
                return $this->searchVendors(100, $fetchAll);
            }
            throw $e;
        } catch (\Exception $e) {
            Log::error('NetSuite Vendor Search Error: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Search for items with pagination support
     */
    public function searchItems($pageSize = 500, $fetchAll = true)
    {
        try {
            $search = new \ItemSearchBasic();
            $request = new \SearchRequest();
            $request->searchRecord = $search;
            
            // Use smaller page size to avoid timeout issues
            $this->service->setSearchPreferences(false, $pageSize, true);
            
            $response = $this->service->search($request);
            
            if (!$response->searchResult->status->isSuccess) {
                throw new \Exception('Item search failed: ' . $this->getStatusMessage($response->searchResult->status));
            }
            
            $allItems = [];
            $firstPage = $response->searchResult->recordList->record ?? [];
            if (!empty($firstPage)) {
                $allItems = is_array($firstPage) ? $firstPage : [$firstPage];
            }
            
            $totalRecords = $response->searchResult->totalRecords ?? 0;
            $currentPageSize = count($allItems);
            
            // If fetchAll is true and there are more records, paginate
            if ($fetchAll && $totalRecords > $currentPageSize) {
                $searchId = $response->searchResult->searchId;
                
                // Calculate how many more pages we need
                $pagesNeeded = ceil(($totalRecords - $currentPageSize) / $pageSize);
                
                for ($page = 1; $page <= $pagesNeeded; $page++) {
                    try {
                        $searchMoreRequest = new \SearchMoreWithIdRequest();
                        $searchMoreRequest->searchId = $searchId;
                        $searchMoreRequest->pageIndex = $page + 1; // pageIndex is 1-based, and we already have page 1
                        
                        $moreResponse = $this->service->searchMoreWithId($searchMoreRequest);
                        
                        if ($moreResponse->searchResult->status->isSuccess) {
                            $moreRecords = $moreResponse->searchResult->recordList->record ?? [];
                            if (!empty($moreRecords)) {
                                $moreRecords = is_array($moreRecords) ? $moreRecords : [$moreRecords];
                                $allItems = array_merge($allItems, $moreRecords);
                            }
                        }
                        
                        // Small delay between pages
                        usleep(200000); // 0.2 seconds
                    } catch (\Exception $e) {
                        Log::warning("Error fetching item page " . ($page + 1) . ": " . $e->getMessage());
                        // Continue with next page
                    }
                }
            }
            
            return $allItems;
        } catch (\SoapFault $e) {
            Log::error('NetSuite Item Search SOAP Error: ' . $e->getMessage());
            // Retry with smaller page size
            if ($pageSize > 100) {
                return $this->searchItems(100, $fetchAll);
            }
            throw $e;
        } catch (\Exception $e) {
            Log::error('NetSuite Item Search Error: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Search for currencies directly from NetSuite
     */
    public function searchCurrencies($pageSize = 1000, $fetchAll = true)
    {
        try {
            // Try to instantiate CurrencySearchBasic - it may be auto-loaded from WSDL
            // If the class doesn't exist, we'll catch the error
            if (!class_exists('CurrencySearchBasic', false)) {
                // Try to load it - it might be in the global namespace
                $className = 'CurrencySearchBasic';
            } else {
                $className = 'CurrencySearchBasic';
            }
            
            // Try creating the search class
            try {
                $search = new $className();
            } catch (\Error $e) {
                // If class doesn't exist, try with namespace
                if (class_exists('\CurrencySearchBasic')) {
                    $search = new \CurrencySearchBasic();
                } else {
                    throw new \Exception('CurrencySearchBasic class not available. Currency search may not be supported in this NetSuite version.');
                }
            }
            $request = new \SearchRequest();
            $request->searchRecord = $search;
            
            $this->service->setSearchPreferences(false, $pageSize, true);
            
            $response = $this->service->search($request);
            
            if (!$response->searchResult->status->isSuccess) {
                throw new \Exception('Currency search failed: ' . $this->getStatusMessage($response->searchResult->status));
            }
            
            $allCurrencies = [];
            $firstPage = $response->searchResult->recordList->record ?? [];
            if (!empty($firstPage)) {
                $allCurrencies = is_array($firstPage) ? $firstPage : [$firstPage];
            }
            
            $totalRecords = $response->searchResult->totalRecords ?? 0;
            $currentPageSize = count($allCurrencies);
            
            // If fetchAll is true and there are more records, paginate
            if ($fetchAll && $totalRecords > $currentPageSize) {
                $searchId = $response->searchResult->searchId;
                $pagesNeeded = ceil(($totalRecords - $currentPageSize) / $pageSize);
                
                for ($page = 1; $page <= $pagesNeeded; $page++) {
                    try {
                        $searchMoreRequest = new \SearchMoreWithIdRequest();
                        $searchMoreRequest->searchId = $searchId;
                        $searchMoreRequest->pageIndex = $page + 1;
                        
                        $moreResponse = $this->service->searchMoreWithId($searchMoreRequest);
                        
                        if ($moreResponse->searchResult->status->isSuccess) {
                            $moreRecords = $moreResponse->searchResult->recordList->record ?? [];
                            if (!empty($moreRecords)) {
                                $moreRecords = is_array($moreRecords) ? $moreRecords : [$moreRecords];
                                $allCurrencies = array_merge($allCurrencies, $moreRecords);
                            }
                        }
                        
                        usleep(200000); // 0.2 seconds delay
                    } catch (\Exception $e) {
                        Log::warning("Error fetching currency page " . ($page + 1) . ": " . $e->getMessage());
                    }
                }
            }
            
            return $allCurrencies;
        } catch (\SoapFault $e) {
            Log::error('NetSuite Currency Search SOAP Error: ' . $e->getMessage());
            throw $e;
        } catch (\Exception $e) {
            Log::error('NetSuite Currency Search Error: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Get vendor with currency information
     */
    public function getVendor($vendorId)
    {
        try {
            $getRequest = new \GetRequest();
            $getRequest->baseRef = new \RecordRef();
            $getRequest->baseRef->internalId = $vendorId;
            $getRequest->baseRef->type = "vendor";
            
            $getResponse = $this->service->get($getRequest);
            
            if (!$getResponse->readResponse->status->isSuccess) {
                throw new \Exception('Vendor get failed: ' . $this->getStatusMessage($getResponse->readResponse->status));
            }
            
            return $getResponse->readResponse->record;
        } catch (\Exception $e) {
            Log::error('NetSuite Get Vendor Error: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Check if a Purchase Order exists by Transaction ID (tranId)
     * 
     * @param string $tranId The Transaction ID (tranId) to search for
     * @return bool True if PO exists, false otherwise
     */
    public function purchaseOrderExistsByTranId($tranId)
    {
        try {
            if (empty($tranId)) {
                return false;
            }
            
            $search = new \TransactionSearchBasic();
            $search->type = new \SearchEnumMultiSelectField();
            $search->type->operator = "anyOf";
            $search->type->searchValue = array("_purchaseOrder");
            
            $search->tranId = new \SearchStringField();
            $search->tranId->operator = "is";
            $search->tranId->searchValue = $tranId;
            
            $request = new \SearchRequest();
            $request->searchRecord = $search;
            
            // Set search preferences: pageSize must be at least 10 for NetSuite
            $this->service->setSearchPreferences(false, 10, true);
            $response = $this->service->search($request);
            
            if (!$response->searchResult->status->isSuccess) {
                // If search fails, assume PO doesn't exist
                Log::warning('PO search failed for Transaction ID (tranId): ' . $tranId . ' - ' . $this->getStatusMessage($response->searchResult->status));
                return false;
            }
            
            return $response->searchResult->totalRecords > 0;
            
        } catch (\Exception $e) {
            Log::error('Error checking if PO exists by tranId: ' . $e->getMessage());
            // On error, assume PO doesn't exist to allow creation attempt
            return false;
        }
    }

    /**
     * Check if a Purchase Order exists by memo (which contains the sheet PO ID)
     * 
     * @param string $memo The memo value to search for
     * @return bool True if PO exists, false otherwise
     */
    public function purchaseOrderExistsByMemo($memo)
    {
        try {
            if (empty($memo)) {
                return false;
            }
            
            $search = new \TransactionSearchBasic();
            $search->type = new \SearchEnumMultiSelectField();
            $search->type->operator = "anyOf";
            $search->type->searchValue = array("_purchaseOrder");
            
            $search->memo = new \SearchStringField();
            $search->memo->operator = "is";
            $search->memo->searchValue = $memo;
            
            $request = new \SearchRequest();
            $request->searchRecord = $search;
            
            // Set search preferences: pageSize must be at least 10 for NetSuite
            $this->service->setSearchPreferences(false, 10, true);
            $response = $this->service->search($request);
            
            if (!$response->searchResult->status->isSuccess) {
                // If search fails, assume PO doesn't exist
                Log::warning('PO search failed for memo: ' . $memo . ' - ' . $this->getStatusMessage($response->searchResult->status));
                return false;
            }
            
            return $response->searchResult->totalRecords > 0;
            
        } catch (\Exception $e) {
            Log::error('Error checking if PO exists by memo: ' . $e->getMessage());
            // On error, assume PO doesn't exist to allow creation attempt
            return false;
        }
    }

    /**
     * Check if a Vendor Bill exists by Transaction ID (tranId)
     */
    public function vendorBillExistsByTranId($tranId): bool
    {
        try {
            if (empty($tranId)) {
                return false;
            }

            $search = new \TransactionSearchBasic();
            $search->type = new \SearchEnumMultiSelectField();
            $search->type->operator = "anyOf";
            $search->type->searchValue = ["_vendorBill"];

            $search->tranId = new \SearchStringField();
            $search->tranId->operator = "is";
            $search->tranId->searchValue = $tranId;

            $request = new \SearchRequest();
            $request->searchRecord = $search;

            $this->service->setSearchPreferences(false, 10, true);
            $response = $this->service->search($request);

            if (!$response->searchResult->status->isSuccess) {
                Log::warning('Vendor Bill search failed for tranId: ' . $tranId . ' - ' . $this->getStatusMessage($response->searchResult->status));
                return false;
            }

            return $response->searchResult->totalRecords > 0;
        } catch (\Exception $e) {
            Log::error('Error checking if Vendor Bill exists by tranId: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Check if a Vendor Bill exists by memo (used for sheet PR/ID matching)
     */
    public function vendorBillExistsByMemo($memo): bool
    {
        try {
            if (empty($memo)) {
                return false;
            }

            $search = new \TransactionSearchBasic();
            $search->type = new \SearchEnumMultiSelectField();
            $search->type->operator = "anyOf";
            $search->type->searchValue = ["_vendorBill"];

            $search->memo = new \SearchStringField();
            $search->memo->operator = "is";
            $search->memo->searchValue = $memo;

            $request = new \SearchRequest();
            $request->searchRecord = $search;

            $this->service->setSearchPreferences(false, 10, true);
            $response = $this->service->search($request);

            if (!$response->searchResult->status->isSuccess) {
                Log::warning('Vendor Bill search failed for memo: ' . $memo . ' - ' . $this->getStatusMessage($response->searchResult->status));
                return false;
            }

            return $response->searchResult->totalRecords > 0;
        } catch (\Exception $e) {
            Log::error('Error checking if Vendor Bill exists by memo: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Get Purchase Order by Transaction ID (tranId)
     * 
     * @param string $tranId The Transaction ID (tranId) to search for
     * @return object|null The Purchase Order record, or null if not found
     */
    public function getPurchaseOrderByTranId($tranId)
    {
        try {
            if (empty($tranId)) {
                Log::warning('getPurchaseOrderByTranId called with empty tranId');
                return null;
            }

            Log::info("Searching for PO with tranId: '{$tranId}'");

            // Search for PO by tranId
            $search = new \TransactionSearchBasic();
            $search->type = new \SearchEnumMultiSelectField();
            $search->type->operator = "anyOf";
            $search->type->searchValue = array("_purchaseOrder");

            $search->tranId = new \SearchStringField();
            $search->tranId->operator = "is";
            $search->tranId->searchValue = $tranId;

            $request = new \SearchRequest();
            $request->searchRecord = $search;

            $this->service->setSearchPreferences(false, 10, true);
            $response = $this->service->search($request);

            if (!$response->searchResult->status->isSuccess) {
                $errorMsg = $this->getStatusMessage($response->searchResult->status);
                Log::warning("PO search for tranId '{$tranId}' failed: {$errorMsg}");
                return null;
            }

            if ($response->searchResult->totalRecords == 0) {
                Log::info("PO search for tranId '{$tranId}' returned 0 records");
                return null;
            }

            Log::info("Found {$response->searchResult->totalRecords} PO(s) with tranId '{$tranId}'");

            // Get the first result
            $po = $response->searchResult->recordList->record;
            if (is_array($po)) {
                $po = $po[0];
            }

            $internalId = $po->internalId;
            Log::info("PO tranId '{$tranId}' has internalId: {$internalId}");

            return $this->getPurchaseOrderByInternalId($internalId);

        } catch (\Exception $e) {
            Log::error('Error getting PO by tranId: ' . $e->getMessage());
            Log::error('Stack trace: ' . $e->getTraceAsString());
            return null;
        }
    }

    /**
     * Get Purchase Order by internal ID
     *
     * @param string|int $internalId
     * @return object|null
     */
    public function getPurchaseOrderByInternalId($internalId)
    {
        try {
            if (empty($internalId)) {
                return null;
            }

            $getRequest = new \GetRequest();
            $getRequest->baseRef = new \RecordRef();
            $getRequest->baseRef->internalId = (string) $internalId;
            $getRequest->baseRef->type = "purchaseOrder";

            $getResponse = $this->service->get($getRequest);

            if (!$getResponse->readResponse->status->isSuccess) {
                Log::error('Failed to get PO details by internalId ' . $internalId . ': ' . $this->getStatusMessage($getResponse->readResponse->status));
                return null;
            }

            return $getResponse->readResponse->record;
        } catch (\Exception $e) {
            Log::error('Error getting PO by internalId: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Extract status message from NetSuite response
     */
    protected function getStatusMessage($status)
    {
        $messages = [];
        if (isset($status->statusDetail)) {
            $details = is_array($status->statusDetail) ? $status->statusDetail : [$status->statusDetail];
            foreach ($details as $detail) {
                $messages[] = $detail->message ?? 'Unknown error';
            }
        }
        return implode('; ', $messages) ?: 'Unknown error';
    }

    /**
     * Create a new vendor in NetSuite
     *
     * @param array $vendorData Vendor data
     *   - company_name (required): Vendor company name
     *   - entity_id (optional): Vendor code/number. If not provided, NetSuite will auto-generate
     *   - email (optional): Email address
     *   - phone (optional): Phone number
     *   - is_inactive (optional): Whether vendor is inactive
     *   - currency_id (optional): Currency internal ID
     *   - category_id (optional): Vendor category internal ID
     *   - And other custom fields...
     * @return array Result with success status, internal_id, entity_id, and error message
     */
    public function createVendor(array $vendorData)
    {
        try {
            $vendor = new \Vendor();

            // Required fields
            if (isset($vendorData['company_name'])) {
                $vendor->companyName = $vendorData['company_name'];
            }

            // Entity ID (vendor code/number) - optional, NetSuite will auto-generate if not provided
            if (isset($vendorData['entity_id']) && !empty($vendorData['entity_id'])) {
                $vendor->entityId = $vendorData['entity_id'];
            }

            // Email
            if (isset($vendorData['email'])) {
                $vendor->email = $vendorData['email'];
            }

            // Phone
            if (isset($vendorData['phone'])) {
                $vendor->phone = $vendorData['phone'];
            }

            // Is Inactive
            if (isset($vendorData['is_inactive'])) {
                $vendor->isInactive = (bool) $vendorData['is_inactive'];
            }

            // Currency
            if (isset($vendorData['currency_id'])) {
                $currencyRef = new \RecordRef();
                $currencyRef->internalId = (string) $vendorData['currency_id'];
                $currencyRef->type = 'currency';
                $vendor->currency = $currencyRef;
            }

            // Category (Vendor Category in NetSuite)
            if (isset($vendorData['category_id'])) {
                $categoryRef = new \RecordRef();
                $categoryRef->internalId = (string) $vendorData['category_id'];
                $categoryRef->type = 'vendorCategory';
                $vendor->category = $categoryRef;
            }

            // Custom fields for ASSA-specific fields
            $customFieldList = [];

            // Category of Suppliers (custom field)
            if (isset($vendorData['category_of_suppliers'])) {
                $customField = new \SelectCustomFieldRef();
                $customField->scriptId = 'custentity_assa_category_of_suppliers';
                $customField->value = new \ListOrRecordRef();
                $customField->value->internalId = (string) $vendorData['category_of_suppliers'];
                $customFieldList[] = $customField;
            }

            // Nature of Business (custom field)
            if (isset($vendorData['nature_of_business'])) {
                $customField = new \SelectCustomFieldRef();
                $customField->scriptId = 'custentity_assa_nature_of_business';
                $customField->value = new \ListOrRecordRef();
                $customField->value->internalId = (string) $vendorData['nature_of_business'];
                $customFieldList[] = $customField;
            }

            // TIN Number (custom field)
            if (isset($vendorData['tin_number'])) {
                $customField = new \StringCustomFieldRef();
                $customField->scriptId = 'custentity_assa_tin_number';
                $customField->value = (string) $vendorData['tin_number'];
                $customFieldList[] = $customField;
            }

            // SST Number (custom field)
            if (isset($vendorData['sst_number'])) {
                $customField = new \StringCustomFieldRef();
                $customField->scriptId = 'custentity_assa_sst_number';
                $customField->value = (string) $vendorData['sst_number'];
                $customFieldList[] = $customField;
            }

            // Tourism Tax (custom field)
            if (isset($vendorData['tourism_tax'])) {
                $customField = new \StringCustomFieldRef();
                $customField->scriptId = 'custentity_assa_tourism_tax';
                $customField->value = (string) $vendorData['tourism_tax'];
                $customFieldList[] = $customField;
            }

            // MSIC Code (custom field)
            if (isset($vendorData['msic_code'])) {
                $customField = new \SelectCustomFieldRef();
                $customField->scriptId = 'custentity_assa_msic_code';
                $customField->value = new \ListOrRecordRef();
                $customField->value->internalId = (string) $vendorData['msic_code'];
                $customFieldList[] = $customField;
            }

            // E-Invoicing fields
            if (isset($vendorData['einv_tin_no'])) {
                $customField = new \StringCustomFieldRef();
                $customField->scriptId = 'custentity_einv_tin_no';
                $customField->value = (string) $vendorData['einv_tin_no'];
                $customFieldList[] = $customField;
            }

            if (isset($vendorData['einv_registered_name'])) {
                $customField = new \StringCustomFieldRef();
                $customField->scriptId = 'custentity_einv_registered_name';
                $customField->value = (string) $vendorData['einv_registered_name'];
                $customFieldList[] = $customField;
            }

            if (isset($vendorData['einv_sst_register_no'])) {
                $customField = new \StringCustomFieldRef();
                $customField->scriptId = 'custentity_einv_sst_register_no';
                $customField->value = (string) $vendorData['einv_sst_register_no'];
                $customFieldList[] = $customField;
            }

            if (isset($vendorData['einv_msic_code'])) {
                $customField = new \StringCustomFieldRef();
                $customField->scriptId = 'custentity_einv_msic_code';
                $customField->value = (string) $vendorData['einv_msic_code'];
                $customFieldList[] = $customField;
            }

            if (isset($vendorData['einv_address_line1'])) {
                $customField = new \StringCustomFieldRef();
                $customField->scriptId = 'custentity_einv_address_line1';
                $customField->value = (string) $vendorData['einv_address_line1'];
                $customFieldList[] = $customField;
            }

            if (isset($vendorData['einv_city_name'])) {
                $customField = new \StringCustomFieldRef();
                $customField->scriptId = 'custentity_einv_city_name';
                $customField->value = (string) $vendorData['einv_city_name'];
                $customFieldList[] = $customField;
            }

            if (isset($vendorData['einv_country_code'])) {
                $customField = new \StringCustomFieldRef();
                $customField->scriptId = 'custentity_einv_country_code';
                $customField->value = (string) $vendorData['einv_country_code'];
                $customFieldList[] = $customField;
            }

            if (isset($vendorData['einv_identification_code'])) {
                $customField = new \StringCustomFieldRef();
                $customField->scriptId = 'custentity_einv_identification_code';
                $customField->value = (string) $vendorData['einv_identification_code'];
                $customFieldList[] = $customField;
            }

            if (isset($vendorData['einv_identification_type'])) {
                $customField = new \StringCustomFieldRef();
                $customField->scriptId = 'custentity_einv_identification_type';
                $customField->value = (string) $vendorData['einv_identification_type'];
                $customFieldList[] = $customField;
            }

            if (isset($vendorData['einv_state_code'])) {
                $customField = new \StringCustomFieldRef();
                $customField->scriptId = 'custentity_einv_state_code';
                $customField->value = (string) $vendorData['einv_state_code'];
                $customFieldList[] = $customField;
            }

            // Add custom fields if any
            if (!empty($customFieldList)) {
                $vendor->customFieldList = new \CustomFieldList();
                $vendor->customFieldList->customField = $customFieldList;
            }

            // Address Book (if address details provided)
            if (isset($vendorData['address_1']) || isset($vendorData['city']) || isset($vendorData['country'])) {
                $addressBook = new \VendorAddressbook();
                $addressBook->defaultBilling = true;
                $addressBook->defaultShipping = true;

                $addressBookAddress = new \Address();

                if (isset($vendorData['address_1'])) {
                    $addressBookAddress->addr1 = $vendorData['address_1'];
                }
                if (isset($vendorData['address_2'])) {
                    $addressBookAddress->addr2 = $vendorData['address_2'];
                }
                if (isset($vendorData['city'])) {
                    $addressBookAddress->city = $vendorData['city'];
                }
                if (isset($vendorData['state'])) {
                    $addressBookAddress->state = $vendorData['state'];
                }
                if (isset($vendorData['zip'])) {
                    $addressBookAddress->zip = $vendorData['zip'];
                }
                if (isset($vendorData['country'])) {
                    $addressBookAddress->country = $vendorData['country'];
                }

                $addressBook->addressbookAddress = $addressBookAddress;

                $vendor->addressbookList = new \VendorAddressbookList();
                $vendor->addressbookList->addressbook = [$addressBook];
            }

            // Create vendor
            $addRequest = new \AddRequest();
            $addRequest->record = $vendor;

            $addResponse = $this->service->add($addRequest);

            if (!$addResponse->writeResponse->status->isSuccess) {
                $errorMsg = $this->getStatusMessage($addResponse->writeResponse->status);
                Log::error('Vendor creation failed: ' . $errorMsg);

                return [
                    'success' => false,
                    'error' => $errorMsg,
                    'netsuite_response' => json_encode($addResponse->writeResponse->status)
                ];
            }

            $internalId = $addResponse->writeResponse->baseRef->internalId;

            // Get the created vendor to retrieve the auto-generated entity ID
            $createdVendor = $this->getVendor($internalId);
            $entityId = $createdVendor->entityId ?? null;

            Log::info("Vendor created successfully with internal ID: {$internalId}" . ($entityId ? ", entity ID: {$entityId}" : ""));

            return [
                'success' => true,
                'internal_id' => $internalId,
                'entity_id' => $entityId
            ];

        } catch (\Exception $e) {
            Log::error('NetSuite Create Vendor Error: ' . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'netsuite_response' => ''
            ];
        }
    }

    /**
     * Check if vendor exists by entity ID (vendor code)
     *
     * @param string $entityId The vendor entity ID
     * @return bool True if vendor exists
     */
    public function vendorExistsByEntityId($entityId)
    {
        try {
            if (empty($entityId)) {
                return false;
            }

            $search = new \VendorSearchBasic();
            $search->entityId = new \SearchStringField();
            $search->entityId->operator = "is";
            $search->entityId->searchValue = $entityId;

            $request = new \SearchRequest();
            $request->searchRecord = $search;

            $this->service->setSearchPreferences(false, 10, true);
            $response = $this->service->search($request);

            if (!$response->searchResult->status->isSuccess || $response->searchResult->totalRecords == 0) {
                return false;
            }

            return true;
        } catch (\Exception $e) {
            Log::error('Error checking vendor existence: ' . $e->getMessage());
            return false;
        }
    }
}

