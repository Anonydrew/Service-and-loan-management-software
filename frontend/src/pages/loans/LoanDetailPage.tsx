import { useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { ArrowLeft, Banknote, FileDown, MessageSquarePlus, Receipt, Send, Undo2, Ban } from 'lucide-react';
import { api, download, errorMessage } from '../../lib/api';
import { useAuth } from '../../lib/auth';
import { date, dateTime, label, money, today } from '../../lib/format';
import { useAction, useForm, useLookups } from '../../lib/hooks';
import type { Installment, Loan, Repayment } from '../../lib/types';
import { ConfirmDialog, Modal } from '../../components/Modal';
import { Alert, Badge, Button, Card, CardHeader, Checkbox, DescriptionList, EmptyState, ErrorState, Field, LoadingBlock, PageHeader, SelectField, Stat, StatusBadge, Tabs, TextField, Textarea } from '../../components/ui';
import { useToast } from '../../components/Toast';

interface LoanResponse {
  loan: Loan;
  repayments: Repayment[];
  balances: { outstanding_minor: number; outstanding_principal_minor: number; overdue_minor: number; next_installment: Installment | null };
}

type Tab = 'schedule' | 'repayments' | 'collections' | 'terms';

export default function LoanDetailPage() {
  const { id = '' } = useParams();
  const { can } = useAuth();
  const toast = useToast();
  const [tab, setTab] = useState<Tab>('schedule');
  const [modal, setModal] = useState<'disburse' | 'repay' | 'writeoff' | 'collection' | null>(null);
  const [reversing, setReversing] = useState<Repayment | null>(null);
  const key = ['loan', id];
  const { data, isLoading, error, refetch } = useQuery({ queryKey: key, queryFn: () => api.get<LoanResponse>(`/api/loans/${id}`) });

  const invalidate = [key, ['/api/loans'], ['/api/repayments'], ['dashboard']];
  const reverse = useAction({
    fn: ({ r, reason }: { r: Repayment; reason: string }) => api.post(`/api/repayments/${r.id}/reverse`, { reason }),
    success: 'Repayment reversed',
    invalidate,
    onSuccess: () => setReversing(null),
  });
  const writeOff = useAction({
    fn: (reason: string) => api.post(`/api/loans/${id}/write-off`, { reason }),
    success: 'Loan written off',
    invalidate,
    onSuccess: () => setModal(null),
  });

  if (isLoading) return <LoadingBlock rows={8} />;
  if (error || !data) return <ErrorState message={errorMessage(error)} onRetry={() => refetch()} />;

  const { loan, repayments, balances } = data;
  const open = ['active', 'in_arrears'].includes(loan.status);
  const paid = loan.principal_paid_minor + loan.interest_paid_minor + loan.fees_paid_minor + loan.penalty_paid_minor;
  const totalDue = loan.total_payable_minor + loan.penalty_charged_minor;
  const progress = totalDue > 0 ? Math.min(100, Math.round((paid / totalDue) * 100)) : 0;

  const dl = (path: string, name: string) => download(path, name).catch((e) => toast.error(errorMessage(e)));

  return (
    <>
      <PageHeader
        back={
          <Link to="/loans" className="inline-flex items-center gap-1 text-sm text-slate-500 hover:text-slate-800">
            <ArrowLeft className="size-4" /> Loans
          </Link>
        }
        title={
          <span className="flex flex-wrap items-center gap-3">
            {loan.loan_number} <StatusBadge status={loan.status} />
          </span>
        }
        description={
          <span>
            <Link to={`/customers/${loan.customer_id}`} className="font-medium text-brand-700 hover:underline">
              {loan.customer?.display_name}
            </Link>{' '}
            · {loan.product?.name} · {loan.branch?.name}
          </span>
        }
        actions={
          <>
            <Button variant="secondary" icon={<FileDown className="size-4" />} onClick={() => dl(`/api/loans/${loan.id}/agreement`, `loan-agreement-${loan.loan_number}.pdf`)}>
              Agreement
            </Button>
            {loan.status === 'pending_disbursement' && can('loans.disburse') && (
              <Button icon={<Send className="size-4" />} onClick={() => setModal('disburse')}>
                Disburse
              </Button>
            )}
            {open && can('repayments.create') && (
              <Button icon={<Banknote className="size-4" />} onClick={() => setModal('repay')}>
                Record repayment
              </Button>
            )}
          </>
        }
      />

      {loan.status === 'pending_disbursement' && (
        <div className="mb-6">
          <Alert tone="violet" title="Approved — awaiting disbursement">
            Print the agreement for the customer to sign. The repayment schedule is generated from the disbursement date.
          </Alert>
        </div>
      )}
      {loan.status === 'in_arrears' && (
        <div className="mb-6">
          <Alert tone="red" title={`${loan.days_in_arrears} days in arrears`}>
            {money(balances.overdue_minor)} is overdue. Log contact attempts on the Collections tab.
          </Alert>
        </div>
      )}

      <div className="mb-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <Stat label="Outstanding balance" value={money(balances.outstanding_minor)} hint={`Principal ${money(balances.outstanding_principal_minor)}`} tone={loan.status === 'in_arrears' ? 'red' : 'green'} />
        <Stat label="Overdue" value={money(balances.overdue_minor)} tone={balances.overdue_minor > 0 ? 'red' : 'neutral'} />
        <Stat
          label="Next installment"
          value={balances.next_installment ? money(balances.next_installment.balance_minor) : '—'}
          hint={balances.next_installment ? `Due ${date(balances.next_installment.due_date)}` : undefined}
          tone="blue"
        />
        <Card>
          <p className="text-sm text-slate-500">Repaid</p>
          <p className="tabular mt-2 text-2xl font-semibold text-slate-900">{progress}%</p>
          <div className="mt-2 h-2 overflow-hidden rounded-full bg-slate-100">
            <div className="h-full rounded-full bg-brand-600" style={{ width: `${progress}%` }} />
          </div>
          <p className="mt-1 text-xs text-slate-500">
            {money(paid)} of {money(totalDue)}
          </p>
        </Card>
      </div>

      <Tabs<Tab>
        value={tab}
        onChange={setTab}
        tabs={[
          { value: 'schedule', label: 'Schedule', count: loan.installments?.length },
          { value: 'repayments', label: 'Repayments', count: repayments.length },
          ...(can('collections.view') ? [{ value: 'collections' as Tab, label: 'Collections', count: loan.collection_activities?.length }] : []),
          { value: 'terms', label: 'Terms' },
        ]}
      />

      {tab === 'schedule' && (
        <Card padded={false}>
          {(loan.installments ?? []).length === 0 ? (
            <EmptyState title="No schedule yet" description="The schedule is created when the loan is disbursed." />
          ) : (
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead>
                  <tr className="border-b border-slate-200 bg-slate-50/70 text-xs font-semibold uppercase tracking-wide text-slate-500">
                    <th className="px-4 py-2.5 text-left">#</th>
                    <th className="px-4 py-2.5 text-left">Due</th>
                    <th className="px-4 py-2.5 text-right">Principal</th>
                    <th className="px-4 py-2.5 text-right">Interest</th>
                    <th className="hidden px-4 py-2.5 text-right md:table-cell">Fees</th>
                    <th className="hidden px-4 py-2.5 text-right md:table-cell">Penalty</th>
                    <th className="px-4 py-2.5 text-right">Due</th>
                    <th className="px-4 py-2.5 text-right">Paid</th>
                    <th className="px-4 py-2.5 text-left">Status</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-100">
                  {loan.installments!.map((i) => {
                    const overdue = i.status !== 'paid' && new Date(i.due_date) < new Date(today());
                    return (
                      <tr key={i.id} className={overdue ? 'bg-red-50/40' : undefined}>
                        <td className="px-4 py-2.5 text-slate-500">{i.installment_number}</td>
                        <td className="whitespace-nowrap px-4 py-2.5">{date(i.due_date)}</td>
                        <td className="tabular px-4 py-2.5 text-right">{money(i.principal_due_minor)}</td>
                        <td className="tabular px-4 py-2.5 text-right">{money(i.interest_due_minor)}</td>
                        <td className="tabular hidden px-4 py-2.5 text-right md:table-cell">{money(i.fees_due_minor)}</td>
                        <td className="tabular hidden px-4 py-2.5 text-right md:table-cell">{i.penalty_due_minor ? <span className="text-red-600">{money(i.penalty_due_minor)}</span> : '—'}</td>
                        <td className="tabular px-4 py-2.5 text-right font-medium">{money(i.total_due_minor)}</td>
                        <td className="tabular px-4 py-2.5 text-right">{money(i.total_paid_minor)}</td>
                        <td className="px-4 py-2.5">{overdue ? <StatusBadge status="overdue" /> : <StatusBadge status={i.status} />}</td>
                      </tr>
                    );
                  })}
                </tbody>
              </table>
            </div>
          )}
        </Card>
      )}

      {tab === 'repayments' && (
        <Card padded={false}>
          {repayments.length === 0 ? (
            <EmptyState title="No repayments yet" />
          ) : (
            <ul className="divide-y divide-slate-100">
              {repayments.map((r) => (
                <li key={r.id} className="flex flex-col gap-3 px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
                  <div>
                    <p className="flex flex-wrap items-center gap-2 text-sm font-medium text-slate-900">
                      {r.receipt_number} <span className="tabular">{money(r.amount_minor)}</span>
                      <StatusBadge status={r.status} />
                    </p>
                    <p className="mt-0.5 text-xs text-slate-500">
                      {date(r.paid_on)} · {label(r.payment_method)}
                      {r.reference ? ` · ${r.reference}` : ''} · received by {r.received_by?.name}
                    </p>
                    <p className="mt-0.5 text-xs text-slate-500">
                      Principal {money(r.principal_minor)} · Interest {money(r.interest_minor)} · Fees {money(r.fees_minor)} · Penalty {money(r.penalty_minor)}
                    </p>
                    {r.status === 'reversed' && <p className="mt-0.5 text-xs text-red-600">Reversed: {r.reversal_reason}</p>}
                  </div>
                  <div className="flex gap-2">
                    <Button size="sm" variant="secondary" icon={<Receipt className="size-3.5" />} onClick={() => dl(`/api/repayments/${r.id}/receipt`, `receipt-${r.receipt_number}.pdf`)}>
                      Receipt
                    </Button>
                    {r.status === 'posted' && can('repayments.reverse') && (
                      <Button size="sm" variant="ghost" icon={<Undo2 className="size-3.5" />} onClick={() => setReversing(r)}>
                        Reverse
                      </Button>
                    )}
                  </div>
                </li>
              ))}
            </ul>
          )}
        </Card>
      )}

      {tab === 'collections' && (
        <Card>
          <CardHeader
            title="Collection activity"
            actions={
              can('collections.manage') && (
                <Button size="sm" variant="soft" icon={<MessageSquarePlus className="size-3.5" />} onClick={() => setModal('collection')}>
                  Log activity
                </Button>
              )
            }
          />
          {(loan.collection_activities ?? []).length === 0 ? (
            <EmptyState title="No collection activity" description="Calls, visits and promises to pay will be listed here." />
          ) : (
            <ul className="space-y-3">
              {loan.collection_activities!.map((a) => (
                <li key={a.id} className="rounded-xl border border-slate-200 p-3">
                  <p className="flex flex-wrap items-center gap-2 text-sm font-medium text-slate-900">
                    <Badge tone="blue">{label(a.activity_type)}</Badge> {a.outcome && <span>{label(a.outcome)}</span>}
                  </p>
                  {a.notes && <p className="mt-1 text-sm text-slate-600">{a.notes}</p>}
                  <p className="mt-1 text-xs text-slate-500">
                    {dateTime(a.created_at)} · {a.user?.name}
                    {a.promise_date && ` · Promised ${money(a.promise_amount_minor)} by ${date(a.promise_date)}`}
                    {a.next_action_date && ` · Next action ${date(a.next_action_date)}`}
                  </p>
                </li>
              ))}
            </ul>
          )}
        </Card>
      )}

      {tab === 'terms' && (
        <Card>
          <DescriptionList
            columns={3}
            items={[
              { label: 'Principal', value: money(loan.principal_minor) },
              { label: 'Interest', value: `${parseFloat(loan.interest_rate)}% ${label(loan.rate_period).toLowerCase()} (${label(loan.interest_method).toLowerCase()})` },
              { label: 'Repayment', value: `${loan.term} ${loan.repayment_frequency} installments` },
              { label: 'Processing fee', value: `${money(loan.fee_minor)} (${loan.fee_collection})` },
              { label: 'Net disbursed', value: money(loan.net_disbursed_minor) },
              { label: 'Total interest', value: money(loan.total_interest_minor) },
              { label: 'Total payable', value: money(loan.total_payable_minor) },
              { label: 'Penalties charged', value: money(loan.penalty_charged_minor) },
              { label: 'Disbursed', value: loan.disbursement_date ? `${date(loan.disbursement_date)} via ${label(loan.disbursement_method)}` : null },
              { label: 'First due', value: date(loan.first_due_date) },
              { label: 'Maturity', value: date(loan.maturity_date) },
              { label: 'Loan officer', value: loan.loan_officer?.name },
              { label: 'Application', value: loan.application ? <Link className="text-brand-700 hover:underline" to={`/loans/applications/${loan.application.id}`}>{loan.application.application_number}</Link> : null },
              { label: 'Purpose', value: loan.application?.purpose },
              { label: 'Write-off reason', value: loan.write_off_reason, hidden: !loan.write_off_reason },
            ]}
          />
          {open && can('loans.write_off') && (
            <div className="mt-6 border-t border-slate-100 pt-4">
              <Button variant="ghost" className="text-red-600 hover:bg-red-50 hover:text-red-700" icon={<Ban className="size-4" />} onClick={() => setModal('writeoff')}>
                Write off loan
              </Button>
            </div>
          )}
        </Card>
      )}

      {modal === 'disburse' && <DisburseModal loan={loan} onClose={() => setModal(null)} invalidate={invalidate} />}
      {modal === 'repay' && <RepaymentModal loan={loan} outstanding={balances.outstanding_minor} next={balances.next_installment} onClose={() => setModal(null)} invalidate={invalidate} />}
      {modal === 'collection' && <CollectionModal loanId={loan.id} onClose={() => setModal(null)} invalidate={[key]} />}
      <ConfirmDialog
        open={modal === 'writeoff'}
        onClose={() => setModal(null)}
        title="Write off this loan"
        message={`${money(balances.outstanding_principal_minor)} of principal will be moved to the write-off expense account. This cannot be undone from here.`}
        reasonLabel="Reason"
        confirmLabel="Write off"
        danger
        loading={writeOff.isPending}
        onConfirm={(reason) => writeOff.mutate(reason)}
      />
      <ConfirmDialog
        open={!!reversing}
        onClose={() => setReversing(null)}
        title={`Reverse ${reversing?.receipt_number}`}
        message="The allocation is undone, the ledger entry is reversed and the loan balance restored."
        reasonLabel="Reason for reversal"
        confirmLabel="Reverse repayment"
        danger
        loading={reverse.isPending}
        onConfirm={(reason) => reversing && reverse.mutate({ r: reversing, reason })}
      />
    </>
  );
}

function DisburseModal({ loan, onClose, invalidate }: { loan: Loan; onClose: () => void; invalidate: unknown[][] }) {
  const { data: lookups } = useLookups();
  const form = useForm({ disbursement_date: today(), method: 'mobile_money', reference: '', agreement_signed: false });
  const save = useAction({
    fn: () => api.post(`/api/loans/${loan.id}/disburse`, form.values),
    success: `${loan.loan_number} disbursed`,
    invalidate,
    onSuccess: onClose,
    onError: form.absorb,
  });
  return (
    <Modal
      open
      onClose={onClose}
      title={`Disburse ${loan.loan_number}`}
      description={`Pay ${money(loan.net_disbursed_minor)} to ${loan.customer?.display_name}.`}
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>
            Cancel
          </Button>
          <Button loading={save.isPending} disabled={!form.values.agreement_signed} onClick={() => save.mutate()}>
            Disburse {money(loan.net_disbursed_minor)}
          </Button>
        </>
      }
    >
      <div className="space-y-4">
        <DescriptionList
          items={[
            { label: 'Principal', value: money(loan.principal_minor) },
            { label: loan.fee_collection === 'deducted' ? 'Fee deducted' : 'Fee (added to repayments)', value: money(loan.fee_minor) },
          ]}
        />
        <div className="grid gap-4 sm:grid-cols-2">
          <TextField label="Disbursement date" type="date" value={form.values.disbursement_date} onChange={(v) => form.set('disbursement_date', v)} error={form.errors.disbursement_date} max={today()} required />
          <SelectField label="Method" value={form.values.method} onChange={(v) => form.set('method', v)} options={lookups?.enums.payment_methods ?? []} error={form.errors.method} />
          <TextField label="Transaction reference" value={form.values.reference} onChange={(v) => form.set('reference', v)} error={form.errors.reference} className="sm:col-span-2" placeholder="MoMo / bank reference" />
        </div>
        <Checkbox
          checked={form.values.agreement_signed}
          onChange={(v) => form.set('agreement_signed', v)}
          label="The customer has signed the loan agreement"
          description="Required by company policy before funds are released."
        />
      </div>
    </Modal>
  );
}

function RepaymentModal({ loan, outstanding, next, onClose, invalidate }: { loan: Loan; outstanding: number; next: Installment | null; onClose: () => void; invalidate: unknown[][] }) {
  const { data: lookups } = useLookups();
  const toast = useToast();
  const form = useForm({ amount: next ? (next.balance_minor / 100).toFixed(2) : '', method: 'cash', reference: '', paid_on: today(), notes: '' });
  const save = useAction({
    fn: () => api.post<Repayment>(`/api/loans/${loan.id}/repayments`, form.values),
    success: (r) => `Receipt ${r.receipt_number} issued`,
    invalidate,
    onSuccess: (r) => {
      onClose();
      download(`/api/repayments/${r.id}/receipt`, `receipt-${r.receipt_number}.pdf`).catch((e) => toast.error(errorMessage(e)));
    },
    onError: form.absorb,
  });
  return (
    <Modal
      open
      onClose={onClose}
      title="Record repayment"
      description={`${loan.loan_number} · outstanding ${money(outstanding)}`}
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
        <TextField label="Amount (GHS)" type="number" min="0.01" step="0.01" value={form.values.amount} onChange={(v) => form.set('amount', v)} error={form.errors.amount} required hint="Applied to penalties, fees, interest, then principal — oldest installment first." />
        <TextField label="Date received" type="date" value={form.values.paid_on} max={today()} onChange={(v) => form.set('paid_on', v)} error={form.errors.paid_on} required />
        <SelectField label="Method" value={form.values.method} onChange={(v) => form.set('method', v)} options={lookups?.enums.payment_methods ?? []} />
        <TextField label="Reference" value={form.values.reference} onChange={(v) => form.set('reference', v)} error={form.errors.reference} required={form.values.method !== 'cash'} placeholder="MoMo / bank / cheque no." />
        <TextField label="Notes" value={form.values.notes} onChange={(v) => form.set('notes', v)} className="sm:col-span-2" />
        <div className="flex flex-wrap gap-2 sm:col-span-2">
          {next && (
            <Button size="sm" variant="soft" onClick={() => form.set('amount', (next.balance_minor / 100).toFixed(2))}>
              Next installment {money(next.balance_minor)}
            </Button>
          )}
          <Button size="sm" variant="soft" onClick={() => form.set('amount', (outstanding / 100).toFixed(2))}>
            Pay off {money(outstanding)}
          </Button>
        </div>
      </div>
    </Modal>
  );
}

function CollectionModal({ loanId, onClose, invalidate }: { loanId: number; onClose: () => void; invalidate: unknown[][] }) {
  const { data: lookups } = useLookups();
  const form = useForm({ activity_type: 'call', outcome: '', notes: '', promise_date: '', promise_amount: '', next_action_date: '' });
  const save = useAction({
    fn: () => api.post(`/api/loans/${loanId}/collections`, Object.fromEntries(Object.entries(form.values).map(([k, v]) => [k, v === '' ? null : v]))),
    success: 'Activity logged',
    invalidate,
    onSuccess: onClose,
    onError: form.absorb,
  });
  const promise = form.values.activity_type === 'promise_to_pay';
  return (
    <Modal
      open
      onClose={onClose}
      title="Log collection activity"
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>
            Cancel
          </Button>
          <Button loading={save.isPending} onClick={() => save.mutate()}>
            Save
          </Button>
        </>
      }
    >
      <div className="grid gap-4 sm:grid-cols-2">
        <SelectField label="Activity" value={form.values.activity_type} onChange={(v) => form.set('activity_type', v)} options={lookups?.enums.collection_activity_types ?? []} />
        <SelectField
          label="Outcome"
          value={form.values.outcome}
          onChange={(v) => form.set('outcome', v)}
          placeholder="—"
          options={['reached', 'no_answer', 'wrong_number', 'refused', 'paid', 'rescheduled']}
        />
        {promise && (
          <>
            <TextField label="Promised amount (GHS)" type="number" value={form.values.promise_amount} onChange={(v) => form.set('promise_amount', v)} error={form.errors.promise_amount} required />
            <TextField label="Promised by" type="date" value={form.values.promise_date} onChange={(v) => form.set('promise_date', v)} error={form.errors.promise_date} required />
          </>
        )}
        <TextField label="Next action date" type="date" value={form.values.next_action_date} onChange={(v) => form.set('next_action_date', v)} error={form.errors.next_action_date} />
        <Field label="Notes" className="sm:col-span-2">
          {(fid) => <Textarea id={fid} value={form.values.notes} onChange={(e) => form.set('notes', e.target.value)} />}
        </Field>
      </div>
    </Modal>
  );
}
