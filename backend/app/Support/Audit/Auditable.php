<?php

declare(strict_types=1);

namespace App\Support\Audit;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Captures every create/update/delete on the model, including changes written
 * by code that never thought about auditing. Services add intent with
 * explicit AuditLogger::log() calls.
 */
trait Auditable
{
    public static function bootAuditable(): void
    {
        static::created(fn (Model $m) => self::recordAudit($m, 'created'));
        static::updated(fn (Model $m) => self::recordAudit($m, 'updated'));
        static::deleted(fn (Model $m) => self::recordAudit($m, 'deleted'));
    }

    private static function recordAudit(Model $model, string $event): void
    {
        $changes = null;

        if ($event === 'updated') {
            $changes = [];
            foreach ($model->getChanges() as $key => $new) {
                if (in_array($key, ['updated_at', 'created_at', 'last_login_at', 'last_login_ip'], true)) {
                    continue;
                }
                $changes[$key] = ['old' => $model->getRawOriginal($key), 'new' => $model->getAttributes()[$key] ?? $new];
            }

            if ($changes === []) {
                return;
            }
        }

        AuditLogger::log(Str::snake(class_basename($model)).'.'.$event, $model, changes: $changes);
    }
}
