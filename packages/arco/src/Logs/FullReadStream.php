<?php declare(strict_types=1);

namespace Multiapi\Arco\Logs;

use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\StreamDecoratorTrait;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;

/**
 * A response body whose read($n) returns exactly $n bytes unless the stream ends. The SDK's
 * event-stream decoder reads each message's trailing CRC with a single read(4), which fails with
 * "Message checksum mismatch" whenever the network delivers fewer bytes (see LiveTailSpikeTest).
 */
final class FullReadStream implements StreamInterface
{
    use StreamDecoratorTrait;

    /** @var StreamInterface */
    private $stream;

    public function __construct(StreamInterface $stream)
    {
        $this->stream = $stream;
    }

    /** Wraps an SDK http_handler: event-stream response bodies get full reads, others are untouched. */
    public static function wrap(callable $http): \Closure
    {
        return static function (RequestInterface $request, array $options) use ($http): PromiseInterface {
            $promise = $http($request, $options);
            if (! $promise instanceof PromiseInterface) {
                throw new \UnexpectedValueException('The http_handler must return a promise.');
            }
            return $promise->then(static function (mixed $response): ResponseInterface {
                if (! $response instanceof ResponseInterface) {
                    throw new \UnexpectedValueException('The http_handler must resolve to a response.');
                }
                return str_starts_with($response->getHeaderLine('Content-Type'), 'application/vnd.amazon.eventstream')
                    ? $response->withBody(new self($response->getBody()))
                    : $response;
            });
        };
    }

    /** @param int $length */
    public function read($length): string
    {
        $data = '';
        while (strlen($data) < $length) {
            $chunk = $this->stream->read($length - strlen($data));
            if ($chunk === '') {
                if ($this->stream->eof()) {
                    break;
                }
                usleep(1000);
                continue;
            }
            $data .= $chunk;
        }
        return $data;
    }
}
