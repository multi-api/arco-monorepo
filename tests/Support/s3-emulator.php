<?php declare(strict_types=1);

// Minimal S3 GetObject for tests (path style: GET /<bucket>/<key>), served by `php -S`. Objects are
// files under ARCO_S3_DIR/objects/<bucket>/<key>; each request is appended to ARCO_S3_DIR/requests.jsonl.
$dir = (string) getenv('ARCO_S3_DIR');
$path = rawurldecode((string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH));
file_put_contents("$dir/requests.jsonl", json_encode([
    'path' => $path,
    'query' => (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_QUERY),
    'authorization' => (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? ''),
    'token' => (string) ($_SERVER['HTTP_X_AMZ_SECURITY_TOKEN'] ?? ''),
]) . "\n", FILE_APPEND);
$file = "$dir/objects" . $path;
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET' || str_contains($path, '..') || ! is_file($file)) {
    http_response_code(404);
    return;
}
header('Content-Type: application/octet-stream');
header('Content-Length: ' . filesize($file));
readfile($file);
