import { Search } from "lucide-react";
import { type ReactNode, useEffect, useState } from "react";
import { useSearchParams } from "react-router";

import { Button } from "@/shared/components/ui/button";
import { EmptyHint } from "@/shared/components/ui/empty-hint";
import { Modal } from "@/shared/components/ui/modal";
import { Spinner } from "@/shared/components/ui/spinner";
import { errorMessage } from "@/shared/lib/errorMessage";
import { cn } from "@/shared/lib/utils";

import type { AdminFeedbackContext, AdminFeedbackItem, AdminFeedbackQos, AdminFeedbackStatus } from "../api";
import { useAdminFeedback, useAdminFeedbackDetail, useTreatAdminFeedback, useUntreatAdminFeedback } from "../queries";
import { ADMIN_SECTIONS_TEXTS } from "../texts/sections";

const dateFormatter = new Intl.DateTimeFormat("fr-FR", { day: "2-digit", month: "short", year: "numeric", hour: "2-digit", minute: "2-digit" });

const fmtDate = (iso: string): string => {
  const d = new Date(iso);
  return Number.isNaN(d.getTime()) ? iso : dateFormatter.format(d);
};

const fmtPct = (v: number): string => `${Math.round(v * 100)} %`;

const fmtHours = (v: number | null): string => {
  if (null === v) return "—";
  return Number.isInteger(v) ? `${v} h` : `${v.toFixed(1)} h`;
};

type StatusFilter = "all" | AdminFeedbackStatus;

const FILTERS: { id: StatusFilter; label: string }[] = [
  { id: "all", label: ADMIN_SECTIONS_TEXTS.feedbackFilterAll },
  { id: "untreated", label: ADMIN_SECTIONS_TEXTS.feedbackFilterUntreated },
  { id: "treated", label: ADMIN_SECTIONS_TEXTS.feedbackFilterTreated },
];

/**
 * Onglet « Signalements » de la console SA (P5-6, D6) : le panneau qualité de service en
 * tête (§3ter), la liste filtrable par statut + recherche (club ou mot du message, `q` serveur),
 * et le détail (message + contexte LARGE en 5 blocs) rendu en TEXTE PUR — le snapshot n'est
 * jamais déplié dans le DOM (métadonnées + copie JSON). Deep-link `?feedback={id}` ouvre
 * directement un signalement (lien du digest) ; `?fstatus` initialise le filtre.
 */
export function FeedbackSection() {
  const [searchParams] = useSearchParams();
  const initialStatus = searchParams.get("fstatus");
  const [filter, setFilter] = useState<StatusFilter>("untreated" === initialStatus || "treated" === initialStatus ? initialStatus : "all");
  const [openId, setOpenId] = useState<string | null>(searchParams.get("feedback"));

  const [searchDraft, setSearchDraft] = useState("");
  const [search, setSearch] = useState("");
  // Débounce : on ne requête qu'après une pause de frappe (la QoS + la liste voyagent ensemble).
  useEffect(() => {
    const timer = setTimeout(() => setSearch(searchDraft), 200);
    return () => clearTimeout(timer);
  }, [searchDraft]);

  const feedback = useAdminFeedback("all" === filter ? undefined : filter, search);
  const items = feedback.data?.items ?? [];

  return (
    <section aria-labelledby="feedback-heading" className="space-y-6">
      <div>
        <p className="text-xs font-semibold uppercase tracking-[0.18em] text-console-muted">{ADMIN_SECTIONS_TEXTS.feedbackEyebrow}</p>
        <h2 id="feedback-heading" className="mt-2 text-xl font-semibold text-white">{ADMIN_SECTIONS_TEXTS.feedbackHeading}</h2>
      </div>

      {feedback.data ? <QosPanel qos={feedback.data.qos} /> : null}

      <section aria-label={ADMIN_SECTIONS_TEXTS.feedbackListLabel} className="space-y-4">
        <div className="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
          <div className="flex flex-wrap gap-2" role="group" aria-label={ADMIN_SECTIONS_TEXTS.feedbackFilterGroupLabel}>
            {FILTERS.map((f) => (
              <Button
                key={f.id}
                type="button"
                size="sm"
                variant={filter === f.id ? "default" : "outline"}
                className={cn(filter === f.id && "bg-console-accent text-console-surface hover:bg-console-accent-hover")}
                aria-pressed={filter === f.id}
                onClick={() => setFilter(f.id)}
              >
                {f.label}
              </Button>
            ))}
          </div>
          <div className="relative w-full md:max-w-xs">
            <Search className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-console-muted" aria-hidden="true" />
            <input
              type="text"
              value={searchDraft}
              maxLength={100}
              aria-label={ADMIN_SECTIONS_TEXTS.feedbackSearchLabel}
              placeholder={ADMIN_SECTIONS_TEXTS.feedbackSearchPlaceholder}
              onChange={(event) => setSearchDraft(event.target.value)}
              className="h-9 w-full rounded-md border border-white/15 bg-white/[0.04] pl-9 pr-3 text-sm text-white outline-none placeholder:text-console-text-faint focus:border-console-accent/70 focus:ring-2 focus:ring-console-accent/20"
            />
          </div>
        </div>

        {feedback.isPending ? (
          <p className="flex items-center gap-2 text-sm text-console-text-dim"><Spinner className="size-4" /> {ADMIN_SECTIONS_TEXTS.loading}</p>
        ) : feedback.isError ? (
          <p className="text-sm text-console-destructive">
            {ADMIN_SECTIONS_TEXTS.listReadError}{" "}
            <button type="button" className="underline" onClick={() => void feedback.refetch()}>{ADMIN_SECTIONS_TEXTS.retry}</button>
          </p>
        ) : 0 === items.length ? (
          <EmptyHint variant="console">{ADMIN_SECTIONS_TEXTS.feedbackEmpty}</EmptyHint>
        ) : (
          <ul className="divide-y divide-white/10 rounded-xl border border-white/10 bg-white/[0.03]">
            {items.map((item) => (
              <li key={item.id}>
                <button
                  type="button"
                  aria-label={ADMIN_SECTIONS_TEXTS.feedbackViewSignalement(item.clubName ?? ADMIN_SECTIONS_TEXTS.feedbackClubUnknownLower)}
                  onClick={() => setOpenId(item.id)}
                  className="flex w-full flex-col gap-1 p-4 text-left transition-colors hover:bg-white/[0.04] md:flex-row md:items-center md:gap-4"
                >
                  <span className="shrink-0 text-xs text-console-muted md:w-40">{fmtDate(item.createdAt)}</span>
                  <span className="shrink-0 font-medium text-white md:w-48 md:truncate">{item.clubName ?? ADMIN_SECTIONS_TEXTS.feedbackClubUnknown}</span>
                  <span className="shrink-0 rounded-full bg-white/10 px-2 py-0.5 text-xs text-console-text-bright md:w-40 md:text-center">{ADMIN_SECTIONS_TEXTS.feedbackTopicLabel(item.topic)}</span>
                  <span className="min-w-0 flex-1 truncate text-sm text-console-text">{item.message}</span>
                  <span className={cn("shrink-0 text-xs font-medium", "untreated" === item.status ? "text-console-warning" : "text-console-success")}>{ADMIN_SECTIONS_TEXTS.feedbackStatusLabel(item.status)}</span>
                </button>
              </li>
            ))}
          </ul>
        )}
      </section>

      {null !== openId ? (
        <FeedbackDetailDialog id={openId} fallback={items.find((i) => i.id === openId) ?? null} onClose={() => setOpenId(null)} />
      ) : null}
    </section>
  );
}

function QosPanel({ qos }: { qos: AdminFeedbackQos }) {
  return (
    <article aria-labelledby="qos-heading" className="rounded-xl border border-white/10 bg-white/[0.04] p-5">
      <h3 id="qos-heading" className="text-sm font-semibold text-white">{ADMIN_SECTIONS_TEXTS.feedbackQos}</h3>
      <dl className="mt-4 grid grid-cols-2 gap-4 sm:grid-cols-4">
        <SmallMetric label={ADMIN_SECTIONS_TEXTS.feedbackTreatedShare} value={fmtPct(qos.treatedShare)} />
        <SmallMetric label={ADMIN_SECTIONS_TEXTS.feedbackOldestUntreated} value={fmtHours(qos.oldestUntreatedAgeHours)} tone={null !== qos.oldestUntreatedAgeHours && qos.oldestUntreatedAgeHours > 72 ? "danger" : undefined} />
      </dl>

      <div className="mt-5 grid gap-5 lg:grid-cols-2">
        <div>
          <p className="text-xs font-semibold uppercase tracking-wide text-console-muted">{ADMIN_SECTIONS_TEXTS.feedbackDelayByMonth}</p>
          {0 === qos.treatDelayByMonth.length ? (
            <p className="mt-2 text-xs text-console-muted">—</p>
          ) : (
            <table className="mt-2 w-full text-left text-xs text-console-text">
              <thead className="text-console-muted">
                <tr>
                  <th className="py-1 font-medium">{ADMIN_SECTIONS_TEXTS.feedbackColMonth}</th>
                  <th className="py-1 font-medium">{ADMIN_SECTIONS_TEXTS.feedbackColAvg}</th>
                  <th className="py-1 font-medium">{ADMIN_SECTIONS_TEXTS.feedbackColP95}</th>
                </tr>
              </thead>
              <tbody>
                {qos.treatDelayByMonth.map((row) => (
                  <tr key={row.month}>
                    <td className="py-1 tabular-nums">{row.month}</td>
                    <td className="py-1 tabular-nums">{fmtHours(row.avgHours)}</td>
                    <td className="py-1 tabular-nums">{fmtHours(row.p95Hours)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}
        </div>

        <div>
          <p className="text-xs font-semibold uppercase tracking-wide text-console-muted">{ADMIN_SECTIONS_TEXTS.feedbackVolumeByTopic}</p>
          {0 === qos.volumeByTopicMonth.length ? (
            <p className="mt-2 text-xs text-console-muted">—</p>
          ) : (
            <table className="mt-2 w-full text-left text-xs text-console-text">
              <thead className="text-console-muted">
                <tr>
                  <th className="py-1 font-medium">{ADMIN_SECTIONS_TEXTS.feedbackColMonth}</th>
                  <th className="py-1 font-medium">{ADMIN_SECTIONS_TEXTS.feedbackColType}</th>
                  <th className="py-1 font-medium">{ADMIN_SECTIONS_TEXTS.feedbackColVolume}</th>
                </tr>
              </thead>
              <tbody>
                {qos.volumeByTopicMonth.map((row) => (
                  <tr key={`${row.month}-${row.topic}`}>
                    <td className="py-1 tabular-nums">{row.month}</td>
                    <td className="py-1">{ADMIN_SECTIONS_TEXTS.feedbackTopicLabel(row.topic)}</td>
                    <td className="py-1 tabular-nums">{row.count}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}
        </div>
      </div>
    </article>
  );
}

function SmallMetric({ label, value, tone }: { label: string; value: string; tone?: "danger" }) {
  return (
    <div>
      <dt className="text-xs text-console-muted">{label}</dt>
      <dd className={cn("mt-1 text-lg font-semibold tabular-nums text-white", "danger" === tone && "text-console-warning")}>{value}</dd>
    </div>
  );
}

function FeedbackDetailDialog({ id, fallback, onClose }: { id: string; fallback: AdminFeedbackItem | null; onClose: () => void }) {
  const detail = useAdminFeedbackDetail(id);
  const treat = useTreatAdminFeedback();
  const untreat = useUntreatAdminFeedback();
  const [confirming, setConfirming] = useState(false);
  const [actionError, setActionError] = useState<string | null>(null);
  const [copied, setCopied] = useState(false);
  const [zoomed, setZoomed] = useState(false);
  const screenshotUrl = `/api/admin/feedback/${encodeURIComponent(id)}/screenshot`;

  // Métadonnées : le détail serveur dès qu'il arrive, sinon la ligne de liste (deep-link : null).
  const data = detail.data ?? fallback;
  const message = data?.message ?? "";
  const context = detail.data?.context ?? null;
  const status: AdminFeedbackStatus = data?.status ?? "untreated";
  const pending = treat.isPending || untreat.isPending;

  const runVerdict = () => {
    setActionError(null);
    const mutation = "untreated" === status ? treat : untreat;
    mutation.mutate(id, {
      onSuccess: () => {
        setConfirming(false);
        onClose();
      },
      onError: (err) => void errorMessage(err).then(setActionError),
    });
  };

  const copyJson = () => {
    if (null === context) return;
    const json = JSON.stringify(context, null, 2);
    void navigator.clipboard?.writeText(json).then(
      () => setCopied(true),
      () => setCopied(false),
    );
  };

  return (
    <Modal
      label={ADMIN_SECTIONS_TEXTS.feedbackModalLabel}
      title={ADMIN_SECTIONS_TEXTS.feedbackModalTitle}
      onClose={onClose}
      size="xl"
      footer={
        confirming ? (
          <>
            <Button type="button" size="sm" variant="ghost" onClick={() => setConfirming(false)}>{ADMIN_SECTIONS_TEXTS.feedbackCancel}</Button>
            <Button type="button" size="sm" disabled={pending} onClick={runVerdict}>
              {pending ? <Spinner className="size-4" /> : null}
              {"untreated" === status ? ADMIN_SECTIONS_TEXTS.feedbackConfirmTreat : ADMIN_SECTIONS_TEXTS.feedbackConfirmReopen}
            </Button>
          </>
        ) : (
          <Button type="button" size="sm" variant="outline" onClick={() => setConfirming(true)}>
            {"untreated" === status ? ADMIN_SECTIONS_TEXTS.feedbackTreat : ADMIN_SECTIONS_TEXTS.feedbackReopen}
          </Button>
        )
      }
    >
      <div className="mt-4 space-y-5 text-sm">
        <dl className="grid grid-cols-2 gap-x-4 gap-y-2 text-xs">
          <MetaRow label={ADMIN_SECTIONS_TEXTS.feedbackMetaClub} value={data?.clubName ?? ADMIN_SECTIONS_TEXTS.feedbackClubUnknown} />
          {data ? <MetaRow label={ADMIN_SECTIONS_TEXTS.feedbackMetaType} value={ADMIN_SECTIONS_TEXTS.feedbackTopicLabel(data.topic)} /> : null}
          {data ? <MetaRow label={ADMIN_SECTIONS_TEXTS.feedbackMetaReceivedAt} value={fmtDate(data.createdAt)} /> : null}
          <MetaRow label={ADMIN_SECTIONS_TEXTS.feedbackMetaStatus} value={ADMIN_SECTIONS_TEXTS.feedbackStatusLabel(status)} />
        </dl>

        <div>
          <p className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">{ADMIN_SECTIONS_TEXTS.feedbackMessage}</p>
          {/* Rétention (PR4) : texte purgé → placeholder, jamais un cadre vide. Sinon TEXTE PUR
              (nœud texte React échappé, retours à la ligne préservés). */}
          {data?.purgedAt ? (
            <p className="mt-1 rounded-md border border-border bg-muted/40 p-3 italic text-muted-foreground">{ADMIN_SECTIONS_TEXTS.feedbackPurgedPlaceholder}</p>
          ) : (
            <p className="mt-1 whitespace-pre-line rounded-md border border-border bg-muted/40 p-3">{message}</p>
          )}
        </div>

        {data?.hasScreenshot ? (
          <div>
            <p className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">{ADMIN_SECTIONS_TEXTS.feedbackScreenshot}</p>
            {/* Aperçu chargé depuis le rail superadmin (session cookie) — seule porte du blob. */}
            <button
              type="button"
              aria-label={ADMIN_SECTIONS_TEXTS.feedbackScreenshotEnlarge}
              onClick={() => setZoomed(true)}
              className="mt-1 block w-full max-w-md cursor-zoom-in overflow-hidden rounded-md border border-border outline-none hover:border-ring focus-visible:ring-2 focus-visible:ring-ring"
            >
              <img src={screenshotUrl} alt={ADMIN_SECTIONS_TEXTS.feedbackScreenshotAlt} loading="lazy" className="max-h-96 w-full object-contain" />
            </button>
            {zoomed ? (
              <Modal label={ADMIN_SECTIONS_TEXTS.feedbackScreenshot} title={ADMIN_SECTIONS_TEXTS.feedbackScreenshot} onClose={() => setZoomed(false)} size="xl">
                {/* Borne = l'écran moins le cadre de la modale (marges, en-tête) : l'image entière se voit sans défiler. */}
                <img src={screenshotUrl} alt={ADMIN_SECTIONS_TEXTS.feedbackScreenshotAlt} className="max-h-[calc(100dvh-8rem)] w-full object-contain" />
              </Modal>
            ) : null}
          </div>
        ) : null}

        {detail.isPending ? (
          <p className="flex items-center gap-2 text-xs text-muted-foreground"><Spinner className="size-4" /> {ADMIN_SECTIONS_TEXTS.feedbackLoadingContext}</p>
        ) : detail.isError ? (
          <p className="text-xs text-destructive">{ADMIN_SECTIONS_TEXTS.feedbackContextError}</p>
        ) : null}

        {null !== context ? <ContextBlocks context={context} onCopy={copyJson} copied={copied} /> : null}

        {null !== actionError ? (
          <p role="alert" className="text-sm text-destructive">{actionError}</p>
        ) : null}

      </div>
    </Modal>
  );
}

/** Le contexte LARGE en 5 blocs titrés — le métier lisible d'abord, le technique après. */
function ContextBlocks({ context, onCopy, copied }: { context: AdminFeedbackContext; onCopy: () => void; copied: boolean }) {
  const auto = context.auto;
  const T = ADMIN_SECTIONS_TEXTS;

  // Snapshot ÉNORME : seules ses métadonnées (jamais son JSON dans le DOM).
  const snapshotBytes = undefined === context.snapshot ? 0 : JSON.stringify(context.snapshot).length;
  const diagnosticsCount = Array.isArray(context.diagnostics) ? context.diagnostics.length : 0;
  const hasHeavy = snapshotBytes > 0 || diagnosticsCount > 0;

  const planningRows: Row[] = [];
  if (auto?.socle) {
    planningRows.push({ label: T.feedbackAutoSocle, value: T.feedbackSocleValue(auto.socle.inForce, null === auto.socle.validatedAt ? null : fmtDate(auto.socle.validatedAt)) });
  }
  if (auto?.versions && (auto.versions.displayed || auto.versions.pointed)) {
    planningRows.push({ label: T.feedbackAutoVersions, value: T.feedbackVersionsValue(auto.versions.displayed?.number ?? null, auto.versions.pointed?.number ?? null) });
  }
  // P4-355 (amendement) — la RAISON d'un échec de génération récent, dans le bloc Planning :
  // la nature + le type (lead) et le 1ᵉʳ message d'erreur, sans copier le JSON.
  const failure = auto?.generationFailure ?? null;

  // P4-355 — versions App (front déclaré) / API (serveur) + mention d'écart (cache du bundle).
  const appVersion = context.appVersion ?? null;
  const apiVersion = auto?.apiVersion ?? null;
  const versionMismatch = Boolean(appVersion && apiVersion && appVersion !== apiVersion);

  const screenLead = context.screenLabel ?? context.screen;
  const screenRows: Row[] = [];
  if (context.url) screenRows.push({ label: T.feedbackMetaUrl, value: context.url });
  if (context.activeFilters) screenRows.push({ label: T.feedbackAutoFilters, value: context.activeFilters });
  if (context.displayedRange) screenRows.push({ label: T.feedbackAutoRange, value: context.displayedRange });

  const lastActionRows: Row[] = [];
  if (context.lastClick) lastActionRows.push({ label: T.feedbackAutoLastClick, value: context.lastClick });
  if (undefined !== context.incidentStatus) {
    lastActionRows.push({ label: T.feedbackAutoIncident, value: T.feedbackIncidentValue(String(context.incidentStatus), context.incidentUrl ?? "", context.incidentCode ?? null) });
  }
  if (context.requestId) lastActionRows.push({ label: T.feedbackMetaIncidentRef, value: context.requestId });

  const technicalRows: Row[] = [];
  if (auto?.season) technicalRows.push({ label: T.feedbackMetaSeason, value: auto.season.name });
  if (auto?.offer) technicalRows.push({ label: T.feedbackAutoOffer, value: auto.offer.name });
  if (auto?.counters) technicalRows.push({ label: T.feedbackAutoCounters, value: T.feedbackCountersValue(auto.counters.teams, auto.counters.venues) });
  if (auto?.role) technicalRows.push({ label: T.feedbackAutoRole, value: auto.role });
  if (auto?.generation) technicalRows.push({ label: T.feedbackAutoGeneration, value: T.feedbackGenerationValue(auto.generation.running) });
  if (appVersion || apiVersion) technicalRows.push({ label: T.feedbackAutoVersion, value: T.feedbackVersionValue(appVersion, apiVersion) });
  if (auto?.simulatedToday) technicalRows.push({ label: T.feedbackAutoClock, value: auto.simulatedToday });
  if (context.scheduleStatus) technicalRows.push({ label: T.feedbackMetaScheduleStatus, value: context.scheduleStatus });
  if (context.userAgent) technicalRows.push({ label: T.feedbackMetaBrowser, value: context.userAgent });

  return (
    <div className="space-y-4">
      <ContextSection title={T.feedbackBlockPlanning} rows={planningRows}>
        {null !== failure ? (
          <div className="space-y-0.5">
            <p className="text-xs font-semibold text-foreground">{T.feedbackAutoGenerationFailure}</p>
            <p className="break-words text-xs font-medium text-foreground">{T.feedbackFailureLead(T.feedbackFailureKindLabel(failure.kind), failure.type, failure.version.number)}</p>
            {"" !== failure.firstError ? <p className="break-words text-xs text-muted-foreground">{failure.firstError}</p> : null}
            <p className="text-xs text-muted-foreground">{fmtDate(failure.failedAt)}</p>
          </div>
        ) : null}
      </ContextSection>
      {auto?.period ? (
        <ContextSection title={T.feedbackBlockPeriod} rows={[]}>
          <p className="break-words text-xs font-medium">{T.feedbackPeriodValue(auto.period.name, auto.period.startDate, auto.period.endDate, Math.round(auto.period.progress * 100))}</p>
        </ContextSection>
      ) : null}
      <ContextSection title={T.feedbackBlockScreen} rows={screenRows}>
        {screenLead ? <p className="break-words text-xs font-medium">{screenLead}</p> : null}
      </ContextSection>
      <ContextSection title={T.feedbackBlockLastAction} rows={lastActionRows} />
      {/* D15 — l'état COMPLET de la modale ouvrante, dans un bloc REPLIABLE (il peut peser lourd) ;
          mention « tronqué » quand le serveur a coupé au plafond. */}
      {"string" === typeof context.modalState && "" !== context.modalState ? (
        <div className="space-y-2">
          <details className="text-xs">
            <summary className="cursor-pointer text-xs font-semibold uppercase tracking-wide text-muted-foreground">
              {T.feedbackBlockModalState}
              {true === context.modalStateTruncated ? <span className="ml-2 font-normal normal-case text-console-warning">{T.feedbackModalStateTruncated}</span> : null}
            </summary>
            <pre className="mt-2 max-h-72 overflow-auto whitespace-pre-wrap break-words rounded border border-border bg-muted px-2 py-1 text-foreground">{context.modalState}</pre>
          </details>
        </div>
      ) : null}
      <ContextSection title={T.feedbackBlockTechnical} rows={technicalRows}>
        {versionMismatch ? <p className="break-words text-xs text-muted-foreground">{T.feedbackVersionMismatch}</p> : null}
        {hasHeavy ? (
          <div className="flex flex-wrap items-center gap-3 text-xs text-muted-foreground">
            <span>{T.feedbackSnapshotMeta(snapshotBytes.toLocaleString("fr-FR"), diagnosticsCount)}</span>
            <Button type="button" size="sm" variant="ghost" className="h-7 px-2" onClick={onCopy}>
              {copied ? T.feedbackCopied : T.feedbackCopyJson}
            </Button>
          </div>
        ) : null}
      </ContextSection>
    </div>
  );
}

interface Row {
  label: string;
  value: string;
}

function ContextSection({ title, rows, children }: { title: string; rows: Row[]; children?: ReactNode }) {
  // Un bloc muet (aucune ligne, aucun enfant) ne s'affiche pas — console lisible.
  if (0 === rows.length && !children) {
    return null;
  }
  return (
    <div className="space-y-2">
      <p className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">{title}</p>
      {children}
      {rows.length > 0 ? (
        <dl className="grid grid-cols-2 gap-x-4 gap-y-1 text-xs">
          {rows.map((row) => (
            <MetaRow key={row.label} label={row.label} value={row.value} />
          ))}
        </dl>
      ) : null}
    </div>
  );
}

function MetaRow({ label, value }: { label: string; value: string }) {
  return (
    <div className="min-w-0">
      <dt className="text-muted-foreground">{label}</dt>
      <dd className="break-words font-medium">{value}</dd>
    </div>
  );
}
