<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServiceRequestUpdate extends Model
{
    use BelongsToCompany;

    protected $guarded = ['id', 'company_id'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
