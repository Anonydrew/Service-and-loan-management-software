import { useEffect } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { ArrowLeft, Building2, User } from 'lucide-react';
import { api } from '../../lib/api';
import { useAuth } from '../../lib/auth';
import { useAction, useForm, useLookups } from '../../lib/hooks';
import type { CustomerDetail } from '../../lib/types';
import { Button, Card, PageHeader, cx } from '../../components/ui';
import { Stepper, ONBOARDING_STEPS } from './Stepper';
import { CustomerForm, customerPayload, emptyRepresentative, type CustomerFormValues } from './CustomerForm';

const blank: CustomerFormValues = {
  type: 'individual', branch_id: '', title: '', first_name: '', middle_name: '', last_name: '', gender: '', date_of_birth: '',
  marital_status: '', nationality: 'GH', occupation: '', employer: '', id_type: 'ghana_card', id_number: '', business_name: '',
  trading_name: '', registration_number: '', tin: '', business_type: '', industry: '', date_of_incorporation: '', monthly_income: '',
  phone: '', alt_phone: '', email: '', address: '', digital_address: '', city: '', region: '', relationship_officer_id: '', notes: '',
  representatives: [emptyRepresentative()],
};

export default function NewCustomerPage() {
  const navigate = useNavigate();
  const { user } = useAuth();
  const { data: lookups } = useLookups();
  const form = useForm<CustomerFormValues>(blank);
  const { values, set } = form;

  useEffect(() => {
    if (!values.branch_id && lookups?.branches.length) {
      const preferred = lookups.branches.find((b) => b.id === user?.primary_branch_id) ?? lookups.branches[0];
      set('branch_id', String(preferred.id));
    }
  }, [lookups, user, values.branch_id, set]);

  const save = useAction({
    fn: () => api.post<CustomerDetail>('/api/customers', customerPayload(values)),
    success: (r) => `${r.customer.display_name} registered as ${r.customer.customer_number}`,
    invalidate: [['/api/customers']],
    onSuccess: (r) => navigate(`/customers/${r.customer.id}/onboarding?step=consent`),
    onError: form.absorb,
  });

  return (
    <>
      <PageHeader
        back={
          <Link to="/customers" className="inline-flex items-center gap-1 text-sm text-slate-500 hover:text-slate-800">
            <ArrowLeft className="size-4" /> Customers
          </Link>
        }
        title="Register a customer"
        description="Start onboarding. You'll capture consent, documents and identity verification next."
      />
      <Stepper steps={ONBOARDING_STEPS} current="details" statuses={{}} />

      <Card className="mt-6">
        <p className="mb-3 text-sm font-medium text-slate-700">Customer type</p>
        <div className="grid gap-3 sm:grid-cols-2">
          {(
            [
              ['individual', User, 'Individual', 'A person applying in their own name.'],
              ['business', Building2, 'Business', 'A registered business, verified through its documents and representatives.'],
            ] as const
          ).map(([type, Icon, title, text]) => (
            <button
              key={type}
              type="button"
              onClick={() => set('type', type)}
              aria-pressed={values.type === type}
              className={cx(
                'flex items-start gap-3 rounded-xl border p-4 text-left transition-colors',
                values.type === type ? 'border-brand-600 bg-brand-50/60 ring-1 ring-brand-600' : 'border-slate-200 hover:border-slate-300',
              )}
            >
              <div className={cx('flex size-10 shrink-0 items-center justify-center rounded-xl', values.type === type ? 'bg-brand-700 text-white' : 'bg-slate-100 text-slate-500')}>
                <Icon className="size-5" />
              </div>
              <div>
                <p className="font-medium text-slate-900">{title}</p>
                <p className="mt-0.5 text-sm text-slate-500">{text}</p>
              </div>
            </button>
          ))}
        </div>
      </Card>

      <Card className="mt-6">
        <form
          onSubmit={(e) => {
            e.preventDefault();
            save.mutate();
          }}
        >
          <CustomerForm values={values} set={set} errors={form.errors} lookups={lookups} />
          <div className="mt-8 flex justify-end gap-2 border-t border-slate-100 pt-5">
            <Button variant="secondary" onClick={() => navigate('/customers')}>
              Cancel
            </Button>
            <Button type="submit" loading={save.isPending}>
              Save and continue
            </Button>
          </div>
        </form>
      </Card>
    </>
  );
}
