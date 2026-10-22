<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Support;

use Multiapi\Arco\Build\FrameworkPreset;
use Multiapi\Arco\Build\RequiresPackages;

/** A plain preset that needs some Composer packages in the app (as Laravel needs laravel/octane). */
final class RequiringPreset implements FrameworkPreset, RequiresPackages
{
    /** @param list<string> $packages */
    public function __construct(private readonly array $packages = ['laravel/octane'])
    {
    }

    public function name(): string { return 'requiring'; }
    public function detects(string $projectDir): bool { return true; }
    public function prepare(string $appDir): void {}
    public function buildSteps(): array { return []; }
    public function workerScript(): ?string { return null; }
    public function publicDir(): string { return 'public'; }
    public function defaultVariables(): array { return []; }
    public function excludeRules(): array { return []; }
    public function healthPath(): string { return '/'; }
    public function requiredPackages(): array { return $this->packages; }
}
