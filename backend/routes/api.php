<?php

use App\Http\Controllers\Api\AccountController;
use App\Http\Controllers\Api\AuditLogController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BiometricController;
use App\Http\Controllers\Api\BranchController;
use App\Http\Controllers\Api\CollectionController;
use App\Http\Controllers\Api\ConsentController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\CustomerDocumentController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\ExpenseController;
use App\Http\Controllers\Api\FinancialStatementController;
use App\Http\Controllers\Api\InvoiceController;
use App\Http\Controllers\Api\JournalController;
use App\Http\Controllers\Api\LoanApplicationController;
use App\Http\Controllers\Api\LoanController;
use App\Http\Controllers\Api\LoanProductController;
use App\Http\Controllers\Api\LookupController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\OnboardingController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\ReconciliationController;
use App\Http\Controllers\Api\RefundController;
use App\Http\Controllers\Api\RepaymentController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\RepresentativeController;
use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\Api\SearchController;
use App\Http\Controllers\Api\ServiceController;
use App\Http\Controllers\Api\ServiceRequestController;
use App\Http\Controllers\Api\SettingsController;
use App\Http\Controllers\Api\UserController;
use Illuminate\Support\Facades\Route;

// The SPA reads its CSRF token here and sends it as X-CSRF-TOKEN. This avoids
// relying on the XSRF-TOKEN cookie, whose fixed name collides with other
// Laravel apps sharing the parent domain (e.g. the clinic ERP).
Route::get('csrf-token', fn (\Illuminate\Http\Request $request) => response()->json([
    'token' => $request->hasSession() ? $request->session()->token() : null,
]))->middleware('throttle:60,1');

Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:login');

// Asynchronous result callbacks from a biometric provider. Authenticated by
// the provider's own signature inside the driver, not by a staff session.
Route::post('webhooks/biometrics/{provider}', [BiometricController::class, 'webhook'])
    ->middleware('throttle:60,1')
    ->where('provider', '[a-z0-9_]+');

Route::middleware(['auth:sanctum', 'tenant', 'throttle:api'])->group(function () {
    Route::post('auth/logout', [AuthController::class, 'logout']);
    Route::get('auth/me', [AuthController::class, 'me']);
    Route::put('auth/password', [AuthController::class, 'changePassword']);

    Route::get('lookups', LookupController::class);
    Route::get('search', SearchController::class);

    Route::get('dashboard', DashboardController::class)->middleware('permission:dashboard.view');

    // Notifications (own only)
    Route::get('notifications', [NotificationController::class, 'index']);
    Route::post('notifications/read-all', [NotificationController::class, 'readAll']);
    Route::post('notifications/{id}/read', [NotificationController::class, 'read']);

    // ---------------------------------------------------------------- Customers
    Route::get('customers', [CustomerController::class, 'index'])->middleware('permission:customers.view');
    Route::get('customers/export', [CustomerController::class, 'export'])->middleware('permission:customers.export');
    Route::post('customers', [CustomerController::class, 'store'])->middleware('permission:customers.create');
    Route::get('customers/{customer}', [CustomerController::class, 'show'])->middleware('permission:customers.view');
    Route::put('customers/{customer}', [CustomerController::class, 'update'])->middleware('permission:customers.update');
    Route::post('customers/{customer}/status', [CustomerController::class, 'setStatus'])->middleware('permission:customers.update');
    Route::get('customers/{customer}/activity', [CustomerController::class, 'activity'])->middleware('permission:customers.view');

    Route::post('customers/{customer}/representatives', [RepresentativeController::class, 'store'])->middleware('permission:customers.update');
    Route::put('customers/{customer}/representatives/{representative}', [RepresentativeController::class, 'update'])->middleware('permission:customers.update')->scopeBindings();
    Route::delete('customers/{customer}/representatives/{representative}', [RepresentativeController::class, 'destroy'])->middleware('permission:customers.update')->scopeBindings();

    Route::get('customers/{customer}/documents', [CustomerDocumentController::class, 'index'])->middleware('permission:customer_documents.view');
    Route::post('customers/{customer}/documents', [CustomerDocumentController::class, 'store'])->middleware('permission:customer_documents.upload');
    Route::get('customers/{customer}/documents/{document}/download', [CustomerDocumentController::class, 'download'])->middleware('permission:customer_documents.view')->scopeBindings();
    Route::post('customers/{customer}/documents/{document}/verify', [CustomerDocumentController::class, 'verify'])->middleware('permission:customer_documents.verify')->scopeBindings();
    Route::delete('customers/{customer}/documents/{document}', [CustomerDocumentController::class, 'destroy'])->middleware('permission:customer_documents.upload')->scopeBindings();

    // ---------------------------------------------------------------- Onboarding & consent
    Route::get('consent-texts', [ConsentController::class, 'texts']);
    Route::get('customers/{customer}/consents', [ConsentController::class, 'index'])->middleware('permission:customers.view');
    Route::post('customers/{customer}/consents', [ConsentController::class, 'store'])->middleware('permission:onboarding.manage');
    Route::post('customers/{customer}/consents/{consent}/withdraw', [ConsentController::class, 'withdraw'])->middleware('permission:onboarding.manage')->scopeBindings();

    Route::get('onboarding/review-queue', [OnboardingController::class, 'queue'])->middleware('permission:onboarding.review');
    Route::get('customers/{customer}/onboarding', [OnboardingController::class, 'show'])->middleware('permission:customers.view');
    Route::post('customers/{customer}/onboarding/complete', [OnboardingController::class, 'complete'])->middleware('permission:onboarding.manage');
    Route::post('customers/{customer}/onboarding/refer', [OnboardingController::class, 'refer'])->middleware('permission:onboarding.manage');
    Route::post('customers/{customer}/onboarding/decide', [OnboardingController::class, 'decide'])->middleware('permission:onboarding.review');

    // ---------------------------------------------------------------- Biometrics (onboarding only)
    Route::get('biometrics/providers', [BiometricController::class, 'providers'])->middleware('permission:biometrics.capture|biometrics.view');
    Route::get('customers/{customer}/biometrics', [BiometricController::class, 'index'])->middleware('permission:biometrics.view|biometrics.capture');
    Route::middleware(['permission:biometrics.capture', 'throttle:biometrics'])->group(function () {
        Route::post('customers/{customer}/biometrics', [BiometricController::class, 'start']);
        Route::post('customers/{customer}/biometrics/{attempt}/complete', [BiometricController::class, 'complete'])->scopeBindings();
        Route::post('customers/{customer}/biometrics/{attempt}/client-failure', [BiometricController::class, 'clientFailure'])->scopeBindings();
        Route::post('customers/{customer}/biometrics/{attempt}/cancel', [BiometricController::class, 'cancel'])->scopeBindings();
    });

    // ---------------------------------------------------------------- Loans
    Route::get('loan-products', [LoanProductController::class, 'index'])->middleware('permission:loan_products.view|loan_applications.create');
    Route::post('loan-products', [LoanProductController::class, 'store'])->middleware('permission:loan_products.manage');
    Route::get('loan-products/{loanProduct}', [LoanProductController::class, 'show'])->middleware('permission:loan_products.view');
    Route::put('loan-products/{loanProduct}', [LoanProductController::class, 'update'])->middleware('permission:loan_products.manage');

    Route::post('loans/calculate', [LoanApplicationController::class, 'calculate'])->middleware('permission:loan_applications.create|loan_products.view');

    Route::get('loan-applications', [LoanApplicationController::class, 'index'])->middleware('permission:loan_applications.view');
    Route::post('loan-applications', [LoanApplicationController::class, 'store'])->middleware('permission:loan_applications.create');
    Route::get('loan-applications/{application}', [LoanApplicationController::class, 'show'])->middleware('permission:loan_applications.view');
    Route::put('loan-applications/{application}', [LoanApplicationController::class, 'update'])->middleware('permission:loan_applications.create');
    Route::post('loan-applications/{application}/submit', [LoanApplicationController::class, 'submit'])->middleware('permission:loan_applications.create');
    Route::post('loan-applications/{application}/cancel', [LoanApplicationController::class, 'cancel'])->middleware('permission:loan_applications.create|loan_applications.approve');
    Route::post('loan-applications/{application}/approve', [LoanApplicationController::class, 'approve'])->middleware('permission:loan_applications.approve');
    Route::post('loan-applications/{application}/reject', [LoanApplicationController::class, 'reject'])->middleware('permission:loan_applications.approve');

    Route::get('loans', [LoanController::class, 'index'])->middleware('permission:loans.view');
    Route::get('loans/{loan}', [LoanController::class, 'show'])->middleware('permission:loans.view');
    Route::get('loans/{loan}/agreement', [LoanController::class, 'agreement'])->middleware('permission:loans.view');
    Route::post('loans/{loan}/disburse', [LoanController::class, 'disburse'])->middleware('permission:loans.disburse');
    Route::post('loans/{loan}/write-off', [LoanController::class, 'writeOff'])->middleware('permission:loans.write_off');
    Route::post('loans/{loan}/repayments', [RepaymentController::class, 'store'])->middleware('permission:repayments.create');
    Route::post('loans/{loan}/collections', [CollectionController::class, 'store'])->middleware('permission:collections.manage');

    Route::get('repayments', [RepaymentController::class, 'index'])->middleware('permission:repayments.view');
    Route::get('repayments/{repayment}/receipt', [RepaymentController::class, 'receipt'])->middleware('permission:repayments.view');
    Route::post('repayments/{repayment}/reverse', [RepaymentController::class, 'reverse'])->middleware('permission:repayments.reverse');

    Route::get('collections/arrears', [CollectionController::class, 'arrears'])->middleware('permission:collections.view');

    // ---------------------------------------------------------------- Services
    Route::get('services', [ServiceController::class, 'index'])->middleware('permission:services.view|service_requests.create');
    Route::post('services', [ServiceController::class, 'store'])->middleware('permission:services.manage');
    Route::put('services/{service}', [ServiceController::class, 'update'])->middleware('permission:services.manage');

    Route::get('service-requests', [ServiceRequestController::class, 'index'])->middleware('permission:service_requests.view');
    Route::post('service-requests', [ServiceRequestController::class, 'store'])->middleware('permission:service_requests.create');
    Route::get('service-requests/{serviceRequest}', [ServiceRequestController::class, 'show'])->middleware('permission:service_requests.view');
    Route::put('service-requests/{serviceRequest}', [ServiceRequestController::class, 'update'])->middleware('permission:service_requests.update');
    Route::post('service-requests/{serviceRequest}/status', [ServiceRequestController::class, 'transition'])->middleware('permission:service_requests.update');
    Route::post('service-requests/{serviceRequest}/invoice', [ServiceRequestController::class, 'invoice'])->middleware('permission:invoices.create');

    // ---------------------------------------------------------------- Billing
    Route::get('invoices', [InvoiceController::class, 'index'])->middleware('permission:invoices.view');
    Route::post('invoices', [InvoiceController::class, 'store'])->middleware('permission:invoices.create');
    Route::get('invoices/{invoice}', [InvoiceController::class, 'show'])->middleware('permission:invoices.view');
    Route::get('invoices/{invoice}/pdf', [InvoiceController::class, 'pdf'])->middleware('permission:invoices.view');
    Route::post('invoices/{invoice}/void', [InvoiceController::class, 'void'])->middleware('permission:invoices.void');
    Route::post('invoices/{invoice}/payments', [PaymentController::class, 'store'])->middleware('permission:payments.create');

    Route::get('payments', [PaymentController::class, 'index'])->middleware('permission:payments.view');
    Route::get('payments/{payment}/receipt', [PaymentController::class, 'receipt'])->middleware('permission:payments.view');
    Route::post('payments/{payment}/refunds', [RefundController::class, 'store'])->middleware('permission:payments.refund');
    Route::get('refunds', [RefundController::class, 'index'])->middleware('permission:payments.refund|payments.refund_approve');
    Route::post('refunds/{refund}/decide', [RefundController::class, 'decide'])->middleware('permission:payments.refund_approve');

    Route::get('reconciliation', [ReconciliationController::class, 'index'])->middleware('permission:payments.reconcile');
    Route::post('reconciliation', [ReconciliationController::class, 'store'])->middleware('permission:payments.reconcile');

    // ---------------------------------------------------------------- Accounting
    Route::get('accounts', [AccountController::class, 'index'])->middleware('permission:accounting.view|expenses.create');
    Route::post('accounts', [AccountController::class, 'store'])->middleware('permission:accounting.manage');
    Route::put('accounts/{account}', [AccountController::class, 'update'])->middleware('permission:accounting.manage');
    Route::get('accounts/{account}/ledger', [AccountController::class, 'ledger'])->middleware('permission:accounting.view');

    Route::get('journal-entries', [JournalController::class, 'index'])->middleware('permission:accounting.view');
    Route::post('journal-entries', [JournalController::class, 'store'])->middleware('permission:accounting.manage');
    Route::get('journal-entries/{journalEntry}', [JournalController::class, 'show'])->middleware('permission:accounting.view');
    Route::post('journal-entries/{journalEntry}/reverse', [JournalController::class, 'reverse'])->middleware('permission:accounting.manage');

    Route::get('expenses', [ExpenseController::class, 'index'])->middleware('permission:expenses.view');
    Route::post('expenses', [ExpenseController::class, 'store'])->middleware('permission:expenses.create');
    Route::post('expenses/{expense}/void', [ExpenseController::class, 'void'])->middleware('permission:expenses.create');

    Route::get('statements/trial-balance', [FinancialStatementController::class, 'trialBalance'])->middleware('permission:reports.financial');
    Route::get('statements/income', [FinancialStatementController::class, 'income'])->middleware('permission:reports.financial');
    Route::get('statements/balance-sheet', [FinancialStatementController::class, 'balanceSheet'])->middleware('permission:reports.financial');

    // ---------------------------------------------------------------- Reports
    Route::get('reports/{report}', [ReportController::class, 'show'])->middleware('permission:reports.view')
        ->where('report', '[a-z_]+');

    // ---------------------------------------------------------------- Organisation
    Route::get('branches', [BranchController::class, 'index'])->middleware('permission:branches.view|branches.manage');
    Route::post('branches', [BranchController::class, 'store'])->middleware('permission:branches.manage');
    Route::put('branches/{branch}', [BranchController::class, 'update'])->middleware('permission:branches.manage');

    Route::get('users', [UserController::class, 'index'])->middleware('permission:users.view|users.manage');
    Route::post('users', [UserController::class, 'store'])->middleware('permission:users.manage');
    Route::get('users/{user}', [UserController::class, 'show'])->middleware('permission:users.view|users.manage');
    Route::put('users/{user}', [UserController::class, 'update'])->middleware('permission:users.manage');
    Route::post('users/{user}/password', [UserController::class, 'resetPassword'])->middleware('permission:users.manage');

    Route::get('roles', [RoleController::class, 'index'])->middleware('permission:roles.manage|users.manage');
    Route::get('permissions', [RoleController::class, 'permissions'])->middleware('permission:roles.manage');
    Route::post('roles', [RoleController::class, 'store'])->middleware('permission:roles.manage');
    Route::put('roles/{role}', [RoleController::class, 'update'])->middleware('permission:roles.manage');
    Route::delete('roles/{role}', [RoleController::class, 'destroy'])->middleware('permission:roles.manage');

    // ---------------------------------------------------------------- Administration
    Route::get('audit-logs', [AuditLogController::class, 'index'])->middleware('permission:audit.view');
    Route::get('settings', [SettingsController::class, 'show'])->middleware('permission:settings.manage');
    Route::put('settings', [SettingsController::class, 'update'])->middleware('permission:settings.manage');
});
