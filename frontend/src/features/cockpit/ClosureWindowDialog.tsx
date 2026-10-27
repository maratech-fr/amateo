import { CalendarCheck, CalendarPlus, Sun, Trash2 } from "lucide-react";
import { type ReactNode, useId, useRef, useState } from "react";

import { StatusPill } from "@/shared/components/ui/badge";
import { Button } from "@/shared/components/ui/button";
import { Input } from "@/shared/components/ui/input";
import { Modal } from "@/shared/components/ui/modal";
import { NoticeBanner } from "@/shared/components/ui/notice-banner";
import { Spinner } from "@/shared/components/ui/spinner";
import { useTodayISO } from "@/shared/lib/clock";
import { cn } from "@/shared/lib/utils";
import { useWorkingSeason } from "@/shared/session/queries";
import { toast } from "@/shared/stores/toastStore";

import { type ClosureWindowEffect, type ClosureWindowEffectKind, PreviewTokenStaleError, WindowAlreadyPlannedError } from "./api";
import { frDateShort } from "./lib/date";
import { useApplyClosureWindow, useClosureWindowPreview } from "./queries";
import { COCKPIT_TEXTS } from "./texts";
import { WindowAlreadyPlannedNotice } from "./WindowAlreadyPlannedNotice";

/**
 * PR2 « Modifier les dates d'une fermeture » (axe planning lifecycle). Le dialogue ANNONCE avant
 * d'appliquer : « Voir les effets » charge un APERÇU servi (dates, pastille par kind, label TEL QUEL
 * — règle d'or : le front n'invente aucune règle métier), « Confirmer » n'applique QUE cet aperçu-là
 * (jeton servi). Une retouche de date, ou une réponse arrivée en retard pour d'anciennes dates,
 * PÉRIME l'aperçu. Les deux 409 serveur ont leur rendu (jeton périmé → bandeau + re-aperçu, confirmation
 * MANUELLE ; chevauchement → `WindowAlreadyPlannedNotice` → le parent ferme les deux modales).
 */

/** Présentation par kind (icône + variante de pastille) — pur affichage, le `label` servi porte le sens. */
const EFFECT_PRESENTATION: Record<ClosureWindowEffectKind, { icon: ReactNode; variant: "accent" | "warning" | "neutral" }> = {
  convert: { icon: <CalendarCheck className="size-3.5 text-accent" />, variant: "accent" },
  birth_copy: { icon: <CalendarPlus className="size-3.5 text-accent" />, variant: "accent" },
  shift: { icon: <CalendarPlus className="size-3.5 text-accent" />, variant: "accent" },
  holiday_pause: { icon: <Sun className="size-3.5 text-muted-foreground" />, variant: "neutral" },
  vanish: { icon: <Trash2 className="size-3.5 text-warning" />, variant: "warning" },
  regenerate: { icon: <CalendarCheck className="size-3.5 text-warning" />, variant: "warning" },
};
const NEUTRAL_PRESENTATION = { icon: <CalendarCheck className="size-3.5 text-muted-foreground" />, variant: "neutral" } as const;

interface ClosureWindowDialogProps {
  plan: { id: string; startDate: string; endDate: string; minEndDate: string; closureTitle: string };
  onClose: () => void;
  onApplied: () => void;
  onOpenConflict: (entryId: string) => void;
  /** Ouvert depuis « Tous les plannings » : le toast de succès reste court (le parent rafraîchit la liste). */
  fromList?: boolean;
}

export function ClosureWindowDialog({ plan, onClose, onApplied, onOpenConflict, fromList = false }: ClosureWindowDialogProps) {
  const today = useTodayISO();
  const workingSeason = useWorkingSeason();
  const preview = useClosureWindowPreview();
  const apply = useApplyClosureWindow();

  // « Le passé ne bouge pas » : une fermeture DÉJÀ COMMENCÉE a son début FIGÉ (readOnly). Sinon le
  // début est éditable mais jamais avant aujourd'hui (plancher de PRÉSENTATION ; le 422 serveur juge).
  const started = plan.startDate < today;
  const [startDate, setStartDate] = useState(plan.startDate);
  const [endDate, setEndDate] = useState(plan.endDate);

  // L'aperçu VALIDE chargé (pour les dates courantes) ; null = pas d'aperçu → « Confirmer » verrouillé.
  const [loaded, setLoaded] = useState<{ token: string; summary: string; futurePlansWarning: boolean; effects: ClosureWindowEffect[] } | null>(null);
  const [previewError, setPreviewError] = useState<string | null>(null);
  const [staleWarning, setStaleWarning] = useState<string | null>(null);
  const [windowConflict, setWindowConflict] = useState<{ message: string; entryId: string } | null>(null);
  // Jeton de requête : toute retouche de date (ou nouvelle requête) l'incrémente — une réponse dont le
  // jeton n'est plus celui en cours est IGNORÉE (course : réponse en retard pour d'anciennes dates).
  const reqIdRef = useRef(0);

  const hintId = useId();
  const sectionTitleId = useId();

  const invalidatePreview = () => {
    reqIdRef.current += 1;
    setLoaded(null);
    setPreviewError(null);
    setStaleWarning(null);
    setWindowConflict(null);
  };
  const changeStart = (value: string) => {
    setStartDate(value);
    setEndDate((prev) => (prev < value ? value : prev));
    invalidatePreview();
  };
  const changeEnd = (value: string) => {
    setEndDate(value);
    invalidatePreview();
  };

  const changed = startDate !== plan.startDate || endDate !== plan.endDate;
  const valid = endDate >= startDate;
  const busy = preview.isPending || apply.isPending;
  const previewValid = null !== loaded;
  const deletionAhead = previewValid && loaded.effects.some((e) => "vanish" === e.kind);

  const loadPreview = async () => {
    if (!changed || !valid || busy) {
      return;
    }
    const myId = ++reqIdRef.current;
    setPreviewError(null);
    setStaleWarning(null);
    setWindowConflict(null);
    try {
      const result = await preview.mutateAsync({ planId: plan.id, startDate, endDate });
      if (myId !== reqIdRef.current) {
        return; // dates changées depuis la requête → réponse caduque, ignorée.
      }
      setLoaded(result);
    } catch (error) {
      const message = error instanceof Error ? error.message : "Une erreur est survenue.";
      if (myId !== reqIdRef.current) {
        return;
      }
      setLoaded(null);
      setPreviewError(message);
    }
  };

  const confirm = async () => {
    if (null === loaded) {
      return;
    }
    const token = loaded.token;
    setWindowConflict(null);
    try {
      await apply.mutateAsync({ planId: plan.id, startDate, endDate, previewToken: token });
      // Toast de succès posé ICI (le hook est muet sur le succès) — jamais dans un onError de mutate().
      toast.success(fromList ? COCKPIT_TEXTS.datesChangedToastFromList : COCKPIT_TEXTS.datesChangedToast);
      onApplied();
    } catch (error) {
      if (error instanceof WindowAlreadyPlannedError) {
        setWindowConflict({ message: error.message, entryId: error.conflictingEntryId });
        return;
      }
      if (error instanceof PreviewTokenStaleError) {
        // La fermeture a bougé depuis l'aperçu : on l'annonce et on REDEMANDE l'aperçu (mêmes dates) ;
        // la confirmation reste MANUELLE (le gestionnaire reclique « Confirmer »).
        setStaleWarning(error.message);
        setLoaded(null);
        const myId = ++reqIdRef.current;
        try {
          const fresh = await preview.mutateAsync({ planId: plan.id, startDate, endDate });
          if (myId !== reqIdRef.current) {
            return;
          }
          setLoaded(fresh);
        } catch (refetchError) {
          const message = refetchError instanceof Error ? refetchError.message : "Une erreur est survenue.";
          if (myId !== reqIdRef.current) {
            return;
          }
          setPreviewError(message);
        }
        return;
      }
      // Transport/erreur inattendue : le hook (onError) a déjà toasté — ne pas doubler.
    }
  };

  return (
    <Modal
      label={COCKPIT_TEXTS.closureWindowTitle(plan.closureTitle)}
      title={COCKPIT_TEXTS.closureWindowTitle(plan.closureTitle)}
      onClose={onClose}
      size="md"
      footer={
        <>
          <Button variant="outline" disabled={!changed || !valid || busy} disabledReason={busy ? COCKPIT_TEXTS.loadingEffects : undefined} onClick={() => void loadPreview()}>
            {busy ? <Spinner className="size-4" /> : null}
            {COCKPIT_TEXTS.seeEffects}
          </Button>
          <Button
            variant={deletionAhead ? "destructive" : "default"}
            disabled={!previewValid || busy}
            disabledReason={previewValid ? undefined : COCKPIT_TEXTS.confirmNeedsPreview}
            onClick={() => void confirm()}
          >
            {COCKPIT_TEXTS.confirm}
          </Button>
        </>
      }
    >
      <div className="space-y-3">
        <div className="grid grid-cols-2 gap-2">
          <label className="block text-xs text-muted-foreground">
            Du
            <Input
              type="date"
              className="mt-1"
              value={startDate}
              readOnly={started}
              min={started ? undefined : today}
              max={workingSeason?.endDate}
              aria-describedby={started ? hintId : undefined}
              onChange={(e) => changeStart(e.target.value)}
            />
          </label>
          <label className="block text-xs text-muted-foreground">
            Jusqu'au
            <Input type="date" className="mt-1" value={endDate} min={plan.minEndDate} max={workingSeason?.endDate} onChange={(e) => changeEnd(e.target.value)} />
          </label>
        </div>
        {started ? (
          <p id={hintId} className="text-xs text-muted-foreground">
            {COCKPIT_TEXTS.startFrozenHint}
          </p>
        ) : null}

        {null !== staleWarning ? <NoticeBanner tone="warning" role="status" message={staleWarning} /> : null}

        {/* Région live PRÉSENTE dès le montage (vide au départ) : le RÉSUMÉ servi SEUL, ou le refus 422.
            La liste d'effets vit dans sa propre section repère (annoncée une fois, pas re-lue ici). */}
        <div aria-live="polite" aria-busy={preview.isPending} className="space-y-1">
          {null !== previewError ? <p className="text-sm text-destructive">{previewError}</p> : null}
          {previewValid ? <p className="text-sm text-foreground">{loaded.summary}</p> : null}
        </div>

        {previewValid ? (
          <section aria-labelledby={sectionTitleId} className="space-y-2">
            <h3 id={sectionTitleId} className="text-sm font-medium text-foreground">
              {COCKPIT_TEXTS.effectsSectionTitle}
            </h3>
            <ul className="space-y-1.5">
              {loaded.effects.map((effect, index) => {
                const presentation = EFFECT_PRESENTATION[effect.kind] ?? NEUTRAL_PRESENTATION;
                const isPause = "holiday_pause" === effect.kind;
                return (
                  <li
                    key={index}
                    className={cn(
                      "flex items-start gap-2 rounded-md border px-2 py-1.5 text-sm",
                      isPause ? "border-dashed border-border" : "border-border",
                    )}
                  >
                    <StatusPill variant={presentation.variant} icon={presentation.icon} className="shrink-0">
                      <span className="tabular-nums">
                        {frDateShort(effect.startDate)} – {frDateShort(effect.endDate)}
                      </span>
                    </StatusPill>
                    <span className="min-w-0 text-foreground">{effect.label}</span>
                  </li>
                );
              })}
            </ul>
            {loaded.futurePlansWarning ? <NoticeBanner tone="muted" message={COCKPIT_TEXTS.futurePlansWarning} /> : null}
          </section>
        ) : null}

        {null !== windowConflict ? <WindowAlreadyPlannedNotice message={windowConflict.message} onOpen={() => onOpenConflict(windowConflict.entryId)} /> : null}
      </div>
    </Modal>
  );
}
