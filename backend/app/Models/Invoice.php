<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Audit\Auditable;
use App\Support\Tenancy\BelongsToBranch;
use App\Support\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Invoice extends Model
{
    use Auditable, BelongsToBranch, BelongsToCompany;

    public const STATUSES = ['issued', 'partially_paid', 'paid', 'void', 'refunded'];

    protected $guarded = ['id', 'company_id'];

    protected $appends = ['balance_minor'];

    protected function casts(): array
    {
        return [
            'issue_date' => 'date:Y-m-d',
            'due_date' => 'date:Y-m-d',
            'subtotal_minor' => 'integer',
            'discount_minor' => 'integer',
            'tax_minor' => 'integer',
            'total_minor' => 'integer',
            'amount_paid_minor' => 'integer',
            'amount_refunded_minor' => 'integer',
            'voided_at' => 'datetime',
        ];
    }

    public function getBalanceMinorAttribute(): int
    {
        if (in_array($this->status, ['void', 'refunded'], true)) {
            return 0;
        }

        return max(0, $this->total_minor - $this->amount_paid_minor);
    }

    public function isOverdue(): bool
    {
        return $this->balance_minor > 0 && $this->due_date->isPast();
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function serviceRequest(): BelongsTo
    {
        return $this->belongsTo(ServiceRequest::class);
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function issuedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }
}
