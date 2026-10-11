<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Audit\Auditable;
use App\Support\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Account extends Model
{
    use Auditable, BelongsToCompany;

    public const TYPES = ['asset', 'liability', 'equity', 'income', 'expense'];

    /** Debit-normal account types; the rest are credit-normal. */
    public const DEBIT_NORMAL = ['asset', 'expense'];

    protected $fillable = ['code', 'name', 'type', 'description', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class);
    }

    public function isDebitNormal(): bool
    {
        return in_array($this->type, self::DEBIT_NORMAL, true);
    }
}
