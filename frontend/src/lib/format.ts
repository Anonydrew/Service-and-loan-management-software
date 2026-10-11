const moneyFmt = new Intl.NumberFormat('en-GH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const compactFmt = new Intl.NumberFormat('en-GH', { notation: 'compact', maximumFractionDigits: 1 });

/** Minor units (pesewas) to "GHS 1,234.56". */
export function money(minor: number | null | undefined, currency = 'GHS'): string {
  if (minor === null || minor === undefined) return '—';
  const sign = minor < 0 ? '-' : '';
  return `${sign}${currency} ${moneyFmt.format(Math.abs(minor) / 100)}`;
}

export function moneyCompact(minor: number, currency = 'GHS'): string {
  return `${currency} ${compactFmt.format(minor / 100)}`;
}

/** Minor units to a plain decimal string for form inputs. */
export function toMajor(minor: number | null | undefined): string {
  if (minor === null || minor === undefined) return '';
  return (minor / 100).toFixed(2);
}

export function date(value: string | null | undefined): string {
  if (!value) return '—';
  const d = new Date(value.length === 10 ? `${value}T00:00:00` : value);
  return d.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' });
}

export function dateTime(value: string | null | undefined): string {
  if (!value) return '—';
  return new Date(value).toLocaleString('en-GB', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' });
}

export function relative(value: string | null | undefined): string {
  if (!value) return '';
  const diff = (Date.now() - new Date(value).getTime()) / 1000;
  if (diff < 60) return 'just now';
  if (diff < 3600) return `${Math.floor(diff / 60)}m ago`;
  if (diff < 86400) return `${Math.floor(diff / 3600)}h ago`;
  if (diff < 604800) return `${Math.floor(diff / 86400)}d ago`;
  return date(value);
}

/** "pending_disbursement" -> "Pending disbursement" */
export function label(value: string | null | undefined): string {
  if (!value) return '—';
  const s = value.replace(/[_-]+/g, ' ');
  return s.charAt(0).toUpperCase() + s.slice(1);
}

export function today(): string {
  const d = new Date();
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

export function monthStart(): string {
  return today().slice(0, 8) + '01';
}

export function initials(name: string): string {
  return name
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 2)
    .map((p) => p[0]?.toUpperCase())
    .join('');
}
