<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Audit\Auditable;
use App\Support\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Branch extends Model
{
    use Auditable, BelongsToCompany;

    protected $fillable = ['name', 'code', 'phone', 'email', 'address', 'city', 'region', 'is_head_office', 'is_active'];

    protected function casts(): array
    {
        return ['is_head_office' => 'boolean', 'is_active' => 'boolean'];
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class);
    }
}
