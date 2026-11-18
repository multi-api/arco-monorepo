<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Unit\Deploy\Domains;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Multiapi\Arco\Deploy\Domains\PublicDnsResolver;
use PHPUnit\Framework\TestCase;

final class PublicDnsResolverTest extends TestCase
{
    public function test_cname_is_queried_over_https_against_a_public_resolver(): void
    {
        $history = [];
        $stack = HandlerStack::create(new MockHandler([self::response([
            'Status' => 0, 'TC' => false,
            'Question' => [['name' => 'routing.example.com.', 'type' => 5]],
            'Answer' => [['name' => 'routing.example.com.', 'type' => 5, 'TTL' => 300, 'data' => 'endpoint.cloudfront.net.']],
        ])]));
        $stack->push(Middleware::history($history));
        $resolver = new PublicDnsResolver(new Client(['handler' => $stack]));

        $records = $resolver->resolve('routing.example.com');

        self::assertSame([['host' => 'routing.example.com.', 'type' => 'CNAME', 'target' => 'endpoint.cloudfront.net.']], $records);
        $request = $history[0]['request'];
        self::assertSame('https', $request->getUri()->getScheme());
        self::assertSame('cloudflare-dns.com', $request->getUri()->getHost());
        self::assertSame('application/dns-json', $request->getHeaderLine('Accept'));
        parse_str($request->getUri()->getQuery(), $query);
        self::assertSame(['name' => 'routing.example.com', 'type' => 'CNAME'], $query);
    }

    public function test_truncated_dns_response_cannot_confirm_a_cname(): void
    {
        $resolver = self::resolver(['Status' => 0, 'TC' => true, 'Question' => [['name' => 'routing.example.com.', 'type' => 5]], 'Answer' => []]);

        self::assertFalse($resolver->resolve('routing.example.com'));
    }

    public function test_dns_error_cannot_confirm_a_cname(): void
    {
        $resolver = self::resolver(['Status' => 2, 'TC' => false, 'Question' => [['name' => 'routing.example.com.', 'type' => 5]]]);

        self::assertFalse($resolver->resolve('routing.example.com'));
    }

    public function test_response_to_another_question_cannot_confirm_a_cname(): void
    {
        $resolver = self::resolver(['Status' => 0, 'TC' => false, 'Question' => [['name' => 'other.example.com.', 'type' => 5]], 'Answer' => []]);

        self::assertFalse($resolver->resolve('routing.example.com'));
    }

    public function test_http_failure_cannot_confirm_a_cname(): void
    {
        $stack = HandlerStack::create(new MockHandler([new Response(503)]));
        $resolver = new PublicDnsResolver(new Client(['handler' => $stack]));

        self::assertFalse($resolver->resolve('routing.example.com'));
    }

    public function test_zone_lookup_detects_an_apex_from_its_soa_record(): void
    {
        $resolver = self::resolver([
            'Status' => 0, 'TC' => false, 'Question' => [['name' => 'example.com.', 'type' => 6]],
            'Answer' => [['name' => 'example.com.', 'type' => 6, 'TTL' => 3600, 'data' => 'ns.example.com. hostmaster.example.com. 1 3600 600 86400 300']],
        ]);

        self::assertSame('example.com', $resolver->zone('example.com'));
    }

    public function test_zone_lookup_finds_the_nearest_parent_with_an_soa_record(): void
    {
        $stack = HandlerStack::create(new MockHandler([
            self::response(['Status' => 3, 'TC' => false, 'Question' => [['name' => 'routing.example.com.', 'type' => 6]]]),
            self::response([
                'Status' => 0, 'TC' => false, 'Question' => [['name' => 'example.com.', 'type' => 6]],
                'Answer' => [['name' => 'example.com.', 'type' => 6, 'TTL' => 3600, 'data' => 'ns.example.com. hostmaster.example.com. 1 3600 600 86400 300']],
            ]),
        ]));
        $resolver = new PublicDnsResolver(new Client(['handler' => $stack]));

        self::assertSame('example.com', $resolver->zone('routing.example.com'));
    }

    public function test_zone_lookup_fails_closed_when_the_response_is_uncertain(): void
    {
        $resolver = self::resolver(['Status' => 2, 'TC' => false, 'Question' => [['name' => 'routing.example.com.', 'type' => 6]]]);

        self::assertFalse($resolver->zone('routing.example.com'));
    }

    public function test_records_lists_every_answer_and_reports_a_missing_name_as_empty(): void
    {
        $stack = HandlerStack::create(new MockHandler([
            self::response(['Status' => 3, 'TC' => false, 'Question' => [['name' => 'routing.example.com.', 'type' => 1]]]),
            self::response(['Status' => 0, 'TC' => false, 'Question' => [['name' => 'routing.example.com.', 'type' => 28]]]),
            self::response([
                'Status' => 0, 'TC' => false, 'Question' => [['name' => 'routing.example.com.', 'type' => 1]],
                'Answer' => [
                    ['name' => 'routing.example.com.', 'type' => 5, 'TTL' => 300, 'data' => 'other.example.net.'],
                    ['name' => 'other.example.net.', 'type' => 1, 'TTL' => 60, 'data' => '192.0.2.1'],
                ],
            ]),
        ]));
        $resolver = new PublicDnsResolver(new Client(['handler' => $stack]));

        self::assertSame([], $resolver->records('routing.example.com', 'A'), 'NXDOMAIN: the name is free');
        self::assertSame([], $resolver->records('routing.example.com', 'AAAA'), 'no data of that type');
        self::assertSame([
            ['host' => 'routing.example.com.', 'type' => 'CNAME', 'target' => 'other.example.net.'],
            ['host' => 'other.example.net.', 'type' => 'A', 'target' => '192.0.2.1'],
        ], $resolver->records('routing.example.com', 'A'));
    }

    public function test_records_fail_closed_on_uncertain_or_malformed_answers(): void
    {
        $question = [['name' => 'routing.example.com.', 'type' => 1]];
        foreach ([
            ['Status' => 2, 'TC' => false, 'Question' => $question],
            ['Status' => 0, 'TC' => true, 'Question' => $question, 'Answer' => []],
            ['Status' => 0, 'TC' => false, 'Question' => $question, 'Answer' => 'none'],
            ['Status' => 0, 'TC' => false, 'Question' => $question, 'Answer' => [['name' => 'routing.example.com.', 'type' => 1]]],
            ['Status' => 0, 'TC' => false, 'Question' => $question, 'Answer' => [['name' => 'routing.example.com.', 'type' => '1', 'data' => '192.0.2.1']]],
            ['Status' => 0, 'TC' => false, 'Question' => $question, 'Answer' => ['x']],
        ] as $body) {
            self::assertFalse(self::resolver($body)->records('routing.example.com', 'A'), json_encode($body, JSON_THROW_ON_ERROR));
        }
        self::assertFalse(self::resolver(['Status' => 0])->records('routing.example.com', 'MX'), 'only the types Arco needs are queried');
    }

    /** @param array<string, mixed> $body */
    private static function resolver(array $body): PublicDnsResolver
    {
        $stack = HandlerStack::create(new MockHandler([self::response($body)]));
        return new PublicDnsResolver(new Client(['handler' => $stack]));
    }

    /** @param array<string, mixed> $body */
    private static function response(array $body): Response
    {
        return new Response(200, ['Content-Type' => 'application/dns-json'], json_encode($body, JSON_THROW_ON_ERROR));
    }
}