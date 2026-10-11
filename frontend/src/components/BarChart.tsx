import { moneyCompact, money } from '../lib/format';

interface Series {
  key: string;
  label: string;
  color: string;
}

/** Grouped vertical bars for money values over a few periods. Dependency-free SVG. */
export function BarChart({ data, series, categoryKey, height = 220 }: { data: Record<string, number | string>[]; series: Series[]; categoryKey: string; height?: number }) {
  const max = Math.max(1, ...data.flatMap((d) => series.map((s) => Number(d[s.key]) || 0)));
  const nice = niceCeil(max);
  const ticks = [0, 0.25, 0.5, 0.75, 1].map((t) => t * nice);
  const left = 64;
  const bottom = 28;
  const width = 640;
  const plotH = height - bottom - 8;
  const groupW = (width - left) / Math.max(1, data.length);
  const barW = Math.min(26, (groupW - 16) / series.length);

  return (
    <div>
      <svg viewBox={`0 0 ${width} ${height}`} className="h-auto w-full" role="img" aria-label="Chart">
        {ticks.map((t) => {
          const y = 8 + plotH - (t / nice) * plotH;
          return (
            <g key={t}>
              <line x1={left} x2={width} y1={y} y2={y} stroke="#e2e8f0" strokeDasharray={t === 0 ? undefined : '3 4'} />
              <text x={left - 8} y={y + 4} textAnchor="end" className="fill-slate-400" fontSize="11">
                {moneyCompact(t)}
              </text>
            </g>
          );
        })}
        {data.map((d, i) => {
          const gx = left + i * groupW + (groupW - barW * series.length - 4 * (series.length - 1)) / 2;
          return (
            <g key={String(d[categoryKey])}>
              {series.map((s, j) => {
                const v = Number(d[s.key]) || 0;
                const h = (v / nice) * plotH;
                return (
                  <rect key={s.key} x={gx + j * (barW + 4)} y={8 + plotH - h} width={barW} height={Math.max(0, h)} rx={4} fill={s.color}>
                    <title>{`${s.label} ${d[categoryKey]}: ${money(v)}`}</title>
                  </rect>
                );
              })}
              <text x={left + i * groupW + groupW / 2} y={height - 8} textAnchor="middle" className="fill-slate-500" fontSize="11">
                {formatMonth(String(d[categoryKey]))}
              </text>
            </g>
          );
        })}
      </svg>
      <div className="mt-2 flex flex-wrap gap-4 text-xs text-slate-600">
        {series.map((s) => (
          <span key={s.key} className="inline-flex items-center gap-1.5">
            <span className="size-2.5 rounded-sm" style={{ background: s.color }} /> {s.label}
          </span>
        ))}
      </div>
    </div>
  );
}

function niceCeil(v: number): number {
  const exp = Math.pow(10, Math.floor(Math.log10(v)));
  const n = v / exp;
  const step = n <= 1 ? 1 : n <= 2 ? 2 : n <= 5 ? 5 : 10;
  return step * exp;
}

function formatMonth(ym: string): string {
  const [y, m] = ym.split('-').map(Number);
  if (!y || !m) return ym;
  return new Date(y, m - 1, 1).toLocaleDateString('en-GB', { month: 'short' });
}
