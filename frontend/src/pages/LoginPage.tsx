import { useState, type FormEvent } from 'react';
import { Navigate, useLocation, useNavigate } from 'react-router-dom';
import { HandCoins, Briefcase, ShieldCheck, Lock } from 'lucide-react';
import { useAuth } from '../lib/auth';
import { ApiError, errorMessage } from '../lib/api';
import { Alert, Button, TextField } from '../components/ui';
import logo from '../assets/logo.svg';

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
      <div className="relative hidden overflow-hidden bg-black p-12 text-white lg:flex lg:flex-col lg:justify-between">
        <div className="relative">
          <img src={logo} alt="DXCOVR" className="h-7 w-auto" />
          <p className="mt-3 text-sm font-medium text-slate-400">Service & Loan Manager</p>
        </div>
        <div className="relative max-w-md">
          <h1 className="text-4xl font-semibold leading-tight">Loans, services and customers in one place.</h1>
          <p className="mt-4 text-slate-300">Onboard customers securely, manage the full loan lifecycle, bill services and keep the books balanced across every branch.</p>
          <ul className="mt-8 space-y-4 text-sm text-slate-200">
            <li className="flex items-center gap-3">
              <HandCoins className="size-5 text-[#76B900]" /> Applications, approvals, disbursements and collections
            </li>
            <li className="flex items-center gap-3">
              <Briefcase className="size-5 text-[#76B900]" /> Service requests, invoicing and payments
            </li>
            <li className="flex items-center gap-3">
              <ShieldCheck className="size-5 text-[#76B900]" /> Consent-based identity verification at onboarding
            </li>
          </ul>
        </div>
        <p className="relative text-xs text-slate-500">Access is restricted to authorised staff. All activity is logged.</p>
      </div>

      <div className="flex items-center justify-center p-6 sm:p-12">
        <div className="w-full max-w-sm">
          <div className="mb-8 flex items-center gap-3 lg:hidden">
            <img src="/favicon.svg" alt="" className="size-11 rounded-xl" />
            <img src={logo} alt="DXCOVR" className="h-5 w-auto" />
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
        </div>
      </div>
    </div>
  );
}
