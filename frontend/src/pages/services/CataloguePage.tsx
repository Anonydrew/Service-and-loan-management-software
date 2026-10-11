import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Pencil, Plus } from 'lucide-react';
import { api } from '../../lib/api';
import { useAuth } from '../../lib/auth';
import { money, toMajor } from '../../lib/format';
import { useAction, useForm } from '../../lib/hooks';
import type { Service } from '../../lib/types';
import { DataTable } from '../../components/DataTable';
import { Modal } from '../../components/Modal';
import { Badge, Button, Card, Checkbox, Field, PageHeader, TextField, Textarea } from '../../components/ui';

export default function CataloguePage() {
  const { can } = useAuth();
  const [editing, setEditing] = useState<Service | 'new' | null>(null);
  const { data, isLoading, error, refetch } = useQuery({ queryKey: ['/api/services'], queryFn: () => api.get<{ data: Service[] }>('/api/services') });

  return (
    <>
      <PageHeader
        title="Service catalogue"
        description="Services offered to customers, with prices and tax."
        actions={
          can('services.manage') && (
            <Button icon={<Plus className="size-4" />} onClick={() => setEditing('new')}>
              New service
            </Button>
          )
        }
      />
      <Card padded={false}>
        <DataTable<Service>
          rows={data?.data}
          loading={isLoading}
          error={error}
          onRetry={refetch}
          empty={{ title: 'No services yet', description: 'Add the services your company provides.' }}
          columns={[
            {
              key: 'n',
              header: 'Service',
              render: (s) => (
                <div>
                  <p className="font-medium text-slate-900">{s.name}</p>
                  <p className="text-xs text-slate-500">
                    {s.code}
                    {s.category ? ` · ${s.category}` : ''}
                  </p>
                </div>
              ),
            },
            { key: 'p', header: 'Price', align: 'right', render: (s) => <span className="tabular">{money(s.price_minor)}{s.price_is_variable && <span className="ml-1 text-xs text-slate-500">(variable)</span>}</span> },
            { key: 't', header: 'Tax', align: 'right', hideOnMobile: true, render: (s) => `${parseFloat(s.tax_rate)}%` },
            { key: 'd', header: 'Duration', hideOnMobile: true, render: (s) => (s.duration_minutes ? `${s.duration_minutes} min` : '—') },
            {
              key: 'flags',
              header: '',
              render: (s) => (
                <div className="flex flex-wrap gap-1">
                  {s.requires_appointment && <Badge tone="violet">Appointment</Badge>}
                  {!s.is_active && <Badge>Inactive</Badge>}
                </div>
              ),
            },
            {
              key: 'e',
              header: '',
              align: 'right',
              render: (s) =>
                can('services.manage') && (
                  <button className="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 hover:text-slate-700" aria-label={`Edit ${s.name}`} onClick={() => setEditing(s)}>
                    <Pencil className="size-4" />
                  </button>
                ),
            },
          ]}
        />
      </Card>
      {editing && <ServiceModal service={editing === 'new' ? null : editing} onClose={() => setEditing(null)} />}
    </>
  );
}

function ServiceModal({ service: s, onClose }: { service: Service | null; onClose: () => void }) {
  const form = useForm({
    name: s?.name ?? '', code: s?.code ?? '', category: s?.category ?? '', description: s?.description ?? '',
    price: s ? toMajor(s.price_minor) : '', price_is_variable: s?.price_is_variable ?? false, tax_rate: s ? String(parseFloat(s.tax_rate)) : '0',
    duration_minutes: s?.duration_minutes ? String(s.duration_minutes) : '', requires_appointment: s?.requires_appointment ?? false, is_active: s?.is_active ?? true,
  });
  const { values: v, set, errors } = form;
  const save = useAction({
    fn: () => {
      const body = { ...v, duration_minutes: v.duration_minutes || null, category: v.category || null };
      return s ? api.put(`/api/services/${s.id}`, body) : api.post('/api/services', body);
    },
    success: s ? 'Service updated' : 'Service created',
    invalidate: [['/api/services'], ['lookups']],
    onSuccess: onClose,
    onError: form.absorb,
  });
  return (
    <Modal
      open
      onClose={onClose}
      size="lg"
      title={s ? `Edit ${s.name}` : 'New service'}
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
        <TextField label="Category" value={v.category} onChange={(x) => set('category', x)} error={errors.category} />
        <TextField label="Price (GHS)" type="number" step="0.01" value={v.price} onChange={(x) => set('price', x)} error={errors.price} required />
        <TextField label="Tax rate (%)" type="number" step="0.01" value={v.tax_rate} onChange={(x) => set('tax_rate', x)} error={errors.tax_rate} hint="e.g. VAT + levies" />
        <TextField label="Duration (minutes)" type="number" value={v.duration_minutes} onChange={(x) => set('duration_minutes', x)} error={errors.duration_minutes} />
        <Field label="Description" className="sm:col-span-2">
          {(id) => <Textarea id={id} value={v.description} onChange={(e) => set('description', e.target.value)} />}
        </Field>
        <Checkbox checked={v.price_is_variable} onChange={(x) => set('price_is_variable', x)} label="Variable price" description="Staff can set the price on each request." />
        <Checkbox checked={v.requires_appointment} onChange={(x) => set('requires_appointment', x)} label="Requires an appointment" />
        <Checkbox checked={v.is_active} onChange={(x) => set('is_active', x)} label="Active" />
      </div>
    </Modal>
  );
}
