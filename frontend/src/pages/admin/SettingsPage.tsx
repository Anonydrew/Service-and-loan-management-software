import { useEffect, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Fingerprint, MessageSquare, ScanFace } from 'lucide-react';
import { api, errorMessage } from '../../lib/api';
import { label } from '../../lib/format';
import { useAction } from '../../lib/hooks';
import { Alert, Badge, Button, Card, CardHeader, Checkbox, ErrorState, LoadingBlock, PageHeader, TextField } from '../../components/ui';

interface SettingsResponse {
  company: Record<string, string | null>;
  settings: {
    onboarding: Record<string, boolean | number | string>;
    loans: Record<string, boolean>;
    billing: Record<string, number>;
  };
  integrations: {
    biometrics: Record<'face' | 'fingerprint', { provider: string; configured: boolean; simulated: boolean }>;
    sms: { driver: string };
  };
}

export default function SettingsPage() {
  const { data, isLoading, error, refetch } = useQuery({ queryKey: ['settings'], queryFn: () => api.get<SettingsResponse>('/api/settings') });
  const [company, setCompany] = useState<Record<string, string | null>>({});
  const [settings, setSettings] = useState<SettingsResponse['settings'] | null>(null);

  useEffect(() => {
    if (data) {
      setCompany(data.company);
      setSettings(data.settings);
    }
  }, [data]);

  const save = useAction({
    fn: () => api.put('/api/settings', { company, settings }),
    success: 'Settings saved',
    invalidate: [['settings'], ['me'], ['biometric-providers']],
  });

  if (isLoading || !settings) return <LoadingBlock rows={8} />;
  if (error || !data) return <ErrorState message={errorMessage(error)} onRetry={() => refetch()} />;

  const ob = settings.onboarding;
  const setOb = (k: string, v: boolean | number | string) => setSettings({ ...settings, onboarding: { ...ob, [k]: v } });
  const bio = data.integrations.biometrics;

  return (
    <>
      <PageHeader
        title="Settings"
        description="Company profile, onboarding policy and business rules. Every change is audited."
        actions={
          <Button loading={save.isPending} onClick={() => save.mutate()}>
            Save changes
          </Button>
        }
      />
      <div className="grid gap-6 lg:grid-cols-3">
        <div className="space-y-6 lg:col-span-2">
          <Card>
            <CardHeader title="Company profile" description="Shown on agreements, invoices and receipts." />
            <div className="grid gap-4 sm:grid-cols-2">
              {(['name', 'legal_name', 'registration_number', 'tin', 'email', 'phone', 'address'] as const).map((k) => (
                <TextField key={k} label={label(k)} value={company[k] ?? ''} onChange={(v) => setCompany({ ...company, [k]: v })} className={k === 'address' ? 'sm:col-span-2' : ''} />
              ))}
            </div>
          </Card>

          <Card>
            <CardHeader title="Onboarding & identity verification policy" description="Set these according to your lawful onboarding (KYC) policy." />
            <div className="space-y-4">
              <p className="text-xs font-semibold uppercase tracking-wide text-slate-500">Documents</p>
              <Checkbox checked={!!ob.individual_requires_id_document} onChange={(v) => setOb('individual_requires_id_document', v)} label="Individuals must provide an identity document" />
              <Checkbox checked={!!ob.require_document_verification} onChange={(v) => setOb('require_document_verification', v)} label="Documents must be verified by an authorised person" description="Otherwise an unexpired uploaded document is enough." />
              <Checkbox checked={!!ob.document_four_eyes} onChange={(v) => setOb('document_four_eyes', v)} label="Uploader cannot verify their own upload" />
              <Checkbox checked={!!ob.business_requires_registration_document} onChange={(v) => setOb('business_requires_registration_document', v)} label="Businesses must provide a registration document" />
              <Checkbox checked={!!ob.business_requires_representative_id} onChange={(v) => setOb('business_requires_representative_id', v)} label="Each authorised signatory must provide an identity document" />

              <p className="pt-2 text-xs font-semibold uppercase tracking-wide text-slate-500">Biometrics (onboarding only)</p>
              <Checkbox checked={!!ob.individual_requires_face} onChange={(v) => setOb('individual_requires_face', v)} label="Require facial verification for individuals" />
              <Checkbox checked={!!ob.individual_requires_fingerprint} onChange={(v) => setOb('individual_requires_fingerprint', v)} label="Require fingerprint verification for individuals" description="Only with compatible scanners and the capture bridge installed." />
              <Checkbox checked={!!ob.representative_requires_face} onChange={(v) => setOb('representative_requires_face', v)} label="Require facial verification for a business's primary representative" />
              <Checkbox checked={!!ob.allow_manual_review} onChange={(v) => setOb('allow_manual_review', v)} label="Allow manual review when biometrics cannot be completed" description="Strongly recommended: customers may decline biometrics or lack a usable device." />
              {(ob.individual_requires_face || ob.representative_requires_face) && !bio.face.configured && (
                <Alert tone="amber">Facial verification is required but no provider is configured. Customers will need manual review until one is set up.</Alert>
              )}
              <div className="grid gap-4 pt-2 sm:grid-cols-3">
                <TextField label="Attempts per person per day" type="number" min="1" max="10" value={String(ob.max_biometric_attempts_per_day)} onChange={(v) => setOb('max_biometric_attempts_per_day', Number(v))} />
                <TextField label="Keep provider references (days)" type="number" min="30" value={String(ob.biometric_retention_days)} onChange={(v) => setOb('biometric_retention_days', Number(v))} />
                <TextField label="Consent policy version" value={String(ob.consent_policy_version)} onChange={(v) => setOb('consent_policy_version', v)} hint="Bump when consent wording changes." />
              </div>
            </div>
          </Card>

          <Card>
            <CardHeader title="Lending & billing rules" />
            <div className="space-y-4">
              <Checkbox checked={!!settings.loans.maker_checker} onChange={(v) => setSettings({ ...settings, loans: { ...settings.loans, maker_checker: v } })} label="Maker-checker for loan decisions" description="The person who prepares an application cannot approve or reject it." />
              <Checkbox checked={!!settings.loans.require_signed_agreement} onChange={(v) => setSettings({ ...settings, loans: { ...settings.loans, require_signed_agreement: v } })} label="Require a signed agreement before disbursement" />
              <TextField
                label="Default invoice payment terms (days)"
                type="number"
                value={String(settings.billing.invoice_due_days)}
                onChange={(v) => setSettings({ ...settings, billing: { invoice_due_days: Number(v) } })}
                className="sm:w-64"
              />
            </div>
          </Card>
        </div>

        <div className="space-y-6">
          <Card>
            <CardHeader title="Integrations" description="Configured by your system administrator in the server environment." />
            <ul className="space-y-4">
              {(
                [
                  ['face', ScanFace, 'Facial verification'],
                  ['fingerprint', Fingerprint, 'Fingerprint verification'],
                ] as const
              ).map(([k, Icon, title]) => (
                <li key={k} className="flex items-start gap-3">
                  <Icon className="mt-0.5 size-5 text-slate-400" />
                  <div className="flex-1">
                    <p className="text-sm font-medium text-slate-800">{title}</p>
                    <p className="text-xs text-slate-500">Driver: {bio[k].provider}</p>
                  </div>
                  {bio[k].simulated ? <Badge tone="violet">Simulator</Badge> : bio[k].configured ? <Badge tone="green">Configured</Badge> : <Badge tone="amber">Not configured</Badge>}
                </li>
              ))}
              <li className="flex items-start gap-3">
                <MessageSquare className="mt-0.5 size-5 text-slate-400" />
                <div className="flex-1">
                  <p className="text-sm font-medium text-slate-800">Customer SMS</p>
                  <p className="text-xs text-slate-500">Driver: {data.integrations.sms.driver}</p>
                </div>
                {data.integrations.sms.driver === 'log' ? <Badge tone="amber">Log only</Badge> : <Badge tone="green">Configured</Badge>}
              </li>
            </ul>
            <p className="mt-4 text-xs text-slate-500">See docs/BIOMETRICS.md and docs/SETUP.md for provider and hardware requirements.</p>
          </Card>
        </div>
      </div>
    </>
  );
}
