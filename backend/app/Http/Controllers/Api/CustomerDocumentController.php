<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\CustomerDocument;
use App\Services\Customers\DocumentService;
use App\Services\Customers\OnboardingService;
use App\Support\BusinessRuleException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CustomerDocumentController extends Controller
{
    public function __construct(
        private readonly DocumentService $documents,
        private readonly OnboardingService $onboarding,
    ) {}

    public function index(Customer $customer): JsonResponse
    {
        return response()->json([
            'data' => $customer->documents()
                ->with(['representative:id,full_name', 'uploader:id,name', 'verifier:id,name'])
                ->latest('id')
                ->get(),
        ]);
    }

    public function store(Request $request, Customer $customer): JsonResponse
    {
        $data = $request->validate([
            'file' => ['required', 'file', 'max:10240', 'mimes:jpg,jpeg,png,pdf', 'mimetypes:image/jpeg,image/png,application/pdf'],
            'document_type' => ['required', Rule::in(CustomerDocument::TYPES)],
            'representative_id' => ['nullable', 'integer'],
            'document_number' => ['nullable', 'string', 'max:60'],
            'issue_date' => ['nullable', 'date', 'before_or_equal:today'],
            'expiry_date' => ['nullable', 'date', 'after:issue_date'],
        ]);

        $document = $this->documents->upload($customer, $request->file('file'), collect($data)->except('file')->all());
        $this->onboarding->refreshVerificationStatus($customer);

        return response()->json($document->load('uploader:id,name'), 201);
    }

    public function download(Customer $customer, CustomerDocument $document): StreamedResponse
    {
        return $this->documents->download($document);
    }

    public function verify(Request $request, Customer $customer, CustomerDocument $document): JsonResponse
    {
        $data = $request->validate([
            'decision' => ['required', Rule::in(['verified', 'rejected'])],
            'reason' => ['required_if:decision,rejected', 'nullable', 'string', 'max:255'],
        ]);

        $this->documents->verify($document, $data['decision'] === 'verified', $data['reason'] ?? null);

        return response()->json($document->fresh(['verifier:id,name', 'uploader:id,name']));
    }

    public function destroy(Customer $customer, CustomerDocument $document): JsonResponse
    {
        if ($document->status === 'verified' || $customer->onboarding_status === 'completed') {
            throw new BusinessRuleException('Verified documents and documents of onboarded customers are retained and cannot be removed.', 'document_locked');
        }

        $document->delete();

        return response()->json(null, 204);
    }
}
