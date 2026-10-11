import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Calculator, Pencil, Plus } from 'lucide-react';
import { api, errorMessage } from '../../lib/api';
import { useAuth } from '../../lib/auth';
import { label, money, toMajor } from '../../lib/format';
import { useAction, useForm } from '../../lib/hooks';
import type { LoanProduct } from '../../lib/types';
import { Modal } from '../../components/Modal';
import { Badge, Button, Card, Checkbox, EmptyState, ErrorState, Field, LoadingBlock, PageHeader, SectionTitle, SelectField, TextField, Textarea } from '../../components/ui';

export default function ProductsPage() {
  const { can } = useAuth();
  const [editing, setEditing] = useState<LoanProduct | 'new' | null>(null);
  const { data, isLoading, error, refetch } = useQuery({ queryKey: ['/api/loan-products'], queryFn: () => api.get<{ data: LoanProduct[] }>('/api/loan-products') });

  return (
    <>
      <PageHeader
        title="Loan products"
        description="Terms are copied onto each loan at approval, so editing a product never changes existing loans."
        actions={
          can('loan_products.manage') && (
            <Button icon={<Plus className="size-4" />} onClick={() => setEditing('new')}>
              New product
            </Button>
          )
        }
      />
      {isLoading ? (
        <LoadingBlock />
      ) : error ? (
        <ErrorState message={errorMessage(error)} onRetry={() => refetch()} />
      ) : (data?.data ?? []).length === 0 ? (
        <Card>
          <EmptyState icon={<Calculator className="size-6" />} title="No loan products" description="Create a product to start taking applications." />
        </Card>
      ) : (
        <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
          {data!.data.map((p) => (
            <Card key={p.id} className={p.is_active ? '' : 'opacity-60'}>
              <div className="flex items-start justify-between gap-2">
                <div>
                  <p className="font-semibold text-slate-900">{p.name}</p>
                  <p className="text-xs text-slate-500">
                    {p.code} · {label(p.customer_type)} customers
                  </p>
                </div>
                <div className="flex items-center gap-1">
                  {!p.is_active && <Badge>Inactive</Badge>}
                  {can('loan_products.manage') && (
                    <button className="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 hover:text-slate-700" aria-label={`Edit ${p.name}`} onClick={() => setEditing(p)}>
                      <Pencil className="size-4" />
                    </button>
                  )}
                </div>
              </div>
              <p className="tabular mt-4 text-3xl font-semibold text-brand-800">
                {parseFloat(p.interest_rate)}%<span className="ml-1 text-sm font-normal text-slate-500">{label(p.rate_period).toLowerCase()}</span>
              </p>
              <p className="text-sm text-slate-500">
                {label(p.interest_method)} · repaid {p.repayment_frequency}
              </p>
              <dl className="mt-4 grid grid-cols-2 gap-3 text-sm">
                <div>
                  <dt className="text-xs text-slate-500">Amount</dt>
                  <dd className="tabular">
                    {money(p.min_amount_minor)} – {money(p.max_amount_minor)}
                  </dd>
                </div>
                <div>
                  <dt className="text-xs text-slate-500">Term</dt>
                  <dd>
                    {p.min_term}–{p.max_term} installments
                  </dd>
                </div>
                <div>
                  <dt className="text-xs text-slate-500">Fee</dt>
                  <dd>
                    {p.fee_type === 'fixed' ? `GHS ${p.fee_value}` : `${parseFloat(p.fee_value ?? '0')}%`} {p.fee_collection}
                  </dd>
                </div>
                <div>
                  <dt className="text-xs text-slate-500">Late penalty</dt>
                  <dd>
                    {p.penalty_type === 'fixed' ? `GHS ${p.penalty_value}` : `${parseFloat(p.penalty_value ?? '0')}%`} after {p.grace_period_days}d
                  </dd>
                </div>
              </dl>
            </Card>
          ))}
        </div>
      )}
      {editing && <ProductModal product={editing === 'new' ? null : editing} onClose={() => setEditing(null)} />}
    </>
  );
}

function ProductModal({ product: p, onClose }: { product: LoanProduct | null; onClose: () => void }) {
  const s = (v: unknown, d = '') => (v === null || v === undefined ? d : String(v));
  const form = useForm({
    name: s(p?.name), code: s(p?.code), description: s(p?.description), customer_type: s(p?.customer_type, 'both'),
    interest_method: s(p?.interest_method, 'flat'), interest_rate: p ? String(parseFloat(p.interest_rate)) : '', rate_period: s(p?.rate_period, 'per_month'),
    repayment_frequency: s(p?.repayment_frequency, 'monthly'), min_amount: p ? toMajor(p.min_amount_minor) : '', max_amount: p ? toMajor(p.max_amount_minor) : '',
    min_term: s(p?.min_term, '1'), max_term: s(p?.max_term, '12'), fee_type: s(p?.fee_type, 'percent'), fee_value: p ? String(parseFloat(p.fee_value ?? '0')) : '0',
    fee_collection: s(p?.fee_collection, 'deducted'), penalty_type: s(p?.penalty_type, 'percent'), penalty_value: p ? String(parseFloat(p.penalty_value ?? '0')) : '0',
    grace_period_days: s(p?.grace_period_days, '0'), max_debt_service_ratio: p?.max_debt_service_ratio ? String(parseFloat(p.max_debt_service_ratio)) : '',
    min_age: s(p?.min_age), max_age: s(p?.max_age), max_active_loans: s(p?.max_active_loans, '1'),
    requires_guarantor: p?.requires_guarantor ?? false, requires_collateral: p?.requires_collateral ?? false, is_active: p?.is_active ?? true,
  });
  const { values: v, set, errors } = form;
  const save = useAction({
    fn: () => {
      const body = Object.fromEntries(Object.entries(v).map(([k, val]) => [k, val === '' ? null : val]));
      return p ? api.put(`/api/loan-products/${p.id}`, body) : api.post('/api/loan-products', body);
    },
    success: p ? 'Product updated' : 'Product created',
    invalidate: [['/api/loan-products'], ['lookups']],
    onSuccess: onClose,
    onError: form.absorb,
  });

  return (
    <Modal
      open
      onClose={onClose}
      size="xl"
      title={p ? `Edit ${p.name}` : 'New loan product'}
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>
            Cancel
          </Button>
          <Button loading={save.isPending} onClick={() => save.mutate()}>
            Save product
          </Button>
        </>
      }
    >
      <div className="space-y-6">
        <section className="grid gap-4 sm:grid-cols-3">
          <TextField label="Name" value={v.name} onChange={(x) => set('name', x)} error={errors.name} required className="sm:col-span-2" />
          <TextField label="Code" value={v.code} onChange={(x) => set('code', x.toUpperCase())} error={errors.code} required />
          <SelectField label="Available to" value={v.customer_type} onChange={(x) => set('customer_type', x)} options={['both', 'individual', 'business']} />
          <Field label="Description" className="sm:col-span-2">
            {(id) => <Textarea id={id} value={v.description} onChange={(e) => set('description', e.target.value)} className="min-h-10" />}
          </Field>
        </section>
        <section>
          <SectionTitle>Pricing</SectionTitle>
          <div className="grid gap-4 sm:grid-cols-4">
            <SelectField label="Interest method" value={v.interest_method} onChange={(x) => set('interest_method', x)} options={['flat', 'reducing_balance']} />
            <TextField label="Interest rate (%)" type="number" step="0.01" value={v.interest_rate} onChange={(x) => set('interest_rate', x)} error={errors.interest_rate} required />
            <SelectField label="Rate period" value={v.rate_period} onChange={(x) => set('rate_period', x)} options={['per_month', 'per_annum']} />
            <SelectField label="Repayment frequency" value={v.repayment_frequency} onChange={(x) => set('repayment_frequency', x)} options={['weekly', 'biweekly', 'monthly']} />
            <TextField label="Min amount (GHS)" type="number" value={v.min_amount} onChange={(x) => set('min_amount', x)} error={errors.min_amount} required />
            <TextField label="Max amount (GHS)" type="number" value={v.max_amount} onChange={(x) => set('max_amount', x)} error={errors.max_amount} required />
            <TextField label="Min installments" type="number" value={v.min_term} onChange={(x) => set('min_term', x)} error={errors.min_term} required />
            <TextField label="Max installments" type="number" value={v.max_term} onChange={(x) => set('max_term', x)} error={errors.max_term} required />
            <SelectField label="Fee type" value={v.fee_type} onChange={(x) => set('fee_type', x)} options={['percent', 'fixed']} />
            <TextField label={v.fee_type === 'percent' ? 'Fee (%)' : 'Fee (GHS)'} type="number" step="0.01" value={v.fee_value} onChange={(x) => set('fee_value', x)} error={errors.fee_value} />
            <SelectField label="Fee collection" value={v.fee_collection} onChange={(x) => set('fee_collection', x)} options={[{ value: 'deducted', label: 'Deducted at disbursement' }, { value: 'capitalised', label: 'Added to repayments' }]} className="sm:col-span-2" />
            <SelectField label="Penalty type" value={v.penalty_type} onChange={(x) => set('penalty_type', x)} options={['percent', 'fixed']} />
            <TextField label={v.penalty_type === 'percent' ? 'Penalty (% of overdue)' : 'Penalty (GHS)'} type="number" step="0.01" value={v.penalty_value} onChange={(x) => set('penalty_value', x)} error={errors.penalty_value} />
            <TextField label="Grace period (days)" type="number" value={v.grace_period_days} onChange={(x) => set('grace_period_days', x)} error={errors.grace_period_days} />
          </div>
        </section>
        <section>
          <SectionTitle>Eligibility rules</SectionTitle>
          <div className="grid gap-4 sm:grid-cols-4">
            <TextField label="Max debt-service ratio (%)" type="number" value={v.max_debt_service_ratio} onChange={(x) => set('max_debt_service_ratio', x)} error={errors.max_debt_service_ratio} hint="Blank = not checked" />
            <TextField label="Min age" type="number" value={v.min_age} onChange={(x) => set('min_age', x)} error={errors.min_age} />
            <TextField label="Max age" type="number" value={v.max_age} onChange={(x) => set('max_age', x)} error={errors.max_age} />
            <TextField label="Max open loans per customer" type="number" value={v.max_active_loans} onChange={(x) => set('max_active_loans', x)} error={errors.max_active_loans} />
          </div>
          <div className="mt-4 grid gap-3 sm:grid-cols-3">
            <Checkbox checked={v.requires_guarantor} onChange={(x) => set('requires_guarantor', x)} label="Requires a guarantor" />
            <Checkbox checked={v.requires_collateral} onChange={(x) => set('requires_collateral', x)} label="Requires collateral" />
            <Checkbox checked={v.is_active} onChange={(x) => set('is_active', x)} label="Active" description="Inactive products accept no new applications." />
          </div>
        </section>
      </div>
    </Modal>
  );
}
