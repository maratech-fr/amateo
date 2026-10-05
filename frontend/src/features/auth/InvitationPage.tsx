import { HTTPError } from "ky";
import { ShieldQuestion } from "lucide-react";
import { type FormEvent, useState } from "react";
import { Link, useNavigate, useParams } from "react-router";

import { Button } from "@/shared/components/ui/button";
import { Input } from "@/shared/components/ui/input";
import { Label } from "@/shared/components/ui/label";
import { NewPasswordFields } from "@/shared/components/ui/new-password-fields";
import { NoticeBanner } from "@/shared/components/ui/notice-banner";
import { Spinner } from "@/shared/components/ui/spinner";
import { errorMessage } from "@/shared/lib/errorMessage";
import { isPasswordValid } from "@/shared/lib/passwordPolicy";
import { roleLabel } from "@/shared/lib/roles";
import { useAuthStore } from "@/shared/stores/authStore";

import { AuthLayout } from "./AuthLayout";
import { useAcceptInvitationConnected, useAcceptInvitationNewAccount, useInvitationInfo } from "./queries";

const INVALID_LINK = "Lien invalide ou expiré.";

const httpStatus = (err: unknown): number | null => (err instanceof HTTPError ? err.response.status : null);

/**
 * P4-299 — la page PUBLIQUE d'une invitation (patron `ClubApprovalPage`), ouverte par
 * le destinataire du mail : le token EST l'identité de l'ADRESSE. Trois issues selon
 * l'état du porteur :
 *  - SANS compte → formulaire de création (adresse verrouillée = l'adresse invitée),
 *    puis entrée DIRECTE dans l'app (compte né vérifié, adhésion active) — jamais `/waiting` ;
 *  - AVEC compte, non connecté → « Se connecter pour accepter » (retour ici après login) ;
 *  - connecté → acceptation en UN clic (l'e-mail du compte doit être l'adresse invitée).
 *
 * Un lien inconnu, expiré OU révoqué rend le MÊME message « Lien invalide ou expiré »
 * (le serveur répond 404 byte-identique — aucun oracle d'existence).
 */
export function InvitationPage() {
  const { token = "" } = useParams();
  const navigate = useNavigate();
  const isAuthenticated = useAuthStore((state) => state.isAuthenticated);

  const info = useInvitationInfo(token);
  const acceptNew = useAcceptInvitationNewAccount(token);
  const acceptConnected = useAcceptInvitationConnected(token);

  const [form, setForm] = useState({ firstName: "", lastName: "", password: "", confirm: "" });
  const [consent, setConsent] = useState(false);
  const [error, setError] = useState<string | null>(null);
  // Un 409 à la création (« un compte existe déjà ») bascule vers le chemin connecté.
  const [accountExists, setAccountExists] = useState(false);

  const enterApp = () => navigate("/", { replace: true });

  async function onCreate(event: FormEvent) {
    event.preventDefault();
    setError(null);
    if (!isPasswordValid(form.password) || form.password !== form.confirm) {
      return;
    }
    if (!consent) {
      setError("Vous devez accepter les conditions d'utilisation et la politique de confidentialité.");
      return;
    }
    try {
      await acceptNew.mutateAsync({ firstName: form.firstName.trim(), lastName: form.lastName.trim(), password: form.password, consent });
      enterApp();
    } catch (err) {
      // 409 : un compte existe pour cette adresse → se connecter pour accepter.
      if (409 === httpStatus(err)) {
        setAccountExists(true);
        return;
      }
      setError(404 === httpStatus(err) ? INVALID_LINK : await errorMessage(err));
    }
  }

  async function onAcceptConnected() {
    setError(null);
    try {
      await acceptConnected.mutateAsync();
      enterApp();
    } catch (err) {
      setError(404 === httpStatus(err) ? INVALID_LINK : await errorMessage(err));
    }
  }

  // --- Chargement / lien mort -------------------------------------------------
  if (info.isPending) {
    return (
      <AuthLayout title="Invitation" description="Vérification de votre invitation.">
        <p className="flex items-center justify-center gap-2 py-4 text-sm text-muted-foreground">
          <Spinner className="size-4" /> Chargement…
        </p>
      </AuthLayout>
    );
  }

  if (info.isError) {
    return (
      <AuthLayout title="Invitation" description="Rejoindre un club.">
        <div className="flex flex-col items-center gap-4 py-2 text-center">
          <span className="flex size-12 items-center justify-center rounded-full bg-muted">
            <ShieldQuestion className="size-6 text-accent" />
          </span>
          <p role="alert" className="text-sm text-muted-foreground">
            {INVALID_LINK} Demandez une nouvelle invitation à votre club.
          </p>
        </div>
      </AuthLayout>
    );
  }

  const invitation = info.data;
  const roleFr = roleLabel(invitation.role);

  // --- Connecté : acceptation en un clic -------------------------------------
  if (isAuthenticated) {
    return (
      <AuthLayout title={`Rejoindre ${invitation.clubName}`} description={`Vous êtes invité comme ${roleFr}.`}>
        <div className="flex flex-col gap-4">
          <p className="text-sm text-muted-foreground">
            L'invitation a été envoyée à <span className="text-foreground">{invitation.email}</span>. Acceptez pour rejoindre le club.
          </p>
          {null !== error ? <NoticeBanner tone="destructive" role="alert" message={error} /> : null}
          <Button type="button" disabled={acceptConnected.isPending} onClick={() => void onAcceptConnected()}>
            {acceptConnected.isPending ? <Spinner className="size-4" /> : null}
            Accepter l'invitation
          </Button>
        </div>
      </AuthLayout>
    );
  }

  // --- Non connecté, compte existant → se connecter pour accepter -------------
  if (invitation.hasAccount || accountExists) {
    return (
      <AuthLayout title={`Rejoindre ${invitation.clubName}`} description={`Vous êtes invité comme ${roleFr}.`}>
        <div className="flex flex-col gap-4">
          <p className="text-sm text-muted-foreground">
            Un compte existe déjà pour <span className="text-foreground">{invitation.email}</span>. Connectez-vous pour accepter l'invitation.
          </p>
          <Button type="button" onClick={() => navigate(`/login?next=${encodeURIComponent(`/invitation/${token}`)}`)}>
            Se connecter pour accepter
          </Button>
        </div>
      </AuthLayout>
    );
  }

  // --- Non connecté, pas de compte → création puis entrée directe -------------
  const passwordReady = isPasswordValid(form.password) && form.password === form.confirm;
  return (
    <AuthLayout title={`Rejoindre ${invitation.clubName}`} description={`Vous êtes invité comme ${roleFr}. Créez votre compte pour entrer.`}>
      <form className="flex flex-col gap-4" onSubmit={onCreate} noValidate>
        <div className="flex flex-col gap-1.5">
          <Label htmlFor="invite-account-email">Email</Label>
          {/* L'adresse est celle de l'invitation (verrouillée) : soumettre une autre est refusé serveur. */}
          <Input id="invite-account-email" type="email" value={invitation.email} readOnly aria-readonly="true" className="text-muted-foreground" />
        </div>
        <div className="grid grid-cols-2 gap-3">
          <div className="flex flex-col gap-1.5">
            <Label htmlFor="invite-firstName">Prénom</Label>
            <Input id="invite-firstName" required value={form.firstName} onChange={(e) => setForm((p) => ({ ...p, firstName: e.target.value }))} />
          </div>
          <div className="flex flex-col gap-1.5">
            <Label htmlFor="invite-lastName">Nom</Label>
            <Input id="invite-lastName" required value={form.lastName} onChange={(e) => setForm((p) => ({ ...p, lastName: e.target.value }))} />
          </div>
        </div>
        <NewPasswordFields
          idPrefix="invite-"
          password={form.password}
          confirm={form.confirm}
          onPasswordChange={(v) => setForm((p) => ({ ...p, password: v }))}
          onConfirmChange={(v) => setForm((p) => ({ ...p, confirm: v }))}
        />
        {/* RGPD : consentement explicite requis (le backend le refuse sans). */}
        <label className="flex items-start gap-2 text-sm">
          <input type="checkbox" className="mt-0.5 size-4 accent-[var(--accent)]" checked={consent} onChange={(e) => setConsent(e.target.checked)} required />
          <span>
            J'accepte les{" "}
            <Link className="text-accent hover:underline" to="/confidentialite" target="_blank" rel="noreferrer">
              conditions d'utilisation et la politique de confidentialité
            </Link>
            .
          </span>
        </label>
        {null !== error ? <NoticeBanner tone="destructive" role="alert" message={error} /> : null}
        <Button type="submit" disabled={acceptNew.isPending || !passwordReady || !form.firstName.trim() || !form.lastName.trim()}>
          {acceptNew.isPending ? <Spinner className="size-4" /> : null}
          Rejoindre {invitation.clubName}
        </Button>
      </form>
    </AuthLayout>
  );
}
