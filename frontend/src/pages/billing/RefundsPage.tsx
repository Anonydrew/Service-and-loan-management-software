import { useState } from 'react';
import { api } from '../../lib/api';
import { useAuth } from '../../lib/auth';
import { dateTime, money } from '../../lib/format';
import { useAction, usePaginated, useUrlFilters } from '../../lib/hooks';
import type { Refund } from '../../lib/types';
import { DataTable, Pagination } from '../../components/DataTable';
import { ConfirmDialog } from '../../components/Modal';
import { Button, Card, PageHeader, StatusBadge, Tabs } from '../../components/ui';

export default function RefundsPage() {
  const { can, user } = useAuth();
  const { filters, set } = useUrlFilters({ status: 'pending' });
  const { data, isLoading, isFetching, error, refetch } = usePaginated<Refund>('/api/refunds', { ...filters, status: filters.status === 'all' ? undefined : filters.status });
  const [rejecting, setRejecting] = useState<Refund | null>(null);
  const decide = useAction({
    fn: ({ r, decision, notes }: { r: Refund; decision: 'approve' | 'reject'; notes?: string }) => api.post(`/api/refunds/${r.id}/decide`, { decision, notes }),
    success: 'Refund decided',
    invalidate: [['/api/refunds'], ['/api/payments'], ['/api/invoices'], ['dashboard']],
    onSuccess: () => setRejecting(null),
  });

  return (
    <>
      <PageHeader title="Refunds" description="Requested by one person, approved by another. Approved refunds are posted to the ledger." />
      <Tabs
        value={filters.status ?? ''}
        onChange={(v) => set('status', v)}
        tabs={[
          { value: 'pending', label: 'Awaiting approval' },
          { value: 'approved', label: 'Approved' },
          { value: 'rejected', label: 'Rejected' },
          { value: 'all', label: 'All' },
        ]}
      />
      <Card padded={false}>
        <DataTable<Refund>
          rows={data?.data}
          loading={isLoading || isFetching}
          error={error}
          onRetry={refetch}
          empty={{ title: 'No refunds here' }}
          columns={[
            {
              key: 'p',
              header: 'Payment',
              render: (r) => (
                <div>
                  <p className="font-medium text-slate-900">{r.payment?.receipt_number}</p>
                  <p className="text-xs text-slate-500">{r.payment?.customer?.display_name}</p>
                </div>
              ),
            },
            { key: 'a', header: 'Amount', align: 'right', render: (r) => <span className="tabular font-medium">{money(r.amount_minor)}</span> },
            { key: 'reason', header: 'Reason', render: (r) => r.reason },
            { key: 'by', header: 'Requested', hideOnMobile: true, render: (r) => `${r.requested_by?.name ?? '—'} · ${dateTime(r.created_at)}` },
            { key: 's', header: 'Status', render: (r) => <StatusBadge status={r.status} /> },
            {
              key: 'act',
              header: '',
              align: 'right',
              render: (r) =>
                r.status === 'pending' && can('payments.refund_approve') ? (
                  r.requested_by?.id === user?.id ? (
                    <span className="text-xs text-slate-400">Needs another approver</span>
                  ) : (
                    <div className="flex justify-end gap-2">
                      <Button size="sm" variant="secondary" onClick={() => setRejecting(r)}>
                        Reject
                      </Button>
                      <Button size="sm" loading={decide.isPending && decide.variables?.r.id === r.id} onClick={() => decide.mutate({ r, decision: 'approve' })}>
                        Approve
                      </Button>
                    </div>
                  )
                ) : (
                  r.decided_by && <span className="text-xs text-slate-500">{r.decided_by.name}</span>
                ),
            },
          ]}
        />
        <Pagination page={data} onPage={(p) => set('page', String(p))} />
      </Card>
      <ConfirmDialog
        open={!!rejecting}
        onClose={() => setRejecting(null)}
        title="Reject refund"
        message="The requester is notified."
        reasonLabel="Reason"
        danger
        confirmLabel="Reject"
        loading={decide.isPending}
        onConfirm={(notes) => rejecting && decide.mutate({ r: rejecting, decision: 'reject', notes })}
      />
    </>
  );
}
