<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Unit\Commands;

use Multiapi\Arco\Cli\Application;
use Multiapi\Arco\Commands\InfoCommand;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;

/** Laravel-only commands in a process project (arco.json, or a go.mod alone): refused before anything runs. */
final class ProcessCommandsTest extends TestCase
{
    private ?ProjectFixture $project = null;

    protected function tearDown(): void
    {
        $this->project?->restore();
    }

    private function processProject(): ProjectFixture
    {
        $this->project = new ProjectFixture();
        file_put_contents($this->project->dir . '/arco.json', '{"name":"shop","region":"us-east-1","runtime":{"kind":"process","language":"go"},"queues":{"emails":{}},"schedules":{"cleanup":{"every":"1h"}}}');
        return $this->project;
    }

    /** @return list<string> every command the spec refuses in a process project */
    private static function refused(): array
    {
        return [
            'command', 'tinker', 'queue:failed-table', 'failed-jobs:add', 'failed-jobs:remove', 'cache:add', 'cache:remove',
            'console:add', 'console:remove', 'scheduler:set', 'scheduler:remove',
            // The arco.php-editing commands.
            'set', 'unset', 'queue:add', 'queue:remove', 'queue:set', 'storage:add', 'storage:remove', 'storage:public',
            'storage:expire', 'storage:cors', 'table:add', 'table:remove', 'domain:add', 'domain:remove', 'vpc:set',
            'vpc:remove', 'certificate:set', 'certificate:remove', 'logs:retention',
        ];
    }

    /** @return iterable<string, array{string}> */
    public static function refusedCommands(): iterable
    {
        foreach (self::refused() as $name) {
            yield $name => [$name];
        }
    }

    #[DataProvider('refusedCommands')]
    public function test_a_laravel_only_command_is_refused_in_a_process_project(string $name): void
    {
        $this->processProject();
        $before = scandir((string) $this->project?->dir);

        $tester = new \Symfony\Component\Console\Tester\CommandTester(Application::create()->find($name));
        $tester->execute(['--env' => 'production'], ['interactive' => false]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString("arco $name is not available for process apps", (string) preg_replace('/\s+/', ' ', $tester->getDisplay()));
        self::assertSame($before, scandir((string) $this->project?->dir), 'nothing written');
        self::assertFileDoesNotExist($this->project?->dir . '/arco.php');
    }

    public function test_a_go_mod_without_arco_json_is_a_process_project_too(): void
    {
        $this->project = new ProjectFixture();
        file_put_contents($this->project->dir . '/go.mod', "module example.com/shop\n\ngo 1.24\n");

        $tester = new \Symfony\Component\Console\Tester\CommandTester(Application::create()->find('queue:add'));
        $tester->execute(['name' => 'emails'], ['interactive' => false]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('arco queue:add is not available for process apps', (string) preg_replace('/\s+/', ' ', $tester->getDisplay()));
    }

    public function test_the_refusal_is_in_spanish_with_lang_es(): void
    {
        $this->processProject();
        $app = Application::create();
        $app->setAutoExit(false);
        $output = new \Symfony\Component\Console\Output\BufferedOutput();

        $code = $app->run(new \Symfony\Component\Console\Input\ArrayInput(['command' => 'tinker', '--lang' => 'es']), $output);

        self::assertSame(Command::FAILURE, $code);
        self::assertStringContainsString('arco tinker no está disponible para apps de proceso', (string) preg_replace('/\s+/', ' ', $output->fetch()));
    }

    public function test_info_works_in_a_process_project(): void
    {
        $this->processProject();

        $tester = $this->project?->tester(new InfoCommand(deployerFactory: fn () => throw new \LogicException('no AWS expected')));
        $tester?->execute(['--env' => 'production', '--json' => true], ['interactive' => false]);

        self::assertSame(Command::SUCCESS, $tester?->getStatusCode());
    }

    public function test_a_php_project_is_not_refused(): void
    {
        $this->project = new ProjectFixture(ProjectFixture::standardConfig());

        $tester = new \Symfony\Component\Console\Tester\CommandTester(Application::create()->find('console:add'));
        $tester->execute(['--env' => 'production'], ['interactive' => false]);

        $tester->assertCommandIsSuccessful();
    }
}
