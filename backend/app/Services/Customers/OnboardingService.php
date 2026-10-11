<?php

declare(strict_types=1);

namespace App\Services\Customers;

use App\Models\BiometricVerification;
use App\Models\Customer;
use App\Models\CustomerDocument;
use App\Models\CustomerRepresentative;
use App\Services\Notifications\StaffNotifier;
use App\Support\Audit\AuditLogger;
use App\Support\BusinessRuleException;
use App\Support\GhanaCard;
use App\Support\Tenancy\Tenant;
use Illuminate\Support\Facades\DB;

/**
 * Decides whether a customer has met the company's onboarding policy and
 * moves them through completion or manual review.
 *
 * Individuals are verified as themselves. A business is never "verified" by
 * face or fingerprint - its registration documents are checked, and its
 * authorised representatives (natural persons) are identity-verified.
 */
final class OnboardingService
{
    public function __construct(
        private readonly ConsentService $consents,
        private readonly StaffNotifier $staff,
    ) {}

    /**
     * @return list<array{key: string, label: string, required: bool, status: string, detail: string}>
     */
    public function checklist(Customer $customer): array
    {
        $policy = (array) Tenant::company()->setting('onboarding');
        $items = [];
        $add = function (string $key, string $label, bool $required, bool $done, string $detail, bool $inProgress = false) use (&$items): void {
            if (collect($items)->contains('key', $key)) {
                return;
            }

            $items[] = [
                'key' => $key,
                'label' => $label,
                'required' => $required,
                'status' => $done ? 'complete' : ($inProgress ? 'in_progress' : 'incomplete'),
                'detail' => $detail,
            ];
        };

        $documents = $customer->documents()->get();
        $docOk = fn (CustomerDocument $d): bool => $d->status === 'verified'
            || (! $policy['require_document_verification'] && $d->status === 'pending' && ! $d->isExpired());

        $consent = $this->consents->active($customer, 'data_processing');
        $add('consent', 'Data processing consent', true, $consent !== null,
            $consent ? 'Recorded '.$consent->granted_at?->toDateString().' ('.str_replace('_', ' ', $consent->capture_method).').' : 'Capture the customer\'s consent to process their data.');

        if ($customer->isIndividual()) {
            $detailsOk = filled($customer->first_name) && filled($customer->last_name) && filled($customer->phone)
                && $customer->date_of_birth !== null && filled($customer->id_type) && filled($customer->id_number);
            $add('details', 'Personal details', true, $detailsOk, $detailsOk ? 'Complete.' : 'Name, phone, date of birth and ID number are required.');

            if (GhanaCard::applies($customer->id_type)) {
                $this->ghanaCardItem($customer, null, $add, $customer->display_name);
            } else {
                $idDocs = $documents->filter(fn ($d) => $d->representative_id === null && in_array($d->document_type, CustomerDocument::IDENTITY_TYPES, true));
                $add('id_document', 'Identity document', (bool) $policy['individual_requires_id_document'],
                    $idDocs->contains($docOk), $this->documentDetail($idDocs), $idDocs->contains(fn ($d) => $d->status === 'pending'));
            }

            $this->biometricItems($customer, null, (bool) $policy['individual_requires_face'], (bool) $policy['individual_requires_fingerprint'], $add, $customer->display_name);
        } else {
            $detailsOk = filled($customer->business_name) && filled($customer->registration_number) && filled($customer->phone) && filled($customer->business_type);
            $add('details', 'Business details', true, $detailsOk, $detailsOk ? 'Complete.' : 'Business name, registration number, type and phone are required.');

            $regDocs = $documents->filter(fn ($d) => in_array($d->document_type, CustomerDocument::BUSINESS_TYPES, true));
            $add('registration_document', 'Business registration document', (bool) $policy['business_requires_registration_document'],
                $regDocs->contains($docOk), $this->documentDetail($regDocs), $regDocs->contains(fn ($d) => $d->status === 'pending'));

            $reps = $customer->representatives()->get();
            $add('representatives', 'Authorised representative', true, $reps->isNotEmpty(),
                $reps->isEmpty() ? 'Add at least one director or authorised signatory.' : $reps->count().' representative(s).');

            foreach ($reps->where('is_signatory', true) as $rep) {
                // A signatory identified by Ghana Card must be verified biometrically
                // whatever the company policy says: that requirement is law.
                if (GhanaCard::applies($rep->id_type)) {
                    $this->ghanaCardItem($customer, $rep, $add, $rep->full_name);
                } elseif ($policy['business_requires_representative_id']) {
                    $repDocs = $documents->filter(fn ($d) => $d->representative_id === $rep->id && in_array($d->document_type, CustomerDocument::IDENTITY_TYPES, true));
                    $add("representative_id_{$rep->id}", "Identity document - {$rep->full_name}", true,
                        $repDocs->contains($docOk), $this->documentDetail($repDocs), $repDocs->contains(fn ($d) => $d->status === 'pending'));
                }
            }

            $primary = $reps->firstWhere('is_primary', true) ?? $reps->first();
            if ($primary !== null) {
                $this->biometricItems($customer, $primary, (bool) $policy['representative_requires_face'], false, $add, $primary->full_name);
            }
        }

        return $items;
    }

    /** @return array{ready: bool, items: list<array<string, mixed>>} */
    public function readiness(Customer $customer): array
    {
        $items = $this->checklist($customer);

        return [
            'ready' => collect($items)->every(fn ($i) => ! $i['required'] || $i['status'] === 'complete'),
            'items' => $items,
        ];
    }

    public function complete(Customer $customer): Customer
    {
        $this->assertOpen($customer);
        $readiness = $this->readiness($customer);

        if (! $readiness['ready']) {
            throw new BusinessRuleException('Onboarding requirements are not yet met.', 'onboarding_incomplete', ['checklist' => $readiness['items']]);
        }

        $customer->forceFill([
            'status' => 'active',
            'onboarding_status' => 'completed',
            'verification_status' => BiometricVerification::STATUS_VERIFIED,
            'onboarded_at' => now(),
            'onboarded_by' => Tenant::user()?->id,
        ])->save();

        AuditLogger::log('customer.onboarded', $customer, "Onboarding completed for {$customer->customer_number}");

        return $customer;
    }

    public function referForManualReview(Customer $customer, string $reason): Customer
    {
        $this->assertOpen($customer);

        if (! Tenant::company()->setting('onboarding.allow_manual_review')) {
            throw new BusinessRuleException('Manual review is disabled by company policy.', 'manual_review_disabled');
        }

        if ($this->consents->active($customer, 'data_processing') === null) {
            throw new BusinessRuleException('Record data processing consent before referring for review.', 'consent_required');
        }

        $customer->forceFill([
            'onboarding_status' => 'manual_review',
            'verification_status' => BiometricVerification::STATUS_MANUAL_REVIEW,
            'manual_review_reason' => $reason,
        ])->save();

        AuditLogger::log('customer.referred_for_review', $customer, "Referred {$customer->customer_number} for manual review", ['reason' => $reason]);
        $this->staff->toPermission('onboarding.review', 'onboarding', 'Customer needs manual identity review',
            "{$customer->display_name} ({$customer->customer_number}): {$reason}", "/customers/{$customer->id}", $customer->branch_id, Tenant::user());

        return $customer;
    }

    /**
     * A reviewer accepts or rejects a customer who could not complete the
     * standard (e.g. biometric) checks. The reviewer must not be the person
     * who registered the customer.
     */
    public function decideReview(Customer $customer, bool $approve, string $notes): Customer
    {
        if ($customer->onboarding_status !== 'manual_review') {
            throw new BusinessRuleException('This customer is not awaiting manual review.', 'invalid_status');
        }

        $user = Tenant::user();
        if ($user !== null && $customer->created_by === $user->id) {
            throw new BusinessRuleException('You registered this customer, so another reviewer must decide.', 'maker_checker');
        }

        if ($approve && $this->consents->active($customer, 'data_processing') === null) {
            throw new BusinessRuleException('Data processing consent is required before approval.', 'consent_required');
        }

        // L.I. 2523: visual inspection cannot confirm a Ghana Card holder.
        if ($approve && ($pending = $this->unverifiedGhanaCardHolders($customer)) !== []) {
            throw new BusinessRuleException(
                'Ghana Card holders can only be approved after biometric verification (match-on-card or NIA register), not by inspection. Still unverified: '.implode(', ', $pending).'.',
                'ghana_card_biometric_required',
                ['unverified' => $pending],
            );
        }

        DB::transaction(function () use ($customer, $approve, $notes, $user): void {
            $customer->forceFill([
                'status' => $approve ? 'active' : 'rejected',
                'onboarding_status' => $approve ? 'completed' : 'rejected',
                'verification_status' => $approve ? BiometricVerification::STATUS_VERIFIED : BiometricVerification::STATUS_FAILED,
                'reviewed_by' => $user?->id,
                'reviewed_at' => now(),
                'review_notes' => $notes,
                'onboarded_at' => $approve ? now() : null,
                'onboarded_by' => $approve ? $user?->id : null,
            ])->save();

            AuditLogger::log($approve ? 'customer.review_approved' : 'customer.review_rejected', $customer,
                ($approve ? 'Manual review approved for ' : 'Manual review rejected for ').$customer->customer_number, ['notes' => $notes]);
        });

        $this->staff->toUser($customer->createdBy, 'onboarding', $approve ? 'Customer approved after review' : 'Customer rejected after review',
            "{$customer->display_name}: {$notes}", "/customers/{$customer->id}");

        return $customer;
    }

    /** Recomputes the customer's headline verification status while onboarding is open. */
    public function refreshVerificationStatus(Customer $customer): void
    {
        if (in_array($customer->onboarding_status, ['completed', 'manual_review', 'rejected'], true)) {
            return;
        }

        $latest = $customer->biometricVerifications()->latest('id')->first();
        $hasDocs = $customer->documents()->exists();

        $status = match (true) {
            $latest !== null && $latest->status === BiometricVerification::STATUS_FAILED => BiometricVerification::STATUS_FAILED,
            $latest !== null && $latest->status === BiometricVerification::STATUS_MANUAL_REVIEW => BiometricVerification::STATUS_MANUAL_REVIEW,
            $latest !== null && $latest->status === BiometricVerification::STATUS_CANCELLED && ! $hasDocs => BiometricVerification::STATUS_CANCELLED,
            $latest !== null || $hasDocs => BiometricVerification::STATUS_PENDING,
            default => BiometricVerification::STATUS_NOT_STARTED,
        };

        $customer->forceFill(['verification_status' => $status])->save();
    }

    /**
     * L.I. 2523: a Ghana Card holder is confirmed only by a biometric match
     * (match-on-card or the NIA register), never by an image or inspection.
     */
    private function ghanaCardItem(Customer $customer, ?CustomerRepresentative $rep, callable $add, string $subject): void
    {
        $suffix = $rep ? "_{$rep->id}" : '';

        $bioConsent = $this->consents->active($customer, 'biometric_processing', $rep?->id);
        $add("biometric_consent{$suffix}", "Biometric consent - {$subject}", true, $bioConsent !== null,
            $bioConsent ? 'Recorded.' : 'Required before the Ghana Card biometric check.');

        $attempts = $customer->biometricVerifications()->where('representative_id', $rep?->id)->latest('id')->get();
        $verified = $attempts->first(fn (BiometricVerification $a) => $a->status === BiometricVerification::STATUS_VERIFIED
            && (! $a->is_simulated || ! app()->isProduction()));
        $latest = $attempts->first();

        $detail = match (true) {
            $verified !== null => sprintf('Verified %s by %s%s.', $verified->completed_at?->toDateString(),
                str_replace('_', ' ', $verified->verification_method ?? $verified->modality), $verified->is_simulated ? ' (SIMULATED - not a real verification)' : ''),
            $latest !== null => 'Last attempt: '.str_replace('_', ' ', $latest->status).($latest->failure_reason ? ' - '.str_replace('_', ' ', $latest->failure_reason) : '').'.',
            default => 'Required by law: verify by match-on-card or against the NIA register. Ghana Card images may not be stored.',
        };

        $add("ghana_card_biometric{$suffix}", "Ghana Card biometric verification - {$subject}", true, $verified !== null, $detail,
            $latest?->status === BiometricVerification::STATUS_PENDING);
    }

    /**
     * Ghana Card holders on this customer who have not yet been verified
     * biometrically. Manual review may not approve while any remain.
     *
     * @return list<string>
     */
    public function unverifiedGhanaCardHolders(Customer $customer): array
    {
        return collect($this->checklist($customer))
            ->filter(fn ($i) => str_starts_with($i['key'], 'ghana_card_biometric') && $i['status'] !== 'complete')
            ->map(fn ($i) => trim(str_replace('Ghana Card biometric verification -', '', $i['label'])))
            ->values()
            ->all();
    }

    private function biometricItems(Customer $customer, ?CustomerRepresentative $rep, bool $face, bool $fingerprint, callable $add, string $subject): void
    {
        if (! $face && ! $fingerprint) {
            return;
        }

        $bioConsent = $this->consents->active($customer, 'biometric_processing', $rep?->id);
        $add('biometric_consent'.($rep ? "_{$rep->id}" : ''), "Biometric consent - {$subject}", true, $bioConsent !== null,
            $bioConsent ? 'Recorded.' : 'Required before any biometric capture. If declined, refer for manual review.');

        foreach (array_filter(['face' => $face, 'fingerprint' => $fingerprint]) as $modality => $_) {
            $latest = $customer->biometricVerifications()
                ->where('modality', $modality)
                ->where('representative_id', $rep?->id)
                ->latest('id')
                ->first();

            $verified = $latest !== null && $latest->status === BiometricVerification::STATUS_VERIFIED
                && (! $latest->is_simulated || ! app()->isProduction());

            $detail = match (true) {
                $latest === null => 'Not started.',
                $verified => 'Verified '.$latest->completed_at?->toDateString().($latest->is_simulated ? ' (SIMULATED - not a real verification)' : '').'.',
                default => 'Last attempt: '.str_replace('_', ' ', $latest->status).($latest->failure_reason ? ' - '.str_replace('_', ' ', $latest->failure_reason) : '').'.',
            };

            $add("{$modality}_verification".($rep ? "_{$rep->id}" : ''), ucfirst($modality)." verification - {$subject}", true, $verified, $detail,
                $latest?->status === BiometricVerification::STATUS_PENDING);
        }
    }

    /** @param \Illuminate\Support\Collection<int, CustomerDocument> $docs */
    private function documentDetail($docs): string
    {
        if ($docs->isEmpty()) {
            return 'Not uploaded.';
        }

        return $docs->map(fn ($d) => str_replace('_', ' ', $d->document_type).': '.$d->status.($d->isExpired() ? ' (expired)' : ''))->implode('; ');
    }

    private function assertOpen(Customer $customer): void
    {
        if (in_array($customer->onboarding_status, ['completed', 'rejected'], true)) {
            throw new BusinessRuleException('Onboarding for this customer is already closed.', 'onboarding_closed');
        }
    }
}
