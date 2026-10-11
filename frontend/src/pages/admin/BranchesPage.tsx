import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Building2, Pencil, Plus } from 'lucide-react';
import { api, errorMessage } from '../../lib/api';
import { useAuth } from '../../lib/auth';
import { useAction, useForm } from '../../lib/hooks';
import type { Branch } from '../../lib/types';
import { GHANA_REGIONS } from '../customers/CustomerForm';
import { Modal } from '../../components/Modal';
import { Badge, Button, Card, Checkbox, EmptyState, ErrorState, LoadingBlock, PageHeader, SelectField, TextField } from '../../components/ui';

export default function BranchesPage() {
  const { can } = useAuth();
  const [editing, setEditing] = useState<Branch | 'new' | null>(null);
  const { data, isLoading, error, refetch } = useQuery({ queryKey: ['/api/branches'], queryFn: () => api.get<{ data: Branch[] }>('/api/branches') });

  return (
    <>
      <PageHeader
        title="Branches"
        description="Staff see records only for the branches they're assigned to, unless they hold all-branch access."
        actions={
          can('branches.manage') && (
            <Button icon={<Plus className="size-4" />} onClick={() => setEditing('new')}>
              New branch
            </Button>
          )
        }
      />
      {isLoading ? (
        <LoadingBlock />
      ) : error ? (
        <ErrorState message={errorMessage(error)} onRetry={() => refetch()} />
      ) : (data?.data ?? []).length === 0 ? (
        <Card>
          <EmptyState title="No branches" />
        </Card>
      ) : (
        <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
          {data!.data.map((b) => (
            <Card key={b.id} className={b.is_active ? '' : 'opacity-60'}>
              <div className="flex items-start justify-between">
                <div className="flex items-center gap-3">
                  <div className="flex size-10 items-center justify-center rounded-xl bg-brand-50 text-brand-700">
                    <Building2 className="size-5" />
                  </div>
                  <div>
                    <p className="font-semibold text-slate-900">{b.name}</p>
                    <p className="text-xs text-slate-500">
                      {b.code}
                      {b.city ? ` · ${b.city}` : ''}
                    </p>
                  </div>
                </div>
                <div className="flex items-center gap-1">
                  {b.is_head_office && <Badge tone="blue">Head office</Badge>}
                  {!b.is_active && <Badge>Inactive</Badge>}
                  {can('branches.manage') && (
                    <button className="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100" aria-label={`Edit ${b.name}`} onClick={() => setEditing(b)}>
                      <Pencil className="size-4" />
                    </button>
                  )}
                </div>
              </div>
              <dl className="mt-4 grid grid-cols-3 gap-2 text-center">
                {[
                  ['Staff', b.users_count],
                  ['Customers', b.customers_count],
                  ['Open loans', b.open_loans_count],
                ].map(([k, v]) => (
                  <div key={k as string} className="rounded-lg bg-slate-50 py-2">
                    <dd className="tabular text-lg font-semibold text-slate-900">{v ?? 0}</dd>
                    <dt className="text-xs text-slate-500">{k}</dt>
                  </div>
                ))}
              </dl>
              {(b.phone || b.address) && <p className="mt-3 text-xs text-slate-500">{[b.address, b.phone].filter(Boolean).join(' · ')}</p>}
            </Card>
          ))}
        </div>
      )}
      {editing && <BranchModal branch={editing === 'new' ? null : editing} onClose={() => setEditing(null)} />}
    </>
  );
}

function BranchModal({ branch: b, onClose }: { branch: Branch | null; onClose: () => void }) {
  const form = useForm({
    name: b?.name ?? '', code: b?.code ?? '', phone: b?.phone ?? '', email: b?.email ?? '', address: b?.address ?? '', city: b?.city ?? '',
    region: b?.region ?? '', is_head_office: b?.is_head_office ?? false, is_active: b?.is_active ?? true,
  });
  const { values: v, set, errors } = form;
  const save = useAction({
    fn: () => {
      const body = Object.fromEntries(Object.entries(v).map(([k, x]) => [k, x === '' ? null : x]));
      return b ? api.put(`/api/branches/${b.id}`, body) : api.post('/api/branches', body);
    },
    success: 'Branch saved',
    invalidate: [['/api/branches'], ['lookups'], ['me']],
    onSuccess: onClose,
    onError: form.absorb,
  });
  return (
    <Modal
      open
      onClose={onClose}
      title={b ? `Edit ${b.name}` : 'New branch'}
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>
            Cancel
          </Button>
          <Button loading={save.isPending} onClick={() => save.mutate()}>
            Save
          </Button>
        </>
      }
    >
      <div className="grid gap-4 sm:grid-cols-2">
        <TextField label="Name" value={v.name} onChange={(x) => set('name', x)} error={errors.name} required />
        <TextField label="Code" value={v.code} onChange={(x) => set('code', x.toUpperCase())} error={errors.code} required />
        <TextField label="Phone" value={v.phone} onChange={(x) => set('phone', x)} />
        <TextField label="Email" type="email" value={v.email} onChange={(x) => set('email', x)} error={errors.email} />
        <TextField label="Address" value={v.address} onChange={(x) => set('address', x)} className="sm:col-span-2" />
        <TextField label="City / town" value={v.city} onChange={(x) => set('city', x)} />
        <SelectField label="Region" value={v.region} onChange={(x) => set('region', x)} options={GHANA_REGIONS.map((r) => ({ value: r, label: r }))} placeholder="—" />
        <Checkbox checked={v.is_head_office} onChange={(x) => set('is_head_office', x)} label="Head office" />
        <Checkbox checked={v.is_active} onChange={(x) => set('is_active', x)} label="Active" />
      </div>
    </Modal>
  );
}
