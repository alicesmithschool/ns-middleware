<?php

namespace App\Services;

use Google_Client;
use Google_Service_Sheets;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class GoogleSheetsService
{
    protected $service;
    protected $spreadsheetId;

    public function __construct($spreadsheetId = null)
    {
        // If spreadsheet ID not provided, determine based on environment
        if ($spreadsheetId === null) {
            $environment = config('netsuite.environment', 'sandbox');
            if ($environment === 'production') {
                $spreadsheetId = env('PROD_GOOGLE_SHEETS_SPREADSHEET_ID') ?: env('GOOGLE_SHEETS_SPREADSHEET_ID');
            } else {
                $spreadsheetId = env('GOOGLE_SHEETS_SPREADSHEET_ID');
            }
        }
        
        $this->spreadsheetId = $spreadsheetId;
        
        if (empty($this->spreadsheetId)) {
            $envVar = config('netsuite.environment', 'sandbox') === 'production' 
                ? 'PROD_GOOGLE_SHEETS_SPREADSHEET_ID' 
                : 'GOOGLE_SHEETS_SPREADSHEET_ID';
            throw new \Exception("Google Sheets Spreadsheet ID not configured. Set {$envVar} in .env");
        }
        
        $serviceAccountPath = storage_path('app/google-sheets/service-account.json');
        
        if (!file_exists($serviceAccountPath)) {
            throw new \Exception("Service account JSON not found at: {$serviceAccountPath}");
        }
        
        $client = new Google_Client();
        $client->setApplicationName('NetSuite Middleware');
        $client->setScopes(Google_Service_Sheets::SPREADSHEETS);
        $client->setAuthConfig($serviceAccountPath);
        $client->setAccessType('offline');
        
        $this->service = new Google_Service_Sheets($client);
    }

    /**
     * Read data from a sheet
     */
    public function readSheet($sheetName, $spreadsheetIdOrRange = null, $spreadsheetId = null)
    {
        try {
            // Support both old and new signatures
            // Old: readSheet($sheetName, $range)
            // New: readSheet($sheetName, $spreadsheetId)
            // New: readSheet($sheetName, $range, $spreadsheetId)
            $range = null;
            $targetSpreadsheetId = $this->spreadsheetId;

            if ($spreadsheetId !== null) {
                // New signature: readSheet($sheetName, $range, $spreadsheetId)
                $range = $spreadsheetIdOrRange;
                $targetSpreadsheetId = $spreadsheetId;
            } elseif ($spreadsheetIdOrRange !== null) {
                // Check if it's a spreadsheet ID (starts with 1 and is long) or a range
                if (preg_match('/^[0-9A-Za-z_-]{20,}$/', $spreadsheetIdOrRange)) {
                    // Likely a spreadsheet ID
                    $targetSpreadsheetId = $spreadsheetIdOrRange;
                } else {
                    // Likely a range
                    $range = $spreadsheetIdOrRange;
                }
            }

            $range = $range ? "{$sheetName}!{$range}" : $sheetName;
            $response = $this->service->spreadsheets_values->get($targetSpreadsheetId, $range);
            return $response->getValues() ?? [];
        } catch (\Exception $e) {
            Log::error('Google Sheets Read Error: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Append data to a sheet
     */
    public function appendToSheet($sheetName, $values, $spreadsheetId = null)
    {
        try {
            $targetSpreadsheetId = $spreadsheetId ?? $this->spreadsheetId;
            $range = $sheetName;
            $body = new \Google_Service_Sheets_ValueRange([
                'values' => $values
            ]);
            $params = [
                'valueInputOption' => 'RAW',
                'insertDataOption' => 'INSERT_ROWS'
            ];

            $result = $this->service->spreadsheets_values->append(
                $targetSpreadsheetId,
                $range,
                $body,
                $params
            );

            return $result;
        } catch (\Exception $e) {
            Log::error('Google Sheets Append Error: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Delete rows from a sheet
     */
    public function deleteRows($sheetName, $startRow, $numRows, $spreadsheetId = null)
    {
        try {
            $targetSpreadsheetId = $spreadsheetId ?? $this->spreadsheetId;

            // Get sheet ID
            $spreadsheet = $this->service->spreadsheets->get($targetSpreadsheetId);
            $sheetId = null;

            foreach ($spreadsheet->getSheets() as $sheet) {
                if ($sheet->getProperties()->getTitle() === $sheetName) {
                    $sheetId = $sheet->getProperties()->getSheetId();
                    break;
                }
            }

            if ($sheetId === null) {
                throw new \Exception("Sheet '{$sheetName}' not found");
            }

            // Delete rows
            $request = new \Google_Service_Sheets_DeleteDimensionRequest([
                'range' => [
                    'sheetId' => $sheetId,
                    'dimension' => 'ROWS',
                    'startIndex' => $startRow - 1, // 0-based
                    'endIndex' => $startRow - 1 + $numRows
                ]
            ]);

            $batchUpdate = new \Google_Service_Sheets_BatchUpdateSpreadsheetRequest([
                'requests' => [
                    new \Google_Service_Sheets_Request(['deleteDimension' => $request])
                ]
            ]);

            $this->service->spreadsheets->batchUpdate($targetSpreadsheetId, $batchUpdate);

            return true;
        } catch (\Exception $e) {
            Log::error('Google Sheets Delete Rows Error: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Update a specific cell or range in a sheet
     */
    public function updateCell($sheetName, $cell, $value)
    {
        try {
            $range = "{$sheetName}!{$cell}";
            $body = new \Google_Service_Sheets_ValueRange([
                'values' => [[$value]]
            ]);
            $params = [
                'valueInputOption' => 'RAW'
            ];
            
            $result = $this->service->spreadsheets_values->update(
                $this->spreadsheetId,
                $range,
                $body,
                $params
            );
            
            return $result;
        } catch (\Exception $e) {
            Log::error('Google Sheets Update Cell Error: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Update multiple cells in a sheet
     */
    public function updateRange($sheetName, $range, $values)
    {
        try {
            $fullRange = "{$sheetName}!{$range}";
            $body = new \Google_Service_Sheets_ValueRange([
                'values' => $values
            ]);
            $params = [
                'valueInputOption' => 'RAW'
            ];
            
            $result = $this->service->spreadsheets_values->update(
                $this->spreadsheetId,
                $fullRange,
                $body,
                $params
            );
            
            return $result;
        } catch (\Exception $e) {
            Log::error('Google Sheets Update Range Error: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Get spreadsheet ID from URL
     */
    public static function extractSpreadsheetId($url)
    {
        preg_match('/\/spreadsheets\/d\/([a-zA-Z0-9-_]+)/', $url, $matches);
        return $matches[1] ?? null;
    }
}

