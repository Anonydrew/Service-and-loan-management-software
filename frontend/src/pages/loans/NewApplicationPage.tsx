import { useState } from 'react';
import { Link, useNavigate, useSearchParams } from 'react-router-dom';
import { ArrowLeft, Plus, Trash2 } from 'lucide-react';
import { api } from '../../lib/api';
import { label, money, toMajor } from '../../lib/format';
import { useAction, useForm, useLookups } from '../../lib/hooks';
import type { Customer, LoanApplication } from '../../lib/types';
import { CustomerPicker } from '../../components/CustomerPicker';
import { Alert, Button, Card, CardHeader, Field, PageHeader, SectionTitle, SelectField, TextField, Textarea } from '../../components/ui';
import { SchedulePreview } from './SchedulePreview';

interface Guarantor {
  name: string;
  phone: string;
  relationship: string;
  id_number: string;
}
interface Collateral {
  description: string;
  estimated_value: string;
}

export default function NewApplicationPage() {
  const [params] = useSearchParams();
  const navigate = useNavigate();
  const { data: lookups } = useLookups();
  const [customer, setCustomer] = useState<Customer | null>(null);
  const [guarantors, setGuarantors] = useState<Guarantor[]>([]);
  const [collateral, setCollateral] = useState<Collateral[]>([]);
  const form = useForm({ loan_product_id: '', amount: '', term: '', purpose: '', monthly_income: '', monthly_obligations: '' });
  const { values, set, errors } = form;

  const products = (lookups?.loan_products ?? []).filter((p) => !customer || p.customer_type === 'both' || p.customer_type === customer.type);
  const product = products.find((p) => String(p.id) === values.loan_product_id);

  const save = useAction({
    fn: () =>
      api.post<LoanApplication>('/api/loan-applications', {
        customer_id: customer?.id,
        ...values,
        monthly_income: values.monthly_income || null,
        monthly_obligations: values.monthly_obligations || 0,
        guarantors: guarantors.length ? guarantors : null,
        collateral: collateral.length ? collateral : null,
      }),
    success: (a) => `Draft ${a.application_number} saved`,
    invalidate: [['/api/loan-applications']],
    onSuccess: (a) => navigate(`/loans/applications/${a.id}`),
    onError: form.absorb,
  });

  return (
    <>
      <PageHeader
        back={
          <Link to="/loans/applications" className="inline-flex items-center gap-1 text-sm text-slate-500 hover:text-slate-800">
            <ArrowLeft className="size-4" /> Applications
          </Link>
        }
        title="New loan application"
        description="Saved as a draft. Eligibility is checked before it can be submitted for approval."
      />
      <div className="grid gap-6 lg:grid-cols-5">
        <div className="space-y-6 lg:col-span-3">
          <Card>
            <CardHeader title="Applicant & product" />
            <div className="grid gap-4 sm:grid-cols-2">
              <div className="sm:col-span-2">
                <CustomerPicker
                  value={customer}
                  onChange={(c) => {
                    setCustomer(c);
                    if (c?.monthly_income_minor && !values.monthly_income) set('monthly_income', toMajor(c.monthly_income_minor));
                  }}
                  error={errors.customer_id}
                  onlyOnboarded
                  initialId={params.get('customer_id')}
                />
              </div>
              <SelectField
                label="Loan product"
                value={values.loan_product_id}
                onChange={(v) => set('loan_product_id', v)}
                options={products.map((p) => ({ value: p.id, label: p.name }))}
                placeholder="Select…"
                error={errors.loan_product_id}
                required
                className="sm:col-span-2"
              />
              {product && (
                <div className="rounded-xl bg-slate-50 p-3 text-xs text-slate-600 sm:col-span-2">
                  {parseFloat(product.interest_rate)}% {label(product.rate_period).toLowerCase()} · {label(product.interest_method).toLowerCase()} · {label(product.repayment_frequency).toLowerCase()} · {money(product.min_amount_minor)}–{money(product.max_amount_minor)} ·{' '}
                  {product.min_term}–{product.max_term} installments
                  {product.requires_guarantor && ' · guarantor required'}
                  {product.requires_collateral && ' · collateral required'}
                </div>
              )}
              <TextField label="Amount (GHS)" type="number" min="1" step="0.01" value={values.amount} onChange={(v) => set('amount', v)} error={errors.amount} required />
              <TextField
                label={`Term (${product ? label(product.repayment_frequency).toLowerCase() + ' installments' : 'installments'})`}
                type="number"
                min="1"
                value={values.term}
                onChange={(v) => set('term', v)}
                error={errors.term}
                required
              />
              <Field label="Purpose" required error={errors.purpose} className="sm:col-span-2">
                {(id) => <Textarea id={id} value={values.purpose} onChange={(e) => set('purpose', e.target.value)} invalid={!!errors.purpose} placeholder="What will the loan be used for?" />}
              </Field>
            </div>
          </Card>

          <Card>
            <CardHeader title="Affordability" description="Used for the debt-service ratio check." />
            <div className="grid gap-4 sm:grid-cols-2">
              <TextField label={customer?.type === 'business' ? 'Monthly revenue (GHS)' : 'Monthly income (GHS)'} type="number" min="0" value={values.monthly_income} onChange={(v) => set('monthly_income', v)} error={errors.monthly_income} />
              <TextField label="Existing monthly obligations (GHS)" type="number" min="0" value={values.monthly_obligations} onChange={(v) => set('monthly_obligations', v)} error={errors.monthly_obligations} hint="Other loan repayments, rent, etc." />
            </div>
          </Card>

          <Card>
            <div className="flex items-center justify-between">
              <SectionTitle>Guarantors {product?.requires_guarantor && <span className="text-red-500">*</span>}</SectionTitle>
              <Button size="sm" variant="soft" icon={<Plus className="size-3.5" />} onClick={() => setGuarantors([...guarantors, { name: '', phone: '', relationship: '', id_number: '' }])}>
                Add guarantor
              </Button>
            </div>
            {guarantors.length === 0 && <p className="text-sm text-slate-500">No guarantors added.</p>}
            <div className="space-y-3">
              {guarantors.map((g, i) => (
                <div key={i} className="grid gap-3 rounded-xl border border-slate-200 p-3 sm:grid-cols-[1fr_1fr_1fr_1fr_auto]">
                  {(['name', 'phone', 'relationship', 'id_number'] as const).map((k) => (
                    <TextField key={k} label={label(k)} value={g[k]} onChange={(v) => setGuarantors(guarantors.map((x, j) => (j === i ? { ...x, [k]: v } : x)))} error={errors[`guarantors.${i}.${k}`]} />
                  ))}
                  <button className="self-end rounded-lg p-2 text-slate-400 hover:bg-red-50 hover:text-red-600" aria-label="Remove guarantor" onClick={() => setGuarantors(guarantors.filter((_, j) => j !== i))}>
                    <Trash2 className="size-4" />
                  </button>
                </div>
              ))}
            </div>
            <div className="mb-3 mt-6 flex items-center justify-between">
              <SectionTitle>Collateral {product?.requires_collateral && <span className="text-red-500">*</span>}</SectionTitle>
              <Button size="sm" variant="soft" icon={<Plus className="size-3.5" />} onClick={() => setCollateral([...collateral, { description: '', estimated_value: '' }])}>
                Add item
              </Button>
            </div>
            {collateral.length === 0 && <p className="text-sm text-slate-500">No collateral recorded.</p>}
            <div className="space-y-3">
              {collateral.map((c, i) => (
                <div key={i} className="grid gap-3 rounded-xl border border-slate-200 p-3 sm:grid-cols-[2fr_1fr_auto]">
                  <TextField label="Description" value={c.description} onChange={(v) => setCollateral(collateral.map((x, j) => (j === i ? { ...x, description: v } : x)))} error={errors[`collateral.${i}.description`]} />
                  <TextField label="Estimated value (GHS)" type="number" value={c.estimated_value} onChange={(v) => setCollateral(collateral.map((x, j) => (j === i ? { ...x, estimated_value: v } : x)))} />
                  <button className="self-end rounded-lg p-2 text-slate-400 hover:bg-red-50 hover:text-red-600" aria-label="Remove item" onClick={() => setCollateral(collateral.filter((_, j) => j !== i))}>
                    <Trash2 className="size-4" />
                  </button>
                </div>
              ))}
            </div>
          </Card>

          {Object.keys(errors).length > 0 && <Alert tone="red">Please correct the highlighted fields.</Alert>}
          <div className="flex justify-end gap-2">
            <Button variant="secondary" onClick={() => navigate('/loans/applications')}>
              Cancel
            </Button>
            <Button loading={save.isPending} disabled={!customer} onClick={() => save.mutate()}>
              Save draft & check eligibility
            </Button>
          </div>
        </div>
        <div className="lg:col-span-2">
          <div className="lg:sticky lg:top-24">
            <SchedulePreview productId={values.loan_product_id} amount={values.amount} term={values.term} />
          </div>
        </div>
      </div>
    </>
  );
}
