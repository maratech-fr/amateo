import { type FormEvent, useState } from "react";
import { Link, useNavigate, useParams } from "react-router";

import { errorMessage } from "@/shared/lib/errorMessage";
import { Button } from "@/shared/components/ui/button";
import { NewPasswordFields } from "@/shared/components/ui/new-password-fields";
import { Spinner } from "@/shared/components/ui/spinner";
import { isPasswordValid } from "@/shared/lib/passwordPolicy";

import { AuthLayout } from "./AuthLayout";
import { useResetPassword } from "./queries";

export function ResetPasswordPage() {
  const { token = "" } = useParams();
  const navigate = useNavigate();
  const reset = useResetPassword();
  const [password, setPassword] = useState("");
  const [confirm, setConfirm] = useState("");
  const [error, setError] = useState<string | null>(null);

  async function onSubmit(event: FormEvent) {
    event.preventDefault();
    setError(null);
    if (!isPasswordValid(password)) {
      setError("Le mot de passe ne respecte pas les critères.");
      return;
    }
    if (password !== confirm) {
      setError("Les deux mots de passe ne sont pas identiques.");
      return;
    }
    try {
      await reset.mutateAsync({ token, password });
      navigate("/login", { replace: true });
    } catch (err) {
      setError(await errorMessage(err));
    }
  }

  return (
    <AuthLayout
      title="Nouveau mot de passe"
      description="Choisissez un nouveau mot de passe pour votre compte."
      footer={<Link className="text-accent hover:underline" to="/login">Retour à la connexion</Link>}
    >
      <form className="flex flex-col gap-4" onSubmit={onSubmit} noValidate>
        <NewPasswordFields password={password} confirm={confirm} onPasswordChange={setPassword} onConfirmChange={setConfirm} passwordLabel="Nouveau mot de passe" />
        {error ? <p className="text-sm text-destructive">{error}</p> : null}
        <Button type="submit" disabled={reset.isPending || !isPasswordValid(password) || password !== confirm}>
          {reset.isPending ? <Spinner className="size-4" /> : null}
          Réinitialiser
        </Button>
      </form>
    </AuthLayout>
  );
}
