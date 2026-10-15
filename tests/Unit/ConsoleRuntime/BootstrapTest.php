<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Unit\ConsoleRuntime;

use Multiapi\Arco\Tests\Support\RuntimeApiEmulator;
use Multiapi\Arco\Tests\Support\S3Emulator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/** Runs runtime/bootstrap with the local sh and curl against local S3 and Runtime API emulators. */
final class BootstrapTest extends TestCase
{
    private const KEY = 'runtime/1.13.0-r1/frankenphp';
    private string $root;
    private string $tmp;
    private string $binary;
    private S3Emulator $s3;
    private RuntimeApiEmulator $api;

    protected function setUp(): void
    {
        $curl = new Process(['curl', '--help', 'all']);
        $curl->run();
        if (! str_contains($curl->getOutput(), '--aws-sigv4')) {
            self::markTestSkipped('curl with --aws-sigv4 (7.75+) is needed.');
        }
        $this->root = sys_get_temp_dir() . '/arco-boot-' . bin2hex(random_bytes(4));
        $this->tmp = "$this->root/tmp";
        mkdir("$this->root/app", 0777, true);
        mkdir("$this->root/runtime", 0777, true);
        // A stand-in for FrankenPHP: prints how it was started.
        $this->binary = "$this->root/fake-frankenphp";
        file_put_contents($this->binary, "#!/bin/sh\necho \"fake-frankenphp \$*\"\necho \"php-binary \$ARCO_PHP_BINARY\"\n");
        $this->s3 = new S3Emulator();
        $this->s3->put('arco-111111111111-us-east-1', self::KEY, $this->binary);
        $this->api = new RuntimeApiEmulator();
    }

    protected function tearDown(): void
    {
        if (isset($this->s3)) {
            $this->s3->stop();
            $this->api->stop();
        }
    }

    private function bootstrap(?string $sha = null): Process
    {
        $process = new Process(['sh', __DIR__ . '/../../../packages/arco/runtime/bootstrap'], null, [
            'LAMBDA_TASK_ROOT' => $this->root,
            'ARCO_TMP' => $this->tmp,
            'ARCO_S3_ENDPOINT' => "http://127.0.0.1:{$this->s3->port}",
            'ARCO_BINARY_BUCKET' => 'arco-111111111111-us-east-1',
            'ARCO_BINARY_KEY' => self::KEY,
            'ARCO_BINARY_SHA256' => $sha ?? (string) hash_file('sha256', $this->binary),
            'AWS_REGION' => 'us-east-1',
            'AWS_ACCESS_KEY_ID' => 'AKIDTEST',
            'AWS_SECRET_ACCESS_KEY' => 'secret',
            'AWS_SESSION_TOKEN' => 'session-token',
            'AWS_LAMBDA_RUNTIME_API' => "127.0.0.1:{$this->api->port}",
        ]);
        $process->run();
        return $process;
    }

    public function test_downloads_verifies_and_runs_the_binary(): void
    {
        $run = $this->bootstrap();

        self::assertSame(0, $run->getExitCode(), $run->getOutput() . $run->getErrorOutput());
        self::assertStringContainsString("fake-frankenphp php-cli $this->root/runtime/console.php", $run->getOutput());
        self::assertStringContainsString("php-binary $this->tmp/bin/frankenphp", $run->getOutput());
        self::assertStringContainsString('{"arco":"binary_ready"', $run->getOutput());
        self::assertSame(0755, fileperms("$this->tmp/bin/frankenphp") & 0777);
        self::assertFileDoesNotExist("$this->tmp/bin/frankenphp.part");

        $request = $this->s3->requests()[0];
        self::assertSame('/arco-111111111111-us-east-1/' . self::KEY, $request['path']);
        self::assertStringStartsWith('AWS4-HMAC-SHA256 Credential=AKIDTEST/', $request['authorization']);
        self::assertStringContainsString('/us-east-1/s3/aws4_request', $request['authorization']);
        self::assertSame('session-token', $request['token']);
    }

    public function test_a_checksum_mismatch_fails_init_and_never_runs_the_binary(): void
    {
        $run = $this->bootstrap(str_repeat('0', 64));

        self::assertSame(1, $run->getExitCode());
        self::assertStringNotContainsString('fake-frankenphp', $run->getOutput());
        self::assertFileDoesNotExist("$this->tmp/bin/frankenphp");
        self::assertFileDoesNotExist("$this->tmp/bin/frankenphp.part");
        self::assertSame('Arco.BinaryChecksum', $this->api->initError()['type'] ?? null);
    }

    public function test_a_missing_object_fails_init(): void
    {
        $this->s3->stop();
        $this->s3 = new S3Emulator(); // empty bucket

        $run = $this->bootstrap();

        self::assertSame(1, $run->getExitCode());
        self::assertSame('Arco.BinaryDownload', $this->api->initError()['type'] ?? null);
    }

    public function test_a_verified_binary_is_reused_without_downloading_again(): void
    {
        self::assertSame(0, $this->bootstrap()->getExitCode());
        $this->s3->stop();

        $again = $this->bootstrap();

        self::assertSame(0, $again->getExitCode(), $again->getErrorOutput());
        self::assertStringContainsString('fake-frankenphp php-cli', $again->getOutput());
        self::assertStringNotContainsString('binary_ready', $again->getOutput());
        self::assertCount(1, $this->s3->requests());
    }
}
