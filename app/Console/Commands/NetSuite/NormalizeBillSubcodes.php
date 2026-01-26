<?php

namespace App\Console\Commands\NetSuite;

use App\Services\GoogleSheetsService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class NormalizeBillSubcodes extends Command
{
    protected $signature = 'netsuite:normalize-bill-subcodes {--dry-run : Show what would be changed without actually updating}';
    protected $description = 'Normalize Bill sheet subcodes (Line Item tab) from if_gl.json to ns_gl.json format';

    public function handle()
    {
        $this->info('Starting Bill subcode normalization...');
        $isDryRun = $this->option('dry-run');

        if ($isDryRun) {
            $this->warn('DRY RUN MODE - No changes will be made to the sheet');
        }

        try {
            $environment = config('netsuite.environment', 'sandbox');
            $spreadsheetId = $this->getBillSpreadsheetId($environment);
            $this->info("Using Bill spreadsheet ID: {$spreadsheetId} ({$environment})");

            $sheetsService = new GoogleSheetsService($spreadsheetId);

            // Load if_gl.json mapping (old system)
            $this->info('Loading if_gl.json mapping...');
            $ifGlPath = base_path('if_gl.json');
            if (!file_exists($ifGlPath)) {
                $this->error("if_gl.json not found at: {$ifGlPath}");
                return Command::FAILURE;
            }

            $ifGlData = json_decode(file_get_contents($ifGlPath), true);
            if (!$ifGlData) {
                $this->error('Failed to parse if_gl.json');
                return Command::FAILURE;
            }

            // Create mapping: account_number -> name
            $ifGlMap = [];
            foreach ($ifGlData as $item) {
                if (isset($item['account_number']) && isset($item['name'])) {
                    $ifGlMap[$item['account_number']] = $item['name'];
                }
            }
            $this->info("Loaded " . count($ifGlMap) . " mappings from if_gl.json");

            // Load ns_gl.json mapping (new system)
            $this->info('Loading ns_gl.json mapping...');
            $nsGlPath = base_path('ns_gl.json');
            if (!file_exists($nsGlPath)) {
                $this->error("ns_gl.json not found at: {$nsGlPath}");
                return Command::FAILURE;
            }

            $nsGlData = json_decode(file_get_contents($nsGlPath), true);
            if (!$nsGlData) {
                $this->error('Failed to parse ns_gl.json');
                return Command::FAILURE;
            }

            // Create mapping: name -> account_number (first match wins)
            $nsGlMap = [];
            foreach ($nsGlData as $item) {
                if (isset($item['account_number']) && isset($item['name'])) {
                    $name = trim($item['name']);
                    if (!isset($nsGlMap[$name])) {
                        $nsGlMap[$name] = $item['account_number'];
                    }
                }
            }
            $this->info("Loaded " . count($nsGlMap) . " mappings from ns_gl.json");

            // Read Line Item sheet (bill line items)
            $sheetName = 'Line Item';
            $this->info("Reading '{$sheetName}' sheet...");
            $rows = $sheetsService->readSheet($sheetName);

            if (empty($rows) || count($rows) < 2) {
                $this->warn("No data found in '{$sheetName}' sheet (need at least header + 1 row)");
                return Command::SUCCESS;
            }

            // Get headers (first row)
            $headers = array_map('trim', $rows[0]);
            $headerMap = array_flip($headers);

            // Validate required headers
            if (!isset($headerMap['Subcode'])) {
                $this->error("Required header 'Subcode' not found in {$sheetName} sheet");
                return Command::FAILURE;
            }

            $subcodeColumnIndex = $headerMap['Subcode'];

            // Process each row
            $dataRows = array_slice($rows, 1);
            $totalRows = count($dataRows);
            $updatedCount = 0;
            $notFoundCount = 0;
            $unchangedCount = 0;
            $errors = [];

            $this->info("Processing {$totalRows} row(s)...");
            $progressBar = $this->output->createProgressBar($totalRows);
            $progressBar->start();

            $updates = []; // Store updates to batch them

            foreach ($dataRows as $index => $row) {
                $rowNumber = $index + 2; // 1-based sheet row number (header + 1)

                if (empty($row) || !isset($row[$subcodeColumnIndex])) {
                    $unchangedCount++;
                    $progressBar->advance();
                    continue;
                }

                $oldSubcode = trim($row[$subcodeColumnIndex] ?? '');
                if (empty($oldSubcode)) {
                    $unchangedCount++;
                    $progressBar->advance();
                    continue;
                }

                try {
                    // Step 1: Find subcode in if_gl.json to get the name
                    if (!isset($ifGlMap[$oldSubcode])) {
                        // Subcode not found in old system - might already be normalized or invalid
                        $notFoundCount++;
                        $errors[] = [
                            'row' => $rowNumber,
                            'old_subcode' => $oldSubcode,
                            'error' => 'Subcode not found in if_gl.json'
                        ];
                        $progressBar->advance();
                        continue;
                    }

                    $accountName = $ifGlMap[$oldSubcode];

                    // Step 2: Find account name in ns_gl.json to get new account_number
                    if (!isset($nsGlMap[$accountName])) {
                        // Account name not found in new system
                        $notFoundCount++;
                        $errors[] = [
                            'row' => $rowNumber,
                            'old_subcode' => $oldSubcode,
                            'account_name' => $accountName,
                            'error' => 'Account name not found in ns_gl.json'
                        ];
                        $progressBar->advance();
                        continue;
                    }

                    $newSubcode = $nsGlMap[$accountName];

                    // If subcode is the same, skip update
                    if ($oldSubcode === $newSubcode) {
                        $unchangedCount++;
                        $progressBar->advance();
                        continue;
                    }

                    // Store update
                    $updates[] = [
                        'row' => $rowNumber,
                        'column' => $subcodeColumnIndex + 1, // 1-based column
                        'old_subcode' => $oldSubcode,
                        'new_subcode' => $newSubcode,
                        'account_name' => $accountName
                    ];

                    $updatedCount++;
                } catch (\Exception $e) {
                    $errors[] = [
                        'row' => $rowNumber,
                        'old_subcode' => $oldSubcode,
                        'error' => $e->getMessage()
                    ];
                    $notFoundCount++;
                }

                $progressBar->advance();
            }

            $progressBar->finish();
            $this->newLine(2);

            // Display summary
            $this->info("Summary:");
            $this->info("  Total rows processed: {$totalRows}");
            $this->info("  To be updated: {$updatedCount}");
            $this->info("  Unchanged: {$unchangedCount}");
            $this->info("  Not found/Errors: {$notFoundCount}");

            if (!empty($errors)) {
                $this->newLine();
                $this->warn("Errors/Warnings:");
                foreach (array_slice($errors, 0, 10) as $error) {
                    $errorMsg = "Row {$error['row']}: {$error['error']}";
                    if (isset($error['old_subcode'])) {
                        $errorMsg .= " (Subcode: {$error['old_subcode']})";
                    }
                    if (isset($error['account_name'])) {
                        $errorMsg .= " (Account: {$error['account_name']})";
                    }
                    $this->warn("  " . $errorMsg);
                }
                if (count($errors) > 10) {
                    $this->warn("  ... and " . (count($errors) - 10) . " more");
                }
            }

            // Show preview of updates
            if (!empty($updates)) {
                $this->newLine();
                $this->info("Preview of changes:");
                foreach (array_slice($updates, 0, 10) as $update) {
                    $this->line("  Row {$update['row']}: '{$update['old_subcode']}' → '{$update['new_subcode']}' ({$update['account_name']})");
                }
                if (count($updates) > 10) {
                    $this->line("  ... and " . (count($updates) - 10) . " more");
                }
            }

            // Apply updates if not dry run
            if (!$isDryRun && !empty($updates)) {
                $this->newLine();
                $this->info("Applying updates to {$sheetName} sheet...");

                // Convert column index to letter (A, B, C, etc.)
                $columnLetter = $this->columnIndexToLetter($subcodeColumnIndex);

                // Update cells in batches to avoid rate limits
                $batchSize = 50;
                $batches = array_chunk($updates, $batchSize);

                foreach ($batches as $batchIndex => $batch) {
                    $this->line("  Processing batch " . ($batchIndex + 1) . " of " . count($batches) . " (" . count($batch) . " updates)...");

                    // Sort by row number for contiguous updates where possible
                    usort($batch, function ($a, $b) {
                        return $a['row'] - $b['row'];
                    });

                    // Group contiguous rows for batch update
                    $contiguousRanges = [];
                    $currentRange = null;

                    foreach ($batch as $update) {
                        if ($currentRange === null) {
                            $currentRange = [
                                'start_row' => $update['row'],
                                'end_row' => $update['row'],
                                'values' => [$update['new_subcode']]
                            ];
                        } elseif ($update['row'] === $currentRange['end_row'] + 1) {
                            // Contiguous, add to current range
                            $currentRange['end_row'] = $update['row'];
                            $currentRange['values'][] = $update['new_subcode'];
                        } else {
                            // Not contiguous, save current range and start new one
                            $contiguousRanges[] = $currentRange;
                            $currentRange = [
                                'start_row' => $update['row'],
                                'end_row' => $update['row'],
                                'values' => [$update['new_subcode']]
                            ];
                        }
                    }
                    if ($currentRange !== null) {
                        $contiguousRanges[] = $currentRange;
                    }

                    // Update each contiguous range
                    foreach ($contiguousRanges as $range) {
                        // Don't include sheet name in range - updateRange() method adds it
                        $rangeStr = "{$columnLetter}{$range['start_row']}:{$columnLetter}{$range['end_row']}";
                        $values = array_map(function ($v) {
                            return [$v];
                        }, $range['values']);
                        $sheetsService->updateRange($sheetName, $rangeStr, $values);
                    }

                    // Small delay between batches
                    if ($batchIndex < count($batches) - 1) {
                        usleep(300000); // 0.3 seconds
                    }
                }

                $this->info("✓ Successfully updated {$updatedCount} subcode(s)");
            } elseif ($isDryRun && !empty($updates)) {
                $this->newLine();
                $this->warn("DRY RUN: {$updatedCount} subcode(s) would be updated (use without --dry-run to apply changes)");
            }

            $this->info("Normalization completed!");

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error('Error normalizing Bill subcodes: ' . $e->getMessage());
            Log::error('Bill subcode normalization error: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }

    protected function columnIndexToLetter(int $index): string
    {
        $letter = '';
        $index++; // Convert to 1-based

        while ($index > 0) {
            $index--;
            $letter = chr(65 + ($index % 26)) . $letter;
            $index = intval($index / 26);
        }

        return $letter;
    }

    protected function getBillSpreadsheetId(string $environment): string
    {
        if ($environment === 'production') {
            $id = config('google-sheets.prod_bill_spreadsheet_id');
        } else {
            $id = config('google-sheets.bill_spreadsheet_id');
        }

        if (empty($id)) {
            $envVar = $environment === 'production' ? 'PROD_BILL_SHEET_ID' : 'SANDBOX_BILL_SHEET_ID';
            throw new \Exception("Bill Spreadsheet ID not configured. Set {$envVar} in .env");
        }

        return $id;
    }
}


