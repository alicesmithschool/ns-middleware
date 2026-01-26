<?php

return [
    'environment' => env('NETSUITE_ENVIRONMENT', 'sandbox'), // 'sandbox' or 'production'
    'endpoint' => env('NETSUITE_ENDPOINT', '2025_2'),
    'host' => env('NETSUITE_HOST'),
    'account' => env('NETSUITE_ACCOUNT'),
    'consumer_key' => env('NETSUITE_CONSUMER_KEY'),
    'consumer_secret' => env('NETSUITE_CONSUMER_SECRET'),
    'token' => env('NETSUITE_TOKEN'),
    'token_secret' => env('NETSUITE_TOKEN_SECRET'),
    
    // REST API credentials (for currency sync)
    'rest_domain' => env('NETSUITE_REST_DOMAIN'), // e.g., 9897202-sb1.suitetalk.api.netsuite.com
    'rest_consumer_key' => env('NETSUITE_REST_CONSUMER_KEY'),
    'rest_certificate_kid' => env('NETSUITE_REST_CERTIFICATE_KID'),
    'rest_certificate_private_key' => env('NETSUITE_REST_CERTIFICATE_PRIVATE_KEY'),
    'rest_scopes' => env('NETSUITE_REST_SCOPES', 'restlets,rest_webservices'),
    
    // Sandbox REST API credentials (used when NETSUITE_ENVIRONMENT=sandbox)
    'sandbox_rest_domain' => env('SANDBOX_NETSUITE_REST_DOMAIN'),
    'sandbox_rest_consumer_key' => env('SANDBOX_NETSUITE_REST_CONSUMER_KEY'),
    'sandbox_rest_certificate_kid' => env('SANDBOX_NETSUITE_REST_CERTIFICATE_KID'),
    'sandbox_rest_certificate_private_key' => env('SANDBOX_NETSUITE_REST_CERTIFICATE_PRIVATE_KEY'),
    
    // Helper method to check if sandbox
    'is_sandbox' => function() {
        return env('NETSUITE_ENVIRONMENT', 'sandbox') === 'sandbox';
    },
];

