<?php

use App\Jobs\E2eMarkerJob;
use Aws\DynamoDb\DynamoDbClient;
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
    try {
        // The default disk with `throw` on: a failed write answers with S3's error instead of `false`.
        $disk = Storage::build(['throw' => true] + config('filesystems.disks.' . config('filesystems.default')));
        $disk->put('e2e/check.txt', $value);

        return response()->json(['ok' => $disk->get('e2e/check.txt') === $value]);
    } catch (Throwable $e) {
        // Messages (bucket, key, endpoint) only against a local emulator; on AWS just the error code or class.
        $errors = [];
        for ($error = $e; $error !== null; $error = $error->getPrevious()) {
            $code = $error instanceof Aws\Exception\AwsException ? $error->getAwsErrorCode() : null;
            $errors[] = (string) getenv('AWS_ENDPOINT_URL') !== '' ? get_class($error) . ': ' . $error->getMessage() : ($code ?? get_class($error));
        }

        return response()->json(['ok' => false, 'error' => implode(' <- ', $errors)], 500);
    }
});

// ->table('state'): write an item to the table ARCO_TABLES names, then read it back.
Route::get('/e2e/table', function () {
    $table = config('services.arco.tables.state');
    if (! is_string($table)) {
        return response()->json(['ok' => false, 'error' => 'ARCO_TABLES has no "state" table'], 500);
    }
    $value = bin2hex(random_bytes(8));
    try {
        $dynamo = new DynamoDbClient(['region' => (string) (getenv('AWS_REGION') ?: 'us-east-1'), 'version' => 'latest']);
        $key = ['PK' => ['S' => 'e2e'], 'SK' => ['S' => 'check']];
        $dynamo->putItem(['TableName' => $table, 'Item' => $key + [
            'value' => ['S' => $value],
            'expires_at' => ['N' => (string) (time() + 3600)],
        ]]);
        $item = $dynamo->getItem(['TableName' => $table, 'Key' => $key, 'ConsistentRead' => true])['Item'] ?? [];

        return response()->json(['ok' => ($item['value']['S'] ?? null) === $value, 'table' => $table]);
    } catch (Throwable $e) {
        // As /e2e/storage: messages only against a local emulator; on AWS just the error code or class.
        $code = $e instanceof Aws\Exception\AwsException ? $e->getAwsErrorCode() : null;
        $error = (string) getenv('AWS_ENDPOINT_URL') !== '' ? get_class($e) . ': ' . $e->getMessage() : ($code ?? get_class($e));

        return response()->json(['ok' => false, 'table' => $table, 'error' => $error], 500);
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
