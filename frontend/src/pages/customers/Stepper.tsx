import { Check } from 'lucide-react';
import { cx } from '../../components/ui';

export type StepKey = 'details' | 'consent' | 'documents' | 'verification' | 'review';

export const ONBOARDING_STEPS: { key: StepKey; label: string; hint: string }[] = [
  { key: 'details', label: 'Details', hint: 'Who the customer is' },
  { key: 'consent', label: 'Consent', hint: 'Data & biometric consent' },
  { key: 'documents', label: 'Documents', hint: 'ID & registration' },
  { key: 'verification', label: 'Identity check', hint: 'Face / fingerprint' },
  { key: 'review', label: 'Review', hint: 'Complete or refer' },
];

export function Stepper({
  steps,
  current,
  statuses,
  onSelect,
}: {
  steps: typeof ONBOARDING_STEPS;
  current: StepKey;
  statuses: Partial<Record<StepKey, 'complete' | 'incomplete' | 'optional'>>;
  onSelect?: (key: StepKey) => void;
}) {
  return (
    <ol className="grid grid-cols-5 gap-2" aria-label="Onboarding progress">
      {steps.map((s, i) => {
        const done = statuses[s.key] === 'complete';
        const active = s.key === current;
        return (
          <li key={s.key}>
            <button
              type="button"
              disabled={!onSelect}
              onClick={() => onSelect?.(s.key)}
              aria-current={active ? 'step' : undefined}
              className={cx('group flex w-full flex-col gap-2 text-left', onSelect && 'cursor-pointer')}
            >
              <span className={cx('h-1.5 w-full rounded-full transition-colors', done ? 'bg-brand-600' : active ? 'bg-brand-300' : 'bg-slate-200')} />
              <span className="flex items-center gap-2">
                <span
                  className={cx(
                    'flex size-6 shrink-0 items-center justify-center rounded-full text-xs font-semibold',
                    done ? 'bg-brand-600 text-white' : active ? 'bg-brand-100 text-brand-800 ring-1 ring-brand-600' : 'bg-slate-100 text-slate-500',
                  )}
                >
                  {done ? <Check className="size-3.5" /> : i + 1}
                </span>
                <span className="hidden min-w-0 sm:block">
                  <span className={cx('block truncate text-sm font-medium', active ? 'text-slate-900' : 'text-slate-600')}>{s.label}</span>
                  <span className="hidden truncate text-xs text-slate-400 lg:block">{statuses[s.key] === 'optional' ? 'Optional' : s.hint}</span>
                </span>
              </span>
            </button>
          </li>
        );
      })}
    </ol>
  );
}
