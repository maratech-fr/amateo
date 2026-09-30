import { type ReactNode, useState } from "react";

import { Button } from "@/shared/components/ui/button";
import { ConfirmDialog } from "@/shared/components/ui/confirm-dialog";
import { Spinner } from "@/shared/components/ui/spinner";
import { toast } from "@/shared/stores/toastStore";

import type { AdminDemoAccount, AdminDemoTarget } from "../api";
import { useActivateAdminDemo, useAdminDemos, useDeactivateAdminDemo, useResetAdminDemoBccl, useSetAdminDemoClock } from "../queries";

// Fenêtre d'activation rendue à l'heure de PARIS (décision fondateur) : l'API renvoie l'ISO UTC.
const parisTime = new Intl.DateTimeFormat("fr-FR", { timeZone: "Europe/Paris", hour: "2-digit", minute: "2-digit" });

function isWindowOpen(activeUntil: string | null): boolean {
  return null !== activeUntil && Date.parse(activeUntil) > Date.now();
}

/**
 * Console démo (PR B du lot Démos) : pilotage des deux comptes de démonstration.
 * « Démo BCCL » (compte permanent) porte en plus la réinitialisation et l'horloge simulée ;
 * « Démo prospect » n'a que la fenêtre d'activation. Aucun bouton de prolongation, aucune
 * activation programmée, aucune création par code FFBB ici (PR C).
 */
export function DemosSection() {
  const demos = useAdminDemos();

  if (demos.isPending) {
    return (
      <div className="flex min-h-40 items-center justify-center rounded-xl border border-white/10 bg-white/[0.03]" role="status">
        <Spinner className="text-console-accent" />
        <span className="sr-only">Chargement des comptes de démonstration</span>
      </div>
    );
  }

  if (demos.isError || !demos.data) {
    return (
      <div className="flex flex-col items-start gap-4 rounded-xl border border-console-warning/20 bg-console-warning/[0.05] p-5" role="alert">
        <p className="text-sm text-console-warning-bright">Les comptes de démonstration sont indisponibles.</p>
        <Button type="button" size="sm" variant="outline" className="border-console-warning/20 text-console-warning-bright hover:bg-console-warning/10" onClick={() => void demos.refetch()}>
          Réessayer
        </Button>
      </div>
    );
  }

  return (
    <section aria-labelledby="demos-heading" className="space-y-4">
      <div>
        <p className="text-xs font-semibold uppercase tracking-[0.18em] text-console-muted">Démonstrations</p>
        <h2 id="demos-heading" className="mt-2 text-xl font-semibold text-white">Comptes de démonstration</h2>
      </div>
      <div className="grid gap-4 lg:grid-cols-2">
        <BcclCard account={demos.data.bccl} />
        <ProspectCard account={demos.data.prospect} />
      </div>
    </section>
  );
}

function BcclCard({ account }: { account: AdminDemoAccount }) {
  const reset = useResetAdminDemoBccl();
  const clock = useSetAdminDemoClock();
  const [confirmingReset, setConfirmingReset] = useState(false);
  const serverDate = account.demoToday ?? "";
  const [dateDraft, setDateDraft] = useState(serverDate);
  // Le champ suit la valeur serveur (après application / reset) : ajustement d'état pendant
  // le rendu au changement de prop, sans effet ni cascade (même patron que ConfirmDialog).
  const [lastServerDate, setLastServerDate] = useState(serverDate);
  if (lastServerDate !== serverDate) {
    setLastServerDate(serverDate);
    setDateDraft(serverDate);
  }

  const applyDate = () => {
    if ("" === dateDraft) return;
    clock.mutate(
      { date: dateDraft },
      {
        onSuccess: () => toast.success("Date simulée appliquée."),
        onError: () => toast.error("Impossible d’appliquer la date simulée."),
      },
    );
  };

  const clearDate = () => {
    clock.mutate(
      { clear: true },
      {
        onSuccess: () => toast.success("La démo est revenue à aujourd’hui."),
        onError: () => toast.error("Impossible de revenir à aujourd’hui."),
      },
    );
  };

  const runReset = () => {
    setConfirmingReset(false);
    reset.mutate(undefined, {
      onSuccess: () => toast.success("La démo BCCL a été réinitialisée."),
      onError: () => toast.error("La réinitialisation de la démo a échoué."),
    });
  };

  return (
    <DemoCard title="Démo BCCL" account={account} target="bccl">
      <div className="mt-5 space-y-4 border-t border-white/10 pt-5">
        <div>
          <p className="text-sm font-medium text-white">Date simulée</p>
          <p className="mt-1 text-xs text-console-muted">
            {account.demoToday ? `La démo vit au ${account.demoToday}.` : "La démo vit à la date du jour."}
          </p>
          <div className="mt-3 flex flex-wrap items-center gap-2">
            <label className="sr-only" htmlFor="demo-bccl-date">Date simulée de la démo</label>
            <input
              id="demo-bccl-date"
              type="date"
              value={dateDraft}
              onChange={(event) => setDateDraft(event.target.value)}
              className="h-10 rounded-md border border-white/15 bg-white/[0.04] px-3 text-sm text-white outline-none focus:border-console-accent/70 focus:ring-2 focus:ring-console-accent/20"
            />
            <Button type="button" size="sm" className="bg-console-accent text-console-surface hover:bg-console-accent-hover" disabled={"" === dateDraft || clock.isPending} onClick={applyDate}>
              Appliquer
            </Button>
            <Button type="button" size="sm" variant="outline" className="border-white/15 text-console-text-bright hover:bg-white/10" disabled={null === (account.demoToday ?? null) || clock.isPending} onClick={clearDate}>
              Revenir à aujourd’hui
            </Button>
          </div>
        </div>

        <div>
          <Button
            type="button"
            size="sm"
            variant="outline"
            className="border-console-destructive-edge/40 text-console-destructive hover:bg-console-destructive-surface/10"
            disabled={reset.isPending}
            onClick={() => setConfirmingReset(true)}
          >
            {reset.isPending ? <Spinner className="size-3.5" /> : null}
            Réinitialiser
          </Button>
        </div>
      </div>

      <ConfirmDialog
        open={confirmingReset}
        title="Réinitialiser la démo BCCL ?"
        description="Tout ce qui a été modifié dans la démo sera effacé et la date simulée revient à aujourd’hui."
        confirmLabel="Réinitialiser"
        destructive
        confirmDisabled={reset.isPending}
        onConfirm={runReset}
        onCancel={() => setConfirmingReset(false)}
      />
    </DemoCard>
  );
}

function ProspectCard({ account }: { account: AdminDemoAccount }) {
  return <DemoCard title="Démo prospect" account={account} target="prospect" />;
}

function DemoCard({ title, account, target, children }: { title: string; account: AdminDemoAccount; target: AdminDemoTarget; children?: ReactNode }) {
  const activate = useActivateAdminDemo();
  const deactivate = useDeactivateAdminDemo();
  const open = isWindowOpen(account.activeUntil);

  const doActivate = () => {
    activate.mutate(target, {
      onSuccess: () => toast.success("Compte de démonstration activé pour 4 h."),
      onError: () => toast.error("Impossible d’activer le compte de démonstration."),
    });
  };

  const doDeactivate = () => {
    deactivate.mutate(target, {
      onSuccess: () => toast.success("Compte de démonstration désactivé."),
      onError: () => toast.error("Impossible de désactiver le compte de démonstration."),
    });
  };

  const busy = activate.isPending || deactivate.isPending;

  return (
    <article className="rounded-xl border border-white/10 bg-white/[0.04] p-5">
      <div className="flex items-start justify-between gap-3">
        <div>
          <p className="text-sm font-medium text-white">{title}</p>
          <p className="mt-1 text-xs text-console-text-faint">{account.email}</p>
          {account.clubName ? <p className="mt-1 text-xs text-console-text-dim">{account.clubName}</p> : null}
        </div>
        <span className={open ? "shrink-0 text-xs font-semibold text-console-success" : "shrink-0 text-xs font-semibold text-console-text-dim"}>
          {open ? `Active jusqu’à ${parisTime.format(new Date(account.activeUntil as string))}` : "Inactive"}
        </span>
      </div>

      <div className="mt-4 flex flex-wrap gap-2">
        <Button type="button" size="sm" className="bg-console-accent text-console-surface hover:bg-console-accent-hover" disabled={busy} onClick={doActivate}>
          {activate.isPending ? <Spinner className="size-3.5" /> : null}
          {open ? "Réactiver 4 h" : "Activer 4 h"}
        </Button>
        <Button type="button" size="sm" variant="outline" className="border-white/15 text-console-text-bright hover:bg-white/10" disabled={busy || !open} onClick={doDeactivate}>
          Désactiver
        </Button>
      </div>

      {children}
    </article>
  );
}
