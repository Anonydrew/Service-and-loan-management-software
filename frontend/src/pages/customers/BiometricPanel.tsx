import { useCallback, useEffect, useRef, useState } from 'react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { Camera, CameraOff, Fingerprint, FlaskConical, Info, RefreshCw, ScanFace, ShieldAlert } from 'lucide-react';
import { api, errorMessage, ApiError } from '../../lib/api';
import { useAuth } from '../../lib/auth';
import { dateTime, label } from '../../lib/format';
import type { BiometricAttempt, Consent, Customer } from '../../lib/types';
import { Alert, Badge, Button, Card, CardHeader, EmptyState, LoadingBlock, Select, Spinner, StatusBadge, cx } from '../../components/ui';
import { useToast } from '../../components/Toast';
import { customerKeys } from './shared';

interface ProviderStatus {
  providers: Record<'face' | 'fingerprint', { provider: string; configured: boolean; simulated: boolean }>;
  policy: Record<string, boolean | number | string>;
  failure_reasons: Record<string, string>;
}

type Modality = 'face' | 'fingerprint';

/**
 * Onboarding-only identity verification. Nothing captured here is stored by
 * this system: the camera preview stays in the browser, and only the
 * provider's outcome and reference are recorded.
 */
export function BiometricPanel({ customer, onReferForReview }: { customer: Customer; onReferForReview?: () => void }) {
  const { can } = useAuth();
  const providers = useQuery({ queryKey: ['biometric-providers'], queryFn: () => api.get<ProviderStatus>('/api/biometrics/providers'), staleTime: 60_000 });
  const attempts = useQuery({
    queryKey: ['customer-biometrics', String(customer.id)],
    queryFn: () => api.get<{ data: BiometricAttempt[] }>(`/api/customers/${customer.id}/biometrics`),
  });
  const consents = useQuery({
    queryKey: ['customer-consents', String(customer.id)],
    queryFn: () => api.get<{ data: Consent[] }>(`/api/customers/${customer.id}/consents`),
  });

  const reps = customer.representatives ?? [];
  const [repId, setRepId] = useState<number | null>(customer.type === 'business' ? (reps.find((r) => r.is_primary)?.id ?? reps[0]?.id ?? null) : null);
  const policy = providers.data?.policy ?? {};
  const faceRequired = customer.type === 'business' ? !!policy.representative_requires_face : !!policy.individual_requires_face;
  const fingerprintRequired = customer.type === 'individual' && !!policy.individual_requires_fingerprint;
  const [modality, setModality] = useState<Modality>(fingerprintRequired && !faceRequired ? 'fingerprint' : 'face');

  const open = !['completed', 'rejected'].includes(customer.onboarding_status);
  const canCapture = can('biometrics.capture') && open;
  const hasConsent = (consents.data?.data ?? []).some((c) => c.consent_type === 'biometric_processing' && c.granted && !c.withdrawn_at && (c.representative?.id ?? null) === repId);
  const status = providers.data?.providers[modality];

  if (providers.isLoading || attempts.isLoading) return <LoadingBlock rows={4} />;

  return (
    <div className="space-y-6">
      <Alert tone="neutral" icon={<Info className="size-4" />} title="Onboarding identity check only">
        Biometrics are used once, to confirm the customer is the person on their identity document. They are never used for sign-in, repayments, disbursements or other transactions.
      </Alert>

      {open && (
        <Card>
          <CardHeader
            title="Verify identity"
            description={customer.type === 'business' ? 'A business is verified through its representatives. Choose who is present.' : `Verify ${customer.display_name} in person.`}
          />

          <div className="mb-5 flex flex-col gap-3 sm:flex-row sm:items-center">
            {customer.type === 'business' && (
              <Select value={repId ?? ''} onChange={(e) => setRepId(e.target.value ? Number(e.target.value) : null)} className="sm:w-64" aria-label="Representative">
                {reps.length === 0 && <option value="">No representatives</option>}
                {reps.map((r) => (
                  <option key={r.id} value={r.id}>
                    {r.full_name}
                    {r.position ? ` — ${r.position}` : ''}
                  </option>
                ))}
              </Select>
            )}
            <div className="inline-flex rounded-xl bg-slate-100 p-1" role="radiogroup" aria-label="Verification method">
              {(
                [
                  ['face', ScanFace, 'Face', faceRequired],
                  ['fingerprint', Fingerprint, 'Fingerprint', fingerprintRequired],
                ] as const
              ).map(([m, Icon, text, required]) => (
                <button
                  key={m}
                  role="radio"
                  aria-checked={modality === m}
                  onClick={() => setModality(m)}
                  className={cx('inline-flex items-center gap-2 rounded-lg px-3 py-1.5 text-sm font-medium transition-colors', modality === m ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-500 hover:text-slate-800')}
                >
                  <Icon className="size-4" />
                  {text}
                  {required ? <Badge tone="amber">Required</Badge> : <span className="text-xs font-normal text-slate-400">Optional</span>}
                </button>
              ))}
            </div>
          </div>

          {!canCapture ? (
            <p className="text-sm text-slate-500">You don't have permission to start biometric verification.</p>
          ) : customer.type === 'business' && repId === null ? (
            <Alert tone="amber">Add a representative before verifying identity.</Alert>
          ) : !status?.configured ? (
            <Alert tone="amber" icon={<ShieldAlert className="size-4" />} title={`${modality === 'face' ? 'Facial' : 'Fingerprint'} verification is not set up`}>
              {modality === 'face'
                ? 'No facial verification provider is configured, so no face match can be performed. Verify the identity document instead and, if company policy requires biometrics, refer the customer for manual review.'
                : 'Fingerprint verification needs a compatible scanner on this workstation and the capture bridge service configured by your administrator. Use document verification and manual review instead.'}
              {onReferForReview && (
                <div className="mt-3">
                  <Button size="sm" variant="secondary" onClick={onReferForReview}>
                    Go to manual review
                  </Button>
                </div>
              )}
            </Alert>
          ) : !hasConsent ? (
            <Alert tone="amber" title="Biometric consent needed">
              Record the {customer.type === 'business' ? "representative's" : "customer's"} biometric consent on the Consent step first. If they decline, refer them for manual review.
            </Alert>
          ) : modality === 'face' ? (
            <FaceCapture key={`face-${repId}`} customerId={customer.id} repId={repId} simulated={!!status.simulated} onReferForReview={onReferForReview} />
          ) : (
            <FingerprintCapture key={`fp-${repId}`} customerId={customer.id} repId={repId} simulated={!!status.simulated} onReferForReview={onReferForReview} />
          )}
        </Card>
      )}

      <AttemptHistory attempts={attempts.data?.data ?? []} />
    </div>
  );
}

/* ---------------------------------------------------------------- Shared */

function useAttempt(customerId: number) {
  const queryClient = useQueryClient();
  const toast = useToast();
  const [attempt, setAttempt] = useState<BiometricAttempt | null>(null);
  const [client, setClient] = useState<Record<string, unknown> | null>(null);
  const [busy, setBusy] = useState(false);
  const pending = useRef<BiometricAttempt | null>(null);

  const refresh = useCallback(() => customerKeys(customerId).forEach((k) => queryClient.invalidateQueries({ queryKey: k })), [customerId, queryClient]);

  const run = useCallback(
    async <T,>(fn: () => Promise<T>): Promise<T | null> => {
      setBusy(true);
      try {
        return await fn();
      } catch (e) {
        toast.error(errorMessage(e));
        if (e instanceof ApiError) refresh();
        return null;
      } finally {
        setBusy(false);
      }
    },
    [toast, refresh],
  );

  const start = (modality: Modality, repId: number | null) =>
    run(async () => {
      const res = await api.post<{ attempt: BiometricAttempt; client: Record<string, unknown> }>(`/api/customers/${customerId}/biometrics`, { modality, representative_id: repId });
      setAttempt(res.attempt);
      setClient(res.client);
      pending.current = res.attempt.status === 'pending' ? res.attempt : null;
      refresh();
      return res;
    });

  const finish = (path: 'complete' | 'client-failure' | 'cancel', body?: unknown) =>
    run(async () => {
      if (!attempt) return null;
      const res = await api.post<{ attempt: BiometricAttempt }>(`/api/customers/${customerId}/biometrics/${attempt.id}/${path}`, body);
      setAttempt(res.attempt);
      pending.current = null;
      refresh();
      return res.attempt;
    });

  // Leaving the page mid-session records the attempt as interrupted.
  useEffect(
    () => () => {
      const open = pending.current;
      if (open) api.post(`/api/customers/${customerId}/biometrics/${open.id}/cancel`).catch(() => undefined);
    },
    [customerId],
  );

  const reset = () => {
    setAttempt(null);
    setClient(null);
  };

  return { attempt, client, busy, start, finish, reset };
}

function ResultView({ attempt, onRetry, onReferForReview }: { attempt: BiometricAttempt; onRetry: () => void; onReferForReview?: () => void }) {
  const ok = attempt.status === 'verified';
  const review = attempt.status === 'manual_review';
  return (
    <div className={cx('rounded-xl border p-5', ok ? 'border-emerald-200 bg-emerald-50' : review ? 'border-amber-200 bg-amber-50' : 'border-red-200 bg-red-50')} role="status">
      <div className="flex flex-wrap items-center gap-2">
        <StatusBadge status={attempt.status} />
        {attempt.is_simulated && (
          <Badge tone="violet">
            <FlaskConical className="size-3" /> SIMULATED — not a real verification
          </Badge>
        )}
      </div>
      <p className="mt-3 text-sm font-medium text-slate-900">
        {ok ? 'Identity verified.' : review ? 'The result was inconclusive and needs a person to review it.' : (attempt.failure_message ?? 'Verification did not succeed.')}
      </p>
      <p className="mt-1 text-xs text-slate-600">
        {attempt.match_score !== null && <>Match score {Math.round(attempt.match_score * 100)}% · </>}
        {attempt.liveness_passed !== null && <>Liveness {attempt.liveness_passed ? 'passed' : 'failed'} · </>}
        Provider {label(attempt.provider)}
      </p>
      {!ok && (
        <div className="mt-4 flex flex-wrap gap-2">
          <Button size="sm" icon={<RefreshCw className="size-3.5" />} onClick={onRetry}>
            Try again
          </Button>
          {onReferForReview && (
            <Button size="sm" variant="secondary" onClick={onReferForReview}>
              Refer for manual review
            </Button>
          )}
        </div>
      )}
    </div>
  );
}

const SIM_OUTCOMES = [
  ['verified', 'Match (verified)'],
  ['no_match', 'No match'],
  ['liveness_failed', 'Liveness check failed'],
  ['poor_quality', 'Poor capture quality'],
  ['manual_review', 'Inconclusive (manual review)'],
  ['provider_unavailable', 'Provider outage'],
] as const;

function SimulatedControls({ busy, onSubmit, verb }: { busy: boolean; onSubmit: (outcome: string) => void; verb: string }) {
  const [outcome, setOutcome] = useState('verified');
  return (
    <div className="rounded-xl border border-violet-200 bg-violet-50 p-4">
      <p className="flex items-center gap-2 text-sm font-semibold text-violet-900">
        <FlaskConical className="size-4" /> Development simulator
      </p>
      <p className="mt-1 text-xs text-violet-800">No biometric data is processed or sent anywhere. Choose the outcome to test each onboarding path. This mode is disabled in production.</p>
      <div className="mt-3 flex flex-col gap-2 sm:flex-row">
        <Select value={outcome} onChange={(e) => setOutcome(e.target.value)} className="sm:w-64" aria-label="Simulated outcome">
          {SIM_OUTCOMES.map(([v, t]) => (
            <option key={v} value={v}>
              {t}
            </option>
          ))}
        </Select>
        <Button loading={busy} onClick={() => onSubmit(outcome)}>
          {verb}
        </Button>
      </div>
    </div>
  );
}

/* ------------------------------------------------------------------ Face */

type CameraState = 'idle' | 'requesting' | 'live' | 'denied' | 'unavailable';

function FaceCapture({ customerId, repId, simulated, onReferForReview }: { customerId: number; repId: number | null; simulated: boolean; onReferForReview?: () => void }) {
  const { attempt, client, busy, start, finish, reset } = useAttempt(customerId);
  const [camera, setCamera] = useState<CameraState>('idle');
  const [cameraMessage, setCameraMessage] = useState('');
  const video = useRef<HTMLVideoElement>(null);
  const stream = useRef<MediaStream | null>(null);

  const stopCamera = () => {
    stream.current?.getTracks().forEach((t) => t.stop());
    stream.current = null;
  };
  useEffect(() => stopCamera, []);
  useEffect(() => {
    if (camera === 'live' && video.current && stream.current) {
      video.current.srcObject = stream.current;
      video.current.play().catch(() => undefined);
    }
  }, [camera]);

  const begin = async () => {
    const res = await start('face', repId);
    if (!res || res.attempt.status !== 'pending') return;

    if (!navigator.mediaDevices?.getUserMedia) {
      setCamera('unavailable');
      setCameraMessage('This browser cannot access a camera here. Camera access requires HTTPS (or localhost) and a supported browser.');
      await finish('client-failure', { reason: 'device_unavailable' });
      return;
    }

    setCamera('requesting');
    try {
      const s = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'user', width: { ideal: 1280 } }, audio: false });
      stream.current = s;
      setCamera('live');
    } catch (e) {
      const name = (e as DOMException).name;
      const denied = name === 'NotAllowedError' || name === 'SecurityError';
      setCamera(denied ? 'denied' : 'unavailable');
      setCameraMessage(
        denied
          ? 'Camera access was blocked. Click the camera icon in the address bar, allow access, then try again.'
          : 'No working camera was found. Connect a webcam (or close other apps using it) and try again.',
      );
      await finish('client-failure', { reason: denied ? 'camera_permission_denied' : 'device_unavailable' });
    }
  };

  const complete = async (payload: Record<string, unknown>) => {
    const result = await finish('complete', payload);
    if (result) {
      stopCamera();
      setCamera('idle');
    }
  };

  const cancel = async () => {
    stopCamera();
    setCamera('idle');
    await finish('cancel');
    reset();
  };

  const retry = () => {
    stopCamera();
    setCamera('idle');
    setCameraMessage('');
    reset();
  };

  if (attempt && attempt.status !== 'pending') {
    return (
      <div className="space-y-3">
        {cameraMessage && (
          <Alert tone="amber" icon={<CameraOff className="size-4" />}>
            {cameraMessage}
          </Alert>
        )}
        <ResultView attempt={attempt} onRetry={retry} onReferForReview={onReferForReview} />
      </div>
    );
  }

  if (!attempt) {
    return (
      <div className="flex flex-col items-center rounded-xl border border-dashed border-slate-300 px-6 py-10 text-center">
        <div className="flex size-14 items-center justify-center rounded-full bg-brand-50 text-brand-700">
          <ScanFace className="size-7" />
        </div>
        <p className="mt-3 font-medium text-slate-900">Facial verification with liveness check</p>
        <ul className="mt-2 max-w-md space-y-1 text-left text-sm text-slate-500">
          <li>• The customer must be present, facing the camera in good light.</li>
          <li>• Remove hats and sunglasses. Only one face should be in view.</li>
          <li>• The browser will ask for permission to use the camera.</li>
        </ul>
        <Button className="mt-5" icon={<Camera className="size-4" />} loading={busy} onClick={begin}>
          Start face verification
        </Button>
      </div>
    );
  }

  return (
    <div className="grid gap-5 lg:grid-cols-2">
      <div className="relative aspect-[4/3] overflow-hidden rounded-xl bg-slate-900">
        {camera === 'live' ? (
          <>
            <video ref={video} muted playsInline className="size-full -scale-x-100 object-cover" aria-label="Camera preview" />
            <div className="pointer-events-none absolute inset-0 flex items-center justify-center">
              <div className="h-[72%] w-[52%] rounded-[50%] border-4 border-white/80 shadow-[0_0_0_9999px_rgba(15,23,42,0.45)]" />
            </div>
            <p className="absolute inset-x-0 bottom-3 text-center text-xs text-white/90">Position the face inside the oval</p>
          </>
        ) : (
          <div className="flex size-full flex-col items-center justify-center gap-2 text-sm text-slate-300">
            <Spinner className="text-white" />
            Waiting for camera permission…
          </div>
        )}
      </div>
      <div className="flex flex-col justify-between gap-4">
        <div>
          <p className="text-sm font-medium text-slate-900">Session in progress</p>
          <p className="mt-1 text-sm text-slate-500">The preview never leaves this device. {simulated ? '' : 'The provider performs the match and liveness check.'}</p>
        </div>
        {client?.mode === 'simulated' ? (
          <SimulatedControls busy={busy} onSubmit={(o) => complete({ simulate_outcome: o })} verb="Capture & verify" />
        ) : (
          <Alert tone="amber" title="Provider capture component not installed">
            The configured provider requires its web capture SDK to be mounted here. See docs/BIOMETRICS.md.
          </Alert>
        )}
        <Button variant="ghost" onClick={cancel} disabled={busy}>
          Cancel session
        </Button>
      </div>
    </div>
  );
}

/* ------------------------------------------------------------ Fingerprint */

function FingerprintCapture({ customerId, repId, simulated, onReferForReview }: { customerId: number; repId: number | null; simulated: boolean; onReferForReview?: () => void }) {
  const { attempt, client, busy, start, finish, reset } = useAttempt(customerId);
  const [phase, setPhase] = useState<'idle' | 'detecting' | 'ready' | 'scanning'>('idle');
  const [deviceInfo, setDeviceInfo] = useState<string>('');
  const [message, setMessage] = useState('');

  const begin = async () => {
    setMessage('');
    const res = await start('fingerprint', repId);
    if (!res || res.attempt.status !== 'pending') return;
    if (res.client.mode === 'simulated') {
      setPhase('ready');
      return;
    }

    // Look for the local capture bridge (vendor SDK wrapper) on this workstation.
    setPhase('detecting');
    try {
      const ctrl = new AbortController();
      const t = setTimeout(() => ctrl.abort(), 3000);
      const status = await fetch(`${res.client.bridge_url}/status`, { signal: ctrl.signal }).then((r) => r.json());
      clearTimeout(t);
      if (!status?.device_connected) throw new Error('no device');
      setDeviceInfo(status.device_model ?? 'Fingerprint scanner');
      setPhase('ready');
    } catch {
      setPhase('idle');
      setMessage('No compatible fingerprint scanner was detected. Check that the scanner is connected and the capture bridge service is running on this computer.');
      await finish('client-failure', { reason: 'device_unavailable' });
    }
  };

  const scan = async () => {
    if (!client) return;
    setPhase('scanning');
    try {
      const response = await fetch(`${client.bridge_url}/capture`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ attempt_id: client.attempt_id, nonce: client.nonce, subject: client.subject, expires_at: client.expires_at }),
      });
      const signed = await response.json();
      await finish('complete', { result: signed.result, signature: signed.signature });
    } catch {
      await finish('client-failure', { reason: 'interrupted' });
    } finally {
      setPhase('idle');
    }
  };

  if (attempt && attempt.status !== 'pending') {
    return (
      <div className="space-y-3">
        {message && <Alert tone="amber">{message}</Alert>}
        <ResultView
          attempt={attempt}
          onRetry={() => {
            reset();
            setMessage('');
          }}
          onReferForReview={onReferForReview}
        />
      </div>
    );
  }

  if (!attempt) {
    return (
      <div className="flex flex-col items-center rounded-xl border border-dashed border-slate-300 px-6 py-10 text-center">
        <div className="flex size-14 items-center justify-center rounded-full bg-brand-50 text-brand-700">
          <Fingerprint className="size-7" />
        </div>
        <p className="mt-3 font-medium text-slate-900">Fingerprint verification</p>
        <p className="mt-2 max-w-md text-sm text-slate-500">Requires a compatible scanner connected to this computer and the capture bridge service. {simulated ? 'The development simulator is active.' : ''}</p>
        <Button className="mt-5" icon={<Fingerprint className="size-4" />} loading={busy} onClick={begin}>
          Start fingerprint verification
        </Button>
      </div>
    );
  }

  return (
    <div className="space-y-4">
      {phase === 'detecting' && (
        <p className="flex items-center gap-2 text-sm text-slate-600">
          <Spinner className="size-4" /> Looking for the fingerprint scanner…
        </p>
      )}
      {client?.mode === 'simulated' ? (
        <SimulatedControls busy={busy} onSubmit={(o) => finish('complete', { simulate_outcome: o })} verb="Simulate scan" />
      ) : (
        phase !== 'detecting' && (
          <div className="flex flex-col items-center rounded-xl border border-slate-200 p-6 text-center">
            <Fingerprint className={cx('size-12 text-brand-700', phase === 'scanning' && 'animate-pulse')} />
            <p className="mt-3 font-medium text-slate-900">{phase === 'scanning' ? 'Scanning…' : 'Ask the customer to place their finger on the scanner'}</p>
            <p className="mt-1 text-xs text-slate-500">{deviceInfo}</p>
            <Button className="mt-4" loading={phase === 'scanning' || busy} onClick={scan}>
              Capture fingerprint
            </Button>
          </div>
        )
      )}
      <Button
        variant="ghost"
        disabled={busy}
        onClick={async () => {
          await finish('cancel');
          reset();
        }}
      >
        Cancel session
      </Button>
    </div>
  );
}

/* --------------------------------------------------------------- History */

function AttemptHistory({ attempts }: { attempts: BiometricAttempt[] }) {
  return (
    <Card padded={false}>
      <div className="p-5 pb-0">
        <CardHeader title="Verification history" description="Outcome and audit details only. No images or fingerprint templates are stored." />
      </div>
      {attempts.length === 0 ? (
        <EmptyState title="No verification attempts" />
      ) : (
        <div className="overflow-x-auto">
          <table className="w-full text-sm">
            <thead>
              <tr className="border-b border-slate-200 bg-slate-50/70 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                <th className="px-4 py-2.5">When</th>
                <th className="px-4 py-2.5">Method</th>
                <th className="px-4 py-2.5">Person</th>
                <th className="px-4 py-2.5">Outcome</th>
                <th className="hidden px-4 py-2.5 md:table-cell">Detail</th>
                <th className="hidden px-4 py-2.5 md:table-cell">Operator</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-100">
              {attempts.map((a) => (
                <tr key={a.id}>
                  <td className="whitespace-nowrap px-4 py-3 text-slate-600">{dateTime(a.started_at ?? a.created_at)}</td>
                  <td className="px-4 py-3">
                    {label(a.modality)}
                    {a.is_simulated && (
                      <span className="ml-2">
                        <Badge tone="violet">Simulated</Badge>
                      </span>
                    )}
                  </td>
                  <td className="px-4 py-3 text-slate-600">{a.representative?.full_name ?? 'Customer'}</td>
                  <td className="px-4 py-3">
                    <StatusBadge status={a.status} />
                  </td>
                  <td className="hidden px-4 py-3 text-xs text-slate-500 md:table-cell">
                    {a.failure_reason ? label(a.failure_reason) : a.match_score !== null ? `Score ${Math.round(a.match_score * 100)}%` : '—'}
                    {a.purged_at && ' · references purged'}
                  </td>
                  <td className="hidden px-4 py-3 text-slate-600 md:table-cell">{a.operator?.name}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </Card>
  );
}
