<?php

namespace Database\Seeders;

use App\Models\Account;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\LoanProduct;
use App\Models\Role;
use App\Models\Service;
use App\Models\User;
use App\Services\Accounting\ExpenseService;
use App\Services\Accounting\Ledger;
use App\Services\Billing\PaymentService;
use App\Services\CompanyProvisioner;
use App\Services\Customers\ConsentService;
use App\Services\Customers\CustomerService;
use App\Services\Loans\ArrearsService;
use App\Services\Loans\LoanService;
use App\Services\Loans\RepaymentService;
use App\Services\ServiceDesk\ServiceRequestService;
use App\Support\Tenancy\Tenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * A fictional company with two branches, staff in every role, products,
 * customers, loans at different stages, service requests and invoices.
 * Every financial record is created through the real services, so the
 * ledger balances exactly as it would in production.
 *
 * DEMO PASSWORDS ARE PUBLIC. Never run this seeder on a production database.
 */
class DemoSeeder extends Seeder
{
    public const PASSWORD = 'Demo-Password-2026';

    public function run(): void
    {
        if (app()->isProduction()) {
            $this->command?->error('Refusing to seed demo data in production.');

            return;
        }

        ['company' => $company, 'branch' => $hq, 'admin' => $admin] = app(CompanyProvisioner::class)->provision(
            'Sika Trust Services', 'Ama Mensah', 'admin@demo.test', self::PASSWORD, 'Accra Central',
        );
        $company->forceFill([
            'legal_name' => 'Sika Trust Services Ltd (demo)', 'phone' => '+233 30 000 0000',
            'email' => 'hello@demo.test', 'address' => 'Ring Road Central, Accra', 'tin' => 'C0000000000',
        ])->save();

        Tenant::runAs($company, function () use ($company, $hq, $admin): void {
            $hq->forceFill(['code' => 'ACC', 'city' => 'Accra', 'region' => 'Greater Accra'])->save();
            $kumasi = Branch::query()->create(['name' => 'Kumasi Adum', 'code' => 'KSI', 'city' => 'Kumasi', 'region' => 'Ashanti', 'is_active' => true]);

            $staff = [
                ['Kofi Boateng', 'manager@demo.test', 'branch-manager', $hq, 'Branch Manager'],
                ['Efua Owusu', 'officer@demo.test', 'loan-officer', $hq, 'Loan Officer'],
                ['Yaw Asante', 'cashier@demo.test', 'cashier', $hq, 'Cashier'],
                ['Abena Darko', 'service@demo.test', 'service-officer', $hq, 'Customer Service Officer'],
                ['Kwame Addo', 'accountant@demo.test', 'accountant', $hq, 'Accountant'],
                ['Akosua Frimpong', 'compliance@demo.test', 'compliance-officer', $hq, 'Compliance Officer'],
                ['Kojo Antwi', 'kumasi.officer@demo.test', 'loan-officer', $kumasi, 'Loan Officer'],
            ];
            $users = [];
            foreach ($staff as [$name, $email, $role, $branch, $title]) {
                $user = new User(['name' => $name, 'email' => $email, 'password' => self::PASSWORD, 'job_title' => $title, 'primary_branch_id' => $branch->id]);
                $user->company_id = $company->id;
                $user->status = 'active';
                $user->save();
                $user->roles()->sync([Role::query()->where('slug', $role)->value('id')]);
                $users[$role.($branch->is($kumasi) ? '-ksi' : '')] = $user;
            }

            $this->catalogue();
            $this->customersAndLoans($company, $hq, $kumasi, $admin, $users);
        }, $admin);

        $this->command?->info('Demo company seeded. Sign in as admin@demo.test / '.self::PASSWORD);
    }

    private function catalogue(): void
    {
        LoanProduct::query()->create([
            'name' => 'Susu Business Loan', 'code' => 'SBL', 'customer_type' => 'both', 'interest_method' => 'flat',
            'interest_rate' => '4.5', 'rate_period' => 'per_month', 'repayment_frequency' => 'weekly',
            'min_amount_minor' => 50000, 'max_amount_minor' => 2000000, 'min_term' => 4, 'max_term' => 26,
            'fee_type' => 'percent', 'fee_value' => '2', 'fee_collection' => 'deducted', 'penalty_type' => 'percent',
            'penalty_value' => '5', 'grace_period_days' => 2, 'max_debt_service_ratio' => '50', 'max_active_loans' => 1,
            'description' => 'Short-term working capital for traders, repaid weekly.', 'is_active' => true,
        ]);
        LoanProduct::query()->create([
            'name' => 'Salary Advance', 'code' => 'SAL', 'customer_type' => 'individual', 'interest_method' => 'reducing_balance',
            'interest_rate' => '30', 'rate_period' => 'per_annum', 'repayment_frequency' => 'monthly',
            'min_amount_minor' => 100000, 'max_amount_minor' => 3000000, 'min_term' => 3, 'max_term' => 24,
            'fee_type' => 'percent', 'fee_value' => '1.5', 'fee_collection' => 'capitalised', 'penalty_type' => 'fixed',
            'penalty_value' => '20', 'grace_period_days' => 5, 'max_debt_service_ratio' => '40', 'min_age' => 21, 'max_age' => 60,
            'max_active_loans' => 1, 'description' => 'Monthly-repaid personal loan for salaried workers.', 'is_active' => true,
        ]);
        LoanProduct::query()->create([
            'name' => 'SME Asset Finance', 'code' => 'SME', 'customer_type' => 'business', 'interest_method' => 'reducing_balance',
            'interest_rate' => '28', 'rate_period' => 'per_annum', 'repayment_frequency' => 'monthly',
            'min_amount_minor' => 1000000, 'max_amount_minor' => 50000000, 'min_term' => 6, 'max_term' => 36,
            'fee_type' => 'percent', 'fee_value' => '2.5', 'fee_collection' => 'deducted', 'penalty_type' => 'percent',
            'penalty_value' => '3', 'grace_period_days' => 7, 'max_active_loans' => 2, 'requires_collateral' => true,
            'description' => 'Equipment and vehicle finance for registered businesses.', 'is_active' => true,
        ]);

        foreach ([
            ['Business Registration Assistance', 'BRA', 'Advisory', 45000, '0', false, 60],
            ['Bookkeeping (monthly)', 'BKM', 'Advisory', 60000, '15', false, null],
            ['Tax Filing Support', 'TAX', 'Advisory', 35000, '15', true, 45],
            ['Mobile Money Agent Setup', 'MMA', 'Payments', 25000, '0', true, 90],
            ['Financial Literacy Training', 'FLT', 'Training', 15000, '0', true, 120],
            ['Document Notarisation', 'DOC', 'Documentation', 8000, '0', false, 20],
        ] as [$name, $code, $category, $price, $tax, $appointment, $duration]) {
            Service::query()->create([
                'name' => $name, 'code' => $code, 'category' => $category, 'price_minor' => $price, 'tax_rate' => $tax,
                'requires_appointment' => $appointment, 'duration_minutes' => $duration, 'is_active' => true,
                'price_is_variable' => $code === 'BKM',
            ]);
        }
    }

    /** @param array<string, User> $users */
    private function customersAndLoans($company, Branch $hq, Branch $kumasi, User $admin, array $users): void
    {
        $customers = app(CustomerService::class);
        $consents = app(ConsentService::class);
        $loans = app(LoanService::class);
        $repayments = app(RepaymentService::class);
        $arrears = app(ArrearsService::class);
        $requests = app(ServiceRequestService::class);
        $payments = app(PaymentService::class);
        $expenses = app(ExpenseService::class);
        $ledger = app(Ledger::class);

        // Opening capital so the cash accounts are not negative.
        $ledger->post('Opening capital', [
            ['account' => 'bank', 'debit' => 50000000],
            ['account' => 'cash', 'debit' => 5000000],
            ['account' => 'mobile_money', 'debit' => 3000000],
            ['account' => 'equity', 'credit' => 58000000],
        ], null, $hq->id, Carbon::now()->subMonths(6));

        $people = [
            ['Akua', 'Agyemang', '1988-03-14', 'female', 'Trader', $hq, 'GHA-713300001-1', 450000],
            ['Kwabena', 'Osei', '1979-11-02', 'male', 'Teacher', $hq, 'GHA-713300002-2', 380000],
            ['Adwoa', 'Nyarko', '1992-07-21', 'female', 'Seamstress', $kumasi, 'GHA-713300003-3', 250000],
            ['Yaw', 'Mensah', '1985-01-30', 'male', 'Civil Servant', $hq, 'GHA-713300004-4', 520000],
            ['Esi', 'Quaye', '1995-09-09', 'female', 'Hairdresser', $kumasi, 'GHA-713300005-5', 200000],
        ];

        $onboarded = [];
        foreach ($people as $i => [$first, $last, $dob, $gender, $job, $branch, $idNumber, $income]) {
            $c = $customers->create([
                'type' => 'individual', 'branch_id' => $branch->id, 'first_name' => $first, 'last_name' => $last,
                'date_of_birth' => $dob, 'gender' => $gender, 'occupation' => $job, 'nationality' => 'GH',
                'phone' => '+23324'.str_pad((string) (1000000 + $i * 7919), 7, '0', STR_PAD_LEFT), 'city' => $branch->city,
                'id_type' => 'ghana_card', 'id_number' => $idNumber, 'monthly_income_minor' => $income,
                'address' => 'House '.(10 + $i).', '.$branch->city, 'digital_address' => 'GA-'.(100 + $i).'-'.(4000 + $i),
            ]);
            $consents->record($c, 'data_processing', true, 'signed_form');
            $consents->record($c, 'credit_check', true, 'signed_form');

            if ($i < 4) {
                // Seed shortcut: marked as onboarded after a (demo) manual document check.
                $c->forceFill(['status' => 'active', 'onboarding_status' => 'completed', 'verification_status' => 'verified',
                    'onboarded_at' => now()->subMonths(5), 'onboarded_by' => $users['compliance-officer']->id,
                    'reviewed_by' => $users['compliance-officer']->id, 'reviewed_at' => now()->subMonths(5),
                    'review_notes' => 'Demo data: seeded as already verified (no real Ghana Card check took place).'])->save();
                $onboarded[] = $c;
            }
        }

        // A customer waiting for manual review, and a business mid-onboarding.
        $pending = Customer::query()->where('first_name', 'Esi')->first();
        $pending?->forceFill(['onboarding_status' => 'manual_review', 'verification_status' => 'manual_review',
            'manual_review_reason' => 'Fingerprint scanner unavailable at the branch; customer to return for match-on-card verification.'])->save();

        $biz = $customers->create([
            'type' => 'business', 'branch_id' => $hq->id, 'business_name' => 'Kente Crafts Enterprise', 'registration_number' => 'BN000123456',
            'business_type' => 'sole_proprietorship', 'industry' => 'Textiles', 'phone' => '+233302000111', 'tin' => 'P0001234567',
            'monthly_income_minor' => 1500000, 'city' => 'Accra', 'address' => 'Makola Market, Accra',
        ], [['full_name' => 'Nana Ama Kyei', 'position' => 'Owner', 'phone' => '+233244000222', 'id_type' => 'ghana_card', 'id_number' => 'GHA-713300009-9', 'is_signatory' => true]]);
        $consents->record($biz, 'data_processing', true, 'signed_form');

        // Loans: maker (loan officer) prepares, checker (manager) approves.
        $products = LoanProduct::query()->get()->keyBy('code');
        $scenarios = [
            [$onboarded[0], 'SBL', 500000, 20, 70, true],   // healthy, paying weekly
            [$onboarded[1], 'SAL', 1200000, 12, 120, true], // paying monthly
            [$onboarded[2], 'SBL', 300000, 16, 75, false],  // stopped paying -> arrears
            [$onboarded[3], 'SAL', 1000000, 6, 0, false],   // approved, awaiting disbursement
        ];

        foreach ($scenarios as [$customer, $code, $amount, $term, $daysAgo, $keepsPaying]) {
            $officer = $customer->branch_id === $kumasi->id ? $users['loan-officer-ksi'] : $users['loan-officer'];
            $application = Tenant::runAs($customer->company, fn () => $loans->createApplication([
                'customer_id' => $customer->id, 'loan_product_id' => $products[$code]->id, 'amount_requested_minor' => $amount,
                'term' => $term, 'purpose' => $code === 'SBL' ? 'Restock shop inventory' : 'School fees and household needs',
                'monthly_obligations_minor' => 0, 'loan_officer_id' => $officer->id,
            ]), $officer);
            Tenant::runAs($customer->company, fn () => $loans->submit($application), $officer);
            // The Accra branch manager cannot see Kumasi records; the administrator approves those.
            $approver = $customer->branch_id === $kumasi->id ? $admin : $users['branch-manager'];
            $loan = Tenant::runAs($customer->company, fn () => $loans->approve($application, null, null, 'Within policy.'), $approver);

            if ($daysAgo === 0) {
                continue;
            }

            $disbursed = Carbon::now()->subDays($daysAgo);
            Carbon::setTestNow($disbursed);
            $loan = $loans->disburse($loan, $disbursed->toDateString(), 'mobile_money', 'MOMO-'.random_int(100000, 999999), true);
            Carbon::setTestNow();

            foreach ($loan->installments as $installment) {
                if ($installment->due_date->isFuture()) {
                    break;
                }
                if (! $keepsPaying && $installment->installment_number > 2) {
                    break;
                }
                $repayments->record($loan->fresh(), $installment->total_due_minor, $installment->installment_number % 2 ? 'cash' : 'mobile_money',
                    'REF'.$installment->id, $installment->due_date->toDateString());
            }

            $arrears->applyPenalties($loan->fresh());
            $arrears->refresh($loan->fresh());
        }

        // Service requests and billing.
        $services = Service::query()->get()->keyBy('code');
        $completed = $requests->create(['customer_id' => $onboarded[0]->id, 'service_id' => $services['BRA']->id, 'priority' => 'normal',
            'description' => 'Register a second shop as a sole proprietorship.', 'assigned_to' => $users['service-officer']->id]);
        $requests->transition($completed, 'in_progress');
        $requests->transition($completed->fresh(), 'completed', 'Certificate collected from ORC.');
        $invoice = $completed->fresh()->invoice;
        $payments->record($invoice, $invoice->total_minor, 'mobile_money', 'MOMO-778812', now()->toDateString());

        $partly = $requests->create(['customer_id' => $biz->id, 'service_id' => $services['BKM']->id, 'unit_price_minor' => 80000,
            'quantity' => 3, 'description' => 'Quarterly bookkeeping', 'assigned_to' => $users['accountant']->id]);
        $requests->transition($partly, 'in_progress');
        $requests->transition($partly->fresh(), 'completed', 'Q3 books closed.');
        $payments->record($partly->fresh()->invoice, 100000, 'bank_transfer', 'GCB-55120', now()->toDateString());

        $requests->create(['customer_id' => $onboarded[1]->id, 'service_id' => $services['FLT']->id, 'priority' => 'low',
            'scheduled_at' => now()->addDays(3)->setTime(10, 0), 'assigned_to' => $users['service-officer']->id]);
        $requests->create(['customer_id' => $onboarded[3]->id, 'service_id' => $services['TAX']->id, 'priority' => 'high',
            'description' => 'Annual returns due end of month.']);

        // Expenses.
        $rent = Account::query()->where('code', '5110')->first();
        $utilities = Account::query()->where('code', '5120')->first();
        $expenses->record(['branch_id' => $hq->id, 'expense_account_id' => $rent->id, 'payment_method' => 'bank_transfer', 'amount_minor' => 450000,
            'expense_date' => now()->startOfMonth()->toDateString(), 'payee' => 'Ring Road Properties', 'description' => 'Office rent']);
        $expenses->record(['branch_id' => $kumasi->id, 'expense_account_id' => $utilities->id, 'payment_method' => 'cash', 'amount_minor' => 32000,
            'expense_date' => now()->subDays(3)->toDateString(), 'payee' => 'ECG', 'description' => 'Electricity prepaid units']);
    }
}
