<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class KissflowService
{
    protected $baseUrl;
    protected $accessKeyId;
    protected $accessKeySecret;

    public function __construct()
    {
        $this->baseUrl = env('KISSFLOW_BASE_URL', 'https://alice-smith.kissflow.com');
        $this->accessKeyId = env('KISSFLOW_ACCESS_KEY_ID');
        $this->accessKeySecret = env('KISSFLOW_ACCESS_KEY_SECRET');
        
        if (empty($this->accessKeyId) || empty($this->accessKeySecret)) {
            throw new \Exception('Kissflow credentials not configured. Set KISSFLOW_ACCESS_KEY_ID and KISSFLOW_ACCESS_KEY_SECRET in .env');
        }
    }

    /**
     * Search for items in Kissflow by PO Number
     * 
     * @param string $poNumber The PO Number to search for
     * @return array|null Returns the first matching item or null if not found
     */
    public function searchByPONumber($poNumber)
    {
        try {
            $url = $this->baseUrl . '/process/2/AcflcLIlo4aq/admin/Electronic_Payment_Requisition_EPR_/item';
            
            $response = Http::withHeaders([
                'X-Access-Key-Id' => $this->accessKeyId,
                'X-Access-Key-Secret' => $this->accessKeySecret,
            ])->get($url, [
                'page_number' => 1,
                'page_size' => 1,
                'q' => $poNumber,
                'search_field' => 'PO_Number'
            ]);

            if (!$response->successful()) {
                Log::error('Kissflow API Error', [
                    'po_number' => $poNumber,
                    'status' => $response->status(),
                    'response' => $response->body()
                ]);
                throw new \Exception('Kissflow API request failed: HTTP ' . $response->status() . ' - ' . $response->body());
            }

            $data = $response->json();
            
            // Check if we have results - response structure: { "Data": [ { "_id": "value" } ] }
            // Try "Data" (capital D) first, then fallback to "data" (lowercase) for compatibility
            $dataArray = $data['Data'] ?? $data['data'] ?? null;
            
            if (isset($dataArray) && is_array($dataArray) && count($dataArray) > 0) {
                $item = $dataArray[0];
                // Extract the Kissflow ID from _id field
                $kissflowId = $item['_id'] ?? $item['id'] ?? null;
                
                if ($kissflowId) {
                    return [
                        'id' => $kissflowId,
                        'data' => $item
                    ];
                }
            }
            
            return null;
            
        } catch (\Exception $e) {
            Log::error('Kissflow Search Error: ' . $e->getMessage(), [
                'po_number' => $poNumber
            ]);
            throw $e;
        }
    }

    /**
     * Get Kissflow ID for a PO Number
     * 
     * @param string $poNumber The PO Number to search for
     * @return string|null Returns the Kissflow ID or null if not found
     */
    public function getKissflowId($poNumber)
    {
        $result = $this->searchByPONumber($poNumber);
        return $result ? $result['id'] : null;
    }

    /**
     * Search for items in Kissflow by EPR Number (ePR_Form_Number)
     * 
     * @param string $eprNumber The ePR_Form_Number to search for
     * @return array|null Returns the first matching item or null if not found
     */
    public function searchByEPRNumber($eprNumber)
    {
        try {
            $url = $this->baseUrl . '/process/2/AcflcLIlo4aq/admin/Electronic_Payment_Requisition_EPR_/item';
            
            $response = Http::withHeaders([
                'X-Access-Key-Id' => $this->accessKeyId,
                'X-Access-Key-Secret' => $this->accessKeySecret,
            ])->get($url, [
                'page_number' => 1,
                'page_size' => 1,
                'q' => $eprNumber,
                'search_field' => 'ePR_Form_Number'
            ]);

            if (!$response->successful()) {
                Log::error('Kissflow API Error (EPR Search)', [
                    'epr_number' => $eprNumber,
                    'status' => $response->status(),
                    'response' => $response->body()
                ]);
                throw new \Exception('Kissflow API request failed: HTTP ' . $response->status() . ' - ' . $response->body());
            }

            $data = $response->json();
            
            // Check if we have results - response structure: { "Data": [ { "_id": "value" } ] }
            // Try "Data" (capital D) first, then fallback to "data" (lowercase) for compatibility
            $dataArray = $data['Data'] ?? $data['data'] ?? null;
            
            if (isset($dataArray) && is_array($dataArray) && count($dataArray) > 0) {
                $item = $dataArray[0];
                // Extract the Kissflow ID from _id field
                $kissflowId = $item['_id'] ?? $item['id'] ?? null;
                
                if ($kissflowId) {
                    return [
                        'id' => $kissflowId,
                        'data' => $item
                    ];
                }
            }
            
            return null;
            
        } catch (\Exception $e) {
            Log::error('Kissflow Search Error (EPR): ' . $e->getMessage(), [
                'epr_number' => $eprNumber
            ]);
            throw $e;
        }
    }

    /**
     * Search for Payment Request in Kissflow by PR Number (PAY_Form_Number)
     * 
     * @param string $prNumber The PAY_Form_Number to search for
     * @return array|null Returns the first matching item or null if not found
     */
    public function searchPaymentRequestByPRNumber($prNumber)
    {
        try {
            $url = $this->baseUrl . '/process/2/AcflcLIlo4aq/admin/Payment_Request/item';
            
            $response = Http::withHeaders([
                'X-Access-Key-Id' => $this->accessKeyId,
                'X-Access-Key-Secret' => $this->accessKeySecret,
            ])->get($url, [
                'page_number' => 1,
                'page_size' => 1,
                'q' => $prNumber,
                'search_field' => 'PAY_Form_Number'
            ]);

            if (!$response->successful()) {
                Log::error('Kissflow API Error (PR Search)', [
                    'pr_number' => $prNumber,
                    'status' => $response->status(),
                    'response' => $response->body()
                ]);
                throw new \Exception('Kissflow API request failed: HTTP ' . $response->status() . ' - ' . $response->body());
            }

            $data = $response->json();
            
            // Check if we have results - response structure: { "Data": [ { "_id": "value" } ] }
            $dataArray = $data['Data'] ?? $data['data'] ?? null;
            
            if (isset($dataArray) && is_array($dataArray) && count($dataArray) > 0) {
                $item = $dataArray[0];
                // Extract the Kissflow ID from _id field
                $kissflowId = $item['_id'] ?? $item['id'] ?? null;
                
                if ($kissflowId) {
                    return [
                        'id' => $kissflowId,
                        'data' => $item
                    ];
                }
            }
            
            return null;
            
        } catch (\Exception $e) {
            Log::error('Kissflow Search Error (PR): ' . $e->getMessage(), [
                'pr_number' => $prNumber
            ]);
            throw $e;
        }
    }

    /**
     * Search for items in Kissflow by PR Number (PAY_Form_Number)
     * 
     * @param string $prNumber The PAY_Form_Number to search for
     * @return array|null Returns the first matching item or null if not found
     */
    public function searchByPRNumber($prNumber)
    {
        try {
            $url = $this->baseUrl . '/process/2/AcflcLIlo4aq/admin/Payment_Request/item';
            
            $response = Http::withHeaders([
                'X-Access-Key-Id' => $this->accessKeyId,
                'X-Access-Key-Secret' => $this->accessKeySecret,
            ])->get($url, [
                'page_number' => 1,
                'page_size' => 1,
                'q' => $prNumber,
                'search_field' => 'PAY_Form_Number'
            ]);

            if (!$response->successful()) {
                Log::error('Kissflow API Error (PR Search)', [
                    'pr_number' => $prNumber,
                    'status' => $response->status(),
                    'response' => $response->body()
                ]);
                throw new \Exception('Kissflow API request failed: HTTP ' . $response->status() . ' - ' . $response->body());
            }

            $data = $response->json();
            
            // Check if we have results - response structure: { "Data": [ { "_id": "value" } ] }
            // Try "Data" (capital D) first, then fallback to "data" (lowercase) for compatibility
            $dataArray = $data['Data'] ?? $data['data'] ?? null;
            
            if (isset($dataArray) && is_array($dataArray) && count($dataArray) > 0) {
                $item = $dataArray[0];
                // Extract the Kissflow ID from _id field
                $kissflowId = $item['_id'] ?? $item['id'] ?? null;
                
                if ($kissflowId) {
                    return [
                        'id' => $kissflowId,
                        'data' => $item
                    ];
                }
            }
            
            return null;
            
        } catch (\Exception $e) {
            Log::error('Kissflow Search Error (PR): ' . $e->getMessage(), [
                'pr_number' => $prNumber
            ]);
            throw $e;
        }
    }

    /**
     * Get full item data from Kissflow by ID (for EPR)
     *
     * @param string $kissflowId The Kissflow ID
     * @return array|null Returns the full item data or null if not found
     */
    public function getItemById($kissflowId)
    {
        try {
            $url = $this->baseUrl . '/process/2/AcflcLIlo4aq/admin/Electronic_Payment_Requisition_EPR_/' . $kissflowId;

            $response = Http::withHeaders([
                'X-Access-Key-Id' => $this->accessKeyId,
                'X-Access-Key-Secret' => $this->accessKeySecret,
            ])->get($url);

            if (!$response->successful()) {
                $errorBody = $response->body();
                Log::error('Kissflow API Error', [
                    'kissflow_id' => $kissflowId,
                    'url' => $url,
                    'status' => $response->status(),
                    'response' => $errorBody
                ]);
                throw new \Exception('Kissflow API request failed: HTTP ' . $response->status() . ' - ' . substr($errorBody, 0, 500));
            }

            // Log successful response for debugging
            Log::info('Kissflow API Success', [
                'kissflow_id' => $kissflowId,
                'url' => $url,
                'status' => $response->status(),
                'content_type' => $response->header('Content-Type')
            ]);

            $responseBody = $response->body();
            $data = $response->json();

            // Check if JSON parsing failed
            if ($data === null && !empty($responseBody)) {
                Log::error('Kissflow API - Invalid JSON Response', [
                    'kissflow_id' => $kissflowId,
                    'response_body' => substr($responseBody, 0, 1000)
                ]);
                throw new \Exception('Invalid JSON response from Kissflow API');
            }

            // Log the response structure for debugging
            $allKeys = [];
            if (is_array($data)) {
                $allKeys = array_keys($data);
                // Also check for Table:: keys
                foreach ($allKeys as $key) {
                    if (strpos($key, 'Table::') === 0) {
                        Log::info('Kissflow Table Key Found', [
                            'key' => $key,
                            'type' => gettype($data[$key]),
                            'is_array' => is_array($data[$key]),
                            'count' => is_array($data[$key]) ? count($data[$key]) : 'N/A'
                        ]);
                    }
                }
            }

            Log::info('Kissflow Get Item Response', [
                'kissflow_id' => $kissflowId,
                'response_type' => gettype($data),
                'response_keys' => $allKeys,
                'has_Data' => is_array($data) && isset($data['Data']),
                'has_data' => is_array($data) && isset($data['data']),
                'has_Table_Model_DSakzWikms' => is_array($data) && isset($data['Table::Model_DSakzWikms']),
                'has_Model_DSakzWikms' => is_array($data) && isset($data['Model_DSakzWikms']),
                'response_sample' => is_array($data) ? json_encode(array_slice($data, 0, 3, true)) : 'not_array',
                'full_response_length' => strlen($responseBody)
            ]);

            // Response structure: { "Data": { ... } } or might be direct object
            $itemData = null;
            if (is_array($data)) {
                $itemData = $data['Data'] ?? $data['data'] ?? $data ?? null;
            }

            // If itemData is still null, log the full response for debugging
            if (!$itemData) {
                Log::warning('Kissflow Get Item - No data found', [
                    'kissflow_id' => $kissflowId,
                    'response_type' => gettype($data),
                    'response_structure' => is_array($data) ? json_encode($data, JSON_PRETTY_PRINT) : (string)$data,
                    'response_body_preview' => substr($responseBody, 0, 500)
                ]);
            }

            if ($itemData) {
                return $itemData;
            }

            return null;

        } catch (\Exception $e) {
            Log::error('Kissflow Get Item Error: ' . $e->getMessage(), [
                'kissflow_id' => $kissflowId
            ]);
            throw $e;
        }
    }

    /**
     * Get full item data from Kissflow Payment Request by ID
     *
     * @param string $kissflowId The Kissflow ID
     * @return array|null Returns the full item data or null if not found
     */
    public function getPaymentRequestById($kissflowId)
    {
        try {
            $url = $this->baseUrl . '/process/2/AcflcLIlo4aq/admin/Payment_Request/' . $kissflowId;

            $response = Http::withHeaders([
                'X-Access-Key-Id' => $this->accessKeyId,
                'X-Access-Key-Secret' => $this->accessKeySecret,
            ])->get($url);

            if (!$response->successful()) {
                $errorBody = $response->body();
                Log::error('Kissflow API Error (Payment Request)', [
                    'kissflow_id' => $kissflowId,
                    'url' => $url,
                    'status' => $response->status(),
                    'response' => $errorBody
                ]);
                throw new \Exception('Kissflow API request failed: HTTP ' . $response->status() . ' - ' . substr($errorBody, 0, 500));
            }

            $responseBody = $response->body();
            $data = $response->json();

            // Check if JSON parsing failed
            if ($data === null && !empty($responseBody)) {
                Log::error('Kissflow API - Invalid JSON Response (Payment Request)', [
                    'kissflow_id' => $kissflowId,
                    'response_body' => substr($responseBody, 0, 1000)
                ]);
                throw new \Exception('Invalid JSON response from Kissflow API');
            }

            // Response structure: { "Data": { ... } } or might be direct object
            $itemData = null;
            if (is_array($data)) {
                $itemData = $data['Data'] ?? $data['data'] ?? $data ?? null;
            }

            // If itemData is still null, log the full response for debugging
            if (!$itemData) {
                Log::warning('Kissflow Get Payment Request - No data found', [
                    'kissflow_id' => $kissflowId,
                    'response_type' => gettype($data),
                    'response_structure' => is_array($data) ? json_encode($data, JSON_PRETTY_PRINT) : (string)$data,
                    'response_body_preview' => substr($responseBody, 0, 500)
                ]);
            }

            if ($itemData) {
                return $itemData;
            }

            return null;

        } catch (\Exception $e) {
            Log::error('Kissflow Get Payment Request Error: ' . $e->getMessage(), [
                'kissflow_id' => $kissflowId
            ]);
            throw $e;
        }
    }

    /**
     * Push vendors to Kissflow dataset in batch
     *
     * @param array $vendors Array of vendor data to push
     * @param bool $isSandbox Whether to use sandbox or production URL
     * @return array Returns success status and response data
     */
    public function pushVendorsBatch(array $vendors, bool $isSandbox = true)
    {
        try {
            // Determine which batch URL to use based on environment
            $batchUrl = $isSandbox
                ? env('KISSFLOW_VENDORS_BATCH_URL_SANDBOX')
                : env('KISSFLOW_VENDORS_BATCH_URL_PRODUCTION');

            if (empty($batchUrl)) {
                throw new \Exception('Kissflow vendor batch URL not configured for ' . ($isSandbox ? 'sandbox' : 'production'));
            }

            // Transform vendor data to Kissflow dataset format
            $batchData = array_map(function ($vendor) {
                $isInactive = $vendor['is_inactive'] ?? false;
                return [
                    '_id' => $vendor['internal_id'] ?? '',
                    'Name' => $vendor['internal_id'] ?? '',
                    'Code' => $vendor['entity_id'] ?? '',
                    'Supplier_Name' => $vendor['company_name'] ?? '',
                    'Email_1' => $vendor['email'] ?? '',
                    'Phone' => $vendor['phone'] ?? '',
                    'Is_Active' => $isInactive, // Inverted: is_inactive false = Is_Active true
                ];
            }, $vendors);

            Log::info('Pushing vendors to Kissflow', [
                'count' => count($batchData),
                'environment' => $isSandbox ? 'sandbox' : 'production',
                'url' => $batchUrl
            ]);

            $response = Http::withHeaders([
                'X-Access-Key-Id' => $this->accessKeyId,
                'X-Access-Key-Secret' => $this->accessKeySecret,
                'Content-Type' => 'application/json',
            ])->post($batchUrl, $batchData);

            if (!$response->successful()) {
                $errorBody = $response->body();
                Log::error('Kissflow Vendor Batch Push Error', [
                    'status' => $response->status(),
                    'response' => $errorBody,
                    'vendor_count' => count($batchData)
                ]);
                throw new \Exception('Kissflow vendor batch push failed: HTTP ' . $response->status() . ' - ' . $errorBody);
            }

            $responseData = $response->json();

            Log::info('Kissflow Vendor Batch Push Success', [
                'vendor_count' => count($batchData),
                'response' => $responseData
            ]);

            return [
                'success' => true,
                'count' => count($batchData),
                'response' => $responseData
            ];

        } catch (\Exception $e) {
            Log::error('Kissflow Vendor Batch Push Error: ' . $e->getMessage(), [
                'vendor_count' => count($vendors)
            ]);
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Push employees to Kissflow dataset in batch
     *
     * Dataset ID: NetSuite_Employee (can be overridden via env)
     *
     * @param array $employees Array of employee data to push
     * @param bool $isSandbox Whether to use sandbox or production URL
     * @return array Returns success status and response data
     */
    public function pushEmployeesBatch(array $employees, bool $isSandbox = true)
    {
        try {
            $batchUrl = $this->resolveEmployeeBatchUrl($isSandbox);

            if (empty($batchUrl)) {
                throw new \Exception('Kissflow employee batch URL not configured for ' . ($isSandbox ? 'sandbox' : 'production'));
            }

            // Transform employee data to Kissflow dataset format (only keep NetSuite ID and name)
            $batchData = array_values(array_filter(array_map(function ($employee) {
                $netsuiteId = (string) ($employee['netsuite_id'] ?? '');
                if ($netsuiteId === '') {
                    return null;
                }
                return [
                    '_id' => $netsuiteId,
                    'Name' => $netsuiteId,
                    'Employee_Name' => $employee['name'] ?? '',
                ];
            }, $employees)));

            if (empty($batchData)) {
                throw new \Exception('No valid employees to push (missing NetSuite IDs)');
            }

            Log::info('Pushing employees to Kissflow', [
                'count' => count($batchData),
                'environment' => $isSandbox ? 'sandbox' : 'production',
                'url' => $batchUrl
            ]);

            $response = Http::withHeaders([
                'X-Access-Key-Id' => $this->accessKeyId,
                'X-Access-Key-Secret' => $this->accessKeySecret,
                'Content-Type' => 'application/json',
            ])->post($batchUrl, $batchData);

            if (!$response->successful()) {
                $errorBody = $response->body();
                Log::error('Kissflow Employee Batch Push Error', [
                    'status' => $response->status(),
                    'response' => $errorBody,
                    'employee_count' => count($batchData)
                ]);
                throw new \Exception('Kissflow employee batch push failed: HTTP ' . $response->status() . ' - ' . $errorBody);
            }

            $responseData = $response->json();

            Log::info('Kissflow Employee Batch Push Success', [
                'employee_count' => count($batchData),
                'response' => $responseData
            ]);

            return [
                'success' => true,
                'count' => count($batchData),
                'response' => $responseData
            ];

        } catch (\Exception $e) {
            Log::error('Kissflow Employee Batch Push Error: ' . $e->getMessage(), [
                'employee_count' => count($employees)
            ]);
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Resolve the Kissflow dataset batch URL for employees.
     *
     * The dataset ID defaults to NetSuite_Employee. Override with:
     * - KISSFLOW_EMPLOYEE_BATCH_URL_SANDBOX / KISSFLOW_EMPLOYEE_BATCH_URL_PRODUCTION (full URL)
     * - KISSFLOW_EMPLOYEE_DATASET_ID[_SANDBOX|_PRODUCTION] to change the dataset slug
     * - KISSFLOW_DATASET_APP_ID to change the dataset app ID (defaults to AcflcLIlo4aq)
     */
    protected function resolveEmployeeBatchUrl(bool $isSandbox): string
    {
        $configured = $isSandbox
            ? env('KISSFLOW_EMPLOYEE_BATCH_URL_SANDBOX')
            : env('KISSFLOW_EMPLOYEE_BATCH_URL_PRODUCTION');

        if (!empty($configured)) {
            return $configured;
        }

        $datasetAppId = env('KISSFLOW_DATASET_APP_ID', 'AcflcLIlo4aq');
        $datasetId = $isSandbox
            ? env('KISSFLOW_EMPLOYEE_DATASET_ID_SANDBOX', env('KISSFLOW_EMPLOYEE_DATASET_ID', 'NetSuite_Employee_Sandbox'))
            : env('KISSFLOW_EMPLOYEE_DATASET_ID_PRODUCTION', env('KISSFLOW_EMPLOYEE_DATASET_ID', 'NetSuite_Employee'));

        return rtrim($this->baseUrl, '/') . "/dataset/2/{$datasetAppId}/{$datasetId}/batch";
    }
}

