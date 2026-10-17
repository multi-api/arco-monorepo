<?php declare(strict_types=1);

// Runs `artisan arco:work` in a hand-built Laravel app (QueueTestApp), as a worker function would.
// Each boot of the app appends a line to the file in ARCO_TEST_BOOTS.
use Illuminate\Console\Application as Artisan;
use Multiapi\Arco\Laravel\ArcoServiceProvider;
use Multiapi\Arco\Tests\Unit\Laravel\Fixtures\QueueTestApp;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\ConsoleOutput;

require __DIR__ . '/../../../../vendor/autoload.php';

$test = new QueueTestApp(sys_get_temp_dir());
$app = $test->app;
$app->booted(function (): void {
    file_put_contents((string) getenv('ARCO_TEST_BOOTS'), "boot\n", FILE_APPEND);
});
$app->register(new ArcoServiceProvider($app));
$app->boot();

$events = $app->make('events');
assert($events instanceof Illuminate\Contracts\Events\Dispatcher);
$artisan = new Artisan($app, $events, 'test');
$artisan->setAutoExit(false);
exit($artisan->run(new ArrayInput(['command' => 'arco:work']), new ConsoleOutput()));
