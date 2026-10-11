<?php

declare(strict_types=1);

namespace App\Services\Customers;

use App\Models\Customer;
use App\Models\CustomerDocument;
use App\Support\Audit\AuditLogger;
use App\Support\BusinessRuleException;
use App\Support\Tenancy\Tenant;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Identity and business documents live on the private disk, outside the web
 * root, and are only ever streamed through an authorised, audited endpoint.
 */
final class DocumentService
{
    public const DISK = 'local';

    /** @param array<string, mixed> $data */
    public function upload(Customer $customer, UploadedFile $file, array $data): CustomerDocument
    {
        if (! empty($data['representative_id'])
            && ! $customer->representatives()->whereKey($data['representative_id'])->exists()) {
            throw new BusinessRuleException('That representative does not belong to this customer.', 'invalid_representative');
        }

        $extension = strtolower($file->extension() ?: $file->getClientOriginalExtension());
        $path = $file->storeAs(
            sprintf('customer-documents/%d/%d', Tenant::id(), $customer->id),
            Str::uuid()->toString().'.'.$extension,
            self::DISK,
        );

        $document = new CustomerDocument($data);
        $document->customer()->associate($customer);
        $document->forceFill([
            'file_path' => $path,
            'original_name' => Str::limit($file->getClientOriginalName(), 200, ''),
            'mime_type' => (string) $file->getMimeType(),
            'size_bytes' => (int) $file->getSize(),
            'checksum' => hash_file('sha256', $file->getRealPath()),
            'status' => 'pending',
            'uploaded_by' => Tenant::user()?->id,
        ])->save();

        return $document;
    }

    public function verify(CustomerDocument $document, bool $approve, ?string $reason): CustomerDocument
    {
        if ($document->status !== 'pending') {
            throw new BusinessRuleException('This document has already been reviewed.', 'already_reviewed');
        }

        $user = Tenant::user();
        if ($approve && Tenant::company()->setting('onboarding.document_four_eyes', true)
            && $user !== null && $document->uploaded_by === $user->id) {
            throw new BusinessRuleException('A document must be verified by someone other than the person who uploaded it.', 'four_eyes');
        }

        if ($approve && $document->isExpired()) {
            throw new BusinessRuleException('This document has expired and cannot be accepted.', 'document_expired');
        }

        $document->forceFill([
            'status' => $approve ? 'verified' : 'rejected',
            'verified_by' => $user?->id,
            'verified_at' => now(),
            'rejection_reason' => $approve ? null : $reason,
        ])->save();

        AuditLogger::log($approve ? 'customer_document.verified' : 'customer_document.rejected', $document,
            ($approve ? 'Verified ' : 'Rejected ').str_replace('_', ' ', $document->document_type), ['reason' => $reason]);

        return $document;
    }

    public function download(CustomerDocument $document): StreamedResponse
    {
        AuditLogger::log('customer_document.downloaded', $document, 'Downloaded '.str_replace('_', ' ', $document->document_type));

        return Storage::disk(self::DISK)->download($document->file_path, $document->original_name, [
            'Content-Type' => $document->mime_type,
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
