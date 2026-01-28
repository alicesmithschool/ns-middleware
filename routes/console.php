<?php

use Illuminate\Support\Facades\Schedule;

// Sync vendors every 30 minutes
Schedule::command('netsuite:sync-vendors  --use-rest')
    ->everyThirtyMinutes()
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/scheduler.log'));

// Sync vendors to Kissflow hourly
Schedule::command('netsuite:sync-vendors-to-kissflow')
    ->hourly()
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/scheduler.log'));

// Sync employees hourly
Schedule::command('netsuite:sync-employees')
    ->hourly()
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/scheduler.log'));

// Sync items hourly
Schedule::command('netsuite:sync-items')
    ->hourly()
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/scheduler.log'));

// Sync POs from sheets hourly
Schedule::command('netsuite:sync-pos-from-sheets')
    ->hourly()
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/scheduler.log'));

// Sync departments (budget codes) daily
Schedule::command('netsuite:sync-departments')
    ->daily()
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/scheduler.log'));

// Sync accounts (GL code) daily
Schedule::command('netsuite:sync-accounts')
    ->daily()
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/scheduler.log'));

// push vendors from sheets every 15th minute of the hour 
Schedule::command('netsuite:push-vendors-from-sheets')
    ->cron('15 * * * *')
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/scheduler.log'));

// pull purchase orders for journal entries every 40th minute of the hour
Schedule::command('journal:get-ns-po')
    ->cron('40 * * * *')
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/scheduler.log'));