<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Unit\Deploy;

use Aws\Exception\AwsException;
use Aws\Result;
use Multiapi\Arco\Arco;
use Multiapi\Arco\Config\App;
use Multiapi\Arco\Config\ConfigResolver;
use Multiapi\Arco\Config\Environment;
use Multiapi\Arco\Deploy\TenantMediator;
use Multiapi\Arco\Tests\Support\TempPaths;
use PHPUnit\Framework\TestCase;

/**
 * The zip a real deploy sends: the mediator built from the package's own runtime/ directory (not a fixture),
 * so a file added to the mediator and forgotten by the packaging, or a require outside the zip, fails here
 * and not on the first invoke in AWS.
 */
final class TenantMediatorPackageTest extends TestCase
{
    /** @return array<string, string> */
    private static function packagedZip(): array
    {
        $config = ConfigResolver::resolve(App::make('shop')->region('us-east-1')->environment('production', function (Environment $e): void {
            $e->domain(['app.example.com'])->tenantDomains('routing.example.com');
        })->toArray(), 'production');
        $notFound = static fn (string $class, string $operation, string $code): AwsException => AwsMocks::error($class, $operation, $code, 404);
        $mocks = new AwsMocks();
        $mocks->handler->append(
            $notFound(\Aws\Iam\Exception\IamException::class, 'GetRole', 'NoSuchEntity'),
            $notFound(\Aws\Lambda\Exception\LambdaException::class, 'GetFunctionConfiguration', 'ResourceNotFoundException'),
            new Result(['Account' => '111111111111']),
            $notFound(\Aws\Iam\Exception\IamException::class, 'GetRole', 'NoSuchEntity'),
            new Result(['Role' => ['Arn' => 'arn:aws:iam::111111111111:role/arco-shop-production-tenants']]),
            new Result([]),
            $notFound(\Aws\Lambda\Exception\LambdaException::class, 'GetFunctionConfiguration', 'ResourceNotFoundException'),
            new Result([]),
            new Result(['State' => 'Active', 'LastUpdateStatus' => 'Successful', 'FunctionName' => 'shop-production-tenants']),
        );
        // No runtime directory given: the package's own runtime/.
        $mediator = new TenantMediator($mocks->clients(), AwsMocks::noSleep());
        $mediator->deploy($config, ['target' => 'routing.example.com', 'endpoint' => 'd1.cloudfront.net', 'distributionId' => 'E1', 'connectionGroupId' => 'cg_1'], ['arco:app' => 'shop', 'arco:environment' => 'production'], static function (): void {
        });
        $bytes = $mocks->params[7]['Code']['ZipFile'] ?? null;
        self::assertIsString($bytes);

        $path = TempPaths::file('tenant-package-zip');
        file_put_contents($path, $bytes);
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($path, \ZipArchive::RDONLY | \ZipArchive::CHECKCONS));
        $entries = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entries[(string) $zip->getNameIndex($i)] = (string) $zip->getFromIndex($i);
        }
        $zip->close();
        return $entries;
    }

    public function test_the_packaged_zip_has_the_entry_every_tenant_module_and_the_suffix_list(): void
    {
        $entries = self::packagedZip();
        $runtime = Arco::packageRoot() . '/runtime';

        self::assertArrayHasKey('tenant-domains.js', $entries);
        self::assertArrayHasKey('lib/tenants/public_suffix_list.dat', $entries);
        $modules = array_map('basename', glob($runtime . '/lib/tenants/*.js') ?: []);
        self::assertNotSame([], $modules);
        foreach ($modules as $module) {
            self::assertArrayHasKey('lib/tenants/' . $module, $entries, "lib/tenants/$module is missing from the zip");
        }
        self::assertStringContainsString('===BEGIN ICANN DOMAINS===', $entries['lib/tenants/public_suffix_list.dat'], 'the real list, with its sections');
        self::assertSame(file_get_contents($runtime . '/tenant-domains.js'), $entries['tenant-domains.js']);
    }

    public function test_nothing_in_the_zip_requires_a_file_outside_it(): void
    {
        $entries = self::packagedZip();
        $checked = [];
        $queue = ['tenant-domains.js'];
        while ($queue !== []) {
            $file = array_shift($queue);
            if (isset($checked[$file])) {
                continue;
            }
            $checked[$file] = true;
            self::assertArrayHasKey($file, $entries, "$file is required but not in the zip");
            // Every relative require('./x') or require('../x'), resolved against the requiring file.
            preg_match_all('/require\(\s*[\'"](\.{1,2}\/[^\'"]+)[\'"]\s*\)/', $entries[$file], $matches);
            foreach ($matches[1] as $relative) {
                $resolved = self::normalize(dirname($file) . '/' . $relative);
                $target = match (true) {
                    isset($entries[$resolved]) => $resolved,
                    isset($entries[$resolved . '.js']) => $resolved . '.js',
                    isset($entries[$resolved . '/index.js']) => $resolved . '/index.js',
                    default => $resolved,
                };
                $queue[] = $target;
            }
        }
        self::assertGreaterThan(1, count($checked), 'the require graph was followed');
        // Every module of lib/tenants is reachable from the entry: none shipped for nothing.
        foreach (array_keys($entries) as $name) {
            if (str_ends_with($name, '.js')) {
                self::assertArrayHasKey($name, $checked, "$name is in the zip but nothing requires it");
            }
        }
    }

    private static function normalize(string $path): string
    {
        $parts = [];
        foreach (explode('/', $path) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                self::assertNotSame([], $parts, "$path climbs out of the zip");
                array_pop($parts);
                continue;
            }
            $parts[] = $part;
        }
        return implode('/', $parts);
    }
}
