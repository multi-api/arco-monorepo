<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Unit\Commands;

use Aws\CommandInterface;
use Aws\MockHandler;
use Aws\Result;
use Multiapi\Arco\Aws\AwsClients;
use Multiapi\Arco\Aws\DnsZoneFinder;
use Psr\Http\Message\RequestInterface;

/** A DnsZoneFinder whose profiles answer from canned Route 53 / STS responses. */
final class FakeZones
{
    /** @var list<string> profile:operation */
    public array $calls = [];

    /** @param array<string, list<mixed>> $responses per profile */
    public function __construct(private readonly array $responses)
    {
    }

    /**
     * Responses for a profile whose Route 53 holds $zones (name => id) in $account.
     *
     * @param array<string, string> $zones
     * @return list<Result>
     */
    public static function holding(string $account, array $zones): array
    {
        $list = [];
        foreach ($zones as $name => $id) {
            $list[] = ['Id' => $id, 'Name' => "$name.", 'Config' => ['PrivateZone' => false]];
        }
        return [new Result(['HostedZones' => $list, 'IsTruncated' => false]), new Result(['Account' => $account])];
    }

    /** @return list<Result> */
    public static function empty(): array
    {
        return [new Result(['HostedZones' => [], 'IsTruncated' => false])];
    }

    public function finder(): DnsZoneFinder
    {
        // Every search builds new clients, so each one replays the profile's responses.
        return new DnsZoneFinder(function (string $profile): AwsClients {
            $mock = new MockHandler($this->responses[$profile] ?? []);
            return new AwsClients(null, 'us-east-1', [
                'handler' => function (CommandInterface $command, RequestInterface $request) use ($mock, $profile) {
                    $this->calls[] = "$profile:" . $command->getName();
                    return $mock($command, $request);
                },
                'credentials' => false,
            ]);
        });
    }
}
