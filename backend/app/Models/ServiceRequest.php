<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Audit\Auditable;
use App\Support\Money;
use App\Support\Tenancy\BelongsToBranch;
use App\Support\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ServiceRequest extends Model
{
    use Auditable, BelongsToBranch, BelongsToCompany;

    public const STATUSES = ['new', 'scheduled', 'in_progress', 'on_hold', 'completed', 'cancelled'];

    public const PRIORITIES = ['low', 'normal', 'high', 'urgent'];

    /** Allowed status transitions. Completed and cancelled are terminal. */
    public const TRANSITIONS = [
        'new' => ['scheduled', 'in_progress', 'on_hold', 'cancelled'],
        'scheduled' => ['in_progress', 'on_hold', 'cancelled', 'new'],
        'in_progress' => ['on_hold', 'completed', 'cancelled'],
        'on_hold' => ['in_progress', 'scheduled', 'cancelled'],
        'completed' => [],
        'cancelled' => [],
    ];

    protected $fillable = [
        'customer_id', 'service_id', 'priority', 'description', 'quantity', 'unit_price_minor', 'discount_minor',
        'scheduled_at', 'assigned_to',
    ];

    protected $appends = ['total_minor'];

    protected function casts(): array
    {
        return [
            'quantity' => 'string',
            'unit_price_minor' => 'integer',
            'discount_minor' => 'integer',
            'scheduled_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function getTotalMinorAttribute(): int
    {
        $gross = (int) Money::roundHalfUp(bcmul((string) $this->unit_price_minor, (string) $this->quantity, 4));

        return max(0, $gross - (int) $this->discount_minor);
    }

    public function canTransitionTo(string $status): bool
    {
        return in_array($status, self::TRANSITIONS[$this->status] ?? [], true);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function updates(): HasMany
    {
        return $this->hasMany(ServiceRequestUpdate::class)->latest();
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
