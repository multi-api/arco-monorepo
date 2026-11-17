<?php declare(strict_types=1);

namespace Multiapi\Arco\Laravel\Contracts;

use Multiapi\Arco\Laravel\TenantDomains\Exceptions\TenantDomainException;
use Multiapi\Arco\Laravel\TenantDomains\Exceptions\TenantDomainRemovalPending;
use Multiapi\Arco\Laravel\TenantDomains\TenantDomainPage;
use Multiapi\Arco\Laravel\TenantDomains\TenantDomainStatus;

/** Customer domains served by this Arco environment (CloudFront multi-tenant distribution). */
interface TenantDomains
{
    /**
     * Adds a customer domain. A new domain answers `pending`; adding a domain again (a retry) answers its
     * current state.
     *
     * @throws TenantDomainException see the subclasses in Multiapi\Arco\Laravel\TenantDomains\Exceptions
     */
    public function add(string $domain): TenantDomainStatus;

    /**
     * The current state of a domain of this environment.
     *
     * @throws \Multiapi\Arco\Laravel\TenantDomains\Exceptions\TenantDomainNotFound when the environment has no such domain
     * @throws TenantDomainException
     */
    public function status(string $domain): TenantDomainStatus;

    /**
     * Lists the domains, a page at a time. Pass the previous page's cursor to continue. A page can be empty
     * and still carry a cursor: only a `null` cursor is the end. The listing is not a snapshot: under
     * concurrent changes a domain can be missed or seen twice.
     *
     * @param int $limit 1 to 100
     * @throws TenantDomainException
     */
    public function list(?string $cursor = null, int $limit = 50): TenantDomainPage;

    /**
     * Removes a domain. Idempotent (removing an unknown domain succeeds). The domain stops being served at
     * once; CloudFront may not allow deleting it yet, in which case this throws
     * {@see TenantDomainRemovalPending}: call it again later.
     *
     * @throws TenantDomainRemovalPending
     * @throws TenantDomainException
     */
    public function remove(string $domain): void;
}
