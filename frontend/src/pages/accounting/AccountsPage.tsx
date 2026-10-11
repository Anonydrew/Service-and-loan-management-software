import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Lock, Plus } from 'lucide-react';
import { api, errorMessage } from '../../lib/api';
import { useAuth } from '../../lib/auth';
import { date, label, money } from '../../lib/format';
import { useAction, useForm } from '../../lib/hooks';
import type { Account, Paginated } from '../../lib/types';
import { Modal } from '../../components/Modal';
import { Badge, Button, Card, ErrorState, LoadingBlock, PageHeader, SelectField, TextField } from '../../components/ui';

const ORDER = ['asset', 'liability', 'equity', 'income', 'expense'];

export default function AccountsPage() {
  const { can } = useAuth();
  const [creating, setCreating] = useState(false);
  const [viewing, setViewing] = useState<Account | null>(null);
  const { data, isLoading, error, refetch } = useQuery({ queryKey: ['/api/accounts'], queryFn: () => api.get<{ data: Account[] }>('/api/accounts') });

  return (
    <>
      <PageHeader
        title="Chart of accounts"
        description="Balances are calculated from posted journal entries. System accounts receive automatic postings."
        actions={
          can('accounting.manage') && (
            <Button icon={<Plus className="size-4" />} onClick={() => setCreating(true)}>
              New account
            </Button>
          )
        }
      />
      {isLoading ? (
        <LoadingBlock rows={10} />
      ) : error ? (
        <ErrorState message={errorMessage(error)} onRetry={() => refetch()} />
      ) : (
        <div className="space-y-6">
          {ORDER.map((type) => {
            const rows = (data?.data ?? []).filter((a) => a.type === type);
            if (rows.length === 0) return null;
            const total = rows.reduce((s, a) => s + (a.balance_minor ?? 0), 0);
            return (
              <Card key={type} padded={false}>
                <div className="flex items-center justify-between border-b border-slate-100 px-5 py-3">
                  <h3 className="font-semibold text-slate-900">{label(type)}</h3>
                  <span className="tabular text-sm font-semibold text-slate-700">{money(total)}</span>
                </div>
                <ul className="divide-y divide-slate-100">
                  {rows.map((a) => (
                    <li key={a.id}>
                      <button className="flex w-full items-center justify-between gap-3 px-5 py-2.5 text-left hover:bg-slate-50" onClick={() => setViewing(a)}>
                        <span className="flex items-center gap-3">
                          <span className="tabular w-12 text-xs text-slate-500">{a.code}</span>
                          <span className="text-sm text-slate-800">{a.name}</span>
                          {a.system_key && (
                            <Badge>
                              <Lock className="size-3" /> System
                            </Badge>
                          )}
                          {!a.is_active && <Badge>Inactive</Badge>}
                        </span>
                        <span className="tabular text-sm text-slate-700">{money(a.balance_minor ?? 0)}</span>
                      </button>
                    </li>
                  ))}
                </ul>
              </Card>
            );
          })}
        </div>
      )}
      {creating && <NewAccountModal onClose={() => setCreating(false)} />}
      {viewing && <LedgerModal account={viewing} onClose={() => setViewing(null)} />}
    </>
  );
}

function NewAccountModal({ onClose }: { onClose: () => void }) {
  const form = useForm({ code: '', name: '', type: 'expense', description: '' });
  const save = useAction({ fn: () => api.post('/api/accounts', form.values), success: 'Account created', invalidate: [['/api/accounts']], onSuccess: onClose, onError: form.absorb });
  return (
    <Modal
      open
      onClose={onClose}
      title="New account"
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>
            Cancel
          </Button>
          <Button loading={save.isPending} onClick={() => save.mutate()}>
            Create
          </Button>
        </>
      }
    >
      <div className="grid gap-4 sm:grid-cols-2">
        <TextField label="Code" value={form.values.code} onChange={(v) => form.set('code', v)} error={form.errors.code} required />
        <SelectField label="Type" value={form.values.type} onChange={(v) => form.set('type', v)} options={ORDER} />
        <TextField label="Name" value={form.values.name} onChange={(v) => form.set('name', v)} error={form.errors.name} required className="sm:col-span-2" />
        <TextField label="Description" value={form.values.description} onChange={(v) => form.set('description', v)} className="sm:col-span-2" />
      </div>
    </Modal>
  );
}

interface LedgerLine {
  id: number;
  entry_number: string;
  entry_date: string;
  description: string;
  debit_minor: number;
  credit_minor: number;
  memo: string | null;
}

function LedgerModal({ account, onClose }: { account: Account; onClose: () => void }) {
  const { data, isLoading } = useQuery({
    queryKey: ['ledger', account.id],
    queryFn: () => api.get<{ lines: Paginated<LedgerLine> }>(`/api/accounts/${account.id}/ledger`, { per_page: 50 }),
  });
  return (
    <Modal open onClose={onClose} size="xl" title={`${account.code} ${account.name}`} description={`Balance ${money(account.balance_minor ?? 0)} · latest 50 postings`}>
      {isLoading ? (
        <LoadingBlock />
      ) : (data?.lines.data ?? []).length === 0 ? (
        <p className="py-6 text-center text-sm text-slate-500">No postings yet.</p>
      ) : (
        <table className="w-full text-sm">
          <thead>
            <tr className="border-b border-slate-200 text-left text-xs uppercase tracking-wide text-slate-500">
              <th className="py-2">Date</th>
              <th className="py-2">Entry</th>
              <th className="py-2 text-right">Debit</th>
              <th className="py-2 text-right">Credit</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-slate-100">
            {data!.lines.data.map((l) => (
              <tr key={l.id}>
                <td className="whitespace-nowrap py-2 pr-3">{date(l.entry_date)}</td>
                <td className="py-2 pr-3">
                  <span className="text-xs text-slate-500">{l.entry_number}</span> {l.description}
                  {l.memo && <span className="text-xs text-slate-500"> · {l.memo}</span>}
                </td>
                <td className="tabular py-2 text-right">{l.debit_minor ? money(l.debit_minor) : ''}</td>
                <td className="tabular py-2 text-right">{l.credit_minor ? money(l.credit_minor) : ''}</td>
              </tr>
            ))}
          </tbody>
        </table>
      )}
    </Modal>
  );
}
