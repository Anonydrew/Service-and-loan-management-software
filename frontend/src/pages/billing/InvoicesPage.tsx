import { FilePlus } from 'lucide-react';
import { useAuth } from '../../lib/auth';
import { date, money } from '../../lib/format';
import { usePaginated, useUrlFilters } from '../../lib/hooks';
import type { Invoice } from '../../lib/types';
import { DataTable, Pagination, SearchInput, Toolbar } from '../../components/DataTable';
import { ButtonLink, Card, PageHeader, StatusBadge, Tabs } from '../../components/ui';

export default function InvoicesPage() {
  const { can } = useAuth();
  const { filters, set } = useUrlFilters();
  const { data, isLoading, isFetching, error, refetch } = usePaginated<Invoice>('/api/invoices', filters);

  return (
    <>
      <PageHeader
        title="Invoices"
        description="Service invoices, balances and their payment status."
        actions={
          can('invoices.create') && (
            <ButtonLink to="/billing/invoices/new" icon={<FilePlus className="size-4" />}>
              New invoice
            </ButtonLink>
          )
        }
      />
      <Tabs
        value={filters.status ?? ''}
        onChange={(v) => set('status', v)}
        tabs={[
          { value: '', label: 'All' },
          { value: 'issued', label: 'Unpaid' },
          { value: 'partially_paid', label: 'Part paid' },
          { value: 'overdue', label: 'Overdue' },
          { value: 'paid', label: 'Paid' },
          { value: 'void', label: 'Void' },
        ]}
      />
      <Card padded={false}>
        <Toolbar>
          <SearchInput value={filters.q ?? ''} onChange={(v) => set('q', v)} placeholder="Invoice number or customer…" />
        </Toolbar>
        <DataTable<Invoice>
          rows={data?.data}
          loading={isLoading || isFetching}
          error={error}
          onRetry={refetch}
          rowLink={(i) => `/billing/invoices/${i.id}`}
          empty={{ title: 'No invoices found' }}
          columns={[
            { key: 'n', header: 'Invoice', render: (i) => <span className="font-medium text-slate-900">{i.invoice_number}</span> },
            { key: 'c', header: 'Customer', render: (i) => i.customer?.display_name },
            { key: 'd', header: 'Issued', hideOnMobile: true, render: (i) => date(i.issue_date) },
            {
              key: 'due',
              header: 'Due',
              hideOnMobile: true,
              render: (i) => <span className={i.balance_minor > 0 && new Date(i.due_date) < new Date() ? 'font-medium text-red-600' : ''}>{date(i.due_date)}</span>,
            },
            { key: 't', header: 'Total', align: 'right', render: (i) => <span className="tabular">{money(i.total_minor)}</span> },
            { key: 'b', header: 'Balance', align: 'right', render: (i) => <span className="tabular font-medium">{money(i.balance_minor)}</span> },
            { key: 's', header: 'Status', render: (i) => <StatusBadge status={i.status} /> },
          ]}
        />
        <Pagination page={data} onPage={(p) => set('page', String(p))} />
      </Card>
    </>
  );
}
