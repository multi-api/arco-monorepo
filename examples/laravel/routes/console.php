<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Proves the scheduler Lambda ticks (checked by /e2e/schedule).
Schedule::call(fn () => Cache::put('e2e-schedule', now()->toIso8601String(), 3600))->everyMinute();
