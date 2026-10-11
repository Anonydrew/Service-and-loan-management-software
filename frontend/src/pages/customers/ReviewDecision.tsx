import { useState } from 'react';
import { api } from '../../lib/api';
import { useAuth } from '../../lib/auth';
import { useAction } from '../../lib/hooks';
import type { Customer } from '../../lib/types';
import { Alert, Button, Card, CardHeader, Field, Textarea } from '../../components/ui';
import { customerKeys } from './shared';

/** A compliance reviewer's decision on a customer referred for manual review. */
export function ReviewDecision({ customer, onDone }: { customer: Customer; onDone?: () => void }) {
  const { user } = useAuth();
  const [notes, setNotes] = useState('');
  const decide = useAction({
    fn: (decision: 'approve' | 'reject') => api.post(`/api/customers/${customer.id}/onboarding/decide`, { decision, notes }),
    success: (r) => ((r as Customer).status === 'active' ? 'Customer approved and activated' : 'Customer rejected'),
    invalidate: [...customerKeys(customer.id), ['/api/onboarding/review-queue']],
    onSuccess: () => onDone?.(),
  });

  if (customer.created_by?.id === user?.id) {
    return <Alert tone="neutral">You registered this customer, so another reviewer must make the decision.</Alert>;
  }

  return (
    <Card>
      <CardHeader title="Reviewer decision" description="Inspect the original identity documents in person before approving. Your decision and notes are recorded in the audit log." />
      <Field label="Review notes" required>
        {(id) => <Textarea id={id} value={notes} onChange={(e) => setNotes(e.target.value)} placeholder="What did you check, and what did you find?" />}
      </Field>
      <div className="mt-4 flex justify-end gap-2">
        <Button variant="secondary" disabled={notes.trim().length < 5} loading={decide.isPending && decide.variables === 'reject'} onClick={() => decide.mutate('reject')}>
          Reject
        </Button>
        <Button disabled={notes.trim().length < 5} loading={decide.isPending && decide.variables === 'approve'} onClick={() => decide.mutate('approve')}>
          Approve identity
        </Button>
      </div>
    </Card>
  );
}
