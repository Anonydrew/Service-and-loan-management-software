import { Link } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { ArrowRight, Banknote, Briefcase, CircleAlert, ClipboardList, FileText, HandCoins, Info, TriangleAlert, UserPlus, Users, Wallet, Gauge } from 'lucide-react';
import { api, errorMessage } from '../lib/api';
import { useAuth } from '../lib/auth';
import { date, label, money } from '../lib/format';
import { BarChart } from '../components/BarChart';
import { Badge, ButtonLink, Card, CardHeader, EmptyState, ErrorState, PageHeader, Skeleton, Stat } from '../components/ui';

interface DashboardData {
  alerts: { level: 'info' | 'warning' | 'danger'; text: string; link: string }[];
  customers?: { active: number; new_this_month: number; onboarding_in_progress: number; manual_review: number };
  loans?: {
    active: number;
    in_arrears: number;
    outstanding_minor: number;
    principal_outstanding_minor: number;
    par30_percent: number;
    disbursed_this_month_minor: number;
    collected_this_month_minor: number;
    pending_approval: number;
    pending_disbursement: number;
    due_today_minor: number;
  };
  services?: { open: number; assigned_to_me: number; completed_this_month: number; scheduled_today: number };
  billing?: { receivables_minor: number; overdue_invoices: number; payments_this_month_minor: number };
  trend?: { month: string; disbursed_minor: number; collected_minor: number }[];
  recent_repayments?: { id: number; receipt_number: string; amount_minor: number; payment_method: string; paid_on: string; customer: { display_name: string } | null; loan: { id: number; loan_number: string } | null }[];
}

export default function DashboardPage() {
  const { user, can } = useAuth();
  const { data, isLoading, error, refetch } = useQuery({ queryKey: ['dashboard'], queryFn: () => api.get<DashboardData>('/api/dashboard'), refetchInterval: 120_000 });

  const hour = new Date().getHours();
  const greeting = hour < 12 ? 'Good morning' : hour < 17 ? 'Good afternoon' : 'Good evening';

  return (
    <>
      <PageHeader
        title={`${greeting}, ${user?.name.split(' ')[0]}`}
        description={`Here's what's happening at ${user?.company.name} today.`}
        actions={
          <>
            {can('customers.create') && (
              <ButtonLink to="/customers/new" variant="secondary" icon={<UserPlus className="size-4" />}>
                Register customer
              </ButtonLink>
            )}
            {can('loan_applications.create') && (
              <ButtonLink to="/loans/applications/new" icon={<HandCoins className="size-4" />}>
                New loan application
              </ButtonLink>
            )}
          </>
        }
      />

      {error ? (
        <Card>
          <ErrorState message={errorMessage(error)} onRetry={() => refetch()} />
        </Card>
      ) : isLoading || !data ? (
        <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
          {Array.from({ length: 8 }).map((_, i) => (
            <Skeleton key={i} className="h-28 rounded-[var(--radius-card)]" />
          ))}
        </div>
      ) : (
        <div className="space-y-6">
          {data.alerts.length > 0 && (
            <div className="grid gap-2 md:grid-cols-2">
              {data.alerts.map((a) => {
                const Icon = a.level === 'danger' ? TriangleAlert : a.level === 'warning' ? CircleAlert : Info;
                const tone = a.level === 'danger' ? 'border-red-200 bg-red-50 text-red-800' : a.level === 'warning' ? 'border-amber-200 bg-amber-50 text-amber-900' : 'border-sky-200 bg-sky-50 text-sky-900';
                return (
                  <Link key={a.text} to={a.link} className={`flex items-center gap-3 rounded-xl border px-4 py-3 text-sm font-medium transition-colors hover:brightness-[0.98] ${tone}`}>
                    <Icon className="size-4 shrink-0" />
                    <span className="flex-1">{a.text}</span>
                    <ArrowRight className="size-4 opacity-60" />
                  </Link>
                );
              })}
            </div>
          )}

          {data.loans && (
            <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
              <Stat label="Portfolio outstanding" value={money(data.loans.outstanding_minor)} hint={`${data.loans.active} open loans`} icon={<HandCoins className="size-5" />} to="/loans" />
              <Stat label="Disbursed this month" value={money(data.loans.disbursed_this_month_minor)} hint={`${data.loans.pending_disbursement} awaiting disbursement`} icon={<Banknote className="size-5" />} tone="blue" />
              <Stat label="Collected this month" value={money(data.loans.collected_this_month_minor)} hint={`${money(data.loans.due_today_minor)} due today`} icon={<Wallet className="size-5" />} tone="violet" to="/loans/repayments" />
              <Stat
                label="Portfolio at risk (30+ days)"
                value={`${data.loans.par30_percent}%`}
                hint={`${data.loans.in_arrears} loans in arrears`}
                icon={<Gauge className="size-5" />}
                tone={data.loans.par30_percent > 5 ? 'red' : 'amber'}
                to="/loans/arrears"
              />
            </div>
          )}

          <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            {data.customers && (
              <Stat label="Active customers" value={data.customers.active.toLocaleString()} hint={`${data.customers.new_this_month} new this month · ${data.customers.onboarding_in_progress} onboarding`} icon={<Users className="size-5" />} to="/customers" />
            )}
            {data.loans && <Stat label="Applications to approve" value={data.loans.pending_approval} hint="Submitted and waiting for a decision" icon={<ClipboardList className="size-5" />} tone="blue" to="/loans/applications?status=submitted" />}
            {data.services && <Stat label="Open service requests" value={data.services.open} hint={`${data.services.assigned_to_me} assigned to you · ${data.services.scheduled_today} scheduled today`} icon={<Briefcase className="size-5" />} tone="violet" to="/services/requests" />}
            {data.billing && <Stat label="Receivables" value={money(data.billing.receivables_minor)} hint={`${data.billing.overdue_invoices} overdue invoices`} icon={<FileText className="size-5" />} tone="amber" to="/billing/invoices?status=overdue" />}
          </div>

          <div className="grid gap-6 lg:grid-cols-5">
            {data.trend && (
              <Card className="lg:col-span-3">
                <CardHeader title="Disbursements vs collections" description="Last six months" />
                <BarChart
                  data={data.trend}
                  categoryKey="month"
                  series={[
                    { key: 'disbursed_minor', label: 'Disbursed', color: '#0b6e4f' },
                    { key: 'collected_minor', label: 'Collected', color: '#7dd3b0' },
                  ]}
                />
              </Card>
            )}
            {data.recent_repayments && (
              <Card className="lg:col-span-2" padded={false}>
                <div className="p-5 pb-0">
                  <CardHeader
                    title="Recent repayments"
                    actions={
                      <Link to="/loans/repayments" className="text-sm font-medium text-brand-700 hover:underline">
                        View all
                      </Link>
                    }
                  />
                </div>
                {data.recent_repayments.length === 0 ? (
                  <EmptyState title="No repayments yet" />
                ) : (
                  <ul className="divide-y divide-slate-100">
                    {data.recent_repayments.map((r) => (
                      <li key={r.id} className="flex items-center justify-between gap-3 px-5 py-3">
                        <div className="min-w-0">
                          <p className="truncate text-sm font-medium text-slate-800">{r.customer?.display_name}</p>
                          <p className="text-xs text-slate-500">
                            {r.loan ? (
                              <Link to={`/loans/${r.loan.id}`} className="hover:underline">
                                {r.loan.loan_number}
                              </Link>
                            ) : null}{' '}
                            · {date(r.paid_on)}
                          </p>
                        </div>
                        <div className="text-right">
                          <p className="tabular text-sm font-semibold text-slate-900">{money(r.amount_minor)}</p>
                          <Badge>{label(r.payment_method)}</Badge>
                        </div>
                      </li>
                    ))}
                  </ul>
                )}
              </Card>
            )}
          </div>
        </div>
      )}
    </>
  );
}
