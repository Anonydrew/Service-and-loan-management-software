import { useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { ArrowLeft, Ban, FileDown, Receipt, Undo2, Wallet } from 'lucide-react';
import { api, download, errorMessage } from '../../lib/api';
import { useAuth } from '../../lib/auth';
import { date, label, money, today } from '../../lib/format';
import { useAction, useForm, useLookups } from '../../lib/hooks';
import type { Invoice, Payment } from '../../lib/types';
import { ConfirmDialog, Modal } from '../../components/Modal';
import { Alert, Button, Card, CardHeader, ErrorState, LoadingBlock, PageHeader, SelectField, StatusBadge, TextField } from '../../components/ui';
import { useToast } from '../../components/Toast';

export default function InvoiceDetailPage() {
  const { id = '' } = useParams();
  const { can } = useAuth();
  const toast = useToast();
  const [paying, setPaying] = useState(false);
  const [voiding, setVoiding] = useState(false);
  const [refunding, setRefunding] = useState<Payment | null>(null);
  const key = ['invoice', id];
  const { data: inv, isLoading, error, refetch } = useQuery({ queryKey: key, queryFn: () => api.get<Invoice>(`/api/invoices/${id}`) });
  const invalidate = [key, ['/api/invoices'], ['/api/payments'], ['dashboard']];
  const voidInv = useAction({ fn: (reason: string) => api.post(`/api/invoices/${id}/void`, { reason }), success: 'Invoice voided', invalidate, onSuccess: () => setVoiding(false) });

  if (isLoading) return <LoadingBlock rows={8} />;
  if (error || !inv) return <ErrorState message={errorMessage(error)} onRetry={() => refetch()} />;

  const dl = (path: string, name: string) => download(path, name).catch((e) => toast.error(errorMessage(e)));
  const payable = ['issued', 'partially_paid'].includes(inv.status);

  return (
    <>
      <PageHeader
        back={
          <Link to="/billing/invoices" className="inline-flex items-center gap-1 text-sm text-slate-500 hover:text-slate-800">
            <ArrowLeft className="size-4" /> Invoices
          </Link>
        }
        title={
          <span className="flex flex-wrap items-center gap-3">
            {inv.invoice_number} <StatusBadge status={inv.status} />
          </span>
        }
        description={
          <span>
            <Link to={`/customers/${inv.customer_id}`} className="font-medium text-brand-700 hover:underline">
              {inv.customer?.display_name}
            </Link>{' '}
            · issued {date(inv.issue_date)} · due {date(inv.due_date)}
          </span>
        }
        actions={
          <>
            {inv.status === 'issued' && inv.amount_paid_minor === 0 && can('invoices.void') && (
              <Button variant="ghost" icon={<Ban className="size-4" />} onClick={() => setVoiding(true)}>
                Void
              </Button>
            )}
            <Button variant="secondary" icon={<FileDown className="size-4" />} onClick={() => dl(`/api/invoices/${inv.id}/pdf`, `invoice-${inv.invoice_number}.pdf`)}>
              PDF
            </Button>
            {payable && can('payments.create') && (
              <Button icon={<Wallet className="size-4" />} onClick={() => setPaying(true)}>
                Record payment
              </Button>
            )}
          </>
        }
      />

      {inv.status === 'void' && (
        <div className="mb-6">
          <Alert tone="neutral" title="Voided">
            {inv.void_reason}
          </Alert>
        </div>
      )}

      <div className="grid gap-6 lg:grid-cols-3">
        <Card padded={false} className="lg:col-span-2">
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b border-slate-200 bg-slate-50/70 text-xs font-semibold uppercase tracking-wide text-slate-500">
                  <th className="px-4 py-2.5 text-left">Description</th>
                  <th className="px-4 py-2.5 text-right">Qty</th>
                  <th className="px-4 py-2.5 text-right">Unit</th>
                  <th className="hidden px-4 py-2.5 text-right sm:table-cell">Discount</th>
                  <th className="hidden px-4 py-2.5 text-right sm:table-cell">Tax</th>
                  <th className="px-4 py-2.5 text-right">Amount</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {(inv.items ?? []).map((it) => (
                  <tr key={it.id}>
                    <td className="px-4 py-3">{it.description}</td>
                    <td className="tabular px-4 py-3 text-right">{parseFloat(it.quantity)}</td>
                    <td className="tabular px-4 py-3 text-right">{money(it.unit_price_minor)}</td>
                    <td className="tabular hidden px-4 py-3 text-right sm:table-cell">{money(it.discount_minor)}</td>
                    <td className="tabular hidden px-4 py-3 text-right sm:table-cell">
                      {money(it.tax_minor)} <span className="text-xs text-slate-400">({parseFloat(it.tax_rate)}%)</span>
                    </td>
                    <td className="tabular px-4 py-3 text-right font-medium">{money(it.line_total_minor)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          <dl className="ml-auto max-w-xs space-y-1.5 p-5 text-sm">
            {[
              ['Subtotal', money(inv.subtotal_minor)],
              ['Discount', `-${money(inv.discount_minor)}`],
              ['Tax', money(inv.tax_minor)],
            ].map(([k, v]) => (
              <div key={k} className="flex justify-between">
                <dt className="text-slate-500">{k}</dt>
                <dd className="tabular">{v}</dd>
              </div>
            ))}
            <div className="flex justify-between border-t border-slate-200 pt-1.5 font-semibold">
              <dt>Total</dt>
              <dd className="tabular">{money(inv.total_minor)}</dd>
            </div>
            <div className="flex justify-between">
              <dt className="text-slate-500">Paid</dt>
              <dd className="tabular">{money(inv.amount_paid_minor)}</dd>
            </div>
            {inv.amount_refunded_minor > 0 && (
              <div className="flex justify-between">
                <dt className="text-slate-500">Refunded</dt>
                <dd className="tabular">-{money(inv.amount_refunded_minor)}</dd>
              </div>
            )}
            <div className="flex justify-between text-base font-semibold text-brand-800">
              <dt>Balance due</dt>
              <dd className="tabular">{money(inv.balance_minor)}</dd>
            </div>
          </dl>
        </Card>
        <div className="space-y-6">
          <Card>
            <CardHeader title="Payments" />
            {(inv.payments ?? []).length === 0 ? (
              <p className="text-sm text-slate-500">No payments recorded.</p>
            ) : (
              <ul className="space-y-3">
                {inv.payments!.map((p) => (
                  <li key={p.id} className="rounded-xl border border-slate-200 p-3">
                    <div className="flex items-start justify-between gap-2">
                      <div>
                        <p className="tabular text-sm font-semibold text-slate-900">{money(p.amount_minor)}</p>
                        <p className="text-xs text-slate-500">
                          {p.receipt_number} · {date(p.paid_on)} · {label(p.payment_method)}
                        </p>
                        {p.refunded_minor > 0 && <p className="text-xs text-amber-700">{money(p.refunded_minor)} refunded</p>}
                        {(p.refunds ?? []).some((r) => r.status === 'pending') && <p className="text-xs text-amber-700">Refund awaiting approval</p>}
                      </div>
                      <StatusBadge status={p.status} />
                    </div>
                    <div className="mt-2 flex gap-2">
                      <Button size="sm" variant="secondary" icon={<Receipt className="size-3.5" />} onClick={() => dl(`/api/payments/${p.id}/receipt`, `receipt-${p.receipt_number}.pdf`)}>
                        Receipt
                      </Button>
                      {can('payments.refund') && p.refunded_minor < p.amount_minor && (
                        <Button size="sm" variant="ghost" icon={<Undo2 className="size-3.5" />} onClick={() => setRefunding(p)}>
                          Refund
                        </Button>
                      )}
                    </div>
                  </li>
                ))}
              </ul>
            )}
          </Card>
          {inv.service_request && (
            <Card>
              <CardHeader title="Source" />
              <Link to={`/services/requests/${inv.service_request.id}`} className="text-sm font-medium text-brand-700 hover:underline">
                Service request {inv.service_request.request_number}
              </Link>
            </Card>
          )}
        </div>
      </div>

      {paying && <PaymentModal invoice={inv} onClose={() => setPaying(false)} invalidate={invalidate} />}
      {refunding && <RefundModal payment={refunding} onClose={() => setRefunding(null)} invalidate={invalidate} />}
      <ConfirmDialog open={voiding} onClose={() => setVoiding(false)} title="Void invoice" message="The invoice and its ledger entry are reversed. This cannot be undone." reasonLabel="Reason" danger confirmLabel="Void invoice" loading={voidInv.isPending} onConfirm={(r) => voidInv.mutate(r)} />
    </>
  );
}

function PaymentModal({ invoice, onClose, invalidate }: { invoice: Invoice; onClose: () => void; invalidate: unknown[][] }) {
  const { data: lookups } = useLookups();
  const toast = useToast();
  const form = useForm({ amount: (invoice.balance_minor / 100).toFixed(2), method: 'cash', reference: '', paid_on: today(), notes: '' });
  const save = useAction({
    fn: () => api.post<Payment>(`/api/invoices/${invoice.id}/payments`, form.values),
    success: (p) => `Receipt ${p.receipt_number} issued`,
    invalidate,
    onSuccess: (p) => {
      onClose();
      download(`/api/payments/${p.id}/receipt`, `receipt-${p.receipt_number}.pdf`).catch((e) => toast.error(errorMessage(e)));
    },
    onError: form.absorb,
  });
  return (
    <Modal
      open
      onClose={onClose}
      title="Record payment"
      description={`${invoice.invoice_number} · balance ${money(invoice.balance_minor)}`}
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>
            Cancel
          </Button>
          <Button loading={save.isPending} onClick={() => save.mutate()}>
            Record & print receipt
          </Button>
        </>
      }
    >
      <div className="grid gap-4 sm:grid-cols-2">
        <TextField label="Amount (GHS)" type="number" step="0.01" value={form.values.amount} onChange={(v) => form.set('amount', v)} error={form.errors.amount} required />
        <TextField label="Date received" type="date" max={today()} value={form.values.paid_on} onChange={(v) => form.set('paid_on', v)} error={form.errors.paid_on} />
        <SelectField label="Method" value={form.values.method} onChange={(v) => form.set('method', v)} options={lookups?.enums.payment_methods ?? []} />
        <TextField label="Reference" value={form.values.reference} onChange={(v) => form.set('reference', v)} error={form.errors.reference} required={form.values.method !== 'cash'} />
      </div>
    </Modal>
  );
}

function RefundModal({ payment, onClose, invalidate }: { payment: Payment; onClose: () => void; invalidate: unknown[][] }) {
  const form = useForm({ amount: ((payment.amount_minor - payment.refunded_minor) / 100).toFixed(2), reason: '' });
  const save = useAction({
    fn: () => api.post(`/api/payments/${payment.id}/refunds`, form.values),
    success: 'Refund requested — awaiting approval by a second person',
    invalidate: [...invalidate, ['/api/refunds']],
    onSuccess: onClose,
    onError: form.absorb,
  });
  return (
    <Modal
      open
      onClose={onClose}
      title="Request refund"
      description="Refunds are paid only after another authorised user approves them."
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>
            Cancel
          </Button>
          <Button loading={save.isPending} onClick={() => save.mutate()}>
            Request refund
          </Button>
        </>
      }
    >
      <div className="grid gap-4">
        <TextField label="Amount (GHS)" type="number" step="0.01" value={form.values.amount} onChange={(v) => form.set('amount', v)} error={form.errors.amount} />
        <TextField label="Reason" value={form.values.reason} onChange={(v) => form.set('reason', v)} error={form.errors.reason} required />
      </div>
    </Modal>
  );
}
