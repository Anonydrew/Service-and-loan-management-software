import {
  forwardRef,
  useId,
  type ButtonHTMLAttributes,
  type InputHTMLAttributes,
  type ReactNode,
  type SelectHTMLAttributes,
  type TextareaHTMLAttributes,
} from 'react';
import { Link } from 'react-router-dom';
import { CircleAlert, Inbox, LoaderCircle, RefreshCw } from 'lucide-react';
import { label as humanize } from '../lib/format';

function cx(...parts: (string | false | null | undefined)[]): string {
  return parts.filter(Boolean).join(' ');
}
export { cx };

/* ------------------------------------------------------------------ Button */

type Variant = 'primary' | 'secondary' | 'ghost' | 'danger' | 'soft';
type Size = 'sm' | 'md';

const variants: Record<Variant, string> = {
  primary: 'bg-brand-700 text-white hover:bg-brand-800 shadow-sm disabled:bg-brand-700/50',
  secondary: 'bg-white text-slate-700 ring-1 ring-inset ring-slate-200 hover:bg-slate-50 disabled:text-slate-400',
  ghost: 'text-slate-600 hover:bg-slate-100 hover:text-slate-900 disabled:text-slate-300',
  danger: 'bg-red-600 text-white hover:bg-red-700 shadow-sm disabled:bg-red-600/50',
  soft: 'bg-brand-50 text-brand-800 hover:bg-brand-100 disabled:opacity-50',
};

interface ButtonProps extends ButtonHTMLAttributes<HTMLButtonElement> {
  variant?: Variant;
  size?: Size;
  loading?: boolean;
  icon?: ReactNode;
}

export const Button = forwardRef<HTMLButtonElement, ButtonProps>(function Button(
  { variant = 'primary', size = 'md', loading, icon, className, children, disabled, type = 'button', ...rest },
  ref,
) {
  return (
    <button
      ref={ref}
      type={type}
      disabled={disabled || loading}
      className={cx(
        'inline-flex shrink-0 items-center justify-center gap-2 whitespace-nowrap rounded-lg font-medium transition-colors disabled:cursor-not-allowed',
        size === 'sm' ? 'h-8 px-3 text-xs' : 'h-10 px-4 text-sm',
        variants[variant],
        className,
      )}
      {...rest}
    >
      {loading ? <LoaderCircle className="size-4 animate-spin" /> : icon}
      {children}
    </button>
  );
});

export function ButtonLink({ to, variant = 'primary', icon, children, className }: { to: string; variant?: Variant; icon?: ReactNode; children: ReactNode; className?: string }) {
  return (
    <Link to={to} className={cx('inline-flex h-10 items-center justify-center gap-2 rounded-lg px-4 text-sm font-medium transition-colors', variants[variant], className)}>
      {icon}
      {children}
    </Link>
  );
}

/* -------------------------------------------------------------------- Card */

export function Card({ children, className, padded = true }: { children: ReactNode; className?: string; padded?: boolean }) {
  return <div className={cx('rounded-[var(--radius-card)] border border-slate-200/80 bg-white shadow-[var(--shadow-card)]', padded && 'p-5', className)}>{children}</div>;
}

export function CardHeader({ title, description, actions }: { title: ReactNode; description?: ReactNode; actions?: ReactNode }) {
  return (
    <div className="mb-4 flex flex-wrap items-start justify-between gap-3">
      <div>
        <h3 className="text-[15px] font-semibold text-slate-900">{title}</h3>
        {description && <p className="mt-0.5 text-sm text-slate-500">{description}</p>}
      </div>
      {actions && <div className="flex flex-wrap items-center gap-2">{actions}</div>}
    </div>
  );
}

/* -------------------------------------------------------------- PageHeader */

export function PageHeader({ title, description, actions, back }: { title: ReactNode; description?: ReactNode; actions?: ReactNode; back?: ReactNode }) {
  return (
    <div className="mb-6">
      {back && <div className="mb-2">{back}</div>}
      <div className="flex flex-wrap items-end justify-between gap-4">
        <div className="min-w-0">
          <h1 className="text-2xl font-semibold tracking-tight text-slate-900">{title}</h1>
          {description && <p className="mt-1 text-sm text-slate-500">{description}</p>}
        </div>
        {actions && <div className="flex flex-wrap items-center gap-2">{actions}</div>}
      </div>
    </div>
  );
}

/* ------------------------------------------------------------------- Badge */

export type Tone = 'neutral' | 'green' | 'amber' | 'red' | 'blue' | 'violet';

const tones: Record<Tone, string> = {
  neutral: 'bg-slate-100 text-slate-700 ring-slate-200',
  green: 'bg-emerald-50 text-emerald-700 ring-emerald-200',
  amber: 'bg-amber-50 text-amber-800 ring-amber-200',
  red: 'bg-red-50 text-red-700 ring-red-200',
  blue: 'bg-sky-50 text-sky-700 ring-sky-200',
  violet: 'bg-violet-50 text-violet-700 ring-violet-200',
};

export function Badge({ tone = 'neutral', children, dot }: { tone?: Tone; children: ReactNode; dot?: boolean }) {
  return (
    <span className={cx('inline-flex items-center gap-1.5 whitespace-nowrap rounded-full px-2.5 py-0.5 text-xs font-medium ring-1 ring-inset', tones[tone])}>
      {dot && <span className="size-1.5 rounded-full bg-current" />}
      {children}
    </span>
  );
}

const statusTones: Record<string, Tone> = {
  active: 'green', verified: 'green', completed: 'green', paid: 'green', approved: 'green', closed: 'neutral', posted: 'green',
  pass: 'green', complete: 'green', granted: 'green', recorded: 'green',
  pending: 'amber', in_progress: 'blue', submitted: 'blue', scheduled: 'violet', partially_paid: 'amber', partial: 'amber',
  pending_disbursement: 'violet', manual_review: 'amber', draft: 'neutral', not_started: 'neutral', new: 'blue', on_hold: 'amber',
  warn: 'amber', issued: 'blue', partially_refunded: 'amber', incomplete: 'neutral',
  failed: 'red', rejected: 'red', in_arrears: 'red', written_off: 'red', cancelled: 'neutral', void: 'neutral',
  reversed: 'neutral', suspended: 'red', refunded: 'neutral', fail: 'red', overdue: 'red', disabled: 'neutral', withdrawn: 'neutral',
  urgent: 'red', high: 'amber', normal: 'neutral', low: 'neutral', disbursed: 'green',
};

export function StatusBadge({ status, label }: { status: string | null | undefined; label?: string }) {
  if (!status) return <span className="text-slate-400">—</span>;
  return (
    <Badge tone={statusTones[status] ?? 'neutral'} dot>
      {label ?? humanize(status)}
    </Badge>
  );
}

/* ------------------------------------------------------------------ Fields */

interface FieldProps {
  label?: ReactNode;
  error?: string;
  hint?: ReactNode;
  required?: boolean;
  children: (id: string) => ReactNode;
  className?: string;
}

export function Field({ label, error, hint, required, children, className }: FieldProps) {
  const id = useId();
  return (
    <div className={cx('flex flex-col gap-1.5', className)}>
      {label && (
        <label htmlFor={id} className="text-sm font-medium text-slate-700">
          {label}
          {required && (
            <span aria-hidden="true" className="ml-0.5 text-red-500">
              *
            </span>
          )}
        </label>
      )}
      {children(id)}
      {error ? (
        <p className="text-xs text-red-600" role="alert">
          {error}
        </p>
      ) : (
        hint && <p className="text-xs text-slate-500">{hint}</p>
      )}
    </div>
  );
}

const control =
  'w-full rounded-lg border bg-white px-3 text-sm text-slate-900 placeholder:text-slate-400 transition-colors focus:outline-none focus:ring-2 focus:ring-brand-500/30 disabled:bg-slate-50 disabled:text-slate-500';

export const Input = forwardRef<HTMLInputElement, InputHTMLAttributes<HTMLInputElement> & { invalid?: boolean }>(function Input({ className, invalid, ...rest }, ref) {
  return <input ref={ref} className={cx(control, 'h-10', invalid ? 'border-red-400 focus:border-red-500' : 'border-slate-300 focus:border-brand-500', className)} aria-invalid={invalid || undefined} {...rest} />;
});

export const Select = forwardRef<HTMLSelectElement, SelectHTMLAttributes<HTMLSelectElement> & { invalid?: boolean }>(function Select({ className, invalid, children, ...rest }, ref) {
  return (
    <select ref={ref} className={cx(control, 'h-10 pr-8', invalid ? 'border-red-400' : 'border-slate-300 focus:border-brand-500', className)} aria-invalid={invalid || undefined} {...rest}>
      {children}
    </select>
  );
});

export const Textarea = forwardRef<HTMLTextAreaElement, TextareaHTMLAttributes<HTMLTextAreaElement> & { invalid?: boolean }>(function Textarea({ className, invalid, ...rest }, ref) {
  return <textarea ref={ref} className={cx(control, 'min-h-[84px] py-2', invalid ? 'border-red-400' : 'border-slate-300 focus:border-brand-500', className)} aria-invalid={invalid || undefined} {...rest} />;
});

/** Text field bound to a form: label, control and server error in one. */
export function TextField({
  label,
  value,
  onChange,
  error,
  hint,
  required,
  type = 'text',
  className,
  ...rest
}: Omit<InputHTMLAttributes<HTMLInputElement>, 'onChange' | 'value'> & {
  label: ReactNode;
  value: string | number | null | undefined;
  onChange: (v: string) => void;
  error?: string;
  hint?: ReactNode;
}) {
  return (
    <Field label={label} error={error} hint={hint} required={required} className={className}>
      {(id) => <Input id={id} type={type} value={value ?? ''} onChange={(e) => onChange(e.target.value)} invalid={!!error} required={required} {...rest} />}
    </Field>
  );
}

export function SelectField({
  label,
  value,
  onChange,
  options,
  error,
  hint,
  required,
  placeholder,
  className,
  disabled,
}: {
  label: ReactNode;
  value: string | number | null | undefined;
  onChange: (v: string) => void;
  options: (string | { value: string | number; label: string })[];
  error?: string;
  hint?: ReactNode;
  required?: boolean;
  placeholder?: string;
  className?: string;
  disabled?: boolean;
}) {
  return (
    <Field label={label} error={error} hint={hint} required={required} className={className}>
      {(id) => (
        <Select id={id} value={value ?? ''} onChange={(e) => onChange(e.target.value)} invalid={!!error} required={required} disabled={disabled}>
          {placeholder !== undefined && <option value="">{placeholder}</option>}
          {options.map((o) =>
            typeof o === 'string' ? (
              <option key={o} value={o}>
                {humanize(o)}
              </option>
            ) : (
              <option key={o.value} value={o.value}>
                {o.label}
              </option>
            ),
          )}
        </Select>
      )}
    </Field>
  );
}

export function Checkbox({ checked, onChange, label, description }: { checked: boolean; onChange: (v: boolean) => void; label: ReactNode; description?: ReactNode }) {
  const id = useId();
  return (
    <label htmlFor={id} className="flex cursor-pointer items-start gap-3">
      <input id={id} type="checkbox" checked={checked} onChange={(e) => onChange(e.target.checked)} className="mt-0.5 size-4 rounded border-slate-300 accent-brand-700" />
      <span>
        <span className="text-sm font-medium text-slate-800">{label}</span>
        {description && <span className="mt-0.5 block text-xs text-slate-500">{description}</span>}
      </span>
    </label>
  );
}

/* ---------------------------------------------------- Loading/empty/error */

export function Spinner({ className }: { className?: string }) {
  return <LoaderCircle className={cx('size-5 animate-spin text-brand-600', className)} aria-label="Loading" />;
}

export function Skeleton({ className }: { className?: string }) {
  return <div className={cx('animate-pulse rounded-md bg-slate-200/70', className)} />;
}

export function LoadingBlock({ rows = 4 }: { rows?: number }) {
  return (
    <div className="space-y-3 p-1" aria-busy="true">
      {Array.from({ length: rows }).map((_, i) => (
        <Skeleton key={i} className="h-10 w-full" />
      ))}
    </div>
  );
}

export function EmptyState({ title, description, action, icon }: { title: string; description?: ReactNode; action?: ReactNode; icon?: ReactNode }) {
  return (
    <div className="flex flex-col items-center justify-center px-6 py-12 text-center">
      <div className="mb-3 flex size-12 items-center justify-center rounded-full bg-slate-100 text-slate-400">{icon ?? <Inbox className="size-6" />}</div>
      <p className="font-medium text-slate-800">{title}</p>
      {description && <p className="mt-1 max-w-sm text-sm text-slate-500">{description}</p>}
      {action && <div className="mt-4">{action}</div>}
    </div>
  );
}

export function ErrorState({ message, onRetry }: { message: string; onRetry?: () => void }) {
  return (
    <div className="flex flex-col items-center justify-center px-6 py-10 text-center" role="alert">
      <div className="mb-3 flex size-12 items-center justify-center rounded-full bg-red-50 text-red-500">
        <CircleAlert className="size-6" />
      </div>
      <p className="font-medium text-slate-800">Couldn't load this</p>
      <p className="mt-1 max-w-sm text-sm text-slate-500">{message}</p>
      {onRetry && (
        <Button variant="secondary" size="sm" className="mt-4" icon={<RefreshCw className="size-3.5" />} onClick={onRetry}>
          Try again
        </Button>
      )}
    </div>
  );
}

export function Alert({ tone = 'blue', title, children, icon }: { tone?: Tone; title?: ReactNode; children?: ReactNode; icon?: ReactNode }) {
  const styles: Record<Tone, string> = {
    blue: 'border-sky-200 bg-sky-50 text-sky-900',
    amber: 'border-amber-200 bg-amber-50 text-amber-900',
    red: 'border-red-200 bg-red-50 text-red-900',
    green: 'border-emerald-200 bg-emerald-50 text-emerald-900',
    neutral: 'border-slate-200 bg-slate-50 text-slate-800',
    violet: 'border-violet-200 bg-violet-50 text-violet-900',
  };
  return (
    <div className={cx('flex gap-3 rounded-xl border px-4 py-3 text-sm', styles[tone])}>
      {icon && <div className="mt-0.5 shrink-0">{icon}</div>}
      <div className="min-w-0">
        {title && <p className="font-semibold">{title}</p>}
        {children && <div className={cx(title ? 'mt-1' : '', 'opacity-90')}>{children}</div>}
      </div>
    </div>
  );
}

/* ------------------------------------------------------------- Stat card */

export function Stat({ label, value, hint, icon, tone = 'green', to }: { label: string; value: ReactNode; hint?: ReactNode; icon?: ReactNode; tone?: Tone; to?: string }) {
  const iconTone: Record<Tone, string> = {
    green: 'bg-brand-50 text-brand-700',
    blue: 'bg-sky-50 text-sky-700',
    amber: 'bg-amber-50 text-amber-700',
    red: 'bg-red-50 text-red-600',
    violet: 'bg-violet-50 text-violet-700',
    neutral: 'bg-slate-100 text-slate-600',
  };
  const body = (
    <Card className={cx('h-full', to && 'transition-shadow hover:shadow-md')}>
      <div className="flex items-start justify-between gap-3">
        <div className="min-w-0">
          <p className="text-sm text-slate-500">{label}</p>
          <p className="tabular mt-2 truncate text-2xl font-semibold tracking-tight text-slate-900">{value}</p>
          {hint && <p className="mt-1 text-xs text-slate-500">{hint}</p>}
        </div>
        {icon && <div className={cx('flex size-10 shrink-0 items-center justify-center rounded-xl', iconTone[tone])}>{icon}</div>}
      </div>
    </Card>
  );
  return to ? (
    <Link to={to} className="block">
      {body}
    </Link>
  ) : (
    body
  );
}

/* ------------------------------------------------------------------- Tabs */

export function Tabs<T extends string>({ tabs, value, onChange }: { tabs: { value: T; label: ReactNode; count?: number }[]; value: T; onChange: (v: T) => void }) {
  return (
    <div className="-mx-1 mb-5 overflow-x-auto border-b border-slate-200">
      <div className="flex min-w-max gap-1 px-1" role="tablist">
        {tabs.map((t) => (
          <button
            key={t.value}
            role="tab"
            aria-selected={value === t.value}
            onClick={() => onChange(t.value)}
            className={cx(
              '-mb-px inline-flex items-center gap-2 border-b-2 px-3 py-2.5 text-sm font-medium transition-colors',
              value === t.value ? 'border-brand-700 text-brand-800' : 'border-transparent text-slate-500 hover:text-slate-800',
            )}
          >
            {t.label}
            {t.count !== undefined && <span className="rounded-full bg-slate-100 px-1.5 text-xs text-slate-600">{t.count}</span>}
          </button>
        ))}
      </div>
    </div>
  );
}

/* --------------------------------------------------------- Description list */

export function DescriptionList({ items, columns = 2 }: { items: { label: ReactNode; value: ReactNode; hidden?: boolean }[]; columns?: 1 | 2 | 3 }) {
  return (
    <dl className={cx('grid gap-x-6 gap-y-4', columns === 1 ? 'grid-cols-1' : columns === 2 ? 'grid-cols-1 sm:grid-cols-2' : 'grid-cols-1 sm:grid-cols-2 lg:grid-cols-3')}>
      {items
        .filter((i) => !i.hidden)
        .map((item, idx) => (
          <div key={idx} className="min-w-0">
            <dt className="text-xs font-medium uppercase tracking-wide text-slate-500">{item.label}</dt>
            <dd className="mt-1 break-words text-sm text-slate-900">{item.value === null || item.value === undefined || item.value === '' ? <span className="text-slate-400">—</span> : item.value}</dd>
          </div>
        ))}
    </dl>
  );
}

export function SectionTitle({ children }: { children: ReactNode }) {
  return <h4 className="mb-3 text-xs font-semibold uppercase tracking-wide text-slate-500">{children}</h4>;
}
