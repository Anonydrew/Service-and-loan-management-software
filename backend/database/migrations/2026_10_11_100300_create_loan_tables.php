<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** All money columns are integer minor units (pesewas for GHS). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loan_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('code', 20);
            $table->text('description')->nullable();
            $table->string('customer_type', 20)->default('both'); // individual|business|both
            $table->string('interest_method', 20); // flat|reducing_balance
            $table->decimal('interest_rate', 8, 4);
            $table->string('rate_period', 20)->default('per_month'); // per_month|per_annum
            $table->string('repayment_frequency', 20)->default('monthly'); // weekly|biweekly|monthly
            $table->unsignedBigInteger('min_amount_minor');
            $table->unsignedBigInteger('max_amount_minor');
            $table->unsignedSmallInteger('min_term');
            $table->unsignedSmallInteger('max_term');
            $table->string('fee_type', 20)->default('percent'); // percent|fixed
            $table->decimal('fee_value', 12, 4)->default(0);
            $table->string('fee_collection', 20)->default('deducted'); // deducted|capitalised
            $table->string('penalty_type', 20)->default('percent'); // percent|fixed
            $table->decimal('penalty_value', 12, 4)->default(0);
            $table->unsignedSmallInteger('grace_period_days')->default(0);
            $table->decimal('max_debt_service_ratio', 5, 2)->nullable();
            $table->unsignedSmallInteger('min_age')->nullable();
            $table->unsignedSmallInteger('max_age')->nullable();
            $table->unsignedSmallInteger('max_active_loans')->default(1);
            $table->boolean('requires_guarantor')->default(false);
            $table->boolean('requires_collateral')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['company_id', 'code']);
        });

        Schema::create('loan_applications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->string('application_number', 30);
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->foreignId('loan_product_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('amount_requested_minor');
            $table->unsignedSmallInteger('term');
            $table->string('purpose');
            $table->unsignedBigInteger('monthly_income_minor')->nullable();
            $table->unsignedBigInteger('monthly_obligations_minor')->default(0);
            $table->json('guarantors')->nullable();
            $table->json('collateral')->nullable();
            $table->json('eligibility')->nullable();
            $table->string('status', 20)->default('draft'); // draft|submitted|approved|rejected|cancelled|disbursed
            $table->unsignedBigInteger('approved_amount_minor')->nullable();
            $table->unsignedSmallInteger('approved_term')->nullable();
            $table->text('decision_notes')->nullable();
            $table->foreignId('loan_officer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'application_number']);
            $table->index(['company_id', 'branch_id', 'status']);
        });

        Schema::create('loans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->string('loan_number', 30);
            $table->foreignId('loan_application_id')->constrained()->restrictOnDelete();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->foreignId('loan_product_id')->constrained()->restrictOnDelete();
            // Terms are snapshotted from the product: editing a product never
            // changes a loan that already exists.
            $table->unsignedBigInteger('principal_minor');
            $table->decimal('interest_rate', 8, 4);
            $table->string('rate_period', 20);
            $table->string('interest_method', 20);
            $table->string('repayment_frequency', 20);
            $table->unsignedSmallInteger('term');
            $table->string('fee_collection', 20);
            $table->unsignedBigInteger('fee_minor')->default(0);
            $table->string('penalty_type', 20);
            $table->decimal('penalty_value', 12, 4)->default(0);
            $table->unsignedSmallInteger('grace_period_days')->default(0);
            $table->unsignedBigInteger('total_interest_minor')->default(0);
            $table->unsignedBigInteger('total_payable_minor')->default(0);
            $table->unsignedBigInteger('net_disbursed_minor')->default(0);
            $table->unsignedBigInteger('principal_paid_minor')->default(0);
            $table->unsignedBigInteger('interest_paid_minor')->default(0);
            $table->unsignedBigInteger('fees_paid_minor')->default(0);
            $table->unsignedBigInteger('penalty_paid_minor')->default(0);
            $table->unsignedBigInteger('penalty_charged_minor')->default(0);
            $table->unsignedBigInteger('written_off_minor')->default(0);
            $table->string('status', 30)->default('pending_disbursement');
            $table->unsignedInteger('days_in_arrears')->default(0);
            $table->foreignId('loan_officer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('agreement_signed_at')->nullable();
            $table->date('disbursement_date')->nullable();
            $table->date('first_due_date')->nullable();
            $table->date('maturity_date')->nullable();
            $table->string('disbursement_method', 20)->nullable();
            $table->string('disbursement_reference')->nullable();
            $table->foreignId('disbursed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('disbursed_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('written_off_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('write_off_reason')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'loan_number']);
            $table->index(['company_id', 'branch_id', 'status']);
            $table->index(['company_id', 'customer_id']);
        });

        Schema::create('loan_installments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('loan_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('installment_number');
            $table->date('due_date');
            $table->unsignedBigInteger('principal_due_minor');
            $table->unsignedBigInteger('interest_due_minor');
            $table->unsignedBigInteger('fees_due_minor')->default(0);
            $table->unsignedBigInteger('penalty_due_minor')->default(0);
            $table->unsignedBigInteger('principal_paid_minor')->default(0);
            $table->unsignedBigInteger('interest_paid_minor')->default(0);
            $table->unsignedBigInteger('fees_paid_minor')->default(0);
            $table->unsignedBigInteger('penalty_paid_minor')->default(0);
            $table->string('status', 20)->default('pending'); // pending|partial|paid
            $table->timestamp('penalty_applied_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->unique(['loan_id', 'installment_number']);
            $table->index(['company_id', 'due_date', 'status']);
        });

        Schema::create('loan_repayments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->string('receipt_number', 30);
            $table->foreignId('loan_id')->constrained()->restrictOnDelete();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('amount_minor');
            $table->unsignedBigInteger('principal_minor')->default(0);
            $table->unsignedBigInteger('interest_minor')->default(0);
            $table->unsignedBigInteger('fees_minor')->default(0);
            $table->unsignedBigInteger('penalty_minor')->default(0);
            $table->string('payment_method', 20);
            $table->string('reference')->nullable();
            $table->date('paid_on');
            $table->string('notes')->nullable();
            $table->string('status', 20)->default('posted'); // posted|reversed
            $table->foreignId('journal_entry_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reversed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reversed_at')->nullable();
            $table->string('reversal_reason')->nullable();
            $table->timestamp('reconciled_at')->nullable();
            $table->foreignId('reconciled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('statement_reference')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'receipt_number']);
            $table->index(['company_id', 'branch_id', 'paid_on']);
            $table->index(['company_id', 'loan_id']);
        });

        // Per-installment split of a repayment, kept so a reversal can undo
        // exactly what the repayment did.
        Schema::create('repayment_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('loan_repayment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('loan_installment_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('principal_minor')->default(0);
            $table->unsignedBigInteger('interest_minor')->default(0);
            $table->unsignedBigInteger('fees_minor')->default(0);
            $table->unsignedBigInteger('penalty_minor')->default(0);
        });

        Schema::create('collection_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('loan_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('activity_type', 30); // call|sms|visit|promise_to_pay|letter|other
            $table->string('outcome', 40)->nullable();
            $table->text('notes')->nullable();
            $table->date('promise_date')->nullable();
            $table->unsignedBigInteger('promise_amount_minor')->nullable();
            $table->date('next_action_date')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'loan_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('collection_activities');
        Schema::dropIfExists('repayment_allocations');
        Schema::dropIfExists('loan_repayments');
        Schema::dropIfExists('loan_installments');
        Schema::dropIfExists('loans');
        Schema::dropIfExists('loan_applications');
        Schema::dropIfExists('loan_products');
    }
};
