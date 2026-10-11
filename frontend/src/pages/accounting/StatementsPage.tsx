import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { api, errorMessage } from '../../lib/api';
import { money, monthStart, today } from '../../lib/format';
import { Card, CardHeader, ErrorState, Input, LoadingBlock, PageHeader, Tabs } from '../../components/ui';

type Tab = 'income' | 'balance' | 'trial';
interface Line {
  code: string;
  name: string;
  amount_minor: number;
}

export default function StatementsPage() {
  const [tab, setTab] = useState<Tab>('income');
  const [from, setFrom] = useState(monthStart());
  const [to, setTo] = useState(today());

  return (
    <>
      <PageHeader title="Financial statements" description="Generated directly from the ledger." />
      <Tabs<Tab>
        value={tab}
        onChange={setTab}
        tabs={[
          { value: 'income', label: 'Income statement' },
          { value: 'balance', label: 'Balance sheet' },
          { value: 'trial', label: 'Trial balance' },
        ]}
      />
      <div className="mb-4 flex flex-wrap items-center gap-2">
        {tab === 'income' && (
          <>
            <Input type="date" value={from} onChange={(e) => setFrom(e.target.value)} className="w-auto" aria-label="From" />
            <span className="text-sm text-slate-500">to</span>
          </>
        )}
        <Input type="date" value={to} onChange={(e) => setTo(e.target.value)} className="w-auto" aria-label={tab === 'income' ? 'To' : 'As of'} />
      </div>
      {tab === 'income' && <Income from={from} to={to} />}
      {tab === 'balance' && <Balance asOf={to} />}
      {tab === 'trial' && <Trial asOf={to} />}
    </>
  );
}

function Section({ title, lines, total }: { title: string; lines: Line[]; total: number }) {
  return (
    <div className="mb-6">
      <h4 className="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-500">{title}</h4>
      {lines.length === 0 && <p className="text-sm text-slate-400">None</p>}
      {lines.map((l) => (
        <div key={l.code} className="flex justify-between py-1 text-sm">
          <span className="text-slate-700">
            <span className="mr-2 text-xs text-slate-400">{l.code}</span>
            {l.name}
          </span>
          <span className="tabular">{money(l.amount_minor)}</span>
        </div>
      ))}
      <div className="mt-1 flex justify-between border-t border-slate-200 pt-1 text-sm font-semibold">
        <span>Total {title.toLowerCase()}</span>
        <span className="tabular">{money(total)}</span>
      </div>
    </div>
  );
}

function Income({ from, to }: { from: string; to: string }) {
  const { data, isLoading, error } = useQuery({
    queryKey: ['income', from, to],
    queryFn: () => api.get<{ income: Line[]; expenses: Line[]; total_income_minor: number; total_expenses_minor: number; net_profit_minor: number }>('/api/statements/income', { from, to }),
  });
  if (isLoading) return <LoadingBlock />;
  if (error || !data) return <ErrorState message={errorMessage(error)} />;
  return (
    <Card className="max-w-3xl">
      <Section title="Income" lines={data.income} total={data.total_income_minor} />
      <Section title="Expenses" lines={data.expenses} total={data.total_expenses_minor} />
      <div className={`flex justify-between rounded-xl px-4 py-3 text-base font-semibold ${data.net_profit_minor >= 0 ? 'bg-emerald-50 text-emerald-800' : 'bg-red-50 text-red-700'}`}>
        <span>Net {data.net_profit_minor >= 0 ? 'profit' : 'loss'}</span>
        <span className="tabular">{money(data.net_profit_minor)}</span>
      </div>
    </Card>
  );
}

function Balance({ asOf }: { asOf: string }) {
  const { data, isLoading, error } = useQuery({
    queryKey: ['balance-sheet', asOf],
    queryFn: () =>
      api.get<{ assets: Line[]; liabilities: Line[]; equity: Line[]; retained_earnings_minor: number; total_assets_minor: number; total_liabilities_minor: number; total_equity_minor: number }>('/api/statements/balance-sheet', { as_of: asOf }),
  });
  if (isLoading) return <LoadingBlock />;
  if (error || !data) return <ErrorState message={errorMessage(error)} />;
  const balanced = data.total_assets_minor === data.total_liabilities_minor + data.total_equity_minor;
  return (
    <div className="grid max-w-5xl gap-6 lg:grid-cols-2">
      <Card>
        <CardHeader title="Assets" />
        <Section title="Assets" lines={data.assets} total={data.total_assets_minor} />
      </Card>
      <Card>
        <CardHeader title="Liabilities & equity" />
        <Section title="Liabilities" lines={data.liabilities} total={data.total_liabilities_minor} />
        <Section title="Equity" lines={[...data.equity, { code: '—', name: 'Retained earnings (current)', amount_minor: data.retained_earnings_minor }]} total={data.total_equity_minor} />
        <p className={`text-xs ${balanced ? 'text-emerald-700' : 'text-red-600'}`}>{balanced ? 'Assets = liabilities + equity ✓' : 'Statement does not balance — check manual journals.'}</p>
      </Card>
    </div>
  );
}

function Trial({ asOf }: { asOf: string }) {
  const { data, isLoading, error } = useQuery({
    queryKey: ['trial', asOf],
    queryFn: () => api.get<{ rows: { code: string; name: string; type: string; debit_minor: number; credit_minor: number }[]; total_debit_minor: number; total_credit_minor: number }>('/api/statements/trial-balance', { as_of: asOf }),
  });
  if (isLoading) return <LoadingBlock />;
  if (error || !data) return <ErrorState message={errorMessage(error)} />;
  return (
    <Card padded={false} className="max-w-4xl">
      <table className="w-full text-sm">
        <thead>
          <tr className="border-b border-slate-200 bg-slate-50/70 text-xs font-semibold uppercase tracking-wide text-slate-500">
            <th className="px-4 py-2.5 text-left">Account</th>
            <th className="px-4 py-2.5 text-right">Debit</th>
            <th className="px-4 py-2.5 text-right">Credit</th>
          </tr>
        </thead>
        <tbody className="divide-y divide-slate-100">
          {data.rows.map((r) => (
            <tr key={r.code}>
              <td className="px-4 py-2">
                <span className="mr-2 text-xs text-slate-400">{r.code}</span>
                {r.name}
              </td>
              <td className="tabular px-4 py-2 text-right">{r.debit_minor ? money(r.debit_minor) : ''}</td>
              <td className="tabular px-4 py-2 text-right">{r.credit_minor ? money(r.credit_minor) : ''}</td>
            </tr>
          ))}
        </tbody>
        <tfoot>
          <tr className="border-t-2 border-slate-300 font-semibold">
            <td className="px-4 py-2.5">Totals {data.total_debit_minor === data.total_credit_minor ? '✓' : '✗'}</td>
            <td className="tabular px-4 py-2.5 text-right">{money(data.total_debit_minor)}</td>
            <td className="tabular px-4 py-2.5 text-right">{money(data.total_credit_minor)}</td>
          </tr>
        </tfoot>
      </table>
    </Card>
  );
}
