<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Unit\Commands;

use Multiapi\Arco\Config\AppConfig;
use Multiapi\Arco\Deploy\DeployResult;
use Multiapi\Arco\Deploy\VerificationResult;
use Multiapi\Arco\Deploy\Verifier;

final class FakeVerifier implements Verifier
{
    public function __construct(private readonly bool $ok, private readonly ?int $initMs = 800)
    {
    }

    public function verify(AppConfig $config, DeployResult $result, string $healthPath, int $sinceMs, ?callable $onStep = null): VerificationResult
    {
        return $this->ok
            ? new VerificationResult(true, 200, null, $this->initMs === null ? null : 'success', null, $this->initMs, [])
            : new VerificationResult(false, 502, null, 'failure', 'Application.InitCodeError', 120, ['{"arco":"fatal","error":"boom"}']);
    }
}
