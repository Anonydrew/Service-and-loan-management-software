import {
  BookOpen,
  Building2,
  Calculator,
  ChartColumn,
  ClipboardList,
  FileText,
  HandCoins,
  History,
  Landmark,
  LayoutDashboard,
  Receipt,
  Scale,
  ScrollText,
  Settings,
  ShieldCheck,
  Undo2,
  UserCheck,
  Users,
  Wallet,
  Briefcase,
  TriangleAlert,
  Banknote,
  ListChecks,
  type LucideIcon,
} from 'lucide-react';

export interface NavItem {
  to: string;
  label: string;
  icon: LucideIcon;
  permission: string | string[];
  end?: boolean;
}

export interface NavGroup {
  label: string;
  items: NavItem[];
}

export const NAVIGATION: NavGroup[] = [
  {
    label: 'Overview',
    items: [{ to: '/', label: 'Dashboard', icon: LayoutDashboard, permission: 'dashboard.view', end: true }],
  },
  {
    label: 'Customers',
    items: [
      { to: '/customers', label: 'Customers', icon: Users, permission: 'customers.view', end: true },
      { to: '/customers/review', label: 'Identity review', icon: UserCheck, permission: 'onboarding.review' },
    ],
  },
  {
    label: 'Lending',
    items: [
      { to: '/loans', label: 'Loans', icon: HandCoins, permission: 'loans.view', end: true },
      { to: '/loans/applications', label: 'Applications', icon: ClipboardList, permission: 'loan_applications.view' },
      { to: '/loans/repayments', label: 'Repayments', icon: Banknote, permission: 'repayments.view' },
      { to: '/loans/arrears', label: 'Arrears & collections', icon: TriangleAlert, permission: 'collections.view' },
      { to: '/loans/products', label: 'Loan products', icon: Calculator, permission: 'loan_products.view' },
    ],
  },
  {
    label: 'Services',
    items: [
      { to: '/services/requests', label: 'Service requests', icon: Briefcase, permission: 'service_requests.view' },
      { to: '/services/catalogue', label: 'Service catalogue', icon: ListChecks, permission: 'services.view' },
    ],
  },
  {
    label: 'Billing',
    items: [
      { to: '/billing/invoices', label: 'Invoices', icon: FileText, permission: 'invoices.view' },
      { to: '/billing/payments', label: 'Payments', icon: Wallet, permission: 'payments.view' },
      { to: '/billing/refunds', label: 'Refunds', icon: Undo2, permission: ['payments.refund', 'payments.refund_approve'] },
      { to: '/billing/reconciliation', label: 'Reconciliation', icon: Scale, permission: 'payments.reconcile' },
    ],
  },
  {
    label: 'Finance',
    items: [
      { to: '/accounting/accounts', label: 'Chart of accounts', icon: BookOpen, permission: 'accounting.view' },
      { to: '/accounting/journal', label: 'Journal', icon: ScrollText, permission: 'accounting.view' },
      { to: '/accounting/expenses', label: 'Expenses', icon: Receipt, permission: 'expenses.view' },
      { to: '/accounting/statements', label: 'Statements', icon: Landmark, permission: 'reports.financial' },
    ],
  },
  {
    label: 'Insights',
    items: [{ to: '/reports', label: 'Reports', icon: ChartColumn, permission: 'reports.view' }],
  },
  {
    label: 'Administration',
    items: [
      { to: '/admin/branches', label: 'Branches', icon: Building2, permission: ['branches.view', 'branches.manage'] },
      { to: '/admin/staff', label: 'Staff', icon: Users, permission: ['users.view', 'users.manage'] },
      { to: '/admin/roles', label: 'Roles & permissions', icon: ShieldCheck, permission: 'roles.manage' },
      { to: '/admin/audit', label: 'Audit log', icon: History, permission: 'audit.view' },
      { to: '/admin/settings', label: 'Settings', icon: Settings, permission: 'settings.manage' },
    ],
  },
];
