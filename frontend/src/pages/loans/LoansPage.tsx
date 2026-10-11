import { HandCoins } from 'lucide-react';
import { useAuth } from '../../lib/auth';
import { date, label, money } from '../../lib/format';
import { useLookups, usePaginated, useUrlFilters } from '../../lib/hooks';
import type { Loan } from '../../lib/types';
import { DataTable, FilterSelect, Pagination, SearchInput, Toolbar } from '../../components/DataTable';
import { ButtonLink, Card, PageHeader, StatusBadge } from '../../components/ui';

export default function LoansPage() {
  const { can } = useAuth();
  const { filters, set } = useUrlFilters();
  const { data: lookups } = useLookups();
  const { data, isLoading, isFetching, error, refetch } = usePaginated<Loan>('/api/loans', filters);

  return (
    <>
      <PageHeader
        title="Loans"
        description="Approved, active and closed loans across your branches."
        actions={
          can('loan_applications.create') && (
            <ButtonLink to="/loans/applications/new" icon={<HandCoins className="size-4" />}>
              New application
            </ButtonLink>
          )
        }
      />
      <Card padded={false}>
        <Toolbar>
          <SearchInput value={filters.q ?? ''} onChange={(v) => set('q', v)} placeholder="Loan number, customer, phone…" />
          <FilterSelect value={filters.status ?? ''} onChange={(v) => set('status', v)} options={['pending_disbursement', 'active', 'in_arrears', 'closed', 'written_off']} placeholder="Any status" />
          <FilterSelect value={filters.product_id ?? ''} onChange={(v) => set('product_id', v)} options={(lookups?.loan_products ?? []).map((p) => ({ value: p.id, label: p.name }))} placeholder="All products" />
          {(lookups?.branches.length ?? 0) > 1 && (
            <FilterSelect value={filters.branch_id ?? ''} onChange={(v) => set('branch_id', v)} options={(lookups?.branches ?? []).map((b) => ({ value: b.id, label: b.name }))} placeholder="All branches" />
          )}
        </Toolbar>
        <DataTable<Loan>
          rows={data?.data}
          loading={isLoading || isFetching}
          error={error}
          onRetry={refetch}
          rowLink={(l) => `/loans/${l.id}`}
          empty={{ title: 'No loans found', description: 'Approved applications become loans here.' }}
          columns={[
            {
              key: 'loan',
              header: 'Loan',
              render: (l) => (
                <div>
                  <p className="font-medium text-slate-900">{l.loan_number}</p>
                  <p className="text-xs text-slate-500">{l.product?.name}</p>
                </div>
              ),
            },
            {
              key: 'customer',
              header: 'Customer',
              render: (l) => (
                <div>
                  <p className="text-slate-800">{l.customer?.display_name}</p>
                  <p className="text-xs text-slate-500">{l.customer?.phone}</p>
                </div>
              ),
            },
            { key: 'principal', header: 'Principal', align: 'right', render: (l) => <span className="tabular">{money(l.principal_minor)}</span> },
            { key: 'outstanding', header: 'Outstanding', align: 'right', render: (l) => <span className="tabular font-medium">{money(l.outstanding_minor ?? 0)}</span> },
            { key: 'terms', header: 'Terms', hideOnMobile: true, render: (l) => <span className="text-xs text-slate-500">{l.term} × {label(l.repayment_frequency).toLowerCase()}</span> },
            { key: 'disbursed', header: 'Disbursed', hideOnMobile: true, render: (l) => date(l.disbursement_date) },
            {
              key: 'status',
              header: 'Status',
              render: (l) => (
                <div className="flex flex-col items-start gap-1">
                  <StatusBadge status={l.status} />
                  {l.days_in_arrears > 0 && <span className="text-xs text-red-600">{l.days_in_arrears} days overdue</span>}
                </div>
              ),
            },
          ]}
        />
        <Pagination page={data} onPage={(p) => set('page', String(p))} />
      </Card>
    </>
  );
}
