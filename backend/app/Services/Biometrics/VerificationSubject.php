<?php

declare(strict_types=1);

namespace App\Services\Biometrics;

use App\Models\Customer;
use App\Models\CustomerRepresentative;

/**
 * The natural person being verified, with the identity record a provider
 * compares against. Passed to the provider in memory only; never persisted.
 */
final class VerificationSubject
{
    public function __construct(
        public readonly string $fullName,
        public readonly ?string $dateOfBirth,
        public readonly ?string $idType,
        public readonly ?string $idNumber,
        public readonly string $country = 'GH',
    ) {}

    public static function fromCustomer(Customer $customer): self
    {
        return new self($customer->display_name, $customer->date_of_birth?->toDateString(), $customer->id_type, $customer->id_number,
            $customer->nationality ?: 'GH');
    }

    public static function fromRepresentative(CustomerRepresentative $rep): self
    {
        return new self($rep->full_name, $rep->date_of_birth?->toDateString(), $rep->id_type, $rep->id_number);
    }
}
