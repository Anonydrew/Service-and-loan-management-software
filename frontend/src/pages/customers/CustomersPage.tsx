import { Building2, Download, User, UserPlus } from 'lucide-react';
import { download, errorMessage } from '../../lib/api';
import { useAuth } from '../../lib/auth';
import { date } from '../../lib/format';
import { useLookups, usePaginated, useUrlFilters } from '../../lib/hooks';
import type { Customer } from '../../lib/types';
import { DataTable, FilterSelect, Pagination, SearchInput, Toolbar } from '../../components/DataTable';
import { Button, ButtonLink, Card, PageHeader, StatusBadge } from '../../components/ui';
import { useToast } from '../../components/Toast';

export default function CustomersPage() {
  const { can } = useAuth();
  const toast = useToast();
  const { filters, set } = useUrlFilters();
  const { data: lookups } = useLookups();
  const { data, isLoading, isFetching, error, refetch } = usePaginated<Customer>('/api/customers', filters);

  const exportCsv = async () => {
    try {
      const qs = new URLSearchParams(Object.entries(filters).filter(([k]) => k !== 'page')).toString();
      await download(`/api/customers/export${qs ? `?${qs}` : ''}`, 'customers.csv');
    } catch (e) {
      toast.error(errorMessage(e));
    }
  };

  return (
    <>
      <PageHeader
        title="Customers"
        description="Individuals and businesses registered with the company."
        actions={
          <>
            {can('customers.export') && (
              <Button variant="secondary" icon={<Download className="size-4" />} onClick={exportCsv}>
                Export
              </Button>
            )}
            {can('customers.create') && (
              <ButtonLink to="/customers/new" icon={<UserPlus className="size-4" />}>
                Register customer
              </ButtonLink>
            )}
          </>
        }
      />
      <Card padded={false}>
        <Toolbar>
          <SearchInput value={filters.q ?? ''} onChange={(v) => set('q', v)} placeholder="Name, number, phone, email…" />
          <FilterSelect value={filters.type ?? ''} onChange={(v) => set('type', v)} options={['individual', 'business']} placeholder="All types" />
          <FilterSelect value={filters.onboarding_status ?? ''} onChange={(v) => set('onboarding_status', v)} options={['in_progress', 'manual_review', 'completed', 'rejected']} placeholder="Any onboarding" />
          <FilterSelect value={filters.status ?? ''} onChange={(v) => set('status', v)} options={['draft', 'active', 'suspended', 'closed', 'rejected']} placeholder="Any status" />
          {(lookups?.branches.length ?? 0) > 1 && (
            <FilterSelect value={filters.branch_id ?? ''} onChange={(v) => set('branch_id', v)} options={(lookups?.branches ?? []).map((b) => ({ value: b.id, label: b.name }))} placeholder="All branches" />
          )}
        </Toolbar>
        <DataTable<Customer>
          rows={data?.data}
          loading={isLoading || isFetching}
          error={error}
          onRetry={refetch}
          rowLink={(c) => `/customers/${c.id}`}
          empty={{
            title: filters.q ? 'No customers match your search' : 'No customers yet',
            description: filters.q ? 'Try a different name, number or phone.' : 'Registered customers will appear here.',
            action: can('customers.create') ? <ButtonLink to="/customers/new">Register the first customer</ButtonLink> : undefined,
          }}
          columns={[
            {
              key: 'name',
              header: 'Customer',
              render: (c) => (
                <div className="flex items-center gap-3">
                  <div className="flex size-9 shrink-0 items-center justify-center rounded-full bg-slate-100 text-slate-500">
                    {c.type === 'business' ? <Building2 className="size-4" /> : <User className="size-4" />}
                  </div>
                  <div className="min-w-0">
                    <p className="truncate font-medium text-slate-900">{c.display_name}</p>
                    <p className="text-xs text-slate-500">{c.customer_number}</p>
                  </div>
                </div>
              ),
            },
            { key: 'phone', header: 'Phone', render: (c) => c.phone, hideOnMobile: true },
            { key: 'branch', header: 'Branch', render: (c) => c.branch?.name, hideOnMobile: true },
            { key: 'onboarding', header: 'Onboarding', render: (c) => <StatusBadge status={c.onboarding_status} /> },
            { key: 'verification', header: 'Identity', render: (c) => <StatusBadge status={c.verification_status} />, hideOnMobile: true },
            { key: 'status', header: 'Account', render: (c) => <StatusBadge status={c.status} />, hideOnMobile: true },
            { key: 'created', header: 'Registered', render: (c) => date(c.created_at), hideOnMobile: true },
          ]}
        />
        <Pagination page={data} onPage={(p) => set('page', String(p))} />
      </Card>
    </>
  );
}
