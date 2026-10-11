import { ClipboardList } from 'lucide-react';
import { useAuth } from '../../lib/auth';
import { date, money } from '../../lib/format';
import { useLookups, usePaginated, useUrlFilters } from '../../lib/hooks';
import type { LoanApplication } from '../../lib/types';
import { DataTable, FilterSelect, Pagination, SearchInput, Toolbar } from '../../components/DataTable';
import { ButtonLink, Card, PageHeader, StatusBadge, Tabs } from '../../components/ui';

export default function ApplicationsPage() {
  const { can } = useAuth();
  const { filters, set } = useUrlFilters();
  const { data: lookups } = useLookups();
  const { data, isLoading, isFetching, error, refetch } = usePaginated<LoanApplication>('/api/loan-applications', filters);

  return (
    <>
      <PageHeader
        title="Loan applications"
        description="Prepared by loan officers, decided by a second authorised person."
        actions={
          can('loan_applications.create') && (
            <ButtonLink to="/loans/applications/new" icon={<ClipboardList className="size-4" />}>
              New application
            </ButtonLink>
          )
        }
      />
      <Tabs
        value={filters.status ?? ''}
        onChange={(v) => set('status', v)}
        tabs={[
          { value: '', label: 'All' },
          { value: 'draft', label: 'Drafts' },
          { value: 'submitted', label: 'Awaiting decision' },
          { value: 'approved', label: 'Approved' },
          { value: 'disbursed', label: 'Disbursed' },
          { value: 'rejected', label: 'Rejected' },
        ]}
      />
      <Card padded={false}>
        <Toolbar>
          <SearchInput value={filters.q ?? ''} onChange={(v) => set('q', v)} placeholder="Application number or customer…" />
          {(lookups?.branches.length ?? 0) > 1 && (
            <FilterSelect value={filters.branch_id ?? ''} onChange={(v) => set('branch_id', v)} options={(lookups?.branches ?? []).map((b) => ({ value: b.id, label: b.name }))} placeholder="All branches" />
          )}
        </Toolbar>
        <DataTable<LoanApplication>
          rows={data?.data}
          loading={isLoading || isFetching}
          error={error}
          onRetry={refetch}
          rowLink={(a) => `/loans/applications/${a.id}`}
          empty={{ title: 'No applications', description: 'New applications will appear here.' }}
          columns={[
            {
              key: 'n',
              header: 'Application',
              render: (a) => (
                <div>
                  <p className="font-medium text-slate-900">{a.application_number}</p>
                  <p className="text-xs text-slate-500">{a.product?.name}</p>
                </div>
              ),
            },
            { key: 'c', header: 'Customer', render: (a) => a.customer?.display_name },
            { key: 'a', header: 'Amount', align: 'right', render: (a) => <span className="tabular">{money(a.amount_requested_minor)}</span> },
            { key: 't', header: 'Term', hideOnMobile: true, render: (a) => a.term },
            { key: 'by', header: 'Prepared by', hideOnMobile: true, render: (a) => a.created_by?.name },
            { key: 'd', header: 'Created', hideOnMobile: true, render: (a) => date(a.created_at) },
            { key: 's', header: 'Status', render: (a) => <StatusBadge status={a.status} /> },
          ]}
        />
        <Pagination page={data} onPage={(p) => set('page', String(p))} />
      </Card>
    </>
  );
}
