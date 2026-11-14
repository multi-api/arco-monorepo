<?php declare(strict_types=1);

// Under ParaTest each worker gets its own temp directory, so a test that compares a temp-dir glob before and
// after (e.g. no `arco-build-*` staging left behind) never sees another worker's files. TEST_TOKEN is the worker
// slot ParaTest sets; plain PHPUnit leaves TMPDIR alone. This runs before anything calls sys_get_temp_dir(),
// which caches its answer, and child processes inherit TMPDIR.
$token = getenv('TEST_TOKEN');
if (is_string($token) && preg_match('/^\d+$/D', $token) === 1) {
    $base = getenv('TMPDIR');
    $dir = rtrim(is_string($base) && $base !== '' ? $base : '/tmp', '/') . '/paratest-' . $token;
    if (! is_dir($dir) && ! mkdir($dir, 0700, true) && ! is_dir($dir)) {
        throw new RuntimeException("Cannot create the worker temp directory $dir.");
    }
    putenv("TMPDIR=$dir");
}

require __DIR__ . '/../vendor/autoload.php';
