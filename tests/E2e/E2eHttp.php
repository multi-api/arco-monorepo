<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\E2e;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use PHPUnit\Framework\Assert;

/**
 * HTTP for the end-to-end checks: GET with a per-request timeout, retried while the function is still
 * cold (connection errors and 5xx), and failure messages that name the check that made the request.
 */
final class E2eHttp
{
    private readonly Client $client;

    public function __construct()
    {
        $this->client = new Client(['http_errors' => false, 'allow_redirects' => false, 'connect_timeout' => 10, 'timeout' => 60]);
    }

    /**
     * GETs $url until it answers below 500 (any answer with $retryServerErrors false) or $deadlineSeconds
     * pass; returns the last answer, or fails the check when there never was one.
     *
     * @param array<string, string> $headers
     * @return array{status: int, body: string}
     */
    public function get(string $check, string $url, array $headers = [], int $deadlineSeconds = 90, bool $retryServerErrors = true): array
    {
        $deadline = time() + $deadlineSeconds;
        $last = 'no request made';
        do {
            try {
                $response = $this->client->get($url, ['headers' => $headers]);
                $answer = ['status' => $response->getStatusCode(), 'body' => (string) $response->getBody()];
                if ($answer['status'] < 500 || ! $retryServerErrors) {
                    return $answer;
                }
                $last = "HTTP {$answer['status']}: " . self::excerpt($answer['body']);
            } catch (GuzzleException $e) {
                $last = $e->getMessage();
                $answer = null;
            }
            if (time() >= $deadline) {
                break;
            }
            sleep(3);
        } while (true);

        return $answer ?? Assert::fail("[$check] GET $url failed for {$deadlineSeconds} s; last error: $last");
    }

    /**
     * GETs $url, asserts a 200 and decodes the JSON object it returns.
     *
     * @param array<string, string> $headers
     * @return array<string, mixed>
     */
    public function json(string $check, string $url, array $headers = [], int $deadlineSeconds = 90): array
    {
        $answer = $this->get($check, $url, $headers, $deadlineSeconds);
        Assert::assertSame(200, $answer['status'], "[$check] GET $url answered {$answer['status']}: " . self::excerpt($answer['body']));
        $data = json_decode($answer['body'], true);
        if (! is_array($data)) {
            Assert::fail("[$check] GET $url did not return a JSON object: " . self::excerpt($answer['body']));
        }
        /** @var array<string, mixed> $data */
        return $data;
    }

    public static function excerpt(string $body, int $length = 800): string
    {
        $body = trim($body);
        return strlen($body) > $length ? substr($body, 0, $length) . '…' : $body;
    }
}
