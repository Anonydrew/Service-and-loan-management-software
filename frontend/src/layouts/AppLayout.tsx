import { useEffect, useRef, useState } from 'react';
import { Link, NavLink, Outlet, useLocation, useNavigate } from 'react-router-dom';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { Bell, ChevronDown, KeyRound, LogOut, Menu, PanelLeftClose, PanelLeftOpen, Search, X } from 'lucide-react';
import { api } from '../lib/api';
import { useAuth } from '../lib/auth';
import { initials, relative } from '../lib/format';
import { useDebounced } from '../lib/hooks';
import { cx, Spinner } from '../components/ui';
import { NAVIGATION } from './navigation';
import logo from '../assets/logo.svg';

const COLLAPSE_KEY = 'slm.sidebar.collapsed';

function readCollapsed(): boolean {
  try {
    return localStorage.getItem(COLLAPSE_KEY) === '1';
  } catch {
    return false;
  }
}

export function AppLayout() {
  const { user, can } = useAuth();
  const [collapsed, setCollapsed] = useState(readCollapsed);
  const [mobileOpen, setMobileOpen] = useState(false);
  const location = useLocation();

  useEffect(() => setMobileOpen(false), [location.pathname]);
  useEffect(() => {
    try {
      localStorage.setItem(COLLAPSE_KEY, collapsed ? '1' : '0');
    } catch {
      /* storage unavailable */
    }
  }, [collapsed]);

  const groups = NAVIGATION.map((g) => ({ ...g, items: g.items.filter((i) => can(i.permission)) })).filter((g) => g.items.length > 0);

  const sidebar = (isMobile: boolean) => (
    <nav className="flex h-full flex-col" aria-label="Main">
      <div className={cx('flex h-16 shrink-0 items-center gap-3 px-6', collapsed && !isMobile && 'justify-center px-0')}>
        {(!collapsed || isMobile) && (
          <div className="min-w-0">
            <img src={logo} alt="DXCOVR" className="h-4 w-auto" />
            <p className="mt-1 truncate text-xs text-slate-500" title={user?.company.name}>
              {user?.company.name}
            </p>
          </div>
        )}
        {isMobile && (
          <button className="ml-auto rounded-lg p-1.5 text-slate-500 hover:bg-slate-100" onClick={() => setMobileOpen(false)} aria-label="Close menu">
            <X className="size-5" />
          </button>
        )}
      </div>
      <div className="flex-1 overflow-y-auto px-3 pb-4">
        {groups.map((group) => (
          <div key={group.label} className="mt-4 first:mt-1">
            {(!collapsed || isMobile) && <p className="mb-1 px-3 text-[11px] font-semibold uppercase tracking-wider text-slate-400">{group.label}</p>}
            <ul className="space-y-0.5">
              {group.items.map((item) => (
                <li key={item.to}>
                  <NavLink
                    to={item.to}
                    end={item.end}
                    title={collapsed && !isMobile ? item.label : undefined}
                    className={({ isActive }) =>
                      cx(
                        'group flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition-colors',
                        collapsed && !isMobile && 'justify-center px-0',
                        isActive ? 'bg-brand-50 text-brand-800' : 'text-slate-600 hover:bg-slate-100 hover:text-slate-900',
                      )
                    }
                  >
                    {({ isActive }) => (
                      <>
                        <item.icon className={cx('size-[18px] shrink-0', isActive ? 'text-brand-700' : 'text-slate-400 group-hover:text-slate-600')} />
                        {(!collapsed || isMobile) && <span className="truncate">{item.label}</span>}
                      </>
                    )}
                  </NavLink>
                </li>
              ))}
            </ul>
          </div>
        ))}
      </div>
      {!isMobile && (
        <div className="border-t border-slate-100 p-3">
          <button
            onClick={() => setCollapsed((c) => !c)}
            className={cx('flex w-full items-center gap-3 rounded-lg px-3 py-2 text-sm text-slate-500 hover:bg-slate-100', collapsed && 'justify-center px-0')}
            aria-label={collapsed ? 'Expand sidebar' : 'Collapse sidebar'}
          >
            {collapsed ? <PanelLeftOpen className="size-[18px]" /> : <PanelLeftClose className="size-[18px]" />}
            {!collapsed && 'Collapse'}
          </button>
        </div>
      )}
    </nav>
  );

  return (
    <div className="flex h-full">
      <aside className={cx('hidden shrink-0 border-r border-slate-200 bg-white transition-[width] duration-200 lg:block', collapsed ? 'w-[72px]' : 'w-64')}>{sidebar(false)}</aside>

      {mobileOpen && (
        <div className="fixed inset-0 z-40 lg:hidden">
          <div className="absolute inset-0 bg-slate-900/40" onClick={() => setMobileOpen(false)} />
          <aside className="animate-slide-in absolute inset-y-0 left-0 w-72 bg-white shadow-xl">{sidebar(true)}</aside>
        </div>
      )}

      <div className="flex min-w-0 flex-1 flex-col">
        <header className="sticky top-0 z-30 flex h-16 shrink-0 items-center gap-3 border-b border-slate-200 bg-white/90 px-4 backdrop-blur sm:px-6">
          <button className="rounded-lg p-2 text-slate-600 hover:bg-slate-100 lg:hidden" onClick={() => setMobileOpen(true)} aria-label="Open menu">
            <Menu className="size-5" />
          </button>
          <GlobalSearch />
          <div className="ml-auto flex items-center gap-1 sm:gap-2">
            <Notifications />
            <UserMenu />
          </div>
        </header>
        <main className="flex-1 overflow-y-auto">
          <div className="mx-auto w-full max-w-7xl px-4 py-6 sm:px-6 lg:py-8">
            <Outlet />
          </div>
        </main>
      </div>
    </div>
  );
}

interface SearchResult {
  type: string;
  id: number;
  title: string;
  subtitle: string;
  link: string;
}

function GlobalSearch() {
  const [q, setQ] = useState('');
  const [open, setOpen] = useState(false);
  const debounced = useDebounced(q, 250);
  const input = useRef<HTMLInputElement>(null);
  const navigate = useNavigate();

  const { data, isFetching } = useQuery({
    queryKey: ['search', debounced],
    queryFn: () => api.get<{ results: SearchResult[] }>('/api/search', { q: debounced }),
    enabled: debounced.trim().length >= 2,
  });

  useEffect(() => {
    const onKey = (e: KeyboardEvent) => {
      if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') {
        e.preventDefault();
        input.current?.focus();
      }
    };
    document.addEventListener('keydown', onKey);
    return () => document.removeEventListener('keydown', onKey);
  }, []);

  const results = debounced.trim().length >= 2 ? (data?.results ?? []) : [];
  const typeLabel: Record<string, string> = { customer: 'Customer', loan: 'Loan', invoice: 'Invoice', service_request: 'Service request' };

  return (
    <div className="relative w-full max-w-md">
      <Search className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-slate-400" />
      <input
        ref={input}
        value={q}
        onChange={(e) => {
          setQ(e.target.value);
          setOpen(true);
        }}
        onFocus={() => setOpen(true)}
        onBlur={() => setTimeout(() => setOpen(false), 150)}
        placeholder="Search customers, loans, invoices…"
        className="h-10 w-full rounded-xl border border-slate-200 bg-slate-50 pl-9 pr-14 text-sm placeholder:text-slate-400 focus:border-brand-500 focus:bg-white focus:outline-none focus:ring-2 focus:ring-brand-500/20"
        aria-label="Global search"
      />
      <kbd className="pointer-events-none absolute right-3 top-1/2 hidden -translate-y-1/2 rounded border border-slate-200 bg-white px-1.5 text-[10px] text-slate-400 sm:block">Ctrl K</kbd>
      {open && q.trim().length >= 2 && (
        <div className="absolute left-0 right-0 top-12 z-40 overflow-hidden rounded-xl border border-slate-200 bg-white shadow-[var(--shadow-pop)]">
          {isFetching && results.length === 0 ? (
            <div className="flex items-center gap-2 px-4 py-3 text-sm text-slate-500">
              <Spinner className="size-4" /> Searching…
            </div>
          ) : results.length === 0 ? (
            <p className="px-4 py-3 text-sm text-slate-500">No matches for “{q}”.</p>
          ) : (
            <ul className="max-h-80 overflow-y-auto py-1">
              {results.map((r) => (
                <li key={`${r.type}-${r.id}`}>
                  <button
                    onMouseDown={() => {
                      navigate(r.link);
                      setQ('');
                      setOpen(false);
                    }}
                    className="flex w-full items-center justify-between gap-3 px-4 py-2 text-left hover:bg-slate-50"
                  >
                    <span className="min-w-0">
                      <span className="block truncate text-sm font-medium text-slate-800">{r.title}</span>
                      <span className="block truncate text-xs text-slate-500">{r.subtitle}</span>
                    </span>
                    <span className="shrink-0 rounded-full bg-slate-100 px-2 py-0.5 text-[11px] text-slate-500">{typeLabel[r.type] ?? r.type}</span>
                  </button>
                </li>
              ))}
            </ul>
          )}
        </div>
      )}
    </div>
  );
}

interface NotificationRow {
  id: string;
  read_at: string | null;
  created_at: string;
  data: { title: string; body: string; link: string | null; category: string };
}

function Notifications() {
  const [open, setOpen] = useState(false);
  const queryClient = useQueryClient();
  const navigate = useNavigate();
  const ref = useRef<HTMLDivElement>(null);

  const { data } = useQuery({
    queryKey: ['notifications'],
    queryFn: () => api.get<{ unread_count: number; data: NotificationRow[] }>('/api/notifications', { limit: 15 }),
    refetchInterval: 60_000,
  });

  useEffect(() => {
    if (!open) return;
    const close = (e: MouseEvent) => !ref.current?.contains(e.target as Node) && setOpen(false);
    document.addEventListener('mousedown', close);
    return () => document.removeEventListener('mousedown', close);
  }, [open]);

  const markAll = async () => {
    await api.post('/api/notifications/read-all');
    queryClient.invalidateQueries({ queryKey: ['notifications'] });
  };

  const openItem = async (n: NotificationRow) => {
    if (!n.read_at) {
      await api.post(`/api/notifications/${n.id}/read`);
      queryClient.invalidateQueries({ queryKey: ['notifications'] });
    }
    setOpen(false);
    if (n.data.link) navigate(n.data.link);
  };

  const unread = data?.unread_count ?? 0;

  return (
    <div className="relative" ref={ref}>
      <button onClick={() => setOpen((o) => !o)} className="relative rounded-xl p-2 text-slate-600 hover:bg-slate-100" aria-label={`Notifications${unread ? `, ${unread} unread` : ''}`}>
        <Bell className="size-5" />
        {unread > 0 && <span className="absolute right-1 top-1 flex min-w-4 items-center justify-center rounded-full bg-red-500 px-1 text-[10px] font-semibold text-white">{unread > 9 ? '9+' : unread}</span>}
      </button>
      {open && (
        <div className="absolute right-0 top-12 z-40 w-[min(92vw,380px)] overflow-hidden rounded-xl border border-slate-200 bg-white shadow-[var(--shadow-pop)]">
          <div className="flex items-center justify-between border-b border-slate-100 px-4 py-3">
            <p className="text-sm font-semibold text-slate-900">Notifications</p>
            {unread > 0 && (
              <button onClick={markAll} className="text-xs font-medium text-brand-700 hover:underline">
                Mark all read
              </button>
            )}
          </div>
          {(data?.data ?? []).length === 0 ? (
            <p className="px-4 py-8 text-center text-sm text-slate-500">You're all caught up.</p>
          ) : (
            <ul className="max-h-96 divide-y divide-slate-100 overflow-y-auto">
              {data!.data.map((n) => (
                <li key={n.id}>
                  <button onClick={() => openItem(n)} className={cx('flex w-full gap-3 px-4 py-3 text-left hover:bg-slate-50', !n.read_at && 'bg-brand-50/40')}>
                    <span className={cx('mt-1.5 size-2 shrink-0 rounded-full', n.read_at ? 'bg-transparent' : 'bg-brand-600')} />
                    <span className="min-w-0">
                      <span className="block text-sm font-medium text-slate-800">{n.data.title}</span>
                      <span className="mt-0.5 block text-xs text-slate-500">{n.data.body}</span>
                      <span className="mt-1 block text-[11px] text-slate-400">{relative(n.created_at)}</span>
                    </span>
                  </button>
                </li>
              ))}
            </ul>
          )}
        </div>
      )}
    </div>
  );
}

function UserMenu() {
  const { user, logout } = useAuth();
  const [open, setOpen] = useState(false);
  const ref = useRef<HTMLDivElement>(null);
  const navigate = useNavigate();

  useEffect(() => {
    if (!open) return;
    const close = (e: MouseEvent) => !ref.current?.contains(e.target as Node) && setOpen(false);
    document.addEventListener('mousedown', close);
    return () => document.removeEventListener('mousedown', close);
  }, [open]);

  if (!user) return null;
  const branchLabel = user.all_branches ? 'All branches' : user.branches.map((b) => b.name).join(', ');

  return (
    <div className="relative" ref={ref}>
      <button onClick={() => setOpen((o) => !o)} className="flex items-center gap-2 rounded-xl p-1.5 hover:bg-slate-100" aria-haspopup="menu" aria-expanded={open}>
        <span className="flex size-8 items-center justify-center rounded-full bg-slate-800 text-xs font-semibold text-white">{initials(user.name)}</span>
        <span className="hidden text-left md:block">
          <span className="block max-w-36 truncate text-sm font-medium leading-tight text-slate-800">{user.name}</span>
          <span className="block max-w-36 truncate text-xs leading-tight text-slate-500">{user.roles[0] ?? user.job_title}</span>
        </span>
        <ChevronDown className="hidden size-4 text-slate-400 md:block" />
      </button>
      {open && (
        <div role="menu" className="absolute right-0 top-12 z-40 w-64 overflow-hidden rounded-xl border border-slate-200 bg-white py-1 shadow-[var(--shadow-pop)]">
          <div className="border-b border-slate-100 px-4 py-3">
            <p className="truncate text-sm font-medium text-slate-900">{user.name}</p>
            <p className="truncate text-xs text-slate-500">{user.email}</p>
            <p className="mt-1 truncate text-xs text-slate-500">{branchLabel}</p>
          </div>
          <Link to="/account" role="menuitem" onClick={() => setOpen(false)} className="flex items-center gap-2 px-4 py-2 text-sm text-slate-700 hover:bg-slate-50">
            <KeyRound className="size-4 text-slate-400" /> Change password
          </Link>
          <button
            role="menuitem"
            onClick={async () => {
              await logout();
              navigate('/login');
            }}
            className="flex w-full items-center gap-2 px-4 py-2 text-left text-sm text-red-600 hover:bg-red-50"
          >
            <LogOut className="size-4" /> Sign out
          </button>
        </div>
      )}
    </div>
  );
}
