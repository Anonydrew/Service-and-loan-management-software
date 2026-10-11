import { useEffect, useState, type ReactNode } from 'react';
import { useNavigate } from 'react-router-dom';
import { ChevronLeft, ChevronRight, Search } from 'lucide-react';
import { errorMessage } from '../lib/api';
import type { Paginated } from '../lib/types';
import { EmptyState, ErrorState, Input, LoadingBlock, Select, cx } from './ui';

export interface Column<T> {
  key: string;
  header: ReactNode;
  render: (row: T) => ReactNode;
  className?: string;
  align?: 'left' | 'right' | 'center';
  /** Hide on small screens to keep the table readable. */
  hideOnMobile?: boolean;
}

interface Props<T> {
  columns: Column<T>[];
  rows: T[] | undefined;
  loading?: boolean;
  error?: unknown;
  onRetry?: () => void;
  rowLink?: (row: T) => string;
  empty?: { title: string; description?: ReactNode; action?: ReactNode };
  rowKey?: (row: T) => string | number;
  footer?: ReactNode;
}

export function DataTable<T extends { id?: number | string }>({ columns, rows, loading, error, onRetry, rowLink, empty, rowKey, footer }: Props<T>) {
  const navigate = useNavigate();

  if (error) return <ErrorState message={errorMessage(error)} onRetry={onRetry} />;
  if (loading && !rows) return <LoadingBlock rows={6} />;
  if (!rows || rows.length === 0) return <EmptyState title={empty?.title ?? 'Nothing here yet'} description={empty?.description} action={empty?.action} />;

  const align = (a?: string) => (a === 'right' ? 'text-right' : a === 'center' ? 'text-center' : 'text-left');

  return (
    <div className={cx('overflow-x-auto', loading && 'opacity-60 transition-opacity')}>
      <table className="w-full text-sm">
        <thead>
          <tr className="border-b border-slate-200 bg-slate-50/70">
            {columns.map((c) => (
              <th key={c.key} scope="col" className={cx('whitespace-nowrap px-4 py-2.5 text-xs font-semibold uppercase tracking-wide text-slate-500', align(c.align), c.hideOnMobile && 'hidden md:table-cell')}>
                {c.header}
              </th>
            ))}
          </tr>
        </thead>
        <tbody className="divide-y divide-slate-100">
          {rows.map((row, i) => (
            <tr
              key={rowKey ? rowKey(row) : (row.id ?? i)}
              onClick={rowLink ? () => navigate(rowLink(row)) : undefined}
              onKeyDown={rowLink ? (e) => e.key === 'Enter' && navigate(rowLink(row)) : undefined}
              tabIndex={rowLink ? 0 : undefined}
              className={cx(rowLink && 'cursor-pointer hover:bg-brand-50/40 focus:bg-brand-50/60 focus:outline-none')}
            >
              {columns.map((c) => (
                <td key={c.key} className={cx('px-4 py-3 align-middle text-slate-700', align(c.align), c.className, c.hideOnMobile && 'hidden md:table-cell')}>
                  {c.render(row)}
                </td>
              ))}
            </tr>
          ))}
        </tbody>
        {footer}
      </table>
    </div>
  );
}

export function Pagination({ page, onPage }: { page: Paginated<unknown> | undefined; onPage: (p: number) => void }) {
  if (!page || page.last_page <= 1) {
    return page && page.total > 0 ? <div className="border-t border-slate-100 px-4 py-3 text-xs text-slate-500">{page.total} record{page.total === 1 ? '' : 's'}</div> : null;
  }
  return (
    <div className="flex items-center justify-between gap-3 border-t border-slate-100 px-4 py-3 text-sm">
      <p className="text-xs text-slate-500">
        {page.from}–{page.to} of {page.total}
      </p>
      <div className="flex items-center gap-1">
        <button
          className="rounded-lg p-1.5 text-slate-500 hover:bg-slate-100 disabled:opacity-40"
          disabled={page.current_page <= 1}
          onClick={() => onPage(page.current_page - 1)}
          aria-label="Previous page"
        >
          <ChevronLeft className="size-4" />
        </button>
        <span className="px-2 text-xs text-slate-600">
          Page {page.current_page} of {page.last_page}
        </span>
        <button
          className="rounded-lg p-1.5 text-slate-500 hover:bg-slate-100 disabled:opacity-40"
          disabled={page.current_page >= page.last_page}
          onClick={() => onPage(page.current_page + 1)}
          aria-label="Next page"
        >
          <ChevronRight className="size-4" />
        </button>
      </div>
    </div>
  );
}

/** Search box that only commits after the user pauses typing. */
export function SearchInput({ value, onChange, placeholder = 'Search…' }: { value: string; onChange: (v: string) => void; placeholder?: string }) {
  const [text, setText] = useState(value);
  useEffect(() => setText(value), [value]);
  useEffect(() => {
    if (text === value) return;
    const t = setTimeout(() => onChange(text), 350);
    return () => clearTimeout(t);
  }, [text, value, onChange]);

  return (
    <div className="relative w-full sm:w-72">
      <Search className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-slate-400" />
      <Input value={text} onChange={(e) => setText(e.target.value)} placeholder={placeholder} className="pl-9" aria-label={placeholder} />
    </div>
  );
}

export function FilterSelect({ value, onChange, options, placeholder }: { value: string; onChange: (v: string) => void; options: (string | { value: string | number; label: string })[]; placeholder: string }) {
  return (
    <Select value={value} onChange={(e) => onChange(e.target.value)} className="w-full sm:w-auto sm:min-w-40" aria-label={placeholder}>
      <option value="">{placeholder}</option>
      {options.map((o) =>
        typeof o === 'string' ? (
          <option key={o} value={o}>
            {o.replace(/_/g, ' ').replace(/^\w/, (c) => c.toUpperCase())}
          </option>
        ) : (
          <option key={o.value} value={o.value}>
            {o.label}
          </option>
        ),
      )}
    </Select>
  );
}

export function Toolbar({ children }: { children: ReactNode }) {
  return <div className="flex flex-col gap-2 border-b border-slate-100 p-4 sm:flex-row sm:flex-wrap sm:items-center">{children}</div>;
}
