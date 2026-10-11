import { useRef, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { CircleCheck, CircleX, Download, FileText, Trash2, Upload } from 'lucide-react';
import { api, download, errorMessage } from '../../lib/api';
import { useAuth } from '../../lib/auth';
import { date, label } from '../../lib/format';
import { useAction, useLookups } from '../../lib/hooks';
import type { Customer, CustomerDocument } from '../../lib/types';
import { ConfirmDialog } from '../../components/Modal';
import { Button, Card, CardHeader, EmptyState, ErrorState, LoadingBlock, SelectField, StatusBadge, TextField, cx } from '../../components/ui';
import { useToast } from '../../components/Toast';
import { customerKeys } from './shared';

export function DocumentsPanel({ customer, readOnly }: { customer: Customer; readOnly?: boolean }) {
  const { can, user } = useAuth();
  const toast = useToast();
  const { data: lookups } = useLookups();
  const docs = useQuery({
    queryKey: ['customer-docs', String(customer.id)],
    queryFn: () => api.get<{ data: CustomerDocument[] }>(`/api/customers/${customer.id}/documents`),
    enabled: can('customer_documents.view'),
  });

  const isBusiness = customer.type === 'business';
  const typeOptions = isBusiness
    ? [...(lookups?.enums.business_document_types ?? []), ...(lookups?.enums.identity_document_types ?? []), 'board_resolution', 'proof_of_address', 'bank_statement', 'signed_consent_form', 'other']
    : [...(lookups?.enums.identity_document_types ?? []), 'ssnit', 'proof_of_address', 'payslip', 'bank_statement', 'signed_consent_form', 'other'];

  const [file, setFile] = useState<File | null>(null);
  const [type, setType] = useState(isBusiness ? 'business_registration' : 'ghana_card');
  const [repId, setRepId] = useState('');
  const [number, setNumber] = useState('');
  const [issue, setIssue] = useState('');
  const [expiry, setExpiry] = useState('');
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [rejecting, setRejecting] = useState<CustomerDocument | null>(null);
  const [removing, setRemoving] = useState<CustomerDocument | null>(null);
  const [dragging, setDragging] = useState(false);
  const fileInput = useRef<HTMLInputElement>(null);

  const isIdentityDoc = (lookups?.enums.identity_document_types ?? []).includes(type);

  const upload = useAction({
    fn: () => {
      const fd = new FormData();
      fd.append('file', file!);
      fd.append('document_type', type);
      if (repId) fd.append('representative_id', repId);
      if (number) fd.append('document_number', number);
      if (issue) fd.append('issue_date', issue);
      if (expiry) fd.append('expiry_date', expiry);
      return api.post(`/api/customers/${customer.id}/documents`, fd);
    },
    success: 'Document uploaded',
    invalidate: customerKeys(customer.id),
    onSuccess: () => {
      setFile(null);
      setNumber('');
      setIssue('');
      setExpiry('');
      setErrors({});
      if (fileInput.current) fileInput.current.value = '';
    },
    onError: (e) => {
      const fe = (e as { fieldErrors?: Record<string, string[]> }).fieldErrors ?? {};
      setErrors(Object.fromEntries(Object.entries(fe).map(([k, v]) => [k, v[0]])));
    },
  });

  const verify = useAction({
    fn: ({ doc, decision, reason }: { doc: CustomerDocument; decision: 'verified' | 'rejected'; reason?: string }) =>
      api.post(`/api/customers/${customer.id}/documents/${doc.id}/verify`, { decision, reason }),
    success: 'Document reviewed',
    invalidate: customerKeys(customer.id),
    onSuccess: () => setRejecting(null),
  });

  const remove = useAction({
    fn: (doc: CustomerDocument) => api.delete(`/api/customers/${customer.id}/documents/${doc.id}`),
    success: 'Document removed',
    invalidate: customerKeys(customer.id),
    onSuccess: () => setRemoving(null),
  });

  const canUpload = !readOnly && can('customer_documents.upload') && customer.onboarding_status !== 'completed' && customer.onboarding_status !== 'rejected';

  if (!can('customer_documents.view')) {
    return <EmptyState title="Documents are restricted" description="You don't have permission to view identity documents." />;
  }

  return (
    <div className="space-y-6">
      {canUpload && (
        <Card>
          <CardHeader title="Upload a document" description="JPG, PNG or PDF up to 10 MB. Files are stored privately and every download is logged." />
          <div
            onDragOver={(e) => {
              e.preventDefault();
              setDragging(true);
            }}
            onDragLeave={() => setDragging(false)}
            onDrop={(e) => {
              e.preventDefault();
              setDragging(false);
              if (e.dataTransfer.files[0]) setFile(e.dataTransfer.files[0]);
            }}
            className={cx('mb-4 flex flex-col items-center justify-center rounded-xl border-2 border-dashed px-4 py-6 text-center transition-colors', dragging ? 'border-brand-500 bg-brand-50' : 'border-slate-200', errors.file && 'border-red-300')}
          >
            <Upload className="mb-2 size-6 text-slate-400" />
            {file ? (
              <p className="text-sm font-medium text-slate-800">
                {file.name} <span className="text-slate-500">({Math.round(file.size / 1024)} KB)</span>
              </p>
            ) : (
              <p className="text-sm text-slate-500">Drag a file here, or</p>
            )}
            <Button variant="secondary" size="sm" className="mt-2" onClick={() => fileInput.current?.click()}>
              {file ? 'Choose another file' : 'Choose file'}
            </Button>
            <input ref={fileInput} type="file" accept=".jpg,.jpeg,.png,.pdf" className="sr-only" onChange={(e) => setFile(e.target.files?.[0] ?? null)} aria-label="Document file" />
            {errors.file && <p className="mt-2 text-xs text-red-600">{errors.file}</p>}
          </div>
          <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
            <SelectField label="Document type" value={type} onChange={setType} options={[...new Set(typeOptions)]} error={errors.document_type} />
            {isBusiness && (
              <SelectField
                label="Belongs to"
                value={repId}
                onChange={setRepId}
                placeholder="The business"
                options={(customer.representatives ?? []).map((r) => ({ value: r.id, label: r.full_name }))}
                hint={isIdentityDoc && !repId ? 'Personal IDs should be linked to a representative.' : undefined}
              />
            )}
            <TextField label="Document number" value={number} onChange={setNumber} error={errors.document_number} autoComplete="off" />
            <TextField label="Issue date" type="date" value={issue} onChange={setIssue} error={errors.issue_date} />
            <TextField label="Expiry date" type="date" value={expiry} onChange={setExpiry} error={errors.expiry_date} />
          </div>
          <div className="mt-4 flex justify-end">
            <Button icon={<Upload className="size-4" />} disabled={!file} loading={upload.isPending} onClick={() => upload.mutate()}>
              Upload
            </Button>
          </div>
        </Card>
      )}

      <Card padded={false}>
        <div className="p-5 pb-0">
          <CardHeader title="Documents on file" />
        </div>
        {docs.isLoading ? (
          <div className="p-5">
            <LoadingBlock rows={3} />
          </div>
        ) : docs.error ? (
          <ErrorState message={errorMessage(docs.error)} onRetry={() => docs.refetch()} />
        ) : (docs.data?.data ?? []).length === 0 ? (
          <EmptyState icon={<FileText className="size-6" />} title="No documents yet" description="Upload the customer's identity document to continue onboarding." />
        ) : (
          <ul className="divide-y divide-slate-100">
            {docs.data!.data.map((d) => {
              const expired = d.expiry_date && new Date(d.expiry_date) < new Date();
              const canVerify = can('customer_documents.verify') && d.status === 'pending' && !readOnly;
              return (
                <li key={d.id} className="flex flex-col gap-3 px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
                  <div className="flex min-w-0 items-start gap-3">
                    <div className="flex size-10 shrink-0 items-center justify-center rounded-lg bg-slate-100 text-slate-500">
                      <FileText className="size-5" />
                    </div>
                    <div className="min-w-0">
                      <p className="flex flex-wrap items-center gap-2 text-sm font-medium text-slate-900">
                        {label(d.document_type)}
                        <StatusBadge status={d.status} />
                        {expired && <StatusBadge status="overdue" label="Expired" />}
                      </p>
                      <p className="mt-0.5 text-xs text-slate-500">
                        {d.representative ? `${d.representative.full_name} · ` : ''}
                        {d.document_number_masked ? `No. ${d.document_number_masked} · ` : ''}
                        {d.expiry_date ? `Expires ${date(d.expiry_date)} · ` : ''}
                        Uploaded {date(d.created_at)} by {d.uploader?.name}
                      </p>
                      {d.status === 'verified' && <p className="mt-0.5 text-xs text-emerald-700">Verified by {d.verifier?.name} on {date(d.verified_at)}</p>}
                      {d.status === 'rejected' && <p className="mt-0.5 text-xs text-red-600">Rejected: {d.rejection_reason}</p>}
                    </div>
                  </div>
                  <div className="flex flex-wrap items-center gap-2">
                    <Button
                      size="sm"
                      variant="secondary"
                      icon={<Download className="size-3.5" />}
                      onClick={() => download(`/api/customers/${customer.id}/documents/${d.id}/download`, d.original_name).catch((e) => toast.error(errorMessage(e)))}
                    >
                      View
                    </Button>
                    {canVerify && (
                      <>
                        <Button
                          size="sm"
                          icon={<CircleCheck className="size-3.5" />}
                          disabled={d.uploader?.id === user?.id}
                          title={d.uploader?.id === user?.id ? 'Another person must verify a document you uploaded' : undefined}
                          loading={verify.isPending && verify.variables?.doc.id === d.id && verify.variables?.decision === 'verified'}
                          onClick={() => verify.mutate({ doc: d, decision: 'verified' })}
                        >
                          Verify
                        </Button>
                        <Button size="sm" variant="secondary" icon={<CircleX className="size-3.5" />} onClick={() => setRejecting(d)}>
                          Reject
                        </Button>
                      </>
                    )}
                    {canUpload && d.status !== 'verified' && (
                      <button className="rounded-lg p-1.5 text-slate-400 hover:bg-red-50 hover:text-red-600" aria-label="Remove document" onClick={() => setRemoving(d)}>
                        <Trash2 className="size-4" />
                      </button>
                    )}
                  </div>
                </li>
              );
            })}
          </ul>
        )}
      </Card>

      <ConfirmDialog
        open={!!rejecting}
        onClose={() => setRejecting(null)}
        title="Reject document"
        message="The uploader will need to provide a replacement."
        reasonLabel="Reason for rejection"
        confirmLabel="Reject"
        danger
        loading={verify.isPending}
        onConfirm={(reason) => rejecting && verify.mutate({ doc: rejecting, decision: 'rejected', reason })}
      />
      <ConfirmDialog
        open={!!removing}
        onClose={() => setRemoving(null)}
        title="Remove document"
        message="Remove this unverified document? The upload remains in the audit log."
        confirmLabel="Remove"
        danger
        loading={remove.isPending}
        onConfirm={() => removing && remove.mutate(removing)}
      />
    </div>
  );
}
