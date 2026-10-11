<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use App\Models\Company;
use App\Models\User;
use Closure;
use RuntimeException;

/**
 * Holds the company (and acting user) the current request runs as.
 *
 * Registered as a scoped singleton, so each request - and each test - starts
 * empty. Access it through the static App\Support\Tenancy\Tenant helper.
 */
final class TenantManager
{
    private ?Company $company = null;

    private ?User $user = null;

    /** @var array<int, int>|null null means "every branch of the company" */
    private ?array $branchIds = null;

    private int $bypassDepth = 0;

    public function set(Company $company, ?User $user = null): void
    {
        if ($user !== null && (int) $user->company_id !== (int) $company->getKey()) {
            throw new RuntimeException('User does not belong to the company being bound.');
        }

        $this->company = $company;
        $this->user = $user;
        $this->branchIds = $user?->accessibleBranchIds();
    }

    public function forget(): void
    {
        $this->company = null;
        $this->user = null;
        $this->branchIds = null;
    }

    public function has(): bool
    {
        return $this->company !== null;
    }

    public function id(): int
    {
        return (int) $this->company()->getKey();
    }

    public function idOrNull(): ?int
    {
        return $this->company?->getKey();
    }

    public function company(): Company
    {
        return $this->company ?? throw new MissingTenantException('No company is bound to the current context.');
    }

    public function user(): ?User
    {
        return $this->user;
    }

    /** @return array<int, int>|null */
    public function branchIds(): ?array
    {
        return $this->branchIds;
    }

    public function canAccessBranch(?int $branchId): bool
    {
        return $this->branchIds === null || ($branchId !== null && in_array($branchId, $this->branchIds, true));
    }

    public function runAs(Company $company, Closure $callback, ?User $user = null): mixed
    {
        $previous = [$this->company, $this->user, $this->branchIds];
        $this->set($company, $user);

        try {
            return $callback();
        } finally {
            [$this->company, $this->user, $this->branchIds] = $previous;
        }
    }

    /** Escape hatch for system jobs that legitimately span companies. */
    public function withoutScopes(Closure $callback): mixed
    {
        $this->bypassDepth++;

        try {
            return $callback();
        } finally {
            $this->bypassDepth--;
        }
    }

    public function bypassed(): bool
    {
        return $this->bypassDepth > 0;
    }
}
