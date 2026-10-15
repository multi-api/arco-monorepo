<?php declare(strict_types=1);

// A minimal Lambda Runtime API for tests, served by `php -S`. State lives in ARCO_EMULATOR_DIR:
// queue/<id>.json (events to hand out, in name order), responses/<id>.json, errors/<id>.json,
// init-error.json, and a "stop" file that ends a waiting /next with HTTP 410.
$dir = (string) getenv('ARCO_EMULATOR_DIR');
$path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$method = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');
$body = (string) file_get_contents('php://input');
$errorType = $_SERVER['HTTP_LAMBDA_RUNTIME_FUNCTION_ERROR_TYPE'] ?? null;

if ($method === 'GET' && $path === '/2018-06-01/runtime/invocation/next') {
    while (! is_file("$dir/stop")) {
        $queued = glob("$dir/queue/*.json") ?: [];
        sort($queued);
        if ($queued !== []) {
            $id = basename($queued[0], '.json');
            $event = (string) file_get_contents($queued[0]);
            unlink($queued[0]);
            header("Lambda-Runtime-Aws-Request-Id: $id");
            header('Lambda-Runtime-Deadline-Ms: ' . (int) floor(microtime(true) * 1000 + (int) (getenv('ARCO_EMULATOR_TIMEOUT_MS') ?: 120000)));
            header('Content-Type: application/json');
            echo $event;
            return;
        }
        usleep(50_000);
    }
    http_response_code(410);
    return;
}
if ($method === 'POST' && preg_match('#^/2018-06-01/runtime/invocation/([^/]+)/(response|error)$#', $path, $m) === 1) {
    $folder = $m[2] === 'response' ? 'responses' : 'errors';
    file_put_contents("$dir/$folder/{$m[1]}.json", json_encode(['type' => $errorType, 'body' => $body]));
    http_response_code(202);
    return;
}
if ($method === 'POST' && $path === '/2018-06-01/runtime/init/error') {
    file_put_contents("$dir/init-error.json", json_encode(['type' => $errorType, 'body' => $body]));
    http_response_code(202);
    return;
}
http_response_code(404);
