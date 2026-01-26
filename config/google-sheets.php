<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Google Sheets Spreadsheet IDs
    |--------------------------------------------------------------------------
    |
    | Configure different spreadsheet IDs for various use cases.
    | These can be set via environment variables or directly here.
    |
    */

    'vendors_spreadsheet_id' => env('VENDORS_SPREADSHEET_ID'),
    'po_spreadsheet_id' => env('GOOGLE_SHEETS_SPREADSHEET_ID'),
    'prod_po_spreadsheet_id' => env('PROD_GOOGLE_SHEETS_SPREADSHEET_ID'),
    'bill_spreadsheet_id' => env('SANDBOX_BILL_SHEET_ID', env('BILL_SHEET_ID')),
    'prod_bill_spreadsheet_id' => env('PROD_BILL_SHEET_ID'),
    'expense_spreadsheet_id' => env('SANDBOX_EXPENSE_SHEET_ID', env('EXPENSE_SHEET_ID')),
    'prod_expense_spreadsheet_id' => env('PROD_EXPENSE_SHEET_ID'),

    /*
    |--------------------------------------------------------------------------
    | Service Account Path
    |--------------------------------------------------------------------------
    |
    | Path to the Google service account JSON credentials file.
    |
    */

    'service_account_path' => storage_path('app/google-sheets/service-account.json'),
];
