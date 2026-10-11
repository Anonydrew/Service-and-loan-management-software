export interface Paginated<T> {
  data: T[];
  current_page: number;
  last_page: number;
  per_page: number;
  total: number;
  from: number | null;
  to: number | null;
}

export interface Ref {
  id: number;
  name: string;
}

export interface Branch {
  id: number;
  name: string;
  code: string;
  phone?: string | null;
  email?: string | null;
  address?: string | null;
  city?: string | null;
  region?: string | null;
  is_head_office?: boolean;
  is_active?: boolean;
  users_count?: number;
  customers_count?: number;
  open_loans_count?: number;
}

export interface Me {
  id: number;
  name: string;
  email: string;
  job_title: string | null;
  roles: string[];
  permissions: string[];
  primary_branch_id: number | null;
  all_branches: boolean;
  branches: Pick<Branch, 'id' | 'name' | 'code'>[];
  company: { id: number; name: string; currency: string; timezone: string };
}

export interface Representative {
  id: number;
  full_name: string;
  position: string | null;
  phone: string | null;
  email: string | null;
  date_of_birth: string | null;
  id_type: string | null;
  id_number_masked: string | null;
  is_primary: boolean;
  is_signatory: boolean;
  verification_status: string;
}

export interface Customer {
  id: number;
  customer_number: string;
  type: 'individual' | 'business';
  display_name: string;
  branch_id: number;
  branch?: Pick<Branch, 'id' | 'name' | 'code'>;
  title: string | null;
  first_name: string | null;
  middle_name: string | null;
  last_name: string | null;
  gender: string | null;
  date_of_birth: string | null;
  marital_status: string | null;
  nationality: string | null;
  occupation: string | null;
  employer: string | null;
  business_name: string | null;
  trading_name: string | null;
  registration_number: string | null;
  tin: string | null;
  business_type: string | null;
  industry: string | null;
  date_of_incorporation: string | null;
  monthly_income_minor: number | null;
  email: string | null;
  phone: string;
  alt_phone: string | null;
  address: string | null;
  digital_address: string | null;
  city: string | null;
  region: string | null;
  id_type: string | null;
  id_number_masked: string | null;
  status: string;
  onboarding_status: string;
  verification_status: string;
  risk_rating: string | null;
  relationship_officer?: Ref | null;
  relationship_officer_id: number | null;
  notes: string | null;
  representatives?: Representative[];
  created_by?: Ref | null;
  reviewer?: Ref | null;
  onboarded_by?: Ref | null;
  onboarded_at: string | null;
  manual_review_reason: string | null;
  review_notes: string | null;
  reviewed_at: string | null;
  created_at: string;
}

export interface ChecklistItem {
  key: string;
  label: string;
  required: boolean;
  status: 'complete' | 'incomplete' | 'in_progress';
  detail: string;
}

export interface Readiness {
  ready: boolean;
  items: ChecklistItem[];
}

export interface CustomerDetail {
  customer: Customer;
  summary: {
    loans_total: number;
    loans_open: number;
    loan_outstanding_minor: number;
    in_arrears: boolean;
    service_requests: number;
    invoice_balance_minor: number;
  };
  onboarding: Readiness;
}

export interface CustomerDocument {
  id: number;
  document_type: string;
  document_number_masked: string | null;
  representative?: { id: number; full_name: string } | null;
  issue_date: string | null;
  expiry_date: string | null;
  original_name: string;
  mime_type: string;
  size_bytes: number;
  status: 'pending' | 'verified' | 'rejected';
  rejection_reason: string | null;
  uploader?: Ref | null;
  verifier?: Ref | null;
  verified_at: string | null;
  created_at: string;
}

export interface Consent {
  id: number;
  consent_type: string;
  granted: boolean;
  capture_method: string;
  policy_version: string;
  subject_name: string;
  granted_at: string | null;
  withdrawn_at: string | null;
  representative?: { id: number; full_name: string } | null;
  captured_by?: Ref | null;
  created_at: string;
}

export interface BiometricAttempt {
  id: number;
  modality: 'face' | 'fingerprint';
  provider: string;
  verification_method: string | null;
  is_simulated: boolean;
  status: string;
  failure_reason: string | null;
  failure_message: string | null;
  match_score: number | null;
  liveness_passed: boolean | null;
  representative_id: number | null;
  representative?: { id: number; full_name: string } | null;
  operator?: Ref | null;
  started_at: string | null;
  completed_at: string | null;
  retain_until: string | null;
  purged_at: string | null;
  created_at: string;
}

export interface LoanProduct {
  id: number;
  name: string;
  code: string;
  description?: string | null;
  customer_type: string;
  interest_method: string;
  interest_rate: string;
  rate_period: string;
  repayment_frequency: string;
  min_amount_minor: number;
  max_amount_minor: number;
  min_term: number;
  max_term: number;
  fee_type?: string;
  fee_value?: string;
  fee_collection?: string;
  penalty_type?: string;
  penalty_value?: string;
  grace_period_days?: number;
  max_debt_service_ratio?: string | null;
  min_age?: number | null;
  max_age?: number | null;
  max_active_loans?: number;
  requires_guarantor: boolean;
  requires_collateral: boolean;
  is_active?: boolean;
}

export interface EligibilityCheck {
  key: string;
  label: string;
  status: 'pass' | 'fail' | 'warn';
  detail: string;
}

export interface LoanApplication {
  id: number;
  application_number: string;
  customer_id: number;
  customer?: Customer;
  product?: LoanProduct;
  loan_product_id: number;
  branch?: Ref;
  amount_requested_minor: number;
  term: number;
  purpose: string;
  monthly_income_minor: number | null;
  monthly_obligations_minor: number;
  guarantors: { name: string; phone: string; relationship?: string; id_number?: string }[] | null;
  collateral: { description: string; estimated_value?: number | string }[] | null;
  eligibility: { eligible: boolean; checks: EligibilityCheck[]; installment_minor: number; monthly_installment_minor: number } | null;
  status: string;
  approved_amount_minor: number | null;
  approved_term: number | null;
  decision_notes: string | null;
  created_by?: Ref | null;
  decided_by?: Ref | null;
  loan_officer?: Ref | null;
  loan?: { id: number; loan_number: string; status: string } | null;
  submitted_at: string | null;
  decided_at: string | null;
  created_at: string;
}

export interface Installment {
  id: number;
  installment_number: number;
  due_date: string;
  principal_due_minor: number;
  interest_due_minor: number;
  fees_due_minor: number;
  penalty_due_minor: number;
  total_due_minor: number;
  total_paid_minor: number;
  balance_minor: number;
  status: string;
}

export interface Loan {
  id: number;
  loan_number: string;
  customer?: Customer;
  customer_id: number;
  product?: Pick<LoanProduct, 'id' | 'name'> & { code?: string };
  branch?: Ref;
  application?: { id: number; application_number: string; purpose: string };
  principal_minor: number;
  interest_rate: string;
  rate_period: string;
  interest_method: string;
  repayment_frequency: string;
  term: number;
  fee_collection: string;
  fee_minor: number;
  total_interest_minor: number;
  total_payable_minor: number;
  net_disbursed_minor: number;
  principal_paid_minor: number;
  interest_paid_minor: number;
  fees_paid_minor: number;
  penalty_paid_minor: number;
  penalty_charged_minor: number;
  status: string;
  days_in_arrears: number;
  disbursement_date: string | null;
  first_due_date: string | null;
  maturity_date: string | null;
  disbursement_method: string | null;
  installments?: Installment[];
  loan_officer?: Ref | null;
  outstanding_minor?: number;
  overdue_minor?: number;
  bucket?: string;
  collection_activities?: CollectionActivity[];
  last_activity?: CollectionActivity | null;
  write_off_reason?: string | null;
  created_at: string;
}

export interface CollectionActivity {
  id: number;
  activity_type: string;
  outcome: string | null;
  notes: string | null;
  promise_date: string | null;
  promise_amount_minor: number | null;
  next_action_date: string | null;
  user?: Ref | null;
  created_at: string;
}

export interface Repayment {
  id: number;
  receipt_number: string;
  loan?: { id: number; loan_number: string };
  loan_id: number;
  customer?: Pick<Customer, 'id' | 'display_name' | 'customer_number'>;
  amount_minor: number;
  principal_minor: number;
  interest_minor: number;
  fees_minor: number;
  penalty_minor: number;
  payment_method: string;
  reference: string | null;
  paid_on: string;
  status: string;
  received_by?: Ref | null;
  reversal_reason?: string | null;
  branch?: Ref;
}

export interface Service {
  id: number;
  name: string;
  code: string;
  category: string | null;
  description?: string | null;
  price_minor: number;
  price_is_variable: boolean;
  tax_rate: string;
  duration_minutes?: number | null;
  requires_appointment: boolean;
  is_active?: boolean;
}

export interface ServiceRequest {
  id: number;
  request_number: string;
  customer?: Customer;
  customer_id: number;
  service?: Service;
  service_id: number;
  branch?: Ref;
  status: string;
  priority: string;
  description: string | null;
  quantity: string;
  unit_price_minor: number;
  discount_minor: number;
  total_minor: number;
  scheduled_at: string | null;
  assignee?: Ref | null;
  assigned_to: number | null;
  completed_at: string | null;
  completion_notes: string | null;
  cancel_reason: string | null;
  invoice?: { id: number; invoice_number: string; status: string; total_minor: number; amount_paid_minor: number } | null;
  invoice_id: number | null;
  updates?: { id: number; status_from: string | null; status_to: string | null; note: string | null; user?: Ref | null; created_at: string }[];
  created_by?: Ref | null;
  created_at: string;
}

export interface InvoiceItem {
  id: number;
  description: string;
  quantity: string;
  unit_price_minor: number;
  discount_minor: number;
  tax_rate: string;
  tax_minor: number;
  line_total_minor: number;
}

export interface Payment {
  id: number;
  receipt_number: string;
  customer?: Pick<Customer, 'id' | 'display_name' | 'customer_number'>;
  invoice?: { id: number; invoice_number: string };
  invoice_id: number;
  amount_minor: number;
  refunded_minor: number;
  payment_method: string;
  reference: string | null;
  paid_on: string;
  status: string;
  received_by?: Ref | null;
  refunds?: Refund[];
  branch?: Ref;
}

export interface Refund {
  id: number;
  payment_id: number;
  payment?: Payment & { customer?: Pick<Customer, 'id' | 'display_name'> };
  amount_minor: number;
  reason: string;
  status: string;
  requested_by?: Ref | null;
  decided_by?: Ref | null;
  decided_at: string | null;
  decision_notes: string | null;
  created_at: string;
}

export interface Invoice {
  id: number;
  invoice_number: string;
  customer?: Customer;
  customer_id: number;
  branch?: Ref;
  service_request?: { id: number; request_number: string } | null;
  issue_date: string;
  due_date: string;
  status: string;
  subtotal_minor: number;
  discount_minor: number;
  tax_minor: number;
  total_minor: number;
  amount_paid_minor: number;
  amount_refunded_minor: number;
  balance_minor: number;
  notes: string | null;
  items?: InvoiceItem[];
  payments?: Payment[];
  issued_by?: Ref | null;
  void_reason?: string | null;
}

export interface Account {
  id: number;
  code: string;
  name: string;
  type: string;
  system_key: string | null;
  description: string | null;
  is_active: boolean;
  debit_minor?: number;
  credit_minor?: number;
  balance_minor?: number;
}

export interface JournalEntry {
  id: number;
  entry_number: string;
  entry_date: string;
  description: string;
  source_type: string | null;
  total_minor: number;
  reversed_at: string | null;
  posted_by?: Ref | null;
  branch?: Ref | null;
  lines: { id: number; account: Pick<Account, 'id' | 'code' | 'name'>; debit_minor: number; credit_minor: number; memo: string | null }[];
}

export interface Expense {
  id: number;
  expense_number: string;
  account?: Pick<Account, 'id' | 'code' | 'name'>;
  branch?: Ref;
  payment_method: string;
  amount_minor: number;
  expense_date: string;
  payee: string | null;
  reference: string | null;
  description: string;
  status: string;
  recorded_by?: Ref | null;
  void_reason?: string | null;
}

export interface StaffUser {
  id: number;
  name: string;
  email: string;
  phone: string | null;
  job_title: string | null;
  status: string;
  last_login_at: string | null;
  primary_branch_id: number | null;
  primary_branch?: Ref | null;
  roles: Ref[];
  branches: Ref[];
}

export interface Role {
  id: number;
  name: string;
  slug: string;
  description: string | null;
  permissions: string[];
  is_system: boolean;
  users_count?: number;
}

export interface AuditEntry {
  id: number;
  action: string;
  description: string | null;
  actor_label: string | null;
  auditable_type: string | null;
  auditable_id: number | null;
  changes: Record<string, { old: unknown; new: unknown }> | null;
  metadata: Record<string, unknown> | null;
  ip_address: string | null;
  created_at: string;
}

export interface Lookups {
  branches: Pick<Branch, 'id' | 'name' | 'code'>[];
  staff: { id: number; name: string; job_title: string | null }[];
  loan_products: LoanProduct[];
  services: Service[];
  enums: {
    id_types: string[];
    business_types: string[];
    document_types: string[];
    identity_document_types: string[];
    business_document_types: string[];
    payment_methods: string[];
    consent_types: string[];
    consent_methods: string[];
    collection_activity_types: string[];
  };
}

export interface ReportResult {
  title: string;
  from: string;
  to: string;
  columns: { key: string; label: string; type: 'text' | 'number' | 'money' | 'percent' | 'date' }[];
  rows: Record<string, string | number | null>[];
  totals: Record<string, number> | null;
  notes?: string;
}
