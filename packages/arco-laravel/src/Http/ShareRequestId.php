<?php declare(strict_types=1);

namespace Multiapi\Arco\Laravel\Http;

use Closure;
use Illuminate\Http\Request;
use Psr\Log\LoggerInterface;

/**
 * Adds the request id to every log line of the request, so all lines of one request can be found
 * together in CloudWatch. Uses the caller's X-Request-ID when present, else the id or trace id the
 * AWS front end adds.
 */
final class ShareRequestId
{
    public const HEADERS = ['X-Request-ID', 'X-Amzn-RequestId', 'X-Amz-Request-Id', 'X-Amzn-Trace-Id'];

    public function __construct(private readonly LoggerInterface $log)
    {
    }

    public function handle(Request $request, Closure $next): mixed
    {
        $id = self::requestId($request);
        if ($id !== null && method_exists($this->log, 'shareContext')) {
            $this->log->shareContext(['requestId' => $id]);
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
