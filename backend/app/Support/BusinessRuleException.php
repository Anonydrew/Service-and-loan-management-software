<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;

/**
 * A request that is well-formed but breaks a business rule (approving your own
 * application, paying more than is owed, ...). Rendered as HTTP 422 with a
 * stable error_code the frontend can switch on.
 */
class BusinessRuleException extends RuntimeException
{
    /** @param array<string, mixed> $context */
    public function __construct(
        string $message,
        public readonly string $errorCode = 'business_rule',
        public readonly array $context = [],
        public readonly int $status = 422,
    ) {
        parent::__construct($message);
    }
}
