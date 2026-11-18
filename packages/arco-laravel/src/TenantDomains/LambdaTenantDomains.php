<?php declare(strict_types=1);

namespace Multiapi\Arco\Laravel\TenantDomains;

use Aws\Exception\AwsException;
use Aws\Lambda\LambdaClient;
use Multiapi\Arco\Laravel\Env;
use Multiapi\Arco\Laravel\Contracts\TenantDomains;
use Multiapi\Arco\Laravel\TenantDomains\Exceptions\InvalidTenantDomain;
use Multiapi\Arco\Laravel\TenantDomains\Exceptions\TenantDomainDnsIncorrect;
use Multiapi\Arco\Laravel\TenantDomains\Exceptions\TenantDomainException;
use Multiapi\Arco\Laravel\TenantDomains\Exceptions\TenantDomainNotFound;
use Multiapi\Arco\Laravel\TenantDomains\Exceptions\TenantDomainQuotaExceeded;
use Multiapi\Arco\Laravel\TenantDomains\Exceptions\TenantDomainRemovalPending;
use Multiapi\Arco\Laravel\TenantDomains\Exceptions\TenantDomainsUnavailable;
use Multiapi\Arco\Laravel\TenantDomains\Exceptions\TenantDomainTaken;

/** Talks to Arco's tenant domains mediator function (synchronous Lambda invoke, JSON in and out). */
final class LambdaTenantDomains implements TenantDomains
{
    public const FUNCTION_ENV = 'ARCO_TENANT_DOMAINS_FUNCTION';

    /** @param \Closure(): LambdaClient $client built on first use, never outside Arco */
    public function __construct(
        private readonly \Closure $client,
        private readonly ?string $function,
    ) {
    }

    public function add(string $domain): TenantDomainStatus
    {
        return self::toStatus(self::result($this->call(['operation' => 'add', 'domain' => $domain])));
    }

    public function status(string $domain): TenantDomainStatus
    {
        return self::toStatus(self::result($this->call(['operation' => 'status', 'domain' => $domain])));
    }

    public function list(?string $cursor = null, int $limit = 50): TenantDomainPage
    {
        if ($limit < 1 || $limit > 100) {
            throw new InvalidTenantDomain('The limit must be between 1 and 100.', 'invalid_request');
        }
        $result = self::result($this->call(['operation' => 'list', 'cursor' => $cursor, 'limit' => $limit]));
        $domains = $result['domains'] ?? null;
        $next = $result['cursor'] ?? null;
        if (! is_array($domains) || ! array_is_list($domains) || ! ($next === null || is_string($next)) || ! array_key_exists('cursor', $result)) {
            throw self::malformed();
        }
        $statuses = [];
        foreach ($domains as $entry) {
            $statuses[] = self::toStatus($entry);
        }
        return new TenantDomainPage($statuses, $next);
    }

    public function remove(string $domain): void
    {
        $result = self::result($this->call(['operation' => 'remove', 'domain' => $domain]));
        if (($result['removed'] ?? null) !== true) {
            throw self::malformed();
        }
    }

    /**
     * @param array<string, mixed> $request
     * @return array<mixed> the decoded response document
     */
    private function call(array $request): array
    {
        $function = $this->guard();
        try {
            $result = ($this->client)()->invoke([
                'FunctionName' => $function,
                'InvocationType' => 'RequestResponse',
                'Payload' => json_encode($request, JSON_THROW_ON_ERROR),
            ]);
        } catch (AwsException $e) {
            if ($e->getAwsErrorCode() === 'AccessDeniedException') {
                throw new TenantDomainsUnavailable(
                    "The function's role may not invoke the tenant domains function: it needs lambda:InvokeFunction on $function (add it to the role you gave Arco with role()).",
                    'unavailable',
                    $e,
                );
            }
            throw new TenantDomainException('Calling the tenant domains function failed: ' . ($e->getAwsErrorMessage() ?? $e->getMessage()), 'failed', $e);
        }
        $error = $result['FunctionError'] ?? null;
        if (is_string($error) && $error !== '') {
            throw new TenantDomainException("The tenant domains function failed ($error).", 'failed');
        }
        $payload = $result['Payload'] ?? null;
        $decoded = is_object($payload) && method_exists($payload, '__toString') ? json_decode((string) $payload, true) : null;
        if (! is_array($decoded)) {
            throw self::malformed();
        }
        return $decoded;
    }

    private function guard(): string
    {
        if (Env::get('ARCO') !== '1') {
            throw new TenantDomainsUnavailable('Tenant domains only work on Arco (ARCO=1); this process is not running there. Use TenantDomains::fake() in tests.');
        }
        if ($this->function === null || $this->function === '') {
            throw new TenantDomainsUnavailable('Tenant domains are not configured: add tenantDomains() to arco.php and deploy (' . self::FUNCTION_ENV . ' is not set).');
        }
        return $this->function;
    }

    /**
     * @param array<mixed> $document
     * @return array<mixed> the `result` of a successful answer; throws the typed exception of a failed one
     */
    private static function result(array $document): array
    {
        $ok = $document['ok'] ?? null;
        if ($ok === true) {
            $result = $document['result'] ?? null;
            return is_array($result) ? $result : throw self::malformed();
        }
        $error = $document['error'] ?? null;
        if ($ok !== false || ! is_array($error) || ! is_string($error['code'] ?? null) || $error['code'] === '') {
            throw self::malformed();
        }
        $code = $error['code'];
        $message = is_string($error['message'] ?? null) && $error['message'] !== '' ? $error['message'] : "Tenant domains answered $code.";
        throw match ($code) {
            'invalid_domain', 'apex_not_supported', 'reserved_domain', 'invalid_request' => new InvalidTenantDomain($message, $code),
            'dns_incorrect' => new TenantDomainDnsIncorrect($message, is_string($error['expected'] ?? null) ? $error['expected'] : null),
            'domain_taken' => new TenantDomainTaken($message),
            'quota' => new TenantDomainQuotaExceeded($message),
            'not_found' => new TenantDomainNotFound($message),
            'removal_pending' => new TenantDomainRemovalPending($message),
            'unavailable' => new TenantDomainsUnavailable($message),
            default => new TenantDomainException($message, $code),
        };
    }

    private static function toStatus(mixed $entry): TenantDomainStatus
    {
        if (! is_array($entry) || ! is_string($entry['domain'] ?? null) || ! is_string($entry['state'] ?? null)
            || ! in_array($entry['state'], TenantDomainStatus::STATES, true)
            || ! array_key_exists('reason', $entry) || ! ($entry['reason'] === null || is_string($entry['reason']))) {
            throw self::malformed();
        }
        return new TenantDomainStatus($entry['domain'], $entry['state'], $entry['reason']);
    }

    private static function malformed(): TenantDomainException
    {
        return new TenantDomainException('The tenant domains function answered something unexpected.', 'failed');
    }
}
