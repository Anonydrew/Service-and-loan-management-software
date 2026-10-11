import { Plus, Trash2 } from 'lucide-react';
import type { Lookups } from '../../lib/types';
import { Button, Checkbox, SectionTitle, SelectField, TextField, Textarea, Field } from '../../components/ui';

export interface RepresentativeInput {
  full_name: string;
  position: string;
  phone: string;
  email: string;
  date_of_birth: string;
  id_type: string;
  id_number: string;
  is_signatory: boolean;
}

export const emptyRepresentative = (): RepresentativeInput => ({
  full_name: '',
  position: '',
  phone: '',
  email: '',
  date_of_birth: '',
  id_type: 'ghana_card',
  id_number: '',
  is_signatory: true,
});

export interface CustomerFormValues extends Record<string, unknown> {
  type: 'individual' | 'business';
  branch_id: string;
  title: string;
  first_name: string;
  middle_name: string;
  last_name: string;
  gender: string;
  date_of_birth: string;
  marital_status: string;
  nationality: string;
  occupation: string;
  employer: string;
  id_type: string;
  id_number: string;
  business_name: string;
  trading_name: string;
  registration_number: string;
  tin: string;
  business_type: string;
  industry: string;
  date_of_incorporation: string;
  monthly_income: string;
  phone: string;
  alt_phone: string;
  email: string;
  address: string;
  digital_address: string;
  city: string;
  region: string;
  relationship_officer_id: string;
  notes: string;
  representatives: RepresentativeInput[];
}

export const GHANA_REGIONS = [
  'Greater Accra', 'Ashanti', 'Western', 'Western North', 'Central', 'Eastern', 'Volta', 'Oti', 'Northern', 'Savannah',
  'North East', 'Upper East', 'Upper West', 'Bono', 'Bono East', 'Ahafo',
];

interface Props {
  values: CustomerFormValues;
  set: <K extends keyof CustomerFormValues>(key: K, value: CustomerFormValues[K]) => void;
  errors: Record<string, string>;
  lookups: Lookups | undefined;
  editing?: boolean;
}

/** Shared fields for registering and editing individual or business customers. */
export function CustomerForm({ values, set, errors, lookups, editing }: Props) {
  const isBusiness = values.type === 'business';
  const branches = (lookups?.branches ?? []).map((b) => ({ value: b.id, label: b.name }));
  const staff = (lookups?.staff ?? []).map((s) => ({ value: s.id, label: s.name }));

  const setRep = (i: number, patch: Partial<RepresentativeInput>) =>
    set(
      'representatives',
      values.representatives.map((r, idx) => (idx === i ? { ...r, ...patch } : r)),
    );

  return (
    <div className="space-y-8">
      {isBusiness ? (
        <section>
          <SectionTitle>Business details</SectionTitle>
          <div className="grid gap-4 sm:grid-cols-2">
            <TextField label="Registered business name" value={values.business_name} onChange={(v) => set('business_name', v)} error={errors.business_name} required />
            <TextField label="Trading name" value={values.trading_name} onChange={(v) => set('trading_name', v)} error={errors.trading_name} />
            <TextField label="Registration number" hint="Registrar-General / ORC number" value={values.registration_number} onChange={(v) => set('registration_number', v)} error={errors.registration_number} required />
            <TextField label="TIN" value={values.tin} onChange={(v) => set('tin', v)} error={errors.tin} />
            <SelectField label="Business type" value={values.business_type} onChange={(v) => set('business_type', v)} options={lookups?.enums.business_types ?? []} placeholder="Select…" error={errors.business_type} required />
            <TextField label="Industry" value={values.industry} onChange={(v) => set('industry', v)} error={errors.industry} />
            <TextField label="Date of incorporation" type="date" value={values.date_of_incorporation} onChange={(v) => set('date_of_incorporation', v)} error={errors.date_of_incorporation} />
            <TextField label="Average monthly revenue (GHS)" type="number" min="0" step="0.01" value={values.monthly_income} onChange={(v) => set('monthly_income', v)} error={errors.monthly_income} />
          </div>
        </section>
      ) : (
        <section>
          <SectionTitle>Personal details</SectionTitle>
          <div className="grid gap-4 sm:grid-cols-3">
            <SelectField label="Title" value={values.title} onChange={(v) => set('title', v)} options={['Mr', 'Mrs', 'Ms', 'Miss', 'Dr', 'Rev', 'Prof'].map((t) => ({ value: t, label: t }))} placeholder="—" />
            <TextField label="First name" value={values.first_name} onChange={(v) => set('first_name', v)} error={errors.first_name} required />
            <TextField label="Middle name" value={values.middle_name} onChange={(v) => set('middle_name', v)} error={errors.middle_name} />
            <TextField label="Last name" value={values.last_name} onChange={(v) => set('last_name', v)} error={errors.last_name} required />
            <TextField label="Date of birth" type="date" value={values.date_of_birth} onChange={(v) => set('date_of_birth', v)} error={errors.date_of_birth} required />
            <SelectField label="Gender" value={values.gender} onChange={(v) => set('gender', v)} options={['female', 'male', 'other']} placeholder="—" error={errors.gender} />
            <SelectField label="Marital status" value={values.marital_status} onChange={(v) => set('marital_status', v)} options={['single', 'married', 'divorced', 'widowed', 'other']} placeholder="—" />
            <TextField label="Occupation" value={values.occupation} onChange={(v) => set('occupation', v)} error={errors.occupation} />
            <TextField label="Employer" value={values.employer} onChange={(v) => set('employer', v)} error={errors.employer} />
            <TextField label="Monthly income (GHS)" type="number" min="0" step="0.01" value={values.monthly_income} onChange={(v) => set('monthly_income', v)} error={errors.monthly_income} />
            <SelectField label="ID type" value={values.id_type} onChange={(v) => set('id_type', v)} options={lookups?.enums.id_types ?? []} placeholder="Select…" error={errors.id_type} required />
            <TextField
              label="ID number"
              value={values.id_number}
              onChange={(v) => set('id_number', v)}
              error={errors.id_number}
              required={!editing}
              placeholder={editing ? 'Leave blank to keep the current number' : 'e.g. GHA-000000000-0'}
              autoComplete="off"
              hint="Stored encrypted. Only the last four characters are displayed."
            />
          </div>
        </section>
      )}

      <section>
        <SectionTitle>Contact & location</SectionTitle>
        <div className="grid gap-4 sm:grid-cols-3">
          <TextField label="Phone" type="tel" value={values.phone} onChange={(v) => set('phone', v)} error={errors.phone} required placeholder="+233 24 000 0000" />
          <TextField label="Alternative phone" type="tel" value={values.alt_phone} onChange={(v) => set('alt_phone', v)} error={errors.alt_phone} />
          <TextField label="Email" type="email" value={values.email} onChange={(v) => set('email', v)} error={errors.email} />
          <TextField label="Address" value={values.address} onChange={(v) => set('address', v)} error={errors.address} className="sm:col-span-2" />
          <TextField label="GhanaPost digital address" value={values.digital_address} onChange={(v) => set('digital_address', v)} error={errors.digital_address} placeholder="GA-123-4567" />
          <TextField label="City / town" value={values.city} onChange={(v) => set('city', v)} error={errors.city} />
          <SelectField label="Region" value={values.region} onChange={(v) => set('region', v)} options={GHANA_REGIONS.map((r) => ({ value: r, label: r }))} placeholder="—" />
        </div>
      </section>

      <section>
        <SectionTitle>Relationship</SectionTitle>
        <div className="grid gap-4 sm:grid-cols-3">
          <SelectField label="Home branch" value={values.branch_id} onChange={(v) => set('branch_id', v)} options={branches} placeholder="Select…" error={errors.branch_id} required />
          <SelectField label="Relationship officer" value={values.relationship_officer_id} onChange={(v) => set('relationship_officer_id', v)} options={staff} placeholder="—" error={errors.relationship_officer_id} />
          <Field label="Notes" className="sm:col-span-3">
            {(id) => <Textarea id={id} value={values.notes} onChange={(e) => set('notes', e.target.value)} />}
          </Field>
        </div>
      </section>

      {isBusiness && !editing && (
        <section>
          <div className="mb-3 flex items-center justify-between">
            <SectionTitle>Directors & authorised representatives</SectionTitle>
            <Button variant="soft" size="sm" icon={<Plus className="size-3.5" />} onClick={() => set('representatives', [...values.representatives, emptyRepresentative()])}>
              Add person
            </Button>
          </div>
          <p className="mb-4 text-sm text-slate-500">
            A business is verified through its documents and the people who act for it. Each representative's identity is checked during onboarding.
          </p>
          {errors.representatives && <p className="mb-3 text-sm text-red-600">{errors.representatives}</p>}
          <div className="space-y-4">
            {values.representatives.map((rep, i) => (
              <div key={i} className="rounded-xl border border-slate-200 p-4">
                <div className="mb-3 flex items-center justify-between">
                  <p className="text-sm font-medium text-slate-700">
                    {i === 0 ? 'Primary representative' : `Representative ${i + 1}`}
                  </p>
                  {values.representatives.length > 1 && (
                    <button className="rounded-lg p-1 text-slate-400 hover:bg-red-50 hover:text-red-600" aria-label="Remove representative" onClick={() => set('representatives', values.representatives.filter((_, idx) => idx !== i))}>
                      <Trash2 className="size-4" />
                    </button>
                  )}
                </div>
                <div className="grid gap-4 sm:grid-cols-3">
                  <TextField label="Full name" value={rep.full_name} onChange={(v) => setRep(i, { full_name: v })} error={errors[`representatives.${i}.full_name`]} required />
                  <TextField label="Position" value={rep.position} onChange={(v) => setRep(i, { position: v })} placeholder="Director" />
                  <TextField label="Phone" value={rep.phone} onChange={(v) => setRep(i, { phone: v })} />
                  <TextField label="Date of birth" type="date" value={rep.date_of_birth} onChange={(v) => setRep(i, { date_of_birth: v })} />
                  <SelectField label="ID type" value={rep.id_type} onChange={(v) => setRep(i, { id_type: v })} options={lookups?.enums.id_types ?? []} />
                  <TextField label="ID number" value={rep.id_number} onChange={(v) => setRep(i, { id_number: v })} autoComplete="off" />
                </div>
                <div className="mt-3">
                  <Checkbox checked={rep.is_signatory} onChange={(v) => setRep(i, { is_signatory: v })} label="Authorised signatory" description="Can sign agreements on behalf of the business." />
                </div>
              </div>
            ))}
          </div>
        </section>
      )}
    </div>
  );
}

/** Builds the API payload, dropping blanks and fields that do not apply to the type. */
export function customerPayload(values: CustomerFormValues, editing = false): Record<string, unknown> {
  const individual = ['title', 'first_name', 'middle_name', 'last_name', 'gender', 'date_of_birth', 'marital_status', 'nationality', 'occupation', 'employer', 'id_type', 'id_number'];
  const business = ['business_name', 'trading_name', 'registration_number', 'tin', 'business_type', 'industry', 'date_of_incorporation'];
  const common = ['branch_id', 'phone', 'alt_phone', 'email', 'address', 'digital_address', 'city', 'region', 'monthly_income', 'relationship_officer_id', 'notes'];
  const keys = [...common, ...(values.type === 'business' ? business : individual)];
  const out: Record<string, unknown> = editing ? {} : { type: values.type };

  for (const key of keys) {
    const v = values[key];
    if (key === 'id_number' && editing && v === '') continue;
    out[key] = v === '' ? null : v;
  }
  if (values.type === 'business' && !editing) {
    out.representatives = values.representatives.map((r) => Object.fromEntries(Object.entries(r).map(([k, v]) => [k, v === '' ? null : v])));
  }
  return out;
}
