<?php declare(strict_types=1);

namespace Multiapi\Arco\Laravel\Queue;

use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobTimedOut;
use Illuminate\Queue\Worker;
use Illuminate\Queue\WorkerOptions;

/**
 * Laravel's worker, plus two alarms:
 *
 * - a time limit for one job: when it is reached the job is handled like a job that timed out under
 *   `queue:work` (failed if out of tries, else released) and the TimeoutExceededException is thrown,
 *   so the rest of the Lambda batch still runs;
 * - a backstop for the whole invocation (shortly before the Lambda deadline), for a job that swallows
 *   the timeout or does not give control back: its callback answers for the batch and ends the process.
 *
 * Both share SIGALRM (one alarm per process): the nearest one is armed. A signal only lands between
 * PHP opcodes, so a job blocked inside one C call (curl, a DB read) is only stopped when it returns.
 *
 * @internal
 */
final class TimedWorker extends Worker
{
    private ?Job $timing = null;
    private ?WorkerOptions $timingOptions = null;
    private int $timingSeconds = 0;
    private float $jobDeadline = INF;
    private float $hardDeadline = INF;
    /** @var (\Closure(): void)|null */
    private ?\Closure $backstop = null;
    private ?string $armed = null;
    private bool $timedOut = false;
    private ?bool $previousAsync = null;
    private static bool $warned = false;

    public static function supported(): bool
    {
        return function_exists('pcntl_alarm') && function_exists('pcntl_signal') && function_exists('pcntl_async_signals');
    }

    /**
     * Starts an invocation: $backstop runs (and must end the process) at $hardDeadline (microtime).
     *
     * @param (\Closure(): void)|null $backstop
     */
    public function startInvocation(float $hardDeadline, ?\Closure $backstop): void
    {
        $this->timedOut = false;
        $this->hardDeadline = $backstop === null ? INF : $hardDeadline;
        $this->backstop = $backstop;
        if (! self::supported()) {
            if (! self::$warned) {
                self::$warned = true;
                file_put_contents('php://stderr', '{"arco":"worker_no_time_limit","reason":"the pcntl extension is missing"}' . "\n");
            }
            return;
        }
        $this->previousAsync = pcntl_async_signals(true);
        pcntl_signal(SIGALRM, function (): void {
            $this->onAlarm();
        });
        $this->arm();
    }

    public function endInvocation(): void
    {
        if ($this->previousAsync === null) {
            return;
        }
        pcntl_alarm(0);
        $this->armed = null;
        $this->backstop = null;
        $this->hardDeadline = INF;
        pcntl_signal(SIGALRM, SIG_DFL);
        pcntl_async_signals($this->previousAsync);
        $this->previousAsync = null;
    }

    /** Whether a job of the current (or last) invocation hit its time limit. */
    public function timedOut(): bool
    {
        return $this->timedOut;
    }

    /**
     * Runs the job within $seconds, or within the job's own timeout when that is shorter.
     *
     * @throws \Throwable what Worker::process throws
     */
    public function processWithin(string $connectionName, Job $job, WorkerOptions $options, int $seconds): void
    {
        $own = $job->timeout();
        $seconds = max(1, is_numeric($own) && (int) $own > 0 ? min((int) $own, $seconds) : $seconds);
        if ($this->previousAsync === null) {
            $this->process($connectionName, $job, $options);
            return;
        }
        $this->timing = $job;
        $this->timingOptions = $options;
        $this->timingSeconds = $seconds;
        $this->jobDeadline = microtime(true) + $seconds;
        $this->arm();
        try {
            $this->process($connectionName, $job, $options);
        } finally {
            $this->timing = null;
            $this->timingOptions = null;
            $this->jobDeadline = INF;
            $this->arm();
        }
    }

    private function arm(): void
    {
        $at = min($this->jobDeadline, $this->hardDeadline);
        if ($at === INF) {
            pcntl_alarm(0);
            $this->armed = null;
            return;
        }
        $this->armed = $this->timing !== null && $at === $this->jobDeadline ? 'job' : 'hard';
        // Whole seconds only: the job's limit is rounded, the backstop never fires late.
        $delta = $at - microtime(true);
        pcntl_alarm(max(1, (int) ($this->armed === 'job' ? round($delta) : floor($delta))));
    }

    private function onAlarm(): void
    {
        if ($this->armed === 'hard') {
            $this->armed = null;
            if ($this->backstop !== null) {
                ($this->backstop)();
            }
            return;
        }
        $job = $this->timing;
        $options = $this->timingOptions;
        $this->timing = null;
        $this->jobDeadline = INF;
        $this->arm();
        // A job that already finished (the alarm raced its end) is left alone.
        if ($job === null || $options === null || $job->isDeleted()) {
            return;
        }
        $this->timedOut = true;
        $e = $this->timeoutExceededException($job);
        $this->markJobAsFailedIfWillExceedMaxAttempts($job->getConnectionName(), $job, (int) $options->maxTries, $e);
        $this->markJobAsFailedIfWillExceedMaxExceptions($job->getConnectionName(), $job, $e);
        $this->markJobAsFailedIfItShouldFailOnTimeout($job->getConnectionName(), $job, $e);
        $this->events->dispatch(new JobTimedOut($job->getConnectionName(), $job, $this->timingSeconds));
        throw $e;
    }
}
