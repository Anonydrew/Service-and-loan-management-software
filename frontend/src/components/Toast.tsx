import { createContext, useCallback, useContext, useMemo, useState, type ReactNode } from 'react';
import { CircleCheck, CircleX, Info, X } from 'lucide-react';

type Tone = 'success' | 'error' | 'info';
interface ToastItem {
  id: number;
  tone: Tone;
  text: string;
}

interface ToastApi {
  success: (text: string) => void;
  error: (text: string) => void;
  info: (text: string) => void;
}

const ToastContext = createContext<ToastApi | null>(null);
let nextId = 1;

export function ToastProvider({ children }: { children: ReactNode }) {
  const [items, setItems] = useState<ToastItem[]>([]);

  const dismiss = useCallback((id: number) => setItems((all) => all.filter((t) => t.id !== id)), []);

  const push = useCallback(
    (tone: Tone, text: string) => {
      const id = nextId++;
      setItems((all) => [...all.slice(-3), { id, tone, text }]);
      setTimeout(() => dismiss(id), tone === 'error' ? 7000 : 4000);
    },
    [dismiss],
  );

  const api = useMemo<ToastApi>(
    () => ({ success: (t) => push('success', t), error: (t) => push('error', t), info: (t) => push('info', t) }),
    [push],
  );

  const icon = { success: CircleCheck, error: CircleX, info: Info };
  const tone = {
    success: 'border-emerald-200 bg-white text-emerald-800',
    error: 'border-red-200 bg-white text-red-800',
    info: 'border-sky-200 bg-white text-sky-800',
  };

  return (
    <ToastContext.Provider value={api}>
      {children}
      <div className="pointer-events-none fixed inset-x-4 bottom-4 z-[60] flex flex-col items-end gap-2 sm:inset-x-auto sm:right-4" aria-live="polite">
        {items.map((t) => {
          const Icon = icon[t.tone];
          return (
            <div
              key={t.id}
              role={t.tone === 'error' ? 'alert' : 'status'}
              className={`animate-slide-in pointer-events-auto flex w-full max-w-sm items-start gap-3 rounded-xl border px-4 py-3 text-sm shadow-[var(--shadow-pop)] ${tone[t.tone]}`}
            >
              <Icon className="mt-0.5 size-4 shrink-0" />
              <p className="flex-1 text-slate-700">{t.text}</p>
              <button onClick={() => dismiss(t.id)} className="text-slate-400 hover:text-slate-600" aria-label="Dismiss">
                <X className="size-4" />
              </button>
            </div>
          );
        })}
      </div>
    </ToastContext.Provider>
  );
}

export function useToast(): ToastApi {
  const ctx = useContext(ToastContext);
  if (!ctx) throw new Error('useToast must be used inside ToastProvider');
  return ctx;
}
