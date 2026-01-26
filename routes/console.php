<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
    Log::info('Inspiring quote: ' . Inspiring::quote());
})->purpose('Display an inspiring quote')->everyMinute();
