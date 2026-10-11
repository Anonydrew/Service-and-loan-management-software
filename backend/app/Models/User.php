<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Audit\Auditable;
use App\Support\Tenancy\Tenant;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

/**
 * Staff user. Deliberately not company-scoped by a global scope (sign-in has to
 * find the user before any company is known). Staff queries filter on
 * company_id explicitly via inCompany(), and route binding is restricted below.
 */
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use Auditable, HasApiTokens, HasFactory, Notifiable;

    protected $fillable = ['name', 'email', 'phone', 'job_title', 'password', 'primary_branch_id'];

    protected $hidden = ['password', 'remember_token'];

    /** @var array<int, string>|null */
    private ?array $permissionCache = null;

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'locked_until' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function primaryBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'primary_branch_id')->withoutGlobalScopes();
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class)->withoutGlobalScopes();
    }

    public function branches(): BelongsToMany
    {
        return $this->belongsToMany(Branch::class)->withoutGlobalScopes();
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /** @return array<int, string> */
    public function permissions(): array
    {
        return $this->permissionCache ??= $this->roles()
            ->where('roles.company_id', $this->company_id)
            ->get(['roles.permissions'])
            ->pluck('permissions')
            ->flatten()
            ->unique()
            ->values()
            ->all();
    }

    public function hasPermission(string $code): bool
    {
        return in_array($code, $this->permissions(), true);
    }

    public function flushPermissionCache(): void
    {
        $this->permissionCache = null;
    }

    /** @return array<int, int>|null null = every branch in the company */
    public function accessibleBranchIds(): ?array
    {
        if ($this->hasPermission('branches.all')) {
            return null;
        }

        $ids = $this->branches()
            ->where('branches.company_id', $this->company_id)
            ->pluck('branches.id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if ($this->primary_branch_id !== null) {
            $ids[] = (int) $this->primary_branch_id;
        }

        return array_values(array_unique($ids));
    }

    /** Route binding never crosses companies. */
    public function resolveRouteBinding($value, $field = null): ?Model
    {
        return $this->newQuery()
            ->where($field ?? $this->getRouteKeyName(), $value)
            ->where('company_id', Tenant::id())
            ->firstOrFail();
    }

    public function scopeInCompany(Builder $query): Builder
    {
        return $query->where('users.company_id', Tenant::id());
    }
}
