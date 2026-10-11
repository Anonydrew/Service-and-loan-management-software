import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { FileSignature, ShieldCheck } from 'lucide-react';
import { api } from '../../lib/api';
import { useAuth } from '../../lib/auth';
import { dateTime, label } from '../../lib/format';
import { useAction } from '../../lib/hooks';
import type { Consent, Customer } from '../../lib/types';
import { Alert, Badge, Button, Card, LoadingBlock, Select } from '../../components/ui';
import { customerKeys } from './shared';

interface ConsentTexts {
  policy_version: string;
  texts: Record<string, string>;
  methods: string[];
}

const TITLES: Record<string, { title: string; required: string }> = {
  data_processing: { title: 'Personal data processing', required: 'Required to open an account' },
  biometric_processing: { title: 'Biometric identity verification', required: 'Required before any face or fingerprint capture' },
  credit_check: { title: 'Credit bureau check', required: 'Needed for loan applications' },
  marketing: { title: 'Marketing messages', required: 'Optional' },
};

export function ConsentPanel({ customer, readOnly }: { customer: Customer; readOnly?: boolean }) {
  const { can } = useAuth();
  const editable = !readOnly && can('onboarding.manage');
  const texts = useQuery({ queryKey: ['consent-texts'], queryFn: () => api.get<ConsentTexts>('/api/consent-texts'), staleTime: Infinity });
  const consents = useQuery({
    queryKey: ['customer-consents', String(customer.id)],
    queryFn: () => api.get<{ data: Consent[] }>(`/api/customers/${customer.id}/consents`),
  });

  if (texts.isLoading || consents.isLoading) return <LoadingBlock rows={4} />;

  // Who each consent is for. Biometric consent is personal, so for a business
  // it is recorded per representative.
  const subjects: { key: string; repId: number | null; name: string }[] =
    customer.type === 'business'
      ? (customer.representatives ?? []).map((r) => ({ key: `rep-${r.id}`, repId: r.id, name: r.full_name }))
      : [{ key: 'self', repId: null, name: customer.display_name }];

  const current = (type: string, repId: number | null) =>
    (consents.data?.data ?? []).find((c) => c.consent_type === type && (c.representative?.id ?? null) === repId && !c.withdrawn_at);

  return (
    <div className="space-y-4">
      <Alert tone="blue" icon={<ShieldCheck className="size-4" />} title="Read the wording to the customer">
        Each record stores the policy version ({texts.data?.policy_version}), the exact wording shown, how consent was captured, and who captured it. Customers may decline biometrics and be verified manually instead.
      </Alert>
      {Object.entries(texts.data?.texts ?? {}).map(([type, text]) => {
        const perPerson = type === 'biometric_processing';
        const rows = perPerson ? subjects : [{ key: 'self', repId: null, name: customer.display_name }];
        return (
          <Card key={type}>
            <div className="flex flex-wrap items-start justify-between gap-2">
              <div>
                <p className="font-medium text-slate-900">{TITLES[type]?.title ?? label(type)}</p>
                <p className="text-xs text-slate-500">{TITLES[type]?.required}</p>
              </div>
            </div>
            <blockquote className="mt-3 rounded-lg border-l-4 border-brand-200 bg-slate-50 px-4 py-3 text-sm text-slate-700">{text}</blockquote>
            {perPerson && rows.length === 0 && <p className="mt-3 text-sm text-amber-700">Add a representative before recording biometric consent.</p>}
            <div className="mt-4 space-y-3">
              {rows.map((s) => (
                <ConsentRow key={s.key} customerId={customer.id} type={type} repId={s.repId} subject={perPerson || customer.type === 'business' ? s.name : null} record={current(type, s.repId)} methods={texts.data?.methods ?? []} editable={editable} />
              ))}
            </div>
          </Card>
        );
      })}
    </div>
  );
}

function ConsentRow({
  customerId,
  type,
  repId,
  subject,
  record,
  methods,
  editable,
}: {
  customerId: number;
  type: string;
  repId: number | null;
  subject: string | null;
  record: Consent | undefined;
  methods: string[];
  editable: boolean;
}) {
  const [method, setMethod] = useState('signed_form');
  const save = useAction({
    fn: (granted: boolean) => api.post(`/api/customers/${customerId}/consents`, { consent_type: type, granted, capture_method: method, representative_id: repId }),
    success: 'Consent decision recorded',
    invalidate: customerKeys(customerId),
  });
  const withdraw = useAction({
    fn: () => api.post(`/api/customers/${customerId}/consents/${record!.id}/withdraw`),
    success: 'Consent withdrawn',
    invalidate: customerKeys(customerId),
  });

  return (
    <div className="flex flex-col gap-3 rounded-xl border border-slate-200 p-3 sm:flex-row sm:items-center sm:justify-between">
      <div className="min-w-0 text-sm">
        {subject && <p className="font-medium text-slate-800">{subject}</p>}
        {record ? (
          <p className="flex flex-wrap items-center gap-2 text-slate-600">
            <Badge tone={record.granted ? 'green' : 'red'} dot>
              {record.granted ? 'Granted' : 'Declined'}
            </Badge>
            <span className="text-xs">
              {label(record.capture_method)} · {dateTime(record.created_at)} · by {record.captured_by?.name}
            </span>
          </p>
        ) : (
          <p className="text-slate-500">Not recorded</p>
        )}
      </div>
      {editable && (
        <div className="flex flex-wrap items-center gap-2">
          <Select value={method} onChange={(e) => setMethod(e.target.value)} className="h-8 w-auto text-xs" aria-label="Capture method">
            {methods.map((m) => (
              <option key={m} value={m}>
                {label(m)}
              </option>
            ))}
          </Select>
          {(!record || !record.granted) && (
            <Button size="sm" icon={<FileSignature className="size-3.5" />} loading={save.isPending && save.variables === true} onClick={() => save.mutate(true)}>
              Record consent
            </Button>
          )}
          {!record && (
            <Button size="sm" variant="secondary" loading={save.isPending && save.variables === false} onClick={() => save.mutate(false)}>
              Declined
            </Button>
          )}
          {record?.granted && (
            <Button size="sm" variant="ghost" loading={withdraw.isPending} onClick={() => withdraw.mutate()}>
              Withdraw
            </Button>
          )}
        </div>
      )}
    </div>
  );
}
