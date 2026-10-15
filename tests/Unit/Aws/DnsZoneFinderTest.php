<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Unit\Aws;

use Aws\CommandInterface;
use Aws\MockHandler;
use Aws\Result;
use Aws\Route53\Exception\Route53Exception;
use Multiapi\Arco\Aws\AwsClients;
use Multiapi\Arco\Aws\DnsZoneFinder;
use Multiapi\Arco\Tests\Unit\Deploy\AwsMocks;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

final class DnsZoneFinderTest extends TestCase
{
    /** @var array<string, list<string>> */
    private array $calls = [];

    public function test_candidates_are_every_suffix_with_two_labels(): void
    {
        self::assertSame(['api-arco.acmeapp.io', 'acmeapp.io'], DnsZoneFinder::candidates('api-arco.acmeapp.io'));
        self::assertSame(['acmeapp.io'], DnsZoneFinder::candidates('acmeapp.io'));
        self::assertSame(['a.b.example.com', 'b.example.com', 'example.com'], DnsZoneFinder::candidates('A.b.example.com.'));
    }

    public function test_finds_the_longest_public_zone_in_the_one_profile_it_is_given(): void
    {
        $finder = $this->finder([
            'common' => [self::zones([['/hostedzone/Z1', 'acmeapp.io.'], ['/hostedzone/ZP', 'api-arco.acmeapp.io.', true], ['/hostedzone/Z2', 'shop.acmeapp.io.'], ['/hostedzone/Z3', 'cl.']]), self::account('222222222222')],
            'other' => [],
        ]);

        $search = $finder->find(['arco.acmeapp.io', 'api-arco.acmeapp.io', 'www.shop.acmeapp.io', 'x.cl'], 'common');

        self::assertSame(['common'], array_keys($this->calls));
        self::assertSame(['ListHostedZonesByName', 'GetCallerIdentity'], $this->calls['common']);
        self::assertNull($search->error);
        self::assertSame('acmeapp.io', $search->found['arco.acmeapp.io']->zoneName);
        self::assertSame('acmeapp.io', $search->found['api-arco.acmeapp.io']->zoneName); // the private zone is ignored
        self::assertSame('/hostedzone/Z2', $search->found['www.shop.acmeapp.io']->zoneId);
        self::assertSame('222222222222', $search->found['www.shop.acmeapp.io']->account);
        self::assertSame('common', $search->found['www.shop.acmeapp.io']->profile);
        self::assertSame(['x.cl'], $search->missing(['arco.acmeapp.io', 'x.cl'])); // "cl." is a single label
    }

    public function test_errors_are_returned_not_thrown(): void
    {
        $expired = new \RuntimeException('Error loading SSO Token: Token for multiapi does not exist or has expired');
        $denied = AwsMocks::error(Route53Exception::class, 'ListHostedZonesByName', 'AccessDenied', 403);
        $finder = $this->finder(['expired' => [$expired], 'denied' => [$denied]]);

        self::assertSame($expired, $finder->find(['arco.acmeapp.io'], 'expired')->error);
        $search = $finder->find(['arco.acmeapp.io'], 'denied');
        self::assertSame($denied, $search->error);
        self::assertSame(['arco.acmeapp.io'], $search->missing(['arco.acmeapp.io']));
    }

    public function test_a_profile_without_matching_zones_makes_no_sts_call(): void
    {
        $finder = $this->finder(['a' => [self::zones([['/hostedzone/Z1', 'other.com.']])]]);

        $search = $finder->find(['www.example.com'], 'a');

        self::assertSame(['a' => ['ListHostedZonesByName']], $this->calls);
        self::assertSame([], $search->found);
        self::assertNull($search->error);
    }

    /** @param list<array{0: string, 1: string, 2?: bool}> $zones */
    private static function zones(array $zones): Result
    {
        return new Result(['HostedZones' => array_map(fn (array $z) => ['Id' => $z[0], 'Name' => $z[1], 'Config' => ['PrivateZone' => $z[2] ?? false]], $zones), 'IsTruncated' => false]);
    }

    private static function account(string $id): Result
    {
        return new Result(['Account' => $id]);
    }

    /** @param array<string, list<mixed>> $responses per profile */
    private function finder(array $responses): DnsZoneFinder
    {
        return new DnsZoneFinder(function (string $profile) use ($responses): AwsClients {
            $mock = new MockHandler($responses[$profile] ?? []);
            return new AwsClients(null, 'us-east-1', [
                'handler' => function (CommandInterface $command, RequestInterface $request) use ($mock, $profile) {
                    $this->calls[$profile][] = $command->getName();
                    return $mock($command, $request);
                },
                'credentials' => false,
            ]);
        });
    }
}
