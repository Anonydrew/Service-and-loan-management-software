<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The single source of truth for permission codes and the default roles a new
 * company is provisioned with. Roles store a subset of these codes.
 */
final class Permissions
{
    /** @var array<string, array<string, string>> module => [code => label] */
    public const MODULES = [
        'Dashboard' => [
            'dashboard.view' => 'View dashboard',
        ],
        'Customers' => [
            'customers.view' => 'View customers',
            'customers.create' => 'Register customers',
            'customers.update' => 'Edit customers',
            'customers.export' => 'Export customer lists',
            'customer_documents.view' => 'View and download identity documents',
            'customer_documents.upload' => 'Upload identity documents',
            'customer_documents.verify' => 'Verify or reject documents',
        ],
        'Onboarding & verification' => [
            'onboarding.manage' => 'Run customer onboarding',
            'onboarding.review' => 'Decide manual-review cases',
            'biometrics.capture' => 'Start biometric verification',
            'biometrics.view' => 'View biometric verification results',
        ],
        'Loans' => [
            'loan_products.view' => 'View loan products',
            'loan_products.manage' => 'Create and edit loan products',
            'loan_applications.view' => 'View loan applications',
            'loan_applications.create' => 'Create and submit applications',
            'loan_applications.approve' => 'Approve or reject applications',
            'loans.view' => 'View loans',
            'loans.disburse' => 'Disburse approved loans',
            'loans.write_off' => 'Write off loans',
            'repayments.view' => 'View repayments',
            'repayments.create' => 'Record repayments',
            'repayments.reverse' => 'Reverse repayments',
            'collections.view' => 'View arrears and collections',
            'collections.manage' => 'Log collection activities',
        ],
        'Services' => [
            'services.view' => 'View service catalogue',
            'services.manage' => 'Manage service catalogue',
            'service_requests.view' => 'View service requests',
            'service_requests.create' => 'Create service requests',
            'service_requests.update' => 'Assign, schedule and progress requests',
        ],
        'Billing & payments' => [
            'invoices.view' => 'View invoices',
            'invoices.create' => 'Issue invoices',
            'invoices.void' => 'Void invoices',
            'payments.view' => 'View payments',
            'payments.create' => 'Record payments',
            'payments.refund' => 'Request refunds',
            'payments.refund_approve' => 'Approve refunds',
            'payments.reconcile' => 'Reconcile payments',
        ],
        'Accounting' => [
            'accounting.view' => 'View ledger and statements',
            'accounting.manage' => 'Manage chart of accounts and manual journals',
            'expenses.view' => 'View expenses',
            'expenses.create' => 'Record and void expenses',
        ],
        'Reports' => [
            'reports.view' => 'View operational reports',
            'reports.financial' => 'View financial reports',
        ],
        'Organisation' => [
            'branches.view' => 'View branches',
            'branches.manage' => 'Manage branches',
            'branches.all' => 'Access records in every branch',
            'users.view' => 'View staff',
            'users.manage' => 'Manage staff accounts',
            'roles.manage' => 'Manage roles and permissions',
        ],
        'Administration' => [
            'audit.view' => 'View audit log',
            'settings.manage' => 'Manage company settings',
        ],
    ];

    /** @return array<int, string> */
    public static function all(): array
    {
        return array_merge(...array_map('array_keys', array_values(self::MODULES)));
    }

    public static function exists(string $code): bool
    {
        return in_array($code, self::all(), true);
    }

    /** @return array<string, array{name: string, description: string, permissions: array<int, string>}> */
    public static function defaultRoles(): array
    {
        $all = self::all();
        $views = array_values(array_filter($all, fn ($p) => str_ends_with($p, '.view')));

        return [
            'administrator' => [
                'name' => 'Administrator',
                'description' => 'Full access to every module and branch.',
                'permissions' => $all,
            ],
            'branch-manager' => [
                'name' => 'Branch Manager',
                'description' => 'Runs a branch: approves loans, oversees services and collections.',
                'permissions' => array_values(array_diff($all, [
                    'roles.manage', 'settings.manage', 'branches.manage', 'branches.all', 'accounting.manage', 'users.manage',
                ])),
            ],
            'loan-officer' => [
                'name' => 'Loan Officer',
                'description' => 'Onboards customers, prepares applications and manages collections.',
                'permissions' => [
                    'dashboard.view', 'customers.view', 'customers.create', 'customers.update',
                    'customer_documents.view', 'customer_documents.upload',
                    'onboarding.manage', 'biometrics.capture', 'biometrics.view',
                    'loan_products.view', 'loan_applications.view', 'loan_applications.create',
                    'loans.view', 'repayments.view', 'collections.view', 'collections.manage',
                    'services.view', 'service_requests.view', 'reports.view',
                ],
            ],
            'cashier' => [
                'name' => 'Cashier',
                'description' => 'Receives repayments and invoice payments.',
                'permissions' => [
                    'dashboard.view', 'customers.view', 'loans.view', 'repayments.view', 'repayments.create',
                    'invoices.view', 'payments.view', 'payments.create', 'payments.refund', 'services.view',
                    'service_requests.view',
                ],
            ],
            'service-officer' => [
                'name' => 'Customer Service Officer',
                'description' => 'Registers customers and handles service requests and billing.',
                'permissions' => [
                    'dashboard.view', 'customers.view', 'customers.create', 'customers.update',
                    'customer_documents.view', 'customer_documents.upload', 'onboarding.manage', 'biometrics.capture',
                    'services.view', 'service_requests.view', 'service_requests.create', 'service_requests.update',
                    'invoices.view', 'invoices.create', 'payments.view',
                ],
            ],
            'accountant' => [
                'name' => 'Accountant',
                'description' => 'Ledger, expenses, reconciliation and financial reporting.',
                'permissions' => [
                    'dashboard.view', 'customers.view', 'loans.view', 'repayments.view', 'invoices.view', 'payments.view',
                    'payments.reconcile', 'payments.refund_approve', 'accounting.view', 'accounting.manage',
                    'expenses.view', 'expenses.create', 'reports.view', 'reports.financial', 'branches.view', 'branches.all',
                ],
            ],
            'compliance-officer' => [
                'name' => 'Compliance Officer',
                'description' => 'Verifies documents, decides manual reviews and audits activity.',
                'permissions' => [
                    'dashboard.view', 'customers.view', 'customers.export', 'customer_documents.view', 'customer_documents.verify',
                    'onboarding.review', 'biometrics.view', 'audit.view', 'reports.view', 'branches.all',
                ],
            ],
            'auditor' => [
                'name' => 'Auditor (read only)',
                'description' => 'Read-only access to every module.',
                'permissions' => array_values(array_merge($views, ['reports.financial', 'branches.all'])),
            ],
        ];
    }
}
