<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Unit\Logs;

/**
 * Encodes AWS event-stream messages (application/vnd.amazon.eventstream): a 12-byte prelude
 * (total length, headers length, prelude CRC32), string headers, the payload, then a CRC32 of the
 * whole message.
 */
final class EventStreamFrames
{
    /** @param array<string, string> $headers */
    public static function message(array $headers, string $payload): string
    {
        $encoded = '';
        foreach ($headers as $name => $value) {
            $encoded .= chr(strlen($name)) . $name . chr(7) . pack('n', strlen($value)) . $value;
        }
        $prelude = pack('NN', 12 + strlen($encoded) + strlen($payload) + 4, strlen($encoded));
        $prelude .= pack('N', crc32($prelude));
        $message = $prelude . $encoded . $payload;
        return $message . pack('N', crc32($message));
    }

    /** @param array<string, mixed> $payload */
    public static function event(string $type, array $payload): string
    {
        return self::message(
            [':message-type' => 'event', ':event-type' => $type, ':content-type' => 'application/json'],
            (string) json_encode($payload, JSON_UNESCAPED_SLASHES),
        );
    }

    public static function exception(string $type, string $message): string
    {
        return self::message(
            [':message-type' => 'exception', ':exception-type' => $type, ':content-type' => 'application/json'],
            (string) json_encode(['message' => $message]),
        );
    }

    /** A recorded Live Tail session: start, an update with two events, an empty heartbeat update. */
    public static function session(): string
    {
        $group = 'arn:aws:logs:us-east-1:111111111111:log-group:/aws/lambda/web/shop-production';
        return self::event('sessionStart', ['requestId' => 'r-1', 'sessionId' => 's-1', 'logGroupIdentifiers' => [$group]])
            . self::event('sessionUpdate', [
                'sessionMetadata' => ['sampled' => false],
                'sessionResults' => [
                    ['logStreamName' => '2026/10/15/a', 'logGroupIdentifier' => $group, 'message' => '{"message":"first","level_name":"INFO"}', 'timestamp' => 1760000000000, 'ingestionTime' => 1760000000500],
                    ['logStreamName' => '2026/10/15/a', 'logGroupIdentifier' => $group, 'message' => 'plain second', 'timestamp' => 1760000001000, 'ingestionTime' => 1760000001500],
                ],
            ])
            . self::event('sessionUpdate', ['sessionMetadata' => ['sampled' => false], 'sessionResults' => []]);
    }
}
