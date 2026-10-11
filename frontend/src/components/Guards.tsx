import type { ReactNode } from 'react';
import { Navigate, useLocation } from 'react-router-dom';
import { ShieldAlert } from 'lucide-react';
import { useAuth } from '../lib/auth';
import { EmptyState, Spinner } from './ui';

export function RequireAuth({ children }: { children: ReactNode }) {
  const { user, loading } = useAuth();
  const location = useLocation();

  if (loading) {
    return (
      <div className="flex h-full items-center justify-center">
        <Spinner className="size-8" />
      </div>
    );
  }
  if (!user) return <Navigate to="/login" replace state={{ from: location.pathname + location.search }} />;
  return <>{children}</>;
}

/**
 * Hides a page the user may not open. This is a convenience only - every
 * endpoint enforces the same permission on the server.
 */
export function RequirePermission({ permission, children }: { permission: string | string[]; children: ReactNode }) {
  const { can } = useAuth();
  if (!can(permission)) {
    return <EmptyState icon={<ShieldAlert className="size-6" />} title="You don't have access to this page" description="Ask an administrator if you need this permission." />;
  }
  return <>{children}</>;
}

/** Renders children only when the user holds the permission. */
export function Can({ permission, children }: { permission: string | string[]; children: ReactNode }) {
  const { can } = useAuth();
  return can(permission) ? <>{children}</> : null;
}
