<?php declare(strict_types=1);

namespace Multiapi\Arco\Laravel\Queue;

use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobTimedOut;
use Illuminate\Queue\Worker;
use Illuminate\Queue\WorkerOptions;

/**
 * Laravel's worker, plus a time limit for one job that does not kill the process: when the limit is
 * reached the job is handled like a job that timed out under `queue:work` (failed if out of tries, else
 * released) and the TimeoutExceededException is thrown, so the rest of the Lambda batch still runs.
 *
 * @internal
 */
final class TimedWorker extends Worker
{
    private ?Job $timing = null;

    /** @throws \Throwable what Worker::process throws */
    public function processWithin(string $connectionName, Job $job, WorkerOptions $options, int $seconds): void
    {
        if (! function_exists('pcntl_alarm') || ! function_exists('pcntl_signal') || ! function_exists('pcntl_async_signals')) {
            $this->process($connectionName, $job, $options);
            return;
        }
        $async = pcntl_async_signals(true);
        $this->timing = $job;
        pcntl_signal(SIGALRM, function () use ($job, $options, $seconds): void {
            if ($this->timing !== $job) {
                return;
            }
            $this->timing = null;
            $e = $this->timeoutExceededException($job);
            $this->markJobAsFailedIfWillExceedMaxAttempts($job->getConnectionName(), $job, (int) $options->maxTries, $e);
            $this->markJobAsFailedIfWillExceedMaxExceptions($job->getConnectionName(), $job, $e);
            $this->markJobAsFailedIfItShouldFailOnTimeout($job->getConnectionName(), $job, $e);
            $this->events->dispatch(new JobTimedOut($job->getConnectionName(), $job, $seconds));
            throw $e;
        });
        pcntl_alarm(max(1, $seconds));
        try {
            $this->process($connectionName, $job, $options);
        } finally {
            pcntl_alarm(0);
            $this->timing = null;
            pcntl_signal(SIGALRM, SIG_DFL);
            pcntl_async_signals($async);
        }
    }
}
