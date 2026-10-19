import { HTTPError } from "ky";
import { type FormEvent, useId, useState } from "react";

import { Button } from "@/shared/components/ui/button";
import { ConfirmDialog } from "@/shared/components/ui/confirm-dialog";
import { Spinner } from "@/shared/components/ui/spinner";
import { errorMessage } from "@/shared/lib/errorMessage";

import { useAdminDeployments, useAdminDeploymentTags, useCancelAdminDeployment, useScheduleAdminDeployment } from "../queries";
import { ADMIN_SECTIONS_TEXTS } from "../texts/sections";

const dateTimeFormatter = new Intl.DateTimeFormat("fr-FR", { dateStyle: "medium", timeStyle: "short" });

/**
 * Mise en prod programmée (onglet Exploitation) — choisir un tag déployable, une date/heure et
 * une durée de maintenance, puis « Programmer ». Une seule mise en production peut être active à
 * la fois (le backend 409 sinon) ; tant qu'elle est au stade « Programmée », on peut l'annuler —
 * une fois le train parti (dispatched), plus de retour en arrière depuis la console.
 */
export function DeploymentSection() {
  const deployments = useAdminDeployments();
  const tags = useAdminDeploymentTags();
  const schedule = useScheduleAdminDeployment();
  const cancel = useCancelAdminDeployment();

  const versionId = useId();
  const dateId = useId();
  const windowId = useId();

  const [version, setVersion] = useState("");
  const [scheduledAtLocal, setScheduledAtLocal] = useState("");
  const [windowMinutes, setWindowMinutes] = useState("30");
  const [scheduleError, setScheduleError] = useState<string | null>(null);
  const [confirmingCancel, setConfirmingCancel] = useState(false);

  const items = deployments.data?.items ?? [];
  const configured = deployments.data?.configured ?? false;
  const tagOptions = tags.data?.items ?? [];
  // GitHub injoignable : la liste des tags revient `available:false` (jamais une 500). On DIT
  // l'indisponibilité au lieu de proposer « Programmer » sur une liste vide et muette.
  const tagsAvailable = tags.data?.available ?? true;

  // Une seule mise en production vit à la fois : la première non terminale est « active ».
  const active = items.find((d) => "scheduled" === d.status || "dispatched" === d.status) ?? null;
  const history = items.filter((d) => "scheduled" !== d.status && "dispatched" !== d.status);

  const canSubmit = configured && tagsAvailable && "" !== version && "" !== scheduledAtLocal && "" !== windowMinutes && !schedule.isPending;

  // Tant que l'état (déploiement actif + configuration) ou les tags déployables chargent, on ne
  // montre NI le formulaire NI ses options : le `<select>` ne doit s'exposer qu'une fois peuplé
  // (sinon on proposerait « Programmer » sur une liste de versions vide et un état inconnu).
  if (deployments.isPending || tags.isPending) {
    return (
      <section aria-labelledby="deployment-heading" className="space-y-4">
        <h2 id="deployment-heading" className="text-xl font-semibold text-white">{ADMIN_SECTIONS_TEXTS.deploymentHeading}</h2>
        <p className="flex items-center gap-2 text-sm text-console-text-dim"><Spinner className="size-4" /> {ADMIN_SECTIONS_TEXTS.loading}</p>
      </section>
    );
  }

  function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (!canSubmit) return;
    setScheduleError(null);
    schedule.mutate(
      // L'offset est DÉRIVÉ du datetime-local (lu à l'heure locale, converti en instant UTC).
      { version, scheduledAt: new Date(scheduledAtLocal).toISOString(), windowMinutes: Number(windowMinutes) },
      {
        onSuccess: () => {
          setVersion("");
          setScheduledAtLocal("");
          setWindowMinutes("30");
        },
        // Message serveur PRÉCIS inline (422 version refusée, 409 déjà programmée) — le toast
        // générique reste au niveau HOOK (FRT-38). `setScheduleError` n'arme pas le garde.
        // SEC-08 : un 5xx ne doit PAS afficher son corps (errorMessage le tait → « Erreur serveur »
        // générique) ; pour le 502 « GitHub injoignable » de la programmation, on AUTEUR nous-mêmes
        // un message d'attente CLAIR côté client (constante, keyée sur le 5xx).
        onError: (err) => {
          if (err instanceof HTTPError && err.response.status >= 500) {
            setScheduleError(ADMIN_SECTIONS_TEXTS.deploymentGithubUnreachableSchedule);
            return;
          }
          void errorMessage(err).then(setScheduleError);
        },
      },
    );
  }

  return (
    <section aria-labelledby="deployment-heading" className="space-y-4">
      <div>
        <p className="text-xs font-semibold uppercase tracking-[0.18em] text-console-muted">{ADMIN_SECTIONS_TEXTS.deploymentEyebrow}</p>
        <h2 id="deployment-heading" className="mt-2 text-xl font-semibold text-white">{ADMIN_SECTIONS_TEXTS.deploymentHeading}</h2>
        <p className="mt-1 text-sm text-console-text-dim">
          {ADMIN_SECTIONS_TEXTS.deploymentDescription}
        </p>
      </div>

      {!configured && deployments.isSuccess ? (
        <p className="rounded-xl border border-console-destructive-edge/40 bg-console-destructive-surface/10 p-4 text-sm text-console-text">
          {ADMIN_SECTIONS_TEXTS.deploymentNotConfigured}
        </p>
      ) : null}

      {configured && !tagsAvailable ? (
        <div className="space-y-2 rounded-xl border border-console-destructive-edge/40 bg-console-destructive-surface/10 p-4 text-sm text-console-text">
          <p>{ADMIN_SECTIONS_TEXTS.deploymentGithubUnreachableTags}</p>
          <button type="button" className="underline" onClick={() => void tags.refetch()}>{ADMIN_SECTIONS_TEXTS.retry}</button>
        </div>
      ) : null}

      {active ? (
        <div className="space-y-2 rounded-xl border border-console-accent/30 bg-console-accent/5 p-4">
          <p className="text-xs uppercase tracking-wide text-console-muted">{ADMIN_SECTIONS_TEXTS.deploymentActiveLabel}</p>
          <div className="flex flex-wrap items-center gap-3">
            <span className="text-base font-semibold text-white">{active.version}</span>
            <span className="rounded bg-console-muted/20 px-1.5 py-0.5 text-xs font-semibold uppercase text-console-text">{ADMIN_SECTIONS_TEXTS.deploymentStatusLabel(active.status)}</span>
            {active.scheduledAt ? <span className="text-sm text-console-text-dim">{formatDate(active.scheduledAt)}</span> : null}
          </div>
          {active.dispatchNote ? <p className="text-sm text-console-text-dim">{active.dispatchNote}</p> : null}
          {"scheduled" === active.status ? (
            <div className="pt-1">
              <Button
                type="button"
                size="sm"
                variant="outline"
                className="border-console-destructive-edge/40 text-console-destructive hover:bg-console-destructive-surface/10"
                disabled={cancel.isPending}
                onClick={() => setConfirmingCancel(true)}
              >
                {ADMIN_SECTIONS_TEXTS.deploymentCancel}
              </Button>
            </div>
          ) : null}
        </div>
      ) : null}

      <form onSubmit={submit} className="space-y-3 rounded-xl border border-white/10 bg-white/5 p-4">
        <div className="grid gap-3 md:grid-cols-3">
          <div className="space-y-1">
            <label htmlFor={versionId} className="block text-xs text-console-text-dim">{ADMIN_SECTIONS_TEXTS.deploymentVersionLabel}</label>
            <select
              id={versionId}
              value={version}
              onChange={(event) => setVersion(event.target.value)}
              disabled={!configured}
              className="h-10 w-full rounded-md border border-white/15 bg-white/[0.04] px-3 text-sm text-white outline-none focus:border-console-accent/70 focus:ring-2 focus:ring-console-accent/20 disabled:opacity-50"
            >
              <option value="">{ADMIN_SECTIONS_TEXTS.deploymentVersionPlaceholder}</option>
              {tagOptions.map((tag) => (
                <option key={tag} value={tag}>{tag}</option>
              ))}
            </select>
          </div>
          <div className="space-y-1">
            <label htmlFor={dateId} className="block text-xs text-console-text-dim">{ADMIN_SECTIONS_TEXTS.deploymentDateLabel}</label>
            <input
              id={dateId}
              type="datetime-local"
              value={scheduledAtLocal}
              onChange={(event) => setScheduledAtLocal(event.target.value)}
              disabled={!configured}
              className="h-10 w-full rounded-md border border-white/15 bg-white/[0.04] px-3 text-sm text-white outline-none focus:border-console-accent/70 focus:ring-2 focus:ring-console-accent/20 disabled:opacity-50"
            />
          </div>
          <div className="space-y-1">
            <label htmlFor={windowId} className="block text-xs text-console-text-dim">{ADMIN_SECTIONS_TEXTS.deploymentWindowLabel}</label>
            <input
              id={windowId}
              type="number"
              min={1}
              max={240}
              value={windowMinutes}
              onChange={(event) => setWindowMinutes(event.target.value)}
              disabled={!configured}
              className="h-10 w-full rounded-md border border-white/15 bg-white/[0.04] px-3 text-sm text-white outline-none focus:border-console-accent/70 focus:ring-2 focus:ring-console-accent/20 disabled:opacity-50"
            />
          </div>
        </div>
        {null !== scheduleError ? <p role="alert" className="text-sm text-console-destructive">{scheduleError}</p> : null}
        <div className="flex justify-end">
          <Button type="submit" className="bg-console-accent text-console-surface hover:bg-console-accent-hover" disabled={!canSubmit}>
            {schedule.isPending ? <Spinner className="size-4" /> : null}
            {ADMIN_SECTIONS_TEXTS.deploymentSubmit}
          </Button>
        </div>
      </form>

      <div className="space-y-2">
        <h3 className="text-sm font-semibold text-white">{ADMIN_SECTIONS_TEXTS.deploymentHistoryHeading}</h3>
        {deployments.isPending ? (
          <p className="flex items-center gap-2 text-sm text-console-text-dim"><Spinner className="size-4" /> {ADMIN_SECTIONS_TEXTS.loading}</p>
        ) : deployments.isError ? (
          <p className="text-sm text-console-destructive">
            {ADMIN_SECTIONS_TEXTS.deploymentHistoryError}{" "}
            <button type="button" className="underline" onClick={() => void deployments.refetch()}>{ADMIN_SECTIONS_TEXTS.retry}</button>
          </p>
        ) : 0 === history.length ? (
          <p className="text-sm text-console-text-dim">{ADMIN_SECTIONS_TEXTS.deploymentHistoryEmpty}</p>
        ) : (
          <ul className="divide-y divide-white/10 rounded-xl border border-white/10 bg-white/[0.03]">
            {history.map((d) => (
              <li key={d.id} className="space-y-1 p-4">
                <div className="flex flex-wrap items-center gap-3">
                  <span className="text-sm font-medium text-white">{d.version}</span>
                  <span className="rounded bg-console-muted/20 px-1.5 py-0.5 text-xs font-semibold uppercase text-console-text">{ADMIN_SECTIONS_TEXTS.deploymentStatusLabel(d.status)}</span>
                  {d.dispatchedAt ? <span className="text-xs text-console-muted">{formatDate(d.dispatchedAt)}</span> : null}
                </div>
                {d.failureReason ? <p className="text-sm text-console-destructive">{d.failureReason}</p> : null}
                {d.dispatchNote ? <p className="text-sm text-console-text-dim">{d.dispatchNote}</p> : null}
              </li>
            ))}
          </ul>
        )}
      </div>

      <ConfirmDialog
        open={confirmingCancel}
        title={ADMIN_SECTIONS_TEXTS.deploymentCancelConfirmTitle}
        description={ADMIN_SECTIONS_TEXTS.deploymentCancelConfirmDescription}
        confirmLabel={ADMIN_SECTIONS_TEXTS.deploymentCancelConfirmLabel}
        cancelLabel={ADMIN_SECTIONS_TEXTS.deploymentCancelConfirmCancel}
        onConfirm={() => {
          if (active) cancel.mutate(active.id);
          setConfirmingCancel(false);
        }}
        onCancel={() => setConfirmingCancel(false)}
      />
    </section>
  );
}

function formatDate(value: string): string {
  const date = new Date(value);
  return Number.isNaN(date.getTime()) ? value : dateTimeFormatter.format(date);
}
