import { api } from '../lib/api';
import { useAuth } from '../lib/auth';
import { useAction, useForm } from '../lib/hooks';
import { Badge, Button, Card, CardHeader, DescriptionList, PageHeader, TextField } from '../components/ui';

export default function AccountPage() {
  const { user } = useAuth();
  const form = useForm({ current_password: '', password: '', password_confirmation: '' });
  const save = useAction({
    fn: () => api.put('/api/auth/password', form.values),
    success: 'Password changed',
    onSuccess: () => form.setValues({ current_password: '', password: '', password_confirmation: '' }),
    onError: form.absorb,
  });

  if (!user) return null;

  return (
    <>
      <PageHeader title="My account" />
      <div className="grid max-w-4xl gap-6 lg:grid-cols-2">
        <Card>
          <CardHeader title="Profile" />
          <DescriptionList
            columns={1}
            items={[
              { label: 'Name', value: user.name },
              { label: 'Email', value: user.email },
              { label: 'Roles', value: <div className="flex flex-wrap gap-1">{user.roles.map((r) => <Badge key={r}>{r}</Badge>)}</div> },
              { label: 'Branch access', value: user.all_branches ? 'All branches' : user.branches.map((b) => b.name).join(', ') },
            ]}
          />
        </Card>
        <Card>
          <CardHeader title="Change password" description="At least 10 characters, with upper and lower case letters and a number." />
          <form
            className="space-y-4"
            onSubmit={(e) => {
              e.preventDefault();
              save.mutate();
            }}
          >
            <TextField label="Current password" type="password" autoComplete="current-password" value={form.values.current_password} onChange={(v) => form.set('current_password', v)} error={form.errors.current_password} required />
            <TextField label="New password" type="password" autoComplete="new-password" value={form.values.password} onChange={(v) => form.set('password', v)} error={form.errors.password} required />
            <TextField label="Confirm new password" type="password" autoComplete="new-password" value={form.values.password_confirmation} onChange={(v) => form.set('password_confirmation', v)} required />
            <Button type="submit" loading={save.isPending}>
              Update password
            </Button>
          </form>
        </Card>
      </div>
    </>
  );
}
