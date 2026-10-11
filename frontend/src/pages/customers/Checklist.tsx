import { CircleCheck, Circle, LoaderCircle } from 'lucide-react';
import type { ChecklistItem } from '../../lib/types';
import { Badge, cx } from '../../components/ui';

export function Checklist({ items }: { items: ChecklistItem[] }) {
  return (
    <ul className="divide-y divide-slate-100">
      {items.map((item) => {
        const Icon = item.status === 'complete' ? CircleCheck : item.status === 'in_progress' ? LoaderCircle : Circle;
        return (
          <li key={item.key} className="flex items-start gap-3 py-3">
            <Icon className={cx('mt-0.5 size-5 shrink-0', item.status === 'complete' ? 'text-emerald-600' : item.status === 'in_progress' ? 'text-sky-600' : 'text-slate-300')} />
            <div className="min-w-0 flex-1">
              <p className="flex flex-wrap items-center gap-2 text-sm font-medium text-slate-800">
                {item.label}
                {!item.required && <Badge>Optional</Badge>}
              </p>
              <p className="mt-0.5 text-xs text-slate-500">{item.detail}</p>
            </div>
          </li>
        );
      })}
    </ul>
  );
}
