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

    /** @param array<string, string> $env */
    private function bootstrap(?string $sha = null, array $env = []): Process
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
            ...$env,
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
        self::assertSame('', $request['query']);
    }

    public function test_the_binary_is_not_pinned_to_an_s3_version(): void
    {
        // The key is immutable and ARCO_BINARY_SHA256 verifies the bytes: a stale variable from an older deploy is ignored.
        $run = $this->bootstrap(env: ['ARCO_BINARY_VERSION' => 'a+b/c']);

        self::assertSame(0, $run->getExitCode(), $run->getOutput() . $run->getErrorOutput());
        self::assertSame('', $this->s3->requests()[0]['query']);
    }

    public function test_credentials_never_appear_in_the_curl_command_line(): void
    {
        $which = new Process(['sh', '-c', 'command -v curl']);
        $which->mustRun();
        $shims = "$this->root/shims";
        mkdir($shims);
        file_put_contents("$shims/curl", "#!/bin/sh\nprintf '%s\\n' \"\$*\" >> $this->root/curl-argv\nexec " . trim($which->getOutput()) . " \"\$@\"\n");
        chmod("$shims/curl", 0755);

        $run = $this->bootstrap(env: ['PATH' => $shims . ':' . getenv('PATH')]);

        self::assertSame(0, $run->getExitCode(), $run->getOutput() . $run->getErrorOutput());
        $argv = (string) file_get_contents("$this->root/curl-argv");
        self::assertStringContainsString('--aws-sigv4', $argv);
        self::assertStringNotContainsString('secret', $argv);
        self::assertStringNotContainsString('session-token', $argv);
        $request = $this->s3->requests()[0];
        self::assertStringStartsWith('AWS4-HMAC-SHA256 Credential=AKIDTEST/', $request['authorization']);
        self::assertSame('session-token', $request['token']);
    }

    public function test_a_failing_step_under_set_e_reports_an_init_error(): void
    {
        file_put_contents("$this->root/not-a-directory", '');

        $run = $this->bootstrap(env: ['ARCO_TMP' => "$this->root/not-a-directory/tmp"]);

        self::assertSame(1, $run->getExitCode());
        self::assertStringNotContainsString('fake-frankenphp', $run->getOutput());
        $error = $this->api->initError();
        self::assertSame('Arco.InitError', $error['type'] ?? null);
        $body = json_decode($error['body'] ?? '', true, flags: JSON_THROW_ON_ERROR);
        self::assertStringContainsString("creating $this->root/not-a-directory/tmp/bin", $body['errorMessage']);
    }

    public function test_a_missing_app_directory_reports_an_init_error(): void
    {
        rmdir("$this->root/app");

        $run = $this->bootstrap();

        self::assertSame(1, $run->getExitCode());
        self::assertStringNotContainsString('fake-frankenphp', $run->getOutput());
        self::assertSame('Arco.InitError', $this->api->initError()['type'] ?? null);
    }

    public function test_a_missing_region_reports_an_init_error(): void
    {
        $run = $this->bootstrap(env: ['AWS_REGION' => false]);

        self::assertSame(1, $run->getExitCode());
        self::assertSame('Arco.InitError', $this->api->initError()['type'] ?? null);
    }

    public function test_the_init_error_and_the_log_line_are_valid_json_whatever_the_message(): void
    {
        $key = "runtime/a\"b\\c\td/frankenphp";

        $run = $this->bootstrap(env: ['ARCO_BINARY_KEY' => $key]);

        self::assertSame(1, $run->getExitCode());
        $error = $this->api->initError();
        self::assertSame('Arco.BinaryDownload', $error['type'] ?? null);
        $body = json_decode($error['body'] ?? '', true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('Arco.BinaryDownload', $body['errorType']);
        self::assertStringContainsString('runtime/a"b\\cd/frankenphp', $body['errorMessage']);
        self::assertSame(1, preg_match('/^\{"arco":"fatal".*$/m', $run->getOutput(), $line), $run->getOutput());
        self::assertSame($body['errorMessage'], json_decode($line[0], true, flags: JSON_THROW_ON_ERROR)['error']);
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

    /** A packaged bin/php stub and a curl shim that records every call (then runs the real curl). Returns the PATH to run with. */
    private function packagedPhp(): string
    {
        mkdir("$this->root/bin");
        file_put_contents("$this->root/bin/php", "#!/bin/sh\necho \"fake-php \$*\"\necho \"php-binary \$ARCO_PHP_BINARY\"\necho \"cwd \$(pwd)\"\n");
        chmod("$this->root/bin/php", 0755);
        $which = new Process(['sh', '-c', 'command -v curl']);
        $which->mustRun();
        $shims = "$this->root/shims";
        mkdir($shims);
        file_put_contents("$shims/curl", "#!/bin/sh\necho called >> $this->root/curl-calls\nexec " . trim($which->getOutput()) . " \"\$@\"\n");
        chmod("$shims/curl", 0755);
        return $shims . ':' . getenv('PATH');
    }

    public function test_bin_php_runs_without_a_download(): void
    {
        $path = $this->packagedPhp();

        $run = $this->bootstrap(env: ['PATH' => $path, 'ARCO_BINARY_BUCKET' => false, 'ARCO_BINARY_KEY' => false, 'ARCO_BINARY_SHA256' => false]);

        self::assertSame(0, $run->getExitCode(), $run->getOutput() . $run->getErrorOutput());
        self::assertStringContainsString("fake-php $this->root/runtime/console.php", $run->getOutput());
        self::assertStringContainsString("php-binary $this->root/bin/php", $run->getOutput());
        self::assertStringContainsString("cwd $this->root/app", $run->getOutput());
        self::assertStringNotContainsString('binary_ready', $run->getOutput());
        self::assertFileDoesNotExist("$this->root/curl-calls");
        self::assertCount(0, $this->s3->requests());
    }

    public function test_bin_php_wins_over_binary_variables(): void
    {
        $path = $this->packagedPhp();

        $run = $this->bootstrap(env: ['PATH' => $path]);

        self::assertSame(0, $run->getExitCode(), $run->getOutput() . $run->getErrorOutput());
        self::assertStringContainsString("php-binary $this->root/bin/php", $run->getOutput());
        self::assertFileDoesNotExist("$this->root/curl-calls");
        self::assertCount(0, $this->s3->requests());
    }

    public function test_bin_php_with_a_missing_app_directory_reports_an_init_error(): void
    {
        $path = $this->packagedPhp();
        rmdir("$this->root/app");

        $run = $this->bootstrap(env: ['PATH' => $path]);

        self::assertSame(1, $run->getExitCode());
        self::assertStringNotContainsString('fake-php', $run->getOutput());
        self::assertSame('Arco.InitError', $this->api->initError()['type'] ?? null);
    }
}
