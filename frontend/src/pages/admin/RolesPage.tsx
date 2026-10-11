import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Lock, Plus } from 'lucide-react';
import { api, errorMessage } from '../../lib/api';
import { useAction, useForm } from '../../lib/hooks';
import type { Role } from '../../lib/types';
import { ConfirmDialog, Modal } from '../../components/Modal';
import { Badge, Button, Card, ErrorState, LoadingBlock, PageHeader, TextField } from '../../components/ui';

export default function RolesPage() {
  const [editing, setEditing] = useState<Role | 'new' | null>(null);
  const [deleting, setDeleting] = useState<Role | null>(null);
  const { data, isLoading, error, refetch } = useQuery({ queryKey: ['/api/roles'], queryFn: () => api.get<{ data: Role[] }>('/api/roles') });
  const remove = useAction({ fn: (r: Role) => api.delete(`/api/roles/${r.id}`), success: 'Role deleted', invalidate: [['/api/roles']], onSuccess: () => setDeleting(null) });

  return (
    <>
      <PageHeader
        title="Roles & permissions"
        description="A user's access is the combination of all their roles. You cannot grant permissions you don't hold."
        actions={
          <Button icon={<Plus className="size-4" />} onClick={() => setEditing('new')}>
            New role
          </Button>
        }
      />
      {isLoading ? (
        <LoadingBlock />
      ) : error ? (
        <ErrorState message={errorMessage(error)} onRetry={() => refetch()} />
      ) : (
        <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
          {data!.data.map((r) => (
            <Card key={r.id}>
              <div className="flex items-start justify-between gap-2">
                <div>
                  <p className="flex items-center gap-2 font-semibold text-slate-900">
                    {r.name} {r.is_system && <Badge>System</Badge>}
                  </p>
                  <p className="mt-1 text-sm text-slate-500">{r.description}</p>
                </div>
              </div>
              <p className="mt-3 text-xs text-slate-500">
                {r.permissions.length} permissions · {r.users_count ?? 0} staff
              </p>
              <div className="mt-4 flex gap-2">
                {r.slug === 'administrator' ? (
                  <span className="inline-flex items-center gap-1 text-xs text-slate-500">
                    <Lock className="size-3" /> Always has every permission
                  </span>
                ) : (
                  <>
                    <Button size="sm" variant="secondary" onClick={() => setEditing(r)}>
                      Edit permissions
                    </Button>
                    {!r.is_system && !r.users_count && (
                      <Button size="sm" variant="ghost" onClick={() => setDeleting(r)}>
                        Delete
                      </Button>
                    )}
                  </>
                )}
              </div>
            </Card>
          ))}
        </div>
      )}
      {editing && <RoleModal role={editing === 'new' ? null : editing} onClose={() => setEditing(null)} />}
      <ConfirmDialog open={!!deleting} onClose={() => setDeleting(null)} title="Delete role" message={`Delete ${deleting?.name}?`} danger confirmLabel="Delete" loading={remove.isPending} onConfirm={() => deleting && remove.mutate(deleting)} />
    </>
  );
}

function RoleModal({ role, onClose }: { role: Role | null; onClose: () => void }) {
  const catalogue = useQuery({ queryKey: ['/api/permissions'], queryFn: () => api.get<{ modules: Record<string, Record<string, string>> }>('/api/permissions') });
  const form = useForm({ name: role?.name ?? '', description: role?.description ?? '', permissions: role?.permissions ?? [] });
  const { values: v, set, errors } = form;
  const toggle = (p: string) => set('permissions', v.permissions.includes(p) ? v.permissions.filter((x) => x !== p) : [...v.permissions, p]);
  const save = useAction({
    fn: () => (role ? api.put(`/api/roles/${role.id}`, v) : api.post('/api/roles', v)),
    success: 'Role saved',
    invalidate: [['/api/roles'], ['me']],
    onSuccess: onClose,
    onError: form.absorb,
  });

  return (
    <Modal
      open
      onClose={onClose}
      size="xl"
      title={role ? `Edit ${role.name}` : 'New role'}
      footer={
        <>
          <span className="mr-auto self-center text-sm text-slate-500">{v.permissions.length} selected</span>
          <Button variant="secondary" onClick={onClose}>
            Cancel
          </Button>
          <Button loading={save.isPending} onClick={() => save.mutate()}>
            Save role
          </Button>
        </>
      }
    >
      <div className="space-y-5">
        <div className="grid gap-4 sm:grid-cols-2">
          <TextField label="Role name" value={v.name} onChange={(x) => set('name', x)} error={errors.name} required />
          <TextField label="Description" value={v.description} onChange={(x) => set('description', x)} />
        </div>
        {errors.permissions && <p className="text-sm text-red-600">{errors.permissions}</p>}
        {catalogue.isLoading ? (
          <LoadingBlock />
        ) : (
          <div className="grid gap-4 md:grid-cols-2">
            {Object.entries(catalogue.data?.modules ?? {}).map(([module, perms]) => {
              const codes = Object.keys(perms);
              const all = codes.every((c) => v.permissions.includes(c));
              return (
                <div key={module} className="rounded-xl border border-slate-200 p-3">
                  <div className="mb-2 flex items-center justify-between">
                    <p className="text-sm font-semibold text-slate-800">{module}</p>
                    <button className="text-xs font-medium text-brand-700 hover:underline" onClick={() => set('permissions', all ? v.permissions.filter((p) => !codes.includes(p)) : [...new Set([...v.permissions, ...codes])])}>
                      {all ? 'Clear' : 'Select all'}
                    </button>
                  </div>
                  <div className="space-y-1.5">
                    {Object.entries(perms).map(([code, text]) => (
                      <label key={code} className="flex cursor-pointer items-center gap-2 text-sm text-slate-700">
                        <input type="checkbox" className="accent-brand-700" checked={v.permissions.includes(code)} onChange={() => toggle(code)} />
                        {text}
                      </label>
                    ))}
                  </div>
                </div>
              );
            })}
          </div>
        )}
      </div>
    </Modal>
  );
}
