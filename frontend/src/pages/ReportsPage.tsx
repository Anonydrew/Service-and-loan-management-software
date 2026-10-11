import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { ChartColumn, Download, FileBarChart } from 'lucide-react';
import { api, download, errorMessage, withQuery } from '../lib/api';
import { date, money, monthStart, today } from '../lib/format';
import { useLookups } from '../lib/hooks';
import type { ReportResult } from '../lib/types';
import { Button, Card, CardHeader, EmptyState, ErrorState, Input, LoadingBlock, PageHeader, Select, cx } from '../components/ui';
import { useToast } from '../components/Toast';

interface CatalogueItem {
  slug: string;
  title: string;
  description: string;
  financial: boolean;
}

export default function ReportsPage() {
  const toast = useToast();
  const { data: lookups } = useLookups();
  const [slug, setSlug] = useState('portfolio');
  const [from, setFrom] = useState(monthStart());
  const [to, setTo] = useState(today());
  const [branch, setBranch] = useState('');
  const catalogue = useQuery({ queryKey: ['report-catalogue'], queryFn: () => api.get<{ data: CatalogueItem[] }>('/api/reports/catalogue'), staleTime: Infinity });
  const params = { from, to, branch_id: branch || undefined };
  const report = useQuery({ queryKey: ['report', slug, params], queryFn: () => api.get<ReportResult>(`/api/reports/${slug}`, params) });

  const cell = (type: string, v: string | number | null) => {
    if (v === null || v === undefined || v === '') return '—';
    if (type === 'money') return money(Number(v));
    if (type === 'percent') return `${v}%`;
    if (type === 'date') return date(String(v));
    if (type === 'number') return Number(v).toLocaleString();
    return String(v).replace(/_/g, ' ');
  };

  return (
    <>
      <PageHeader title="Reports" description="Operational and financial reports, limited to the branches you can access." />
      <div className="grid gap-6 lg:grid-cols-4">
        <Card padded={false} className="h-fit">
          <ul className="p-2">
            {(catalogue.data?.data ?? []).map((r) => (
              <li key={r.slug}>
                <button onClick={() => setSlug(r.slug)} className={cx('flex w-full items-start gap-3 rounded-lg px-3 py-2.5 text-left', slug === r.slug ? 'bg-brand-50' : 'hover:bg-slate-50')}>
                  <FileBarChart className={cx('mt-0.5 size-4 shrink-0', slug === r.slug ? 'text-brand-700' : 'text-slate-400')} />
                  <span>
                    <span className={cx('block text-sm font-medium', slug === r.slug ? 'text-brand-800' : 'text-slate-700')}>{r.title}</span>
                    <span className="block text-xs text-slate-500">{r.description}</span>
                  </span>
                </button>
              </li>
            ))}
          </ul>
        </Card>
        <div className="lg:col-span-3">
          <Card padded={false}>
            <div className="flex flex-col gap-2 border-b border-slate-100 p-4 sm:flex-row sm:flex-wrap sm:items-center">
              <Input type="date" value={from} onChange={(e) => setFrom(e.target.value)} className="sm:w-40" aria-label="From" />
              <Input type="date" value={to} onChange={(e) => setTo(e.target.value)} className="sm:w-40" aria-label="To" />
              {(lookups?.branches.length ?? 0) > 1 && (
                <Select value={branch} onChange={(e) => setBranch(e.target.value)} className="sm:w-48" aria-label="Branch">
                  <option value="">All my branches</option>
                  {lookups!.branches.map((b) => (
                    <option key={b.id} value={b.id}>
                      {b.name}
                    </option>
                  ))}
                </Select>
              )}
              <Button
                variant="secondary"
                className="sm:ml-auto"
                icon={<Download className="size-4" />}
                onClick={() => download(withQuery(`/api/reports/${slug}`, { ...params, format: 'csv' }), `${slug}.csv`).catch((e) => toast.error(errorMessage(e)))}
              >
                Export CSV
              </Button>
            </div>
            <div className="p-5 pb-0">
              <CardHeader title={report.data?.title ?? 'Report'} description={report.data ? (['portfolio', 'arrears_aging'].includes(slug) ? 'Position as of today' : `${date(report.data.from)} – ${date(report.data.to)}`) : undefined} />
            </div>
            {report.isLoading ? (
              <div className="p-5">
                <LoadingBlock />
              </div>
            ) : report.error ? (
              <ErrorState message={errorMessage(report.error)} onRetry={() => report.refetch()} />
            ) : (report.data?.rows ?? []).length === 0 ? (
              <EmptyState icon={<ChartColumn className="size-6" />} title="No data for this period" />
            ) : (
              <div className="overflow-x-auto">
                <table className="w-full text-sm">
                  <thead>
                    <tr className="border-b border-slate-200 bg-slate-50/70 text-xs font-semibold uppercase tracking-wide text-slate-500">
                      {report.data!.columns.map((c) => (
                        <th key={c.key} className={cx('whitespace-nowrap px-4 py-2.5', ['money', 'number', 'percent'].includes(c.type) ? 'text-right' : 'text-left')}>
                          {c.label}
                        </th>
                      ))}
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-slate-100">
                    {report.data!.rows.map((row, i) => (
                      <tr key={i}>
                        {report.data!.columns.map((c) => (
                          <td key={c.key} className={cx('whitespace-nowrap px-4 py-2.5', ['money', 'number', 'percent'].includes(c.type) ? 'tabular text-right' : '')}>
                            {cell(c.type, row[c.key])}
                          </td>
                        ))}
                      </tr>
                    ))}
                  </tbody>
                  {report.data!.totals && (
                    <tfoot>
                      <tr className="border-t-2 border-slate-300 bg-slate-50 font-semibold">
                        {report.data!.columns.map((c, i) => (
                          <td key={c.key} className={cx('whitespace-nowrap px-4 py-2.5', ['money', 'number', 'percent'].includes(c.type) ? 'tabular text-right' : '')}>
                            {i === 0 ? 'Total' : report.data!.totals![c.key] !== undefined ? cell(c.type, report.data!.totals![c.key]) : ''}
                          </td>
                        ))}
                      </tr>
                    </tfoot>
                  )}
                </table>
              </div>
            )}
            {report.data?.notes && <p className="p-4 text-xs text-slate-500">{report.data.notes}</p>}
          </Card>
        </div>
      </div>
    </>
  );
}
