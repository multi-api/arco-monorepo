<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Unit\Commands;

use Multiapi\Arco\Cli\Application;
use Multiapi\Arco\Tests\Support\TempPaths;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class ProjectFixture
{
    public string $dir;
    private string $previousCwd;
    private string $credentialsFile;
    private string $configFile;

    public function __construct(?string $arcoPhp = null)
    {
        $this->previousCwd = (string) getcwd();
        $this->dir = TempPaths::path('arco-fixture-');
        mkdir($this->dir . '/public', 0777, true);
        if ($arcoPhp !== null) {
            file_put_contents($this->dir . '/arco.php', $arcoPhp);
        }
        chdir($this->dir);
        putenv('AWS_PROFILE');
        putenv('AWS_ACCESS_KEY_ID');
        putenv('CLOUDFLARE_API_TOKEN');
        putenv('ARCO_CONFIG_LAYER');
        $this->credentialsFile = $this->dir . '/aws-credentials';
        $this->configFile = $this->dir . '/aws-config';
        $keys = "aws_access_key_id = AKIATEST\naws_secret_access_key = secret\n";
        file_put_contents($this->credentialsFile, "[p]\n$keys\n[acme-prod]\n$keys");
        file_put_contents($this->configFile, '');
        putenv('AWS_SHARED_CREDENTIALS_FILE=' . $this->credentialsFile);
        putenv('AWS_CONFIG_FILE=' . $this->configFile);
    }

    public static function standardConfig(): string
    {
        return <<<'PHP'
            <?php
            use Multiapi\Arco\Config\App;
            use Multiapi\Arco\Config\Environment;

            return App::make('shop')
                ->region('us-east-1')
                ->concurrency(48)
                ->environment('staging')
                ->environment('production', fn (Environment $env) => $env
                    ->timeout(60)
                    ->variables(['APP_ENV' => 'production', 'API_TOKEN' => 'plain-token', 'APP_KEY' => 'arco-ssm:/shop/production/app-key']));
            PHP;
    }

    /** standardConfig() with the console function on (arco command, tinker, deploy commands). */
    public static function consoleConfig(): string
    {
        return str_replace('->concurrency(48)', "->concurrency(48)\n    ->console()", self::standardConfig());
    }

    /**
     * Writes a config layer (JSON) into the fixture directory and returns its path.
     *
     * @param array<string, mixed> $document
     */
    public function layer(array $document): string
    {
        $path = $this->dir . '/layer.json';
        file_put_contents($path, json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        return $path;
    }

    public function tester(Command $command): CommandTester
    {
        $app = Application::create();
        $app->addCommands([$command]);
        return new CommandTester($app->find((string) $command->getName()));
    }

    public function restore(): void
    {
        chdir($this->previousCwd);
        putenv('AWS_SHARED_CREDENTIALS_FILE');
        putenv('AWS_CONFIG_FILE');
        putenv('ARCO_CONFIG_LAYER');
    }
}
