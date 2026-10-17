<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Unit\Laravel;

use Illuminate\Console\Application as Artisan;
use Illuminate\Events\Dispatcher;
use Illuminate\Foundation\Application;
use Multiapi\Arco\Laravel\ArcoServiceProvider;
use Multiapi\Arco\Tests\Support\RuntimeApiEmulator;
use Multiapi\Arco\Tests\Support\TempPaths;
use Multiapi\Arco\Tests\Unit\Laravel\Fixtures\QueueTestApp;
use Multiapi\Arco\Tests\Unit\Laravel\Fixtures\QueueTestJob;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/** Runs `arco:work` in its own process against the Runtime API emulator. */
final class WorkCommandTest extends TestCase
{
    private ?RuntimeApiEmulator $api = null;
    private ?Process $worker = null;

    protected function tearDown(): void
    {
        $this->api?->stop();
        $this->worker?->stop(1);
        unset($_SERVER['ARCO']);
        Artisan::forgetBootstrappers();
    }

    public function test_the_provider_registers_a_hidden_arco_work(): void
    {
        $_SERVER['ARCO'] = '1';
        $app = new Application(sys_get_temp_dir());
        (new ArcoServiceProvider($app))->boot();

        $artisan = new Artisan($app, new Dispatcher($app), 'test');
        self::assertTrue($artisan->has('arco:work'));
        self::assertTrue($artisan->get('arco:work')->isHidden());
    }

    public function test_the_loop_serves_invocations_with_laravel_booted_once(): void
    {
        $this->api = new RuntimeApiEmulator();
        $boots = TempPaths::path('arco-boots-', '.txt');
        $this->worker = new Process([PHP_BINARY, __DIR__ . '/Fixtures/work-app.php'], null, [
            ...QueueTestApp::env(),
            'AWS_LAMBDA_RUNTIME_API' => "127.0.0.1:{$this->api->port}",
            'ARCO_TEST_BOOTS' => $boots,
            // Never inherit real credentials from the developer's shell.
            'AWS_ACCESS_KEY_ID' => false,
            'AWS_SECRET_ACCESS_KEY' => false,
            'AWS_SESSION_TOKEN' => false,
        ]);
        $this->worker->start();

        $first = $this->api->await($this->api->enqueue(['Records' => [QueueTestJob::record('ok')]]));
        $second = $this->api->await($this->api->enqueue(['Records' => [QueueTestJob::record('ok', id: 'a'), QueueTestJob::record('throw')]]));
        $broken = $this->api->await($this->api->enqueueRaw('not json'));

        $output = $this->worker->getOutput() . $this->worker->getErrorOutput();
        self::assertSame('response', $first['type'], $first['body'] . $output);
        self::assertSame(['batchItemFailures' => []], json_decode($first['body'], true));
        self::assertSame('response', $second['type'], $second['body'] . $output);
        self::assertSame(['batchItemFailures' => [['itemIdentifier' => 'm-throw']]], json_decode($second['body'], true));
        self::assertSame('error', $broken['type'], $output);
        self::assertSame("boot\n", file_get_contents($boots), 'Laravel boots once for all invocations');
    }
}
