import { useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { ArrowLeft, ArrowRight, CircleCheck, CircleX, Send, TriangleAlert, Ban } from 'lucide-react';
import { api, ApiError, errorMessage } from '../../lib/api';
import { useAuth } from '../../lib/auth';
import { date, dateTime, label, money, toMajor } from '../../lib/format';
import { useAction } from '../../lib/hooks';
import type { EligibilityCheck, Loan, LoanApplication } from '../../lib/types';
import { ConfirmDialog, Modal } from '../../components/Modal';
import { Alert, Button, ButtonLink, Card, CardHeader, DescriptionList, ErrorState, Field, LoadingBlock, PageHeader, StatusBadge, TextField, Textarea, cx } from '../../components/ui';
import { SchedulePreview } from './SchedulePreview';

export default function ApplicationDetailPage() {
  const { id = '' } = useParams();
  const { can, user } = useAuth();
  const navigate = useNavigate();
  const [approving, setApproving] = useState(false);
  const [rejecting, setRejecting] = useState(false);
  const [cancelling, setCancelling] = useState(false);
  const key = ['application', id];
  const { data: app, isLoading, error, refetch } = useQuery({ queryKey: key, queryFn: () => api.get<LoanApplication>(`/api/loan-applications/${id}`) });
  const invalidate = [key, ['/api/loan-applications'], ['dashboard']];

  const submit = useAction({
    fn: () => api.post(`/api/loan-applications/${id}/submit`),
    success: 'Submitted for approval',
    invalidate,
    onError: (e) => e instanceof ApiError && e.errorCode === 'not_eligible' && refetch(),
  });
  const reject = useAction({ fn: (notes: string) => api.post(`/api/loan-applications/${id}/reject`, { notes }), success: 'Application rejected', invalidate, onSuccess: () => setRejecting(false) });
  const cancel = useAction({ fn: (reason: string) => api.post(`/api/loan-applications/${id}/cancel`, { reason }), success: 'Application cancelled', invalidate, onSuccess: () => setCancelling(false) });

  if (isLoading) return <LoadingBlock rows={8} />;
  if (error || !app) return <ErrorState message={errorMessage(error)} onRetry={() => refetch()} />;

  const preparedByMe = user && [app.created_by?.id, app.loan_officer?.id].includes(user.id);
  const checks = app.eligibility?.checks ?? [];

  return (
    <>
      <PageHeader
        back={
          <Link to="/loans/applications" className="inline-flex items-center gap-1 text-sm text-slate-500 hover:text-slate-800">
            <ArrowLeft className="size-4" /> Applications
          </Link>
        }
        title={
          <span className="flex flex-wrap items-center gap-3">
            {app.application_number} <StatusBadge status={app.status} />
          </span>
        }
        description={
          <span>
            <Link to={`/customers/${app.customer_id}`} className="font-medium text-brand-700 hover:underline">
              {app.customer?.display_name}
            </Link>{' '}
            · {app.product?.name} · {app.branch?.name}
          </span>
        }
        actions={
          <>
            {['draft', 'submitted'].includes(app.status) && can(['loan_applications.create', 'loan_applications.approve']) && (
              <Button variant="ghost" icon={<Ban className="size-4" />} onClick={() => setCancelling(true)}>
                Cancel
              </Button>
            )}
            {app.status === 'draft' && can('loan_applications.create') && (
              <Button icon={<Send className="size-4" />} loading={submit.isPending} onClick={() => submit.mutate()}>
                Submit for approval
              </Button>
            )}
            {app.status === 'submitted' && can('loan_applications.approve') && !preparedByMe && (
              <>
                <Button variant="secondary" icon={<CircleX className="size-4" />} onClick={() => setRejecting(true)}>
                  Reject
                </Button>
                <Button icon={<CircleCheck className="size-4" />} onClick={() => setApproving(true)}>
                  Approve
                </Button>
              </>
            )}
            {app.loan && (
              <ButtonLink to={`/loans/${app.loan.id}`} variant="soft">
                Open {app.loan.loan_number} <ArrowRight className="size-4" />
              </ButtonLink>
            )}
          </>
        }
      />

      {app.status === 'submitted' && preparedByMe && can('loan_applications.approve') && (
        <div className="mb-6">
          <Alert tone="neutral">You prepared this application, so another authorised user must approve or reject it.</Alert>
        </div>
      )}
      {app.status === 'rejected' && (
        <div className="mb-6">
          <Alert tone="red" title="Rejected">
            {app.decision_notes} — {app.decided_by?.name}, {dateTime(app.decided_at)}
          </Alert>
        </div>
      )}

      <div className="grid gap-6 lg:grid-cols-5">
        <div className="space-y-6 lg:col-span-3">
          <Card>
            <CardHeader title="Eligibility" description={app.eligibility?.eligible ? 'All rules passed.' : 'Failures must be resolved before submission.'} />
            <ul className="divide-y divide-slate-100">
              {checks.map((c) => (
                <CheckRow key={c.key} check={c} />
              ))}
            </ul>
          </Card>
          <Card>
            <CardHeader title="Application" />
            <DescriptionList
              items={[
                { label: 'Amount requested', value: money(app.amount_requested_minor) },
                { label: 'Term', value: `${app.term} ${app.product?.repayment_frequency ?? ''} installments` },
                { label: 'Approved', value: app.approved_amount_minor ? `${money(app.approved_amount_minor)} over ${app.approved_term}` : null },
                { label: 'Estimated installment', value: app.eligibility ? money(app.eligibility.installment_minor) : null },
                { label: 'Monthly income', value: money(app.monthly_income_minor) },
                { label: 'Other obligations', value: money(app.monthly_obligations_minor) },
                { label: 'Purpose', value: app.purpose },
                { label: 'Prepared by', value: `${app.created_by?.name ?? '—'} · ${date(app.created_at)}` },
                { label: 'Submitted', value: dateTime(app.submitted_at) },
                { label: 'Decision', value: app.decided_by ? `${app.decided_by.name} · ${dateTime(app.decided_at)}` : null },
                { label: 'Decision notes', value: app.decision_notes },
              ]}
            />
            {(app.guarantors?.length ?? 0) > 0 && (
              <div className="mt-6">
                <p className="mb-2 text-xs font-medium uppercase tracking-wide text-slate-500">Guarantors</p>
                <ul className="space-y-1 text-sm text-slate-700">
                  {app.guarantors!.map((g, i) => (
                    <li key={i}>
                      {g.name} · {g.phone} {g.relationship ? `· ${g.relationship}` : ''}
                    </li>
                  ))}
                </ul>
              </div>
            )}
            {(app.collateral?.length ?? 0) > 0 && (
              <div className="mt-4">
                <p className="mb-2 text-xs font-medium uppercase tracking-wide text-slate-500">Collateral</p>
                <ul className="space-y-1 text-sm text-slate-700">
                  {app.collateral!.map((c, i) => (
                    <li key={i}>
                      {c.description}
                      {c.estimated_value ? ` · est. GHS ${Number(c.estimated_value).toLocaleString()}` : ''}
                    </li>
                  ))}
                </ul>
              </div>
            )}
          </Card>
        </div>
        <div className="lg:col-span-2">
          <SchedulePreview productId={String(app.loan_product_id)} amount={toMajor(app.approved_amount_minor ?? app.amount_requested_minor)} term={String(app.approved_term ?? app.term)} />
        </div>
      </div>

      {approving && <ApproveModal app={app} onClose={() => setApproving(false)} onDone={(loan) => navigate(`/loans/${loan.id}`)} invalidate={invalidate} />}
      <ConfirmDialog open={rejecting} onClose={() => setRejecting(false)} title="Reject application" message="The preparer is notified with your reason." reasonLabel="Reason" confirmLabel="Reject" danger loading={reject.isPending} onConfirm={(r) => reject.mutate(r)} />
      <ConfirmDialog open={cancelling} onClose={() => setCancelling(false)} title="Cancel application" message="The application will be closed without a decision." reasonLabel="Reason" confirmLabel="Cancel application" danger loading={cancel.isPending} onConfirm={(r) => cancel.mutate(r)} />
    </>
  );
}

function CheckRow({ check }: { check: EligibilityCheck }) {
  const Icon = check.status === 'pass' ? CircleCheck : check.status === 'warn' ? TriangleAlert : CircleX;
  return (
    <li className="flex items-start gap-3 py-3">
      <Icon className={cx('mt-0.5 size-5 shrink-0', check.status === 'pass' ? 'text-emerald-600' : check.status === 'warn' ? 'text-amber-500' : 'text-red-500')} />
      <div>
        <p className="text-sm font-medium text-slate-800">{check.label}</p>
        <p className="text-xs text-slate-500">{check.detail}</p>
      </div>
      <span className="ml-auto">
        <StatusBadge status={check.status} label={label(check.status)} />
      </span>
    </li>
  );
}

function ApproveModal({ app, onClose, onDone, invalidate }: { app: LoanApplication; onClose: () => void; onDone: (loan: Loan) => void; invalidate: unknown[][] }) {
  const [amount, setAmount] = useState(toMajor(app.amount_requested_minor));
  const [term, setTerm] = useState(String(app.term));
  const [notes, setNotes] = useState('');
  const warnings = (app.eligibility?.checks ?? []).filter((c) => c.status === 'warn');
  const approve = useAction({
    fn: () => api.post<{ loan: Loan }>(`/api/loan-applications/${app.id}/approve`, { approved_amount: amount, approved_term: term, notes }),
    success: (r) => `Approved as ${r.loan.loan_number}`,
    invalidate,
    onSuccess: (r) => onDone(r.loan),
  });

  return (
    <Modal
      open
      onClose={onClose}
      title={`Approve ${app.application_number}`}
      description="You may approve a lower amount or a different term within the product limits."
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>
            Cancel
          </Button>
          <Button loading={approve.isPending} onClick={() => approve.mutate()}>
            Approve loan
          </Button>
        </>
      }
    >
      <div className="space-y-4">
        {warnings.length > 0 && (
          <Alert tone="amber" title="Review before approving">
            {warnings.map((w) => (
              <p key={w.key}>
                {w.label}: {w.detail}
              </p>
            ))}
          </Alert>
        )}
        <div className="grid gap-4 sm:grid-cols-2">
          <TextField label="Approved amount (GHS)" type="number" value={amount} onChange={setAmount} />
          <TextField label="Approved term" type="number" value={term} onChange={setTerm} />
        </div>
        <Field label="Notes">{(fid) => <Textarea id={fid} value={notes} onChange={(e) => setNotes(e.target.value)} placeholder="Conditions or rationale" />}</Field>
      </div>
    </Modal>
  );
}
