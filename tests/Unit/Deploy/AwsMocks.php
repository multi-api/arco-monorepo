<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Unit\Deploy;

use Aws\Command;
use Aws\CommandInterface;
use Aws\Exception\AwsException;
use Aws\MockHandler;
use GuzzleHttp\Psr7\Response;
use Multiapi\Arco\Aws\AwsClients;
use Multiapi\Arco\Deploy\Sleeper;
use Psr\Http\Message\RequestInterface;

final class AwsMocks
{
    /** @var list<string> */
    public array $calls = [];
    /** @var list<array<string, mixed>> */
    public array $params = [];
    /** @var list<string> request host per call */
    public array $hosts = [];
    public MockHandler $handler;

    public function __construct()
    {
        $this->handler = new MockHandler();
    }

    public function clients(string $region = 'us-east-1'): AwsClients
    {
        return new AwsClients(null, $region, [
            'handler' => function (CommandInterface $command, RequestInterface $request) {
                $this->calls[] = $command->getName();
                $this->params[] = $command->toArray();
                $this->hosts[] = $request->getUri()->getHost();
                return ($this->handler)($command, $request);
            },
            'credentials' => false,
            'validate' => false, // tests use short placeholder ARNs and empty payloads
        ]);
    }

    /**
     * @param class-string<AwsException> $class
     */
    /** @param string|null $fullMessage getMessage() text when it differs from the AWS error message (the SDK adds request context) */
    public static function error(string $class, string $operation, string $code, int $status, string $message = 'error', ?string $fullMessage = null): AwsException
    {
        return new $class($fullMessage ?? $message, new Command($operation), ['code' => $code, 'message' => $message, 'response' => new Response($status)]);
    }

    public static function noSleep(): Sleeper
    {
        return new class extends Sleeper {
            public int $slept = 0;

            public function sleep(int $seconds): void
            {
                $this->slept += $seconds;
            }

            public function now(): float
            {
                return 1_000_000.0 + $this->slept;
            }
        };
    }
}
