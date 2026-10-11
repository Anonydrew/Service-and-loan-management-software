import { Link } from 'react-router-dom';
import { Receipt } from 'lucide-react';
import { download, errorMessage } from '../../lib/api';
import { date, label, money } from '../../lib/format';
import { useLookups, usePaginated, useUrlFilters } from '../../lib/hooks';
import type { Payment } from '../../lib/types';
import { DataTable, FilterSelect, Pagination, SearchInput, Toolbar } from '../../components/DataTable';
import { Card, Input, PageHeader, StatusBadge } from '../../components/ui';
import { useToast } from '../../components/Toast';

export default function PaymentsPage() {
  const toast = useToast();
  const { filters, set } = useUrlFilters();
  const { data: lookups } = useLookups();
  const { data, isLoading, isFetching, error, refetch } = usePaginated<Payment>('/api/payments', filters);

  return (
    <>
      <PageHeader title="Payments" description="Money received against service invoices. Loan repayments are listed under Lending." />
      <Card padded={false}>
        <Toolbar>
          <SearchInput value={filters.q ?? ''} onChange={(v) => set('q', v)} placeholder="Receipt, reference or customer…" />
          <FilterSelect value={filters.method ?? ''} onChange={(v) => set('method', v)} options={lookups?.enums.payment_methods ?? []} placeholder="Any method" />
          <Input type="date" value={filters.from ?? ''} onChange={(e) => set('from', e.target.value)} className="sm:w-40" aria-label="From date" />
          <Input type="date" value={filters.to ?? ''} onChange={(e) => set('to', e.target.value)} className="sm:w-40" aria-label="To date" />
        </Toolbar>
        <DataTable<Payment>
          rows={data?.data}
          loading={isLoading || isFetching}
          error={error}
          onRetry={refetch}
          empty={{ title: 'No payments found' }}
          columns={[
            { key: 'r', header: 'Receipt', render: (p) => <span className="font-medium text-slate-900">{p.receipt_number}</span> },
            { key: 'd', header: 'Date', render: (p) => date(p.paid_on) },
            { key: 'c', header: 'Customer', render: (p) => p.customer?.display_name },
            {
              key: 'i',
              header: 'Invoice',
              hideOnMobile: true,
              render: (p) => (
                <Link to={`/billing/invoices/${p.invoice_id}`} className="text-brand-700 hover:underline">
                  {p.invoice?.invoice_number}
                </Link>
              ),
            },
            { key: 'm', header: 'Method', hideOnMobile: true, render: (p) => `${label(p.payment_method)}${p.reference ? ` · ${p.reference}` : ''}` },
            { key: 'a', header: 'Amount', align: 'right', render: (p) => <span className="tabular font-medium">{money(p.amount_minor)}</span> },
            { key: 's', header: 'Status', render: (p) => <StatusBadge status={p.status} /> },
            {
              key: 'pdf',
              header: '',
              render: (p) => (
                <button className="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 hover:text-slate-700" aria-label="Download receipt" onClick={() => download(`/api/payments/${p.id}/receipt`, `receipt-${p.receipt_number}.pdf`).catch((e) => toast.error(errorMessage(e)))}>
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
