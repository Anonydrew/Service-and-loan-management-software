import { useState } from 'react';
import { Link, useNavigate, useParams, useSearchParams } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { ArrowLeft, ArrowRight, BadgeCheck, CircleCheck, UserCheck } from 'lucide-react';
import { api, errorMessage } from '../../lib/api';
import { useAuth } from '../../lib/auth';
import { label } from '../../lib/format';
import { useAction } from '../../lib/hooks';
import type { CustomerDetail } from '../../lib/types';
import { Alert, Button, Card, CardHeader, DescriptionList, ErrorState, Field, LoadingBlock, PageHeader, StatusBadge, Textarea } from '../../components/ui';
import { Stepper, ONBOARDING_STEPS, type StepKey } from './Stepper';
import { ConsentPanel } from './ConsentPanel';
import { DocumentsPanel } from './DocumentsPanel';
import { BiometricPanel } from './BiometricPanel';
import { Checklist } from './Checklist';
import { customerKeys } from './shared';
import { ReviewDecision } from './ReviewDecision';

export default function OnboardingPage() {
  const { id = '' } = useParams();
  const [params, setParams] = useSearchParams();
  const navigate = useNavigate();
  const step = (params.get('step') as StepKey) || 'consent';
  const setStep = (s: StepKey) => setParams({ step: s }, { replace: false });

  const { data, isLoading, error, refetch } = useQuery({ queryKey: ['customer', id], queryFn: () => api.get<CustomerDetail>(`/api/customers/${id}`) });

  if (isLoading) return <LoadingBlock rows={8} />;
  if (error || !data) return <ErrorState message={errorMessage(error)} onRetry={() => refetch()} />;

  const { customer, onboarding } = data;
  const items = onboarding.items;
  const done = (keys: (k: string) => boolean) => {
    const relevant = items.filter((i) => keys(i.key) && i.required);
    return relevant.length === 0 ? 'optional' : relevant.every((i) => i.status === 'complete') ? 'complete' : 'incomplete';
  };
  const statuses = {
    details: done((k) => k === 'details'),
    consent: done((k) => k === 'consent' || k.startsWith('biometric_consent')),
    documents: done((k) => k.includes('document') || k.startsWith('representative')),
    verification: done((k) => k.startsWith('face_') || k.startsWith('fingerprint_')),
    review: customer.onboarding_status === 'completed' ? 'complete' : 'incomplete',
  } as const;

  const index = ONBOARDING_STEPS.findIndex((s) => s.key === step);
  const next = ONBOARDING_STEPS[index + 1]?.key;
  const prev = ONBOARDING_STEPS[index - 1]?.key;
  const closed = ['completed', 'rejected'].includes(customer.onboarding_status);

  return (
    <>
      <PageHeader
        back={
          <Link to={`/customers/${customer.id}`} className="inline-flex items-center gap-1 text-sm text-slate-500 hover:text-slate-800">
            <ArrowLeft className="size-4" /> {customer.display_name}
          </Link>
        }
        title="Customer onboarding"
        description={
          <span className="flex flex-wrap items-center gap-2">
            {customer.customer_number} · {label(customer.type)} <StatusBadge status={customer.onboarding_status} />
          </span>
        }
      />

      <Stepper steps={ONBOARDING_STEPS} current={step} statuses={statuses} onSelect={setStep} />

      {closed && (
        <div className="mt-6">
          <Alert tone={customer.onboarding_status === 'completed' ? 'green' : 'red'} icon={<BadgeCheck className="size-4" />} title={customer.onboarding_status === 'completed' ? 'Onboarding complete' : 'Onboarding rejected'}>
            This customer's onboarding is closed. Records below are read-only.
          </Alert>
        </div>
      )}

      <div className="mt-6 grid gap-6 lg:grid-cols-3">
        <div className="lg:col-span-2">
          {step === 'details' && (
            <Card>
              <CardHeader
                title="Customer details"
                actions={
                  <Link to={`/customers/${customer.id}?edit=1`} className="text-sm font-medium text-brand-700 hover:underline">
                    Edit details
                  </Link>
                }
              />
              <DescriptionList
                items={
                  customer.type === 'business'
                    ? [
                        { label: 'Business name', value: customer.business_name },
                        { label: 'Registration no.', value: customer.registration_number },
                        { label: 'Business type', value: label(customer.business_type) },
                        { label: 'Phone', value: customer.phone },
                        { label: 'Representatives', value: (customer.representatives ?? []).map((r) => r.full_name).join(', ') },
                        { label: 'Branch', value: customer.branch?.name },
                      ]
                    : [
                        { label: 'Full name', value: customer.display_name },
                        { label: 'Date of birth', value: customer.date_of_birth },
                        { label: 'ID', value: customer.id_type ? `${label(customer.id_type)} ${customer.id_number_masked ?? ''}` : null },
                        { label: 'Phone', value: customer.phone },
                        { label: 'Address', value: [customer.address, customer.digital_address].filter(Boolean).join(' · ') },
                        { label: 'Branch', value: customer.branch?.name },
                      ]
                }
              />
            </Card>
          )}
          {step === 'consent' && <ConsentPanel customer={customer} readOnly={closed} />}
          {step === 'documents' && <DocumentsPanel customer={customer} readOnly={closed} />}
          {step === 'verification' && <BiometricPanel customer={customer} onReferForReview={() => setStep('review')} />}
          {step === 'review' && <ReviewStep data={data} onDone={() => navigate(`/customers/${customer.id}`)} />}

          <div className="mt-6 flex justify-between">
            <Button variant="secondary" disabled={!prev} icon={<ArrowLeft className="size-4" />} onClick={() => prev && setStep(prev)}>
              Back
            </Button>
            {next && (
              <Button variant="secondary" onClick={() => setStep(next)}>
                Next: {ONBOARDING_STEPS[index + 1].label} <ArrowRight className="size-4" />
              </Button>
            )}
          </div>
        </div>

        <div className="space-y-6">
          <Card>
            <CardHeader title="Onboarding checklist" description={onboarding.ready ? 'All requirements met.' : 'Required by company policy.'} />
            <Checklist items={items} />
          </Card>
        </div>
      </div>
    </>
  );
}

function ReviewStep({ data, onDone }: { data: CustomerDetail; onDone: () => void }) {
  const { can } = useAuth();
  const { customer, onboarding } = data;
  const [reason, setReason] = useState('');

  const complete = useAction({
    fn: () => api.post(`/api/customers/${customer.id}/onboarding/complete`),
    success: `${customer.display_name} is now an active customer`,
    invalidate: customerKeys(customer.id),
    onSuccess: onDone,
  });
  const refer = useAction({
    fn: () => api.post(`/api/customers/${customer.id}/onboarding/refer`, { reason }),
    success: 'Referred for manual identity review',
    invalidate: customerKeys(customer.id),
  });

  if (customer.onboarding_status === 'completed') {
    return (
      <Card>
        <div className="flex items-center gap-3">
          <CircleCheck className="size-8 text-emerald-600" />
          <div>
            <p className="font-medium text-slate-900">Onboarding complete</p>
            <p className="text-sm text-slate-500">The customer can now apply for loans and services.</p>
          </div>
        </div>
      </Card>
    );
  }

  if (customer.onboarding_status === 'manual_review') {
    return (
      <div className="space-y-4">
        <Alert tone="amber" icon={<UserCheck className="size-4" />} title="Awaiting manual identity review">
          Reason given: {customer.manual_review_reason}
        </Alert>
        {can('onboarding.review') && <ReviewDecision customer={customer} />}
      </div>
    );
  }

  return (
    <div className="space-y-6">
      <Card>
        <CardHeader title="Complete onboarding" description="All required checklist items must be complete." />
        {onboarding.ready ? (
          <Alert tone="green" title="Ready to complete">
            Identity evidence and consent are in place.
          </Alert>
        ) : (
          <Alert tone="amber" title="Some requirements are outstanding">
            See the checklist. If the customer cannot complete a step (for example, they decline biometrics or no device is available), refer them for manual review.
          </Alert>
        )}
        {can('onboarding.manage') && (
          <div className="mt-4 flex justify-end">
            <Button icon={<BadgeCheck className="size-4" />} disabled={!onboarding.ready} loading={complete.isPending} onClick={() => complete.mutate()}>
              Complete onboarding
            </Button>
          </div>
        )}
      </Card>

      {can('onboarding.manage') && (
        <Card>
          <CardHeader title="Refer for manual review" description="A compliance reviewer (not you) will inspect original documents and decide." />
          <Field label="Why can't standard verification be completed?" required>
            {(fid) => <Textarea id={fid} value={reason} onChange={(e) => setReason(e.target.value)} placeholder="e.g. Customer declined biometric capture; Ghana Card photo is worn." />}
          </Field>
          <div className="mt-4 flex justify-end">
            <Button variant="secondary" disabled={reason.trim().length < 5} loading={refer.isPending} onClick={() => refer.mutate()}>
              Refer for review
            </Button>
          </div>
        </Card>
      )}
    </div>
  );
}
