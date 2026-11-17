<?php declare(strict_types=1);

namespace Multiapi\Arco\Deploy\Domains;

use Multiapi\Arco\Deploy\DeployException;

/**
 * The DNS record of a tenantDomains() target: a CNAME to the connection group's routing endpoint, in
 * Route 53, in Cloudflare or created by the user. A record is only taken as this environment's when it
 * is exactly the CNAME to $ownedEndpoint, which callers read from the environment's own verified stack
 * (TenantDomains); anything else at the name is someone else's and is never changed.
 *
 * @internal
 */
interface TenantTarget
{
    /**
     * Read-only: refuses a target without a public zone, at the apex of its zone, or holding records
     * that are not this environment's. Anything that cannot be read is a refusal.
     *
     * @throws DeployException domains.tenant_target_zone_missing, domains.tenant_target_apex,
     *         domains.tenant_target_occupied, domains.tenant_target_unverified
     */
    public function check(string $target, ?string $ownedEndpoint): void;

    /**
     * Leaves the target as a CNAME to $endpoint, checked again on a fresh read, and returns once it is
     * verified. Never overwrites a record; an existing one must already be the environment's.
     *
     * @throws DeployException as check(), plus domains.tenant_target_change, domains.tenant_manual_cname_missing
     */
    public function publish(string $target, string $endpoint, ?string $ownedEndpoint): void;
}
