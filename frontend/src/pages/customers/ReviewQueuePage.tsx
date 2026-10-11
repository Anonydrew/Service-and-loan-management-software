import { UserCheck } from 'lucide-react';
import { date, label } from '../../lib/format';
import { usePaginated, useUrlFilters } from '../../lib/hooks';
import type { Customer } from '../../lib/types';
import { DataTable, Pagination } from '../../components/DataTable';
import { Card, PageHeader } from '../../components/ui';

export default function ReviewQueuePage() {
  const { filters, set } = useUrlFilters();
  const { data, isLoading, isFetching, error, refetch } = usePaginated<Customer>('/api/onboarding/review-queue', filters);

  return (
    <>
      <PageHeader title="Identity review" description="Customers referred for manual identity verification, oldest first. You cannot decide on customers you registered." />
      <Card padded={false}>
        <DataTable<Customer>
          rows={data?.data}
          loading={isLoading || isFetching}
          error={error}
          onRetry={refetch}
          rowLink={(c) => `/customers/${c.id}/onboarding?step=review`}
          empty={{ title: 'No customers awaiting review', description: 'Referrals from onboarding staff will appear here.' }}
          columns={[
            {
              key: 'customer',
              header: 'Customer',
              render: (c) => (
                <div className="flex items-center gap-3">
                  <UserCheck className="size-4 text-amber-600" />
                  <div>
                    <p className="font-medium text-slate-900">{c.display_name}</p>
                    <p className="text-xs text-slate-500">
                      {c.customer_number} · {label(c.type)}
                    </p>
                  </div>
                </div>
              ),
            },
            { key: 'reason', header: 'Reason', render: (c) => <span className="line-clamp-2 text-sm text-slate-600">{c.manual_review_reason}</span> },
            { key: 'branch', header: 'Branch', render: (c) => c.branch?.name, hideOnMobile: true },
            { key: 'by', header: 'Registered by', render: (c) => c.created_by?.name, hideOnMobile: true },
            { key: 'date', header: 'Registered', render: (c) => date(c.created_at), hideOnMobile: true },
          ]}
        />
        <Pagination page={data} onPage={(p) => set('page', String(p))} />
      </Card>
    </>
  );
}
