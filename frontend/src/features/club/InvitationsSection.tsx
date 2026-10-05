import { Mail, RotateCcw, Trash2 } from "lucide-react";
import { type FormEvent, useState } from "react";

import { Button } from "@/shared/components/ui/button";
import { Card, CardContent } from "@/shared/components/ui/card";
import { ConfirmDialog } from "@/shared/components/ui/confirm-dialog";
import { EmptyHint } from "@/shared/components/ui/empty-hint";
import { Input } from "@/shared/components/ui/input";
import { LoadErrorHint } from "@/shared/components/ui/load-error-hint";
import { NoticeBanner } from "@/shared/components/ui/notice-banner";
import { Select } from "@/shared/components/ui/select";
import { Spinner } from "@/shared/components/ui/spinner";
import { errorMessage } from "@/shared/lib/errorMessage";
import { readFailed, readLoading } from "@/shared/lib/readState";
import { ASSIGNABLE_ROLES, roleLabel, type AssignableRole } from "@/shared/lib/roles";

import type { ClubInvitation } from "./api";
import { useCreateInvitation, useInvitations, useResendInvitation, useRevokeInvitation } from "./queries";

// Miroir léger du serveur (filter_var FILTER_VALIDATE_EMAIL) pour un refus immédiat
// avant d'émettre ; le serveur reste l'autorité (il revalide et renvoie 422).
const EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

/** « 12/10/2026 » depuis une date ISO `YYYY-MM-DD`, sans Date (aucun décalage de fuseau). */
function frDate(iso: string): string {
  return iso.split("-").reverse().join("/");
}

/**
 * P4-299 — « Invitations » (management, embarqué dans le hub Club). Un gestionnaire
 * saisit une adresse + un rôle (défaut Membre, moindre privilège — patron
 * `PendingMembersSection`) ; la ligne apparaît dans la liste des invitations en cours
 * (adresse, rôle, date d'expiration) avec « Renvoyer » et « Révoquer » (derrière une
 * confirmation, norme N2). Après acceptation la ligne DISPARAÎT (le token consommé est
 * supprimé ; l'invité devient visible dans « Membres », la source de vérité unique).
 *
 * Les erreurs d'ÉMISSION (« déjà membre », « adresse non invitable », quota) sont un
 * retour de SAISIE : affichées inline (`NoticeBanner role="alert"`, annoncé au lecteur
 * d'écran, avec le message exact du serveur), près du formulaire — jamais un toast de
 * fond. Le succès → toast + la ligne ajoutée (câblé dans `useCreateInvitation`).
 */
export function InvitationsSection() {
  const invitationsQuery = useInvitations(true);
  const create = useCreateInvitation();
  const resend = useResendInvitation();
  const revoke = useRevokeInvitation();

  const [email, setEmail] = useState("");
  const [role, setRole] = useState<AssignableRole>("member");
  const [error, setError] = useState<string | null>(null);
  // Invitation en attente de confirmation de révocation (null = dialogue fermé).
  const [toRevoke, setToRevoke] = useState<ClubInvitation | null>(null);

  async function onSubmit(event: FormEvent) {
    event.preventDefault();
    setError(null);
    const trimmed = email.trim();
    if ("" === trimmed || !EMAIL_RE.test(trimmed)) {
      setError("Renseignez une adresse e-mail valide.");
      return;
    }
    try {
      await create.mutateAsync({ email: trimmed, role });
      setEmail("");
      setRole("member");
    } catch (err) {
      setError(await errorMessage(err));
    }
  }

  const sending = create.isPending;

  return (
    <div className="space-y-5">
      <form className="flex flex-col gap-3" onSubmit={onSubmit} noValidate>
        <div className="flex flex-wrap items-end gap-2">
          <div className="flex min-w-[14rem] flex-1 flex-col gap-1.5">
            <label htmlFor="invite-email" className="text-xs text-muted-foreground">
              Adresse e-mail
            </label>
            <Input
              id="invite-email"
              type="email"
              autoComplete="email"
              placeholder="personne@club.fr"
              value={email}
              disabled={sending}
              onChange={(e) => {
                setEmail(e.target.value);
                if (null !== error) setError(null);
              }}
            />
          </div>
          <div className="flex flex-col gap-1.5">
            <label htmlFor="invite-role" className="text-xs text-muted-foreground">
              Rôle
            </label>
            <Select id="invite-role" wrapperClassName="w-40" value={role} disabled={sending} onChange={(e) => setRole(e.target.value as AssignableRole)}>
              {ASSIGNABLE_ROLES.map((r) => (
                <option key={r} value={r}>
                  {roleLabel(r)}
                </option>
              ))}
            </Select>
          </div>
          <Button type="submit" disabled={sending}>
            {sending ? <Spinner className="size-4" /> : <Mail className="size-4" />}
            Inviter
          </Button>
        </div>
        {null !== error ? (
          <NoticeBanner tone="warning" role="alert" message={error} />
        ) : null}
      </form>

      {readLoading(invitationsQuery) ? (
        <div className="flex justify-center py-6">
          <Spinner className="size-5" />
        </div>
      ) : readFailed(invitationsQuery) ? (
        <LoadErrorHint onRetry={() => void invitationsQuery.refetch()}>Impossible de charger les invitations.</LoadErrorHint>
      ) : 0 === (invitationsQuery.data?.invitations.length ?? 0) ? (
        <EmptyHint>Aucune invitation en cours.</EmptyHint>
      ) : (
        <ul className="flex flex-col gap-2">
          {(invitationsQuery.data?.invitations ?? []).map((invitation) => {
            const busy = resend.isPending || revoke.isPending;
            return (
              <li key={invitation.id}>
                <Card>
                  <CardContent className="flex flex-wrap items-center justify-between gap-4 py-4">
                    <div>
                      <p className="font-medium">{invitation.email}</p>
                      <p className="text-sm text-muted-foreground">
                        {roleLabel(invitation.role)} · Expire le {frDate(invitation.expiresAt)}
                      </p>
                    </div>
                    <div className="flex flex-wrap items-center gap-2">
                      <Button size="sm" variant="outline" disabled={busy} onClick={() => resend.mutate(invitation.id)}>
                        <RotateCcw className="size-4" /> Renvoyer
                      </Button>
                      <Button size="sm" variant="ghost" className="text-destructive" disabled={busy} onClick={() => setToRevoke(invitation)}>
                        <Trash2 className="size-4" /> Révoquer
                      </Button>
                    </div>
                  </CardContent>
                </Card>
              </li>
            );
          })}
        </ul>
      )}

      <ConfirmDialog
        open={null !== toRevoke}
        title="Révoquer l'invitation ?"
        description={
          null !== toRevoke ? (
            <>
              Le lien envoyé à <span className="font-medium">{toRevoke.email}</span> cessera de fonctionner. Vous pourrez ré-inviter cette adresse plus tard.
            </>
          ) : undefined
        }
        confirmLabel="Révoquer"
        onCancel={() => setToRevoke(null)}
        onConfirm={() => {
          if (null !== toRevoke) {
            revoke.mutate(toRevoke.id);
          }
          setToRevoke(null);
        }}
      />
    </div>
  );
}
