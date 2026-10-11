import { useQuery } from '@tanstack/react-query';
import { api } from '../../lib/api';
import { date, money } from '../../lib/format';
import { useDebounced } from '../../lib/hooks';
import { Card, CardHeader, DescriptionList, Skeleton } from '../../components/ui';

interface Preview {
  installments: { number: number; due_date: string; principal: number; interest: number; fees: number; total: number }[];
  total_interest: number;
  total_fees: number;
  total_payable: number;
  fee_minor: number;
  fee_collection: string;
  net_disbursed_minor: number;
  first_due_date: string;
  maturity_date: string;
}

/** Live repayment schedule for a product/amount/term, computed by the server's calculator. */
export function SchedulePreview({ productId, amount, term }: { productId: string; amount: string; term: string }) {
  const args = useDebounced({ productId, amount, term }, 400);
  const valid = !!args.productId && Number(args.amount) > 0 && Number(args.term) > 0;
  const { data, isFetching, error } = useQuery({
    queryKey: ['loan-preview', args],
    queryFn: () => api.post<Preview>('/api/loans/calculate', { loan_product_id: args.productId, amount: args.amount, term: args.term }),
    enabled: valid,
    retry: false,
  });

  return (
    <Card>
      <CardHeader title="Repayment estimate" description="Final dates are set from the disbursement date." />
      {!valid ? (
        <p className="text-sm text-slate-500">Choose a product, amount and term to see the schedule.</p>
      ) : error ? (
        <p className="text-sm text-red-600">Couldn't calculate this schedule.</p>
      ) : !data ? (
        <Skeleton className="h-40 w-full" />
      ) : (
        <div className={isFetching ? 'opacity-60' : ''}>
          <div className="mb-4 rounded-xl bg-brand-50 p-4">
            <p className="text-xs font-medium uppercase tracking-wide text-brand-800">Installment</p>
            <p className="tabular mt-1 text-2xl font-semibold text-brand-900">{money(data.installments[0]?.total)}</p>
          </div>
          <DescriptionList
            items={[
              { label: 'Total interest', value: money(data.total_interest) },
              { label: data.fee_collection === 'deducted' ? 'Fee (deducted)' : 'Fee (in repayments)', value: money(data.fee_minor) },
              { label: 'Customer receives', value: money(data.net_disbursed_minor) },
              { label: 'Total repayable', value: <strong>{money(data.total_payable)}</strong> },
            ]}
          />
          <div className="mt-4 max-h-64 overflow-y-auto rounded-lg border border-slate-100">
            <table className="w-full text-xs">
              <thead className="sticky top-0 bg-slate-50 text-slate-500">
                <tr>
                  <th className="px-3 py-2 text-left">#</th>
                  <th className="px-3 py-2 text-left">Due (est.)</th>
                  <th className="px-3 py-2 text-right">Principal</th>
                  <th className="px-3 py-2 text-right">Interest</th>
                  <th className="px-3 py-2 text-right">Total</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {data.installments.map((i) => (
                  <tr key={i.number}>
                    <td className="px-3 py-1.5 text-slate-500">{i.number}</td>
                    <td className="px-3 py-1.5">{date(i.due_date)}</td>
                    <td className="tabular px-3 py-1.5 text-right">{money(i.principal)}</td>
                    <td className="tabular px-3 py-1.5 text-right">{money(i.interest)}</td>
                    <td className="tabular px-3 py-1.5 text-right font-medium">{money(i.total)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>
      )}
    </Card>
  );
}
