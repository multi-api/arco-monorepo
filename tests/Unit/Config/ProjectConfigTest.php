<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Unit\Config;

use Multiapi\Arco\Config\ConfigException;
use Multiapi\Arco\Config\ConfigLayer;
use Multiapi\Arco\Config\ProjectConfig;
use Multiapi\Arco\Tests\Support\TempPaths;
use PHPUnit\Framework\TestCase;

final class ProjectConfigTest extends TestCase
{
    private const ARCO_PHP = "<?php\nreturn \\Multiapi\\Arco\\Config\\App::make('shop')->region('us-east-1')->environment('production');\n";

    /** @param list<string> $files names under the project; values: arco.php / arco.json / go.mod */
    private function project(array $files, string $json = '{"name":"orders","region":"us-east-1","runtime":{"language":"go"},"environments":{"production":{}}}'): string
    {
        $dir = TempPaths::path('arco-project-');
        mkdir($dir);
        foreach ($files as $file) {
            file_put_contents("$dir/$file", match ($file) {
                'arco.php' => self::ARCO_PHP,
                'arco.json' => $json,
                default => "module example.com/app\n",
            });
        }
        return $dir;
    }

    private function layer(?string $name = 'cloud-app', ?string $region = 'eu-west-1'): ConfigLayer
    {
        return new ConfigLayer('layer.json', $name, $region, 'production', []);
    }

    public function test_arco_php_is_php_with_or_without_go_mod(): void
    {
        foreach ([['arco.php'], ['arco.php', 'go.mod']] as $files) {
            $result = ProjectConfig::raw($this->project($files), null);

            self::assertTrue($result['hasArcoPhp']);
            self::assertSame('shop', $result['raw']['name']);
            self::assertArrayNotHasKey('process', $result['raw']);
        }
    }

    public function test_arco_php_and_arco_json_together_are_refused(): void
    {
        $this->expectKey('config.both_files', fn () => ProjectConfig::raw($this->project(['arco.php', 'arco.json']), null));
    }

    public function test_arco_json_gives_its_process_runtime(): void
    {
        $result = ProjectConfig::raw($this->project(['arco.json']), null);

        self::assertFalse($result['hasArcoPhp']);
        self::assertSame('orders', $result['raw']['name']);
        self::assertSame(['language' => 'go'], $result['raw']['process']);
    }

    public function test_arco_json_without_runtime_uses_go_mod(): void
    {
        $json = '{"name":"orders","region":"us-east-1"}';

        $result = ProjectConfig::raw($this->project(['arco.json', 'go.mod'], $json), null);
        self::assertSame(['language' => 'go'], $result['raw']['process']);

        $this->expectKey('config.json_runtime_missing', fn () => ProjectConfig::raw($this->project(['arco.json'], $json), null));
    }

    public function test_arco_json_takes_name_and_region_from_the_layer_when_it_lacks_them(): void
    {
        $json = '{"runtime":{"language":"go"}}';
        $dir = $this->project(['arco.json'], $json);

        $result = ProjectConfig::raw($dir, $this->layer());
        self::assertFalse($result['hasArcoPhp']);
        self::assertNull($result['raw']['name']);

        $config = \Multiapi\Arco\Config\ConfigResolver::resolve($result['raw'], 'production', $this->layer());
        self::assertSame('cloud-app', $config->app);
        self::assertSame('eu-west-1', $config->region);
    }

    public function test_arco_json_without_name_or_region_and_without_a_layer_is_refused(): void
    {
        $dir = $this->project(['arco.json'], '{"region":"us-east-1","runtime":{"language":"go"}}');
        try {
            ProjectConfig::raw($dir, null);
            self::fail('expected config.json_missing');
        } catch (ConfigException $e) {
            self::assertSame('config.json_missing', $e->key);
            self::assertSame('name', $e->params['key']);
        }

        $dir = $this->project(['arco.json'], '{"name":"orders","runtime":{"language":"go"}}');
        try {
            ProjectConfig::raw($dir, $this->layer(region: null));
            self::fail('expected config.json_missing');
        } catch (ConfigException $e) {
            self::assertSame('region', $e->params['key']);
        }
    }

    public function test_a_layer_and_a_go_mod_without_a_config_file_is_a_go_process(): void
    {
        $result = ProjectConfig::raw($this->project(['go.mod']), $this->layer());

        self::assertFalse($result['hasArcoPhp']);
        self::assertSame('cloud-app', $result['raw']['name']);
        self::assertSame(['language' => 'go'], $result['raw']['process']);
    }

    public function test_a_layer_without_a_config_file_or_go_mod_is_the_layer_alone(): void
    {
        $result = ProjectConfig::raw($this->project([]), $this->layer());

        self::assertFalse($result['hasArcoPhp']);
        self::assertSame('cloud-app', $result['raw']['name']);
        self::assertArrayNotHasKey('process', $result['raw']);
    }

    public function test_arco_php_with_a_layer_keeps_arco_php(): void
    {
        $result = ProjectConfig::raw($this->project(['arco.php']), $this->layer('shop', 'us-east-1'));

        self::assertTrue($result['hasArcoPhp']);
    }

    public function test_nothing_is_config_not_found(): void
    {
        $this->expectKey('config.not_found', fn () => ProjectConfig::raw($this->project(['go.mod']), null));
        $this->expectKey('config.not_found', fn () => ProjectConfig::raw($this->project([]), null));
    }

    public function test_is_process(): void
    {
        self::assertTrue(ProjectConfig::isProcess($this->project(['arco.json'])));
        self::assertTrue(ProjectConfig::isProcess($this->project(['go.mod'])));
        self::assertFalse(ProjectConfig::isProcess($this->project(['arco.php', 'go.mod'])));
        self::assertFalse(ProjectConfig::isProcess($this->project(['arco.php'])));
        self::assertFalse(ProjectConfig::isProcess($this->project([])));
    }

    private function expectKey(string $key, callable $call): void
    {
        try {
            $call();
            self::fail("expected $key");
        } catch (ConfigException $e) {
            self::assertSame($key, $e->key);
        }
    }
}
