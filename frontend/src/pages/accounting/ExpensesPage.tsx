import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Plus } from 'lucide-react';
import { api } from '../../lib/api';
import { useAuth } from '../../lib/auth';
import { date, label, money, today } from '../../lib/format';
import { useAction, useForm, useLookups, usePaginated, useUrlFilters } from '../../lib/hooks';
import type { Account, Expense } from '../../lib/types';
import { DataTable, FilterSelect, Pagination, SearchInput, Toolbar } from '../../components/DataTable';
import { ConfirmDialog, Modal } from '../../components/Modal';
import { Button, Card, Input, PageHeader, SelectField, StatusBadge, TextField } from '../../components/ui';

export default function ExpensesPage() {
  const { can } = useAuth();
  const { filters, set } = useUrlFilters();
  const { data: lookups } = useLookups();
  const [creating, setCreating] = useState(false);
  const [voiding, setVoiding] = useState<Expense | null>(null);
  const accounts = useQuery({ queryKey: ['/api/accounts'], queryFn: () => api.get<{ data: Account[] }>('/api/accounts') });
  const { data, isLoading, isFetching, error, refetch } = usePaginated<Expense>('/api/expenses', filters);
  const voidExp = useAction({
    fn: ({ e, reason }: { e: Expense; reason: string }) => api.post(`/api/expenses/${e.id}/void`, { reason }),
    success: 'Expense voided',
    invalidate: [['/api/expenses'], ['/api/accounts']],
    onSuccess: () => setVoiding(null),
  });
  const expenseAccounts = (accounts.data?.data ?? []).filter((a) => a.type === 'expense' && a.is_active);

  return (
    <>
      <PageHeader
        title="Expenses"
        description="Operating costs by branch and account. Each expense posts to the ledger."
        actions={
          can('expenses.create') && (
            <Button icon={<Plus className="size-4" />} onClick={() => setCreating(true)}>
              Record expense
            </Button>
          )
        }
      />
      <Card padded={false}>
        <Toolbar>
          <SearchInput value={filters.q ?? ''} onChange={(v) => set('q', v)} placeholder="Description, payee, number…" />
          <FilterSelect value={filters.account_id ?? ''} onChange={(v) => set('account_id', v)} options={expenseAccounts.map((a) => ({ value: a.id, label: `${a.code} ${a.name}` }))} placeholder="All accounts" />
          {(lookups?.branches.length ?? 0) > 1 && <FilterSelect value={filters.branch_id ?? ''} onChange={(v) => set('branch_id', v)} options={(lookups?.branches ?? []).map((b) => ({ value: b.id, label: b.name }))} placeholder="All branches" />}
          <Input type="date" value={filters.from ?? ''} onChange={(e) => set('from', e.target.value)} className="sm:w-40" aria-label="From" />
          <Input type="date" value={filters.to ?? ''} onChange={(e) => set('to', e.target.value)} className="sm:w-40" aria-label="To" />
        </Toolbar>
        <DataTable<Expense>
          rows={data?.data}
          loading={isLoading || isFetching}
          error={error}
          onRetry={refetch}
          empty={{ title: 'No expenses recorded' }}
          columns={[
            { key: 'd', header: 'Date', render: (e) => date(e.expense_date) },
            {
              key: 'desc',
              header: 'Description',
              render: (e) => (
                <div>
                  <p className="text-slate-900">{e.description}</p>
                  <p className="text-xs text-slate-500">
                    {e.expense_number}
                    {e.payee ? ` · ${e.payee}` : ''}
                  </p>
                </div>
              ),
            },
            { key: 'a', header: 'Account', hideOnMobile: true, render: (e) => `${e.account?.code} ${e.account?.name}` },
            { key: 'b', header: 'Branch', hideOnMobile: true, render: (e) => e.branch?.name },
            { key: 'm', header: 'Paid by', hideOnMobile: true, render: (e) => label(e.payment_method) },
            { key: 'amt', header: 'Amount', align: 'right', render: (e) => <span className={`tabular font-medium ${e.status === 'void' ? 'text-slate-400 line-through' : ''}`}>{money(e.amount_minor)}</span> },
            { key: 's', header: 'Status', render: (e) => <StatusBadge status={e.status} /> },
            {
              key: 'x',
              header: '',
              align: 'right',
              render: (e) =>
                e.status === 'recorded' &&
                can('expenses.create') && (
                  <Button size="sm" variant="ghost" onClick={() => setVoiding(e)}>
                    Void
                  </Button>
                ),
            },
          ]}
        />
        <Pagination page={data} onPage={(p) => set('page', String(p))} />
      </Card>
      {creating && <ExpenseModal accounts={expenseAccounts} onClose={() => setCreating(false)} />}
      <ConfirmDialog open={!!voiding} onClose={() => setVoiding(null)} title="Void expense" message="A reversing journal entry will be posted." reasonLabel="Reason" danger confirmLabel="Void" loading={voidExp.isPending} onConfirm={(reason) => voiding && voidExp.mutate({ e: voiding, reason })} />
    </>
  );
}

function ExpenseModal({ accounts, onClose }: { accounts: Account[]; onClose: () => void }) {
  const { data: lookups } = useLookups();
  const form = useForm({ branch_id: String(lookups?.branches[0]?.id ?? ''), expense_account_id: '', payment_method: 'cash', amount: '', expense_date: today(), payee: '', reference: '', description: '' });
  const { values: v, set, errors } = form;
  const save = useAction({ fn: () => api.post('/api/expenses', v), success: 'Expense recorded', invalidate: [['/api/expenses'], ['/api/accounts']], onSuccess: onClose, onError: form.absorb });
  return (
    <Modal
      open
      onClose={onClose}
      size="lg"
      title="Record expense"
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>
            Cancel
          </Button>
          <Button loading={save.isPending} onClick={() => save.mutate()}>
            Save
          </Button>
        </>
      }
    >
      <div className="grid gap-4 sm:grid-cols-2">
        <TextField label="Description" value={v.description} onChange={(x) => set('description', x)} error={errors.description} required className="sm:col-span-2" />
        <SelectField label="Expense account" value={v.expense_account_id} onChange={(x) => set('expense_account_id', x)} options={accounts.map((a) => ({ value: a.id, label: `${a.code} ${a.name}` }))} placeholder="Select…" error={errors.expense_account_id} required />
        <SelectField label="Branch" value={v.branch_id} onChange={(x) => set('branch_id', x)} options={(lookups?.branches ?? []).map((b) => ({ value: b.id, label: b.name }))} error={errors.branch_id} />
        <TextField label="Amount (GHS)" type="number" step="0.01" value={v.amount} onChange={(x) => set('amount', x)} error={errors.amount} required />
        <TextField label="Date" type="date" max={today()} value={v.expense_date} onChange={(x) => set('expense_date', x)} error={errors.expense_date} />
        <SelectField label="Paid by" value={v.payment_method} onChange={(x) => set('payment_method', x)} options={lookups?.enums.payment_methods ?? []} />
        <TextField label="Payee" value={v.payee} onChange={(x) => set('payee', x)} />
        <TextField label="Reference" value={v.reference} onChange={(x) => set('reference', x)} className="sm:col-span-2" />
      </div>
    </Modal>
  );
}
