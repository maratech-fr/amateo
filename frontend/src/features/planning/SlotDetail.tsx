import { AlertTriangle, ChevronDown, ChevronRight, Loader2, Lock, LockOpen, Move, X } from "lucide-react";
import { useState } from "react";

import { Button } from "@/shared/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/shared/components/ui/card";
import { EmptyHint } from "@/shared/components/ui/empty-hint";
import { WizardStepLink } from "@/features/wizard/WizardStepLink";

import type { Constraint, LockOrigin, MoveViolation, Slot, Venue } from "./api";
import { applicableConstraints, isClubWide } from "./lib/applicableConstraints";
import { tagLabel } from "@/features/wizard/lib/tagLabels";
import { describeConstraint } from "./lib/describeConstraint";
import { type GridCell, NO_COACH_LABEL } from "./lib/grid";

/** Map vide partagée : une CLUB+tag ne s'affiche nulle part tant que la résolution n'est pas fournie. */
const NO_TAGS: ReadonlyMap<string, ReadonlySet<string>> = new Map();

/**
 * L'état du dernier déplacement demandé, pour l'écran (F2b). `pending` = le moteur
 * délibère (~500 ms) ; `rejected` = refusé, avec les règles violées NOMMÉES ; `blocked` =
 * une génération tourne (réessayer ensuite) ; `error` = le moteur n'a pas répondu.
 */
export type MoveFeedback =
  | { status: "idle" }
  | { status: "pending" }
  | { status: "rejected"; violations: MoveViolation[] }
  | { status: "blocked" }
  // P4-119 (b) : l'attente a été coupée CÔTÉ CLIENT avant la réponse — DISTINCT d'un moteur muet
  // (`error`) : on ne sait rien de la santé du moteur, on le dit sans l'accuser.
  | { status: "interrupted" }
  | { status: "error" };

interface SlotDetailProps {
  cell: GridCell;
  slot: Slot;
  venues: Venue[];
  categoryLabel: string;
  /** All club constraints — the applicable ones are composed here (F1). */
  constraints: Constraint[];
  /** tag NAME → équipes taguées (saison courante) : une CLUB+tag ne s'affiche que sur les
   *  équipes du tag, miroir de l'éclatement backend (cf. lib/applicableConstraints). */
  tagTeamIds?: ReadonlyMap<string, ReadonlySet<string>>;
  /** Résolveurs de NOM de la cible d'une contrainte (équipe / coach) — venant des lookups du
   *  planning. Absents (défaut) → la description rend le prédicat SEUL, jamais « ? · … ». */
  teamName?: (teamId: string) => string | undefined;
  coachName?: (coachId: string) => string | undefined;
  busy: boolean;
  /** F2b : le retour du dernier déplacement (verdict moteur). Défaut = idle. */
  moveState?: MoveFeedback;
  /** VALIDATED schedule → the slot is read-only (no move/lock). */
  readOnly?: boolean;
  /** P2-30 geste 1 : ce créneau est la SOURCE d'un mode cible armé — le bouton bascule sur
   *  une consigne de choix de cible, et une aide « Échap pour annuler » s'affiche. */
  armed?: boolean;
  /** P2-51 PR-6 (D11) — ce créneau appartient à une séance de BLOC de mutualisation (tous les
   *  membres co-localisés sur sa case) : « Déplacer » devient « Déplacer le groupe » (le déplacement
   *  individuel n'est plus proposé — le moteur le refuse). `memberCount` = nombre d'équipes du bloc,
   *  annoncé sur le bouton et en note. `null`/absent = créneau ordinaire. */
  groupSession?: { memberCount: number } | null;
  onClose: () => void;
  onToggleLock: () => void;
  /** P2-30 : « Déplacer » n'ouvre plus de formulaire — il ARME le mode cible click-click (la
   *  cible se choisit sur la grille). Rappeler quand c'est armé = annuler (toggle côté page).
   *  P2-51 PR-6 : sur une séance de bloc, la page route ce geste vers le déplacement de GROUPE. */
  onArmMove: () => void;
}

/**
 * L'origine du verrou EN CLAIR (F1). ⚠ `UNKNOWN` se lit comme une IGNORANCE — le créneau EST
 * bien verrouillé, on ne sait simplement pas d'où vient le verrou : c'est cette nuance qui
 * décide si le gestionnaire ose y toucher. Jamais de code d'enum à l'écran.
 */
const LOCK_ORIGIN: Record<LockOrigin, { label: string; hint: string }> = {
  RESERVATION: { label: "Réservation gymnase", hint: "Ce créneau est réservé auprès du gymnase — ne le déplacez pas sans vérifier." },
  MANUAL: { label: "Épinglé manuellement", hint: "Vous (ou un gestionnaire) avez fixé ce créneau à la main. Vous pouvez le retirer." },
  UNKNOWN: { label: "Verrouillé — origine inconnue", hint: "Ce créneau est bien verrouillé, mais on ne sait pas pourquoi. Vérifiez avant d'y toucher." },
};

function ConstraintList({ label, items, describe }: { label: string; items: Constraint[]; describe: (c: Constraint) => string | null }) {
  return (
    <div className="mt-2 first:mt-0">
      <p className="text-[0.7rem] font-semibold uppercase tracking-wide text-muted-foreground/80">{label}</p>
      <ul className="mt-1 divide-y divide-border/60">
        {items.map((c) => {
          // B2 — UNE seule ligne de texte par contrainte : ce que la règle FAIT (dérivé de la
          // config, vérifiable) ou, faute de description fidèle (`null`), son nom libre. Le nom
          // libre ne double plus la description (doublon supprimé).
          const what = describe(c);

          return (
            <li key={c.id} className="flex flex-col gap-0.5 py-2 text-sm first:pt-0.5 last:pb-0.5">
              <div className="flex items-start justify-between gap-2">
                <span className="min-w-0">{what ?? c.name}</span>
                <span className="mt-0.5 shrink-0 rounded-full bg-muted px-1.5 text-xs text-muted-foreground">{"HARD" === c.ruleType ? "obligatoire" : "préférence"}</span>
              </div>
              {/* P2-25 lien B — un problème DÉSIGNÉ (la règle qui contraint ce créneau) mène à son
                  lieu de correction : l'éditeur du wizard, ouvert PRÉ-REMPLI sur elle. Rattaché
                  visuellement à SA règle (même bloc, sous sa ligne). Retour nommé « ← Retour au
                  planning ». */}
              <WizardStepLink
                step="constraints"
                params={{ edit: c.id }}
                from="planning"
                className="self-start text-xs font-medium text-accent underline underline-offset-2 hover:text-accent/80"
              >
                Corriger cette contrainte
              </WizardStepLink>
            </li>
          );
        })}
      </ul>
    </div>
  );
}

const noName = (): string | undefined => undefined;

export function SlotDetail({ cell, slot, venues, categoryLabel, constraints, tagTeamIds = NO_TAGS, teamName = noName, coachName = noName, busy, moveState = { status: "idle" }, readOnly = false, armed = false, groupSession = null, onClose, onToggleLock, onArmMove }: SlotDetailProps) {
  // Repliées par défaut : ouvrir un créneau ne doit pas agrandir l'aside (retour fondateur).
  // Le compte reste visible replié pour savoir s'il y a quelque chose à ouvrir.
  const [constraintsOpen, setConstraintsOpen] = useState(false);

  const origin = null !== slot.lockOrigin ? LOCK_ORIGIN[slot.lockOrigin] : null;
  const applicable = applicableConstraints(slot, constraints, tagTeamIds);
  // Ce que chaque règle FAIT se dérive de sa config ; le gymnase d'une règle FACILITY se nomme
  // depuis les gymnases connus du panneau (introuvable → on retombe sur le nom, pas d'invention).
  const venueNameById = new Map(venues.map((v) => [v.id, v.name]));
  // La description NOMME sa cible en tête (« <équipe/coach/Groupe X/Toutes les équipes> ·
  // <prédicat> »), pour que la boucle de correction pointe le bon objet ; le nom libre reste
  // en 2e ligne. Cible introuvable (résolveur absent) → prédicat seul.
  const describe = (c: Constraint): string | null => describeConstraint(c, { venueName: (id) => venueNameById.get(id), teamName, coachName, tagLabel });
  // « Tout le club » ne concerne pas l'équipe DIRECTEMENT (fondateur) : on sépare les deux
  // groupes pour que la distinction se lise sans lire. Une CLUB+tag est côté équipe (elle
  // vise les équipes taguées, comme l'éclatement backend), pas côté club.
  const teamConstraints = applicable.filter((c) => !isClubWide(c));
  const clubConstraints = applicable.filter((c) => isClubWide(c));

  return (
    // Borné à la hauteur de l'aside (= celle de la grille) et défile en INTERNE au-delà, au lieu
    // d'étirer la page (retour fondateur : « de la même taille que la grille »). Le contrat
    // flexbox : `min-h-0` sur le conteneur qui défile (`CardContent`), sinon `overflow-y-auto`
    // ne défile jamais — un enfant flex refuse de rétrécir sous son contenu. jsdom ne peut pas
    // l'attester (aucun moteur de layout) : garde de classes ici, effet prouvé en Playwright.
    <Card className="flex min-h-0 flex-col overflow-hidden">
      <CardHeader className="shrink-0 flex-row items-center justify-between">
        <CardTitle className="flex items-center gap-2">
          {cell.teamLabel}
          {cell.locked ? <Lock className="size-4 text-muted-foreground" /> : null}
        </CardTitle>
        <button type="button" onClick={onClose} aria-label="Fermer" className="rounded p-1 text-muted-foreground hover:text-foreground">
          <X className="size-4" />
        </button>
      </CardHeader>
      <CardContent className="min-h-0 flex-1 overflow-y-auto pt-0">
        {/* B1 — une seule ligne discrète sous le titre (au lieu de trois lignes étiquetées).
            Séparateurs « · », aucun libellé sauf le préfixe « Coach » ; un segment vide est
            omis sans « · » orphelin ; wrapping naturel (pas de troncature). Sans coach, le
            préfixe reste et le nom devient une croix rouge (décision fondateur 2026-08-16). */}
        <p className="text-sm text-muted-foreground">
          {[categoryLabel || null, `${slot.durationMinutes} min`].filter(Boolean).join(" · ")}
          {cell.coachLabel ? (
            <>
              {" · Coach "}
              {NO_COACH_LABEL === cell.coachLabel ? <X aria-label={NO_COACH_LABEL} className="inline size-3.5 align-text-bottom text-destructive" /> : cell.coachLabel}
            </>
          ) : null}
        </p>

        {null !== origin ? (
          <div className="mt-3 border-t border-border pt-3">
            <div className="flex items-center gap-2 text-sm font-medium">
              <Lock className="size-4 text-muted-foreground" aria-hidden="true" />
              <span>{origin.label}</span>
            </div>
            <p className="mt-1 text-xs text-muted-foreground">{origin.hint}</p>
          </div>
        ) : null}

        <div className="mt-3 border-t border-border pt-3">
          <button
            type="button"
            aria-expanded={constraintsOpen}
            onClick={() => setConstraintsOpen((open) => !open)}
            className="flex w-full items-center gap-1.5 text-xs font-medium text-muted-foreground hover:text-foreground"
          >
            {constraintsOpen ? <ChevronDown className="size-3.5 shrink-0" aria-hidden="true" /> : <ChevronRight className="size-3.5 shrink-0" aria-hidden="true" />}
            Contraintes applicables ({applicable.length})
          </button>
          {constraintsOpen ? (
            applicable.length > 0 ? (
              // Plus de `max-h`/`overflow` ici : c'est désormais TOUT le panneau (`CardContent`)
              // qui est borné et défile (cf. la Card ci-dessus), donc pas de double ascenseur.
              <div className="mt-2">
                {teamConstraints.length > 0 ? <ConstraintList label="Cette équipe" items={teamConstraints} describe={describe} /> : null}
                {clubConstraints.length > 0 ? <ConstraintList label="Tout le club" items={clubConstraints} describe={describe} /> : null}
              </div>
            ) : (
              <EmptyHint className="mt-1 text-xs">Aucune contrainte spécifique à ce créneau.</EmptyHint>
            )
          ) : null}
        </div>

        {readOnly ? (
          <p className="mt-3 border-t border-border pt-3 text-xs text-muted-foreground">Planning validé (lecture seule). Rouvrez-le pour modifier ce créneau.</p>
        ) : (
        <div className="mt-3 flex flex-col gap-2 border-t border-border pt-3">
          {/* P2-30 (D11) : plus de formulaire jour/heure/gymnase — « Déplacer » ARME le mode
              cible click-click, la cible se choisit sur la grille. Armé, le bouton bascule sur
              une consigne, et une aide « Échap pour annuler » apparaît.
              P2-51 PR-6 (D11) : sur une séance de BLOC, ce bouton devient « Déplacer le groupe »
              (le déplacement d'une seule équipe serait refusé par le moteur — on ne le propose pas).
              L'action reste le MÊME geste click-click (la page l'aiguille vers le rail move-group). */}
          <div className="flex gap-2">
            <Button
              size="sm"
              variant={armed ? "default" : "outline"}
              className="flex-1"
              disabled={busy}
              onClick={onArmMove}
              aria-label={null !== groupSession && !armed ? `Déplacer le groupe, ${groupSession.memberCount} équipes` : undefined}
            >
              {"pending" === moveState.status ? (
                <>
                  <Loader2 className="size-4 animate-spin" aria-hidden="true" />
                  Vérification…
                </>
              ) : (
                <>
                  <Move className="size-4" aria-hidden="true" />
                  {armed ? "Choisir la case cible…" : null !== groupSession ? "Déplacer le groupe" : "Déplacer"}
                </>
              )}
            </Button>
            <Button size="sm" variant={cell.locked ? "default" : "outline"} className="flex-1" disabled={busy} onClick={onToggleLock}>
              {cell.locked ? <LockOpen className="size-4" /> : <Lock className="size-4" />}
              {cell.locked ? "Déverrouiller" : "Verrouiller"}
            </Button>
          </div>

          {/* Sur une séance de bloc, dire AVANT le geste que déplacer cette case bouge TOUT le groupe
              (retour design : la conséquence se révèle au point d'action). Masquée pendant le ciblage. */}
          {null !== groupSession && !armed ? (
            <p className="text-xs text-muted-foreground">Déplace les {groupSession.memberCount} équipes du groupe ensemble.</p>
          ) : null}

          {armed ? (
            <p className="rounded-md border border-accent/40 bg-accent/10 p-2 text-xs text-muted-foreground">
              {null !== groupSession
                ? "Cliquez la case cible de la grille pour y déplacer tout le groupe."
                : "Cliquez une case libre de la grille pour y déplacer ce créneau, ou une séance à évincer."}{" "}
              <span className="font-medium text-foreground">Échap</span> pour annuler.
            </p>
          ) : null}

          {/* Le déplacement passe sous le verdict du moteur (F2b) : ici le résultat du dernier
              essai. On ne le montre que hors « pending » (le bouton porte déjà l'attente). */}
          {"rejected" === moveState.status ? (
            <div className="rounded-md border border-destructive/40 bg-destructive/10 p-2 text-sm" role="alert">
              <div className="flex items-center gap-2 font-medium text-destructive">
                <AlertTriangle className="size-4" aria-hidden="true" />
                <span>Déplacement refusé — le créneau n’a pas bougé.</span>
              </div>
              <ul className="mt-1 flex list-disc flex-col gap-1 pl-6 text-muted-foreground">
                {moveState.violations.map((v, i) => (
                  <li key={`${v.rule}-${i}`}>{v.message}</li>
                ))}
              </ul>
            </div>
          ) : null}

          {"blocked" === moveState.status ? (
            <p className="rounded-md border border-warning/40 bg-warning/10 p-2 text-sm text-muted-foreground" role="alert">
              Une génération est en cours pour ce club — réessayez le déplacement une fois qu’elle est terminée.
            </p>
          ) : null}

          {"interrupted" === moveState.status ? (
            <p className="rounded-md border border-warning/40 bg-warning/10 p-2 text-sm text-muted-foreground" role="alert">
              La vérification a été interrompue avant la réponse — rien n’a été modifié, réessayez.
            </p>
          ) : null}

          {"error" === moveState.status ? (
            <p className="rounded-md border border-warning/40 bg-warning/10 p-2 text-sm text-muted-foreground" role="alert">
              Le moteur n’a pas répondu — rien n’a été modifié, réessayez.
            </p>
          ) : null}
        </div>
        )}
      </CardContent>
    </Card>
  );
}
