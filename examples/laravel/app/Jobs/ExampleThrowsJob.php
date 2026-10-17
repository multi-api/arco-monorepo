<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

/** Used by Arco's worker runtime integration test (tests/Integration/WorkerRuntimeTest.php). */
class ExampleThrowsJob implements ShouldQueue
{
    use InteractsWithQueue;

    public function handle(): void
    {
        self::trace('ran');
        throw new \RuntimeException('The example job always fails.');
    }

    /** One JSON line on stderr (the function log), with the PID that proves how many times the app booted. */
    private static function trace(string $event): void
    {
        file_put_contents('php://stderr', json_encode(['example_job' => static::class, 'event' => $event, 'pid' => getmypid()]) . "\n");
    }
}
