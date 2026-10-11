<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Audit\Auditable;
use App\Support\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CollectionActivity extends Model
{
    use Auditable, BelongsToCompany;

    public const TYPES = ['call', 'sms', 'visit', 'promise_to_pay', 'letter', 'other'];

    protected $fillable = ['activity_type', 'outcome', 'notes', 'promise_date', 'promise_amount_minor', 'next_action_date'];

    protected function casts(): array
    {
        return [
            'promise_date' => 'date:Y-m-d',
            'next_action_date' => 'date:Y-m-d',
            'promise_amount_minor' => 'integer',
        ];
    }

    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
