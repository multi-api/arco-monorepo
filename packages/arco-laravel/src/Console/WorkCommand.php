<?php declare(strict_types=1);

namespace Multiapi\Arco\Laravel\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Application;
use Multiapi\Arco\Laravel\Env;
use Multiapi\Arco\Laravel\Queue\LambdaWorker;
use Multiapi\Arco\Laravel\Queue\RuntimeApiClient;
use Throwable;

/**
 * The loop of a queue worker function: Laravel boots once per execution environment and this command
 * serves every invocation (an SQS batch) through the Lambda Runtime API. Arco's worker runtime starts
 * it; it is not meant to be run by hand.
 */
final class WorkCommand extends Command
{
    protected $signature = 'arco:work';
    protected $description = 'Run the jobs Lambda hands over from SQS, through the Lambda Runtime API (internal)';
    protected $hidden = true;

    private const MAX_LAMBDA_MS = 900_000;
    /** Exit codes: the process ends on purpose so Lambda starts a fresh one. */
    private const TIMEOUT_EXIT = 3;
    private const DEADLINE_EXIT = 4;

    public function handle(): int
    {
        $endpoint = Env::get('AWS_LAMBDA_RUNTIME_API');
        if ($endpoint === null || ! $this->laravel instanceof Application) {
            $this->error('arco:work runs inside an Arco queue worker function (AWS_LAMBDA_RUNTIME_API is not set).');
            return self::FAILURE;
        }
        $api = new RuntimeApiClient($endpoint);
        $worker = new LambdaWorker($this->laravel);
        for (;;) {
            // A failure to reach the Runtime API ends the process; Lambda starts a new one.
            $invocation = $api->next();
            $requestId = $invocation['requestId'];
            if ($invocation['traceId'] !== null) {
                putenv("_X_AMZN_TRACE_ID={$invocation['traceId']}");
            }
            $nowMs = (int) floor(microtime(true) * 1000);
            // Without a deadline header, assume Lambda's longest timeout.
            $remainingMs = $invocation['deadlineMs'] > 0 ? $invocation['deadlineMs'] - $nowMs : self::MAX_LAMBDA_MS;
            try {
                $event = json_decode($invocation['body'], true, flags: JSON_THROW_ON_ERROR);
                if (! is_array($event)) {
                    throw new \InvalidArgumentException('The event is not a JSON object.');
                }
                $result = $worker->handle($event, $remainingMs, static function (array $result) use ($api, $requestId): void {
                    // A job kept running past its time limit: answer for the batch before Lambda's deadline
                    // kills it, and leave; the app may be in any state.
                    LambdaWorker::stderr(['arco' => 'worker_deadline', 'reported' => count($result['batchItemFailures'])]);
                    try {
                        $api->respond($requestId, json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
                    } finally {
                        exit(self::DEADLINE_EXIT);
                    }
                });
            } catch (Throwable $e) {
                $this->report($e);
                $api->invocationError($requestId, $e::class, $e->getMessage());
                continue;
            }
            $api->respond($requestId, json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
            if ($worker->timedOut()) {
                // Like `queue:work` killing a timed-out job: the next invocation boots a clean app.
                LambdaWorker::stderr(['arco' => 'worker_restart', 'reason' => 'timeout']);
                return self::TIMEOUT_EXIT;
            }
        }
    }

    private function report(Throwable $e): void
    {
        try {
            $this->laravel->make(ExceptionHandler::class)->report($e);
        } catch (Throwable) {
            // Reporting must never stop the loop.
        }
    }
}
