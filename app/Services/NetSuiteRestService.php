<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use Jose\Component\Core\AlgorithmManager;
use Jose\Component\Core\JWK;
use Jose\Component\KeyManagement\JWKFactory;
use Jose\Component\Signature\Algorithm\PS256;
use Jose\Component\Signature\JWSBuilder;
use Jose\Component\Signature\Serializer\CompactSerializer;

class NetSuiteRestService
{
    protected $domain;
    protected $consumerKey;
    protected $certificateKid;
    protected $certificatePrivateKey;
    protected $scopes;
    protected $tokenPath;

    public function __construct()
    {
        $environment = config('netsuite.environment', 'sandbox');
        
        if ($environment === 'production') {
            $this->domain = config('netsuite.rest_domain') ?: config('netsuite.host');
            $this->consumerKey = config('netsuite.rest_consumer_key');
            $this->certificateKid = config('netsuite.rest_certificate_kid');
            $this->certificatePrivateKey = config('netsuite.rest_certificate_private_key');
        } else {
            // Sandbox
            $this->domain = config('netsuite.sandbox_rest_domain') ?: config('netsuite.rest_domain') ?: config('netsuite.host');
            $this->consumerKey = config('netsuite.sandbox_rest_consumer_key') ?: config('netsuite.rest_consumer_key');
            $this->certificateKid = config('netsuite.sandbox_rest_certificate_kid') ?: config('netsuite.rest_certificate_kid');
            $this->certificatePrivateKey = config('netsuite.sandbox_rest_certificate_private_key') ?: config('netsuite.rest_certificate_private_key');
        }
        
        $this->scopes = config('netsuite.rest_scopes', 'restlets,rest_webservices');
        $this->tokenPath = env('NETSUITE_REST_TOKEN_PATH', '/services/rest/auth/oauth2/v1/token');
        
        // Remove https:// if present
        $this->domain = preg_replace('/^https?:\/\//', '', $this->domain);
    }

    /**
     * Get OAuth2 access token using JWT client assertion
     */
    protected function getAccessToken()
    {
        // Check cache first
        $cacheKey = 'netsuite_rest_token_' . config('netsuite.environment', 'sandbox');
        $cached = Cache::get($cacheKey);
        
        if ($cached && isset($cached['access_token']) && isset($cached['expires_at'])) {
            $expiresAt = strtotime($cached['expires_at']);
            // Refresh if expires in less than 15 seconds
            if ($expiresAt > time() + 15) {
                return $cached['access_token'];
            }
        }

        if (empty($this->consumerKey) || empty($this->certificateKid) || empty($this->certificatePrivateKey)) {
            throw new \Exception('NetSuite REST credentials not configured. Set NETSUITE_REST_CONSUMER_KEY, NETSUITE_REST_CERTIFICATE_KID, and NETSUITE_REST_CERTIFICATE_PRIVATE_KEY in .env');
        }

        // Create JWT assertion
        $aud = "https://{$this->domain}{$this->tokenPath}";
        $now = time();
        $exp = $now + 3600; // 1 hour
        $scopeArray = array_map('trim', explode(',', $this->scopes));
        
        $header = [
            'alg' => 'PS256',
            'typ' => 'JWT',
            'kid' => $this->certificateKid
        ];
        
        $payload = [
            'iss' => $this->consumerKey,
            'scope' => $scopeArray,
            'iat' => $now,
            'exp' => $exp,
            'aud' => $aud
        ];

        try {
            // Note: firebase/php-jwt doesn't support PS256 natively
            // We need to use OpenSSL directly for PS256
            $assertion = $this->signJWTPS256($header, $payload, $this->certificatePrivateKey);
        } catch (\Exception $e) {
            throw new \Exception('Failed to create JWT assertion: ' . $e->getMessage());
        }

        // Request token
        $response = Http::asForm()->post($aud, [
            'grant_type' => 'client_credentials',
            'client_assertion_type' => 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer',
            'client_assertion' => $assertion
        ]);

        if (!$response->successful()) {
            throw new \Exception('Token request failed: HTTP ' . $response->status() . ' - ' . $response->body());
        }

        $data = $response->json();
        if (!isset($data['access_token'])) {
            throw new \Exception('No access token in response');
        }

        // Cache the token
        $expiresIn = $data['expires_in'] ?? 3600;
        $expiresAt = now()->addSeconds($expiresIn - 15); // Refresh 15 seconds before expiry
        
        Cache::put($cacheKey, [
            'access_token' => $data['access_token'],
            'expires_at' => $expiresAt->toIso8601String(),
            'raw' => $data
        ], $expiresAt);

        return $data['access_token'];
    }

    /**
     * Make authenticated REST API request
     */
    protected function makeRequest($method, $endpoint, $params = [])
    {
        try {
            $token = $this->getAccessToken();
        } catch (\Exception $e) {
            Log::error('Failed to get access token: ' . $e->getMessage());
            throw new \Exception('Authentication failed: ' . $e->getMessage());
        }
        
        $url = "https://{$this->domain}{$endpoint}";
        
        $response = Http::withToken($token)
            ->withHeaders(['Accept' => 'application/json'])
            ->send($method, $url, $params);

        if (!$response->successful()) {
            $errorBody = $response->body();
            $errorData = $response->json();
            
            Log::error('NetSuite REST API Error', [
                'method' => $method,
                'endpoint' => $endpoint,
                'url' => $url,
                'status' => $response->status(),
                'response' => $errorBody,
                'error_data' => $errorData
            ]);
            
            $errorMessage = 'NetSuite REST API request failed: HTTP ' . $response->status();
            
            // Extract error details from o:errorDetails array
            $errorDetails = [];
            if (isset($errorData['o:errorDetails']) && is_array($errorData['o:errorDetails'])) {
                foreach ($errorData['o:errorDetails'] as $detail) {
                    if (isset($detail['detail'])) {
                        $errorDetails[] = $detail['detail'];
                    }
                }
            }
            
            // Build error message
            if (!empty($errorDetails)) {
                $errorMessage .= ' - ' . implode('; ', $errorDetails);
            } elseif (isset($errorData['title'])) {
                $errorMessage .= ' - ' . $errorData['title'];
                if (isset($errorData['detail'])) {
                    $errorMessage .= ': ' . $errorData['detail'];
                }
            } elseif (isset($errorData['error']) || isset($errorData['message'])) {
                $errorMessage .= ' - ' . ($errorData['error'] ?? $errorData['message'] ?? $errorBody);
            } else {
                $errorMessage .= ' - ' . substr($errorBody, 0, 200);
            }
            
            // Include full error data in exception message for better debugging
            if (!empty($errorData)) {
                $errorMessage .= ' | Full response: ' . json_encode($errorData);
            }
            
            throw new \Exception($errorMessage);
        }

        return $response->json();
    }

    /**
     * Sign JWT with PS256 algorithm using web-token library
     * PS256 = RSASSA-PSS with SHA-256 and MGF1
     */
    protected function signJWTPS256($header, $payload, $privateKey)
    {
        try {
            // Create algorithm manager with PS256
            $algorithmManager = new AlgorithmManager([new PS256()]);
            
            // Create JWS Builder
            $jwsBuilder = new JWSBuilder($algorithmManager);
            
            // Create JWK from private key PEM
            $jwk = JWKFactory::createFromKey($privateKey);
            
            // Build JWS
            $jws = $jwsBuilder
                ->create()
                ->withPayload(json_encode($payload))
                ->addSignature($jwk, $header)
                ->build();
            
            // Serialize to compact format
            $serializer = new CompactSerializer();
            return $serializer->serialize($jws, 0);
            
        } catch (\Exception $e) {
            throw new \Exception('Failed to sign JWT with PS256: ' . $e->getMessage());
        }
    }

    /**
     * Fetch all currencies from NetSuite REST API
     * Based on the structure in currencies.json, we'll fetch individual currencies
     */
    public function fetchCurrencies()
    {
        try {
            $currencies = [];
            $endpoint = '/services/rest/record/v1/currency';
            
            // Try fetching common currency IDs (based on currencies.json structure)
            // We'll try IDs 1-20, which should cover most currencies
            $knownCurrencyIds = range(1, 20);
            
            Log::info("Fetching currencies from NetSuite REST API...");
            
            $errors = [];
            foreach ($knownCurrencyIds as $id) {
                try {
                    $currency = $this->makeRequest('GET', "{$endpoint}/{$id}");
                    
                    // NetSuite REST API returns currency data
                    if (isset($currency['id']) || isset($currency['refName'])) {
                        $currencies[$currency['id'] ?? $id] = $currency;
                        Log::debug("Fetched currency ID {$id}: " . ($currency['refName'] ?? $currency['name'] ?? 'Unknown'));
                    }
                    
                    // Small delay to avoid rate limiting
                    usleep(100000); // 0.1 seconds
                } catch (\Exception $e) {
                    $errorMsg = $e->getMessage();
                    // Currency might not exist, skip it
                    if (strpos($errorMsg, '404') !== false || strpos($errorMsg, 'not found') !== false) {
                        // 404 is expected for non-existent currencies, don't log
                        continue;
                    }
                    // Log other errors (auth, permissions, etc.)
                    $errors[] = "Currency ID {$id}: {$errorMsg}";
                    Log::warning("Currency ID {$id} error: {$errorMsg}");
                }
            }
            
            if (empty($currencies)) {
                $errorSummary = !empty($errors) ? "\nErrors encountered:\n" . implode("\n", array_slice($errors, 0, 5)) : '';
                throw new \Exception('No currencies found. Please check your REST API credentials and permissions.' . $errorSummary);
            }
            
            Log::info("Successfully fetched " . count($currencies) . " currencies");
            return $currencies;
            
        } catch (\Exception $e) {
            Log::error('Error fetching currencies from NetSuite REST API: ' . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Search vendors using SuiteQL with pagination support
     * 
     * @param array $fields Fields to select (default: id, entityid, companyname, email, phone, currency, isinactive)
     * @param string $where WHERE clause (optional)
     * @param int $limit Number of records per page (default: 1000, max: 1000)
     * @return array Array of vendor records
     */
    public function searchVendors($fields = null, $where = null, $limit = 1000)
    {
        try {
            $defaultFields = 'id, entityid, companyname, email, phone, currency, isinactive';
            $selectFields = $fields ? implode(', ', $fields) : $defaultFields;
            
            $baseQuery = "SELECT {$selectFields} FROM vendor";
            if ($where) {
                $baseQuery .= " WHERE {$where}";
            }
            $baseQuery .= " ORDER BY id";
            
            $endpoint = '/services/rest/query/v1/suiteql';
            $token = $this->getAccessToken();
            $url = "https://{$this->domain}{$endpoint}";
            
            $allVendors = [];
            $offset = 0;
            $hasMore = true;
            
            // NetSuite SuiteQL has a max limit of 1000 per request
            $pageLimit = min($limit, 1000);
            
            Log::info("Fetching vendors using SuiteQL with pagination (limit: {$pageLimit} per page)...");
            
            while ($hasMore) {
                // SuiteQL request body: only 'q' parameter (the SQL query)
                // NetSuite SuiteQL doesn't support limit/offset in request body
                // We'll fetch 1000 at a time (default limit) and use WHERE clause for pagination
                $requestPayload = ['q' => $baseQuery];
                
                // For pagination beyond first 1000, we need to use WHERE clause with ID filtering
                // Get the last ID from previous page to continue
                if ($offset > 0 && !empty($allVendors)) {
                    // Use the last vendor's ID to continue pagination
                    $lastVendorId = end($allVendors)['id'] ?? null;
                    if ($lastVendorId) {
                        // Modify query to get vendors with ID greater than last one
                        $paginationQuery = $baseQuery;
                        // Replace ORDER BY clause and add WHERE for ID > lastId
                        if (strpos($paginationQuery, 'WHERE') !== false) {
                            $paginationQuery = str_replace('ORDER BY id', "AND id > {$lastVendorId} ORDER BY id", $paginationQuery);
                        } else {
                            $paginationQuery = str_replace('ORDER BY id', "WHERE id > {$lastVendorId} ORDER BY id", $paginationQuery);
                        }
                        $requestPayload['q'] = $paginationQuery;
                    }
                }
                
                $response = Http::withToken($token)
                    ->withHeaders([
                        'Accept' => 'application/json',
                        'Content-Type' => 'application/json',
                        'Prefer' => 'transient'
                    ])
                    ->post($url, $requestPayload);
                
                if (!$response->successful()) {
                    $errorBody = $response->body();
                    $errorData = $response->json();
                    
                    Log::error('NetSuite REST API SuiteQL Error', [
                        'endpoint' => $endpoint,
                        'url' => $url,
                        'query' => $requestPayload['q'],
                        'payload' => $requestPayload,
                        'offset' => $offset,
                        'status' => $response->status(),
                        'response' => $errorBody
                    ]);
                    
                    $errorMessage = 'NetSuite REST API SuiteQL request failed: HTTP ' . $response->status();
                    if (isset($errorData['error']) || isset($errorData['message'])) {
                        $errorMessage .= ' - ' . ($errorData['error'] ?? $errorData['message'] ?? $errorBody);
                    } else {
                        $errorMessage .= ' - ' . substr($errorBody, 0, 200);
                    }
                    
                    throw new \Exception($errorMessage);
                }
                
                $data = $response->json();
                $items = $data['items'] ?? [];
                
                // Check for pagination info in response
                // SuiteQL might return hasMore, links, or count field
                $hasMore = isset($data['hasMore']) ? (bool)$data['hasMore'] : false;
                
                // Check for next link in response links array
                if (!$hasMore && isset($data['links']) && is_array($data['links'])) {
                    foreach ($data['links'] as $link) {
                        if (isset($link['rel']) && $link['rel'] === 'next') {
                            $hasMore = true;
                            // Could use link['href'] for next page, but for now use offset
                            break;
                        }
                    }
                }
                
                // If no explicit pagination info, infer from item count
                if (!$hasMore && count($items) >= $pageLimit) {
                    // Got full page (1000 items), likely has more
                    $hasMore = true;
                }
                
                if (!empty($items)) {
                    $allVendors = array_merge($allVendors, $items);
                    Log::info("Fetched " . count($items) . " vendors (total so far: " . count($allVendors) . ")");
                } else {
                    $hasMore = false;
                }
                
                // Move to next page
                // Track how many we've fetched
                $offset += count($items);
                
                // If we got exactly 1000 items, there might be more
                // If we got fewer than 1000, we've reached the end
                if (count($items) < 1000) {
                    $hasMore = false;
                }
                
                // Small delay between pages to avoid rate limiting
                if ($hasMore) {
                    usleep(200000); // 0.2 seconds
                }
                
                // Safety check: NetSuite has a max of 100,000 results for SuiteQL
                if ($offset >= 100000) {
                    Log::warning("Reached NetSuite SuiteQL maximum limit of 100,000 results");
                    break;
                }
            }
            
            Log::info("Successfully fetched " . count($allVendors) . " vendors total");
            return $allVendors;
            
        } catch (\Exception $e) {
            Log::error('Error searching vendors via REST API: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Get a single vendor by ID
     *
     * @param string|int $vendorId NetSuite vendor ID
     * @return array Vendor record data
     */
    public function getVendor($vendorId)
    {
        try {
            $endpoint = "/services/rest/record/v1/vendor/{$vendorId}";
            return $this->makeRequest('GET', $endpoint);

        } catch (\Exception $e) {
            Log::error("Error fetching vendor {$vendorId} via REST API: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Check if vendor exists by entity ID
     *
     * @param string $entityId Entity ID to check
     * @return bool True if vendor exists
     */
    public function vendorExistsByEntityId($entityId)
    {
        try {
            $vendors = $this->searchVendors(
                'id, entityid',
                "entityid = '{$entityId}'",
                1
            );

            return !empty($vendors);
        } catch (\Exception $e) {
            Log::error("Error checking vendor existence: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Create a new vendor via REST API
     *
     * @param array $vendorData Vendor data
     * @return array Response with vendor ID
     */
    public function createVendor(array $vendorData)
    {
        try {
            $endpoint = "/services/rest/record/v1/vendor";

            // Build vendor payload
            $payload = [];

            // Company name (required) - must be set and not empty
            if (empty($vendorData['company_name']) || trim($vendorData['company_name']) === '') {
                throw new \Exception('Company name is required and cannot be empty. Received: ' . json_encode($vendorData['company_name'] ?? 'NOT SET'));
            }
            $payload['companyName'] = trim($vendorData['company_name']);
            
            // Ensure companyName is not empty after trimming
            if (empty($payload['companyName'])) {
                throw new \Exception('Company name cannot be empty after trimming. Original value: ' . json_encode($vendorData['company_name']));
            }

            // Entity ID (optional - NetSuite will auto-generate if not provided)
            if (isset($vendorData['entity_id']) && !empty($vendorData['entity_id'])) {
                $payload['entityId'] = $vendorData['entity_id'];
            }

            // Email
            if (isset($vendorData['email'])) {
                $payload['email'] = $vendorData['email'];
            }

            // Phone
            if (isset($vendorData['phone'])) {
                $payload['phone'] = $vendorData['phone'];
            }

            // Is Inactive
            if (isset($vendorData['is_inactive'])) {
                $payload['isInactive'] = (bool) $vendorData['is_inactive'];
            }

            // Currency
            if (isset($vendorData['currency_id'])) {
                $payload['currency'] = ['id' => (string) $vendorData['currency_id']];
            }

            // Address book
            if (isset($vendorData['address_1']) || isset($vendorData['city']) || isset($vendorData['country'])) {
                $addressbook = [
                    'defaultBilling' => true,
                    'defaultShipping' => true,
                    'addressbookAddress' => []
                ];

                if (isset($vendorData['address_1'])) {
                    $addressbook['addressbookAddress']['addr1'] = $vendorData['address_1'];
                }
                if (isset($vendorData['address_2'])) {
                    $addressbook['addressbookAddress']['addr2'] = $vendorData['address_2'];
                }
                if (isset($vendorData['city'])) {
                    $addressbook['addressbookAddress']['city'] = $vendorData['city'];
                }
                if (isset($vendorData['state'])) {
                    $addressbook['addressbookAddress']['state'] = $vendorData['state'];
                }
                if (isset($vendorData['zip'])) {
                    $addressbook['addressbookAddress']['zip'] = $vendorData['zip'];
                }
                if (isset($vendorData['country'])) {
                    $addressbook['addressbookAddress']['country'] = $vendorData['country'];
                }

                $payload['addressbookList'] = ['addressbook' => [$addressbook]];
            }

            // Custom fields
            $customFields = [];

            // TIN Number
            if (isset($vendorData['tin_number'])) {
                $customFields['custentity_assa_tin_number'] = $vendorData['tin_number'];
            }

            // SST Number
            if (isset($vendorData['sst_number'])) {
                $customFields['custentity_assa_sst_number'] = $vendorData['sst_number'];
            }

            // Tourism Tax
            if (isset($vendorData['tourism_tax'])) {
                $customFields['custentity_assa_tourism_tax'] = $vendorData['tourism_tax'];
            }

            // E-Invoicing fields - ALL required by NetSuite for ALL vendors
            // Always set these fields with proper defaults - NEVER skip these
            // Determine if vendor is Malaysian based on country code
            $countryCode = strtoupper(trim($vendorData['einv_country_code'] ?? ''));
            $countryField = strtoupper(trim($vendorData['country'] ?? ''));
            
            // Check if Malaysian
            $isMalaysian = false;
            if (!empty($countryCode)) {
                $isMalaysian = in_array($countryCode, ['MY', 'MYS']);
            } elseif (!empty($countryField)) {
                $isMalaysian = in_array($countryField, ['_MALAYSIA', 'MALAYSIA', 'MY', 'MYS']);
            } else {
                $isMalaysian = true; // Default to Malaysian if no country specified
            }
            
            // Helper function to get value or default (allows "0" as valid value)
            $getValue = function($key, $default) use ($vendorData) {
                return (isset($vendorData[$key]) && $vendorData[$key] !== null && $vendorData[$key] !== '') 
                    ? trim((string)$vendorData[$key]) 
                    : $default;
            };
            
            // ALWAYS set all E-Invoicing fields - these are REQUIRED by NetSuite
            // For non-Malaysian vendors: TIN = EI000000000030, Identification Code = 000000
            $customFields['custentity_einv_tin_no'] = $getValue('einv_tin_no', 'EI000000000030');
            $customFields['custentity_einv_registered_name'] = $getValue('einv_registered_name', $getValue('company_name', ''));
            $customFields['custentity_einv_sst_register_no'] = $getValue('einv_sst_register_no', '0');
            $customFields['custentity_einv_msic_code'] = $getValue('einv_msic_code', '00000');
            $customFields['custentity_einv_address_line1'] = $getValue('einv_address_line1', '0');
            // City Name: For non-Malaysian vendors use "Not Applicable", for Malaysian use "Kuala Lumpur"
            $customFields['custentity_einv_city_name'] = $getValue('einv_city_name', (!$isMalaysian ? 'Not Applicable' : 'Kuala Lumpur'));
            $customFields['custentity_einv_country_code'] = strtoupper($getValue('einv_country_code', 'MY'));
            $customFields['custentity_einv_state_code'] = $getValue('einv_state_code', '0');
            // For non-Malaysian vendors, default Identification Code to "000000", otherwise "0"
            $customFields['custentity_einv_identification_code'] = $getValue('einv_identification_code', (!$isMalaysian ? '000000' : '0'));
            $customFields['custentity_einv_identification_type'] = $getValue('einv_identification_type', 'BRN');
            
            $hasEInvFields = true; // Always true since we always set these fields

            // ALWAYS add customFields to payload - E-Invoicing fields are required
            // Verify all required E-Invoicing fields are present
            $requiredEInvFields = [
                'custentity_einv_tin_no',
                'custentity_einv_registered_name',
                'custentity_einv_sst_register_no',
                'custentity_einv_msic_code',
                'custentity_einv_address_line1',
                'custentity_einv_city_name',
                'custentity_einv_country_code',
                'custentity_einv_state_code',
                'custentity_einv_identification_code',
                'custentity_einv_identification_type'
            ];
            
            foreach ($requiredEInvFields as $field) {
                if (!isset($customFields[$field]) || $customFields[$field] === null || $customFields[$field] === '') {
                    throw new \Exception("Required E-Invoicing field {$field} is missing or empty. Value: " . ($customFields[$field] ?? 'NOT SET'));
                }
            }
            
            // Always add customFields to payload
            $payload['customFields'] = $customFields;

            // Log E-Invoicing field values for debugging
            $einvFieldValues = [];
            foreach ($customFields as $key => $value) {
                if (strpos($key, 'custentity_einv_') === 0) {
                    $einvFieldValues[$key] = $value;
                }
            }
            
            Log::info('Creating vendor via REST API', [
                'company_name' => $payload['companyName'] ?? null,
                'entity_id' => $vendorData['entity_id'] ?? null,
                'has_custom_fields' => !empty($customFields),
                'has_einv_fields' => $hasEInvFields,
                'einv_fields_count' => count($einvFieldValues),
                'einv_fields' => $einvFieldValues,
                'is_malaysian' => $isMalaysian,
                'country_code' => $countryCode,
                'country_field' => $countryField,
                'identification_code' => $customFields['custentity_einv_identification_code'] ?? null,
                'full_payload' => json_encode($payload, JSON_PRETTY_PRINT),
                'custom_fields_keys' => array_keys($customFields)
            ]);

            $response = $this->makeRequest('POST', $endpoint, $payload);

            // Extract the created vendor ID
            $vendorId = $response['id'] ?? null;

            // Fetch the vendor to get the auto-generated entity ID if needed
            $entityId = null;
            if ($vendorId) {
                try {
                    $createdVendor = $this->getVendor($vendorId);
                    $entityId = $createdVendor['entityId'] ?? null;
                } catch (\Exception $e) {
                    Log::warning("Could not fetch created vendor to get entity ID: " . $e->getMessage());
                }
            }

            return [
                'success' => true,
                'internal_id' => $vendorId,
                'entity_id' => $entityId
            ];

        } catch (\Exception $e) {
            $errorMessage = $e->getMessage();
            
            // Try to extract more details from the error message if it contains JSON
            $netsuiteResponse = $errorMessage;
            if (preg_match('/\{.*\}/s', $errorMessage, $matches)) {
                $errorJson = json_decode($matches[0], true);
                if ($errorJson) {
                    $netsuiteResponse = json_encode($errorJson, JSON_PRETTY_PRINT);
                    
                    // Extract error details if available
                    if (isset($errorJson['o:errorDetails']) && is_array($errorJson['o:errorDetails'])) {
                        $details = [];
                        foreach ($errorJson['o:errorDetails'] as $detail) {
                            if (isset($detail['detail'])) {
                                $details[] = $detail['detail'];
                            }
                        }
                        if (!empty($details)) {
                            $errorMessage .= ' | Details: ' . implode('; ', $details);
                        }
                    }
                }
            }
            
            Log::error('Error creating vendor via REST API', [
                'error' => $errorMessage,
                'payload' => $payload ?? null,
                'full_response' => $netsuiteResponse
            ]);

            return [
                'success' => false,
                'error' => $errorMessage,
                'netsuite_response' => $netsuiteResponse
            ];
        }
    }

    /**
     * Fetch all countries from NetSuite using SuiteQL
     *
     * @return array Array of countries with their details
     */
    public function fetchCountries()
    {
        try {
            $token = $this->getAccessToken();

            // Query to get all countries
            // Note: countries is a system record type in NetSuite
            $query = "SELECT id, country, countrycode, addressbookcode FROM countries ORDER BY country";

            $response = Http::withToken($token)
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'Prefer' => 'transient'
                ])
                ->post("https://{$this->domain}/services/rest/query/v1/suiteql", [
                    'q' => $query
                ]);

            if (!$response->successful()) {
                $errorBody = $response->body();
                Log::error("NetSuite countries fetch failed: " . $errorBody);
                throw new \Exception("NetSuite countries fetch failed: {$response->status()} - {$errorBody}");
            }

            $data = $response->json();

            if (!isset($data['items'])) {
                // If SuiteQL doesn't work, try getting from record API
                return $this->fetchCountriesViaRecord();
            }

            Log::info("Fetched " . count($data['items']) . " countries from NetSuite");

            return $data['items'] ?? [];

        } catch (\Exception $e) {
            Log::error("Error fetching countries via SuiteQL: " . $e->getMessage());
            // Fallback to record API
            return $this->fetchCountriesViaRecord();
        }
    }

    /**
     * Fetch countries using record API (fallback method)
     *
     * @return array Array of countries
     */
    protected function fetchCountriesViaRecord()
    {
        try {
            // Hardcoded list of common countries with their NetSuite enum values
            // This is a fallback since NetSuite doesn't provide a direct REST endpoint for countries
            $countries = [
                ['id' => '1', 'country_code' => '_afghanistan', 'name' => 'Afghanistan', 'iso_code_2' => 'AF', 'iso_code_3' => 'AFG'],
                ['id' => '2', 'country_code' => '_alandIslands', 'name' => 'Aland Islands', 'iso_code_2' => 'AX', 'iso_code_3' => 'ALA'],
                ['id' => '3', 'country_code' => '_albania', 'name' => 'Albania', 'iso_code_2' => 'AL', 'iso_code_3' => 'ALB'],
                ['id' => '4', 'country_code' => '_algeria', 'name' => 'Algeria', 'iso_code_2' => 'DZ', 'iso_code_3' => 'DZA'],
                ['id' => '5', 'country_code' => '_americanSamoa', 'name' => 'American Samoa', 'iso_code_2' => 'AS', 'iso_code_3' => 'ASM'],
                ['id' => '6', 'country_code' => '_andorra', 'name' => 'Andorra', 'iso_code_2' => 'AD', 'iso_code_3' => 'AND'],
                ['id' => '7', 'country_code' => '_angola', 'name' => 'Angola', 'iso_code_2' => 'AO', 'iso_code_3' => 'AGO'],
                ['id' => '8', 'country_code' => '_anguilla', 'name' => 'Anguilla', 'iso_code_2' => 'AI', 'iso_code_3' => 'AIA'],
                ['id' => '9', 'country_code' => '_antarctica', 'name' => 'Antarctica', 'iso_code_2' => 'AQ', 'iso_code_3' => 'ATA'],
                ['id' => '10', 'country_code' => '_antiguaandBarbuda', 'name' => 'Antigua and Barbuda', 'iso_code_2' => 'AG', 'iso_code_3' => 'ATG'],
                ['id' => '11', 'country_code' => '_argentina', 'name' => 'Argentina', 'iso_code_2' => 'AR', 'iso_code_3' => 'ARG'],
                ['id' => '12', 'country_code' => '_armenia', 'name' => 'Armenia', 'iso_code_2' => 'AM', 'iso_code_3' => 'ARM'],
                ['id' => '13', 'country_code' => '_aruba', 'name' => 'Aruba', 'iso_code_2' => 'AW', 'iso_code_3' => 'ABW'],
                ['id' => '14', 'country_code' => '_australia', 'name' => 'Australia', 'iso_code_2' => 'AU', 'iso_code_3' => 'AUS'],
                ['id' => '15', 'country_code' => '_austria', 'name' => 'Austria', 'iso_code_2' => 'AT', 'iso_code_3' => 'AUT'],
                ['id' => '16', 'country_code' => '_azerbaijan', 'name' => 'Azerbaijan', 'iso_code_2' => 'AZ', 'iso_code_3' => 'AZE'],
                ['id' => '17', 'country_code' => '_bahamas', 'name' => 'Bahamas', 'iso_code_2' => 'BS', 'iso_code_3' => 'BHS'],
                ['id' => '18', 'country_code' => '_bahrain', 'name' => 'Bahrain', 'iso_code_2' => 'BH', 'iso_code_3' => 'BHR'],
                ['id' => '19', 'country_code' => '_bangladesh', 'name' => 'Bangladesh', 'iso_code_2' => 'BD', 'iso_code_3' => 'BGD'],
                ['id' => '20', 'country_code' => '_barbados', 'name' => 'Barbados', 'iso_code_2' => 'BB', 'iso_code_3' => 'BRB'],
                ['id' => '21', 'country_code' => '_belarus', 'name' => 'Belarus', 'iso_code_2' => 'BY', 'iso_code_3' => 'BLR'],
                ['id' => '22', 'country_code' => '_belgium', 'name' => 'Belgium', 'iso_code_2' => 'BE', 'iso_code_3' => 'BEL'],
                ['id' => '23', 'country_code' => '_belize', 'name' => 'Belize', 'iso_code_2' => 'BZ', 'iso_code_3' => 'BLZ'],
                ['id' => '24', 'country_code' => '_benin', 'name' => 'Benin', 'iso_code_2' => 'BJ', 'iso_code_3' => 'BEN'],
                ['id' => '25', 'country_code' => '_bermuda', 'name' => 'Bermuda', 'iso_code_2' => 'BM', 'iso_code_3' => 'BMU'],
                ['id' => '26', 'country_code' => '_bhutan', 'name' => 'Bhutan', 'iso_code_2' => 'BT', 'iso_code_3' => 'BTN'],
                ['id' => '27', 'country_code' => '_bolivia', 'name' => 'Bolivia', 'iso_code_2' => 'BO', 'iso_code_3' => 'BOL'],
                ['id' => '28', 'country_code' => '_bonaireSaintEustatiusandSaba', 'name' => 'Bonaire, Saint Eustatius and Saba', 'iso_code_2' => 'BQ', 'iso_code_3' => 'BES'],
                ['id' => '29', 'country_code' => '_bosniaandHerzegovina', 'name' => 'Bosnia and Herzegovina', 'iso_code_2' => 'BA', 'iso_code_3' => 'BIH'],
                ['id' => '30', 'country_code' => '_botswana', 'name' => 'Botswana', 'iso_code_2' => 'BW', 'iso_code_3' => 'BWA'],
                ['id' => '31', 'country_code' => '_bouvetIsland', 'name' => 'Bouvet Island', 'iso_code_2' => 'BV', 'iso_code_3' => 'BVT'],
                ['id' => '32', 'country_code' => '_brazil', 'name' => 'Brazil', 'iso_code_2' => 'BR', 'iso_code_3' => 'BRA'],
                ['id' => '33', 'country_code' => '_britishIndianOceanTerritory', 'name' => 'British Indian Ocean Territory', 'iso_code_2' => 'IO', 'iso_code_3' => 'IOT'],
                ['id' => '34', 'country_code' => '_bruneiDarussalam', 'name' => 'Brunei Darussalam', 'iso_code_2' => 'BN', 'iso_code_3' => 'BRN'],
                ['id' => '35', 'country_code' => '_bulgaria', 'name' => 'Bulgaria', 'iso_code_2' => 'BG', 'iso_code_3' => 'BGR'],
                ['id' => '36', 'country_code' => '_burkinaFaso', 'name' => 'Burkina Faso', 'iso_code_2' => 'BF', 'iso_code_3' => 'BFA'],
                ['id' => '37', 'country_code' => '_burundi', 'name' => 'Burundi', 'iso_code_2' => 'BI', 'iso_code_3' => 'BDI'],
                ['id' => '38', 'country_code' => '_cambodia', 'name' => 'Cambodia', 'iso_code_2' => 'KH', 'iso_code_3' => 'KHM'],
                ['id' => '39', 'country_code' => '_cameroon', 'name' => 'Cameroon', 'iso_code_2' => 'CM', 'iso_code_3' => 'CMR'],
                ['id' => '40', 'country_code' => '_canada', 'name' => 'Canada', 'iso_code_2' => 'CA', 'iso_code_3' => 'CAN'],
                ['id' => '41', 'country_code' => '_canaryIslands', 'name' => 'Canary Islands', 'iso_code_2' => 'IC', 'iso_code_3' => 'ICA'],
                ['id' => '42', 'country_code' => '_capeVerde', 'name' => 'Cape Verde', 'iso_code_2' => 'CV', 'iso_code_3' => 'CPV'],
                ['id' => '43', 'country_code' => '_caymanIslands', 'name' => 'Cayman Islands', 'iso_code_2' => 'KY', 'iso_code_3' => 'CYM'],
                ['id' => '44', 'country_code' => '_centralAfricanRepublic', 'name' => 'Central African Republic', 'iso_code_2' => 'CF', 'iso_code_3' => 'CAF'],
                ['id' => '45', 'country_code' => '_ceutaandMelilla', 'name' => 'Ceuta and Melilla', 'iso_code_2' => 'EA', 'iso_code_3' => 'CEU'],
                ['id' => '46', 'country_code' => '_chad', 'name' => 'Chad', 'iso_code_2' => 'TD', 'iso_code_3' => 'TCD'],
                ['id' => '47', 'country_code' => '_chile', 'name' => 'Chile', 'iso_code_2' => 'CL', 'iso_code_3' => 'CHL'],
                ['id' => '48', 'country_code' => '_china', 'name' => 'China', 'iso_code_2' => 'CN', 'iso_code_3' => 'CHN'],
                ['id' => '49', 'country_code' => '_christmasIsland', 'name' => 'Christmas Island', 'iso_code_2' => 'CX', 'iso_code_3' => 'CXR'],
                ['id' => '50', 'country_code' => '_cocosKeelingIslands', 'name' => 'Cocos (Keeling) Islands', 'iso_code_2' => 'CC', 'iso_code_3' => 'CCK'],
                ['id' => '51', 'country_code' => '_colombia', 'name' => 'Colombia', 'iso_code_2' => 'CO', 'iso_code_3' => 'COL'],
                ['id' => '52', 'country_code' => '_comoros', 'name' => 'Comoros', 'iso_code_2' => 'KM', 'iso_code_3' => 'COM'],
                ['id' => '53', 'country_code' => '_congoDemocraticPeoplesRepublic', 'name' => 'Congo, Democratic Republic of', 'iso_code_2' => 'CD', 'iso_code_3' => 'COD'],
                ['id' => '54', 'country_code' => '_congoRepublicof', 'name' => 'Congo, Republic of', 'iso_code_2' => 'CG', 'iso_code_3' => 'COG'],
                ['id' => '55', 'country_code' => '_cookIslands', 'name' => 'Cook Islands', 'iso_code_2' => 'CK', 'iso_code_3' => 'COK'],
                ['id' => '56', 'country_code' => '_costaRica', 'name' => 'Costa Rica', 'iso_code_2' => 'CR', 'iso_code_3' => 'CRI'],
                ['id' => '57', 'country_code' => '_cotedIvoire', 'name' => 'Cote d\'Ivoire', 'iso_code_2' => 'CI', 'iso_code_3' => 'CIV'],
                ['id' => '58', 'country_code' => '_croatia', 'name' => 'Croatia', 'iso_code_2' => 'HR', 'iso_code_3' => 'HRV'],
                ['id' => '59', 'country_code' => '_cuba', 'name' => 'Cuba', 'iso_code_2' => 'CU', 'iso_code_3' => 'CUB'],
                ['id' => '60', 'country_code' => '_curacao', 'name' => 'Curacao', 'iso_code_2' => 'CW', 'iso_code_3' => 'CUW'],
                ['id' => '61', 'country_code' => '_cyprus', 'name' => 'Cyprus', 'iso_code_2' => 'CY', 'iso_code_3' => 'CYP'],
                ['id' => '62', 'country_code' => '_czechRepublic', 'name' => 'Czech Republic', 'iso_code_2' => 'CZ', 'iso_code_3' => 'CZE'],
                ['id' => '63', 'country_code' => '_denmark', 'name' => 'Denmark', 'iso_code_2' => 'DK', 'iso_code_3' => 'DNK'],
                ['id' => '64', 'country_code' => '_djibouti', 'name' => 'Djibouti', 'iso_code_2' => 'DJ', 'iso_code_3' => 'DJI'],
                ['id' => '65', 'country_code' => '_dominica', 'name' => 'Dominica', 'iso_code_2' => 'DM', 'iso_code_3' => 'DMA'],
                ['id' => '66', 'country_code' => '_dominicanRepublic', 'name' => 'Dominican Republic', 'iso_code_2' => 'DO', 'iso_code_3' => 'DOM'],
                ['id' => '67', 'country_code' => '_eastTimor', 'name' => 'East Timor', 'iso_code_2' => 'TL', 'iso_code_3' => 'TLS'],
                ['id' => '68', 'country_code' => '_ecuador', 'name' => 'Ecuador', 'iso_code_2' => 'EC', 'iso_code_3' => 'ECU'],
                ['id' => '69', 'country_code' => '_egypt', 'name' => 'Egypt', 'iso_code_2' => 'EG', 'iso_code_3' => 'EGY'],
                ['id' => '70', 'country_code' => '_elSalvador', 'name' => 'El Salvador', 'iso_code_2' => 'SV', 'iso_code_3' => 'SLV'],
                ['id' => '71', 'country_code' => '_equatorialGuinea', 'name' => 'Equatorial Guinea', 'iso_code_2' => 'GQ', 'iso_code_3' => 'GNQ'],
                ['id' => '72', 'country_code' => '_eritrea', 'name' => 'Eritrea', 'iso_code_2' => 'ER', 'iso_code_3' => 'ERI'],
                ['id' => '73', 'country_code' => '_estonia', 'name' => 'Estonia', 'iso_code_2' => 'EE', 'iso_code_3' => 'EST'],
                ['id' => '74', 'country_code' => '_ethiopia', 'name' => 'Ethiopia', 'iso_code_2' => 'ET', 'iso_code_3' => 'ETH'],
                ['id' => '75', 'country_code' => '_falklandIslands', 'name' => 'Falkland Islands', 'iso_code_2' => 'FK', 'iso_code_3' => 'FLK'],
                ['id' => '76', 'country_code' => '_faroeIslands', 'name' => 'Faroe Islands', 'iso_code_2' => 'FO', 'iso_code_3' => 'FRO'],
                ['id' => '77', 'country_code' => '_fiji', 'name' => 'Fiji', 'iso_code_2' => 'FJ', 'iso_code_3' => 'FJI'],
                ['id' => '78', 'country_code' => '_finland', 'name' => 'Finland', 'iso_code_2' => 'FI', 'iso_code_3' => 'FIN'],
                ['id' => '79', 'country_code' => '_france', 'name' => 'France', 'iso_code_2' => 'FR', 'iso_code_3' => 'FRA'],
                ['id' => '80', 'country_code' => '_frenchGuiana', 'name' => 'French Guiana', 'iso_code_2' => 'GF', 'iso_code_3' => 'GUF'],
                ['id' => '81', 'country_code' => '_frenchPolynesia', 'name' => 'French Polynesia', 'iso_code_2' => 'PF', 'iso_code_3' => 'PYF'],
                ['id' => '82', 'country_code' => '_frenchSouthernTerritories', 'name' => 'French Southern Territories', 'iso_code_2' => 'TF', 'iso_code_3' => 'ATF'],
                ['id' => '83', 'country_code' => '_gabon', 'name' => 'Gabon', 'iso_code_2' => 'GA', 'iso_code_3' => 'GAB'],
                ['id' => '84', 'country_code' => '_gambia', 'name' => 'Gambia', 'iso_code_2' => 'GM', 'iso_code_3' => 'GMB'],
                ['id' => '85', 'country_code' => '_georgia', 'name' => 'Georgia', 'iso_code_2' => 'GE', 'iso_code_3' => 'GEO'],
                ['id' => '86', 'country_code' => '_germany', 'name' => 'Germany', 'iso_code_2' => 'DE', 'iso_code_3' => 'DEU'],
                ['id' => '87', 'country_code' => '_ghana', 'name' => 'Ghana', 'iso_code_2' => 'GH', 'iso_code_3' => 'GHA'],
                ['id' => '88', 'country_code' => '_gibraltar', 'name' => 'Gibraltar', 'iso_code_2' => 'GI', 'iso_code_3' => 'GIB'],
                ['id' => '89', 'country_code' => '_greece', 'name' => 'Greece', 'iso_code_2' => 'GR', 'iso_code_3' => 'GRC'],
                ['id' => '90', 'country_code' => '_greenland', 'name' => 'Greenland', 'iso_code_2' => 'GL', 'iso_code_3' => 'GRL'],
                ['id' => '91', 'country_code' => '_grenada', 'name' => 'Grenada', 'iso_code_2' => 'GD', 'iso_code_3' => 'GRD'],
                ['id' => '92', 'country_code' => '_guadeloupe', 'name' => 'Guadeloupe', 'iso_code_2' => 'GP', 'iso_code_3' => 'GLP'],
                ['id' => '93', 'country_code' => '_guam', 'name' => 'Guam', 'iso_code_2' => 'GU', 'iso_code_3' => 'GUM'],
                ['id' => '94', 'country_code' => '_guatemala', 'name' => 'Guatemala', 'iso_code_2' => 'GT', 'iso_code_3' => 'GTM'],
                ['id' => '95', 'country_code' => '_guernsey', 'name' => 'Guernsey', 'iso_code_2' => 'GG', 'iso_code_3' => 'GGY'],
                ['id' => '96', 'country_code' => '_guinea', 'name' => 'Guinea', 'iso_code_2' => 'GN', 'iso_code_3' => 'GIN'],
                ['id' => '97', 'country_code' => '_guineaBissau', 'name' => 'Guinea-Bissau', 'iso_code_2' => 'GW', 'iso_code_3' => 'GNB'],
                ['id' => '98', 'country_code' => '_guyana', 'name' => 'Guyana', 'iso_code_2' => 'GY', 'iso_code_3' => 'GUY'],
                ['id' => '99', 'country_code' => '_haiti', 'name' => 'Haiti', 'iso_code_2' => 'HT', 'iso_code_3' => 'HTI'],
                ['id' => '100', 'country_code' => '_heardandMcDonaldIslands', 'name' => 'Heard and McDonald Islands', 'iso_code_2' => 'HM', 'iso_code_3' => 'HMD'],
                ['id' => '101', 'country_code' => '_holySeeCityVaticanState', 'name' => 'Holy See (City Vatican State)', 'iso_code_2' => 'VA', 'iso_code_3' => 'VAT'],
                ['id' => '102', 'country_code' => '_honduras', 'name' => 'Honduras', 'iso_code_2' => 'HN', 'iso_code_3' => 'HND'],
                ['id' => '103', 'country_code' => '_hongKong', 'name' => 'Hong Kong', 'iso_code_2' => 'HK', 'iso_code_3' => 'HKG'],
                ['id' => '104', 'country_code' => '_hungary', 'name' => 'Hungary', 'iso_code_2' => 'HU', 'iso_code_3' => 'HUN'],
                ['id' => '105', 'country_code' => '_iceland', 'name' => 'Iceland', 'iso_code_2' => 'IS', 'iso_code_3' => 'ISL'],
                ['id' => '106', 'country_code' => '_india', 'name' => 'India', 'iso_code_2' => 'IN', 'iso_code_3' => 'IND'],
                ['id' => '107', 'country_code' => '_indonesia', 'name' => 'Indonesia', 'iso_code_2' => 'ID', 'iso_code_3' => 'IDN'],
                ['id' => '108', 'country_code' => '_iranIslamicRepublicof', 'name' => 'Iran (Islamic Republic of)', 'iso_code_2' => 'IR', 'iso_code_3' => 'IRN'],
                ['id' => '109', 'country_code' => '_iraq', 'name' => 'Iraq', 'iso_code_2' => 'IQ', 'iso_code_3' => 'IRQ'],
                ['id' => '110', 'country_code' => '_ireland', 'name' => 'Ireland', 'iso_code_2' => 'IE', 'iso_code_3' => 'IRL'],
                ['id' => '111', 'country_code' => '_isleofMan', 'name' => 'Isle of Man', 'iso_code_2' => 'IM', 'iso_code_3' => 'IMN'],
                ['id' => '112', 'country_code' => '_israel', 'name' => 'Israel', 'iso_code_2' => 'IL', 'iso_code_3' => 'ISR'],
                ['id' => '113', 'country_code' => '_italy', 'name' => 'Italy', 'iso_code_2' => 'IT', 'iso_code_3' => 'ITA'],
                ['id' => '114', 'country_code' => '_jamaica', 'name' => 'Jamaica', 'iso_code_2' => 'JM', 'iso_code_3' => 'JAM'],
                ['id' => '115', 'country_code' => '_japan', 'name' => 'Japan', 'iso_code_2' => 'JP', 'iso_code_3' => 'JPN'],
                ['id' => '116', 'country_code' => '_jersey', 'name' => 'Jersey', 'iso_code_2' => 'JE', 'iso_code_3' => 'JEY'],
                ['id' => '117', 'country_code' => '_jordan', 'name' => 'Jordan', 'iso_code_2' => 'JO', 'iso_code_3' => 'JOR'],
                ['id' => '118', 'country_code' => '_kazakhstan', 'name' => 'Kazakhstan', 'iso_code_2' => 'KZ', 'iso_code_3' => 'KAZ'],
                ['id' => '119', 'country_code' => '_kenya', 'name' => 'Kenya', 'iso_code_2' => 'KE', 'iso_code_3' => 'KEN'],
                ['id' => '120', 'country_code' => '_kiribati', 'name' => 'Kiribati', 'iso_code_2' => 'KI', 'iso_code_3' => 'KIR'],
                ['id' => '121', 'country_code' => '_koreaDemocraticPeoplesRepublic', 'name' => 'Korea, Democratic People\'s Republic', 'iso_code_2' => 'KP', 'iso_code_3' => 'PRK'],
                ['id' => '122', 'country_code' => '_koreaRepublicof', 'name' => 'Korea, Republic of', 'iso_code_2' => 'KR', 'iso_code_3' => 'KOR'],
                ['id' => '123', 'country_code' => '_kosovo', 'name' => 'Kosovo', 'iso_code_2' => 'XK', 'iso_code_3' => 'XKS'],
                ['id' => '124', 'country_code' => '_kuwait', 'name' => 'Kuwait', 'iso_code_2' => 'KW', 'iso_code_3' => 'KWT'],
                ['id' => '125', 'country_code' => '_kyrgyzstan', 'name' => 'Kyrgyzstan', 'iso_code_2' => 'KG', 'iso_code_3' => 'KGZ'],
                ['id' => '126', 'country_code' => '_laoPeoplesDemocraticRepublic', 'name' => 'Lao People\'s Democratic Republic', 'iso_code_2' => 'LA', 'iso_code_3' => 'LAO'],
                ['id' => '127', 'country_code' => '_latvia', 'name' => 'Latvia', 'iso_code_2' => 'LV', 'iso_code_3' => 'LVA'],
                ['id' => '128', 'country_code' => '_lebanon', 'name' => 'Lebanon', 'iso_code_2' => 'LB', 'iso_code_3' => 'LBN'],
                ['id' => '129', 'country_code' => '_lesotho', 'name' => 'Lesotho', 'iso_code_2' => 'LS', 'iso_code_3' => 'LSO'],
                ['id' => '130', 'country_code' => '_liberia', 'name' => 'Liberia', 'iso_code_2' => 'LR', 'iso_code_3' => 'LBR'],
                ['id' => '131', 'country_code' => '_libya', 'name' => 'Libya', 'iso_code_2' => 'LY', 'iso_code_3' => 'LBY'],
                ['id' => '132', 'country_code' => '_liechtenstein', 'name' => 'Liechtenstein', 'iso_code_2' => 'LI', 'iso_code_3' => 'LIE'],
                ['id' => '133', 'country_code' => '_lithuania', 'name' => 'Lithuania', 'iso_code_2' => 'LT', 'iso_code_3' => 'LTU'],
                ['id' => '134', 'country_code' => '_luxembourg', 'name' => 'Luxembourg', 'iso_code_2' => 'LU', 'iso_code_3' => 'LUX'],
                ['id' => '135', 'country_code' => '_macau', 'name' => 'Macau', 'iso_code_2' => 'MO', 'iso_code_3' => 'MAC'],
                ['id' => '136', 'country_code' => '_macedoniaformerYugoslavRepublic', 'name' => 'Macedonia (former Yugoslav Republic)', 'iso_code_2' => 'MK', 'iso_code_3' => 'MKD'],
                ['id' => '137', 'country_code' => '_madagascar', 'name' => 'Madagascar', 'iso_code_2' => 'MG', 'iso_code_3' => 'MDG'],
                ['id' => '138', 'country_code' => '_malawi', 'name' => 'Malawi', 'iso_code_2' => 'MW', 'iso_code_3' => 'MWI'],
                ['id' => '139', 'country_code' => '_malaysia', 'name' => 'Malaysia', 'iso_code_2' => 'MY', 'iso_code_3' => 'MYS'],
                ['id' => '140', 'country_code' => '_maldives', 'name' => 'Maldives', 'iso_code_2' => 'MV', 'iso_code_3' => 'MDV'],
                ['id' => '141', 'country_code' => '_mali', 'name' => 'Mali', 'iso_code_2' => 'ML', 'iso_code_3' => 'MLI'],
                ['id' => '142', 'country_code' => '_malta', 'name' => 'Malta', 'iso_code_2' => 'MT', 'iso_code_3' => 'MLT'],
                ['id' => '143', 'country_code' => '_marshallIslands', 'name' => 'Marshall Islands', 'iso_code_2' => 'MH', 'iso_code_3' => 'MHL'],
                ['id' => '144', 'country_code' => '_martinique', 'name' => 'Martinique', 'iso_code_2' => 'MQ', 'iso_code_3' => 'MTQ'],
                ['id' => '145', 'country_code' => '_mauritania', 'name' => 'Mauritania', 'iso_code_2' => 'MR', 'iso_code_3' => 'MRT'],
                ['id' => '146', 'country_code' => '_mauritius', 'name' => 'Mauritius', 'iso_code_2' => 'MU', 'iso_code_3' => 'MUS'],
                ['id' => '147', 'country_code' => '_mayotte', 'name' => 'Mayotte', 'iso_code_2' => 'YT', 'iso_code_3' => 'MYT'],
                ['id' => '148', 'country_code' => '_mexico', 'name' => 'Mexico', 'iso_code_2' => 'MX', 'iso_code_3' => 'MEX'],
                ['id' => '149', 'country_code' => '_micronesiaFederalStateof', 'name' => 'Micronesia, Federal State of', 'iso_code_2' => 'FM', 'iso_code_3' => 'FSM'],
                ['id' => '150', 'country_code' => '_moldovaRepublicof', 'name' => 'Moldova, Republic of', 'iso_code_2' => 'MD', 'iso_code_3' => 'MDA'],
                ['id' => '151', 'country_code' => '_monaco', 'name' => 'Monaco', 'iso_code_2' => 'MC', 'iso_code_3' => 'MCO'],
                ['id' => '152', 'country_code' => '_mongolia', 'name' => 'Mongolia', 'iso_code_2' => 'MN', 'iso_code_3' => 'MNG'],
                ['id' => '153', 'country_code' => '_montenegro', 'name' => 'Montenegro', 'iso_code_2' => 'ME', 'iso_code_3' => 'MNE'],
                ['id' => '154', 'country_code' => '_montserrat', 'name' => 'Montserrat', 'iso_code_2' => 'MS', 'iso_code_3' => 'MSR'],
                ['id' => '155', 'country_code' => '_morocco', 'name' => 'Morocco', 'iso_code_2' => 'MA', 'iso_code_3' => 'MAR'],
                ['id' => '156', 'country_code' => '_mozambique', 'name' => 'Mozambique', 'iso_code_2' => 'MZ', 'iso_code_3' => 'MOZ'],
                ['id' => '157', 'country_code' => '_myanmar', 'name' => 'Myanmar', 'iso_code_2' => 'MM', 'iso_code_3' => 'MMR'],
                ['id' => '158', 'country_code' => '_namibia', 'name' => 'Namibia', 'iso_code_2' => 'NA', 'iso_code_3' => 'NAM'],
                ['id' => '159', 'country_code' => '_nauru', 'name' => 'Nauru', 'iso_code_2' => 'NR', 'iso_code_3' => 'NRU'],
                ['id' => '160', 'country_code' => '_nepal', 'name' => 'Nepal', 'iso_code_2' => 'NP', 'iso_code_3' => 'NPL'],
                ['id' => '161', 'country_code' => '_netherlands', 'name' => 'Netherlands', 'iso_code_2' => 'NL', 'iso_code_3' => 'NLD'],
                ['id' => '162', 'country_code' => '_newCaledonia', 'name' => 'New Caledonia', 'iso_code_2' => 'NC', 'iso_code_3' => 'NCL'],
                ['id' => '163', 'country_code' => '_newZealand', 'name' => 'New Zealand', 'iso_code_2' => 'NZ', 'iso_code_3' => 'NZL'],
                ['id' => '164', 'country_code' => '_nicaragua', 'name' => 'Nicaragua', 'iso_code_2' => 'NI', 'iso_code_3' => 'NIC'],
                ['id' => '165', 'country_code' => '_niger', 'name' => 'Niger', 'iso_code_2' => 'NE', 'iso_code_3' => 'NER'],
                ['id' => '166', 'country_code' => '_nigeria', 'name' => 'Nigeria', 'iso_code_2' => 'NG', 'iso_code_3' => 'NGA'],
                ['id' => '167', 'country_code' => '_niue', 'name' => 'Niue', 'iso_code_2' => 'NU', 'iso_code_3' => 'NIU'],
                ['id' => '168', 'country_code' => '_norfolkIsland', 'name' => 'Norfolk Island', 'iso_code_2' => 'NF', 'iso_code_3' => 'NFK'],
                ['id' => '169', 'country_code' => '_northernMarianaIslands', 'name' => 'Northern Mariana Islands', 'iso_code_2' => 'MP', 'iso_code_3' => 'MNP'],
                ['id' => '170', 'country_code' => '_norway', 'name' => 'Norway', 'iso_code_2' => 'NO', 'iso_code_3' => 'NOR'],
                ['id' => '171', 'country_code' => '_oman', 'name' => 'Oman', 'iso_code_2' => 'OM', 'iso_code_3' => 'OMN'],
                ['id' => '172', 'country_code' => '_pakistan', 'name' => 'Pakistan', 'iso_code_2' => 'PK', 'iso_code_3' => 'PAK'],
                ['id' => '173', 'country_code' => '_palau', 'name' => 'Palau', 'iso_code_2' => 'PW', 'iso_code_3' => 'PLW'],
                ['id' => '174', 'country_code' => '_palestinianTerritories', 'name' => 'Palestinian Territories', 'iso_code_2' => 'PS', 'iso_code_3' => 'PSE'],
                ['id' => '175', 'country_code' => '_panama', 'name' => 'Panama', 'iso_code_2' => 'PA', 'iso_code_3' => 'PAN'],
                ['id' => '176', 'country_code' => '_papuaNewGuinea', 'name' => 'Papua New Guinea', 'iso_code_2' => 'PG', 'iso_code_3' => 'PNG'],
                ['id' => '177', 'country_code' => '_paraguay', 'name' => 'Paraguay', 'iso_code_2' => 'PY', 'iso_code_3' => 'PRY'],
                ['id' => '178', 'country_code' => '_peru', 'name' => 'Peru', 'iso_code_2' => 'PE', 'iso_code_3' => 'PER'],
                ['id' => '179', 'country_code' => '_philippines', 'name' => 'Philippines', 'iso_code_2' => 'PH', 'iso_code_3' => 'PHL'],
                ['id' => '180', 'country_code' => '_pitcairnIsland', 'name' => 'Pitcairn Island', 'iso_code_2' => 'PN', 'iso_code_3' => 'PCN'],
                ['id' => '181', 'country_code' => '_poland', 'name' => 'Poland', 'iso_code_2' => 'PL', 'iso_code_3' => 'POL'],
                ['id' => '182', 'country_code' => '_portugal', 'name' => 'Portugal', 'iso_code_2' => 'PT', 'iso_code_3' => 'PRT'],
                ['id' => '183', 'country_code' => '_puertoRico', 'name' => 'Puerto Rico', 'iso_code_2' => 'PR', 'iso_code_3' => 'PRI'],
                ['id' => '184', 'country_code' => '_qatar', 'name' => 'Qatar', 'iso_code_2' => 'QA', 'iso_code_3' => 'QAT'],
                ['id' => '185', 'country_code' => '_reunion', 'name' => 'Reunion', 'iso_code_2' => 'RE', 'iso_code_3' => 'REU'],
                ['id' => '186', 'country_code' => '_romania', 'name' => 'Romania', 'iso_code_2' => 'RO', 'iso_code_3' => 'ROU'],
                ['id' => '187', 'country_code' => '_russianFederation', 'name' => 'Russian Federation', 'iso_code_2' => 'RU', 'iso_code_3' => 'RUS'],
                ['id' => '188', 'country_code' => '_rwanda', 'name' => 'Rwanda', 'iso_code_2' => 'RW', 'iso_code_3' => 'RWA'],
                ['id' => '189', 'country_code' => '_saintBarthelemy', 'name' => 'Saint Barthelemy', 'iso_code_2' => 'BL', 'iso_code_3' => 'BLM'],
                ['id' => '190', 'country_code' => '_saintHelena', 'name' => 'Saint Helena', 'iso_code_2' => 'SH', 'iso_code_3' => 'SHN'],
                ['id' => '191', 'country_code' => '_saintKittsandNevis', 'name' => 'Saint Kitts and Nevis', 'iso_code_2' => 'KN', 'iso_code_3' => 'KNA'],
                ['id' => '192', 'country_code' => '_saintLucia', 'name' => 'Saint Lucia', 'iso_code_2' => 'LC', 'iso_code_3' => 'LCA'],
                ['id' => '193', 'country_code' => '_saintMartin', 'name' => 'Saint Martin', 'iso_code_2' => 'MF', 'iso_code_3' => 'MAF'],
                ['id' => '194', 'country_code' => '_saintPierreandMiquelon', 'name' => 'Saint Pierre and Miquelon', 'iso_code_2' => 'PM', 'iso_code_3' => 'SPM'],
                ['id' => '195', 'country_code' => '_saintVincentandtheGrenadines', 'name' => 'Saint Vincent and the Grenadines', 'iso_code_2' => 'VC', 'iso_code_3' => 'VCT'],
                ['id' => '196', 'country_code' => '_samoa', 'name' => 'Samoa', 'iso_code_2' => 'WS', 'iso_code_3' => 'WSM'],
                ['id' => '197', 'country_code' => '_sanMarino', 'name' => 'San Marino', 'iso_code_2' => 'SM', 'iso_code_3' => 'SMR'],
                ['id' => '198', 'country_code' => '_saoTomeandPrincipe', 'name' => 'Sao Tome and Principe', 'iso_code_2' => 'ST', 'iso_code_3' => 'STP'],
                ['id' => '199', 'country_code' => '_saudiArabia', 'name' => 'Saudi Arabia', 'iso_code_2' => 'SA', 'iso_code_3' => 'SAU'],
                ['id' => '200', 'country_code' => '_senegal', 'name' => 'Senegal', 'iso_code_2' => 'SN', 'iso_code_3' => 'SEN'],
                ['id' => '201', 'country_code' => '_serbia', 'name' => 'Serbia', 'iso_code_2' => 'RS', 'iso_code_3' => 'SRB'],
                ['id' => '202', 'country_code' => '_seychelles', 'name' => 'Seychelles', 'iso_code_2' => 'SC', 'iso_code_3' => 'SYC'],
                ['id' => '203', 'country_code' => '_sierraLeone', 'name' => 'Sierra Leone', 'iso_code_2' => 'SL', 'iso_code_3' => 'SLE'],
                ['id' => '204', 'country_code' => '_singapore', 'name' => 'Singapore', 'iso_code_2' => 'SG', 'iso_code_3' => 'SGP'],
                ['id' => '205', 'country_code' => '_sintMaarten', 'name' => 'Sint Maarten', 'iso_code_2' => 'SX', 'iso_code_3' => 'SXM'],
                ['id' => '206', 'country_code' => '_slovakia', 'name' => 'Slovakia', 'iso_code_2' => 'SK', 'iso_code_3' => 'SVK'],
                ['id' => '207', 'country_code' => '_slovenia', 'name' => 'Slovenia', 'iso_code_2' => 'SI', 'iso_code_3' => 'SVN'],
                ['id' => '208', 'country_code' => '_solomonIslands', 'name' => 'Solomon Islands', 'iso_code_2' => 'SB', 'iso_code_3' => 'SLB'],
                ['id' => '209', 'country_code' => '_somalia', 'name' => 'Somalia', 'iso_code_2' => 'SO', 'iso_code_3' => 'SOM'],
                ['id' => '210', 'country_code' => '_southAfrica', 'name' => 'South Africa', 'iso_code_2' => 'ZA', 'iso_code_3' => 'ZAF'],
                ['id' => '211', 'country_code' => '_southGeorgia', 'name' => 'South Georgia', 'iso_code_2' => 'GS', 'iso_code_3' => 'SGS'],
                ['id' => '212', 'country_code' => '_southSudan', 'name' => 'South Sudan', 'iso_code_2' => 'SS', 'iso_code_3' => 'SSD'],
                ['id' => '213', 'country_code' => '_spain', 'name' => 'Spain', 'iso_code_2' => 'ES', 'iso_code_3' => 'ESP'],
                ['id' => '214', 'country_code' => '_sriLanka', 'name' => 'Sri Lanka', 'iso_code_2' => 'LK', 'iso_code_3' => 'LKA'],
                ['id' => '215', 'country_code' => '_sudan', 'name' => 'Sudan', 'iso_code_2' => 'SD', 'iso_code_3' => 'SDN'],
                ['id' => '216', 'country_code' => '_suriname', 'name' => 'Suriname', 'iso_code_2' => 'SR', 'iso_code_3' => 'SUR'],
                ['id' => '217', 'country_code' => '_svalbardandJanMayenIslands', 'name' => 'Svalbard and Jan Mayen Islands', 'iso_code_2' => 'SJ', 'iso_code_3' => 'SJM'],
                ['id' => '218', 'country_code' => '_swaziland', 'name' => 'Swaziland', 'iso_code_2' => 'SZ', 'iso_code_3' => 'SWZ'],
                ['id' => '219', 'country_code' => '_sweden', 'name' => 'Sweden', 'iso_code_2' => 'SE', 'iso_code_3' => 'SWE'],
                ['id' => '220', 'country_code' => '_switzerland', 'name' => 'Switzerland', 'iso_code_2' => 'CH', 'iso_code_3' => 'CHE'],
                ['id' => '221', 'country_code' => '_syrianArabRepublic', 'name' => 'Syrian Arab Republic', 'iso_code_2' => 'SY', 'iso_code_3' => 'SYR'],
                ['id' => '222', 'country_code' => '_taiwan', 'name' => 'Taiwan', 'iso_code_2' => 'TW', 'iso_code_3' => 'TWN'],
                ['id' => '223', 'country_code' => '_tajikistan', 'name' => 'Tajikistan', 'iso_code_2' => 'TJ', 'iso_code_3' => 'TJK'],
                ['id' => '224', 'country_code' => '_tanzaniaUnitedRepublicof', 'name' => 'Tanzania, United Republic of', 'iso_code_2' => 'TZ', 'iso_code_3' => 'TZA'],
                ['id' => '225', 'country_code' => '_thailand', 'name' => 'Thailand', 'iso_code_2' => 'TH', 'iso_code_3' => 'THA'],
                ['id' => '226', 'country_code' => '_togo', 'name' => 'Togo', 'iso_code_2' => 'TG', 'iso_code_3' => 'TGO'],
                ['id' => '227', 'country_code' => '_tokelau', 'name' => 'Tokelau', 'iso_code_2' => 'TK', 'iso_code_3' => 'TKL'],
                ['id' => '228', 'country_code' => '_tonga', 'name' => 'Tonga', 'iso_code_2' => 'TO', 'iso_code_3' => 'TON'],
                ['id' => '229', 'country_code' => '_trinidadandTobago', 'name' => 'Trinidad and Tobago', 'iso_code_2' => 'TT', 'iso_code_3' => 'TTO'],
                ['id' => '230', 'country_code' => '_tunisia', 'name' => 'Tunisia', 'iso_code_2' => 'TN', 'iso_code_3' => 'TUN'],
                ['id' => '231', 'country_code' => '_turkey', 'name' => 'Turkey', 'iso_code_2' => 'TR', 'iso_code_3' => 'TUR'],
                ['id' => '232', 'country_code' => '_turkmenistan', 'name' => 'Turkmenistan', 'iso_code_2' => 'TM', 'iso_code_3' => 'TKM'],
                ['id' => '233', 'country_code' => '_turksandCaicosIslands', 'name' => 'Turks and Caicos Islands', 'iso_code_2' => 'TC', 'iso_code_3' => 'TCA'],
                ['id' => '234', 'country_code' => '_tuvalu', 'name' => 'Tuvalu', 'iso_code_2' => 'TV', 'iso_code_3' => 'TUV'],
                ['id' => '235', 'country_code' => '_uganda', 'name' => 'Uganda', 'iso_code_2' => 'UG', 'iso_code_3' => 'UGA'],
                ['id' => '236', 'country_code' => '_ukraine', 'name' => 'Ukraine', 'iso_code_2' => 'UA', 'iso_code_3' => 'UKR'],
                ['id' => '237', 'country_code' => '_unitedArabEmirates', 'name' => 'United Arab Emirates', 'iso_code_2' => 'AE', 'iso_code_3' => 'ARE'],
                ['id' => '238', 'country_code' => '_unitedKingdom', 'name' => 'United Kingdom', 'iso_code_2' => 'GB', 'iso_code_3' => 'GBR'],
                ['id' => '239', 'country_code' => '_unitedStates', 'name' => 'United States', 'iso_code_2' => 'US', 'iso_code_3' => 'USA'],
                ['id' => '240', 'country_code' => '_uruguay', 'name' => 'Uruguay', 'iso_code_2' => 'UY', 'iso_code_3' => 'URY'],
                ['id' => '241', 'country_code' => '_uSMinorOutlyingIslands', 'name' => 'US Minor Outlying Islands', 'iso_code_2' => 'UM', 'iso_code_3' => 'UMI'],
                ['id' => '242', 'country_code' => '_uzbekistan', 'name' => 'Uzbekistan', 'iso_code_2' => 'UZ', 'iso_code_3' => 'UZB'],
                ['id' => '243', 'country_code' => '_vanuatu', 'name' => 'Vanuatu', 'iso_code_2' => 'VU', 'iso_code_3' => 'VUT'],
                ['id' => '244', 'country_code' => '_venezuela', 'name' => 'Venezuela', 'iso_code_2' => 'VE', 'iso_code_3' => 'VEN'],
                ['id' => '245', 'country_code' => '_vietnam', 'name' => 'Vietnam', 'iso_code_2' => 'VN', 'iso_code_3' => 'VNM'],
                ['id' => '246', 'country_code' => '_virginIslandsBritish', 'name' => 'Virgin Islands (British)', 'iso_code_2' => 'VG', 'iso_code_3' => 'VGB'],
                ['id' => '247', 'country_code' => '_virginIslandsUSA', 'name' => 'Virgin Islands (USA)', 'iso_code_2' => 'VI', 'iso_code_3' => 'VIR'],
                ['id' => '248', 'country_code' => '_wallisandFutunaIslands', 'name' => 'Wallis and Futuna Islands', 'iso_code_2' => 'WF', 'iso_code_3' => 'WLF'],
                ['id' => '249', 'country_code' => '_westernSahara', 'name' => 'Western Sahara', 'iso_code_2' => 'EH', 'iso_code_3' => 'ESH'],
                ['id' => '250', 'country_code' => '_yemen', 'name' => 'Yemen', 'iso_code_2' => 'YE', 'iso_code_3' => 'YEM'],
                ['id' => '251', 'country_code' => '_zambia', 'name' => 'Zambia', 'iso_code_2' => 'ZM', 'iso_code_3' => 'ZMB'],
                ['id' => '252', 'country_code' => '_zimbabwe', 'name' => 'Zimbabwe', 'iso_code_2' => 'ZW', 'iso_code_3' => 'ZWE'],
            ];

            Log::info("Using hardcoded country list (" . count($countries) . " countries)");
            return $countries;

        } catch (\Exception $e) {
            Log::error("Error in fetchCountriesViaRecord: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Helper method for info messages (for use in commands)
     */
    protected function info($message)
    {
        // This will be overridden in command context
        Log::info($message);
    }
}

