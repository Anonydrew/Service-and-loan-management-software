import { useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { ArrowLeft, CalendarClock, CircleCheck, FileText, Pause, Play, UserRound, X } from 'lucide-react';
import { api, errorMessage } from '../../lib/api';
import { useAuth } from '../../lib/auth';
import { dateTime, label, money } from '../../lib/format';
import { useAction, useLookups } from '../../lib/hooks';
import type { ServiceRequest } from '../../lib/types';
import { ConfirmDialog, Modal } from '../../components/Modal';
import { Button, ButtonLink, Card, CardHeader, Checkbox, DescriptionList, ErrorState, Field, LoadingBlock, PageHeader, SelectField, StatusBadge, TextField, Textarea } from '../../components/ui';

export default function ServiceRequestDetailPage() {
  const { id = '' } = useParams();
  const { can } = useAuth();
  const { data: lookups } = useLookups();
  const key = ['service-request', id];
  const [completing, setCompleting] = useState(false);
  const [cancelling, setCancelling] = useState(false);
  const [rescheduling, setRescheduling] = useState(false);
  const { data: r, isLoading, error, refetch } = useQuery({ queryKey: key, queryFn: () => api.get<ServiceRequest>(`/api/service-requests/${id}`) });
  const invalidate = [key, ['/api/service-requests'], ['/api/invoices'], ['dashboard']];

  const move = useAction({
    fn: ({ status, note, create_invoice }: { status: string; note?: string; create_invoice?: boolean }) => api.post(`/api/service-requests/${id}/status`, { status, note, create_invoice }),
    success: (res) => `Request ${label((res as ServiceRequest).status).toLowerCase()}`,
    invalidate,
    onSuccess: () => {
      setCompleting(false);
      setCancelling(false);
    },
  });
  const assign = useAction({ fn: (assigned_to: string) => api.put(`/api/service-requests/${id}`, { assigned_to: assigned_to || null }), success: 'Assignment updated', invalidate });
  const invoice = useAction({ fn: () => api.post(`/api/service-requests/${id}/invoice`), success: 'Invoice issued', invalidate });

  if (isLoading) return <LoadingBlock rows={8} />;
  if (error || !r) return <ErrorState message={errorMessage(error)} onRetry={() => refetch()} />;

  const editable = can('service_requests.update') && !['completed', 'cancelled'].includes(r.status);
  const can_ = (s: string) => ({ new: ['scheduled', 'in_progress', 'on_hold', 'cancelled'], scheduled: ['in_progress', 'on_hold', 'cancelled', 'new'], in_progress: ['on_hold', 'completed', 'cancelled'], on_hold: ['in_progress', 'scheduled', 'cancelled'] } as Record<string, string[]>)[r.status]?.includes(s);

  return (
    <>
      <PageHeader
        back={
          <Link to="/services/requests" className="inline-flex items-center gap-1 text-sm text-slate-500 hover:text-slate-800">
            <ArrowLeft className="size-4" /> Service requests
          </Link>
        }
        title={
          <span className="flex flex-wrap items-center gap-3">
            {r.request_number} <StatusBadge status={r.status} />
          </span>
        }
        description={
          <span>
            {r.service?.name} for{' '}
            <Link to={`/customers/${r.customer_id}`} className="font-medium text-brand-700 hover:underline">
              {r.customer?.display_name}
            </Link>
          </span>
        }
        actions={
          editable && (
            <>
              {can_('cancelled') && (
                <Button variant="ghost" icon={<X className="size-4" />} onClick={() => setCancelling(true)}>
                  Cancel
                </Button>
              )}
              {can_('on_hold') && (
                <Button variant="secondary" icon={<Pause className="size-4" />} loading={move.isPending && move.variables?.status === 'on_hold'} onClick={() => move.mutate({ status: 'on_hold' })}>
                  Hold
                </Button>
              )}
              {can_('scheduled') && (
                <Button variant="secondary" icon={<CalendarClock className="size-4" />} onClick={() => setRescheduling(true)}>
                  Schedule
                </Button>
              )}
              {can_('in_progress') && (
                <Button icon={<Play className="size-4" />} loading={move.isPending && move.variables?.status === 'in_progress'} onClick={() => move.mutate({ status: 'in_progress' })}>
                  Start work
                </Button>
              )}
              {can_('completed') && (
                <Button icon={<CircleCheck className="size-4" />} onClick={() => setCompleting(true)}>
                  Complete
                </Button>
              )}
            </>
          )
        }
      />

      <div className="grid gap-6 lg:grid-cols-3">
        <div className="space-y-6 lg:col-span-2">
          <Card>
            <CardHeader title="Details" />
            <DescriptionList
              items={[
                { label: 'Service', value: `${r.service?.name} (${r.service?.code})` },
                { label: 'Priority', value: <StatusBadge status={r.priority} /> },
                { label: 'Quantity', value: parseFloat(r.quantity) },
                { label: 'Unit price', value: money(r.unit_price_minor) },
                { label: 'Discount', value: money(r.discount_minor) },
                { label: 'Total (before tax)', value: <strong>{money(r.total_minor)}</strong> },
                { label: 'Appointment', value: dateTime(r.scheduled_at) },
                { label: 'Branch', value: r.branch?.name },
                { label: 'Created', value: `${dateTime(r.created_at)} by ${r.created_by?.name ?? '—'}` },
                { label: 'Completed', value: r.completed_at ? dateTime(r.completed_at) : null },
                { label: 'Description', value: r.description },
                { label: 'Completion notes', value: r.completion_notes },
                { label: 'Cancellation reason', value: r.cancel_reason, hidden: !r.cancel_reason },
              ]}
            />
          </Card>
          <Card>
            <CardHeader title="Timeline" />
            <ol className="relative ml-2 border-l border-slate-200">
              {(r.updates ?? []).map((u) => (
                <li key={u.id} className="mb-5 ml-5">
                  <span className="absolute -left-[5px] mt-1.5 size-2.5 rounded-full bg-brand-500 ring-4 ring-white" />
                  <p className="text-sm text-slate-800">
                    {u.status_to ? (
                      <>
                        {u.status_from ? `${label(u.status_from)} → ` : ''}
                        <strong>{label(u.status_to)}</strong>
                      </>
                    ) : null}
                    {u.note && <span className="block text-slate-600">{u.note}</span>}
                  </p>
                  <p className="text-xs text-slate-500">
                    {dateTime(u.created_at)} · {u.user?.name}
                  </p>
                </li>
              ))}
            </ol>
          </Card>
        </div>
        <div className="space-y-6">
          <Card>
            <CardHeader title="Assignment" />
            {editable ? (
              <SelectField label="Assigned to" value={r.assigned_to ?? ''} onChange={(v) => assign.mutate(v)} options={(lookups?.staff ?? []).map((s) => ({ value: s.id, label: s.name }))} placeholder="Unassigned" disabled={assign.isPending} />
            ) : (
              <p className="flex items-center gap-2 text-sm text-slate-700">
                <UserRound className="size-4 text-slate-400" /> {r.assignee?.name ?? 'Unassigned'}
              </p>
            )}
          </Card>
          <Card>
            <CardHeader title="Billing" />
            {r.invoice ? (
              <div className="space-y-3">
                <p className="flex items-center justify-between text-sm">
                  <Link to={`/billing/invoices/${r.invoice.id}`} className="font-medium text-brand-700 hover:underline">
                    {r.invoice.invoice_number}
                  </Link>
                  <StatusBadge status={r.invoice.status} />
                </p>
                <p className="text-sm text-slate-600">
                  {money(r.invoice.amount_paid_minor)} paid of {money(r.invoice.total_minor)}
                </p>
                <ButtonLink to={`/billing/invoices/${r.invoice.id}`} variant="secondary" icon={<FileText className="size-4" />} className="w-full">
                  Open invoice
                </ButtonLink>
              </div>
            ) : r.status === 'cancelled' ? (
              <p className="text-sm text-slate-500">Cancelled — not billed.</p>
            ) : (
              <div className="space-y-3">
                <p className="text-sm text-slate-500">Not invoiced yet. An invoice is issued automatically on completion.</p>
                {can('invoices.create') && r.total_minor > 0 && (
                  <Button variant="secondary" className="w-full" loading={invoice.isPending} onClick={() => invoice.mutate()}>
                    Invoice now
                  </Button>
                )}
              </div>
            )}
          </Card>
        </div>
      </div>

      {completing && <CompleteModal busy={move.isPending} onClose={() => setCompleting(false)} onSubmit={(note, create_invoice) => move.mutate({ status: 'completed', note, create_invoice })} invoiced={!!r.invoice} />}
      {rescheduling && <ScheduleModal request={r} onClose={() => setRescheduling(false)} invalidate={invalidate} />}
      <ConfirmDialog open={cancelling} onClose={() => setCancelling(false)} title="Cancel request" message="The request will be closed and not billed." reasonLabel="Reason" danger confirmLabel="Cancel request" loading={move.isPending} onConfirm={(note) => move.mutate({ status: 'cancelled', note })} />
    </>
  );
}

function CompleteModal({ onClose, onSubmit, busy, invoiced }: { onClose: () => void; onSubmit: (note: string, invoice: boolean) => void; busy: boolean; invoiced: boolean }) {
  const [note, setNote] = useState('');
  const [invoice, setInvoice] = useState(!invoiced);
  return (
    <Modal
      open
      onClose={onClose}
      title="Complete request"
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>
            Cancel
          </Button>
          <Button loading={busy} onClick={() => onSubmit(note, invoice)}>
            Mark complete
          </Button>
        </>
      }
    >
      <div className="space-y-4">
        <Field label="Completion notes">{(id) => <Textarea id={id} value={note} onChange={(e) => setNote(e.target.value)} placeholder="What was delivered?" />}</Field>
        {!invoiced && <Checkbox checked={invoice} onChange={setInvoice} label="Issue an invoice now" description="Uses the request's quantity, price, discount and the service's tax rate." />}
      </div>
    </Modal>
  );
}

function ScheduleModal({ request, onClose, invalidate }: { request: ServiceRequest; onClose: () => void; invalidate: unknown[][] }) {
  const [when, setWhen] = useState(request.scheduled_at ? request.scheduled_at.slice(0, 16) : '');
  const save = useAction({ fn: () => api.put(`/api/service-requests/${request.id}`, { scheduled_at: when }), success: 'Appointment scheduled', invalidate, onSuccess: onClose });
  return (
    <Modal
      open
      onClose={onClose}
      title="Schedule appointment"
      size="sm"
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>
            Cancel
          </Button>
          <Button disabled={!when} loading={save.isPending} onClick={() => save.mutate()}>
            Save
          </Button>
        </>
      }
    >
      <TextField label="Date and time" type="datetime-local" value={when} onChange={setWhen} />
    </Modal>
  );
}
