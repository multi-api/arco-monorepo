<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Support;

use PHPUnit\Event\Test\Finished;
use PHPUnit\Event\Test\FinishedSubscriber;
use PHPUnit\Event\TestRunner\ExecutionFinished;
use PHPUnit\Event\TestRunner\ExecutionFinishedSubscriber;
use PHPUnit\Runner\Extension\Extension;
use PHPUnit\Runner\Extension\Facade;
use PHPUnit\Runner\Extension\ParameterCollection;
use PHPUnit\TextUI\Configuration\Configuration;

/** Removes the paths of TempPaths after each test, once its tearDown() has run, and the run-long ones at the end. */
final class RemoveTempPaths implements Extension
{
    public function bootstrap(Configuration $configuration, Facade $facade, ParameterCollection $parameters): void
    {
        $facade->registerSubscriber(new class implements FinishedSubscriber {
            public function notify(Finished $event): void
            {
                TempPaths::removeAll();
            }
        });
        $facade->registerSubscriber(new class implements ExecutionFinishedSubscriber {
            public function notify(ExecutionFinished $event): void
            {
                TempPaths::removeRunPaths();
            }
        });
    }
}
