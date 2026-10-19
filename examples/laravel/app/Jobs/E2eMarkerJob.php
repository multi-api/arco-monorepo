<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Cache;

/** Marks itself done in the cache so /e2e/job/{id} can prove the queue worker ran it. */
class E2eMarkerJob implements ShouldQueue
{
    use Dispatchable;

    public function __construct(public readonly string $id) {}

    public function handle(): void
    {
        Cache::put("e2e-job-{$this->id}", 'done', 600);
    }
}
