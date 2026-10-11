import { useEffect, useState } from 'react';
import { Link, useParams, useSearchParams } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { ArrowLeft, ArrowRight, Briefcase, Building2, HandCoins, Pencil, Plus, User } from 'lucide-react';
import { api, errorMessage } from '../../lib/api';
import { useAuth } from '../../lib/auth';
import { date, dateTime, label, money, toMajor } from '../../lib/format';
import { useAction, useForm, useLookups } from '../../lib/hooks';
import type { AuditEntry, Customer, CustomerDetail, Invoice, Loan, Paginated, ServiceRequest } from '../../lib/types';
import { DataTable } from '../../components/DataTable';
import { ConfirmDialog, Modal } from '../../components/Modal';
import { Alert, Badge, Button, ButtonLink, Card, CardHeader, DescriptionList, ErrorState, LoadingBlock, PageHeader, Stat, StatusBadge, Tabs } from '../../components/ui';
import { CustomerForm, customerPayload, type CustomerFormValues } from './CustomerForm';
import { DocumentsPanel } from './DocumentsPanel';
import { BiometricPanel } from './BiometricPanel';
import { ConsentPanel } from './ConsentPanel';
import { Checklist } from './Checklist';
import { ReviewDecision } from './ReviewDecision';
import { customerKeys } from './shared';

type Tab = 'overview' | 'documents' | 'verification' | 'loans' | 'services' | 'activity';

export default function CustomerDetailPage() {
  const { id = '' } = useParams();
  const { can } = useAuth();
  const [params, setParams] = useSearchParams();
  const [tab, setTab] = useState<Tab>('overview');
  const [editing, setEditing] = useState(params.get('edit') === '1');
  const [statusChange, setStatusChange] = useState<'active' | 'suspended' | 'closed' | null>(null);

  const { data, isLoading, error, refetch } = useQuery({ queryKey: ['customer', id], queryFn: () => api.get<CustomerDetail>(`/api/customers/${id}`) });

  const setStatus = useAction({
    fn: ({ status, reason }: { status: string; reason: string }) => api.post(`/api/customers/${id}/status`, { status, reason }),
    success: 'Customer status updated',
    invalidate: customerKeys(id),
    onSuccess: () => setStatusChange(null),
  });

  if (isLoading) return <LoadingBlock rows={8} />;
  if (error || !data) return <ErrorState message={errorMessage(error)} onRetry={() => refetch()} />;

  const { customer: c, summary, onboarding } = data;
  const onboarded = c.onboarding_status === 'completed';

  return (
    <>
      <PageHeader
        back={
          <Link to="/customers" className="inline-flex items-center gap-1 text-sm text-slate-500 hover:text-slate-800">
            <ArrowLeft className="size-4" /> Customers
          </Link>
        }
        title={
          <span className="flex items-center gap-3">
            <span className="flex size-11 items-center justify-center rounded-full bg-brand-50 text-brand-700">{c.type === 'business' ? <Building2 className="size-5" /> : <User className="size-5" />}</span>
            {c.display_name}
          </span>
        }
        description={
          <span className="flex flex-wrap items-center gap-2">
            {c.customer_number} · {label(c.type)} · {c.branch?.name}
            <StatusBadge status={c.status} />
            <StatusBadge status={c.verification_status} label={`Identity: ${label(c.verification_status).toLowerCase()}`} />
          </span>
        }
        actions={
          <>
            {can('customers.update') && (
              <Button variant="secondary" icon={<Pencil className="size-4" />} onClick={() => setEditing(true)}>
                Edit
              </Button>
            )}
            {onboarded && can('service_requests.create') && (
              <ButtonLink variant="secondary" to={`/services/requests?new=1&customer_id=${c.id}`} icon={<Briefcase className="size-4" />}>
                New service request
              </ButtonLink>
            )}
            {onboarded && c.status === 'active' && can('loan_applications.create') && (
              <ButtonLink to={`/loans/applications/new?customer_id=${c.id}`} icon={<HandCoins className="size-4" />}>
                New loan application
              </ButtonLink>
            )}
          </>
        }
      />

      {!onboarded && c.onboarding_status !== 'rejected' && (
        <div className="mb-6">
          <Alert tone={c.onboarding_status === 'manual_review' ? 'amber' : 'blue'} title={c.onboarding_status === 'manual_review' ? 'Awaiting manual identity review' : 'Onboarding in progress'}>
            <div className="flex flex-wrap items-center justify-between gap-3">
              <span>
                {onboarding.items.filter((i) => i.required && i.status === 'complete').length} of {onboarding.items.filter((i) => i.required).length} requirements complete. Loans are available once onboarding is complete.
              </span>
              <ButtonLink to={`/customers/${c.id}/onboarding?step=${c.onboarding_status === 'manual_review' ? 'review' : 'consent'}`} variant="soft">
                {c.onboarding_status === 'manual_review' ? 'Open review' : 'Continue onboarding'} <ArrowRight className="size-4" />
              </ButtonLink>
            </div>
          </Alert>
        </div>
      )}

      <div className="mb-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <Stat label="Open loans" value={summary.loans_open} hint={`${summary.loans_total} in total`} icon={<HandCoins className="size-5" />} tone={summary.in_arrears ? 'red' : 'green'} />
        <Stat label="Loan balance" value={money(summary.loan_outstanding_minor)} hint={summary.in_arrears ? 'In arrears' : 'Up to date'} tone={summary.in_arrears ? 'red' : 'blue'} />
        <Stat label="Service requests" value={summary.service_requests} icon={<Briefcase className="size-5" />} tone="violet" />
        <Stat label="Unpaid invoices" value={money(summary.invoice_balance_minor)} tone="amber" />
      </div>

      <Tabs<Tab>
        value={tab}
        onChange={setTab}
        tabs={[
          { value: 'overview', label: 'Overview' },
          ...(can('customer_documents.view') ? [{ value: 'documents' as Tab, label: 'Documents' }] : []),
          ...(can(['biometrics.view', 'biometrics.capture']) ? [{ value: 'verification' as Tab, label: 'Consent & verification' }] : []),
          ...(can('loans.view') ? [{ value: 'loans' as Tab, label: 'Loans', count: summary.loans_total }] : []),
          ...(can(['service_requests.view', 'invoices.view']) ? [{ value: 'services' as Tab, label: 'Services & billing' }] : []),
          { value: 'activity', label: 'Activity' },
        ]}
      />

      {tab === 'overview' && <Overview customer={c} checklist={onboarding.items} onStatus={setStatusChange} />}
      {tab === 'documents' && <DocumentsPanel customer={c} />}
      {tab === 'verification' && (
        <div className="space-y-6">
          <BiometricPanel customer={c} />
          <ConsentPanel customer={c} readOnly />
        </div>
      )}
      {tab === 'loans' && <CustomerLoans customerId={c.id} />}
      {tab === 'services' && <CustomerServices customerId={c.id} />}
      {tab === 'activity' && <Activity customerId={c.id} />}

      {editing && (
        <EditCustomer
          customer={c}
          onClose={() => {
            setEditing(false);
            if (params.get('edit')) setParams({}, { replace: true });
          }}
        />
      )}
      <ConfirmDialog
        open={!!statusChange}
        onClose={() => setStatusChange(null)}
        title={statusChange === 'active' ? 'Reactivate customer' : statusChange === 'suspended' ? 'Suspend customer' : 'Close account'}
        message={statusChange === 'active' ? 'The customer will be able to use loans and services again.' : 'The customer will not be able to start new loans or services.'}
        reasonLabel="Reason"
        danger={statusChange !== 'active'}
        loading={setStatus.isPending}
        onConfirm={(reason) => statusChange && setStatus.mutate({ status: statusChange, reason })}
      />
    </>
  );
}

function Overview({ customer: c, checklist, onStatus }: { customer: Customer; checklist: CustomerDetail['onboarding']['items']; onStatus: (s: 'active' | 'suspended' | 'closed') => void }) {
  const { can } = useAuth();
  const isBusiness = c.type === 'business';

  return (
    <div className="grid gap-6 lg:grid-cols-3">
      <div className="space-y-6 lg:col-span-2">
        <Card>
          <CardHeader title={isBusiness ? 'Business profile' : 'Personal profile'} />
          <DescriptionList
            items={
              isBusiness
                ? [
                    { label: 'Registered name', value: c.business_name },
                    { label: 'Trading name', value: c.trading_name },
                    { label: 'Registration no.', value: c.registration_number },
                    { label: 'TIN', value: c.tin },
                    { label: 'Business type', value: label(c.business_type) },
                    { label: 'Industry', value: c.industry },
                    { label: 'Incorporated', value: date(c.date_of_incorporation) },
                    { label: 'Monthly revenue', value: c.monthly_income_minor ? money(c.monthly_income_minor) : null },
                  ]
                : [
                    { label: 'Full name', value: [c.title, c.display_name].filter(Boolean).join(' ') },
                    { label: 'Date of birth', value: date(c.date_of_birth) },
                    { label: 'Gender', value: label(c.gender) },
                    { label: 'Marital status', value: label(c.marital_status) },
                    { label: 'Occupation', value: c.occupation },
                    { label: 'Employer', value: c.employer },
                    { label: 'Monthly income', value: c.monthly_income_minor ? money(c.monthly_income_minor) : null },
                    { label: 'Identity document', value: c.id_type ? `${label(c.id_type)} · ${c.id_number_masked ?? ''}` : null },
                  ]
            }
          />
        </Card>
        <Card>
          <CardHeader title="Contact" />
          <DescriptionList
            items={[
              { label: 'Phone', value: c.phone },
              { label: 'Alternative phone', value: c.alt_phone },
              { label: 'Email', value: c.email },
              { label: 'Digital address', value: c.digital_address },
              { label: 'Address', value: c.address },
              { label: 'City / region', value: [c.city, c.region].filter(Boolean).join(', ') },
            ]}
          />
        </Card>
        {isBusiness && (
          <Card>
            <CardHeader title="Representatives" description="People authorised to act for the business." />
            <ul className="divide-y divide-slate-100">
              {(c.representatives ?? []).map((r) => (
                <li key={r.id} className="flex flex-wrap items-center justify-between gap-2 py-3">
                  <div>
                    <p className="flex items-center gap-2 text-sm font-medium text-slate-900">
                      {r.full_name}
                      {r.is_primary && <Badge tone="blue">Primary</Badge>}
                      {r.is_signatory && <Badge>Signatory</Badge>}
                    </p>
                    <p className="text-xs text-slate-500">
                      {[r.position, r.phone, r.id_type ? `${label(r.id_type)} ${r.id_number_masked ?? ''}` : null].filter(Boolean).join(' · ')}
                    </p>
                  </div>
                  <StatusBadge status={r.verification_status} />
                </li>
              ))}
            </ul>
          </Card>
        )}
      </div>
      <div className="space-y-6">
        {c.onboarding_status === 'manual_review' && can('onboarding.review') && <ReviewDecision customer={c} />}
        <Card>
          <CardHeader title="Onboarding" />
          <DescriptionList
            columns={1}
            items={[
              { label: 'Status', value: <StatusBadge status={c.onboarding_status} /> },
              { label: 'Registered', value: `${dateTime(c.created_at)} by ${c.created_by?.name ?? '—'}` },
              { label: 'Onboarded', value: c.onboarded_at ? `${dateTime(c.onboarded_at)} by ${c.onboarded_by?.name ?? '—'}` : null },
              { label: 'Manual review', value: c.review_notes ? `${c.reviewer?.name ? `${c.reviewer.name}: ` : ''}${c.review_notes}` : c.manual_review_reason },
              { label: 'Relationship officer', value: c.relationship_officer?.name },
              { label: 'Risk rating', value: label(c.risk_rating) },
            ]}
          />
          {c.onboarding_status !== 'completed' && (
            <div className="mt-4 border-t border-slate-100 pt-2">
              <Checklist items={checklist} />
            </div>
          )}
        </Card>
        {can('customers.update') && c.onboarding_status === 'completed' && (
          <Card>
            <CardHeader title="Account status" />
            <div className="flex flex-wrap gap-2">
              {c.status !== 'active' && c.status !== 'closed' && (
                <Button size="sm" onClick={() => onStatus('active')}>
                  Reactivate
                </Button>
              )}
              {c.status === 'active' && (
                <Button size="sm" variant="secondary" onClick={() => onStatus('suspended')}>
                  Suspend
                </Button>
              )}
              {c.status !== 'closed' && (
                <Button size="sm" variant="ghost" onClick={() => onStatus('closed')}>
                  Close account
                </Button>
              )}
            </div>
          </Card>
        )}
      </div>
    </div>
  );
}

function EditCustomer({ customer: c, onClose }: { customer: Customer; onClose: () => void }) {
  const { data: lookups } = useLookups();
  const s = (v: string | number | null | undefined) => (v === null || v === undefined ? '' : String(v));
  const form = useForm<CustomerFormValues>({
    type: c.type, branch_id: s(c.branch_id), title: s(c.title), first_name: s(c.first_name), middle_name: s(c.middle_name),
    last_name: s(c.last_name), gender: s(c.gender), date_of_birth: s(c.date_of_birth), marital_status: s(c.marital_status),
    nationality: s(c.nationality), occupation: s(c.occupation), employer: s(c.employer), id_type: s(c.id_type), id_number: '',
    business_name: s(c.business_name), trading_name: s(c.trading_name), registration_number: s(c.registration_number), tin: s(c.tin),
    business_type: s(c.business_type), industry: s(c.industry), date_of_incorporation: s(c.date_of_incorporation),
    monthly_income: toMajor(c.monthly_income_minor), phone: s(c.phone), alt_phone: s(c.alt_phone), email: s(c.email),
    address: s(c.address), digital_address: s(c.digital_address), city: s(c.city), region: s(c.region),
    relationship_officer_id: s(c.relationship_officer_id), notes: s(c.notes), representatives: [],
  });

  const save = useAction({
    fn: () => api.put(`/api/customers/${c.id}`, customerPayload(form.values, true)),
    success: 'Customer updated',
    invalidate: customerKeys(c.id),
    onSuccess: onClose,
    onError: form.absorb,
  });

  useEffect(() => {
    form.setErrors({});
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  return (
    <Modal
      open
      onClose={onClose}
      title={`Edit ${c.display_name}`}
      size="xl"
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>
            Cancel
          </Button>
          <Button loading={save.isPending} onClick={() => save.mutate()}>
            Save changes
          </Button>
        </>
      }
    >
      <CustomerForm values={form.values} set={form.set} errors={form.errors} lookups={lookups} editing />
    </Modal>
  );
}

function CustomerLoans({ customerId }: { customerId: number }) {
  const { can } = useAuth();
  const loans = useQuery({ queryKey: ['/api/loans', { customer_id: customerId }], queryFn: () => api.get<Paginated<Loan>>('/api/loans', { customer_id: customerId, per_page: 50 }) });
  const apps = useQuery({
    queryKey: ['/api/loan-applications', { customer_id: customerId }],
    queryFn: () => api.get<Paginated<import('../../lib/types').LoanApplication>>('/api/loan-applications', { customer_id: customerId, per_page: 50 }),
    enabled: can('loan_applications.view'),
  });

  return (
    <div className="space-y-6">
      <Card padded={false}>
        <div className="p-5 pb-0">
          <CardHeader title="Loans" />
        </div>
        <DataTable<Loan>
          rows={loans.data?.data}
          loading={loans.isLoading}
          error={loans.error}
          rowLink={(l) => `/loans/${l.id}`}
          empty={{ title: 'No loans yet' }}
          columns={[
            { key: 'n', header: 'Loan', render: (l) => <span className="font-medium text-slate-900">{l.loan_number}</span> },
            { key: 'p', header: 'Product', render: (l) => l.product?.name },
            { key: 'a', header: 'Principal', align: 'right', render: (l) => <span className="tabular">{money(l.principal_minor)}</span> },
            { key: 'o', header: 'Outstanding', align: 'right', render: (l) => <span className="tabular">{money(l.outstanding_minor ?? 0)}</span> },
            { key: 's', header: 'Status', render: (l) => <StatusBadge status={l.status} /> },
          ]}
        />
      </Card>
      {can('loan_applications.view') && (
        <Card padded={false}>
          <div className="p-5 pb-0">
            <CardHeader title="Applications" />
          </div>
          <DataTable
            rows={apps.data?.data}
            loading={apps.isLoading}
            rowLink={(a) => `/loans/applications/${a.id}`}
            empty={{ title: 'No applications' }}
            columns={[
              { key: 'n', header: 'Application', render: (a) => <span className="font-medium text-slate-900">{a.application_number}</span> },
              { key: 'amt', header: 'Amount', align: 'right', render: (a) => <span className="tabular">{money(a.amount_requested_minor)}</span> },
              { key: 'd', header: 'Created', render: (a) => date(a.created_at) },
              { key: 's', header: 'Status', render: (a) => <StatusBadge status={a.status} /> },
            ]}
          />
        </Card>
      )}
    </div>
  );
}

function CustomerServices({ customerId }: { customerId: number }) {
  const { can } = useAuth();
  const requests = useQuery({
    queryKey: ['/api/service-requests', { customer_id: customerId }],
    queryFn: () => api.get<Paginated<ServiceRequest>>('/api/service-requests', { customer_id: customerId, per_page: 50 }),
    enabled: can('service_requests.view'),
  });
  const invoices = useQuery({
    queryKey: ['/api/invoices', { customer_id: customerId }],
    queryFn: () => api.get<Paginated<Invoice>>('/api/invoices', { customer_id: customerId, per_page: 50 }),
    enabled: can('invoices.view'),
  });

  return (
    <div className="space-y-6">
      {can('service_requests.view') && (
        <Card padded={false}>
          <div className="p-5 pb-0">
            <CardHeader
              title="Service requests"
              actions={
                can('service_requests.create') && (
                  <ButtonLink to={`/services/requests?new=1&customer_id=${customerId}`} variant="soft" icon={<Plus className="size-4" />}>
                    New request
                  </ButtonLink>
                )
              }
            />
          </div>
          <DataTable<ServiceRequest>
            rows={requests.data?.data}
            loading={requests.isLoading}
            rowLink={(r) => `/services/requests/${r.id}`}
            empty={{ title: 'No service requests' }}
            columns={[
              { key: 'n', header: 'Request', render: (r) => <span className="font-medium text-slate-900">{r.request_number}</span> },
              { key: 's', header: 'Service', render: (r) => r.service?.name },
              { key: 't', header: 'Total', align: 'right', render: (r) => <span className="tabular">{money(r.total_minor)}</span> },
              { key: 'st', header: 'Status', render: (r) => <StatusBadge status={r.status} /> },
            ]}
          />
        </Card>
      )}
      {can('invoices.view') && (
        <Card padded={false}>
          <div className="p-5 pb-0">
            <CardHeader title="Invoices" />
          </div>
          <DataTable<Invoice>
            rows={invoices.data?.data}
            loading={invoices.isLoading}
            rowLink={(i) => `/billing/invoices/${i.id}`}
            empty={{ title: 'No invoices' }}
            columns={[
              { key: 'n', header: 'Invoice', render: (i) => <span className="font-medium text-slate-900">{i.invoice_number}</span> },
              { key: 'd', header: 'Issued', render: (i) => date(i.issue_date) },
              { key: 't', header: 'Total', align: 'right', render: (i) => <span className="tabular">{money(i.total_minor)}</span> },
              { key: 'b', header: 'Balance', align: 'right', render: (i) => <span className="tabular">{money(i.balance_minor)}</span> },
              { key: 's', header: 'Status', render: (i) => <StatusBadge status={i.status} /> },
            ]}
          />
        </Card>
      )}
    </div>
  );
}

function Activity({ customerId }: { customerId: number }) {
  const { data, isLoading, error } = useQuery({
    queryKey: ['customer-activity', String(customerId)],
    queryFn: () => api.get<{ data: AuditEntry[] }>(`/api/customers/${customerId}/activity`),
  });
  if (isLoading) return <LoadingBlock rows={5} />;
  if (error) return <ErrorState message={errorMessage(error)} />;

  return (
    <Card>
      <CardHeader title="Activity" description="Changes to this customer's record, documents, consent and verification." />
      <ol className="relative ml-2 border-l border-slate-200">
        {(data?.data ?? []).map((e) => (
          <li key={e.id} className="mb-5 ml-5">
            <span className="absolute -left-[5px] mt-1.5 size-2.5 rounded-full bg-brand-500 ring-4 ring-white" />
            <p className="text-sm text-slate-800">{e.description ?? label(e.action.replace('.', ' '))}</p>
            <p className="mt-0.5 text-xs text-slate-500">
              {dateTime(e.created_at)} · {e.actor_label?.split(' <')[0] ?? 'System'}
            </p>
          </li>
        ))}
      </ol>
    </Card>
  );
}
