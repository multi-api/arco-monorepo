<?php declare(strict_types=1);

namespace Multiapi\Arco\Config;

final readonly class RuntimeConfig
{
    /**
     * @param array<string, string|int|bool> $phpIni
     * @param string|null $php the PHP minor php() selects; null: the manifest's default
     * @param string|null $phpBinary the PHP CLI given to binary(php: ...), next to the custom $binary
     */
    public function __construct(
        public ?string $build,
        public ?string $frankenphp,
        public ?string $binary,
        public int $workersMin,
        public int $workersMax,
        public int $threads,
        public string $mode,
        public array $phpIni,
        public ?string $php = null,
        public ?string $phpBinary = null,
    ) {
    }
}
