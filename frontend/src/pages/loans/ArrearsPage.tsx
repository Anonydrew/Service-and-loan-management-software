import { Phone } from 'lucide-react';
import { date, label, money } from '../../lib/format';
import { useLookups, usePaginated, useUrlFilters } from '../../lib/hooks';
import type { Loan } from '../../lib/types';
import { DataTable, FilterSelect, Pagination, Toolbar } from '../../components/DataTable';
import { Badge, Card, PageHeader, Tabs } from '../../components/ui';

export default function ArrearsPage() {
  const { filters, set } = useUrlFilters();
  const { data: lookups } = useLookups();
  const { data, isLoading, isFetching, error, refetch } = usePaginated<Loan>('/api/collections/arrears', filters);

  return (
    <>
      <PageHeader title="Arrears & collections" description="Loans with overdue installments, most overdue first. Open a loan to log calls, visits and promises to pay." />
      <Tabs
        value={filters.bucket ?? ''}
        onChange={(v) => set('bucket', v)}
        tabs={[
          { value: '', label: 'All' },
          { value: '1-30', label: '1–30 days' },
          { value: '31-60', label: '31–60 days' },
          { value: '61-90', label: '61–90 days' },
          { value: '90+', label: '90+ days' },
        ]}
      />
      <Card padded={false}>
        {(lookups?.branches.length ?? 0) > 1 && (
          <Toolbar>
            <FilterSelect value={filters.branch_id ?? ''} onChange={(v) => set('branch_id', v)} options={(lookups?.branches ?? []).map((b) => ({ value: b.id, label: b.name }))} placeholder="All branches" />
          </Toolbar>
        )}
        <DataTable<Loan>
          rows={data?.data}
          loading={isLoading || isFetching}
          error={error}
          onRetry={refetch}
          rowLink={(l) => `/loans/${l.id}`}
          empty={{ title: 'No loans in arrears', description: 'Every open loan is up to date.' }}
          columns={[
            {
              key: 'c',
              header: 'Customer',
              render: (l) => (
                <div>
                  <p className="font-medium text-slate-900">{l.customer?.display_name}</p>
                  <p className="flex items-center gap-1 text-xs text-slate-500">
                    <Phone className="size-3" /> {l.customer?.phone}
                  </p>
                </div>
              ),
            },
            { key: 'l', header: 'Loan', render: (l) => l.loan_number },
            {
              key: 'days',
              header: 'Days overdue',
              render: (l) => <Badge tone={l.days_in_arrears > 60 ? 'red' : l.days_in_arrears > 30 ? 'amber' : 'neutral'}>{l.days_in_arrears} days</Badge>,
            },
            { key: 'over', header: 'Overdue', align: 'right', render: (l) => <span className="tabular font-medium text-red-600">{money(l.overdue_minor ?? 0)}</span> },
            { key: 'out', header: 'Outstanding', align: 'right', hideOnMobile: true, render: (l) => <span className="tabular">{money(l.outstanding_minor ?? 0)}</span> },
            { key: 'officer', header: 'Officer', hideOnMobile: true, render: (l) => l.loan_officer?.name },
            {
              key: 'last',
              header: 'Last contact',
              hideOnMobile: true,
              render: (l) =>
                l.last_activity ? (
                  <span className="text-xs text-slate-600">
                    {label(l.last_activity.activity_type)} · {date(l.last_activity.created_at)}
                    {l.last_activity.promise_date && <span className="block text-amber-700">Promised {date(l.last_activity.promise_date)}</span>}
                  </span>
                ) : (
                  <span className="text-xs text-slate-400">None</span>
                ),
            },
          ]}
        />
        <Pagination page={data} onPage={(p) => set('page', String(p))} />
      </Card>
    </>
  );
}
