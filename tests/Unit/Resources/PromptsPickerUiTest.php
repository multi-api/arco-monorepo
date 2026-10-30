<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Unit\Resources;

use Laravel\Prompts\Prompt;
use Multiapi\Arco\Resources\PromptsPickerUi;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\BufferedOutput;

/** Without a terminal laravel/prompts answers every question with its default: what the picker passes in comes back. */
final class PromptsPickerUiTest extends TestCase
{
    public function test_defaults_come_back_without_a_terminal(): void
    {
        Prompt::interactive(false);
        $ui = new PromptsPickerUi(new BufferedOutput());

        self::assertTrue($ui->confirm('Continue?', true));
        self::assertSame(['subnet-b'], $ui->multiselect('Subnets', ['subnet-a' => 'a', 'subnet-b' => 'b'], ['subnet-b']));
        $many = [];
        for ($i = 1; $i <= 20; $i++) {
            $many["sg-$i"] = "group $i";
        }
        self::assertSame(['sg-3', 'sg-17'], $ui->multiselect('Groups', $many, ['sg-3', 'sg-17']), 'the searchable list keeps the defaults');
        self::assertSame('uploads', $ui->text('Name', 'uploads'));
        self::assertSame('cache', $ui->select('Use', ['generic' => 'as a table', 'cache' => 'as the cache'], 'cache'));
    }
}
