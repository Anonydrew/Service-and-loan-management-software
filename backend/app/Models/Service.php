<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Audit\Auditable;
use App\Support\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class Service extends Model
{
    use Auditable, BelongsToCompany;

    protected $fillable = [
        'name', 'code', 'category', 'description', 'price_minor', 'price_is_variable', 'tax_rate',
        'duration_minutes', 'requires_appointment', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'price_minor' => 'integer',
            'price_is_variable' => 'boolean',
            'tax_rate' => 'string',
            'duration_minutes' => 'integer',
            'requires_appointment' => 'boolean',
            'is_active' => 'boolean',
        ];
    }
}
