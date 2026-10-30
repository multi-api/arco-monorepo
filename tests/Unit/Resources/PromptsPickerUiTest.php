<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Unit\Resources;

use Laravel\Prompts\Prompt;
use Multiapi\Arco\Resources\PromptsPickerUi;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

final class PromptsPickerUiTest extends TestCase
{
    protected function tearDown(): void
    {
        // fallbackWhen(), the fallbacks and interactive() are global: an interactive prompt later would wait on STDIN.
        Prompt::flushState();
        Prompt::interactive(false);
    }

    /** Without a terminal laravel/prompts answers every question with its default: what the picker passes in comes back. */
    public function test_defaults_come_back_without_a_terminal(): void
    {
        Prompt::interactive(false);
        $ui = new PromptsPickerUi(new ArrayInput([]), new BufferedOutput());

        self::assertTrue($ui->confirm('Continue?', true));
        self::assertSame(['subnet-b'], $ui->multiselect('Subnets', ['subnet-a' => 'a', 'subnet-b' => 'b'], ['subnet-b']));
        self::assertSame(['sg-3', 'sg-17'], $ui->multiselect('Groups', self::many(), ['sg-3', 'sg-17']), 'the searchable list keeps the defaults');
        self::assertSame('uploads', $ui->text('Name', 'uploads'));
        self::assertSame('cache', $ui->select('Use', ['generic' => 'as a table', 'cache' => 'as the cache'], 'cache'));
    }

    /** Where prompts cannot draw (Windows, no stty), each question is a Symfony question on the command's input. */
    public function test_falls_back_to_symfony_questions(): void
    {
        Prompt::interactive(true);
        $input = new ArrayInput([]);
        $stream = fopen('php://memory', 'r+');
        self::assertIsResource($stream);
        fwrite($stream, "no\nsubnet-a,subnet-b\n\nsg-2\nfiles\ncache\n");
        rewind($stream);
        $input->setStream($stream);
        $output = new BufferedOutput();
        $ui = new PromptsPickerUi($input, $output);
        Prompt::fallbackWhen(true);

        self::assertFalse($ui->confirm('Continue?', true));
        self::assertSame(['subnet-a', 'subnet-b'], $ui->multiselect('Subnets', ['subnet-a' => 'a', 'subnet-b' => 'b'], []));
        self::assertSame(['sg-3'], $ui->multiselect('Groups', self::many(), ['sg-3']), 'Enter keeps the defaults of the searchable list');
        self::assertSame(['sg-2'], $ui->multiselect('Groups', self::many(), ['sg-3']));
        self::assertSame('files', $ui->text('Name', 'uploads'));
        self::assertSame('cache', $ui->select('Use', ['generic' => 'as a table', 'cache' => 'as the cache'], 'generic'));
        $shown = $output->fetch();
        self::assertStringContainsString('Continue? (yes/no) [yes]:', $shown);
        self::assertStringContainsString('[subnet-a] a', $shown);
        self::assertStringContainsString('Name [uploads]:', $shown);
    }

    /** @return array<string, string> more options than one screen: the searchable list */
    private static function many(): array
    {
        $many = [];
        for ($i = 1; $i <= 20; $i++) {
            $many["sg-$i"] = "group $i";
        }
        return $many;
    }
}
