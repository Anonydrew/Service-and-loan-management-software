<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvoiceItem extends Model
{
    use BelongsToCompany;

    public $timestamps = false;

    protected $guarded = ['id', 'company_id'];

    protected function casts(): array
    {
        return [
            'quantity' => 'string',
            'unit_price_minor' => 'integer',
            'discount_minor' => 'integer',
            'tax_rate' => 'string',
            'tax_minor' => 'integer',
            'line_total_minor' => 'integer',
        ];
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }
}
