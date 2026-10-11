<?php

declare(strict_types=1);

namespace App\Support\Audit;

use App\Models\AuditLog;
use App\Support\Tenancy\Tenant;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * Writes the append-only audit trail. Entries are written synchronously so
 * that, inside a transaction, they commit or roll back with the change they
 * describe.
 */
final class AuditLogger
{
    /** Attribute names whose values never reach the audit log. */
    private const REDACTED = [
        'password', 'remember_token', 'id_number', 'document_number', 'provider_reference',
        'provider_session_id', 'metadata', 'client_nonce', 'token', 'secret',
    ];

    /**
     * @param  array<string, mixed>  $metadata
     * @param  array<string, array{old: mixed, new: mixed}>|null  $changes
     */
    public static function log(
        string $action,
        ?Model $subject = null,
        ?string $description = null,
        array $metadata = [],
        ?array $changes = null,
        ?int $companyId = null,
    ): void {
        $user = Tenant::user() ?? self::safely(fn () => auth()->user());
        $request = app()->bound('request') ? request() : null;

        try {
            AuditLog::query()->create([
                'company_id' => $companyId
                    ?? ($subject?->getAttribute('company_id') !== null ? (int) $subject->getAttribute('company_id') : null)
                    ?? Tenant::idOrNull(),
                'user_id' => $user?->getKey(),
                'actor_label' => $user ? $user->name.' <'.$user->email.'>' : 'system',
                'action' => $action,
                'auditable_type' => $subject ? class_basename($subject) : null,
                'auditable_id' => $subject?->getKey(),
                'description' => $description,
                'changes' => $changes === null ? null : self::redact($changes),
                'metadata' => $metadata === [] ? null : self::redact($metadata),
                'ip_address' => $request?->ip(),
                'user_agent' => $request ? substr((string) $request->userAgent(), 0, 500) : null,
                'created_at' => now(),
            ]);
        } catch (Throwable $e) {
            // Losing an audit line must never be silent, but it also must not
            // turn a successful business action into a 500 for the user.
            report($e);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function redact(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_string($key) && in_array($key, self::REDACTED, true)) {
                $data[$key] = '[redacted]';
            } elseif (is_array($value)) {
                $data[$key] = self::redact($value);
            }
        }

        return $data;
    }

    private static function safely(callable $callback): mixed
    {
        try {
            return $callback();
        } catch (Throwable) {
            return null;
        }
    }
}
