import { AlertTriangle, CheckCircle2, ChevronDown, ChevronRight, Info, PanelRightClose, XCircle } from "lucide-react";
import { useState } from "react";

import { Card, CardContent, CardHeader, CardTitle } from "@/shared/components/ui/card";
import { EmptyHint } from "@/shared/components/ui/empty-hint";
import { WizardStepLink } from "@/features/wizard/WizardStepLink";
import { cn } from "@/shared/lib/utils";

import type { Diagnostic, DiagnosticCause, DiagnosticCauseKind, DiagnosticSeverity, Slot } from "./api";
import { SEVERITY_ORDER } from "./lib/diagnosticsSummary";
import { concernedSlots, type Lookups } from "./lib/grid";

const SEVERITY: Record<DiagnosticSeverity, { icon: typeof Info; className: string; label: string }> = {
  ERROR: { icon: XCircle, className: "text-destructive", label: "Erreurs" },
  WARNING: { icon: AlertTriangle, className: "text-warning", label: "Alertes" },
  INFO: { icon: Info, className: "text-muted-foreground", label: "Infos" },
  SUCCESS: { icon: CheckCircle2, className: "text-success", label: "OK" },
};

// Ordre des sévérités : foyer unique dans `lib/diagnosticsSummary` (partagé avec la barre repliée).
const ORDER = SEVERITY_ORDER;

// P4-99 — PRÉSENTATION PURE : traduit le `kind` MESURÉ par le moteur en une phrase lisible.
// Le front n'invente aucune règle métier ; il ne DÉCIDE d'aucun comportement à partir du kind,
// il choisit seulement un libellé (autorisé — cf. `matches/lib/diagnostic.ts`). `Record` sur
// l'union → le compilateur exige les 7 familles ; un kind futur, absent, dégrade au compte seul.
const CAUSE_KIND_LABELS: Record<DiagnosticCauseKind, string> = {
  hard_lock: "un créneau déjà verrouillé",
  venue_forbidden: "un gymnase interdit",
  coach_unavailability: "l'indisponibilité d'un coach",
  time_window: "une plage horaire trop étroite",
  day_conflict: "un conflit de jour",
  day_forbidden: "un jour interdit",
  forced_venue_elsewhere: "un gymnase imposé ailleurs",
};

// Kind inconnu → `null` : on affiche le compte SANS phrase inventée, jamais le code brut.
function causeKindLabel(kind: string): string | null {
  return CAUSE_KIND_LABELS[kind as DiagnosticCauseKind] ?? null;
}

function causeSentence(cause: DiagnosticCause): string {
  const { count, label } = cause;
  const slots = `${count} créneau${count > 1 ? "x" : ""} fermé${count > 1 ? "s" : ""}`;
  const kind = causeKindLabel(cause.kind);
  // Le NOM de la règle est l'information principale — c'est lui qui répond à « quelle
  // contrainte a mangé la séance ? » sans cliquer, et qui distingue deux causes de même
  // famille. La famille reste en complément quand elle est connue.
  if (null !== label && "" !== label) {
    return null === kind ? `${slots} par « ${label} ».` : `${slots} par « ${label} » (${kind}).`;
  }
  // Pas de nom (contrainte sans nom côté payload) → repli sur la famille ; kind inconnu →
  // le compte seul. Jamais « null », jamais le code brut du kind.
  return null === kind ? `${slots}.` : `${slots} par ${kind}.`;
}

// « Resté ouvert » n'est PAS une cause : phrase dédiée, appelée seulement quand n > 0.
function openCandidatesSentence(count: number): string {
  const slots = `${count} créneau${count > 1 ? "x" : ""}`;
  const stayed = count > 1 ? "restaient disponibles" : "restait disponible";
  return `${slots} ${stayed} — le planning y a placé une autre séance.`;
}

interface DiagnosticsPanelProps {
  diagnostics: Diagnostic[];
  slots: Slot[];
  /** Synthetic `vide` cells, so an "unused_slot" warning can highlight them. */
  emptySlots?: Slot[];
  lookups: Lookups;
  onHighlight: (slotIds: Set<string>) => void;
  /** "unused_slot" warning: bring the concerned venue's column on screen. */
  onFocusVenue?: (venueId: string) => void;
  /** `conflict` portant (venue, jour, heure) : ouvre LE créneau fautif (le premier de
   *  `concernedSlots`) sur la grille. Absent → pas d'ouverture (dégradation douce). */
  onOpenSlot?: (slotId: string) => void;
  /** Collapse the panel back to the compact bar (frees grid width). */
  onCollapse?: () => void;
  /** Ouvre d'emblée le groupe le PLUS SÉVÈRE présent (P4-40, contexte wizard).
   *  ⚠ Le panneau est DÉMONTÉ quand on le replie (`PlanningPage`), donc chaque
   *  réouverture ré-amorce : c'est voulu — rouvrir l'aside, c'est redemander à voir les
   *  diagnostics, et ce qu'on doit alors voir est le plus grave. */
  openMostSevere?: boolean;
  /** IDENTITÉ du jeu de diagnostics — en pratique l'id de la version affichée. Changer de
   *  valeur ré-amorce l'ouverture ; garder la même la laisse intacte. */
  seedToken?: string | null;
  /** La lecture des diagnostics est EN COURS — ne pas annoncer un planning propre. */
  pending?: boolean;
}

export function DiagnosticsPanel({ diagnostics, slots, emptySlots = [], lookups, onHighlight, onFocusVenue, onOpenSlot, onCollapse, openMostSevere = false, seedToken = null, pending = false }: DiagnosticsPanelProps) {
  const [openSeverity, setOpenSeverity] = useState<DiagnosticSeverity | null>(null);
  const [activeId, setActiveId] = useState<string | null>(null);

  const groups = ORDER.map((severity) => ({ severity, items: diagnostics.filter((d) => d.severity === severity) })).filter((g) => g.items.length > 0);

  // Second repli (P4-40) : panneau ouvert, les groupes restaient TOUS fermés — un
  // diagnostic était donc encore à deux clics, ce qui vidait de son sens le fait d'ouvrir
  // le panneau. Au sortir du wizard, le groupe le plus sévère présent est déplié.
  // `ORDER` étant trié du plus grave au moins grave, c'est le PREMIER groupe non vide.
  //
  // Ajustement pendant le rendu plutôt qu'un effet : `setState` dans un effet est interdit
  // par le lint React Compiler du dépôt (`react-hooks/set-state-in-effect`), et un
  // `useState(initial)` ne suffirait pas — les diagnostics arrivent APRÈS le premier
  // rendu, quand l'état initial est déjà figé.
  //
  // ⚠ Le ré-amorçage se déclenche sur l'IDENTITÉ de la version (`seedToken`), et non sur un
  // booléen « déjà fait » ni sur la FORME des diagnostics. Deux essais avant celui-ci, tous
  // deux faux, et pour des raisons opposées (revue #350) :
  //  (1) un verrou à un coup ne se réarmait jamais — changer de version remplace les
  //      diagnostics sans démonter le panneau, `openSeverity` désignait alors une sévérité
  //      absente de la nouvelle version, donc AUCUN groupe ouvert ;
  //  (2) une clé « sévérités + cardinalités » se trompait dans les DEUX sens : deux
  //      versions de même forme (1 ERROR + 1 INFO) donnaient la même clé et ne
  //      ré-amorçaient pas, tandis qu'un simple filtre gymnase changeait les cardinalités
  //      et rouvrait un groupe que l'utilisateur venait de fermer.
  // L'appelant CONNAÎT la version affichée : la deviner à partir du contenu était le tort.
  // Sentinelle `undefined`, distincte des jetons possibles (`string | null`) : sans elle,
  // un appelant qui pose `openMostSevere` sans jeton ne déclenchait JAMAIS l'amorce —
  // l'état initial étant déjà égal au jeton absent. Une prop qui ne fait rien en silence
  // est pire que pas de prop.
  const [seededToken, setSeededToken] = useState<string | null | undefined>(undefined);
  if (openMostSevere && groups.length > 0 && seededToken !== seedToken) {
    setSeededToken(seedToken);
    setOpenSeverity(groups[0].severity);
    // Le surlignage de la grille appartenait au diagnostic de la version PRÉCÉDENTE : le
    // laisser en place désignerait des créneaux qui ne le concernent plus.
    setActiveId(null);
    onHighlight(new Set());
  }

  function toggleGroup(severity: DiagnosticSeverity) {
    setOpenSeverity((current) => (current === severity ? null : severity));
    setActiveId(null);
    onHighlight(new Set());
  }

  function selectDiagnostic(diagnostic: Diagnostic) {
    if (activeId === diagnostic.id) {
      setActiveId(null);
      onHighlight(new Set());
      return;
    }
    setActiveId(diagnostic.id);
    // The solver's "unused_slot" warning points at a defined-but-unfilled window:
    // highlight the matching `vide` cell(s) and bring their venue column on screen.
    if ("unused_slot" === diagnostic.type && null !== diagnostic.venueId) {
      onHighlight(new Set(emptySlots.filter((s) => s.venueId === diagnostic.venueId).map((s) => s.id)));
      onFocusVenue?.(diagnostic.venueId);
      return;
    }
    // Un `conflict` qui PORTE (jour, heure) + un discriminant : `concernedSlots` resserre sur les
    // créneaux fautifs de cet instant. On les surligne toujours ; on n'en OUVRE un que s'ils
    // tiennent dans UNE case (même gymnase/jour/heure) — la sur-capacité d'un gymnase, où ouvrir
    // « le premier » désigne bien le lieu du problème.
    //
    // ⚠ P4-95 (décision fondateur 2026-08-29) — un conflit de COACH s'étale sur DEUX cases, dans
    // deux gymnases : on surligne les deux et on n'ouvre RIEN. En ouvrir une désignerait
    // arbitrairement le créneau à déplacer, alors que l'arbitrage appartient au gestionnaire — et
    // ouvrir un panneau masquerait justement l'autre moitié du choc.
    if ("conflict" === diagnostic.type && null !== diagnostic.dayOfWeek && null !== diagnostic.startTime) {
      const concerned = concernedSlots(diagnostic, slots, lookups);
      onHighlight(new Set(concerned.map((c) => c.slotId)));
      const cells = new Set(
        concerned
          .map((c) => slots.find((s) => s.id === c.slotId))
          .filter((s): s is (typeof slots)[number] => undefined !== s)
          .map((s) => `${s.venueId}|${s.dayOfWeek}|${s.startTime}`),
      );
      const first = concerned[0]?.slotId;
      if (1 === cells.size && undefined !== first) {
        onOpenSlot?.(first);
      }
      return;
    }
    onHighlight(new Set(concernedSlots(diagnostic, slots, lookups).map((c) => c.slotId)));
  }

  return (
    <Card className="flex h-full min-h-0 flex-col">
      <CardHeader className="shrink-0 flex-row items-center justify-between gap-2 pb-3">
        <CardTitle className="text-base">Diagnostics du système</CardTitle>
        {onCollapse ? (
          <button type="button" onClick={onCollapse} className="rounded-md p-1 text-muted-foreground hover:bg-muted hover:text-foreground" aria-label="Réduire les diagnostics" title="Réduire (plus de place pour la grille)">
            <PanelRightClose className="size-4" />
          </button>
        ) : null}
      </CardHeader>
      <CardContent className="min-h-0 flex-1 overflow-y-auto pt-0">
        {0 === diagnostics.length ? (
          // ⚠ Charger n'est pas être propre. Le panneau étant désormais OUVERT d'emblée au
          // sortir du wizard, cet état vide est ce qu'on lit pendant le chargement d'une
          // version — annoncer « le planning est propre » y serait une affirmation fausse,
          // et rassurante (revue #350).
          <EmptyHint>{pending ? "Lecture des diagnostics…" : "Aucun diagnostic — le planning est propre."}</EmptyHint>
        ) : (
          <div className="flex flex-col gap-1">
            {groups.map((group) => {
              const meta = SEVERITY[group.severity];
              const Icon = meta.icon;
              const open = openSeverity === group.severity;
              return (
                <div key={group.severity} className="rounded-md border border-border">
                  <button type="button" onClick={() => toggleGroup(group.severity)} className="flex w-full items-center gap-2 px-2 py-1.5 text-left text-sm hover:bg-muted">
                    {open ? <ChevronDown className="size-4 shrink-0" /> : <ChevronRight className="size-4 shrink-0" />}
                    <Icon className={cn("size-4 shrink-0", meta.className)} />
                    <span className="flex-1 font-medium">{meta.label}</span>
                    <span className="rounded-full bg-muted px-1.5 text-xs text-muted-foreground">{group.items.length}</span>
                  </button>
                  {open ? (
                    <ul className="flex flex-col border-t border-border">
                      {group.items.map((item) => (
                        <li key={item.id} className="flex flex-col">
                          <button
                            type="button"
                            onClick={() => selectDiagnostic(item)}
                            className={cn(
                              "w-full px-3 py-1.5 text-left text-xs text-muted-foreground transition hover:bg-muted",
                              item.id === activeId ? "bg-muted text-foreground" : "",
                            )}
                          >
                            {item.message}
                          </button>
                          {/* P2-28 — un diagnostic « règle assouplie » mène à SON réglage : l'onglet
                              Contraintes du wizard, ouvert sur la règle visée (`?rule=<ruleKey>`).
                              Retour nommé « ← Retour au planning ». */}
                          {"implicit_rule_not_honored" === item.type && null !== item.ruleKey ? (
                            <WizardStepLink
                              step="constraints"
                              params={{ rule: item.ruleKey }}
                              from="planning"
                              className="self-start px-3 pb-1.5 text-xs font-medium text-accent underline underline-offset-2 hover:text-accent/80"
                            >
                              Ajuster cette règle
                            </WizardStepLink>
                          ) : null}
                          {/* P4-99 — la CAUSE mesurée d'une séance manquante. Une ligne par cause
                              (libellé du kind + compte) ; un `constraintId` connu mène à SA
                              contrainte (`?step=constraints&edit=<id>`, rail ConstraintsStep). Pas
                              d'id → pas de lien (jamais de lien mort). `openCandidates > 0` a sa
                              propre phrase : des créneaux étaient DISPONIBLES, pas une cause. */}
                          {"session_below_effective_min" === item.type ? (
                            <div className="flex flex-col gap-1 px-3 pb-1.5">
                              {item.causes.map((cause, index) => (
                                <div key={`${cause.kind}-${cause.constraintId ?? "none"}-${index}`} className="flex flex-col gap-0.5">
                                  <span className="text-xs text-muted-foreground">{causeSentence(cause)}</span>
                                  {null !== cause.constraintId ? (
                                    <WizardStepLink
                                      step="constraints"
                                      params={{ edit: cause.constraintId }}
                                      from="planning"
                                      className="self-start text-xs font-medium text-accent underline underline-offset-2 hover:text-accent/80"
                                    >
                                      Corriger cette règle
                                    </WizardStepLink>
                                  ) : null}
                                </div>
                              ))}
                              {null !== item.openCandidates && item.openCandidates > 0 ? (
                                <span className="text-xs italic text-muted-foreground">{openCandidatesSentence(item.openCandidates)}</span>
                              ) : null}
                            </div>
                          ) : null}
                        </li>
                      ))}
                    </ul>
                  ) : null}
                </div>
              );
            })}
          </div>
        )}
      </CardContent>
    </Card>
  );
}
