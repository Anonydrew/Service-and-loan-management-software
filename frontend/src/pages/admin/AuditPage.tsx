import { useState } from 'react';
import { ChevronDown } from 'lucide-react';
import { dateTime, label } from '../../lib/format';
import { useLookups, usePaginated, useUrlFilters } from '../../lib/hooks';
import type { AuditEntry } from '../../lib/types';
import { FilterSelect, Pagination, SearchInput, Toolbar } from '../../components/DataTable';
import { Badge, Card, EmptyState, ErrorState, Input, LoadingBlock, PageHeader, cx } from '../../components/ui';
import { errorMessage } from '../../lib/api';

export default function AuditPage() {
  const { filters, set } = useUrlFilters();
  const { data: lookups } = useLookups();
  const { data, isLoading, error, refetch } = usePaginated<AuditEntry & { company_id: number }>('/api/audit-logs', filters);
  const [open, setOpen] = useState<number | null>(null);

  return (
    <>
      <PageHeader title="Audit log" description="Append-only record of sign-ins, changes and sensitive actions. Identity numbers and biometric references are never written here." />
      <Card padded={false}>
        <Toolbar>
          <SearchInput value={filters.q ?? ''} onChange={(v) => set('q', v)} placeholder="Description or person…" />
          <FilterSelect value={filters.user_id ?? ''} onChange={(v) => set('user_id', v)} options={(lookups?.staff ?? []).map((s) => ({ value: s.id, label: s.name }))} placeholder="Anyone" />
          <FilterSelect
            value={filters.action ?? ''}
            onChange={(v) => set('action', v)}
            options={['auth', 'customer', 'customer_document', 'consent', 'biometric', 'loan', 'loan_application', 'loan_repayment', 'invoice', 'refund', 'expense', 'user', 'role', 'settings', 'security'].map((a) => ({ value: a, label: label(a) }))}
            placeholder="Any action"
          />
          <Input type="date" value={filters.from ?? ''} onChange={(e) => set('from', e.target.value)} className="sm:w-40" aria-label="From" />
          <Input type="date" value={filters.to ?? ''} onChange={(e) => set('to', e.target.value)} className="sm:w-40" aria-label="To" />
        </Toolbar>
        {isLoading ? (
          <div className="p-5">
            <LoadingBlock />
          </div>
        ) : error ? (
          <ErrorState message={errorMessage(error)} onRetry={() => refetch()} />
        ) : (data?.data ?? []).length === 0 ? (
          <EmptyState title="No audit entries" />
        ) : (
          <ul className="divide-y divide-slate-100">
            {data!.data.map((e) => {
              const detail = e.changes || e.metadata;
              return (
                <li key={e.id}>
                  <button className="flex w-full items-start gap-3 px-5 py-3 text-left hover:bg-slate-50" onClick={() => setOpen(open === e.id ? null : e.id)} aria-expanded={open === e.id} disabled={!detail}>
                    <div className="min-w-0 flex-1">
                      <p className="flex flex-wrap items-center gap-2 text-sm text-slate-800">
                        <Badge tone={e.action.startsWith('security') || e.action.includes('failed') ? 'red' : 'neutral'}>{e.action}</Badge>
                        {e.description ?? (e.auditable_type ? `${e.auditable_type} #${e.auditable_id}` : '')}
                      </p>
                      <p className="mt-0.5 text-xs text-slate-500">
                        {dateTime(e.created_at)} · {e.actor_label ?? 'System'} {e.ip_address ? `· ${e.ip_address}` : ''}
                      </p>
                    </div>
                    {detail && <ChevronDown className={cx('mt-1 size-4 text-slate-400 transition-transform', open === e.id && 'rotate-180')} />}
                  </button>
                  {open === e.id && detail && <pre className="mx-5 mb-3 max-h-72 overflow-auto rounded-lg bg-slate-900 p-3 text-xs text-slate-100">{JSON.stringify(detail, null, 2)}</pre>}
                </li>
              );
            })}
          </ul>
        )}
        <Pagination page={data} onPage={(p) => set('page', String(p))} />
      </Card>
    </>
  );
}
