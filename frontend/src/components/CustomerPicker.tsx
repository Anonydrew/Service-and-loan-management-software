import { useEffect, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Building2, Search, User, X } from 'lucide-react';
import { api } from '../lib/api';
import { useDebounced } from '../lib/hooks';
import type { Customer, Paginated } from '../lib/types';
import { Field, Input, Spinner, StatusBadge } from './ui';

/** Search-as-you-type customer selector. `onlyOnboarded` limits to customers who completed onboarding. */
export function CustomerPicker({
  value,
  onChange,
  error,
  onlyOnboarded,
  label = 'Customer',
  initialId,
}: {
  value: Customer | null;
  onChange: (c: Customer | null) => void;
  error?: string;
  onlyOnboarded?: boolean;
  label?: string;
  initialId?: string | null;
}) {
  const [q, setQ] = useState('');
  const [open, setOpen] = useState(false);
  const term = useDebounced(q, 250);

  const preset = useQuery({
    queryKey: ['customer-preset', initialId],
    queryFn: () => api.get<{ customer: Customer }>(`/api/customers/${initialId}`),
    enabled: !!initialId && !value,
  });
  useEffect(() => {
    if (preset.data && !value) onChange(preset.data.customer);
  }, [preset.data, value, onChange]);

  const { data, isFetching } = useQuery({
    queryKey: ['customer-picker', term, onlyOnboarded],
    queryFn: () => api.get<Paginated<Customer>>('/api/customers', { q: term, per_page: 8, onboarding_status: onlyOnboarded ? 'completed' : undefined }),
    enabled: open && !value,
  });

  return (
    <Field label={label} error={error} required hint={onlyOnboarded ? 'Only customers who have completed onboarding are listed.' : undefined}>
      {(id) =>
        value ? (
          <div className="flex items-center justify-between gap-3 rounded-lg border border-slate-300 bg-slate-50 px-3 py-2">
            <div className="flex min-w-0 items-center gap-2">
              {value.type === 'business' ? <Building2 className="size-4 text-slate-400" /> : <User className="size-4 text-slate-400" />}
              <span className="truncate text-sm font-medium text-slate-900">{value.display_name}</span>
              <span className="text-xs text-slate-500">{value.customer_number}</span>
            </div>
            <button type="button" onClick={() => onChange(null)} className="rounded p-1 text-slate-400 hover:bg-slate-200 hover:text-slate-700" aria-label="Clear customer">
              <X className="size-4" />
            </button>
          </div>
        ) : (
          <div className="relative">
            <Search className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-slate-400" />
            <Input
              id={id}
              value={q}
              onChange={(e) => {
                setQ(e.target.value);
                setOpen(true);
              }}
              onFocus={() => setOpen(true)}
              onBlur={() => setTimeout(() => setOpen(false), 150)}
              placeholder="Search by name, number or phone"
              className="pl-9"
              invalid={!!error}
              autoComplete="off"
            />
            {open && (
              <div className="absolute left-0 right-0 top-11 z-30 max-h-72 overflow-y-auto rounded-xl border border-slate-200 bg-white py-1 shadow-[var(--shadow-pop)]">
                {isFetching && !data ? (
                  <div className="flex items-center gap-2 px-3 py-2 text-sm text-slate-500">
                    <Spinner className="size-4" /> Searching…
                  </div>
                ) : (data?.data ?? []).length === 0 ? (
                  <p className="px-3 py-2 text-sm text-slate-500">No matching customers.</p>
                ) : (
                  data!.data.map((c) => (
                    <button
                      type="button"
                      key={c.id}
                      onMouseDown={() => {
                        onChange(c);
                        setQ('');
                        setOpen(false);
                      }}
                      className="flex w-full items-center justify-between gap-3 px-3 py-2 text-left hover:bg-slate-50"
                    >
                      <span className="min-w-0">
                        <span className="block truncate text-sm font-medium text-slate-800">{c.display_name}</span>
                        <span className="block text-xs text-slate-500">
                          {c.customer_number} · {c.phone}
                        </span>
                      </span>
                      <StatusBadge status={c.status} />
                    </button>
                  ))
                )}
              </div>
            )}
          </div>
        )
      }
    </Field>
  );
}
