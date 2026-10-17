<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Unit\Laravel;

use Illuminate\Console\Application as Artisan;
use Illuminate\Console\Scheduling\EventMutex;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Console\Scheduling\SchedulingMutex;
use Illuminate\Container\Container;
use Illuminate\Events\Dispatcher;
use Illuminate\Foundation\Application;
use Multiapi\Arco\Laravel\ArcoServiceProvider;
use Multiapi\Arco\Laravel\Console\ScheduleIntervalsCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

final class ScheduleIntervalsCommandTest extends TestCase
{
    private Application $app;

    protected function setUp(): void
    {
        $this->app = new Application(sys_get_temp_dir());
        Container::setInstance($this->app);
        $mutex = new class implements EventMutex, SchedulingMutex {
            public function create($event, $time = null): bool { return true; }
            public function exists($event, $time = null): bool { return false; }
            public function forget($event): void {}
        };
        $this->app->instance(EventMutex::class, $mutex);
        $this->app->instance(SchedulingMutex::class, $mutex);
        $this->app->instance(Schedule::class, new Schedule());
    }

    protected function tearDown(): void
    {
        unset($_SERVER['ARCO']);
        Artisan::forgetBootstrappers();
        Container::setInstance(null);
    }

    private function schedule(): Schedule
    {
        $schedule = $this->app->make(Schedule::class);
        self::assertInstanceOf(Schedule::class, $schedule);
        return $schedule;
    }

    /** @return list<array{task: string, minutes: list<int>}> */
    private function run_(): array
    {
        $_SERVER['ARCO'] = '1';
        (new ArcoServiceProvider($this->app))->boot();
        $artisan = new Artisan($this->app, new Dispatcher($this->app), 'test');
        $artisan->setAutoExit(false);
        self::assertTrue($artisan->get('arco:schedule-intervals')->isHidden());
        $output = new BufferedOutput();
        self::assertSame(0, $artisan->run(new ArrayInput(['command' => 'arco:schedule-intervals']), $output));
        $tasks = json_decode(trim($output->fetch()), true);
        self::assertIsArray($tasks);
        /** @var list<array{task: string, minutes: list<int>}> $tasks */
        return $tasks;
    }

    public function test_prints_the_minutes_each_task_can_fire_at(): void
    {
        $this->schedule()->command('reports:send')->everyFiveMinutes();
        $this->schedule()->call(static fn () => null)->dailyAt('09:00')->timezone('America/Santiago')->description('Daily digest');
        $this->schedule()->exec('echo hi')->everyMinute();

        $tasks = $this->run_();

        self::assertCount(3, $tasks);
        self::assertStringContainsString('reports:send', $tasks[0]['task']);
        self::assertStringNotContainsString('artisan', $tasks[0]['task']);
        self::assertSame(range(0, 55, 5), $tasks[0]['minutes']);
        self::assertSame(['task' => 'Daily digest', 'minutes' => [0]], $tasks[1]);
        self::assertSame('echo hi', $tasks[2]['task']);
        self::assertSame(range(0, 59), $tasks[2]['minutes']);
    }

    public function test_a_half_hour_timezone_shifts_the_minutes(): void
    {
        $this->schedule()->command('reports:send')->dailyAt('09:00')->timezone('Asia/Kolkata');
        $this->schedule()->command('reports:quarter')->hourlyAt(15)->timezone(new \DateTimeZone('America/St_Johns'));

        $tasks = $this->run_();

        self::assertSame([30], $tasks[0]['minutes']);
        self::assertSame([45], $tasks[1]['minutes']);
    }

    public function test_an_empty_schedule_prints_an_empty_list(): void
    {
        self::assertSame([], $this->run_());
        self::assertSame([], ScheduleIntervalsCommand::tasks(new Schedule()));
    }
}
