<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use App\Models\Company;
use App\Models\User;
use Closure;

/**
 * Static accessor for the request's TenantManager.
 *
 * @method static void set(Company $company, ?User $user = null)
 * @method static void forget()
 * @method static bool has()
 * @method static int id()
 * @method static int|null idOrNull()
 * @method static Company company()
 * @method static User|null user()
 * @method static array<int, int>|null branchIds()
 * @method static bool canAccessBranch(?int $branchId)
 * @method static mixed runAs(Company $company, Closure $callback, ?User $user = null)
 * @method static mixed withoutScopes(Closure $callback)
 * @method static bool bypassed()
 */
final class Tenant
{
    public static function __callStatic(string $method, array $arguments): mixed
    {
        return app(TenantManager::class)->{$method}(...$arguments);
    }
}
