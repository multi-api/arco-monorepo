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
            try {
                $event = json_decode($invocation['body'], true, flags: JSON_THROW_ON_ERROR);
                if (! is_array($event)) {
                    throw new \InvalidArgumentException('The event is not a JSON object.');
                }
                $remainingMs = $invocation['deadlineMs'] - (int) floor(microtime(true) * 1000);
                $result = $worker->handle($event, $remainingMs);
                $api->respond($invocation['requestId'], json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
            } catch (Throwable $e) {
                $this->report($e);
                $api->invocationError($invocation['requestId'], $e::class, $e->getMessage());
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
