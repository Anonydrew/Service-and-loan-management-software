import { createContext, useCallback, useContext, useEffect, useMemo, useState, type ReactNode } from 'react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { api, ApiError, resetCsrf, SESSION_EXPIRED_EVENT } from './api';
import type { Me } from './types';

interface AuthState {
  user: Me | null;
  loading: boolean;
  sessionNotice: string | null;
  login: (email: string, password: string) => Promise<void>;
  logout: () => Promise<void>;
  /** True if the user holds the permission (or any of them, for an array). */
  can: (permission: string | string[]) => boolean;
}

const AuthContext = createContext<AuthState | null>(null);

export function AuthProvider({ children }: { children: ReactNode }) {
  const queryClient = useQueryClient();
  const [sessionNotice, setSessionNotice] = useState<string | null>(null);

  const { data, isLoading } = useQuery({
    queryKey: ['me'],
    queryFn: async () => {
      try {
        return (await api.get<{ user: Me }>('/api/auth/me')).user;
      } catch (e) {
        if (e instanceof ApiError && e.status === 401) return null;
        throw e;
      }
    },
    retry: false,
    staleTime: 5 * 60_000,
  });

  useEffect(() => {
    const onExpired = (event: Event) => {
      const reason = (event as CustomEvent<string | undefined>).detail;
      setSessionNotice(reason === 'account_disabled' ? 'Your account has been disabled.' : 'Your session has ended. Please sign in again.');
      resetCsrf();
      queryClient.setQueryData(['me'], null);
      queryClient.removeQueries({ predicate: (q) => q.queryKey[0] !== 'me' });
    };
    window.addEventListener(SESSION_EXPIRED_EVENT, onExpired);
    return () => window.removeEventListener(SESSION_EXPIRED_EVENT, onExpired);
  }, [queryClient]);

  // The ['me'] query is observed by this provider for the app's lifetime, so it
  // is only ever updated in place. Clearing it (queryClient.clear()) would
  // detach the observer and leave the app reporting "signed out" after a
  // successful sign-in.
  const resetOtherQueries = useCallback(() => queryClient.removeQueries({ predicate: (q) => q.queryKey[0] !== 'me' }), [queryClient]);

  const login = useCallback(
    async (email: string, password: string) => {
      const res = await api.post<{ user: Me }>('/api/auth/login', { email, password });
      setSessionNotice(null);
      resetCsrf();
      resetOtherQueries();
      queryClient.setQueryData(['me'], res.user);
    },
    [queryClient, resetOtherQueries],
  );

  const logout = useCallback(async () => {
    try {
      await api.post('/api/auth/logout');
    } finally {
      resetCsrf();
      queryClient.setQueryData(['me'], null);
      resetOtherQueries();
    }
  }, [queryClient, resetOtherQueries]);

  const value = useMemo<AuthState>(() => {
    const permissions = new Set(data?.permissions ?? []);
    return {
      user: data ?? null,
      loading: isLoading,
      sessionNotice,
      login,
      logout,
      can: (p) => (Array.isArray(p) ? p.some((x) => permissions.has(x)) : permissions.has(p)),
    };
  }, [data, isLoading, sessionNotice, login, logout]);

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}

export function useAuth(): AuthState {
  const ctx = useContext(AuthContext);
  if (!ctx) throw new Error('useAuth must be used inside AuthProvider');
  return ctx;
}
