<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Unit\Deploy\Domains;

use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Response;
use Multiapi\Arco\Deploy\Domains\CloudflareApi;
use Multiapi\Arco\Deploy\Sleeper;
use Multiapi\Arco\Tests\Unit\Deploy\AwsMocks;
use Psr\Http\Message\RequestInterface;
use Throwable;

/**
 * An in-memory Cloudflare API v4 (zones and DNS records) behind a Guzzle handler: GET /zones?name=,
 * GET/POST /zones/{id}/dns_records, PATCH/DELETE /zones/{id}/dns_records/{id}. Queued responses or
 * exceptions answer first, in order, whatever the request.
 */
final class FakeCloudflare
{
    public const TOKEN = 'cf-test-token-0123456789abcdef';

    /** @var array<string, string> zone name => id */
    public array $zones = [];
    /** @var array<string, array<string, mixed>> by record id */
    public array $records = [];
    /** @var list<string> "METHOD path?query" per request */
    public array $log = [];
    /** @var list<array<string, mixed>|null> decoded JSON body per request */
    public array $bodies = [];
    /** @var list<string> Authorization header per request */
    public array $auth = [];
    /** @var list<Response|Throwable> */
    private array $queue = [];
    private int $next = 1;
    public Sleeper $sleeper;

    public function __construct()
    {
        $this->sleeper = AwsMocks::noSleep();
    }

    public function zone(string $name, string $id): self
    {
        $this->zones[$name] = $id;
        return $this;
    }

    /** @param array<string, mixed> $fields */
    public function record(string $zoneId, string $name, string $type, string $content, ?string $comment = null, array $fields = []): string
    {
        $id = 'rec' . $this->next++;
        $this->records[$id] = ['id' => $id, 'zone_id' => $zoneId, 'name' => $name, 'type' => $type, 'content' => $content, 'proxied' => false, 'ttl' => 1, 'comment' => $comment] + $fields;
        return $id;
    }

    public function queue(Response|Throwable ...$responses): self
    {
        array_push($this->queue, ...$responses);
        return $this;
    }

    public function api(string $token = self::TOKEN): CloudflareApi
    {
        return CloudflareApi::create($token, $this->sleeper, $this->handler(...));
    }

    /** @return list<array<string, mixed>> the records named $name, in creation order */
    public function named(string $name): array
    {
        return array_values(array_filter($this->records, fn (array $r) => $r['name'] === $name));
    }

    /** @return list<string> the log without GETs */
    public function writes(): array
    {
        return array_values(array_filter($this->log, fn (string $line) => ! str_starts_with($line, 'GET ')));
    }

    /** @param array<string, mixed> $options */
    public function handler(RequestInterface $request, array $options): PromiseInterface
    {
        $path = substr($request->getUri()->getPath(), strlen('/client/v4/'));
        $query = $request->getUri()->getQuery();
        $this->log[] = $request->getMethod() . ' ' . $path . ($query === '' ? '' : '?' . urldecode($query));
        $body = json_decode((string) $request->getBody(), true);
        $this->bodies[] = is_array($body) ? $body : null;
        $this->auth[] = $request->getHeaderLine('Authorization');

        if ($this->queue !== []) {
            $next = array_shift($this->queue);
            return $next instanceof Throwable ? Create::rejectionFor($next) : Create::promiseFor($next);
        }
        parse_str($query, $params);
        return Create::promiseFor($this->route($request->getMethod(), $path, $params, is_array($body) ? $body : []));
    }

    /**
     * @param array<mixed> $params
     * @param array<mixed> $body
     */
    private function route(string $method, string $path, array $params, array $body): Response
    {
        if ($method === 'GET' && $path === 'zones') {
            $zones = [];
            foreach ($this->zones as $name => $id) {
                if (! isset($params['name']) || $params['name'] === $name) {
                    $zones[] = ['id' => $id, 'name' => $name, 'status' => 'active'];
                }
            }
            return self::ok($zones);
        }
        if (preg_match('#^zones/([^/]+)/dns_records(?:/([^/]+))?$#', $path, $m) !== 1) {
            return self::json(404, ['success' => false, 'errors' => [['code' => 7003, 'message' => 'Could not route']], 'result' => null]);
        }
        $zone = $m[1];
        $id = $m[2] ?? null;
        if (! in_array($zone, $this->zones, true)) {
            return self::json(404, ['success' => false, 'errors' => [['code' => 7003, 'message' => 'Could not route to zone']], 'result' => null]);
        }
        if ($method === 'GET') {
            return self::ok(array_values(array_filter($this->records, fn (array $r) => $r['zone_id'] === $zone && (! isset($params['name']) || $r['name'] === $params['name']))));
        }
        if ($method === 'POST') {
            $new = 'rec' . $this->next++;
            $this->records[$new] = ['id' => $new, 'zone_id' => $zone] + $body;
            return self::ok($this->records[$new]);
        }
        if ($id === null || ! isset($this->records[$id])) {
            return self::json(404, ['success' => false, 'errors' => [['code' => 81044, 'message' => 'Record does not exist.']], 'result' => null]);
        }
        if ($method === 'PATCH') {
            $this->records[$id] = $body + $this->records[$id];
            return self::ok($this->records[$id]);
        }
        unset($this->records[$id]);
        return self::ok(['id' => $id]);
    }

    public static function ok(mixed $result): Response
    {
        return self::json(200, ['success' => true, 'errors' => [], 'messages' => [], 'result' => $result]);
    }

    /** @param array<string, mixed> $body */
    public static function json(int $status, array $body, array $headers = []): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'] + $headers, (string) json_encode($body));
    }
}
