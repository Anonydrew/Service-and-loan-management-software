import { useMemo, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { CheckCheck } from 'lucide-react';
import { api, errorMessage } from '../../lib/api';
import { date, label, money, monthStart, today } from '../../lib/format';
import { useAction, useLookups } from '../../lib/hooks';
import { Badge, Button, Card, EmptyState, ErrorState, Input, LoadingBlock, PageHeader, Select, TextField } from '../../components/ui';

interface Row {
  kind: 'payment' | 'repayment';
  id: number;
  receipt_number: string;
  customer: string | null;
  amount_minor: number;
  method: string;
  reference: string | null;
  paid_on: string;
  reconciled_at: string | null;
  statement_reference: string | null;
}

export default function ReconciliationPage() {
  const { data: lookups } = useLookups();
  const [method, setMethod] = useState('mobile_money');
  const [from, setFrom] = useState(monthStart());
  const [to, setTo] = useState(today());
  const [state, setState] = useState('unreconciled');
  const [selected, setSelected] = useState<Set<string>>(new Set());
  const [statement, setStatement] = useState('');
  const query = { method, from, to, state };
  const { data, isLoading, error, refetch } = useQuery({ queryKey: ['/api/reconciliation', query], queryFn: () => api.get<{ data: Row[]; totals: { count: number; amount_minor: number } }>('/api/reconciliation', query) });

  const rows = useMemo(() => data?.data ?? [], [data]);
  const selectedTotal = rows.filter((r) => selected.has(`${r.kind}-${r.id}`)).reduce((s, r) => s + r.amount_minor, 0);
  const toggle = (k: string) => setSelected((s) => {
    const n = new Set(s);
    if (n.has(k)) n.delete(k);
    else n.add(k);
    return n;
  });

  const save = useAction({
    fn: () => {
      const picked = rows.filter((r) => selected.has(`${r.kind}-${r.id}`));
      return api.post<{ reconciled: number }>('/api/reconciliation', {
        statement_reference: statement,
        payment_ids: picked.filter((r) => r.kind === 'payment').map((r) => r.id),
        repayment_ids: picked.filter((r) => r.kind === 'repayment').map((r) => r.id),
      });
    },
    success: (r) => `${r.reconciled} transaction(s) reconciled`,
    invalidate: [['/api/reconciliation']],
    onSuccess: () => {
      setSelected(new Set());
      setStatement('');
    },
  });

  return (
    <>
      <PageHeader title="Reconciliation" description="Tick off money received against your bank, mobile money or cash statement." />
      <Card padded={false}>
        <div className="flex flex-col gap-2 border-b border-slate-100 p-4 sm:flex-row sm:flex-wrap sm:items-center">
          <Select value={method} onChange={(e) => setMethod(e.target.value)} className="sm:w-44" aria-label="Method">
            {(lookups?.enums.payment_methods ?? []).map((m) => (
              <option key={m} value={m}>
                {label(m)}
              </option>
            ))}
          </Select>
          <Input type="date" value={from} onChange={(e) => setFrom(e.target.value)} className="sm:w-40" aria-label="From" />
          <Input type="date" value={to} onChange={(e) => setTo(e.target.value)} className="sm:w-40" aria-label="To" />
          <Select value={state} onChange={(e) => setState(e.target.value)} className="sm:w-44" aria-label="State">
            <option value="unreconciled">Unreconciled</option>
            <option value="reconciled">Reconciled</option>
            <option value="all">All</option>
          </Select>
          <span className="text-sm text-slate-500 sm:ml-auto">
            {data?.totals.count ?? 0} transactions · {money(data?.totals.amount_minor ?? 0)}
          </span>
        </div>
        {isLoading ? (
          <div className="p-5">
            <LoadingBlock />
          </div>
        ) : error ? (
          <ErrorState message={errorMessage(error)} onRetry={() => refetch()} />
        ) : rows.length === 0 ? (
          <EmptyState title="Nothing to reconcile" description="No transactions match these filters." />
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b border-slate-200 bg-slate-50/70 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                  <th className="w-10 px-4 py-2.5">
                    <input
                      type="checkbox"
                      aria-label="Select all"
                      className="accent-brand-700"
                      checked={rows.filter((r) => !r.reconciled_at).length > 0 && rows.filter((r) => !r.reconciled_at).every((r) => selected.has(`${r.kind}-${r.id}`))}
                      onChange={(e) => setSelected(e.target.checked ? new Set(rows.filter((r) => !r.reconciled_at).map((r) => `${r.kind}-${r.id}`)) : new Set())}
                    />
                  </th>
                  <th className="px-4 py-2.5">Date</th>
                  <th className="px-4 py-2.5">Receipt</th>
                  <th className="px-4 py-2.5">Customer</th>
                  <th className="px-4 py-2.5">Reference</th>
                  <th className="px-4 py-2.5 text-right">Amount</th>
                  <th className="px-4 py-2.5">Statement</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {rows.map((r) => {
                  const k = `${r.kind}-${r.id}`;
                  return (
                    <tr key={k} className={selected.has(k) ? 'bg-brand-50/50' : undefined}>
                      <td className="px-4 py-2.5">
                        {!r.reconciled_at && <input type="checkbox" className="accent-brand-700" aria-label={`Select ${r.receipt_number}`} checked={selected.has(k)} onChange={() => toggle(k)} />}
                      </td>
                      <td className="whitespace-nowrap px-4 py-2.5">{date(r.paid_on)}</td>
                      <td className="px-4 py-2.5">
                        {r.receipt_number} <Badge>{r.kind === 'payment' ? 'Invoice' : 'Loan'}</Badge>
                      </td>
                      <td className="px-4 py-2.5">{r.customer}</td>
                      <td className="px-4 py-2.5 text-slate-600">{r.reference ?? '—'}</td>
                      <td className="tabular px-4 py-2.5 text-right font-medium">{money(r.amount_minor)}</td>
                      <td className="px-4 py-2.5 text-xs text-slate-500">{r.statement_reference ?? ''}</td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
        )}
        {selected.size > 0 && (
          <div className="sticky bottom-0 flex flex-col gap-3 border-t border-slate-200 bg-white p-4 sm:flex-row sm:items-end">
            <p className="text-sm text-slate-600 sm:mr-auto">
              {selected.size} selected · <strong className="tabular">{money(selectedTotal)}</strong>
            </p>
            <TextField label="Statement reference" value={statement} onChange={setStatement} placeholder="e.g. MOMO-OCT-2026 line 42" className="sm:w-72" />
            <Button icon={<CheckCheck className="size-4" />} disabled={!statement.trim()} loading={save.isPending} onClick={() => save.mutate()}>
              Mark reconciled
            </Button>
          </div>
        )}
      </Card>
    </>
  );
}
