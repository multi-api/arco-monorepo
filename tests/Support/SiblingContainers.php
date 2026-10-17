<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Support;

/**
 * How a container the integration tests start (a Lambda simulation) and the test process reach each
 * other. bin/test-docker runs PHPUnit in a container on a user-defined network (ARCO_TEST_NETWORK) under
 * a known name (ARCO_TEST_HOST); the tests start their containers on the host's daemon as siblings on
 * that network. Without those variables the test process is on the host: host.docker.internal.
 */
final class SiblingContainers
{
    /** Emulators the containers call listen on every interface of the test process. */
    public const BIND = '0.0.0.0';

    /** The host name a container uses to reach a server of the test process. */
    public static function testHost(): string
    {
        $host = getenv('ARCO_TEST_HOST');
        return is_string($host) && $host !== '' ? $host : 'host.docker.internal';
    }

    /** The network the test process shares with its containers, or null when it runs on the host. */
    public static function network(): ?string
    {
        $network = getenv('ARCO_TEST_NETWORK');
        return is_string($network) && $network !== '' ? $network : null;
    }

    /** @return list<string> `docker run` options that let the container reach testHost() */
    public static function runOptions(): array
    {
        $network = self::network();
        return $network === null ? ['--add-host', 'host.docker.internal:host-gateway'] : ['--network', $network];
    }
}
