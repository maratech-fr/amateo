import { type FormEvent, useState } from "react";

import { useLogout } from "@/features/auth/queries";
import { useMe } from "@/shared/session/queries";
import { Button } from "@/shared/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/shared/components/ui/card";
import { FichePage } from "@/shared/components/ui/fiche-page";
import { Input } from "@/shared/components/ui/input";
import { Label } from "@/shared/components/ui/label";
import { NewPasswordFields } from "@/shared/components/ui/new-password-fields";
import { PageHeader } from "@/shared/components/ui/page-header";
import { PasswordInput } from "@/shared/components/ui/password-input";
import { isPasswordValid } from "@/shared/lib/passwordPolicy";
import { FullPageSpinner, Spinner } from "@/shared/components/ui/spinner";
import { toast } from "@/shared/stores/toastStore";

import {
  useCancelEmailChange,
  useChangePassword,
  useDeleteAccount,
  useDownloadMyData,
  useRequestEmailChange,
  useUpdateProfile,
} from "./queries";

function ProfileForm({
  firstName,
  lastName,
  email,
  pendingEmail,
}: {
  firstName: string;
  lastName: string;
  email: string;
  pendingEmail: string | null;
}) {
  const update = useUpdateProfile();
  const requestEmail = useRequestEmailChange();
  const cancelEmail = useCancelEmailChange();
  const [first, setFirst] = useState(firstName);
  const [last, setLast] = useState(lastName);
  const [mail, setMail] = useState(email);
  // Le serveur exige le mot de passe courant (revue sécu P4-74 : changer
  // l'adresse transfère le compte — un JWT emprunté ne doit pas suffire).
  const [emailPassword, setEmailPassword] = useState("");

  const nameDirty = first.trim() !== firstName || last.trim() !== lastName;
  const emailChanged = mail.trim() !== email && mail.trim() !== "";

  const saveName = (event: FormEvent) => {
    event.preventDefault();
    update.mutate({ firstName: first.trim(), lastName: last.trim() });
  };

  // P4-74 — « confirmer d'abord, basculer ensuite » : la saisie DÉCLENCHE la
  // demande (lien envoyé à la nouvelle adresse). L'adresse actuelle reste
  // active : on la remet dans le champ, la nouvelle passe « en attente ».
  const requestEmailChange = () => {
    requestEmail.mutate(
      { email: mail.trim(), currentPassword: emailPassword },
      {
        onSuccess: () => {
          setMail(email);
          setEmailPassword("");
        },
      },
    );
  };

  return (
    <Card>
      <CardHeader>
        <CardTitle>Mes informations</CardTitle>
      </CardHeader>
      <CardContent className="space-y-6">
        <form className="space-y-4" onSubmit={saveName}>
          <div className="grid grid-cols-2 gap-3">
            <div className="space-y-1">
              <Label htmlFor="firstName">Prénom</Label>
              <Input id="firstName" value={first} onChange={(e) => setFirst(e.target.value)} required />
            </div>
            <div className="space-y-1">
              <Label htmlFor="lastName">Nom</Label>
              <Input id="lastName" value={last} onChange={(e) => setLast(e.target.value)} required />
            </div>
          </div>
          <Button type="submit" disabled={!nameDirty || update.isPending}>
            {update.isPending ? <Spinner className="size-4" /> : null}
            Enregistrer
          </Button>
        </form>

        <div className="space-y-2 border-t border-border pt-4">
          <Label htmlFor="email">E-mail</Label>
          <Input id="email" type="email" value={mail} onChange={(e) => setMail(e.target.value)} />
          <p className="text-xs text-muted-foreground">
            Changer d'adresse envoie un lien de confirmation à la nouvelle adresse. Votre adresse actuelle reste active
            tant que vous n'avez pas confirmé, et reçoit un avertissement.
          </p>
          {emailChanged ? (
            <div className="space-y-1">
              <Label htmlFor="email-password">Votre mot de passe</Label>
              <PasswordInput
                id="email-password"
                autoComplete="current-password"
                value={emailPassword}
                onChange={(e) => setEmailPassword(e.target.value)}
              />
            </div>
          ) : null}
          {null !== pendingEmail ? (
            <div className="flex flex-wrap items-center justify-between gap-2 rounded-md bg-muted p-3 text-sm">
              <span>
                En attente de confirmation : <strong>{pendingEmail}</strong>
              </span>
              <Button type="button" variant="ghost" size="sm" disabled={cancelEmail.isPending} onClick={() => cancelEmail.mutate()}>
                Annuler
              </Button>
            </div>
          ) : null}
          <Button type="button" variant="outline" disabled={!emailChanged || emailPassword === "" || requestEmail.isPending} onClick={requestEmailChange}>
            {requestEmail.isPending ? <Spinner className="size-4" /> : null}
            Envoyer un lien de confirmation
          </Button>
        </div>
      </CardContent>
    </Card>
  );
}

function PasswordForm() {
  const change = useChangePassword();
  const [current, setCurrent] = useState("");
  const [next, setNext] = useState("");
  const [confirm, setConfirm] = useState("");

  const submit = (event: FormEvent) => {
    event.preventDefault();
    change.mutate(
      { currentPassword: current, newPassword: next },
      {
        onSuccess: () => {
          setCurrent("");
          setNext("");
          setConfirm("");
        },
      },
    );
  };

  return (
    <Card>
      <CardHeader>
        <CardTitle>Mot de passe</CardTitle>
      </CardHeader>
      <CardContent>
        <form className="space-y-4" onSubmit={submit}>
          <div className="space-y-1">
            <Label htmlFor="current">Mot de passe actuel</Label>
            <PasswordInput id="current" autoComplete="current-password" value={current} onChange={(e) => setCurrent(e.target.value)} required />
          </div>
          <NewPasswordFields password={next} confirm={confirm} onPasswordChange={setNext} onConfirmChange={setConfirm} idPrefix="profile-" passwordLabel="Nouveau mot de passe" />
          <Button type="submit" disabled={current === "" || !isPasswordValid(next) || next !== confirm || change.isPending}>
            {change.isPending ? <Spinner className="size-4" /> : null}
            Changer le mot de passe
          </Button>
        </form>
      </CardContent>
    </Card>
  );
}

/** RGPD — portabilité (art. 20) : télécharger ses données de compte en JSON. */
function ExportSection() {
  const exportDownload = useDownloadMyData();
  return (
    <Card>
      <CardHeader>
        <CardTitle>Mes données</CardTitle>
      </CardHeader>
      <CardContent className="space-y-3">
        <p className="text-sm text-muted-foreground">
          Téléchargez une copie de vos données personnelles (identité de compte et adhésions) au format JSON.
        </p>
        <Button
          type="button"
          variant="outline"
          disabled={exportDownload.isPending}
          onClick={() => exportDownload.mutate()}
        >
          {exportDownload.isPending ? <Spinner className="size-4" /> : null}
          Exporter mes données
        </Button>
      </CardContent>
    </Card>
  );
}

/**
 * RGPD — droit à l'effacement, self-service. Confirmation = ré-authentification
 * (mot de passe courant, patron changement de mot de passe) : un JWT volé ne
 * suffit pas à détruire le compte. L'anonymisation est immédiate ; sans autre
 * membre actif, les données du club sont supprimées après un délai de grâce de
 * 30 jours (annulé si un membre revient avant l'échéance).
 */
function DangerZone() {
  const deleteAccount = useDeleteAccount();
  const logout = useLogout();
  const [password, setPassword] = useState("");

  const submit = (event: FormEvent) => {
    event.preventDefault();
    deleteAccount.mutate(password, {
      onSuccess: (result) => {
        toast.info(
          result.clubPurgeScheduled
            ? `Compte supprimé. Sans autre membre actif, les données du club seront effacées dans ${result.gracePeriodDays} jours.`
            : "Compte supprimé.",
        );
        logout();
      },
    });
  };

  return (
    <Card className="border-destructive/40">
      <CardHeader>
        <CardTitle className="text-destructive">Supprimer mon compte</CardTitle>
      </CardHeader>
      <CardContent>
        <form className="space-y-3" onSubmit={submit}>
          <p className="text-sm text-muted-foreground">
            Action <strong>irréversible</strong> : vos données personnelles sont anonymisées immédiatement. Si vous êtes
            le dernier membre actif, les données du club seront supprimées après un délai de 30 jours (seule la fiche
            publique FFBB du club est conservée).
          </p>
          <div className="space-y-1">
            <Label htmlFor="deletePassword">Confirmez avec votre mot de passe</Label>
            <PasswordInput
              id="deletePassword"
              autoComplete="current-password"
              value={password}
              onChange={(e) => setPassword(e.target.value)}
            />
          </div>
          <Button type="submit" variant="destructive" disabled={password === "" || deleteAccount.isPending}>
            {deleteAccount.isPending ? <Spinner className="size-4" /> : null}
            Supprimer définitivement mon compte
          </Button>
        </form>
      </CardContent>
    </Card>
  );
}

export function ProfilePage() {
  const { data, isLoading } = useMe();

  if (isLoading || !data) {
    return <FullPageSpinner />;
  }

  return (
    <FichePage className="space-y-4">
      <PageHeader title="Profil" screen="/profile" subtitle={`${data.club?.name ?? "—"} · ${data.role ?? "—"}`} />
      <ProfileForm firstName={data.firstName} lastName={data.lastName} email={data.email} pendingEmail={data.pendingEmail} />
      <PasswordForm />
      <ExportSection />
      <DangerZone />
    </FichePage>
  );
}
