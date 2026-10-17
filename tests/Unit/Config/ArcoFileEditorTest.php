<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Unit\Config;

use Multiapi\Arco\Config\ArcoFileEditor;
use Multiapi\Arco\Config\ConfigException;
use Multiapi\Arco\Config\ConfigLoader;
use Multiapi\Arco\Config\ConfigResolver;
use Multiapi\Arco\Tests\Support\TempPaths;
use PHPUnit\Framework\TestCase;

final class ArcoFileEditorTest extends TestCase
{
    private function file(string $php): string
    {
        $path = TempPaths::path('arco-edit-', '.php');
        file_put_contents($path, $php);
        return $path;
    }

    public function test_adds_account_inside_an_environment_closure(): void
    {
        $path = $this->file(<<<'PHP'
            <?php
            use Multiapi\Arco\Config\App;
            use Multiapi\Arco\Config\Environment;

            return App::make('shop')
                ->region('us-east-1')
                ->environment('production', fn (Environment $env) => $env
                    ->concurrency(64));
            PHP);

        self::assertTrue(ArcoFileEditor::addAccount($path, 'production', '222222222222'));
        $config = ConfigResolver::resolve((new ConfigLoader())->load($path), 'production');
        self::assertSame('222222222222', $config->account);
        self::assertSame(64, $config->concurrency);
    }

    public function test_adds_account_to_an_environment_without_closure(): void
    {
        $path = $this->file(<<<'PHP'
            <?php
            use Multiapi\Arco\Config\App;
            use Multiapi\Arco\Config\Environment;

            return App::make('shop')->region('us-east-1')->environment('staging');
            PHP);

        self::assertTrue(ArcoFileEditor::addAccount($path, 'staging', '111111111111'));
        self::assertSame('111111111111', ConfigResolver::resolve((new ConfigLoader())->load($path), 'staging')->account);
    }

    public function test_returns_false_when_pattern_is_not_found(): void
    {
        $path = $this->file("<?php\n\$envs = ['production'];\nreturn build(\$envs);");
        $before = file_get_contents($path);

        self::assertFalse(ArcoFileEditor::addAccount($path, 'production', '111111111111'));
        self::assertSame($before, file_get_contents($path));
    }

    private static function resolve(string $path, string $environment = 'production'): \Multiapi\Arco\Config\AppConfig
    {
        return ConfigResolver::resolve((new ConfigLoader())->load($path), $environment);
    }

    public function test_inserts_domains_and_dns_inside_an_environment_closure(): void
    {
        $path = $this->file(<<<'PHP'
            <?php
            use Multiapi\Arco\Config\App;
            use Multiapi\Arco\Config\Environment;

            return App::make('shop')
                ->region('us-east-1')
                ->environment('staging', fn (Environment $env) => $env->timeout(10))
                ->environment('production', fn (Environment $env) => $env
                    ->concurrency(64));
            PHP);

        self::assertTrue(ArcoFileEditor::setDomains($path, 'production', ['arco.acmeapp.io', 'api-arco.acmeapp.io']));
        self::assertTrue(ArcoFileEditor::setDns($path, 'production', 'acme-dns'));

        $config = self::resolve($path);
        self::assertSame(['arco.acmeapp.io', 'api-arco.acmeapp.io'], $config->domains);
        self::assertSame('acme-dns', $config->dnsProfile);
        self::assertSame(64, $config->concurrency);
        self::assertSame([], self::resolve($path, 'staging')->domains);
        self::assertStringContainsString("=> \$env\n        ->domain(['arco.acmeapp.io', 'api-arco.acmeapp.io'])", (string) file_get_contents($path));
    }

    public function test_replaces_literal_calls_and_removes_them(): void
    {
        $path = $this->file(<<<'PHP'
            <?php
            use Multiapi\Arco\Config\App;
            use Multiapi\Arco\Config\Environment;

            return App::make('shop')
                ->region('us-east-1')
                ->environment("production", static fn (Environment $e) => $e
                    ->domain('old.example.com')
                    ->dns(false)
                    ->timeout(20));
            PHP);

        self::assertTrue(ArcoFileEditor::setDomains($path, 'production', ['a.example.com', 'b.example.com']));
        self::assertTrue(ArcoFileEditor::setDns($path, 'production', 'common'));
        $config = self::resolve($path);
        self::assertSame(['a.example.com', 'b.example.com'], $config->domains);
        self::assertSame('common', $config->dnsProfile);

        self::assertTrue(ArcoFileEditor::setDns($path, 'production', false));
        self::assertTrue(self::resolve($path)->dnsManual);

        self::assertTrue(ArcoFileEditor::setDomains($path, 'production', []));
        self::assertTrue(ArcoFileEditor::setDns($path, 'production', null));
        $config = self::resolve($path);
        self::assertSame([], $config->domains);
        self::assertFalse($config->dnsManual);
        self::assertSame(20, $config->timeout);
        self::assertStringNotContainsString('->domain(', (string) file_get_contents($path));
    }

    public function test_converts_a_bare_environment_into_a_closure(): void
    {
        $path = $this->file(<<<'PHP'
            <?php
            use Multiapi\Arco\Config\App;

            return App::make('shop')->region('us-east-1')->environment('staging')->environment('production');
            PHP);

        self::assertTrue(ArcoFileEditor::setDomains($path, 'production', ['a.example.com']));
        self::assertTrue(ArcoFileEditor::setDns($path, 'production', false));
        $config = self::resolve($path);
        self::assertSame(['a.example.com'], $config->domains);
        self::assertTrue($config->dnsManual);
        self::assertSame([], self::resolve($path, 'staging')->domains);
    }

    public function test_removing_a_call_that_is_not_there_changes_nothing(): void
    {
        $php = "<?php\nreturn Multiapi\\Arco\\Config\\App::make('shop')->region('us-east-1')->environment('production');\n";
        $path = $this->file($php);

        self::assertTrue(ArcoFileEditor::setDomains($path, 'production', []));
        self::assertSame($php, file_get_contents($path));
    }

    public function test_refuses_unknown_shapes(): void
    {
        $shapes = [
            // domains built from a variable
            "<?php\nuse Multiapi\\Arco\\Config\\App;\n\$d = ['a.example.com'];\nreturn App::make('shop')->environment('production', fn (\$env) => \$env->domain(\$d));\n",
            // app-level domain() only
            "<?php\nuse Multiapi\\Arco\\Config\\App;\nreturn App::make('shop')->domain('a.example.com')->environment('production');\n",
            // two domain() calls
            "<?php\nuse Multiapi\\Arco\\Config\\App;\nreturn App::make('shop')->environment('production', fn (\$env) => \$env->domain('a.example.com')->domain('b.example.com'));\n",
            // long closure
            "<?php\nuse Multiapi\\Arco\\Config\\App;\nreturn App::make('shop')->environment('production', function (\$env) { \$env->timeout(5); });\n",
            // no such environment
            "<?php\nuse Multiapi\\Arco\\Config\\App;\nreturn App::make('shop')->environment('staging');\n",
        ];
        foreach ($shapes as $php) {
            $path = $this->file($php);
            self::assertFalse(ArcoFileEditor::setDomains($path, 'production', ['c.example.com']), $php);
            self::assertSame($php, file_get_contents($path));
        }
    }

    public function test_dns_can_be_inserted_next_to_an_app_level_domain(): void
    {
        $path = $this->file("<?php\nuse Multiapi\\Arco\\Config\\App;\nreturn App::make('shop')->region('us-east-1')->domain('a.example.com')->environment('production');\n");

        self::assertTrue(ArcoFileEditor::setDns($path, 'production', 'common'));
        self::assertSame('common', self::resolve($path)->dnsProfile);
    }

    public function test_a_result_that_does_not_parse_keeps_the_original(): void
    {
        $php = "<?php\nuse Multiapi\\Arco\\Config\\App;\nreturn App::make('shop')->environment('production');\n";
        $path = $this->file($php);

        try {
            ArcoFileEditor::write($path, "<?php\nreturn [;\n");
            self::fail('Expected a lint failure.');
        } catch (ConfigException $e) {
            self::assertSame('editor.lint_failed', $e->key);
        }
        self::assertSame($php, file_get_contents($path));
        self::assertSame([basename($path)], array_values(array_filter(scandir(dirname($path)) ?: [], fn (string $f) => str_starts_with($f, basename($path)))));
    }

    public function test_renders_the_lines_to_add_by_hand(): void
    {
        self::assertSame("->domain(['a.example.com', 'b.example.com'])", ArcoFileEditor::domainLine(['a.example.com', 'b.example.com']));
        self::assertSame("->dns('common')", ArcoFileEditor::dnsLine('common'));
        self::assertSame('->dns(false)', ArcoFileEditor::dnsLine(false));
    }

    private static function head(): string
    {
        return "<?php\nuse Multiapi\\Arco\\Config\\App;\nuse Multiapi\\Arco\\Config\\Environment;\n\$stage = 'x';\n";
    }

    public function test_interpolated_strings_heredocs_and_comments_do_not_count_as_brackets(): void
    {
        $tricky = [
            '"Shop ($stage)"', '"($stage"', '"$stage)"', '"[$stage]"', '"{$stage}"', '"}{$stage}{"',
            "<<<TXT\n  a ) ] } {\$stage} (\n  TXT", "<<<'TXT'\n  ) ] }\n  TXT",
        ];
        foreach ($tricky as $value) {
            $php = self::head() . "return App::make('shop')->region('us-east-1')\n"
                . "    ->environment('production', fn (Environment \$env) => \$env // a ( comment ]\n"
                . "        ->variables(['A' => $value]) # another ) one\n"
                . "        ->domain(['shop.example.com']) /* } */\n"
                . "        ->dns('old-dns')\n"
                . "        ->variables(['B' => $value]))\n"
                . "    ->environment('staging');\n";
            $path = $this->file($php);

            self::assertTrue(ArcoFileEditor::setDns($path, 'production', 'new-dns'), $value);
            self::assertTrue(ArcoFileEditor::setDomains($path, 'production', ['a.example.com']), $value);
            $config = self::resolve($path);
            self::assertSame('new-dns', $config->dnsProfile, $value);
            self::assertSame(['a.example.com'], $config->domains, $value);
            self::assertSame([], self::resolve($path, 'staging')->domains, $value);
        }
    }

    public function test_refuses_a_repeated_environment_or_another_receiver(): void
    {
        $shapes = [
            self::head() . "return App::make('shop')->environment('production')\n    ->environment('production', fn (Environment \$env) => \$env->domain(['a.example.com']));\n",
            self::head() . "\$other = App::make('x')->environment('production');\nreturn App::make('shop')->environment('production', fn (Environment \$env) => \$env->domain(['a.example.com']));\n",
            self::head() . "\$app = App::make('shop');\nreturn \$app->environment('production', fn (Environment \$env) => \$env->domain(['a.example.com']));\n",
        ];
        foreach ($shapes as $php) {
            $path = $this->file($php);
            self::assertFalse(ArcoFileEditor::setDomains($path, 'production', ['a.example.com', 'b.example.com']), $php);
            self::assertFalse(ArcoFileEditor::setDomains($path, 'production', []), $php);
            self::assertSame($php, file_get_contents($path));
        }
    }

    public function test_the_arrow_function_body_must_start_with_its_parameter(): void
    {
        $shapes = [
            self::head() . "\$big = true;\nreturn App::make('shop')->environment('production', fn (Environment \$env) => \$big ? \$env->timeout(10) : \$env);\n",
            self::head() . "return App::make('shop')->environment('production', fn (Environment \$env) => \$env ?? null);\n",
        ];
        foreach ($shapes as $php) {
            $path = $this->file($php);
            self::assertFalse(ArcoFileEditor::setDns($path, 'production', 'x'), $php);
            self::assertSame($php, file_get_contents($path));
        }
        $path = $this->file(self::head() . "return App::make('shop')->environment('production', fn (Environment \$env) => \$env);\n");
        self::assertTrue(ArcoFileEditor::setDns($path, 'production', 'x'));
        self::assertSame('x', self::resolve($path)->dnsProfile);
    }

    public function test_update_writes_both_calls_once_or_nothing(): void
    {
        $php = self::head() . "return App::make('shop')->environment('production', fn (Environment \$env) => \$env\n    ->domain(['a.example.com'])\n    ->dns(getenv('X') ?: 'x'));\n";
        $path = $this->file($php);

        self::assertFalse(ArcoFileEditor::update($path, 'production', ['domains' => ['a.example.com', 'b.example.com'], 'dns' => 'other']));
        self::assertSame($php, file_get_contents($path));

        $path = $this->file(self::head() . "return App::make('shop')->environment('production');\n");
        self::assertTrue(ArcoFileEditor::update($path, 'production', ['domains' => ['a.example.com'], 'dns' => false]));
        $config = self::resolve($path);
        self::assertSame(['a.example.com'], $config->domains);
        self::assertTrue($config->dnsManual);
    }

    public function test_a_wrong_result_is_rolled_back(): void
    {
        $php = self::head() . "return App::make('shop')->environment('production', fn (Environment \$env) => \$env->domain(['a.example.com']));\n";
        $path = $this->file($php);

        try {
            ArcoFileEditor::verifiedWrite($path, str_replace("'a.example.com'", "'z.example.com'", $php), 'production', ['domains' => ['b.example.com']]);
            self::fail('Expected a verification failure.');
        } catch (ConfigException $e) {
            self::assertSame('editor.verify_failed', $e->key);
        }
        self::assertSame($php, file_get_contents($path));
    }

    public function test_removing_a_call_after_a_line_comment_keeps_the_file_valid(): void
    {
        $path = $this->file(self::head() . "return App::make('shop')\n    ->environment('production', fn (Environment \$env) => \$env // production\n        ->domain(['a.example.com']));\n");

        self::assertTrue(ArcoFileEditor::setDomains($path, 'production', []));
        self::assertSame([], self::resolve($path)->domains);

        $path = $this->file(self::head() . "return App::make('shop')\n    ->environment('production', fn (Environment \$env) => \$env # production\n        ->domain(['a.example.com'])\n        ->timeout(9));\n");
        self::assertTrue(ArcoFileEditor::setDomains($path, 'production', []));
        self::assertSame(9, self::resolve($path)->timeout);
        self::assertStringContainsString("# production\n        ->timeout(9));", (string) file_get_contents($path));
    }

    public function test_keeps_crlf_line_endings(): void
    {
        $path = $this->file(str_replace("\n", "\r\n", self::head() . "return App::make('shop')\n    ->environment('production', fn (Environment \$env) => \$env\n        ->timeout(9));\n"));

        self::assertTrue(ArcoFileEditor::update($path, 'production', ['domains' => ['a.example.com'], 'dns' => 'x']));
        $source = (string) file_get_contents($path);
        self::assertSame(0, preg_match("/(?<!\r)\n/", $source));
        self::assertSame('x', self::resolve($path)->dnsProfile);
    }

    private static function settingsFile(): string
    {
        return self::head() . "return App::make('shop')\n"
            . "    ->region('us-east-1')\n"
            . "    ->concurrency(48)\n"
            . "    ->environment('staging')\n"
            . "    ->environment('production', fn (Environment \$env) => \$env\n"
            . "        ->timeout(60)\n"
            . "        ->variables(['APP_ENV' => 'production']));\n";
    }

    public function test_sets_replaces_and_removes_a_setting_in_an_environment_closure(): void
    {
        $path = $this->file(self::settingsFile());

        self::assertTrue(ArcoFileEditor::setSetting($path, 'production', 'concurrency', 16));
        self::assertStringContainsString("=> \$env\n        ->concurrency(16)\n        ->timeout(60)", (string) file_get_contents($path));
        self::assertTrue(ArcoFileEditor::setSetting($path, 'production', 'timeout', 28));
        self::assertTrue(ArcoFileEditor::setSetting($path, 'production', 'logLevel', 'debug'));
        $config = self::resolve($path);
        self::assertSame([16, 28, 'debug', 'environment'], [$config->concurrency, $config->timeout, $config->logLevel, $config->sources['concurrency']]);
        self::assertSame(48, self::resolve($path, 'staging')->concurrency);

        self::assertTrue(ArcoFileEditor::setSetting($path, 'production', 'concurrency', null));
        self::assertTrue(ArcoFileEditor::setSetting($path, 'production', 'logLevel', null));
        $config = self::resolve($path);
        self::assertSame([48, 'app', 28, 'info'], [$config->concurrency, $config->sources['concurrency'], $config->timeout, $config->logLevel]);
        self::assertSame(str_replace('->timeout(60)', '->timeout(28)', self::settingsFile()), file_get_contents($path));
    }

    public function test_sets_a_setting_on_a_bare_environment(): void
    {
        $path = $this->file(self::settingsFile());

        self::assertTrue(ArcoFileEditor::setSetting($path, 'staging', 'rateLimit', 100));
        self::assertSame(100, self::resolve($path, 'staging')->rateLimit);
        self::assertNull(self::resolve($path)->rateLimit);
    }

    public function test_sets_replaces_and_removes_an_app_level_setting(): void
    {
        $path = $this->file(self::settingsFile());

        self::assertTrue(ArcoFileEditor::setSetting($path, null, 'timeout', 90));
        self::assertStringContainsString("    ->region('us-east-1')\n    ->timeout(90)\n    ->concurrency(48)\n", (string) file_get_contents($path));
        self::assertSame([90, 'app'], [self::resolve($path, 'staging')->timeout, self::resolve($path, 'staging')->sources['timeout']]);
        self::assertSame(60, self::resolve($path)->timeout);

        self::assertTrue(ArcoFileEditor::setSetting($path, null, 'concurrency', 8));
        self::assertSame(8, self::resolve($path, 'staging')->concurrency);
        self::assertTrue(ArcoFileEditor::setSetting($path, null, 'timeout', null));
        self::assertTrue(ArcoFileEditor::setSetting($path, null, 'concurrency', null));
        self::assertSame('default', self::resolve($path, 'staging')->sources['concurrency']);
        self::assertSame(str_replace("    ->concurrency(48)\n", '', self::settingsFile()), file_get_contents($path));
    }

    public function test_app_level_setting_on_a_one_line_chain_without_region(): void
    {
        $path = $this->file(self::head() . "return App::make('shop')->environment('production');\n");

        self::assertTrue(ArcoFileEditor::setSetting($path, null, 'maxInstances', 3));
        self::assertSame(3, self::resolve($path)->maxInstances);
    }

    public function test_max_environments_is_replaced_by_max_instances(): void
    {
        $path = $this->file(self::head() . "return App::make('shop')->region('us-east-1')->maxEnvironments(4)\n"
            . "    ->environment('production', fn (Environment \$env) => \$env->maxEnvironments(2)->timeout(9));\n");

        self::assertTrue(ArcoFileEditor::setSetting($path, 'production', 'maxInstances', 20));
        self::assertTrue(ArcoFileEditor::setSetting($path, null, 'maxInstances', 5));
        $source = (string) file_get_contents($path);
        self::assertStringNotContainsString('maxEnvironments', $source);
        self::assertStringContainsString("\$env->maxInstances(20)->timeout(9)", $source);
        self::assertSame(20, self::resolve($path)->maxInstances);

        self::assertTrue(ArcoFileEditor::setSetting($path, 'production', 'maxInstances', null));
        self::assertSame([5, 'app'], [self::resolve($path)->maxInstances, self::resolve($path)->sources['maxInstances']]);
    }

    public function test_refuses_unknown_setting_shapes(): void
    {
        $cases = [
            // value not a literal
            ['production', 'timeout', self::head() . "return App::make('shop')->environment('production', fn (Environment \$env) => \$env->timeout((int) getenv('T')));\n"],
            // the setting twice (alias counts)
            ['production', 'maxInstances', self::head() . "return App::make('shop')->environment('production', fn (Environment \$env) => \$env->maxEnvironments(2)->maxInstances(3));\n"],
            // app built in steps
            [null, 'timeout', self::head() . "\$app = App::make('shop');\n\$app->timeout(5);\nreturn \$app->environment('production');\n"],
            // app-level call outside the App::make() chain
            [null, 'timeout', self::head() . "\$app = App::make('shop')->environment('production');\n\$app->timeout(5);\nreturn \$app;\n"],
            // two apps
            [null, 'timeout', self::head() . "\$x = App::make('x');\nreturn App::make('shop')->environment('production');\n"],
            // named argument
            [null, 'timeout', self::head() . "return App::make('shop')->timeout(seconds: 5)->environment('production');\n"],
        ];
        foreach ($cases as [$environment, $method, $php]) {
            $path = $this->file($php);
            self::assertFalse(ArcoFileEditor::setSetting($path, $environment, $method, 10), $php);
            self::assertFalse(ArcoFileEditor::setSetting($path, $environment, $method, null), $php);
            self::assertSame($php, file_get_contents($path));
        }
    }

    public function test_a_wrong_setting_result_is_rolled_back(): void
    {
        $php = self::settingsFile();
        $path = $this->file($php);

        try {
            ArcoFileEditor::verifiedWrite($path, str_replace('->timeout(60)', '->timeout(61)->concurrency(2)', $php), 'production', ['settings' => ['timeout' => 61]]);
            self::fail('Expected a verification failure.');
        } catch (ConfigException $e) {
            self::assertSame('editor.verify_failed', $e->key);
        }
        self::assertSame($php, file_get_contents($path));
    }

    private static function consoleFile(string $calls): string
    {
        return "<?php\nuse Multiapi\\Arco\\Config\\App;\nuse Multiapi\\Arco\\Config\\Environment;\n\n"
            . "return App::make('shop')\n    ->region('us-east-1')\n"
            . "    ->environment('production', fn (Environment \$env) => \$env\n        ->timeout(60)" . $calls . ");\n";
    }

    /** @return array<string, mixed> */
    private static function productionSettings(string $path): array
    {
        /** @var array<string, array<string, mixed>> $environments */
        $environments = (new ConfigLoader())->load($path)['environments'];
        /** @var array<string, mixed> $settings */
        $settings = $environments['production']['settings'];
        return $settings;
    }

    public function test_writes_no_argument_call(): void
    {
        $path = $this->file(self::consoleFile(''));

        self::assertTrue(ArcoFileEditor::setSetting($path, 'production', 'console', true));
        self::assertStringContainsString("->console()\n        ->timeout(60)", (string) file_get_contents($path));
        self::assertTrue(self::productionSettings($path)['console']);
    }

    public function test_replaces_no_argument_call_with_false(): void
    {
        $path = $this->file(self::consoleFile("\n        ->console()"));

        self::assertTrue(ArcoFileEditor::setSetting($path, 'production', 'console', false));
        self::assertStringContainsString('->console(false)', (string) file_get_contents($path));
        self::assertStringNotContainsString('->console()', (string) file_get_contents($path));
        self::assertFalse(self::productionSettings($path)['console']);
    }

    public function test_removes_boolean_call(): void
    {
        $path = $this->file(str_replace("->timeout(60)", "->console(false)\n        ->timeout(60)", self::consoleFile('')));

        self::assertTrue(ArcoFileEditor::setSetting($path, 'production', 'console', null));
        self::assertSame(self::consoleFile(''), file_get_contents($path));
    }

    public function test_scheduler_value_replaced(): void
    {
        $path = $this->file(self::consoleFile("\n        ->scheduler(1)"));

        self::assertTrue(ArcoFileEditor::setSetting($path, 'production', 'scheduler', 5));
        self::assertStringContainsString('->scheduler(5)', (string) file_get_contents($path));
        self::assertSame(5, self::productionSettings($path)['scheduler']);
    }

    public function test_duplicate_console_calls_are_not_edited(): void
    {
        $path = $this->file(self::consoleFile("\n        ->console()\n        ->console()"));
        $before = file_get_contents($path);

        self::assertFalse(ArcoFileEditor::setSetting($path, 'production', 'console', false));
        self::assertSame($before, file_get_contents($path));
    }

    public function test_non_literal_argument_is_not_edited(): void
    {
        $path = $this->file(self::consoleFile("\n        ->scheduler(\$every)"));
        $before = file_get_contents($path);

        self::assertFalse(ArcoFileEditor::setSetting($path, 'production', 'scheduler', 5));
        self::assertSame($before, file_get_contents($path));
    }

    public function test_renders_setting_lines(): void
    {
        self::assertSame('->concurrency(16)', ArcoFileEditor::settingLine('concurrency', 16));
        self::assertSame("->logLevel('debug')", ArcoFileEditor::settingLine('logLevel', 'debug'));
        self::assertSame('->console()', ArcoFileEditor::settingLine('console', true));
        self::assertSame('->console(false)', ArcoFileEditor::settingLine('console', false));
    }
    private static function queueFile(string $calls): string
    {
        return self::head() . "\$t = 30;\nreturn App::make('shop')\n    ->region('us-east-1')\n"
            . "    ->environment('production', fn (Environment \$env) => \$env\n        ->timeout(60)" . $calls . ");\n";
    }

    /** @return list<string> */
    private static function queueNames(string $path, string $environment = 'production'): array
    {
        return array_map(fn (\Multiapi\Arco\Config\QueueConfig $q) => $q->name, self::resolve($path, $environment)->queues);
    }

    /** @return array<string, mixed> */
    private static function rawQueues(string $path, ?string $environment = 'production'): array
    {
        $raw = (new ConfigLoader())->load($path);
        if ($environment === null) {
            /** @var array<string, mixed> $queues */
            $queues = $raw['queues'];
            return $queues;
        }
        /** @var array<string, array<string, mixed>> $environments */
        $environments = $raw['environments'];
        /** @var array<string, mixed> $queues */
        $queues = $environments[$environment]['queues'];
        return $queues;
    }

    public function test_inserts_a_queue_after_the_existing_one(): void
    {
        $path = $this->file(self::queueFile("\n        ->queue('jobs', timeout: 30)"));

        self::assertTrue(ArcoFileEditor::setQueue($path, 'production', 'emails', []));
        self::assertSame(self::queueFile("\n        ->queue('jobs', timeout: 30)\n        ->queue('emails')"), file_get_contents($path));
        self::assertSame(['jobs', 'emails'], self::queueNames($path));
        self::assertSame(['jobs' => ['timeout' => 30], 'emails' => []], self::rawQueues($path));
    }

    public function test_replaces_the_options_of_one_queue_only(): void
    {
        $path = $this->file(self::queueFile("\n        ->queue('jobs', timeout: 30)\n        ->queue('emails', tries: 2)"));

        self::assertTrue(ArcoFileEditor::setQueue($path, 'production', 'jobs', ['tries' => 5, 'timeout' => 120, 'dlq' => false, 'default' => true, 'existing' => 'legacy-jobs']));
        self::assertSame(
            self::queueFile("\n        ->queue('jobs', existing: 'legacy-jobs', default: true, timeout: 120, tries: 5, dlq: false)\n        ->queue('emails', tries: 2)"),
            file_get_contents($path),
        );
        self::assertSame(['jobs', 'emails'], self::queueNames($path));
        self::assertSame(['tries' => 2], self::rawQueues($path)['emails']);
        self::assertSame(120, self::resolve($path)->queues[0]->timeout);
    }

    public function test_removes_one_queue(): void
    {
        $path = $this->file(self::queueFile("\n        ->queue('jobs', timeout: 30)\n        ->queue('emails', tries: 2)"));

        self::assertTrue(ArcoFileEditor::setQueue($path, 'production', 'jobs', null));
        self::assertSame(self::queueFile("\n        ->queue('emails', tries: 2)"), file_get_contents($path));
        self::assertTrue(ArcoFileEditor::setQueue($path, 'production', 'jobs', null));
        self::assertSame(['emails'], self::queueNames($path));
    }

    public function test_writes_a_queue_dropped_with_false(): void
    {
        $path = $this->file(str_replace("    ->region('us-east-1')\n", "    ->region('us-east-1')\n    ->queue('jobs')\n", self::queueFile('')));

        self::assertTrue(ArcoFileEditor::setQueue($path, 'production', 'jobs', false));
        self::assertStringContainsString("=> \$env\n        ->queue('jobs', false)\n        ->timeout(60));", (string) file_get_contents($path));
        self::assertSame(['jobs' => false], self::rawQueues($path));
        self::assertSame([], self::queueNames($path));
    }

    public function test_refuses_queue_calls_it_cannot_edit(): void
    {
        $cases = [
            // non-literal option
            "\n        ->queue('jobs', timeout: \$t)",
            // the same queue twice
            "\n        ->queue('jobs', timeout: 30)\n        ->queue('jobs', tries: 2)",
            // a queue whose name is not a literal
            "\n        ->queue(\$stage)",
        ];
        foreach ($cases as $calls) {
            $php = self::queueFile($calls);
            $path = $this->file($php);
            self::assertFalse(ArcoFileEditor::setQueue($path, 'production', 'jobs', ['tries' => 4]), $calls);
            self::assertFalse(ArcoFileEditor::setQueue($path, 'production', 'jobs', null), $calls);
            self::assertSame($php, file_get_contents($path));
        }
    }

    public function test_inserts_app_level_queues_in_order(): void
    {
        $path = $this->file(self::settingsFile());

        self::assertTrue(ArcoFileEditor::setQueue($path, null, 'jobs', ['memory' => 2048]));
        self::assertTrue(ArcoFileEditor::setQueue($path, null, 'emails', ['fifo' => true, 'batchSize' => 10, 'concurrency' => 4]));
        self::assertStringContainsString(
            "    ->region('us-east-1')\n    ->queue('jobs', memory: 2048)\n    ->queue('emails', concurrency: 4, batchSize: 10, fifo: true)\n    ->concurrency(48)\n",
            (string) file_get_contents($path),
        );
        self::assertSame(['jobs' => ['memory' => 2048], 'emails' => ['concurrency' => 4, 'batchSize' => 10, 'fifo' => true]], self::rawQueues($path, null));
        self::assertSame(['jobs', 'emails'], self::queueNames($path, 'staging'));
        self::assertSame([], self::rawQueues($path));
    }

    public function test_renders_queue_lines(): void
    {
        self::assertSame("->queue('jobs')", ArcoFileEditor::queueLine('jobs', []));
        self::assertSame("->queue('jobs', false)", ArcoFileEditor::queueLine('jobs', false));
        self::assertSame("->queue('jobs', memory: 512, dlq: true)", ArcoFileEditor::queueLine('jobs', ['dlq' => true, 'memory' => 512]));
    }
}
