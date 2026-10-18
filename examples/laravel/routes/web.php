<?php

use App\Jobs\E2eMarkerJob;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

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

// End-to-end checks (Floci and real AWS): each one exercises a resource Arco provisioned.
Route::get('/e2e/cache', function () {
    $value = bin2hex(random_bytes(8));
    Cache::put('e2e-cache', $value, 60);

    // DynamoDB hands numeric strings back as integers, so compare as strings.
    return response()->json(['ok' => (string) Cache::get('e2e-cache') === $value]);
});

Route::get('/e2e/storage', function () {
    $value = bin2hex(random_bytes(8));
    // The default disk with `throw` on: a failed write answers with S3's error instead of `false`.
    $disk = Storage::build(['throw' => true] + config('filesystems.disks.' . config('filesystems.default')));
    try {
        $disk->put('e2e/check.txt', $value);

        return response()->json(['ok' => $disk->get('e2e/check.txt') === $value]);
    } catch (Throwable $e) {
        $errors = [];
        for ($error = $e; $error !== null; $error = $error->getPrevious()) {
            $errors[] = get_class($error) . ': ' . $error->getMessage();
        }

        return response()->json(['ok' => false, 'error' => implode(' <- ', $errors)], 500);
    }
});

Route::get('/e2e/dispatch', function () {
    $id = bin2hex(random_bytes(4));
    E2eMarkerJob::dispatch($id);

    return response()->json(['id' => $id]);
});

Route::get('/e2e/job/{id}', fn (string $id) => response()->json([
    'status' => Cache::get("e2e-job-$id"),
]));

Route::get('/e2e/schedule', fn () => response()->json([
    'last' => Cache::get('e2e-schedule'),
]));
