import { type FormEvent, useState } from "react";
import { useLocation, useNavigate, useNavigation } from "react-router";

import { errorMessage } from "@/shared/lib/errorMessage";
import { Button } from "@/shared/components/ui/button";
import { Input } from "@/shared/components/ui/input";
import { Label } from "@/shared/components/ui/label";
import { PasswordInput } from "@/shared/components/ui/password-input";
import { Spinner } from "@/shared/components/ui/spinner";

import { AdminAuthLayout } from "./AdminAuthLayout";
import { completeAdminTotp, startAdminPassword } from "./api";
import { useAdminStore } from "./store";
import { ADMIN_DASHBOARD_TEXTS } from "./texts/dashboard";

type Step = "password" | "totp";

export function AdminLoginPage() {
  const navigate = useNavigate();
  const location = useLocation();
  const setCsrfToken = useAdminStore((state) => state.setCsrfToken);
  const [step, setStep] = useState<Step>("password");
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [code, setCode] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [pending, setPending] = useState(false);
  // P4-6 — `/admin` charge maintenant deux chunks lazy : sans ce verrou, le bouton
  // se ré-active sur le formulaire encore affiché, le superadmin resoumet un code
  // TOTP DÉJÀ consommé → échec + incrément du throttle par IP du firewall SA0.
  const navigating = "idle" !== useNavigation().state;

  async function submitPassword(event: FormEvent) {
    event.preventDefault();
    setError(null);
    setPending(true);
    try {
      await startAdminPassword({ email, password });
      setStep("totp");
    } catch (err) {
      setError(await errorMessage(err));
    } finally {
      setPending(false);
    }
  }

  async function submitTotp(event: FormEvent) {
    event.preventDefault();
    setError(null);
    setPending(true);
    try {
      const result = await completeAdminTotp(code);
      setCsrfToken(result.csrfToken);
      // The guard hydrates the authoritative identity on the next render.
      const from = (location.state as { from?: string } | null)?.from;
      navigate(from?.startsWith("/admin") ? from : "/admin", { replace: true });
    } catch (err) {
      setError(await errorMessage(err));
    } finally {
      setPending(false);
    }
  }

  if (step === "totp") {
    return (
      <AdminAuthLayout title={ADMIN_DASHBOARD_TEXTS.loginTotpTitle} description={ADMIN_DASHBOARD_TEXTS.loginTotpDescription}>
        <form className="flex flex-col gap-5" onSubmit={submitTotp} noValidate>
          <div className="flex flex-col gap-2">
            <Label className="text-console-text-bright" htmlFor="admin-totp">{ADMIN_DASHBOARD_TEXTS.loginTotpLabel}</Label>
            <Input
              id="admin-totp"
              className="h-12 text-center text-xl tracking-[0.45em] text-white"
              autoComplete="one-time-code"
              inputMode="numeric"
              pattern="[0-9]{6}"
              maxLength={6}
              required
              value={code}
              onChange={(event) => setCode(event.target.value.replace(/\D/g, "").slice(0, 6))}
            />
          </div>
          {error ? <p className="text-sm text-console-danger" role="alert">{error}</p> : null}
          <Button type="submit" className="h-11 bg-console-accent text-console-surface hover:bg-console-accent-hover" disabled={pending || navigating || 6 !== code.length}>
            {pending ? <Spinner className="size-4 text-console-surface" /> : null}
            {ADMIN_DASHBOARD_TEXTS.loginTotpSubmit}
          </Button>
          <button type="button" className="text-sm text-console-text-dim underline-offset-4 hover:text-white hover:underline" onClick={() => { setStep("password"); setCode(""); setError(null); }}>
            {ADMIN_DASHBOARD_TEXTS.loginBackToIdentification}
          </button>
        </form>
      </AdminAuthLayout>
    );
  }

  return (
    <AdminAuthLayout title={ADMIN_DASHBOARD_TEXTS.loginTitle} description={ADMIN_DASHBOARD_TEXTS.loginDescription}>
      <form className="flex flex-col gap-5" onSubmit={submitPassword} noValidate>
        <div className="flex flex-col gap-2">
          <Label className="text-console-text-bright" htmlFor="admin-email">{ADMIN_DASHBOARD_TEXTS.loginEmailLabel}</Label>
          <Input id="admin-email" className="text-white" type="email" autoComplete="username" required value={email} onChange={(event) => setEmail(event.target.value)} />
        </div>
        <div className="flex flex-col gap-2">
          <Label className="text-console-text-bright" htmlFor="admin-password">{ADMIN_DASHBOARD_TEXTS.loginPasswordLabel}</Label>
          <PasswordInput id="admin-password" className="text-white" autoComplete="current-password" required value={password} onChange={(event) => setPassword(event.target.value)} />
        </div>
        {error ? <p className="text-sm text-console-danger" role="alert">{error}</p> : null}
        <Button type="submit" className="h-11 bg-console-accent text-console-surface hover:bg-console-accent-hover" disabled={pending || navigating || !email || !password}>
          {pending ? <Spinner className="size-4 text-console-surface" /> : null}
          {ADMIN_DASHBOARD_TEXTS.loginSubmit}
        </Button>
      </form>
    </AdminAuthLayout>
  );
}
