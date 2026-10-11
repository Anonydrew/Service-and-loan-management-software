import { useState } from 'react';
import { useNavigate, useSearchParams } from 'react-router-dom';
import { Briefcase, Plus } from 'lucide-react';
import { api } from '../../lib/api';
import { useAuth } from '../../lib/auth';
import { dateTime, money } from '../../lib/format';
import { useAction, useForm, useLookups, usePaginated, useUrlFilters } from '../../lib/hooks';
import type { Customer, ServiceRequest } from '../../lib/types';
import { CustomerPicker } from '../../components/CustomerPicker';
import { DataTable, FilterSelect, Pagination, SearchInput, Toolbar } from '../../components/DataTable';
import { Modal } from '../../components/Modal';
import { Button, Card, Checkbox, Field, PageHeader, SelectField, StatusBadge, Tabs, TextField, Textarea } from '../../components/ui';

export default function ServiceRequestsPage() {
  const { can } = useAuth();
  const [params] = useSearchParams();
  const [creating, setCreating] = useState(params.get('new') === '1');
  const { filters, set } = useUrlFilters();
  const query = Object.fromEntries(Object.entries(filters).filter(([k]) => k !== 'new'));
  const { data, isLoading, isFetching, error, refetch } = usePaginated<ServiceRequest>('/api/service-requests', query);

  return (
    <>
      <PageHeader
        title="Service requests"
        description="Customer service work from request through completion and billing."
        actions={
          can('service_requests.create') && (
            <Button icon={<Plus className="size-4" />} onClick={() => setCreating(true)}>
              New request
            </Button>
          )
        }
      />
      <Tabs
        value={filters.mine === '1' ? 'mine' : (filters.status ?? '')}
        onChange={(v) => {
          if (v === 'mine') {
            set('status', null);
            set('mine', '1');
          } else {
            set('mine', null);
            set('status', v);
          }
        }}
        tabs={[
          { value: '', label: 'All' },
          { value: 'mine', label: 'Assigned to me' },
          { value: 'new', label: 'New' },
          { value: 'scheduled', label: 'Scheduled' },
          { value: 'in_progress', label: 'In progress' },
          { value: 'on_hold', label: 'On hold' },
          { value: 'completed', label: 'Completed' },
        ]}
      />
      <Card padded={false}>
        <Toolbar>
          <SearchInput value={filters.q ?? ''} onChange={(v) => set('q', v)} placeholder="Request number or customer…" />
          <FilterSelect value={filters.priority ?? ''} onChange={(v) => set('priority', v)} options={['low', 'normal', 'high', 'urgent']} placeholder="Any priority" />
        </Toolbar>
        <DataTable<ServiceRequest>
          rows={data?.data}
          loading={isLoading || isFetching}
          error={error}
          onRetry={refetch}
          rowLink={(r) => `/services/requests/${r.id}`}
          empty={{ title: 'No service requests', description: 'Create a request when a customer asks for a service.' }}
          columns={[
            {
              key: 'n',
              header: 'Request',
              render: (r) => (
                <div className="flex items-center gap-3">
                  <Briefcase className="size-4 text-slate-400" />
                  <div>
                    <p className="font-medium text-slate-900">{r.request_number}</p>
                    <p className="text-xs text-slate-500">{r.service?.name}</p>
                  </div>
                </div>
              ),
            },
            { key: 'c', header: 'Customer', render: (r) => r.customer?.display_name },
            { key: 'a', header: 'Assigned to', hideOnMobile: true, render: (r) => r.assignee?.name ?? <span className="text-slate-400">Unassigned</span> },
            { key: 'sch', header: 'Scheduled', hideOnMobile: true, render: (r) => dateTime(r.scheduled_at) },
            { key: 'p', header: 'Priority', hideOnMobile: true, render: (r) => <StatusBadge status={r.priority} /> },
            { key: 't', header: 'Total', align: 'right', render: (r) => <span className="tabular">{money(r.total_minor)}</span> },
            { key: 's', header: 'Status', render: (r) => <StatusBadge status={r.status} /> },
          ]}
        />
        <Pagination page={data} onPage={(p) => set('page', String(p))} />
      </Card>
      {creating && <NewRequestModal initialCustomerId={params.get('customer_id')} onClose={() => setCreating(false)} />}
    </>
  );
}

function NewRequestModal({ onClose, initialCustomerId }: { onClose: () => void; initialCustomerId: string | null }) {
  const navigate = useNavigate();
  const { data: lookups } = useLookups();
  const [customer, setCustomer] = useState<Customer | null>(null);
  const [schedule, setSchedule] = useState(false);
  const form = useForm({ service_id: '', priority: 'normal', description: '', quantity: '1', unit_price: '', discount: '', scheduled_at: '', assigned_to: '' });
  const { values: v, set, errors } = form;
  const service = lookups?.services.find((s) => String(s.id) === v.service_id);

  const save = useAction({
    fn: () =>
      api.post<ServiceRequest>('/api/service-requests', {
        customer_id: customer?.id,
        service_id: v.service_id,
        priority: v.priority,
        description: v.description || null,
        quantity: v.quantity,
        unit_price: service?.price_is_variable ? v.unit_price || null : null,
        discount: v.discount || null,
        scheduled_at: schedule && v.scheduled_at ? v.scheduled_at : null,
        assigned_to: v.assigned_to || null,
      }),
    success: (r) => `Request ${r.request_number} created`,
    invalidate: [['/api/service-requests'], ['dashboard']],
    onSuccess: (r) => navigate(`/services/requests/${r.id}`),
    onError: form.absorb,
  });

  return (
    <Modal
      open
      onClose={onClose}
      size="lg"
      title="New service request"
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>
            Cancel
          </Button>
          <Button disabled={!customer || !v.service_id} loading={save.isPending} onClick={() => save.mutate()}>
            Create request
          </Button>
        </>
      }
    >
      <div className="grid gap-4 sm:grid-cols-2">
        <div className="sm:col-span-2">
          <CustomerPicker value={customer} onChange={setCustomer} error={errors.customer_id} initialId={initialCustomerId} />
        </div>
        <SelectField
          label="Service"
          value={v.service_id}
          onChange={(x) => set('service_id', x)}
          options={(lookups?.services ?? []).map((s) => ({ value: s.id, label: `${s.name} — ${money(s.price_minor)}` }))}
          placeholder="Select…"
          error={errors.service_id}
          required
          className="sm:col-span-2"
        />
        <TextField label="Quantity" type="number" min="0.01" step="0.01" value={v.quantity} onChange={(x) => set('quantity', x)} error={errors.quantity} />
        {service?.price_is_variable ? (
          <TextField label="Unit price (GHS)" type="number" value={v.unit_price} onChange={(x) => set('unit_price', x)} error={errors.unit_price} hint={`Catalogue price ${money(service.price_minor)}`} />
        ) : (
          <TextField label="Discount (GHS)" type="number" value={v.discount} onChange={(x) => set('discount', x)} error={errors.discount} />
        )}
        <SelectField label="Priority" value={v.priority} onChange={(x) => set('priority', x)} options={['low', 'normal', 'high', 'urgent']} />
        <SelectField label="Assign to" value={v.assigned_to} onChange={(x) => set('assigned_to', x)} options={(lookups?.staff ?? []).map((s) => ({ value: s.id, label: s.name }))} placeholder="Unassigned" error={errors.assigned_to} />
        <div className="sm:col-span-2">
          <Checkbox checked={schedule || !!service?.requires_appointment} onChange={setSchedule} label="Book an appointment" description={service?.requires_appointment ? 'This service requires an appointment.' : undefined} />
        </div>
        {(schedule || service?.requires_appointment) && <TextField label="Appointment" type="datetime-local" value={v.scheduled_at} onChange={(x) => { set('scheduled_at', x); setSchedule(true); }} error={errors.scheduled_at} />}
        <Field label="Details" className="sm:col-span-2">
          {(id) => <Textarea id={id} value={v.description} onChange={(e) => set('description', e.target.value)} placeholder="What does the customer need?" />}
        </Field>
      </div>
    </Modal>
  );
}
