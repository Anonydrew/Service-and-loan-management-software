<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use RuntimeException;

/** Thrown instead of running a tenant query unfiltered. */
final class MissingTenantException extends RuntimeException
{
    public static function forModel(string $model): self
    {
        return new self("Refusing to query or create [{$model}] without a company context.");
    }
}
