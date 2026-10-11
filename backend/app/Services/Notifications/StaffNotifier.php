<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Models\User;
use App\Notifications\SystemAlert;
use App\Support\Tenancy\Tenant;
use Throwable;

/**
 * Sends in-app notifications to staff. Recipients are chosen by permission and
 * branch access, so nobody is told about a record they could not open.
 */
final class StaffNotifier
{
    public function toPermission(
        string $permission,
        string $category,
        string $title,
        string $body,
        ?string $link = null,
        ?int $branchId = null,
        ?User $except = null,
    ): void {
        $recipients = User::query()
            ->inCompany()
            ->where('status', 'active')
            ->when($except, fn ($q) => $q->whereKeyNot($except->id))
            ->get()
            ->filter(function (User $user) use ($permission, $branchId): bool {
                if (! $user->hasPermission($permission)) {
                    return false;
                }

                $branches = $user->accessibleBranchIds();

                return $branchId === null || $branches === null || in_array($branchId, $branches, true);
            });

        foreach ($recipients as $user) {
            $this->toUser($user, $category, $title, $body, $link);
        }
    }

    public function toUser(?User $user, string $category, string $title, string $body, ?string $link = null): void
    {
        if ($user === null || (int) $user->company_id !== Tenant::id()) {
            return;
        }

        try {
            $user->notify(new SystemAlert($category, $title, $body, $link));
        } catch (Throwable $e) {
            // A failed notification must never undo the business action that triggered it.
            report($e);
        }
    }
}
