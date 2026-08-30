import { LogOut, ShieldCheck } from "lucide-react";
import { useState } from "react";
import { Outlet, useNavigate } from "react-router";

import { apiErrorMessage } from "@/shared/api/errors";
import { Button } from "@/shared/components/ui/button";
import { Spinner } from "@/shared/components/ui/spinner";

import { logoutAdmin } from "./api";
import { useAdminStore } from "./store";

export function AdminShell() {
  const navigate = useNavigate();
  const identity = useAdminStore((state) => state.identity);
  const csrfToken = useAdminStore((state) => state.csrfToken);
  const clear = useAdminStore((state) => state.clear);
  const [pending, setPending] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function logout() {
    if (!csrfToken) {
      clear();
      navigate("/admin/login", { replace: true });
      return;
    }
    setPending(true);
    setError(null);
    try {
      await logoutAdmin(csrfToken);
      clear();
      navigate("/admin/login", { replace: true });
    } catch (err) {
      setError(await apiErrorMessage(err));
    } finally {
      setPending(false);
    }
  }

  return (
    <div className="min-h-screen bg-console-surface text-console-text-strong">
      <header className="border-b border-white/10 bg-console-surface/90">
        <div className="mx-auto flex max-w-7xl items-center justify-between px-6 py-4">
          <div className="flex items-center gap-3">
            <div className="flex size-9 items-center justify-center rounded-lg bg-console-accent/10 text-console-accent-hover"><ShieldCheck className="size-5" aria-hidden="true" /></div>
            <div><p className="font-semibold text-white">Console superadmin</p><p className="text-xs text-console-muted">Surface d’exploitation transverse</p></div>
          </div>
          <div className="flex items-center gap-4">
            <span className="hidden text-sm text-console-text-dim sm:inline">{identity?.email}</span>
            <Button variant="ghost" size="sm" className="text-console-text hover:bg-white/10 hover:text-white" onClick={logout} disabled={pending}>
              {pending ? <Spinner className="size-4" /> : <LogOut className="size-4" aria-hidden="true" />}
              Sortir
            </Button>
          </div>
        </div>
      </header>
      {error ? <div className="border-b border-console-danger/20 bg-console-danger-deep/10 px-6 py-3 text-center text-sm text-console-danger-bright" role="alert">{error}</div> : null}
      <main className="mx-auto max-w-7xl px-6 py-10"><Outlet /></main>
    </div>
  );
}
