<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Rules from the National Identity Register (Amendment) Regulations, 2026
 * (L.I. 2523), in force 9 June 2026 (transition to 2 November 2026):
 *
 *  - A Ghana Card holder's identity must be confirmed by biometric
 *    authentication: match-on-card, or against the National Identity
 *    Register using an NIA-approved device / the NIA IVSP.
 *  - Organisations may not request, reproduce or retain a photocopy, scan or
 *    image of the Ghana Card, and may not rely on visual inspection.
 *
 * These are legal requirements, so they are not company-configurable.
 */
final class GhanaCard
{
    public const ID_TYPE = 'ghana_card';

    /** Methods a verification provider may report for a Ghana Card check. */
    public const METHODS = ['match_on_card', 'nia_register'];

    public const LAW = 'L.I. 2523 (National Identity Register (Amendment) Regulations, 2026)';

    public static function applies(?string $idType): bool
    {
        return $idType === self::ID_TYPE;
    }

    /** True when two Ghana Card numbers refer to the same card, ignoring formatting. */
    public static function sameNumber(?string $a, ?string $b): bool
    {
        $hashA = IdentityHash::make($a);

        return $hashA !== null && hash_equals($hashA, (string) IdentityHash::make($b));
    }
}
