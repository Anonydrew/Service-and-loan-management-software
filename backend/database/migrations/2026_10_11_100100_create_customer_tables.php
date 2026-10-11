<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Customers, onboarding evidence and biometric verification attempts.
 *
 * Identity numbers are stored encrypted, with a keyed hash beside them so
 * duplicates can be found without decrypting. No biometric image or template is
 * ever stored here: biometric_verifications holds only the outcome, an opaque
 * provider reference and audit metadata.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->string('customer_number', 30);
            $table->string('type', 20); // individual | business
            $table->string('display_name');

            // Individual
            $table->string('title', 20)->nullable();
            $table->string('first_name')->nullable();
            $table->string('middle_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('gender', 20)->nullable();
            $table->date('date_of_birth')->nullable();
            $table->string('marital_status', 20)->nullable();
            $table->string('nationality', 2)->nullable();
            $table->string('occupation')->nullable();
            $table->string('employer')->nullable();

            // Business
            $table->string('business_name')->nullable();
            $table->string('trading_name')->nullable();
            $table->string('registration_number')->nullable();
            $table->string('tin')->nullable();
            $table->string('business_type', 40)->nullable();
            $table->string('industry')->nullable();
            $table->date('date_of_incorporation')->nullable();

            // Income (individual) or revenue (business), monthly, minor units.
            $table->unsignedBigInteger('monthly_income_minor')->nullable();

            // Contact
            $table->string('email')->nullable();
            $table->string('phone', 40);
            $table->string('alt_phone', 40)->nullable();
            $table->string('address')->nullable();
            $table->string('digital_address', 20)->nullable();
            $table->string('city')->nullable();
            $table->string('region')->nullable();

            // Primary identity document (individuals). Encrypted + keyed hash.
            $table->string('id_type', 30)->nullable();
            $table->text('id_number')->nullable();
            $table->string('id_number_hash', 64)->nullable();

            $table->string('status', 20)->default('draft'); // draft|active|suspended|closed|rejected
            $table->string('onboarding_status', 20)->default('in_progress'); // in_progress|manual_review|completed|rejected
            $table->string('verification_status', 30)->default('not_started');
            $table->string('risk_rating', 10)->nullable();
            $table->foreignId('relationship_officer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();

            $table->timestamp('onboarded_at')->nullable();
            $table->foreignId('onboarded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('manual_review_reason')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'customer_number']);
            $table->index(['company_id', 'branch_id', 'status']);
            $table->index(['company_id', 'onboarding_status']);
            $table->index(['company_id', 'id_number_hash']);
            $table->index(['company_id', 'phone']);
        });

        // Authorised representatives / directors of a business customer. These
        // are the people who are identity-verified - never the company itself.
        Schema::create('customer_representatives', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('full_name');
            $table->string('position')->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('email')->nullable();
            $table->date('date_of_birth')->nullable();
            $table->string('id_type', 30)->nullable();
            $table->text('id_number')->nullable();
            $table->string('id_number_hash', 64)->nullable();
            $table->boolean('is_primary')->default(false);
            $table->boolean('is_signatory')->default(true);
            $table->string('verification_status', 30)->default('not_started');
            $table->timestamps();

            $table->index(['company_id', 'customer_id']);
        });

        Schema::create('customer_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('representative_id')->nullable()->constrained('customer_representatives')->nullOnDelete();
            $table->string('document_type', 40);
            $table->text('document_number')->nullable();
            $table->string('document_number_hash', 64)->nullable();
            $table->date('issue_date')->nullable();
            $table->date('expiry_date')->nullable();
            // Private disk, never under public/. Served only through an
            // authorised, audited download endpoint.
            $table->string('file_path');
            $table->string('original_name');
            $table->string('mime_type', 100);
            $table->unsignedInteger('size_bytes');
            $table->string('checksum', 64);
            $table->string('status', 20)->default('pending'); // pending|verified|rejected
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->string('rejection_reason')->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'customer_id']);
        });

        Schema::create('consent_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('representative_id')->nullable()->constrained('customer_representatives')->nullOnDelete();
            $table->string('consent_type', 40); // data_processing|biometric_processing|credit_check|marketing
            $table->boolean('granted');
            $table->string('policy_version', 20);
            $table->string('consent_text_hash', 64);
            $table->string('capture_method', 30); // signed_form|electronic|verbal_recorded
            $table->string('subject_name');
            $table->timestamp('granted_at')->nullable();
            $table->timestamp('withdrawn_at')->nullable();
            $table->foreignId('captured_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();

            $table->index(['company_id', 'customer_id', 'consent_type']);
        });

        Schema::create('biometric_verifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('representative_id')->nullable()->constrained('customer_representatives')->nullOnDelete();
            $table->foreignId('consent_record_id')->nullable()->constrained('consent_records')->nullOnDelete();
            $table->string('modality', 20); // face|fingerprint
            $table->string('provider', 40);
            $table->boolean('is_simulated')->default(false);
            $table->string('status', 30)->default('pending');
            $table->string('failure_reason', 40)->nullable();
            $table->string('failure_message')->nullable();
            $table->decimal('match_score', 6, 4)->nullable();
            $table->boolean('liveness_passed')->nullable();
            // Opaque identifiers issued by the provider. Encrypted at rest.
            $table->text('provider_reference')->nullable();
            $table->text('provider_session_id')->nullable();
            $table->string('client_nonce', 64)->nullable();
            $table->text('metadata')->nullable();
            $table->foreignId('operator_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('review_decision', 20)->nullable();
            $table->text('review_notes')->nullable();
            $table->date('retain_until')->nullable();
            $table->timestamp('purged_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'customer_id', 'modality']);
            $table->index(['company_id', 'status']);
            $table->index(['retain_until', 'purged_at']);
        });

        // Outbound customer messages (SMS/email) - delivery history.
        Schema::create('customer_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('channel', 10);
            $table->string('recipient');
            $table->string('event', 60);
            $table->text('body');
            $table->string('status', 20)->default('queued');
            $table->string('provider_message_id')->nullable();
            $table->string('error')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'customer_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_messages');
        Schema::dropIfExists('biometric_verifications');
        Schema::dropIfExists('consent_records');
        Schema::dropIfExists('customer_documents');
        Schema::dropIfExists('customer_representatives');
        Schema::dropIfExists('customers');
    }
};
