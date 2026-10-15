<?php declare(strict_types=1);

namespace Multiapi\Arco\Tinker;

use Multiapi\Arco\Config\AppConfig;
use Multiapi\Arco\Console\Console;

/** One tinker session: each statement goes to the console function with the scope of the last answer. */
final class TinkerSession
{
    /** Must match Multiapi\Arco\Laravel\Console\TinkerCommand::MARKER (multiapi/arco-laravel). */
    public const MARKER = 'ARCO-TINKER:';

    private ?string $context = null;

    public function __construct(private readonly Console $console, private readonly AppConfig $config)
    {
    }

    public function execute(string $code): TinkerReply
    {
        $reply = TinkerReply::parse($this->console->tinker($this->config, $code, $this->context));
        if ($reply->replied) {
            $this->context = $reply->context;
        }
        return $reply;
    }

    public function context(): ?string
    {
        return $this->context;
    }
}
