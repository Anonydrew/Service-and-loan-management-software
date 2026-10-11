<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Service;
use App\Support\Tenancy\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ServiceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'data' => Service::query()
                ->when($request->boolean('active_only'), fn ($q) => $q->where('is_active', true))
                ->when($request->filled('q'), fn ($q) => $q->where('name', 'like', '%'.$request->input('q').'%'))
                ->orderBy('category')->orderBy('name')
                ->get(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $service = new Service($this->validated($request));
        $service->save();

        return response()->json($service, 201);
    }

    public function update(Request $request, Service $service): JsonResponse
    {
        $service->fill($this->validated($request, $service))->save();

        return response()->json($service);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?Service $service = null): array
    {
        return $this->minor($request->validate([
            'name' => ['required', 'string', 'max:150'],
            'code' => ['required', 'string', 'max:20', 'alpha_dash',
                Rule::unique('services', 'code')->where('company_id', Tenant::id())->ignore($service?->id)],
            'category' => ['nullable', 'string', 'max:80'],
            'description' => ['nullable', 'string', 'max:2000'],
            'price' => ['required', 'numeric', 'min:0'],
            'price_is_variable' => ['boolean'],
            'tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'duration_minutes' => ['nullable', 'integer', 'min:1', 'max:10080'],
            'requires_appointment' => ['boolean'],
            'is_active' => ['boolean'],
        ]), ['price' => 'price_minor']);
    }
}
