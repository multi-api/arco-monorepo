<?php declare(strict_types=1);

namespace Multiapi\Arco\Laravel\Http;

use Closure;
use Illuminate\Http\Request;
use Multiapi\Arco\Laravel\Log\LogContext;
use Psr\Log\LoggerInterface;

/**
 * Adds the request id to every log line of the request, so all lines of one request can be found
 * together in CloudWatch. Uses the caller's X-Request-ID when present, else the other ids or trace id a
 * caller may send. Without any, Arco's web runtime adds an X-Request-ID (Lambda Web Functions add none), the
 * id of the request's access log line. With an X-Amzn-Trace-Id, its X-Ray trace id is shared too (`traceId`).
 */
final class ShareRequestId
{
    public const HEADERS = ['X-Request-ID', 'X-Amzn-RequestId', 'X-Amz-Request-Id', 'X-Amzn-Trace-Id'];

    public function __construct(private readonly LoggerInterface $log)
    {
    }

    public function handle(Request $request, Closure $next): mixed
    {
        $context = array_filter(
            ['requestId' => self::requestId($request), 'traceId' => LogContext::traceId($request->headers->get('X-Amzn-Trace-Id'))],
            static fn (?string $value): bool => $value !== null,
        );
        if ($context !== [] && method_exists($this->log, 'shareContext')) {
            $this->log->shareContext($context);
        }
        return $next($request);
    }

    public static function requestId(Request $request): ?string
    {
        foreach (self::HEADERS as $header) {
            $value = $request->headers->get($header);
            if (is_string($value) && $value !== '') {
                return substr($value, 0, 200);
            }
        }
        return null;
    }
}
