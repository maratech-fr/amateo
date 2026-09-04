import { useState } from "react";

import { Button } from "@/shared/components/ui/button";
import { ConfirmDialog } from "@/shared/components/ui/confirm-dialog";
import { EmptyHint } from "@/shared/components/ui/empty-hint";
import { Modal } from "@/shared/components/ui/modal";
import { Spinner } from "@/shared/components/ui/spinner";
import { WarningPanel } from "@/shared/components/ui/warning-panel";

import type { CalendarEntryPeriodType, PlannedWindow } from "./api";
import { frDateShort, mergeSegments, segmentLabel, segmentsFromOffer, segmentWeekCount, splitSegment, type ExcludedWeekRange, type WeekSegment, type WeekWindow } from "./lib/date";
import type { WeekPickerState, WindowConflict } from "./lib/useWeekAdapt";
import { WindowAlreadyPlannedNotice } from "./WindowAlreadyPlannedNotice";

/** Ce que le picker doit dire de l'état « bloc déjà généré » + la découpe destructive à câbler. */
export interface WeekPickerBlock {
  versionCount: number;
  /** Bloc VALIDÉ (version choisie) : la découpe destructive n'est PAS offerte (chaîne non atomique). */
  validated: boolean;
  /** Une version est EN GÉNÉRATION : la découpe est désactivée avec sa raison. */
  generationInFlight: boolean;
  /** La suppression des versions est en cours. */
  deleting: boolean;
  /** Un échec PARTIEL a laissé des versions : on le dit, on reste dans l'état bloqué. */
  deleteFailed: boolean;
  /** Confirmé : supprime les versions puis laisse le picker rebasculer en « choix des semaines ». */
  onDeleteVersions: () => void;
}

interface WeekPickerDialogProps {
  /** Libellé de la période mère (matérialisée OU vacance pas encore créée — P2-5 E1). */
  title: string;
  /** Fenêtre de la mère (pour segmenter : entame/fin partielles de l'événement). */
  startDate: string;
  endDate: string;
  /**
   * Type de la mère. Pour une FERMETURE (closure), le découpage début·milieu·fin est IMPOSÉ
   * (règle fondateur 2026-09-05) : Scinder/Fusionner disparaissent, les segments restent cochables
   * (créer le milieu maintenant, la fin plus tard). Les VACANCES gardent Scinder/Fusionner.
   */
  periodType?: CalendarEntryPeriodType | null;
  /** Semaines lun→dim OFFERTES couvrant la fenêtre de la mère, clampées à la saison. */
  weeks: WeekWindow[];
  /** A2 — la saison affichée : un libellé de segment DANS la saison omet l'année (sinon la garde). */
  season?: { startDate: string; endDate: string };
  busy: boolean;
  /**
   * P2-36/P2-40 — état NOMMÉ du picker : `weeks` (choix, l'existant), `loading` (plans/plannings/
   * enfants pas résolus — le dialogue s'ouvre et le DIT au lieu de partir en bloc en silence),
   * `block` (une adaptation d'un bloc porte déjà des versions), `holiday` (une fermeture chevauche
   * des vacances : les semaines sous vacances sont écartées, le chemin d'un bloc disparaît). Défaut
   * `weeks`.
   */
  state?: WeekPickerState;
  /** Requis en état `block` : les faits + la découpe destructive à proposer. */
  block?: WeekPickerBlock;
  /** P2-40 — les blocs de semaines écartés parce qu'une vacance les gouverne (ligne d'info, état `holiday`). */
  excludedRanges?: ExcludedWeekRange[];
  /**
   * P2-38 (prévention) — les fenêtres qu'un AUTRE plan de période gouverne déjà, SERVIES par le
   * backend. Leurs semaines sont déjà RETIRÉES de l'offre (pas de case à cocher) ; on les NOMME dans
   * un encart au-dessus de la liste, avec la `reason` servie TELLE QUELLE (le front ne compose aucune
   * phrase métier) et le raccourci « Ouvrir le planning en place ». On ne fabrique pas de ligne-segment
   * désactivée : l'unité de la liste est le SEGMENT, pas la semaine, et forcer une frontière de segment
   * ici obligerait le front à dériver un objet métier.
   */
  plannedRanges?: PlannedWindow[];
  /** P2-41 — segments cochés → un planning (enfant) par segment. */
  onPickSegments: (segments: WeekSegment[]) => void;
  /** Chemin « d'un bloc » : adapter toute la période sur son plan (comportement historique). */
  onAdaptWhole: () => void;
  /**
   * P2-40 — « Consigner l'indisponibilité » : chemin PENDING, état `holiday` sans aucune semaine
   * offerte (100 % sous vacances). Matérialise le FAIT sans plan ni navigation. Absent quand
   * l'entrée existe déjà en base (rien à consigner).
   */
  onRecordOnly?: () => void;
  onClose: () => void;
  /** P2-38 — un refus « une seule planification par fenêtre » sur la création de semaines. */
  conflict?: WindowConflict | null;
  /** Ouvrir le planning en conflit (navigation vers son entrée). */
  onOpenConflict?: (entryId: string) => void;
}

/**
 * P2-41 (fondateur, amende P2-5 E1) : « le SEGMENT est l'unité hors socle ». La liste du picker
 * n'est plus une semaine par ligne mais une liste de SEGMENTS — des blocs de semaines pleines et
 * contiguës proposés aux ruptures GÉOMÉTRIQUES (`segmentsFromOffer` : entame/fin partielle de
 * l'événement, discontinuité de l'offre). Ils sont PRÉCOCHÉS ; le gestionnaire peut SCINDER un
 * segment (le déplier en semaines) ou FUSIONNER des segments adjacents dans l'offre — liberté
 * totale, le serveur ne borne que contiguïté + enveloppe. Chaque segment coché devient un planning.
 *
 * Le front ne redérive AUCUNE règle solveur : les ruptures sont calculées des données servies
 * (semaines offertes + fenêtre de l'événement). La phrase sur un segment multi-semaines est de la
 * PRÉSENTATION (comment le solveur traitera N semaines en un plan), jamais une décision.
 *
 * P2-36 : le dialogue s'OUVRE toujours et NOMME sa raison (`state`) — « en chargement » ≠ « déjà
 * générée d'un bloc » — au lieu de basculer en bloc sans un mot.
 */
export function WeekPickerDialog({ title, startDate, endDate, weeks, season, periodType, busy, state = "weeks", block, excludedRanges = [], plannedRanges = [], onPickSegments, onAdaptWhole, onRecordOnly, onClose, conflict, onOpenConflict }: WeekPickerDialogProps) {
  // FERMETURE : le découpage début·milieu·fin est IMPOSÉ, Scinder/Fusionner n'ont pas lieu d'être
  // (le serveur ne validerait pas une frontière libre). Les VACANCES gardent l'édition manuelle.
  const allowSegmentEditing = "closure" !== periodType;
  // Segments dérivés de l'offre + fenêtre, PUIS mutés localement par scinder/fusionner.
  const [segments, setSegments] = useState<WeekSegment[]>(() => segmentsFromOffer(weeks, startDate, endDate));
  const [checked, setChecked] = useState<Set<string>>(() => new Set(segments.map((s) => s.monday)));
  // L'offre change (loading → weeks : de [] à peuplée) : on ré-initialise segments + coches. Motif
  // « ajuster un state quand une prop change » (setState pendant le rendu) plutôt qu'un effet — les
  // gestes scinder/fusionner ne doivent PAS être écrasés à chaque rendu (l'array `weeks` est neuf à
  // chaque fois, seule sa signature est stable).
  const signature = weeks.map((w) => w.monday).join("|");
  const [sig, setSig] = useState(signature);
  if (sig !== signature) {
    const fresh = segmentsFromOffer(weeks, startDate, endDate);
    setSegments(fresh);
    setChecked(new Set(fresh.map((s) => s.monday)));
    setSig(signature);
  }
  // Confirmation de la découpe destructive (état `block`) : réutilise le patron d'avertissement
  // existant (ConfirmDialog destructif) plutôt qu'une deuxième maison du danger.
  const [confirmingSplit, setConfirmingSplit] = useState(false);

  const toggle = (monday: string) =>
    setChecked((prev) => {
      const next = new Set(prev);
      if (next.has(monday)) {
        next.delete(monday);
      } else {
        next.add(monday);
      }
      return next;
    });

  // Scinder un segment multi-semaines : il devient ses semaines individuelles (les coches suivent).
  const splitAt = (index: number) => {
    const seg = segments[index];
    const parts = splitSegment(seg);
    setSegments((prev) => [...prev.slice(0, index), ...parts, ...prev.slice(index + 1)]);
    setChecked((prev) => {
      const next = new Set(prev);
      const was = next.has(seg.monday);
      next.delete(seg.monday);
      if (was) {
        parts.forEach((p) => next.add(p.monday));
      }
      return next;
    });
  };

  // Fusionner un segment avec le PRÉCÉDENT (adjacent dans la liste — même par-dessus une rupture).
  const mergeWithPrevious = (index: number) => {
    const a = segments[index - 1];
    const b = segments[index];
    const merged = mergeSegments(a, b);
    setSegments((prev) => [...prev.slice(0, index - 1), merged, ...prev.slice(index + 1)]);
    setChecked((prev) => {
      const next = new Set(prev);
      const was = next.has(a.monday) || next.has(b.monday);
      next.delete(a.monday);
      next.delete(b.monday);
      if (was) {
        next.add(merged.monday); // merged.monday === a.monday
      }
      return next;
    });
  };

  const picked = segments.filter((s) => checked.has(s.monday));
  const versionCount = block?.versionCount ?? 0;
  const versionLabel = `${versionCount} version${versionCount > 1 ? "s" : ""}`;
  const createLabel = picked.length > 1 ? `Créer les ${picked.length} plannings` : "Créer le planning";

  // P2-38 (prévention) — le chemin « d'un bloc » est INTERDIT dès qu'une partie de la fenêtre est
  // gouvernée : par des vacances (état `holiday`, aligné sur ce patron par décision fondateur) OU
  // par un autre plan déjà en place (`plannedRanges`). Un plan de bloc engloberait des semaines qui
  // ne lui appartiennent pas. On le DÉSACTIVE avec sa raison VISIBLE — pas caché, et jamais via
  // `title=` (`button.tsx` pose `disabled:pointer-events-none`, l'infobulle native ne se déclenche
  // pas). L'état `block` (déjà généré d'un bloc) garde son propre chemin « Continuer d'un bloc ».
  const isBlockState = "block" === state;
  // FERMETURE à plusieurs segments (début·milieu·fin) : « d'un bloc » est INTERDIT côté serveur
  // (WeekSegmentationRule ⇒ 422). Le front ne propose donc pas un geste que le backend refuse — il
  // DÉSACTIVE le bouton avec sa raison, comme pour les vacances / le déjà-planifié.
  const closureMultiSegment = "closure" === periodType && segments.length > 1;
  const blockPathBlocked = !isBlockState && ("holiday" === state || plannedRanges.length > 0 || closureMultiSegment);
  const blockPathReason =
    plannedRanges.length > 0
      ? "Une partie de ces dates est déjà planifiée par ailleurs — adaptez les semaines restantes une à une."
      : "holiday" === state
        ? "Des vacances couvrent une partie de cette période — adaptez les semaines restantes une à une."
        : "Cette indisponibilité a une semaine entamée — adaptez-la par début, milieu, fin.";
  // Le choix des semaines et le bloc « à consigner » ne sont offerts qu'en états DÉCIDÉS
  // (weeks / holiday), jamais en chargement ni en bloc.
  const decided = "weeks" === state || "holiday" === state;
  const noneOfferable = decided && 0 === segments.length;

  // La liste de segments, partagée par l'état `weeks` (choix classique) et `holiday` (les segments
  // hors vacances qui restent à traiter). Précochés ; scinder/fusionner sont des gestes nommés,
  // atteignables au clavier.
  const segmentList = (
    <ul className="mt-4 space-y-2">
      {segments.map((seg, index) => {
        const multi = segmentWeekCount(seg) > 1;
        const label = segmentLabel(seg, season);
        return (
          <li key={seg.monday} className="rounded-md border border-border px-3 py-2 text-sm">
            <div className="flex items-start justify-between gap-2">
              {/* A2 — filet CSS : le libellé long tronque proprement (min-w-0 + truncate) et
                  garde son texte complet en `title`, il ne déborde plus de son item. */}
              <label className="flex min-w-0 items-center gap-2">
                <input type="checkbox" className="size-4 shrink-0 accent-[var(--accent)]" checked={checked.has(seg.monday)} onChange={() => toggle(seg.monday)} />
                <span className="truncate" title={label}>
                  {label}
                </span>
              </label>
              <div className="flex shrink-0 gap-1">
                {allowSegmentEditing && index > 0 ? (
                  <Button variant="ghost" size="sm" disabled={busy} aria-label={`Fusionner « ${label} » avec le segment précédent`} onClick={() => mergeWithPrevious(index)}>
                    Fusionner
                  </Button>
                ) : null}
                {allowSegmentEditing && multi ? (
                  <Button variant="ghost" size="sm" disabled={busy} aria-label={`Scinder « ${label} » en semaines`} onClick={() => splitAt(index)}>
                    Scinder
                  </Button>
                ) : null}
              </div>
            </div>
            {/* Pédagogie (présentation, pas décision) sur un segment multi-semaines : comment le
                solveur traite N semaines en UN plan. */}
            {multi ? (
              <p className="mt-1 text-xs text-muted-foreground">Des semaines identiques donnent un planning exact ; si elles diffèrent, la fermeture la plus large s'applique à toutes.</p>
            ) : null}
          </li>
        );
      })}
    </ul>
  );

  return (
    <Modal
      label="Choisir les semaines"
      title="Quelles semaines ajuster ?"
      onClose={onClose}
      footer={
        <>
          {/* Le chemin « d'un bloc » reste VISIBLE mais DÉSACTIVÉ (avec sa raison) dès qu'une partie
              de la fenêtre est gouvernée — vacances (P2-40) ou déjà planifié (P2-38) — au lieu d'être
              caché (décision fondateur, alignement du cas vacances sur le patron « désactivé + raison »).
              La raison QUALIFIE le bouton : elle voyage AVEC lui dans le pied (règle de migration P4-127 d). */}
          <div>
            <Button variant="ghost" size="sm" onClick={onAdaptWhole} disabled={busy || block?.deleting || blockPathBlocked}>
              {isBlockState ? "Continuer d'un bloc" : "Adapter toute la période d'un bloc"}
            </Button>
            {blockPathBlocked ? <p className="mt-1 text-xs text-muted-foreground">{blockPathReason}</p> : null}
          </div>
          {decided && segments.length > 0 ? (
            <Button size="sm" onClick={() => onPickSegments(picked)} disabled={busy || 0 === picked.length}>
              {busy ? <Spinner className="size-4" /> : null}
              {createLabel}
            </Button>
          ) : null}
          {/* 0 segment offert (100 % sous vacances OU 100 % déjà planifié), chemin pending : consigner
              le FAIT (sans plan ni navigation). Condition GÉNÉRALISÉE sur « 0 segment offert », pas sur
              l'état holiday — sinon le nouveau cas « tout déjà planifié » donnerait une modale en cul-de-sac. */}
          {noneOfferable && undefined !== onRecordOnly ? (
            <Button size="sm" onClick={onRecordOnly} disabled={busy}>
              {busy ? <Spinner className="size-4" /> : null}
              Consigner l'indisponibilité
            </Button>
          ) : null}
        </>
      }
    >
      {/* P2-38 (prévention) — les fenêtres déjà planifiées par un AUTRE plan, NOMMÉES au-dessus de
          la liste (une par fenêtre gouvernante), avec la `reason` servie TELLE QUELLE + « Ouvrir le
          planning en place ». `aria-live="polite"` : le verdict arrive APRÈS l'ouverture (la modale
          s'ouvre en chargement). Rendu dans les états `weeks` ET `holiday` — les deux exclusions
          (vacances P2-40, déjà planifié P2-38) coexistent. */}
      <div aria-live="polite">
        {decided
          ? plannedRanges.map((planned) => (
              <div key={planned.entryId} className="mt-4">
                <WindowAlreadyPlannedNotice message={planned.reason} onOpen={() => onOpenConflict?.(planned.entryId)} />
              </div>
            ))
          : null}
      </div>

      {"weeks" === state ? (
        segments.length > 0 ? (
          <>
            <p className="mt-2 text-sm text-muted-foreground">« {title} » couvre plusieurs semaines. Chaque segment coché devient un planning indépendant — scindez-le en semaines ou fusionnez des segments voisins à votre main.</p>
            {segmentList}
          </>
        ) : (
          // Fenêtre 100 % gouvernée (déjà planifiée) : les encarts ci-dessus disent par qui, il ne
          // reste rien à cocher ici.
          <EmptyHint className="mt-2">Aucune semaine de cette indisponibilité ne reste à ajuster ici.</EmptyHint>
        )
      ) : null}

      {/* ÉTAT « chevauchement vacances » (P2-40) : les semaines sous vacances sont EXCLUES (pas
          grisées) — le rappel vit déjà dans le planning des vacances. Une ligne d'info le dit, et
          le chemin « d'un bloc » disparaît (un plan de bloc gouvernerait la fenêtre des vacances).
          Reste (s'il en reste) le choix des segments HORS vacances ; 100 % couvert → info seule. */}
      {"holiday" === state ? (
        <div className="mt-2 space-y-3 text-sm">
          {excludedRanges.map((range) => (
            <WarningPanel
              key={range.startDate}
              message={`Semaines du ${frDateShort(range.startDate)} au ${frDateShort(range.endDate)} couvertes par ${range.labels.join(", ")} — le rappel vous attend dans son planning.`}
            />
          ))}
          {segments.length > 0 ? (
            <>
              <p className="text-muted-foreground">Choisissez les semaines à ajuster, hors vacances. Chaque segment coché devient un planning indépendant.</p>
              {segmentList}
            </>
          ) : (
            <EmptyHint>Toutes les semaines de cette indisponibilité sont couvertes par des vacances — il n'y a rien à ajuster en dehors.</EmptyHint>
          )}
        </div>
      ) : null}

      {/* ÉTAT « chargement » : on ne connaît pas encore l'état des plans/plannings/enfants — le
          dialogue le DIT (au lieu de partir en bloc en silence) ; il ne prétend PAS que le choix
          des semaines n'existe pas. Le chemin « d'un bloc » reste offert, il marche toujours. */}
      {"loading" === state ? (
        <div className="mt-2 flex items-start gap-2 text-sm text-muted-foreground">
          <Spinner className="mt-0.5 size-4 shrink-0" />
          <p>On vérifie l'état de « {title} »… Le choix « semaine par semaine ou d'un bloc » s'affiche dès que c'est chargé.</p>
        </div>
      ) : null}

      {/* ÉTAT « déjà générée d'un bloc » : NOMMER le fait (≠ « en chargement », ≠ « une seule
          semaine ») — un message générique recréerait le défaut. « Continuer d'un bloc » ne se
          perd jamais ; la découpe destructive n'apparaît QUE si le bloc n'est pas validé. */}
      {"block" === state ? (
        <div className="mt-2 space-y-3 text-sm">
          <p className="text-muted-foreground">
            « {title} » a déjà été adaptée d'un bloc — {versionLabel}. Continuez sur ce planning, ou repartez de zéro en le découpant en semaines.
          </p>
          {block?.validated ? (
            // Décision fondateur : bloc VALIDÉ → pas de découpe destructive ici (la chaîne
            // rouvrir→supprimer n'est pas atomique, un échec après réouverture laisserait un
            // planning validé dépointé). On renvoie vers les gestes qui existent.
            <p className="rounded-md border border-border bg-muted/40 px-3 py-2 text-muted-foreground">
              Ce planning de bloc est validé. Pour le découper en semaines, rouvrez-le puis supprimez-le d'abord (depuis « Voir le planning »), puis revenez adapter.
            </p>
          ) : (
            <>
              {block?.deleteFailed ? (
                <p role="alert" className="rounded-md border border-destructive/50 bg-destructive/10 px-3 py-2 text-foreground">
                  Certaines versions n'ont pas pu être supprimées — réessayez.
                </p>
              ) : null}
              <div>
                <Button
                  variant="destructive"
                  size="sm"
                  disabled={busy || block?.deleting || block?.generationInFlight}
                  title={block?.generationInFlight ? "Une génération est en cours — attendez qu'elle finisse." : undefined}
                  onClick={() => setConfirmingSplit(true)}
                >
                  {block?.deleting ? <Spinner className="size-4" /> : null}
                  Supprimer les versions et découper en semaines
                </Button>
                {block?.generationInFlight ? <p className="mt-1 text-xs text-muted-foreground">Une génération est en cours — la découpe sera possible ensuite.</p> : null}
              </div>
            </>
          )}
        </div>
      ) : null}

      {conflict && onOpenConflict ? (
        <div className="mt-4">
          <WindowAlreadyPlannedNotice message={conflict.message} onOpen={() => onOpenConflict(conflict.entryId)} />
        </div>
      ) : null}

      {/* Confirmation destructive : NOMME la portée (nombre de versions + réglages qui repartent
          de la saison — ce que fait la découpe côté serveur). Patron ConfirmDialog réutilisé. */}
      {block ? (
        <ConfirmDialog
          open={confirmingSplit}
          title="Découper cette période en semaines ?"
          description={
            <>
              Cela supprime {versionLabel} déjà générée{versionCount > 1 ? "s" : ""} d'un bloc pour « {title} », puis permet de la découper. Les réglages de cette période repartiront de la saison. Action définitive.
            </>
          }
          confirmLabel="Supprimer et découper"
          destructive
          onConfirm={() => {
            setConfirmingSplit(false);
            block.onDeleteVersions();
          }}
          onCancel={() => setConfirmingSplit(false)}
        />
      ) : null}
    </Modal>
  );
}
