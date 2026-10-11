import { useState, type FormEvent } from 'react';
import { Navigate, useLocation, useNavigate } from 'react-router-dom';
import { HandCoins, Briefcase, ShieldCheck, Lock } from 'lucide-react';
import { useAuth } from '../lib/auth';
import { ApiError, errorMessage } from '../lib/api';
import { Alert, Button, TextField } from '../components/ui';

export default function LoginPage() {
  const { user, login, sessionNotice } = useAuth();
  const navigate = useNavigate();
  const location = useLocation();
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  if (user) return <Navigate to={(location.state as { from?: string } | null)?.from ?? '/'} replace />;

  const submit = async (e: FormEvent) => {
    e.preventDefault();
    setBusy(true);
    setError(null);
    try {
      await login(email.trim(), password);
      navigate((location.state as { from?: string } | null)?.from ?? '/', { replace: true });
    } catch (err) {
      setError(err instanceof ApiError && err.status === 429 ? 'Too many attempts. Please wait a minute and try again.' : errorMessage(err));
    } finally {
      setBusy(false);
    }
  };

  return (
    <div className="grid min-h-full lg:grid-cols-2">
      <div className="relative hidden overflow-hidden bg-brand-800 p-12 text-white lg:flex lg:flex-col lg:justify-between">
        <div className="absolute -right-24 -top-24 size-96 rounded-full bg-brand-600/40" />
        <div className="absolute -bottom-32 -left-16 size-96 rounded-full bg-brand-900/50" />
        <div className="relative flex items-center gap-3">
          <img src="/favicon.svg" alt="" className="size-10 rounded-xl ring-2 ring-white/20" />
          <span className="text-lg font-semibold">Service & Loan Manager</span>
        </div>
        <div className="relative max-w-md">
          <h1 className="text-4xl font-semibold leading-tight">Loans, services and customers in one place.</h1>
          <p className="mt-4 text-brand-100">Onboard customers securely, manage the full loan lifecycle, bill services and keep the books balanced across every branch.</p>
          <ul className="mt-8 space-y-4 text-sm text-brand-50">
            <li className="flex items-center gap-3">
              <HandCoins className="size-5 text-brand-200" /> Applications, approvals, disbursements and collections
            </li>
            <li className="flex items-center gap-3">
              <Briefcase className="size-5 text-brand-200" /> Service requests, invoicing and payments
            </li>
            <li className="flex items-center gap-3">
              <ShieldCheck className="size-5 text-brand-200" /> Consent-based identity verification at onboarding
            </li>
          </ul>
        </div>
        <p className="relative text-xs text-brand-200">Access is restricted to authorised staff. All activity is logged.</p>
      </div>

      <div className="flex items-center justify-center p-6 sm:p-12">
        <div className="w-full max-w-sm">
          <div className="mb-8 lg:hidden">
            <img src="/favicon.svg" alt="" className="size-11 rounded-xl" />
          </div>
          <h2 className="text-2xl font-semibold tracking-tight text-slate-900">Sign in</h2>
          <p className="mt-1 text-sm text-slate-500">Use your staff account to continue.</p>

          <form onSubmit={submit} className="mt-8 space-y-4" noValidate>
            {sessionNotice && <Alert tone="amber">{sessionNotice}</Alert>}
            {error && <Alert tone="red">{error}</Alert>}
            <TextField label="Email address" type="email" autoComplete="username" value={email} onChange={setEmail} required autoFocus />
            <TextField label="Password" type="password" autoComplete="current-password" value={password} onChange={setPassword} required />
            <Button type="submit" className="w-full" loading={busy} icon={<Lock className="size-4" />}>
              Sign in
            </Button>
          </form>

          {import.meta.env.DEV && (
            <div className="mt-8 rounded-xl border border-dashed border-slate-300 p-4 text-xs text-slate-500">
              <p className="font-medium text-slate-600">Development demo accounts</p>
              <p className="mt-1">
                admin@demo.test, manager@, officer@, cashier@, service@, accountant@, compliance@demo.test
                <br />
                Password: <code>Demo-Password-2026</code>
              </p>
            </div>
          )}
        </div>
      </div>
    </div>
  );
}
