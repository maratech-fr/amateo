import { useQueryClient } from "@tanstack/react-query";
import { useId, useState } from "react";

import { useUpdateCoach } from "@/features/wizard/queries";
import { StatusPill } from "@/shared/components/ui/badge";
import { Button } from "@/shared/components/ui/button";
import { Input } from "@/shared/components/ui/input";
import { LoadErrorHint } from "@/shared/components/ui/load-error-hint";
import { Modal } from "@/shared/components/ui/modal";
import { Spinner } from "@/shared/components/ui/spinner";
import { coachFullName, coachShortName } from "@/shared/lib/coachName";
import { formatMinutes } from "@/shared/lib/time";

import type { Coach, ConflictCoachMailing, ConflictMailPreview, ConflictMailPreviewRecipient } from "./api";
import { CONFLICT_MAIL_PREVIEW_KEY, useConflictMailPreview, useMailConflictsToCoaches } from "./queries";
import { MATCHES_CONFLICTS_MAIL_TEXTS as MAIL_TEXTS } from "./texts/conflictsMail";

/** Le motif servi quand rien ne partirait → son libellé (présentation seule, le serveur décide). */
const BLOCKED_LABEL: Record<NonNullable<ConflictMailPreview["blockedByDefault"]>, string> = {
  nothing: MAIL_TEXTS.disabledNothingToSend,
  no_address: MAIL_TEXTS.blockedNoAddress,
  nothing_new: MAIL_TEXTS.blockedNothingNew,
  mixed: MAIL_TEXTS.blockedMixed,
};

/** « 03/11 » depuis un instant ATOM servi à l'heure murale du club (on DÉCOUPE, sans fuseau). */
const dayMonth = (atom: string | null): string | null => (null === atom ? null : `${atom.slice(8, 10)}/${atom.slice(5, 7)}`);

/**
 * « Envoyer aux coachs » + la ligne « Envoyé le … » : le serveur calcule TOUT (`coachMailing` du
 * GET, aperçu avant envoi, résultat du POST) ; l'écran n'affiche que ça. Le bouton n'est rendu
 * qu'au gestionnaire ; la ligne, à tous. Rendu AU-DESSUS du retour anticipé « aucun conflit ».
 */
export function CoachMailBar({ mailing, canManage, isDemo, seasonReadonly, seasonName, coaches }: { mailing: ConflictCoachMailing | undefined; canManage: boolean; isDemo: boolean; seasonReadonly: boolean; seasonName: string; coaches: Map<string, Coach> }) {
  const [confirmOpen, setConfirmOpen] = useState(false);
  const reasonId = useId();

  const disabledReason = isDemo
    ? MAIL_TEXTS.disabledDemo
    : seasonReadonly
      ? MAIL_TEXTS.disabledArchived
      : undefined === mailing
        ? MAIL_TEXTS.disabledUnavailable
        : 0 === mailing.personConflictCount && 0 === mailing.pendingRecipientCount
          ? MAIL_TEXTS.disabledNothingToSend
          : undefined;

  const last = mailing?.last ?? null;
  // L'instant est servi à l'heure murale du club : on DÉCOUPE la chaîne, sans conversion de fuseau.
  const statusLine =
    null === last
      ? null
      : MAIL_TEXTS.statusLine({
          date: `${last.sentAt.slice(8, 10)}/${last.sentAt.slice(5, 7)}/${last.sentAt.slice(0, 4)}`,
          time: formatMinutes(Number(last.sentAt.slice(11, 13)) * 60 + Number(last.sentAt.slice(14, 16))),
          sender: last.sentByFirstName,
          mailedCount: last.mailedCount,
          skippedNames: last.skippedCoachIds.map((id) => {
            const coach = coaches.get(id);
            return undefined === coach ? MAIL_TEXTS.coachRemoved : coachShortName(coach);
          }),
        });

  if (!canManage && null === statusLine) {
    return null;
  }

  return (
    <div className="flex flex-col items-end gap-1">
      {canManage ? (
        // Rangée 1 : le bouton et, s'il est grisé, son motif LU en clair à côté (règle front : pas
        // de doublon `disabledReason`) ; le bouton le désigne par `aria-describedby`.
        <div className="flex flex-wrap items-center justify-end gap-x-3 gap-y-1">
          <Button type="button" variant="outline" size="sm" disabled={undefined !== disabledReason} aria-describedby={undefined === disabledReason ? undefined : reasonId} onClick={() => setConfirmOpen(true)}>
            {MAIL_TEXTS.sendButton}
          </Button>
          {undefined === disabledReason ? null : (
            <span id={reasonId} className="text-sm text-muted-foreground">
              {disabledReason}
            </span>
          )}
        </div>
      ) : null}
      {/* Rangée 2 : la ligne « Envoyé le … », jamais collée au motif. */}
      {null === statusLine ? null : <p className="text-xs text-muted-foreground">{statusLine}</p>}
      {confirmOpen ? <CoachMailPreviewModal seasonName={seasonName} onClose={() => setConfirmOpen(false)} /> : null}
    </div>
  );
}

/**
 * La modale d'aperçu avant envoi : qui recevra quoi (relu sur `GET …/mail-coaches/preview` à chaque
 * ouverture), l'adresse d'un coach saisie ou corrigée sur place, la case « Tout renvoyer ». Rien
 * n'est recalculé : chaque nombre, chaque « recevra / ne recevra rien », chaque motif vient du
 * serveur ; la case choisit seulement quelle colonne servie on lit (aucune relecture).
 */
function CoachMailPreviewModal({ seasonName, onClose }: { seasonName: string; onClose: () => void }) {
  const preview = useConflictMailPreview(true);
  const send = useMailConflictsToCoaches();
  const updateCoach = useUpdateCoach();
  const queryClient = useQueryClient();
  const [resendAll, setResendAll] = useState(false);
  const [savedMessage, setSavedMessage] = useState("");
  const data = preview.data;

  const blocked = undefined === data ? null : resendAll ? data.blockedOnResendAll : data.blockedByDefault;
  // « Envoyer » grisé, avec son motif, tant que l'aperçu n'est pas sûr ou que rien ne partirait.
  const confirmReason = send.isPending
    ? undefined
    : preview.isFetching
      ? MAIL_TEXTS.confirmDisabledRefreshing
      : updateCoach.isPending
        ? MAIL_TEXTS.confirmDisabledSaving
        : undefined === data
          ? MAIL_TEXTS.previewLoadFailed
          : null === blocked
            ? undefined
            : BLOCKED_LABEL[blocked];

  const saveEmail = (recipient: ConflictMailPreviewRecipient, value: string) => {
    const trimmed = value.trim();
    // La modale saisit ou corrige une adresse, ne la RETIRE jamais (vide ou inchangée : rien).
    if ("" === trimmed || trimmed === (recipient.email ?? "")) {
      return;
    }
    setSavedMessage("");
    // Pas d'`onError` ici : le filet global toaste l'échec du PUT, une seule fois.
    updateCoach.mutate(
      { id: recipient.coachId, body: { firstName: recipient.firstName, lastName: recipient.lastName, email: trimmed } },
      {
        onSuccess: () => {
          setSavedMessage(MAIL_TEXTS.emailSaved);
          void queryClient.invalidateQueries({ queryKey: CONFLICT_MAIL_PREVIEW_KEY });
        },
      },
    );
  };

  return (
    <Modal
      label={MAIL_TEXTS.confirmTitle}
      title={MAIL_TEXTS.confirmTitle}
      size="lg"
      onClose={onClose}
      footer={
        <>
          <Button type="button" variant="outline" onClick={onClose}>
            {MAIL_TEXTS.confirmCancel}
          </Button>
          <Button type="button" disabled={send.isPending || undefined !== confirmReason} disabledReason={confirmReason} onClick={() => send.mutate({ resendAll }, { onSettled: onClose })}>
            {send.isPending ? MAIL_TEXTS.sending : MAIL_TEXTS.confirmAction}
          </Button>
        </>
      }
    >
      <div className="flex flex-col gap-3 text-sm">
        <p>{MAIL_TEXTS.confirmBody(seasonName)}</p>
        <p>{MAIL_TEXTS.confirmIgnoresFilters}</p>
        {undefined !== data ? (
          <>
            <ul className="flex flex-col gap-2">
              {data.recipients.map((recipient) => (
                <RecipientRow key={recipient.coachId} recipient={recipient} resendAll={resendAll} onSaveEmail={saveEmail} />
              ))}
            </ul>
            <p className="font-medium">
              {MAIL_TEXTS.previewSummary({
                mails: resendAll ? data.resendAllMailCount : data.defaultMailCount,
                noAddress: data.recipients.filter((recipient) => !recipient.hasValidAddress).length,
                resendAll,
              })}
            </p>
            <label className="flex w-fit items-center gap-2">
              <input type="checkbox" className="size-4" checked={resendAll} onChange={(event) => setResendAll(event.target.checked)} />
              {MAIL_TEXTS.resendAllLabel}
            </label>
          </>
        ) : preview.isError ? (
          <LoadErrorHint onRetry={() => void preview.refetch()}>{MAIL_TEXTS.previewLoadFailed}</LoadErrorHint>
        ) : (
          <Spinner />
        )}
        <p className="sr-only" aria-live="polite">
          {savedMessage}
        </p>
      </div>
    </Modal>
  );
}

/** Une ligne de l'aperçu : « Prénom N. — adresse », le détail servi, ce qui partira ; le champ d'adresse si elle manque ou est invalide. */
function RecipientRow({ recipient, resendAll, onSaveEmail }: { recipient: ConflictMailPreviewRecipient; resendAll: boolean; onSaveEmail: (recipient: ConflictMailPreviewRecipient, value: string) => void }) {
  const [email, setEmail] = useState(recipient.email ?? "");
  const noEmail = null === recipient.email || "" === recipient.email;

  const detail =
    "cleared" === recipient.kind
      ? MAIL_TEXTS.clearedDetail
      : MAIL_TEXTS.recipientDetail({
          conflictCount: recipient.conflictCount,
          newCount: recipient.newCount,
          changedCount: recipient.changedCount,
          alreadySentCount: recipient.alreadySentCount,
          lastSentDate: dayMonth(recipient.lastSentAt),
          receiptKnown: recipient.receiptKnown,
        });
  // Lu sur les drapeaux SERVIS (`sendsByDefault` / `sendsOnResendAll`) ; seul le libellé est choisi ici.
  const sends = resendAll ? recipient.sendsOnResendAll : recipient.sendsByDefault;
  const fullList = "conflicts" === recipient.kind && (resendAll || !recipient.receiptKnown);
  const verdict = sends ? (fullList ? MAIL_TEXTS.willSendFull : MAIL_TEXTS.willSend) : recipient.hasValidAddress ? MAIL_TEXTS.willNotSendNothingNew : MAIL_TEXTS.willNotSendNoAddress;

  return (
    <li className="flex flex-col gap-1 rounded-md border border-border p-2">
      <div className="flex flex-wrap items-center gap-2">
        <span className="font-medium">{MAIL_TEXTS.recipientLine({ name: coachShortName(recipient), email: recipient.email })}</span>
        {recipient.hasValidAddress ? null : <StatusPill variant="warning">{noEmail ? MAIL_TEXTS.noAddressPill : MAIL_TEXTS.invalidAddressPill}</StatusPill>}
      </div>
      <p className="text-xs text-muted-foreground">
        {detail} — {verdict}
      </p>
      {recipient.hasValidAddress ? null : (
        <Input
          type="email"
          className="text-xs"
          value={email}
          placeholder={MAIL_TEXTS.emailPlaceholder}
          aria-label={MAIL_TEXTS.emailAria(coachFullName(recipient))}
          onChange={(event) => setEmail(event.target.value)}
          onBlur={() => onSaveEmail(recipient, email)}
        />
      )}
    </li>
  );
}
