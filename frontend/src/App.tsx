import { lazy, Suspense, type ReactNode } from 'react';
import { Navigate, Route, Routes } from 'react-router-dom';
import { AppLayout } from './layouts/AppLayout';
import { RequireAuth, RequirePermission } from './components/Guards';
import { Spinner } from './components/ui';
import { useAuth } from './lib/auth';
import LoginPage from './pages/LoginPage';

const Dashboard = lazy(() => import('./pages/DashboardPage'));
const Customers = lazy(() => import('./pages/customers/CustomersPage'));
const NewCustomer = lazy(() => import('./pages/customers/NewCustomerPage'));
const CustomerDetail = lazy(() => import('./pages/customers/CustomerDetailPage'));
const Onboarding = lazy(() => import('./pages/customers/OnboardingPage'));
const ReviewQueue = lazy(() => import('./pages/customers/ReviewQueuePage'));
const Loans = lazy(() => import('./pages/loans/LoansPage'));
const LoanDetail = lazy(() => import('./pages/loans/LoanDetailPage'));
const Applications = lazy(() => import('./pages/loans/ApplicationsPage'));
const NewApplication = lazy(() => import('./pages/loans/NewApplicationPage'));
const ApplicationDetail = lazy(() => import('./pages/loans/ApplicationDetailPage'));
const Repayments = lazy(() => import('./pages/loans/RepaymentsPage'));
const Arrears = lazy(() => import('./pages/loans/ArrearsPage'));
const Products = lazy(() => import('./pages/loans/ProductsPage'));
const ServiceRequests = lazy(() => import('./pages/services/ServiceRequestsPage'));
const ServiceRequestDetail = lazy(() => import('./pages/services/ServiceRequestDetailPage'));
const Catalogue = lazy(() => import('./pages/services/CataloguePage'));
const Invoices = lazy(() => import('./pages/billing/InvoicesPage'));
const InvoiceDetail = lazy(() => import('./pages/billing/InvoiceDetailPage'));
const NewInvoice = lazy(() => import('./pages/billing/NewInvoicePage'));
const Payments = lazy(() => import('./pages/billing/PaymentsPage'));
const Refunds = lazy(() => import('./pages/billing/RefundsPage'));
const Reconciliation = lazy(() => import('./pages/billing/ReconciliationPage'));
const Accounts = lazy(() => import('./pages/accounting/AccountsPage'));
const Journal = lazy(() => import('./pages/accounting/JournalPage'));
const Expenses = lazy(() => import('./pages/accounting/ExpensesPage'));
const Statements = lazy(() => import('./pages/accounting/StatementsPage'));
const Reports = lazy(() => import('./pages/ReportsPage'));
const Branches = lazy(() => import('./pages/admin/BranchesPage'));
const Staff = lazy(() => import('./pages/admin/StaffPage'));
const Roles = lazy(() => import('./pages/admin/RolesPage'));
const Audit = lazy(() => import('./pages/admin/AuditPage'));
const Settings = lazy(() => import('./pages/admin/SettingsPage'));
const Account = lazy(() => import('./pages/AccountPage'));

function guard(permission: string | string[], page: ReactNode) {
  return <RequirePermission permission={permission}>{page}</RequirePermission>;
}

function Home() {
  const { can } = useAuth();
  // Staff without the dashboard land on their most relevant list.
  if (can('dashboard.view')) return <Dashboard />;
  if (can('customers.view')) return <Navigate to="/customers" replace />;
  return <Navigate to="/account" replace />;
}

export default function App() {
  return (
    <Routes>
      <Route path="/login" element={<LoginPage />} />
      <Route
        element={
          <RequireAuth>
            <AppLayout />
          </RequireAuth>
        }
      >
        <Route
          path="*"
          element={
            <Suspense
              fallback={
                <div className="flex justify-center py-24">
                  <Spinner className="size-7" />
                </div>
              }
            >
              <Routes>
                <Route index element={<Home />} />
                <Route path="customers" element={guard('customers.view', <Customers />)} />
                <Route path="customers/new" element={guard('customers.create', <NewCustomer />)} />
                <Route path="customers/review" element={guard('onboarding.review', <ReviewQueue />)} />
                <Route path="customers/:id" element={guard('customers.view', <CustomerDetail />)} />
                <Route path="customers/:id/onboarding" element={guard('customers.view', <Onboarding />)} />
                <Route path="loans" element={guard('loans.view', <Loans />)} />
                <Route path="loans/applications" element={guard('loan_applications.view', <Applications />)} />
                <Route path="loans/applications/new" element={guard('loan_applications.create', <NewApplication />)} />
                <Route path="loans/applications/:id" element={guard('loan_applications.view', <ApplicationDetail />)} />
                <Route path="loans/repayments" element={guard('repayments.view', <Repayments />)} />
                <Route path="loans/arrears" element={guard('collections.view', <Arrears />)} />
                <Route path="loans/products" element={guard('loan_products.view', <Products />)} />
                <Route path="loans/:id" element={guard('loans.view', <LoanDetail />)} />
                <Route path="services/requests" element={guard('service_requests.view', <ServiceRequests />)} />
                <Route path="services/requests/:id" element={guard('service_requests.view', <ServiceRequestDetail />)} />
                <Route path="services/catalogue" element={guard('services.view', <Catalogue />)} />
                <Route path="billing/invoices" element={guard('invoices.view', <Invoices />)} />
                <Route path="billing/invoices/new" element={guard('invoices.create', <NewInvoice />)} />
                <Route path="billing/invoices/:id" element={guard('invoices.view', <InvoiceDetail />)} />
                <Route path="billing/payments" element={guard('payments.view', <Payments />)} />
                <Route path="billing/refunds" element={guard(['payments.refund', 'payments.refund_approve'], <Refunds />)} />
                <Route path="billing/reconciliation" element={guard('payments.reconcile', <Reconciliation />)} />
                <Route path="accounting/accounts" element={guard('accounting.view', <Accounts />)} />
                <Route path="accounting/journal" element={guard('accounting.view', <Journal />)} />
                <Route path="accounting/expenses" element={guard('expenses.view', <Expenses />)} />
                <Route path="accounting/statements" element={guard('reports.financial', <Statements />)} />
                <Route path="reports" element={guard('reports.view', <Reports />)} />
                <Route path="admin/branches" element={guard(['branches.view', 'branches.manage'], <Branches />)} />
                <Route path="admin/staff" element={guard(['users.view', 'users.manage'], <Staff />)} />
                <Route path="admin/roles" element={guard('roles.manage', <Roles />)} />
                <Route path="admin/audit" element={guard('audit.view', <Audit />)} />
                <Route path="admin/settings" element={guard('settings.manage', <Settings />)} />
                <Route path="account" element={<Account />} />
                <Route path="*" element={<Navigate to="/" replace />} />
              </Routes>
            </Suspense>
          }
        />
      </Route>
    </Routes>
  );
}
