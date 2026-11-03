import { useState } from "react";
import { HTTPError } from "ky";
import { Check, Copy, Eye, Mail, Send } from "lucide-react";

import { todayISO } from "@/features/cockpit/lib/date";
import { ResourceFilter } from "@/features/planning/ResourceFilter";
import { usePriorityTiers, useUpdateCoach, useWizardTeams } from "@/features/wizard/queries";
import { StatusPill } from "@/shared/components/ui/badge";
import { Button } from "@/shared/components/ui/button";
import { ConfirmDialog } from "@/shared/components/ui/confirm-dialog";
import { EmptyHint } from "@/shared/components/ui/empty-hint";
import { FilterChip } from "@/shared/components/ui/filter-chip";
import { Input } from "@/shared/components/ui/input";
import { ReadOnlyFieldset } from "@/shared/components/ui/read-only-fieldset";
import { LoadErrorHint } from "@/shared/components/ui/load-error-hint";
import { Modal } from "@/shared/components/ui/modal";
import { NoticeBanner } from "@/shared/components/ui/notice-banner";
import { Select } from "@/shared/components/ui/select";
import { FullPageSpinner, Spinner } from "@/shared/components/ui/spinner";
import { groupTeamsByTier, tierGroupLabel } from "@/shared/lib/teamTiers";
import { copyToClipboard } from "@/shared/lib/clipboard";
import { ManagerOnly } from "@/shared/session/ManagerOnly";
import { useCanManage } from "@/shared/session/queries";
import { ReadOnlyNotice } from "@/shared/session/ReadOnlyNotice";

import { doleancesLink, type CampaignCoach, type CampaignTeam, type CoachWishCampaign } from "./campaignApi";
import { useCoachWishEmailPreview, useRemindCampaignSilent, useSendCampaignLinks, useSetCampaignRecipient } from "./campaignQueries";
import { COACH_WISHES_MANAGER_TEXTS as TEXTS } from "./texts/manager";

const frDate = (iso: string): string => {
  const [y, m, d] = iso.split("-");
  return `${d}/${m}/${y}`;
};

/** Jour calendaire Europe/Paris d'une date (YYYY-MM-DD) — le back throttle sur CE fuseau. */
const parisDay = (d: Date): string => new Intl.DateTimeFormat("fr-CA", { timeZone: "Europe/Paris", year: "numeric", month: "2-digit", day: "2-digit" }).format(d);

/**
 * lastReminderAt (ISO) tombe-t-il le MÊME jour que l'« aujourd'hui » de l'app ? (parité
 * back, D3). Le « aujourd'hui » vient de `todayISO()` (horloge simulée d'un club démo
 * comprise), PAS de `new Date()` : sous une date simulée, le throttle « déjà relancé
 * aujourd'hui » suit la même date que le serveur (`CoachWishCampaignActionController::
 * remind`, qui compare le jour Europe/Paris de `clock->now()`).
 */
const isSameParisDay = (iso: string | null): boolean => null !== iso && parisDay(new Date(iso)) === todayISO();

/** Message d'erreur d'une action d'envoi : 409 = saison close (jamais réessayable), sinon défaut. */
const errorMessage = (error: unknown, fallback: string): string => (error instanceof HTTPError && 409 === error.response.status ? TEXTS.seasonClosed : fallback);

type CoachStatus = "responded" | "pending" | "no-email";

/**
 * Prédicats de statut d'un coach — NON exclusifs (correctif #1137, décision fondateur 2026-10-09 :
 * « tu n'as pas répondu, ce n'est pas parce que tu n'as pas d'email que tu n'as pas répondu »).
 *  - « En attente » (`pending`) = tout coach SANS réponse, avec OU sans email — son décompte
 *    coïncide ainsi avec le N du libellé d'onglet (`CoachWishesHub.sollicitationLabel`, même
 *    critère `respondedAt === null`).
 *  - « Répondu » (`responded`) = a répondu (souvent via WhatsApp, sans email).
 *  - « Pas d'email » (`no-email`) = critère INDÉPENDANT : les coachs sans email, qu'ils aient
 *    répondu ou non.
 * Un même coach peut relever de PLUSIEURS statuts (p. ex. « Répondu » + « Pas d'email »). Les
 * puces se cumulent en OU : un coach apparaît s'il satisfait AU MOINS une puce cochée.
 */
const STATUS_MATCHERS: Record<CoachStatus, (c: CampaignCoach) => boolean> = {
  responded: (c) => null !== c.respondedAt,
  pending: (c) => null === c.respondedAt,
  "no-email": (c) => null === c.email || "" === c.email,
};

const STATUS_LABELS: { key: CoachStatus; label: string }[] = [
  { key: "responded", label: TEXTS.statusResponded },
  { key: "pending", label: TEXTS.statusPending },
  { key: "no-email", label: TEXTS.statusNoEmail },
];

/**
 * Onglet « Sollicitation » de la fenêtre doléances (ex-moitié « Coachs » de `CampaignDialog`) :
 * suivi des réponses et envoi des liens personnels d'une campagne. N'EXISTE que si une campagne
 * a été créée. Déplacement VERBATIM, avec les seuls changements décidés le 2026-10-09 :
 *  - le filtre par équipe passe par `ResourceFilter viewMode="equipe"` (même sélecteur que
 *    l'onglet Doléances, restreint aux équipes de la campagne) plutôt qu'une rangée de puces ;
 *  - le filtre statut démarre sur « En attente » ;
 *  - le bouton par coach devient un « Lien » compact.
 */
export function SollicitationTab({
  campaign,
  onEmailSaved,
  onCampaignRefreshed,
}: {
  campaign: CoachWishCampaign;
  onEmailSaved: (coachId: string, email: string) => void;
  onCampaignRefreshed: (c: CoachWishCampaign) => void;
}) {
  const canManage = useCanManage();
  const sendLinks = useSendCampaignLinks();
  const remind = useRemindCampaignSilent();
  const teamsQuery = useWizardTeams();
  const setRecipient = useSetCampaignRecipient({ onSuccess: (r) => onCampaignRefreshed(r.campaign) });
  const { data: tiers = [] } = usePriorityTiers();
  // Aperçu de l'e-mail (envoi initial) — chargé seulement quand la fenêtre est ouverte.
  const [previewOpen, setPreviewOpen] = useState(false);

  // Filtres (D1/D2/D3) : par ÉQUIPE (celles de la campagne) et par STATUT, additifs dans
  // chaque axe, combinés en ET entre axes. N'affectent QUE la liste, jamais les boutons (D4).
  const [teamFilter, setTeamFilter] = useState<string[]>([]);
  // Décision fondateur 2026-10-09 : le filtre statut démarre sur « En attente » — le gestionnaire
  // vient d'abord voir qui n'a pas répondu.
  const [statusFilter, setStatusFilter] = useState<Set<CoachStatus>>(new Set(["pending"]));

  // Équipes de la campagne (id + nom) pour le sélecteur de filtre, GROUPÉES PAR RANG comme
  // l'onglet Doléances (même `ResourceFilter viewMode="equipe"`, même regroupement partagé).
  const campaignTeamIds = new Set(campaign.teamIds);
  const campaignTeams = (teamsQuery.data ?? []).filter((t) => campaignTeamIds.has(t.id));
  const teamGroups = groupTeamsByTier(campaignTeams, tiers).map((g) => ({ label: tierGroupLabel(g.tier), resources: g.teams.map((t) => ({ id: t.id, label: t.name })) }));
  const teamName = new Map((teamsQuery.data ?? []).map((t) => [t.id, t.name]));

  // Un seul destinataire par équipe : le gestionnaire le choisit, sur une collecte ouverte.
  const [pendingRecipient, setPendingRecipient] = useState<{ team: CampaignTeam; coachId: string } | null>(null);
  const contested = campaign.open ? campaign.teams.filter((t) => t.candidates.length >= 2) : [];
  const withoutMain = campaign.open ? campaign.teams.filter((t) => 0 === t.candidates.length) : [];
  const fullName = (team: CampaignTeam, coachId: string | null): string => {
    const c = team.candidates.find((k) => k.coachId === coachId);
    return undefined === c ? "" : `${c.firstName} ${c.lastName}`.trim();
  };
  const rowOf = (coachId: string | null) => campaign.coaches.find((c) => c.coachId === coachId);
  const changeRecipient = (team: CampaignTeam, coachId: string) => {
    const current = rowOf(team.recipientCoachId);
    if (undefined !== current && (null !== current.sentAt || null !== current.respondedAt)) {
      setPendingRecipient({ team, coachId });
      return;
    }
    setRecipient.mutate({ id: campaign.id, teamId: team.teamId, coachId });
  };

  const toggleStatus = (value: CoachStatus) => {
    const next = new Set(statusFilter);
    if (next.has(value)) {
      next.delete(value);
    } else {
      next.add(value);
    }
    setStatusFilter(next);
  };

  const visibleCoaches = campaign.coaches.filter((c) => {
    const okTeam = 0 === teamFilter.length || c.teamIds.some((t) => teamFilter.includes(t));
    const okStatus = 0 === statusFilter.size || [...statusFilter].some((s) => STATUS_MATCHERS[s](c));
    return okTeam && okStatus;
  });

  // Envoi global : les coachs à email PAS ENCORE servis (le bouton n'est pas un renvoi — D2).
  const unsentWithEmail = campaign.coaches.filter((c) => null !== c.email && "" !== c.email && null === c.sentAt);
  // Relance : silencieux à email — et une seule fois par jour (D3).
  const silentWithEmail = campaign.coaches.filter((c) => null !== c.email && "" !== c.email && null === c.respondedAt);
  const remindedToday = isSameParisDay(campaign.lastReminderAt);

  // Aperçu « ce que voit le coach » (D2) : un sélecteur de coach + ouverture de la VRAIE page en
  // lecture seule dans un nouvel onglet (le gestionnaire garde la collecte sous les yeux). Défaut
  // = le premier coach. Le bouton d'ajout de bloc #1129 vit ailleurs : zone distincte, voulu.
  const [previewCoachId, setPreviewCoachId] = useState(campaign.coaches[0]?.coachId ?? "");

  return (
    <div className="mt-2">
      {/* Un Membre lit l'état de la collecte et les adresses ; aucun lien, aucun envoi. */}
      <ReadOnlyNotice />
      <p className="text-sm font-medium">{TEXTS.linksSummary(campaign.respondedCoachCount, campaign.totalCoachCount)}</p>
      {contested.length > 0 ? (
        <div className="mt-2 rounded-md border border-border p-2">
          <p className="text-sm font-medium">{TEXTS.recipientBlockTitle}</p>
          <p className="text-xs text-muted-foreground">{TEXTS.recipientBlockHelp}</p>
          <ReadOnlyFieldset readOnly={!canManage} className="mt-2 flex flex-wrap gap-3">
            {contested.map((team) => (
              <label key={team.teamId} className="text-xs text-muted-foreground">
                {TEXTS.recipientSelectLabel(teamName.get(team.teamId) ?? TEXTS.teamFallback)}
                <Select
                  aria-label={TEXTS.recipientSelectLabel(teamName.get(team.teamId) ?? TEXTS.teamFallback)}
                  wrapperClassName="mt-0.5 w-56"
                  value={team.recipientCoachId ?? ""}
                  disabled={setRecipient.isPending}
                  onChange={(e) => changeRecipient(team, e.target.value)}
                >
                  {team.candidates.map((c) => (
                    <option key={c.coachId} value={c.coachId}>
                      {c.firstName} {c.lastName}
                    </option>
                  ))}
                </Select>
              </label>
            ))}
          </ReadOnlyFieldset>
        </div>
      ) : null}
      {withoutMain.length > 0 ? <NoticeBanner tone="warning" className="mt-2" message={TEXTS.noMainCoach(withoutMain.map((t) => teamName.get(t.teamId) ?? TEXTS.teamFallback))} /> : null}
      <ConfirmDialog
        open={null !== pendingRecipient}
        title={TEXTS.recipientConfirmTitle}
        description={
          null === pendingRecipient
            ? undefined
            : [
                (null !== rowOf(pendingRecipient.team.recipientCoachId)?.respondedAt ? TEXTS.recipientConfirmAnswered : TEXTS.recipientConfirmReceived)(
                  fullName(pendingRecipient.team, pendingRecipient.team.recipientCoachId),
                  fullName(pendingRecipient.team, pendingRecipient.coachId),
                  teamName.get(pendingRecipient.team.teamId) ?? TEXTS.teamFallback,
                ),
                ...(null !== (rowOf(pendingRecipient.coachId)?.sentAt ?? null) ? [TEXTS.recipientConfirmAlreadyServed(fullName(pendingRecipient.team, pendingRecipient.coachId))] : []),
              ].join(" ")
        }
        confirmLabel={TEXTS.recipientConfirmChange}
        cancelLabel={TEXTS.recipientConfirmCancel}
        destructive={false}
        onConfirm={() => {
          if (null !== pendingRecipient) {
            setRecipient.mutate({ id: campaign.id, teamId: pendingRecipient.team.teamId, coachId: pendingRecipient.coachId });
          }
          setPendingRecipient(null);
        }}
        onCancel={() => setPendingRecipient(null)}
      />
      <ManagerOnly>
      <div className="mt-2 flex flex-wrap gap-2">
        <Button
          variant="outline"
          size="sm"
          disabled={0 === unsentWithEmail.length || sendLinks.isPending}
          disabledReason={0 === unsentWithEmail.length ? TEXTS.allServedReason : undefined}
          onClick={() => sendLinks.mutate({ id: campaign.id }, { onSuccess: (r) => onCampaignRefreshed(r.campaign) })}
        >
          {sendLinks.isPending ? <Spinner className="size-4" /> : <Send className="size-4" />}
          {TEXTS.sendLinks}
        </Button>
        <Button
          variant="ghost"
          size="sm"
          disabled={remindedToday || 0 === silentWithEmail.length || remind.isPending}
          disabledReason={remindedToday ? TEXTS.remindedTodayReason : 0 === silentWithEmail.length ? TEXTS.noSilentReason : undefined}
          onClick={() => remind.mutate(campaign.id, { onSuccess: (r) => onCampaignRefreshed(r.campaign) })}
        >
          {remind.isPending ? <Spinner className="size-4" /> : null}
          {TEXTS.remind}
        </Button>
        {/* Aperçu : ce que le gestionnaire verra PARTIR à l'envoi initial (lien coach habillé). */}
        <Button variant="ghost" size="sm" onClick={() => setPreviewOpen(true)}>
          <Mail className="size-4" />
          {TEXTS.emailPreview}
        </Button>
      </div>
      </ManagerOnly>
      {previewOpen ? <EmailPreviewModal campaignId={campaign.id} onClose={() => setPreviewOpen(false)} /> : null}
      {/* Aperçu coach par coach (D2) — zone distincte du reste des actions. */}
      {campaign.coaches.length > 0 ? (
        <div className="mt-2 flex flex-wrap items-end gap-2">
          <label className="text-xs text-muted-foreground">
            {TEXTS.pagePreviewLabel}
            <Select aria-label={TEXTS.pagePreviewAria} wrapperClassName="mt-0.5 w-48" value={previewCoachId} onChange={(e) => setPreviewCoachId(e.target.value)}>
              {campaign.coaches.map((c) => (
                <option key={c.coachId} value={c.coachId}>
                  {c.firstName} {c.lastName}
                </option>
              ))}
            </Select>
          </label>
          <Button
            variant="outline"
            size="sm"
            disabled={"" === previewCoachId}
            onClick={() => window.open(`/doleances/apercu/${campaign.id}?coach=${previewCoachId}`, "_blank", "noopener")}
          >
            <Eye className="size-4" />
            {TEXTS.pagePreviewButton}
          </Button>
        </div>
      ) : null}

      {/* Sans ceci un 422/409 resterait muet ; on distingue « saison close » (409) de « déjà
          relancé aujourd'hui » (422 — cache périmé, autre onglet) pour ne pas mal expliquer. */}
      {sendLinks.isError ? <p className="mt-2 text-sm text-destructive">{errorMessage(sendLinks.error, TEXTS.sendFailed)}</p> : null}
      {remind.isError ? <p className="mt-2 text-sm text-destructive">{errorMessage(remind.error, TEXTS.remindFailed)}</p> : null}

      {/* Filtres — n'affectent QUE la liste ci-dessous (les boutons gardent leur périmètre, D4).
          Le filtre par ÉQUIPE réutilise `ResourceFilter` (même sélecteur que l'onglet Doléances) ;
          les puces STATUT restent des `FilterChip`. */}
      {campaign.coaches.length > 0 ? (
        <div className="mt-3 flex flex-wrap items-center gap-1.5">
          {campaignTeams.length > 1 ? (
            <ResourceFilter viewMode="equipe" groups={teamGroups} selected={teamFilter} onToggle={(id) => setTeamFilter((p) => (p.includes(id) ? p.filter((x) => x !== id) : [...p, id]))} onClear={() => setTeamFilter([])} />
          ) : null}
          <span className="text-xs text-muted-foreground">{TEXTS.statusLabel}</span>
          {STATUS_LABELS.map((s) => (
            <FilterChip key={s.key} pressed={statusFilter.has(s.key)} onPress={() => toggleStatus(s.key)}>
              {s.label}
            </FilterChip>
          ))}
        </div>
      ) : null}

      {0 === campaign.coaches.length ? (
        <EmptyHint className="mt-1">{TEXTS.noCoach}</EmptyHint>
      ) : 0 === visibleCoaches.length ? (
        <EmptyHint className="mt-2">{TEXTS.noCoachForFilter}</EmptyHint>
      ) : (
        <ul className="mt-2 space-y-2" aria-label={TEXTS.coachListLabel}>
          {visibleCoaches.map((coach) => (
            <CoachRow key={coach.coachId} coach={coach} campaignId={campaign.id} onEmailSaved={onEmailSaved} onCampaignRefreshed={onCampaignRefreshed} />
          ))}
        </ul>
      )}
    </div>
  );
}

/**
 * Aperçu de l'e-mail du lien coach (D1) — le rendu EXACT du serveur (sujet + expéditeur +
 * HTML), bâti avec un jeton factice. Le HTML vit dans une iframe `sandbox=""` (ni script, ni
 * même origine), patron de `MailboxPage` : du HTML non fiable ne s'injecte jamais autrement.
 * Lecture lue à la `readState` (inlinée) : `undefined` = chargement, ou échec s'il n'y a rien.
 */
function EmailPreviewModal({ campaignId, onClose }: { campaignId: string; onClose: () => void }) {
  const query = useCoachWishEmailPreview(campaignId, true);

  return (
    <Modal label={TEXTS.emailPreview} title={TEXTS.emailPreview} size="lg" onClose={onClose}>
      {renderBody()}
    </Modal>
  );

  function renderBody() {
    if (undefined === query.data) {
      return query.isError ? (
        <LoadErrorHint onRetry={() => void query.refetch()}>{TEXTS.emailPreviewLoadFailed}</LoadErrorHint>
      ) : (
        <FullPageSpinner />
      );
    }

    const preview = query.data;
    return (
      <div className="space-y-3">
        <div className="space-y-1 border-b border-border pb-3 text-sm">
          <p>
            <span className="text-muted-foreground">{TEXTS.emailSubject}</span> <span className="font-semibold text-foreground">{preview.subject}</span>
          </p>
          <p>
            <span className="text-muted-foreground">{TEXTS.emailFrom}</span> <span className="text-foreground">{preview.from}</span>
          </p>
        </div>
        <iframe title={TEXTS.emailPreview} sandbox="" srcDoc={preview.html} className="h-[28rem] w-full rounded border border-border bg-white" />
      </div>
    );
  }
}

function CoachRow({ coach, campaignId, onEmailSaved, onCampaignRefreshed }: { coach: CampaignCoach; campaignId: string; onEmailSaved: (coachId: string, email: string) => void; onCampaignRefreshed: (c: CoachWishCampaign) => void }) {
  const [copied, setCopied] = useState(false);
  const [email, setEmail] = useState(coach.email ?? "");
  const updateCoach = useUpdateCoach();
  const sendLinks = useSendCampaignLinks();
  // Le lien personnel n'est servi qu'au gestionnaire (`token` absent pour un Membre).
  const canManage = useCanManage();
  const token = coach.token;

  const copy = async () => {
    if (undefined === token) {
      return;
    }
    if (await copyToClipboard(doleancesLink(token))) {
      setCopied(true);
      window.setTimeout(() => setCopied(false), 2000);
    }
  };

  const saveEmail = () => {
    const trimmed = email.trim();
    if ("" === trimmed || trimmed === (coach.email ?? "")) {
      return;
    }
    updateCoach.mutate({ id: coach.coachId, body: { firstName: coach.firstName, lastName: coach.lastName, email: trimmed } }, { onSuccess: () => onEmailSaved(coach.coachId, trimmed) });
  };

  const hasEmail = null !== coach.email && "" !== coach.email;

  return (
    <li className="rounded-md border border-border p-2">
      <div className="flex flex-wrap items-center gap-2">
        <span className="text-sm font-medium">
          {coach.firstName} {coach.lastName}
        </span>
        {null !== coach.respondedAt ? (
          <StatusPill variant="accent" icon={<Check className="size-3 text-accent" aria-hidden="true" />}>
            {TEXTS.respondedOn(frDate(coach.respondedAt.slice(0, 10)))}
          </StatusPill>
        ) : null}
        {hasEmail ? null : <StatusPill>{TEXTS.noEmailPill}</StatusPill>}
        {null !== coach.sentAt ? <StatusPill>{TEXTS.sentOn(frDate(coach.sentAt.slice(0, 10)))}</StatusPill> : null}
        {hasEmail && canManage ? (
          // Envoi CIBLÉ (ajout tardif d'un email, ou renvoi volontaire à CE coach — D1).
          <Button variant="ghost" size="sm" disabled={sendLinks.isPending} onClick={() => sendLinks.mutate({ id: campaignId, coachIds: [coach.coachId] }, { onSuccess: (r) => onCampaignRefreshed(r.campaign) })}>
            <Send className="size-4" />
            {null === coach.sentAt ? TEXTS.send : TEXTS.resend}
          </Button>
        ) : null}
        {/* Bouton COMPACT « Lien » (décision fondateur 2026-10-09) : icône + « Lien » → « Copié »
            2 s ; le nom accessible CONTEXTUALISE (A11Y-28) pour distinguer les coachs à l'écoute. */}
        {canManage && undefined !== token ? (
          <Button variant="outline" size="sm" className="ml-auto" aria-label={TEXTS.copyLinkAria(`${coach.firstName} ${coach.lastName}`)} onClick={copy}>
            {copied ? <Check className="size-4" /> : <Copy className="size-4" />}
            {copied ? TEXTS.copied : TEXTS.linkButton}
          </Button>
        ) : null}
      </div>
      <ReadOnlyFieldset readOnly={!canManage} className="mt-1.5 flex items-center gap-2">
        <Input type="email" placeholder={TEXTS.emailPlaceholder} className="flex-1 text-xs" value={email} onChange={(e) => setEmail(e.target.value)} onBlur={saveEmail} aria-label={TEXTS.emailAria(`${coach.firstName} ${coach.lastName}`)} />
      </ReadOnlyFieldset>
    </li>
  );
}
