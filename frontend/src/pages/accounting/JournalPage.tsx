import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Plus, Trash2 } from 'lucide-react';
import { api, ApiError } from '../../lib/api';
import { useAuth } from '../../lib/auth';
import { date, money, today } from '../../lib/format';
import { useAction, usePaginated, useUrlFilters } from '../../lib/hooks';
import type { Account, JournalEntry } from '../../lib/types';
import { FilterSelect, Pagination, SearchInput, Toolbar } from '../../components/DataTable';
import { Modal } from '../../components/Modal';
import { Alert, Badge, Button, Card, EmptyState, ErrorState, Input, LoadingBlock, PageHeader, Select, TextField } from '../../components/ui';
import { errorMessage } from '../../lib/api';

export default function JournalPage() {
  const { can } = useAuth();
  const { filters, set } = useUrlFilters();
  const [creating, setCreating] = useState(false);
  const { data, isLoading, error, refetch } = usePaginated<JournalEntry>('/api/journal-entries', filters);

  return (
    <>
      <PageHeader
        title="Journal"
        description="Every posting, balanced. Automatic entries come from disbursements, repayments, invoices, payments, refunds and expenses."
        actions={
          can('accounting.manage') && (
            <Button icon={<Plus className="size-4" />} onClick={() => setCreating(true)}>
              Manual journal
            </Button>
          )
        }
      />
      <Card padded={false}>
        <Toolbar>
          <SearchInput value={filters.q ?? ''} onChange={(v) => set('q', v)} placeholder="Entry number or description…" />
          <FilterSelect
            value={filters.source ?? ''}
            onChange={(v) => set('source', v)}
            options={['Loan', 'LoanRepayment', 'Invoice', 'Payment', 'Refund', 'Expense', 'Manual'].map((s) => ({ value: s, label: s.replace(/([a-z])([A-Z])/g, '$1 $2') }))}
            placeholder="Any source"
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
          <EmptyState title="No journal entries" />
        ) : (
          <ul className="divide-y divide-slate-100">
            {data!.data.map((e) => (
              <li key={e.id} className="px-5 py-4">
                <div className="flex flex-wrap items-baseline justify-between gap-2">
                  <p className="text-sm font-medium text-slate-900">
                    <span className="mr-2 text-xs text-slate-500">{e.entry_number}</span>
                    {e.description}
                  </p>
                  <p className="text-xs text-slate-500">
                    {date(e.entry_date)} · {e.posted_by?.name ?? 'System'} {e.branch ? `· ${e.branch.name}` : ''} {e.source_type && <Badge>{e.source_type}</Badge>} {e.reversed_at && <Badge tone="amber">Reversed</Badge>}
                  </p>
                </div>
                <table className="mt-2 w-full text-xs">
                  <tbody>
                    {e.lines.map((l) => (
                      <tr key={l.id}>
                        <td className={`py-0.5 ${l.credit_minor ? 'pl-8' : ''} text-slate-700`}>
                          {l.account.code} {l.account.name}
                          {l.memo && <span className="text-slate-400"> · {l.memo}</span>}
                        </td>
                        <td className="tabular w-32 py-0.5 text-right">{l.debit_minor ? money(l.debit_minor) : ''}</td>
                        <td className="tabular w-32 py-0.5 text-right">{l.credit_minor ? money(l.credit_minor) : ''}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </li>
            ))}
          </ul>
        )}
        <Pagination page={data} onPage={(p) => set('page', String(p))} />
      </Card>
      {creating && <ManualJournal onClose={() => setCreating(false)} />}
    </>
  );
}

interface Line {
  account_id: string;
  debit: string;
  credit: string;
  memo: string;
}

function ManualJournal({ onClose }: { onClose: () => void }) {
  const accounts = useQuery({ queryKey: ['/api/accounts'], queryFn: () => api.get<{ data: Account[] }>('/api/accounts') });
  const [entryDate, setEntryDate] = useState(today());
  const [description, setDescription] = useState('');
  const [lines, setLines] = useState<Line[]>([
    { account_id: '', debit: '', credit: '', memo: '' },
    { account_id: '', debit: '', credit: '', memo: '' },
  ]);
  const [error, setError] = useState<string | null>(null);

  const cents = (v: string) => Math.round((Number(v) || 0) * 100);
  const debits = lines.reduce((s, l) => s + cents(l.debit), 0);
  const credits = lines.reduce((s, l) => s + cents(l.credit), 0);
  const balanced = debits > 0 && debits === credits;

  const save = useAction({
    fn: () => api.post('/api/journal-entries', { entry_date: entryDate, description, lines: lines.filter((l) => l.account_id).map((l) => ({ ...l, debit: l.debit || 0, credit: l.credit || 0 })) }),
    success: 'Journal posted',
    invalidate: [['/api/journal-entries'], ['/api/accounts']],
    onSuccess: onClose,
    onError: (e) => setError(e instanceof ApiError ? e.message : 'Could not post'),
  });

  const setLine = (i: number, patch: Partial<Line>) => setLines(lines.map((l, j) => (j === i ? { ...l, ...patch } : l)));

  return (
    <Modal
      open
      onClose={onClose}
      size="xl"
      title="Manual journal"
      description="For adjustments, opening balances and capital. Posted entries are immutable; correct them with a reversal."
      footer={
        <>
          <span className={`mr-auto self-center text-sm ${balanced ? 'text-emerald-700' : 'text-amber-700'}`}>
            Debits {money(debits)} · Credits {money(credits)} {balanced ? '✓ balanced' : '— must balance'}
          </span>
          <Button variant="secondary" onClick={onClose}>
            Cancel
          </Button>
          <Button disabled={!balanced || !description} loading={save.isPending} onClick={() => save.mutate()}>
            Post journal
          </Button>
        </>
      }
    >
      <div className="space-y-4">
        {error && <Alert tone="red">{error}</Alert>}
        <div className="grid gap-4 sm:grid-cols-3">
          <TextField label="Date" type="date" value={entryDate} max={today()} onChange={setEntryDate} />
          <TextField label="Description" value={description} onChange={setDescription} className="sm:col-span-2" required />
        </div>
        <div className="space-y-2">
          {lines.map((l, i) => (
            <div key={i} className="grid gap-2 sm:grid-cols-[2fr_1fr_1fr_1.5fr_auto]">
              <Select value={l.account_id} onChange={(e) => setLine(i, { account_id: e.target.value })} aria-label="Account">
                <option value="">Account…</option>
                {(accounts.data?.data ?? []).filter((a) => a.is_active).map((a) => (
                  <option key={a.id} value={a.id}>
                    {a.code} {a.name}
                  </option>
                ))}
              </Select>
              <Input type="number" placeholder="Debit" value={l.debit} onChange={(e) => setLine(i, { debit: e.target.value, credit: e.target.value ? '' : l.credit })} aria-label="Debit" />
              <Input type="number" placeholder="Credit" value={l.credit} onChange={(e) => setLine(i, { credit: e.target.value, debit: e.target.value ? '' : l.debit })} aria-label="Credit" />
              <Input placeholder="Memo" value={l.memo} onChange={(e) => setLine(i, { memo: e.target.value })} aria-label="Memo" />
              <button className="rounded-lg p-2 text-slate-400 hover:bg-red-50 hover:text-red-600 disabled:opacity-30" disabled={lines.length <= 2} aria-label="Remove line" onClick={() => setLines(lines.filter((_, j) => j !== i))}>
                <Trash2 className="size-4" />
              </button>
            </div>
          ))}
          <Button size="sm" variant="soft" icon={<Plus className="size-3.5" />} onClick={() => setLines([...lines, { account_id: '', debit: '', credit: '', memo: '' }])}>
            Add line
          </Button>
        </div>
      </div>
    </Modal>
  );
}
