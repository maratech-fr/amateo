import { AlertTriangle, CheckCircle2, MapPinOff } from "lucide-react";

import { Spinner } from "@/shared/components/ui/spinner";

import { Button } from "@/shared/components/ui/button";
import { DeclaredFixturesNotice } from "@/shared/components/ui/declared-fixtures-notice";
import { Modal } from "@/shared/components/ui/modal";
import { NoticeBanner } from "@/shared/components/ui/notice-banner";
import { dayLabelLong } from "@/shared/lib/days";

import type { PendingImpact, ValidateChange } from "./api";
import { PLANNING_TEXTS } from "./texts";

/** P2-52 — l'impact de dépointage de la validation, tel que l'écran le connaît au moment de
 *  confirmer. `orphanCount` = matchs qui perdront leur salle ; `declaredCount` = sous-ensemble
 *  déjà déclaré à la fédération. `loading`/`failed` : on ne laisse JAMAIS confirmer sur un impact
 *  inconnu (jamais « inconnu = vide »). */
export interface OrphanImpact {
  orphanCount: number;
  declaredCount: number;
  loading: boolean;
  failed: boolean;
  onRetry: () => void;
}

/** Lot 5 — ce qu'une validation change par rapport au planning en vigueur, tel que le serveur l'annonce. */
export interface ChangesAnnouncement {
  changes: readonly ValidateChange[];
  destroyedTitles: readonly string[];
  keptWishes: number;
}

/**
 * Ce que change la validation (lot 5) : la liste famille par famille (ou « rien ne change »), les plannings de
 * période supprimés (ou « aucun n'est supprimé » quand rien d'autre ne les touche) et les doléances conservées.
 * Le serveur décide ; l'écran affiche.
 */
function ChangesList({ announcement }: { announcement: ChangesAnnouncement }) {
  // Une règle (un groupe) porte sa ligne de plan (ses membres) à côté : la règle seule se dit, la ligne ne la double pas.
  const lines = announcement.changes.filter(
    (line) =>
      !("PlanConstraint" === line.family && announcement.changes.some((other) => "Constraint" === other.family)) &&
      !("SharedTrainingBlockTeam" === line.family && announcement.changes.some((other) => "SharedTrainingBlock" === other.family)),
  );
  return (
    <div className="mt-3 text-sm text-foreground">
      {0 === lines.length ? (
        <p>{PLANNING_TEXTS.validateNothingChanges}</p>
      ) : (
        <>
          <p>{PLANNING_TEXTS.validateChangesHeader}</p>
          <ul className="mt-1 list-disc pl-5">
            {lines.map((line) => (
              <li key={line.family}>{PLANNING_TEXTS.validateChangeLine(line.family, line.count)}</li>
            ))}
          </ul>
        </>
      )}
      {announcement.destroyedTitles.length > 0 ? (
        <NoticeBanner tone="destructive" className="mt-3" message={PLANNING_TEXTS.validateDeletesPeriodPlans([...announcement.destroyedTitles])} />
      ) : lines.length > 0 ? (
        <p className="mt-2">{PLANNING_TEXTS.validateNoPeriodPlanDeleted}</p>
      ) : null}
      {announcement.keptWishes > 0 ? <p className="mt-2 text-muted-foreground">{PLANNING_TEXTS.validateKeptWishes(announcement.keptWishes)}</p> : null}
    </div>
  );
}

/**
 * La confirmation de validation d'un planning. Elle S'AFFICHE comme aujourd'hui (aucun dialogue
 * ajouté) ; l'annonce « salle perdue » de P2-52 n'apparaît QUE si N>0 (aucun bruit préventif),
 * et le bouton Valider reste désactivé tant que l'impact n'est pas connu (en vol / échec). Lot 5 :
 * elle dit aussi ce qui change par rapport au planning en vigueur (`announcement`) ; quand des
 * plannings de période seront supprimés, le bouton devient « Valider et supprimer ».
 */
export function ValidateDialog({ hasAlerts, missingSessions, siblingCount, busy, orphan, announcement = null, onConfirm, onCancel }: { hasAlerts: boolean; missingSessions: readonly { teamId: string; teamName: string; count: number }[]; siblingCount: number; busy: boolean; orphan: OrphanImpact; announcement?: ChangesAnnouncement | null; onConfirm: () => void; onCancel: () => void }) {
  // On ne laisse pas confirmer tant que l'impact n'est pas connu (en vol / échec) : confirmer à
  // l'aveugle est exactement ce que la route d'impact existe pour éviter.
  const confirmDisabled = busy || orphan.loading || orphan.failed;
  const destroys = null !== announcement && announcement.destroyedTitles.length > 0;
  return (
    <Modal
      size="sm"
      label="Valider le planning"
      title={
        <span className="flex items-center gap-2">
          {hasAlerts ? <AlertTriangle aria-hidden="true" className="size-5 text-warning" /> : <CheckCircle2 aria-hidden="true" className="size-5 text-muted-foreground" />}
          Valider ce planning ?
        </span>
      }
      // Block Escape/overlay/X dismissal while the validation is in flight: dismissing
      // mid-request would hide the dialog but let the un-aborted mutation still lock the
      // planning read-only (the raw dialog had no escape at all during busy).
      onClose={() => {
        if (!busy) {
          onCancel();
        }
      }}
      footer={
        <>
          <Button variant="outline" size="sm" onClick={onCancel} disabled={busy}>
            Annuler
          </Button>
          <Button size="sm" variant={destroys ? "destructive" : "default"} onClick={onConfirm} disabled={confirmDisabled}>
            {destroys ? PLANNING_TEXTS.validateAndDelete : PLANNING_TEXTS.validate}
          </Button>
        </>
      }
    >
      <p className="mt-2 text-sm text-muted-foreground">
        {hasAlerts
          ? "Ce planning présente des alertes du système (créneaux non placés, contraintes non satisfaites…). En le validant, vous assumez ces contre-indications sous votre responsabilité. Le planning passera en lecture seule."
          : "Le planning passera en lecture seule (« Validé »). Vous pourrez le rouvrir pour le modifier."}
      </p>
      {missingSessions.length > 0 ? (
        <ul className="mt-2 list-disc pl-5 text-sm text-foreground">
          {missingSessions.map((missing) => (
            <li key={missing.teamId}>{PLANNING_TEXTS.validateMissingSessions(missing.teamName, missing.count)}</li>
          ))}
        </ul>
      ) : null}
      {null === announcement ? null : <ChangesList announcement={announcement} />}
      {/* P2-52 — l'annonce « salle perdue » N'APPARAÎT QUE si N>0 (aucun bruit préventif sinon).
          Ton destructif encadré, distinct des deux paragraphes en prose ci-dessus/dessous. */}
      {orphan.orphanCount > 0 ? (
        <NoticeBanner tone="destructive" className="mt-3">
          <div className="flex items-start gap-2">
            <MapPinOff aria-hidden="true" className="mt-0.5 size-4 shrink-0 text-destructive" />
            <div>
              <p>
                {orphan.orphanCount > 1 ? `${orphan.orphanCount} matchs perdront leur gymnase` : "1 match perdra son gymnase"}
                {orphan.declaredCount > 0 ? `, dont ${orphan.declaredCount} déjà ${orphan.declaredCount > 1 ? "déclarés" : "déclaré"} à la fédération` : ""}.
              </p>
              <p className="mt-1 text-muted-foreground">
                {orphan.orphanCount > 1 ? "Ils repasseront « à placer », leur horaire conservé — vous pourrez leur réattribuer un gymnase." : "Il repassera « à placer », son horaire conservé — vous pourrez lui réattribuer un gymnase."}
              </p>
              <DeclaredFixturesNotice count={orphan.declaredCount} />
            </div>
          </div>
        </NoticeBanner>
      ) : null}
      {/* En vol / échec : le bouton Valider est désactivé, et on DIT pourquoi (jamais un bouton
          grisé muet, jamais un impact inconnu présenté comme vide). */}
      {orphan.loading ? (
        <p aria-live="polite" className="mt-3 flex items-center gap-1.5 text-sm text-muted-foreground">
          <Spinner className="size-3.5" />
          Vérification des matchs concernés…
        </p>
      ) : null}
      {orphan.failed ? (
        <p aria-live="polite" className="mt-3 text-sm text-destructive">
          Vérification impossible pour l'instant —{" "}
          <button type="button" onClick={orphan.onRetry} className="underline underline-offset-2 hover:no-underline">
            Réessayer
          </button>
          .
        </p>
      ) : null}
      {siblingCount > 0 ? (
        <p className="mt-3 text-sm font-medium text-foreground">
          Seule cette version sera conservée — {siblingCount > 1 ? `les ${siblingCount} autres versions seront définitivement supprimées` : "l'autre version sera définitivement supprimée"}.
        </p>
      ) : null}
    </Modal>
  );
}

/**
 * Lot 5 — « Valider ces modifications », sans régénérer : ce qui change, les plannings de période supprimés, puis le
 * contrôle d'incohérences du serveur avec le planning en vigueur (règles cassées nommées, séances manquantes, contrôle
 * non concluant). Le contrôle avertit, il ne bloque pas : la confirmation reste possible dès que l'annonce est lue.
 */
export function ApplyPendingChangesDialog({ impact, loading, failed, busy, onRetry, onConfirm, onCancel }: { impact: PendingImpact | undefined; loading: boolean; failed: boolean; busy: boolean; onRetry: () => void; onConfirm: () => void; onCancel: () => void }) {
  const destroys = undefined !== impact && impact.destroyedPeriodPlans.length > 0;
  return (
    <Modal
      size="sm"
      label={PLANNING_TEXTS.applyPendingChangesTitle}
      title={PLANNING_TEXTS.applyPendingChangesTitle}
      onClose={() => {
        if (!busy) {
          onCancel();
        }
      }}
      footer={
        <>
          <Button variant="outline" size="sm" onClick={onCancel} disabled={busy}>
            {PLANNING_TEXTS.cancel}
          </Button>
          <Button size="sm" variant={destroys ? "destructive" : "default"} onClick={onConfirm} disabled={busy || loading || failed || undefined === impact}>
            {destroys ? PLANNING_TEXTS.validateAndDelete : PLANNING_TEXTS.validate}
          </Button>
        </>
      }
    >
      <p className="mt-2 text-sm text-muted-foreground">{PLANNING_TEXTS.applyPendingChangesDescription}</p>
      {loading ? (
        <p aria-live="polite" className="mt-3 flex items-center gap-1.5 text-sm text-muted-foreground">
          <Spinner className="size-3.5" />
          {PLANNING_TEXTS.checkingCoherence}
        </p>
      ) : null}
      {failed ? (
        <p aria-live="polite" className="mt-3 text-sm text-destructive">
          {PLANNING_TEXTS.checkFailed}{" "}
          <button type="button" onClick={onRetry} className="underline underline-offset-2 hover:no-underline">
            {PLANNING_TEXTS.retry}
          </button>
        </p>
      ) : null}
      {undefined === impact || loading ? null : (
        <>
          <ChangesList announcement={{ changes: impact.changes, destroyedTitles: impact.destroyedPeriodPlans.map((plan) => plan.title), keptWishes: impact.keptWishes }} />
          <div aria-live="polite" className="mt-3 text-sm text-foreground">
            {impact.checkInconclusive ? (
              <NoticeBanner tone="warning" message={PLANNING_TEXTS.checkInconclusive} />
            ) : 0 === impact.incoherences.length ? (
              <p>{PLANNING_TEXTS.noIncoherence}</p>
            ) : (
              <NoticeBanner tone="warning">
                <p>{PLANNING_TEXTS.incoherencesHeader(impact.incoherences.length)}</p>
                <ul className="mt-1 list-disc pl-5">
                  {impact.incoherences.map((incoherence) => (
                    <li key={`${incoherence.slotId}|${incoherence.rule}`}>{PLANNING_TEXTS.incoherenceLine(incoherence.teamName, dayLabelLong(incoherence.dayOfWeek), incoherence.startTime, incoherence.label)}</li>
                  ))}
                </ul>
                <p className="mt-1">{PLANNING_TEXTS.incoherencesFollowUp}</p>
              </NoticeBanner>
            )}
            {impact.missingSessions.length > 0 ? (
              <ul className="mt-2 list-disc pl-5">
                {impact.missingSessions.map((missing) => (
                  <li key={missing.teamId}>{PLANNING_TEXTS.validateMissingSessions(missing.teamName, missing.count)}</li>
                ))}
              </ul>
            ) : null}
          </div>
        </>
      )}
    </Modal>
  );
}
