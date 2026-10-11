<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\CustomerRepresentative;
use App\Services\Customers\CustomerService;
use App\Support\BusinessRuleException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class RepresentativeController extends Controller
{
    public function __construct(private readonly CustomerService $customers) {}

    public function store(Request $request, Customer $customer): JsonResponse
    {
        $rep = $this->customers->addRepresentative($customer, $request->validate($this->rules()));

        return response()->json($rep, 201);
    }

    public function update(Request $request, Customer $customer, CustomerRepresentative $representative): JsonResponse
    {
        $data = $request->validate($this->rules(true));

        if (! empty($data['is_primary'])) {
            $customer->representatives()->whereKeyNot($representative->id)->update(['is_primary' => false]);
        }

        // Changing who the person is invalidates any earlier verification of them.
        $identityChanged = array_key_exists('id_number', $data) && $data['id_number'] !== $representative->id_number;
        $representative->fill($data);
        if ($identityChanged) {
            $representative->verification_status = 'not_started';
        }
        $representative->save();

        return response()->json($representative);
    }

    public function destroy(Customer $customer, CustomerRepresentative $representative): JsonResponse
    {
        if ($customer->onboarding_status === 'completed' && $customer->representatives()->count() <= 1) {
            throw new BusinessRuleException('An onboarded business must keep at least one representative.', 'last_representative');
        }

        $representative->delete();

        return response()->json(null, 204);
    }

    /** @return array<string, mixed> */
    private function rules(bool $updating = false): array
    {
        return [
            'full_name' => [$updating ? 'sometimes' : 'required', 'string', 'max:190'],
            'position' => ['nullable', 'string', 'max:100'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email'],
            'date_of_birth' => ['nullable', 'date', 'before:today'],
            'id_type' => ['nullable', Rule::in(Customer::ID_TYPES)],
            'id_number' => ['nullable', 'string', 'max:40'],
            'is_primary' => ['boolean'],
            'is_signatory' => ['boolean'],
        ];
    }
}
