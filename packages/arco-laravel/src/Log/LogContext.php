<?php declare(strict_types=1);

namespace Multiapi\Arco\Laravel\Log;

use Illuminate\Contracts\Container\Container;

/**
 * The ids every log line of one unit of work carries in its context (`context.requestId`, `context.traceId`,
 * `context.job` in the JSON lines), so the lines of one request, command or job can be found together in
 * CloudWatch. `traceId` is an X-Ray trace id (`1-<8 hex>-<24 hex>`), the one the X-Ray console opens.
 */
final class LogContext
{
    /** Set by the console runtime for each command it runs: the Lambda invocation id. */
    public const REQUEST_ID = 'ARCO_REQUEST_ID';

    /**
     * The trace id of an X-Ray trace header (`Root=1-...;Parent=...;Sampled=...`): Lambda's _X_AMZN_TRACE_ID,
     * the X-Amzn-Trace-Id request header or an SQS message's AWSTraceHeader. Null without a valid Root.
     */
    public static function traceId(?string $header): ?string
    {
        foreach (explode(';', $header ?? '') as $part) {
            $pair = explode('=', trim($part), 2);
            if (count($pair) === 2 && strtolower($pair[0]) === 'root' && preg_match('/^1-[0-9a-f]{8}-[0-9a-f]{24}$/D', $pair[1]) === 1) {
                return $pair[1];
            }
        }
        return null;
    }

    /** The same id from a W3C traceparent (`00-<32 hex>-<16 hex>-<2 hex>`), the form multiapi/arco-xray puts in a job's payload. */
    public static function traceIdFromTraceparent(?string $traceparent): ?string
    {
        if ($traceparent === null || preg_match('/^[0-9a-f]{2}-([0-9a-f]{8})([0-9a-f]{24})-[0-9a-f]{16}-[0-9a-f]{2}$/D', trim($traceparent), $m) !== 1) {
            return null;
        }
        return "1-$m[1]-$m[2]";
    }

    /** @param array<string, string|null> $context added, without the null entries, to the context of every log line from here on */
    public static function share(Container $app, array $context): void
    {
        $context = array_filter($context, static fn (mixed $value): bool => $value !== null);
        if ($context === [] || ! $app->bound('log')) {
            return;
        }
        $log = $app->make('log');
        if (is_object($log) && method_exists($log, 'shareContext')) {
            $log->shareContext($context);
        }
    }

    /** @param array<string, string|null> $context like share(), dropping what earlier units of work shared first */
    public static function replace(Container $app, array $context): void
    {
        if ($app->bound('log')) {
            $log = $app->make('log');
            if (is_object($log) && method_exists($log, 'flushSharedContext')) {
                $log->flushSharedContext();
            }
        }
        self::share($app, $context);
    }
}
