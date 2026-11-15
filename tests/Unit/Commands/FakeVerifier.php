<?php declare(strict_types=1);

namespace Multiapi\Arco\Tests\Unit\Commands;

use Multiapi\Arco\Config\AppConfig;
use Multiapi\Arco\Deploy\ColdStartLookup;
use Multiapi\Arco\Deploy\DeployResult;
use Multiapi\Arco\Deploy\VerificationResult;
use Multiapi\Arco\Deploy\Verifier;

final class FakeVerifier implements Verifier
{
    /** @var list<string> */
    public array $calls = [];

    /** the $sinceMs verify() was given */
    public ?int $sinceMs = null;

    /**
     * @param int|null $initMs cold start found at the end of the deploy, null when it stays unknown
     * @param int|null $verifyInitMs cold start verify() itself reports (an auth('iam') endpoint's init report)
     */
    public function __construct(private readonly bool $ok, private readonly ?int $initMs = 800, private readonly ?\Closure $onColdStart = null, private readonly ?int $verifyInitMs = null)
    {
    }

    public function verify(AppConfig $config, DeployResult $result, string $healthPath, int $sinceMs, ?callable $onStep = null): VerificationResult
    {
        $this->sinceMs = $sinceMs;
        return $this->ok
            ? new VerificationResult(true, 200, null, $this->verifyInitMs === null ? null : 'success', null, $this->verifyInitMs, [])
            : new VerificationResult(false, 502, null, 'failure', 'Application.InitCodeError', 120, ['{"arco":"fatal","error":"boom"}']);
    }

    public function coldStart(AppConfig $config, DeployResult $result, int $sinceMs, VerificationResult $verification, ?callable $onStep = null): VerificationResult
    {
        $this->calls[] = 'coldStart';
        if ($this->onColdStart !== null) {
            ($this->onColdStart)();
        }
        return $this->initMs === null ? $verification : $verification->withInit('success', null, $this->initMs);
    }

    public function coldStartLookup(AppConfig $config, DeployResult $result, int $sinceMs): ColdStartLookup
    {
        $this->calls[] = 'coldStartLookup';
        return new ColdStartLookup('/aws/lambda/web/' . $config->functionName(), $sinceMs, [$config->functionName() . "[$result->revisionId]"]);
    }
}
