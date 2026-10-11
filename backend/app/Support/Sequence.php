<?php

declare(strict_types=1);

namespace App\Support;

use App\Support\Tenancy\Tenant;
use Illuminate\Support\Facades\DB;

/**
 * Gap-free, per-company document numbers. The counter row is locked for the
 * duration of the caller's transaction, so two cashiers can never be issued
 * the same receipt number.
 */
final class Sequence
{
    public const PREFIXES = [
        'customer' => 'CUS',
        'loan_application' => 'APP',
        'loan' => 'LN',
        'repayment' => 'RCP',
        'service_request' => 'SR',
        'invoice' => 'INV',
        'payment' => 'PAY',
        'journal' => 'JE',
        'expense' => 'EXP',
    ];

    public static function next(string $key, ?int $companyId = null): string
    {
        $companyId ??= Tenant::id();
        $prefix = self::PREFIXES[$key] ?? strtoupper($key);

        return DB::transaction(function () use ($key, $companyId, $prefix): string {
            $row = DB::table('sequences')
                ->where('company_id', $companyId)
                ->where('key', $key)
                ->lockForUpdate()
                ->first();

            if ($row === null) {
                DB::table('sequences')->insert([
                    'company_id' => $companyId,
                    'key' => $key,
                    'next_value' => 2,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $value = 1;
            } else {
                $value = (int) $row->next_value;
                DB::table('sequences')->where('id', $row->id)->update([
                    'next_value' => $value + 1,
                    'updated_at' => now(),
                ]);
            }

            return sprintf('%s-%06d', $prefix, $value);
        });
    }
}
