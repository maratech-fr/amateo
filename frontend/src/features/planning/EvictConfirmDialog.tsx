import { AlertTriangle } from "lucide-react";

import { Spinner } from "@/shared/components/ui/spinner";
import { useId, useRef } from "react";
import { createPortal } from "react-dom";

import { Button } from "@/shared/components/ui/button";
import { EmptyHint } from "@/shared/components/ui/empty-hint";
import { MODAL_WIDTH } from "@/shared/components/ui/modal-width";
import { NoticeBanner } from "@/shared/components/ui/notice-banner";
import { useModalA11y } from "@/shared/lib/useModalA11y";
import { cn } from "@/shared/lib/utils";

import { MOVE_VERDICT_TIMEOUT_SECONDS } from "./api";
import type { Compromise, MoveViolation } from "./api";
import { CompromiseList } from "./CompromiseList";
import { PLANNING_TEXTS } from "./texts";

/**
 * P2-32 (D6) — la modale qui s'interpose quand on déplace une équipe vers un créneau OCCUPÉ.
 * Elle remplace la confirmation statique : un ESSAI (dry-run) part pendant qu'elle s'ouvre, et
 * elle RESTITUE le verdict du moteur AVANT d'écrire quoi que ce soit. Quatre états :
 *  - `checking` : l'essai délibère (« Vérification… ») — aucun bouton de confirmation ;
 *  - `accepted` : le déplacement est légal — on nomme l'occupant, on liste les compromis (ou
 *    « Aucun compromis détecté. »), et [Déplacer et évincer] déclenche le move RÉEL ;
 *  - `refused`  : le moteur refuse — les motifs NOMMÉS, PAS de confirmation, [Fermer] seul ;
 *  - `failed`   : l'essai n'a PAS ABOUTI (moteur trop lent / indisponible) — DISTINCT d'un refus :
 *    rien n'est tranché, la modale RESTE ouverte, DIT ce qui s'est passé et propose [Réessayer].
 *    C'est la demande fondateur : ne jamais fermer en silence sur un échec de la vérification.
 *  - `indeterminate` (ENG-51) : le solveur n'a PAS tranché dans le temps imparti — ni accepté ni
 *    refusé. Bandeau NEUTRE (jamais un style conflit : aucune règle n'est en cause), rien n'est
 *    écrit, [Réessayer].
 *
 * Markup calqué sur `ConfirmDialog` (portail + overlay + focus-trap partagé) : celui-ci ne sait
 * pas rendre un état de chargement ni un refus sans bouton, d'où une modale dédiée à la zone
 * planning plutôt qu'une extension du composant partagé.
 */

export type EvictDialogPhase = "checking" | "accepted" | "refused" | "failed" | "indeterminate";

/**
 * Pourquoi l'essai a échoué (état `failed`) — trois causes DISTINCTES, jamais confondues (P4-119 b) :
 *  - `timeout`     : le SERVEUR a tranché « moteur trop lent » (504 `engine_timeout`) ;
 *  - `interrupted` : l'attente a été coupée CÔTÉ CLIENT avant la réponse — on n'a AUCUNE preuve de
 *                    panne (le moteur répondait peut-être `valid`), donc surtout pas « indisponible » ;
 *  - `unreachable` : une vraie panne réseau / 5xx du backend — LÀ le moteur est bien indisponible.
 */
export type EvictFailureKind = "timeout" | "interrupted" | "unreachable";

const FAILURE_MESSAGE: Record<EvictFailureKind, string> = {
  timeout: PLANNING_TEXTS.evictFailedTimeout,
  interrupted: PLANNING_TEXTS.evictFailedInterrupted,
  unreachable: PLANNING_TEXTS.evictFailedUnreachable,
};

interface EvictConfirmDialogProps {
  open: boolean;
  phase: EvictDialogPhase;
  /**
   * Le geste jugé : `evict` (« Prendre sa place » — la source évince l'occupant, qui passe à
   * replacer) ou `swap` (« Échanger » — les deux séances troquent leurs cases, sans perte). Seuls
   * le titre, le texte d'acceptation et le libellé du bouton changent ; les phases sont identiques.
   */
  kind?: "evict" | "swap";
  /** Nom de l'équipe occupant la cible (déjà résolu par la page). */
  occupantName: string;
  /**
   * L'occupant est un GROUPE mutualisé (plusieurs séances), pas une équipe : la tournure du texte
   * d'acceptation passe au masculin pluriel (« Le déplacer d'ici ? Ses séances passeront… »), car
   * c'est tout un bloc de séances qui part à replacer, pas une seule.
   */
  occupantIsGroup?: boolean;
  /** Compromis du verdict accepté (peut être vide → « Aucun compromis détecté. »). */
  compromises: Compromise[];
  /** Motifs du refus (état `refused`). */
  violations: MoveViolation[];
  /** Cause de l'échec (état `failed`) — pilote le message affiché. */
  failureKind: EvictFailureKind;
  /** Le move RÉEL est en vol (confirmation cliquée) : geler la confirmation. */
  busy: boolean;
  onConfirm: () => void;
  /** Relancer l'essai (état `failed`). */
  onRetry: () => void;
  onClose: () => void;
}

export function EvictConfirmDialog({ open, phase, kind = "evict", occupantName, occupantIsGroup = false, compromises, violations, failureKind, busy, onConfirm, onRetry, onClose }: EvictConfirmDialogProps) {
  const titleId = useId();
  const panelRef = useRef<HTMLDivElement>(null);
  useModalA11y({ ref: panelRef, onClose, active: open });

  if (!open) {
    return null;
  }

  const isSwap = "swap" === kind;
  const title =
    "refused" === phase
      ? isSwap
        ? PLANNING_TEXTS.swapRefusedTitle
        : PLANNING_TEXTS.evictRefusedTitle
      : "failed" === phase
        ? PLANNING_TEXTS.evictFailedTitle
        : "indeterminate" === phase
          ? PLANNING_TEXTS.evictIndeterminateTitle
          : isSwap
            ? PLANNING_TEXTS.swapTitle
            : PLANNING_TEXTS.evictTitle;

  return createPortal(
    <div className="fixed inset-0 z-[90] flex items-center justify-center p-4">
      <div className="absolute inset-0 bg-black/50" aria-hidden="true" onClick={onClose} />
      <div
        ref={panelRef}
        tabIndex={-1}
        role="dialog"
        aria-modal="true"
        aria-labelledby={titleId}
        // Palier `sm` de l'échelle partagée : une confirmation ne grandit pas avec l'écran.
        className={cn("relative flex max-h-[calc(100dvh-2rem)] w-full flex-col rounded-lg border border-border bg-card p-6 text-card-foreground shadow-xl", MODAL_WIDTH.sm)}
      >
        <h2 id={titleId} className="shrink-0 text-lg font-semibold">
          {title}
        </h2>

        <div className="mt-2 min-h-0 overflow-y-auto text-sm">
          {"checking" === phase ? (
            // Attente HONNÊTE (P4-119 b) : le moteur peut mettre plusieurs dizaines de secondes sur
            // un club dense — on le DIT, plutôt que de laisser croire à un gel puis de mentir « moteur
            // indisponible » si le front raccroche. La borne suit le budget client (cf. api.ts).
            <div className="flex items-center gap-2 text-muted-foreground" role="status">
              <Spinner className="size-4" />
              {PLANNING_TEXTS.evictChecking(MOVE_VERDICT_TIMEOUT_SECONDS)}
            </div>
          ) : null}

          {"accepted" === phase ? (
            <div className="flex flex-col gap-3">
              <p className="text-muted-foreground">
                {isSwap ? (
                  <>
                    {PLANNING_TEXTS.swapAcceptedLead}
                    <span className="font-medium text-foreground">{occupantName}</span>
                    {PLANNING_TEXTS.swapAcceptedTail}
                  </>
                ) : (
                  <>
                    {PLANNING_TEXTS.evictOccupiedLead}
                    <span className="font-medium text-foreground">{occupantName}</span>
                    {occupantIsGroup ? PLANNING_TEXTS.evictGroupAcceptedTail : PLANNING_TEXTS.evictTeamAcceptedTail}
                  </>
                )}
              </p>
              {compromises.length > 0 ? <CompromiseList compromises={compromises} /> : <EmptyHint>{PLANNING_TEXTS.evictNoCompromise}</EmptyHint>}
            </div>
          ) : null}

          {"refused" === phase ? (
            <div className="flex flex-col gap-2">
              <div className="flex items-center gap-2 font-medium text-destructive">
                <AlertTriangle className="size-4" aria-hidden="true" />
                <span>{PLANNING_TEXTS.evictRefusedReason}</span>
              </div>
              <ul className="flex list-disc flex-col gap-1 pl-6 text-muted-foreground">
                {violations.map((violation, index) => (
                  <li key={`${violation.rule}-${index}`}>{violation.message}</li>
                ))}
              </ul>
            </div>
          ) : null}

          {"failed" === phase ? (
            <div className="flex items-center gap-2 font-medium text-destructive" role="alert">
              <AlertTriangle className="size-4" aria-hidden="true" />
              <span>{FAILURE_MESSAGE[failureKind]}</span>
            </div>
          ) : null}

          {/* ENG-51 — verdict indéterminé : ton NEUTRE (`muted`), jamais un style conflit. Ce n'est
              pas un refus (aucune règle en cause) — rien n'a bougé, on invite à réessayer. */}
          {"indeterminate" === phase ? (
            <NoticeBanner tone="muted" role="status" message={PLANNING_TEXTS.verifyTimeout} />
          ) : null}
        </div>

        {/* Même filet que le pied ÉPINGLÉ de `Modal` / `ConfirmDialog` (P4-127 d) : bordure +
            `pt-4`. Troisième panneau jumeau fait main — il partage la grammaire. Les boutons
            vivaient déjà HORS de la zone défilante. */}
        <div className="mt-4 flex shrink-0 flex-wrap justify-end gap-2 border-t border-border pt-4">
          <Button variant="ghost" onClick={onClose}>
            {"refused" === phase ? PLANNING_TEXTS.evictClose : PLANNING_TEXTS.evictCancel}
          </Button>
          {"accepted" === phase ? (
            <Button variant={isSwap ? "default" : "destructive"} disabled={busy} onClick={onConfirm}>
              {isSwap ? PLANNING_TEXTS.swapConfirm : PLANNING_TEXTS.evictConfirm}
            </Button>
          ) : null}
          {"failed" === phase || "indeterminate" === phase ? (
            <Button variant="default" onClick={onRetry}>
              {PLANNING_TEXTS.evictRetry}
            </Button>
          ) : null}
        </div>
      </div>
    </div>,
    document.body,
  );
}
