<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

// Artisan::command('inspire', function () {
//     $this->comment(Inspiring::quote());
//     Log::info('Inspiring quote: ' . Inspiring::quote());
// })->purpose('Display an inspiring quote')->everyMinute();

// sync vendors every 30 minutes
Artisan::command('netsuite:sync-vendors', function () {
    Log::info('Vendors synced');
})->everyThirtyMinutes();

Artisan::command('netsuite:sync-vendors-to-kissflow', function () {
    Log::info('Vendors synced to kissflow');
})->hourly();

// sync employees
Artisan::command('netsuite:sync-employees', function () {
    Log::info('Employees synced');
})->hourly();

// sync items
Artisan::command('netsuite:sync-items', function () {
    Log::info('Items synced');
})->hourly();

Artisan::command('netsuite:sync-pos-from-sheets', function () {
    Log::info('POs synced to NetSuite');
})->hourly();

// sync departments (budget codes)
Artisan::command('netsuite:sync-departments', function () {
    Log::info('Departments synced');
})->daily();

// sync accounts (GL code)
Artisan::command('netsuite:sync-accounts', function () {
    Log::info('Accounts synced');
})->daily();

