import { Link } from 'react-router-dom';
import { Receipt } from 'lucide-react';
import { download, errorMessage } from '../../lib/api';
import { date, label, money } from '../../lib/format';
import { useLookups, usePaginated, useUrlFilters } from '../../lib/hooks';
import type { Repayment } from '../../lib/types';
import { DataTable, FilterSelect, Pagination, SearchInput, Toolbar } from '../../components/DataTable';
import { Card, Input, PageHeader, StatusBadge } from '../../components/ui';
import { useToast } from '../../components/Toast';

export default function RepaymentsPage() {
  const toast = useToast();
  const { filters, set } = useUrlFilters();
  const { data: lookups } = useLookups();
  const { data, isLoading, isFetching, error, refetch } = usePaginated<Repayment>('/api/repayments', filters);

  return (
    <>
      <PageHeader title="Repayments" description="Every loan repayment received, with its allocation." />
      <Card padded={false}>
        <Toolbar>
          <SearchInput value={filters.q ?? ''} onChange={(v) => set('q', v)} placeholder="Receipt, reference or loan…" />
          <FilterSelect value={filters.method ?? ''} onChange={(v) => set('method', v)} options={lookups?.enums.payment_methods ?? []} placeholder="Any method" />
          <FilterSelect value={filters.status ?? ''} onChange={(v) => set('status', v)} options={['posted', 'reversed']} placeholder="Any status" />
          <Input type="date" value={filters.from ?? ''} onChange={(e) => set('from', e.target.value)} className="sm:w-40" aria-label="From date" />
          <Input type="date" value={filters.to ?? ''} onChange={(e) => set('to', e.target.value)} className="sm:w-40" aria-label="To date" />
        </Toolbar>
        <DataTable<Repayment>
          rows={data?.data}
          loading={isLoading || isFetching}
          error={error}
          onRetry={refetch}
          empty={{ title: 'No repayments found' }}
          columns={[
            { key: 'r', header: 'Receipt', render: (r) => <span className="font-medium text-slate-900">{r.receipt_number}</span> },
            { key: 'd', header: 'Date', render: (r) => date(r.paid_on) },
            {
              key: 'l',
              header: 'Loan',
              render: (r) => (
                <div>
                  <Link to={`/loans/${r.loan_id}`} className="text-brand-700 hover:underline">
                    {r.loan?.loan_number}
                  </Link>
                  <p className="text-xs text-slate-500">{r.customer?.display_name}</p>
                </div>
              ),
            },
            { key: 'm', header: 'Method', hideOnMobile: true, render: (r) => `${label(r.payment_method)}${r.reference ? ` · ${r.reference}` : ''}` },
            { key: 'a', header: 'Amount', align: 'right', render: (r) => <span className="tabular font-medium">{money(r.amount_minor)}</span> },
            { key: 'split', header: 'Principal / interest', hideOnMobile: true, align: 'right', render: (r) => <span className="tabular text-xs text-slate-500">{money(r.principal_minor)} / {money(r.interest_minor)}</span> },
            { key: 'by', header: 'Received by', hideOnMobile: true, render: (r) => r.received_by?.name },
            { key: 's', header: 'Status', render: (r) => <StatusBadge status={r.status} /> },
            {
              key: 'pdf',
              header: '',
              render: (r) => (
                <button
                  className="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 hover:text-slate-700"
                  aria-label="Download receipt"
                  onClick={() => download(`/api/repayments/${r.id}/receipt`, `receipt-${r.receipt_number}.pdf`).catch((e) => toast.error(errorMessage(e)))}
                >
                  <Receipt className="size-4" />
                </button>
              ),
            },
          ]}
        />
        <Pagination page={data} onPage={(p) => set('page', String(p))} />
      </Card>
    </>
  );
}
