<?php declare(strict_types=1);

namespace Multiapi\Arco\Deploy;

final readonly class AppStatus
{
    public function __construct(
        public string $functionName,
        public string $state,
        public ?string $domainName,
        public ?string $activeRevisionId,
        public ?string $distributionDomain = null,
        public ?string $distributionStatus = null,
        public ?string $consoleFunction = null,
        /** version the console's live alias points to; null when not deployed or unreadable */
        public ?string $consoleVersion = null,
    ) {
    }
}
