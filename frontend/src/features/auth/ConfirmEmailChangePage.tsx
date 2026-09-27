import { useEffect, useRef, useState } from "react";
import { Link, useNavigate, useParams } from "react-router";

import { errorMessage } from "@/shared/lib/errorMessage";
import { Spinner } from "@/shared/components/ui/spinner";
import { toast } from "@/shared/stores/toastStore";

import { AuthLayout } from "./AuthLayout";
import { useConfirmEmailChange } from "./queries";

/**
 * P4-74 — consomme le lien de confirmation de changement d'e-mail : bascule
 * l'adresse et route dans l'app. Miroir de VerifyEmailPage — le token de l'URL
 * EST l'action, jouée au montage. Le serveur repose un cookie frais pour la
 * nouvelle identité, donc l'utilisateur reste connecté.
 */
export function ConfirmEmailChangePage() {
  const { token = "" } = useParams();
  const navigate = useNavigate();
  const confirm = useConfirmEmailChange();
  const [error, setError] = useState<string | null>(null);
  const ran = useRef(false);

  useEffect(() => {
    if (ran.current) return; // StrictMode double-invoke guard — the token is single-use.
    ran.current = true;
    confirm
      .mutateAsync(token)
      .then((result) => {
        toast.success(`Adresse e-mail confirmée : ${result.email}.`);
        navigate("/", { replace: true });
      })
      .catch(async (err) => setError(await errorMessage(err)));
  }, [token, confirm, navigate]);

  return (
    <AuthLayout
      title="Nouvelle adresse e-mail"
      description="Confirmation de votre nouvelle adresse."
      footer={<Link className="text-accent hover:underline" to="/login">Retour à la connexion</Link>}
    >
      {null === error ? (
        <p className="flex items-center gap-2 text-sm text-muted-foreground">
          <Spinner className="size-4" />
          Confirmation en cours…
        </p>
      ) : (
        <p className="text-sm text-destructive">
          {error} Le lien a peut-être expiré — vous pouvez redemander un changement depuis votre profil.
        </p>
      )}
    </AuthLayout>
  );
}
