<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Unit\Build;

use Closure;
use Multiapi\Arco\Build\AppInspector;
use Multiapi\Arco\Tests\Support\TempPaths;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class AppInspectorTest extends TestCase
{
    private string $project;

    protected function setUp(): void
    {
        $this->project = TempPaths::path('arco-app-');
        mkdir($this->project, 0777, true);
    }

    private function write(string $relative, string $contents): void
    {
        $path = "$this->project/$relative";
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }
        file_put_contents($path, $contents);
    }

    private function inspector(?Closure $schedule = null): AppInspector
    {
        return new AppInspector($schedule ?? fn (string $dir): ?array => null);
    }

    public function test_stock_laravel_app_detects_nothing(): void
    {
        $this->write('config/filesystems.php', "<?php return ['default' => env('FILESYSTEM_DISK', 'local'), 'disks' => ['s3' => ['driver' => 's3']]];");
        $this->write('config/cache.php', "<?php return ['default' => env('CACHE_STORE', 'database'), 'stores' => ['dynamodb' => ['driver' => 'dynamodb']]];");
        $this->write('.env', "FILESYSTEM_DISK=local\nCACHE_STORE=database\n");

        $detection = $this->inspector()->detect($this->project);

        self::assertNull($detection->schedule);
        self::assertSame([], $detection->queues);
        self::assertSame(0, $detection->queuedClasses);
        self::assertFalse($detection->storage);
        self::assertFalse($detection->cache);
        self::assertFalse($detection->sqlDatabase);
    }

    public function test_schedule_from_inspector(): void
    {
        $tasks = [['task' => 'inspire', 'minutes' => [0]]];

        $detection = $this->inspector(fn (string $dir): array => $tasks)->detect($this->project);

        self::assertSame($tasks, $detection->schedule);
    }

    public function test_schedule_static_fallback_when_artisan_cannot_boot(): void
    {
        $this->write('routes/console.php', "<?php\nSchedule::command('x')->hourly();\n");

        $detection = $this->inspector(fn (string $dir) => throw new RuntimeException('boom'))->detect($this->project);

        self::assertSame([], $detection->schedule);
    }

    public function test_schedule_static_fallback_for_other_locations(): void
    {
        $this->write('bootstrap/app.php', '<?php return Application::configure()->withSchedule(fn () => null);');
        self::assertSame([], $this->inspector()->detect($this->project)->schedule);

        $other = TempPaths::path('arco-app-');
        mkdir("$other/app/Console", 0777, true);
        file_put_contents("$other/app/Console/Kernel.php", '<?php $schedule->command("x");');
        self::assertSame([], $this->inspector()->detect($other)->schedule);
    }

    public function test_queue_names_from_code(): void
    {
        $this->write('app/Jobs/A.php', '<?php class A implements ShouldQueue {}');
        $this->write('app/Jobs/B.php', "<?php class B implements ShouldBeUnique, ShouldQueue { public \$queue = 'emails'; }");
        $this->write('app/X.php', "<?php A::dispatch()->onQueue('reports'); B::dispatch()->onQueue(\$dynamic); C::dispatch()->onQueue('High Priority');");

        $detection = $this->inspector()->detect($this->project);

        self::assertSame(['default', 'emails', 'reports'], $detection->queues);
        self::assertSame(2, $detection->queuedClasses);
    }

    public function test_queue_name_forms_and_default_handling(): void
    {
        $this->write('app/Jobs/A.php', "<?php class A implements ShouldQueue { public ?string \$queue = 'zeta'; }");
        $this->write('routes/web.php', "<?php dispatch(new A)->onQueue(queue: 'alpha'); dispatch(new A)->onQueue('default');");

        self::assertSame(['default', 'alpha', 'zeta'], $this->inspector()->detect($this->project)->queues);
    }

    public function test_queue_literals_without_a_queued_class_are_ignored(): void
    {
        $this->write('app/X.php', "<?php A::dispatch()->onQueue('reports');");

        $detection = $this->inspector()->detect($this->project);

        self::assertSame([], $detection->queues);
        self::assertSame(0, $detection->queuedClasses);
    }

    #[DataProvider('storageSignals')]
    public function test_storage_signals(string $file, string $contents): void
    {
        $this->write($file, $contents);

        self::assertTrue($this->inspector()->detect($this->project)->storage);
    }

    /** @return iterable<string, array{string, string}> */
    public static function storageSignals(): iterable
    {
        yield 'env disk' => ['.env', "FILESYSTEM_DISK=s3\n"];
        yield 'composer package' => ['composer.json', '{"require": {"league/flysystem-aws-s3-v3": "^3.0"}}'];
        yield 'disk call' => ['app/Service.php', "<?php Storage::disk('s3')->put('a', 'b');"];
    }

    #[DataProvider('cacheSignals')]
    public function test_cache_signals(string $file, string $contents): void
    {
        $this->write($file, $contents);

        self::assertTrue($this->inspector()->detect($this->project)->cache);
    }

    /** @return iterable<string, array{string, string}> */
    public static function cacheSignals(): iterable
    {
        yield 'env store quoted' => ['.env', "CACHE_STORE=\"dynamodb\"\n"];
        yield 'env driver' => ['.env', "CACHE_DRIVER=dynamodb\n"];
        yield 'config literal' => ['config/cache.php', "<?php return ['default' => 'dynamodb'];"];
        yield 'config env default' => ['config/cache.php', "<?php return ['default' => env('CACHE_STORE', 'dynamodb')];"];
    }

    public function test_non_laravel_project(): void
    {
        $detection = $this->inspector()->detect($this->project);

        self::assertNull($detection->schedule);
        self::assertSame([], $detection->queues);
        self::assertSame(0, $detection->queuedClasses);
        self::assertFalse($detection->storage);
        self::assertFalse($detection->cache);
        self::assertFalse($detection->sqlDatabase);
    }

    public function test_sql_database(): void
    {
        self::assertFalse($this->inspector()->detect($this->project)->sqlDatabase);

        $this->write('.env', "DB_CONNECTION=pgsql\n");
        self::assertTrue($this->inspector()->detect($this->project)->sqlDatabase);

        $this->write('.env', "DB_CONNECTION=sqlite\n");
        self::assertFalse($this->inspector()->detect($this->project)->sqlDatabase);
    }

    public function test_database_connection_unquotes_and_lowercases(): void
    {
        $this->write('.env', "export DB_CONNECTION=\"MySQL\" # main\n");

        self::assertSame('mysql', AppInspector::databaseConnection("$this->project/.env"));
        self::assertNull(AppInspector::databaseConnection("$this->project/missing"));
    }

    public function test_symlinks_under_app_are_not_followed(): void
    {
        $outside = TempPaths::path('arco-outside-');
        mkdir($outside, 0777, true);
        file_put_contents("$outside/J.php", '<?php class J implements ShouldQueue {}');
        mkdir("$this->project/app", 0777, true);
        symlink($outside, "$this->project/app/linked");
        symlink("$outside/J.php", "$this->project/app/J.php");

        self::assertSame(0, $this->inspector()->detect($this->project)->queuedClasses);
    }

    public function test_unreadable_app_directory_does_not_fail_detection(): void
    {
        mkdir("$this->project/app", 0777, true);
        chmod("$this->project/app", 0000);
        try {
            if (is_readable("$this->project/app")) {
                self::markTestSkipped('The directory stays readable (running as root).');
            }
            self::assertSame([], $this->inspector()->detect($this->project)->queues);
        } finally {
            chmod("$this->project/app", 0755);
        }
    }

    public function test_queue_names_cannot_span_lines(): void
    {
        $this->write('app/Jobs/A.php', "<?php class A implements ShouldQueue {}\n\$a->onQueue('multi\n');");

        self::assertSame(['default'], $this->inspector()->detect($this->project)->queues);
    }
}
