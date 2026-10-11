import { useMemo, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { ArrowLeft, Plus, Trash2 } from 'lucide-react';
import { api } from '../../lib/api';
import { money, toMajor } from '../../lib/format';
import { useAction, useLookups } from '../../lib/hooks';
import type { Customer, Invoice } from '../../lib/types';
import { CustomerPicker } from '../../components/CustomerPicker';
import { Button, Card, CardHeader, Field, Input, PageHeader, Select, TextField, Textarea } from '../../components/ui';
import { ApiError } from '../../lib/api';

interface Line {
  service_id: string;
  description: string;
  quantity: string;
  unit_price: string;
  discount: string;
  tax_rate: string;
}

const blankLine = (): Line => ({ service_id: '', description: '', quantity: '1', unit_price: '', discount: '', tax_rate: '0' });

export default function NewInvoicePage() {
  const navigate = useNavigate();
  const { data: lookups } = useLookups();
  const [customer, setCustomer] = useState<Customer | null>(null);
  const [lines, setLines] = useState<Line[]>([blankLine()]);
  const [due, setDue] = useState('');
  const [notes, setNotes] = useState('');
  const [errors, setErrors] = useState<Record<string, string>>({});

  const setLine = (i: number, patch: Partial<Line>) => setLines(lines.map((l, j) => (j === i ? { ...l, ...patch } : l)));
  const pickService = (i: number, id: string) => {
    const s = lookups?.services.find((x) => String(x.id) === id);
    setLine(i, s ? { service_id: id, description: s.name, unit_price: toMajor(s.price_minor), tax_rate: String(parseFloat(s.tax_rate)) } : { service_id: '' });
  };

  // Display-only preview; the server recalculates every figure.
  const totals = useMemo(() => {
    let subtotal = 0;
    let discount = 0;
    let tax = 0;
    for (const l of lines) {
      const gross = Math.round((Number(l.unit_price) || 0) * 100 * (Number(l.quantity) || 0));
      const d = Math.min(Math.round((Number(l.discount) || 0) * 100), gross);
      subtotal += gross;
      discount += d;
      tax += Math.round(((gross - d) * (Number(l.tax_rate) || 0)) / 100);
    }
    return { subtotal, discount, tax, total: subtotal - discount + tax };
  }, [lines]);

  const save = useAction({
    fn: () => api.post<Invoice>('/api/invoices', { customer_id: customer?.id, due_date: due || null, notes: notes || null, items: lines.map((l) => ({ ...l, service_id: l.service_id || null, discount: l.discount || 0 })) }),
    success: (inv) => `Invoice ${inv.invoice_number} issued`,
    invalidate: [['/api/invoices'], ['dashboard']],
    onSuccess: (inv) => navigate(`/billing/invoices/${inv.id}`),
    onError: (e) => e instanceof ApiError && setErrors(Object.fromEntries(Object.entries(e.fieldErrors).map(([k, v]) => [k, v[0]]))),
  });

  return (
    <>
      <PageHeader
        back={
          <Link to="/billing/invoices" className="inline-flex items-center gap-1 text-sm text-slate-500 hover:text-slate-800">
            <ArrowLeft className="size-4" /> Invoices
          </Link>
        }
        title="New invoice"
        description="For services not raised through a service request."
      />
      <div className="grid gap-6 lg:grid-cols-3">
        <div className="space-y-6 lg:col-span-2">
          <Card>
            <div className="grid gap-4 sm:grid-cols-2">
              <div className="sm:col-span-2">
                <CustomerPicker value={customer} onChange={setCustomer} error={errors.customer_id} />
              </div>
              <TextField label="Due date" type="date" value={due} onChange={setDue} error={errors.due_date} hint="Defaults to company payment terms" />
            </div>
          </Card>
          <Card>
            <CardHeader
              title="Lines"
              actions={
                <Button size="sm" variant="soft" icon={<Plus className="size-3.5" />} onClick={() => setLines([...lines, blankLine()])}>
                  Add line
                </Button>
              }
            />
            <div className="space-y-4">
              {lines.map((l, i) => (
                <div key={i} className="rounded-xl border border-slate-200 p-3">
                  <div className="grid gap-3 sm:grid-cols-6">
                    <Field label="Service" className="sm:col-span-2">
                      {(id) => (
                        <Select id={id} value={l.service_id} onChange={(e) => pickService(i, e.target.value)}>
                          <option value="">Custom line</option>
                          {(lookups?.services ?? []).map((s) => (
                            <option key={s.id} value={s.id}>
                              {s.name}
                            </option>
                          ))}
                        </Select>
                      )}
                    </Field>
                    <TextField label="Description" value={l.description} onChange={(v) => setLine(i, { description: v })} error={errors[`items.${i}.description`]} className="sm:col-span-4" />
                    <TextField label="Qty" type="number" value={l.quantity} onChange={(v) => setLine(i, { quantity: v })} error={errors[`items.${i}.quantity`]} />
                    <TextField label="Unit price" type="number" value={l.unit_price} onChange={(v) => setLine(i, { unit_price: v })} error={errors[`items.${i}.unit_price`]} />
                    <TextField label="Discount" type="number" value={l.discount} onChange={(v) => setLine(i, { discount: v })} />
                    <TextField label="Tax %" type="number" value={l.tax_rate} onChange={(v) => setLine(i, { tax_rate: v })} />
                    <Field label="Line total" className="sm:col-span-2">
                      {() => (
                        <div className="flex items-center gap-2">
                          <Input readOnly value={money(Math.round((Number(l.unit_price) || 0) * 100 * (Number(l.quantity) || 0)) - Math.round((Number(l.discount) || 0) * 100))} className="tabular bg-slate-50" aria-label="Line total" />
                          {lines.length > 1 && (
                            <button className="rounded-lg p-2 text-slate-400 hover:bg-red-50 hover:text-red-600" aria-label="Remove line" onClick={() => setLines(lines.filter((_, j) => j !== i))}>
                              <Trash2 className="size-4" />
                            </button>
                          )}
                        </div>
                      )}
                    </Field>
                  </div>
                </div>
              ))}
            </div>
            <Field label="Notes" className="mt-4">
              {(id) => <Textarea id={id} value={notes} onChange={(e) => setNotes(e.target.value)} />}
            </Field>
          </Card>
        </div>
        <div>
          <Card className="lg:sticky lg:top-24">
            <CardHeader title="Summary" />
            <dl className="space-y-2 text-sm">
              <div className="flex justify-between">
                <dt className="text-slate-500">Subtotal</dt>
                <dd className="tabular">{money(totals.subtotal)}</dd>
              </div>
              <div className="flex justify-between">
                <dt className="text-slate-500">Discount</dt>
                <dd className="tabular">-{money(totals.discount)}</dd>
              </div>
              <div className="flex justify-between">
                <dt className="text-slate-500">Tax</dt>
                <dd className="tabular">{money(totals.tax)}</dd>
              </div>
              <div className="flex justify-between border-t border-slate-200 pt-2 text-base font-semibold">
                <dt>Total</dt>
                <dd className="tabular">{money(totals.total)}</dd>
              </div>
            </dl>
            <Button className="mt-5 w-full" disabled={!customer || totals.total <= 0} loading={save.isPending} onClick={() => save.mutate()}>
              Issue invoice
            </Button>
          </Card>
        </div>
      </div>
    </>
  );
}
