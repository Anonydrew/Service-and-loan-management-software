import { useCallback, useEffect, useMemo, useState } from 'react';
import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useSearchParams } from 'react-router-dom';
import { api, ApiError, errorMessage, type Query } from './api';
import type { Lookups, Paginated } from './types';
import { useToast } from '../components/Toast';

export function useLookups() {
  return useQuery({ queryKey: ['lookups'], queryFn: () => api.get<Lookups>('/api/lookups'), staleTime: 5 * 60_000 });
}

/** Filters and page number kept in the URL, so lists are linkable and survive reloads. */
export function useUrlFilters(defaults: Record<string, string> = {}) {
  const [params, setParams] = useSearchParams();

  const filters = useMemo(() => {
    const out: Record<string, string> = { ...defaults };
    params.forEach((v, k) => {
      out[k] = v;
    });
    return out;
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [params]);

  const set = useCallback(
    (key: string, value: string | null) => {
      setParams(
        (prev) => {
          const next = new URLSearchParams(prev);
          if (value === null || value === '') next.delete(key);
          else next.set(key, value);
          if (key !== 'page') next.delete('page');
          return next;
        },
        { replace: true },
      );
    },
    [setParams],
  );

  return { filters, set };
}

export function usePaginated<T>(path: string, query: Query, enabled = true) {
  return useQuery({
    queryKey: [path, query],
    queryFn: () => api.get<Paginated<T>>(path, query),
    placeholderData: keepPreviousData,
    enabled,
  });
}

export function useDebounced<T>(value: T, delay = 300): T {
  const [debounced, setDebounced] = useState(value);
  useEffect(() => {
    const t = setTimeout(() => setDebounced(value), delay);
    return () => clearTimeout(t);
  }, [value, delay]);
  return debounced;
}

/** Minimal form state with server-side validation errors mapped onto fields. */
export function useForm<T extends Record<string, unknown>>(initial: T) {
  const [values, setValues] = useState<T>(initial);
  const [errors, setErrors] = useState<Record<string, string>>({});

  const set = useCallback(<K extends keyof T>(key: K, value: T[K]) => {
    setValues((v) => ({ ...v, [key]: value }));
    setErrors((e) => {
      if (!e[key as string]) return e;
      const next = { ...e };
      delete next[key as string];
      return next;
    });
  }, []);

  const absorb = useCallback((error: unknown) => {
    if (error instanceof ApiError && Object.keys(error.fieldErrors).length > 0) {
      setErrors(Object.fromEntries(Object.entries(error.fieldErrors).map(([k, v]) => [k, v[0]])));
    }
  }, []);

  return { values, set, setValues, errors, setErrors, absorb };
}

interface ActionOptions<TVars, TResult> {
  fn: (vars: TVars) => Promise<TResult>;
  success?: string | ((result: TResult) => string);
  invalidate?: unknown[][];
  onSuccess?: (result: TResult) => void;
  onError?: (error: unknown) => void;
}

/** A mutation that toasts its outcome and refreshes affected queries. */
export function useAction<TVars = void, TResult = unknown>(opts: ActionOptions<TVars, TResult>) {
  const queryClient = useQueryClient();
  const toast = useToast();

  return useMutation({
    mutationFn: opts.fn,
    onSuccess: (result) => {
      if (opts.success) toast.success(typeof opts.success === 'function' ? opts.success(result) : opts.success);
      (opts.invalidate ?? []).forEach((key) => queryClient.invalidateQueries({ queryKey: key }));
      opts.onSuccess?.(result);
    },
    onError: (error) => {
      toast.error(errorMessage(error));
      opts.onError?.(error);
    },
  });
}
