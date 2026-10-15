<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Used by Arco's integration and end-to-end tests.
Route::get('/arco-check', fn () => response()->json([
    'sapi' => PHP_SAPI,
    'storage' => storage_path(),
    'environment' => env('ARCO_ENV'),
    'scheme' => request()->getScheme(),
]));

Route::get('/arco-slow', function () {
    usleep(min((int) request('ms', 300), 5000) * 1000);
    return response()->json(['ok' => true]);
});
