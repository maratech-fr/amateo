import { useEffect, useRef, useState } from "react";
import { Link, useNavigate, useParams } from "react-router";

import { errorMessage } from "@/shared/lib/errorMessage";
import { Spinner } from "@/shared/components/ui/spinner";

import { AuthLayout } from "./AuthLayout";
import { useVerifyEmail } from "./queries";

/**
 * Consumes the emailed verification link: exchanges the token for a JWT (the
 * effective login) and routes into the app. Mirrors ResetPasswordPage, but runs
 * automatically on mount — the token in the URL is the whole action.
 */
export function VerifyEmailPage() {
  const { token = "" } = useParams();
  const navigate = useNavigate();
  const verify = useVerifyEmail();
  const [error, setError] = useState<string | null>(null);
  const ran = useRef(false);

  useEffect(() => {
    if (ran.current) return; // StrictMode double-invoke guard — the token is single-use.
    ran.current = true;
    verify
      .mutateAsync(token)
      .then((result) => navigate(result.membershipStatus === "active" ? "/" : "/waiting", { replace: true }))
      .catch(async (err) => setError(await errorMessage(err)));
  }, [token, verify, navigate]);

  return (
    <AuthLayout
      title="Activation du compte"
      description="Confirmation de votre adresse e-mail."
      footer={<Link className="text-accent hover:underline" to="/login">Retour à la connexion</Link>}
    >
      {null === error ? (
        <p className="flex items-center gap-2 text-sm text-muted-foreground">
          <Spinner className="size-4" />
          Activation en cours…
        </p>
      ) : (
        <p className="text-sm text-destructive">
          {error} Le lien a peut-être expiré — vous pouvez vous inscrire à nouveau.
        </p>
      )}
    </AuthLayout>
  );
}
