<?php declare(strict_types=1);

namespace Multiapi\Arco\Config;

final class Runtime
{
    /** @var array<string, mixed> */
    private array $values = [];

    /**
     * The newest runtime build whose PHP is this minor (such as '8.5'). Without php(), the default PHP
     * minor of this Arco release, which a 1.x release never moves to another minor.
     */
    public function php(string $minor): static { $this->values['php'] = $minor; return $this; }

    /** Only builds whose FrankenPHP version starts with this one ('1.13' or '1.13.0'); combines with php(). */
    public function frankenphp(string $version): static { $this->values['frankenphp'] = $version; return $this; }

    /** An exact runtime build id ('php8.5.11-fp1.13.0-r2', or an id of an earlier Arco such as '1.13.0-r2'); excludes php() and frankenphp(). */
    public function build(string $id): static { $this->values['build'] = $id; return $this; }

    /**
     * Your own Linux arm64 FrankenPHP binary instead of a runtime build. With $php (a static PHP CLI with
     * the same PHP version and extensions), the console function and queue workers run it as bin/php;
     * without it they download the FrankenPHP binary at cold start.
     */
    public function binary(string $frankenphp, ?string $php = null): static
    {
        $this->values['binary'] = $frankenphp;
        $this->values['phpBinary'] = $php;
        return $this;
    }

    public function workers(int $min, int $max): static { $this->values['workers'] = ['min' => $min, 'max' => $max]; return $this; }
    public function threads(int $threads): static { $this->values['threads'] = $threads; return $this; }
    public function mode(string $mode): static { $this->values['mode'] = $mode; return $this; }

    /** @param array<string, string|int|bool> $ini */
    public function phpIni(array $ini): static
    {
        /** @var array<string, string|int|bool> $current */
        $current = $this->values['phpIni'] ?? [];
        $this->values['phpIni'] = [...$current, ...$ini];
        return $this;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->values;
    }
}
