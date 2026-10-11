import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { KeyRound, Pencil, UserPlus } from 'lucide-react';
import { api } from '../../lib/api';
import { useAuth } from '../../lib/auth';
import { dateTime, initials } from '../../lib/format';
import { useAction, useForm, useLookups, usePaginated, useUrlFilters } from '../../lib/hooks';
import type { Role, StaffUser } from '../../lib/types';
import { DataTable, FilterSelect, Pagination, SearchInput, Toolbar } from '../../components/DataTable';
import { Modal } from '../../components/Modal';
import { Badge, Button, Card, Checkbox, PageHeader, SectionTitle, SelectField, StatusBadge, TextField } from '../../components/ui';

export default function StaffPage() {
  const { can } = useAuth();
  const { filters, set } = useUrlFilters();
  const [editing, setEditing] = useState<StaffUser | 'new' | null>(null);
  const [resetting, setResetting] = useState<StaffUser | null>(null);
  const { data, isLoading, isFetching, error, refetch } = usePaginated<StaffUser>('/api/users', filters);

  return (
    <>
      <PageHeader
        title="Staff"
        description="User accounts, roles and branch access."
        actions={
          can('users.manage') && (
            <Button icon={<UserPlus className="size-4" />} onClick={() => setEditing('new')}>
              Add staff
            </Button>
          )
        }
      />
      <Card padded={false}>
        <Toolbar>
          <SearchInput value={filters.q ?? ''} onChange={(v) => set('q', v)} placeholder="Name or email…" />
          <FilterSelect value={filters.status ?? ''} onChange={(v) => set('status', v)} options={['active', 'disabled']} placeholder="Any status" />
        </Toolbar>
        <DataTable<StaffUser>
          rows={data?.data}
          loading={isLoading || isFetching}
          error={error}
          onRetry={refetch}
          empty={{ title: 'No staff found' }}
          columns={[
            {
              key: 'n',
              header: 'Name',
              render: (u) => (
                <div className="flex items-center gap-3">
                  <span className="flex size-9 items-center justify-center rounded-full bg-slate-800 text-xs font-semibold text-white">{initials(u.name)}</span>
                  <div>
                    <p className="font-medium text-slate-900">{u.name}</p>
                    <p className="text-xs text-slate-500">{u.email}</p>
                  </div>
                </div>
              ),
            },
            { key: 'r', header: 'Roles', render: (u) => <div className="flex flex-wrap gap-1">{u.roles.map((r) => <Badge key={r.id}>{r.name}</Badge>)}</div> },
            {
              key: 'b',
              header: 'Branches',
              hideOnMobile: true,
              render: (u) => [u.primary_branch?.name, ...u.branches.filter((b) => b.id !== u.primary_branch_id).map((b) => b.name)].filter(Boolean).join(', '),
            },
            { key: 'l', header: 'Last sign-in', hideOnMobile: true, render: (u) => dateTime(u.last_login_at) },
            { key: 's', header: 'Status', render: (u) => <StatusBadge status={u.status} /> },
            {
              key: 'a',
              header: '',
              align: 'right',
              render: (u) =>
                can('users.manage') && (
                  <div className="flex justify-end gap-1">
                    <button className="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 hover:text-slate-700" aria-label={`Reset password for ${u.name}`} onClick={() => setResetting(u)}>
                      <KeyRound className="size-4" />
                    </button>
                    <button className="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 hover:text-slate-700" aria-label={`Edit ${u.name}`} onClick={() => setEditing(u)}>
                      <Pencil className="size-4" />
                    </button>
                  </div>
                ),
            },
          ]}
        />
        <Pagination page={data} onPage={(p) => set('page', String(p))} />
      </Card>
      {editing && <StaffModal user={editing === 'new' ? null : editing} onClose={() => setEditing(null)} />}
      {resetting && <ResetModal user={resetting} onClose={() => setResetting(null)} />}
    </>
  );
}

function StaffModal({ user: u, onClose }: { user: StaffUser | null; onClose: () => void }) {
  const { data: lookups } = useLookups();
  const roles = useQuery({ queryKey: ['/api/roles'], queryFn: () => api.get<{ data: Role[] }>('/api/roles') });
  const allBranches = useQuery({ queryKey: ['/api/branches'], queryFn: () => api.get<{ data: { id: number; name: string }[] }>('/api/branches') });
  const form = useForm({
    name: u?.name ?? '', email: u?.email ?? '', phone: u?.phone ?? '', job_title: u?.job_title ?? '', password: '',
    primary_branch_id: String(u?.primary_branch_id ?? lookups?.branches[0]?.id ?? ''), status: u?.status ?? 'active',
    role_ids: (u?.roles ?? []).map((r) => r.id), branch_ids: (u?.branches ?? []).map((b) => b.id),
  });
  const { values: v, set, errors } = form;
  const toggle = (key: 'role_ids' | 'branch_ids', id: number) => set(key, v[key].includes(id) ? v[key].filter((x) => x !== id) : [...v[key], id]);
  const save = useAction({
    fn: () => (u ? api.put(`/api/users/${u.id}`, { ...v, password: undefined }) : api.post('/api/users', v)),
    success: u ? 'Staff updated' : 'Staff account created',
    invalidate: [['/api/users'], ['lookups']],
    onSuccess: onClose,
    onError: form.absorb,
  });

  return (
    <Modal
      open
      onClose={onClose}
      size="lg"
      title={u ? `Edit ${u.name}` : 'Add staff member'}
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
      <div className="space-y-6">
        <div className="grid gap-4 sm:grid-cols-2">
          <TextField label="Full name" value={v.name} onChange={(x) => set('name', x)} error={errors.name} required />
          <TextField label="Email" type="email" value={v.email} onChange={(x) => set('email', x)} error={errors.email} required />
          <TextField label="Phone" value={v.phone} onChange={(x) => set('phone', x)} />
          <TextField label="Job title" value={v.job_title} onChange={(x) => set('job_title', x)} />
          {!u && <TextField label="Temporary password" type="password" value={v.password} onChange={(x) => set('password', x)} error={errors.password} required hint="At least 10 characters with upper and lower case and a number." autoComplete="new-password" />}
          {u && <SelectField label="Status" value={v.status} onChange={(x) => set('status', x)} options={['active', 'disabled']} hint="Disabling signs the user out everywhere." />}
          <SelectField label="Home branch" value={v.primary_branch_id} onChange={(x) => set('primary_branch_id', x)} options={(allBranches.data?.data ?? []).map((b) => ({ value: b.id, label: b.name }))} error={errors.primary_branch_id} />
        </div>
        <div>
          <SectionTitle>Roles</SectionTitle>
          {errors.role_ids && <p className="mb-2 text-xs text-red-600">{errors.role_ids}</p>}
          <div className="grid gap-3 sm:grid-cols-2">
            {(roles.data?.data ?? []).map((r) => (
              <Checkbox key={r.id} checked={v.role_ids.includes(r.id)} onChange={() => toggle('role_ids', r.id)} label={r.name} description={r.description ?? undefined} />
            ))}
          </div>
        </div>
        <div>
          <SectionTitle>Additional branches</SectionTitle>
          <p className="mb-2 text-xs text-slate-500">Roles with all-branch access ignore this.</p>
          <div className="grid gap-3 sm:grid-cols-3">
            {(allBranches.data?.data ?? []).map((b) => (
              <Checkbox key={b.id} checked={v.branch_ids.includes(b.id)} onChange={() => toggle('branch_ids', b.id)} label={b.name} />
            ))}
          </div>
        </div>
      </div>
    </Modal>
  );
}

function ResetModal({ user, onClose }: { user: StaffUser; onClose: () => void }) {
  const form = useForm({ password: '' });
  const save = useAction({ fn: () => api.post(`/api/users/${user.id}/password`, form.values), success: 'Password reset; the user was signed out everywhere', onSuccess: onClose, onError: form.absorb });
  return (
    <Modal
      open
      onClose={onClose}
      size="sm"
      title={`Reset password for ${user.name}`}
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>
            Cancel
          </Button>
          <Button loading={save.isPending} onClick={() => save.mutate()}>
            Reset password
          </Button>
        </>
      }
    >
      <TextField label="New password" type="password" value={form.values.password} onChange={(x) => form.set('password', x)} error={form.errors.password} autoComplete="new-password" />
    </Modal>
  );
}
