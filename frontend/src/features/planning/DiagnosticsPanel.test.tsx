import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { describe, expect, it, vi } from "vitest";

import { renderWithProviders } from "@/test/utils";

import type { Diagnostic, DiagnosticSeverity, Slot } from "./api";
import { DiagnosticsPanel } from "./DiagnosticsPanel";
import type { Lookups } from "./lib/grid";

const lookups: Lookups = {
  teams: new Map(),
  venues: new Map(),
  coaches: new Map(),
  teamCoach: new Map(),
  teamPlayerCoaches: new Map(),
};

const diag = (severity: DiagnosticSeverity, message: string): Diagnostic =>
  ({ id: `${severity}-${message}`, scheduleId: "s", type: "generic", severity, teamId: null, coachId: null, venueId: null, message, suggestions: null }) as Diagnostic;

const panel = (props: Partial<Parameters<typeof DiagnosticsPanel>[0]> = {}) => (
  <DiagnosticsPanel diagnostics={[]} slots={[]} lookups={lookups} onHighlight={vi.fn()} {...props} />
);

/**
 * P4-40 — LE SECOND REPLI.
 *
 * Ouvrir le panneau ne suffisait pas : ses groupes par sévérité restaient TOUS fermés, si
 * bien qu'un diagnostic était encore à deux clics. Le retour terrain visait la visibilité
 * (« on risque de ne pas le voir si on n'est pas familier avec l'écran génération ») —
 * un panneau ouvert sur des groupes fermés ne la donne pas.
 */
describe("DiagnosticsPanel — ouverture contextuelle", () => {
  it("déplie le groupe le PLUS SÉVÈRE présent quand on sort du wizard", () => {
    render(panel({ diagnostics: [diag("INFO", "info lisible"), diag("ERROR", "erreur lisible")], openMostSevere: true }));

    // Le message de l'erreur est lisible sans le moindre clic…
    expect(screen.getByText("erreur lisible")).toBeInTheDocument();
    // …et le groupe INFO reste replié : ouvrir TOUT ferait un mur.
    expect(screen.queryByText("info lisible")).not.toBeInTheDocument();
  });

  it("déplie le plus sévère PRÉSENT, pas ERROR par principe", () => {
    render(panel({ diagnostics: [diag("INFO", "info lisible"), diag("WARNING", "alerte lisible")], openMostSevere: true }));

    expect(screen.getByText("alerte lisible")).toBeInTheDocument();
    expect(screen.queryByText("info lisible")).not.toBeInTheDocument();
  });

  it("RÉ-AMORCE quand la VERSION change, même à forme identique", () => {
    // LE défaut du round 2. Le premier correctif comparait « sévérités + cardinalités » :
    // deux versions de même forme (1 ERROR + 1 INFO) produisaient la MÊME clé, donc aucun
    // ré-amorçage — le groupe INFO que l'utilisateur avait ouvert sur V1 restait ouvert et
    // l'ERREUR de V2 restait fermée. Deviner l'identité à partir du contenu était le tort :
    // l'appelant la connaît.
    const v1 = [diag("ERROR", "erreur V1"), diag("INFO", "info V1")];
    const v2 = [diag("ERROR", "erreur V2"), diag("INFO", "info V2")];
    const { rerender } = render(panel({ diagnostics: v1, openMostSevere: true, seedToken: "v1" }));
    expect(screen.getByText("erreur V1")).toBeInTheDocument();

    rerender(panel({ diagnostics: v2, openMostSevere: true, seedToken: "v2" }));

    expect(screen.getByText("erreur V2")).toBeInTheDocument();
  });

  it("ne ré-amorce PAS quand seul le filtre change à version constante", () => {
    // Le pendant, et l'autre moitié du défaut : la clé de forme changeait quand un filtre
    // gymnase retirait des lignes, si bien qu'un simple glisser-déposer rouvrait sous le
    // curseur un groupe que l'utilisateur venait de fermer. À version constante, l'amorce
    // ne rejoue pas.
    const complet = [diag("ERROR", "erreur filtrable"), diag("WARNING", "alerte restante")];
    const { rerender } = render(panel({ diagnostics: complet, openMostSevere: true, seedToken: "v1" }));
    expect(screen.getByText("erreur filtrable")).toBeInTheDocument();

    // Le filtre retire les ERREURS : la FORME change, la version non.
    rerender(panel({ diagnostics: [diag("WARNING", "alerte restante")], openMostSevere: true, seedToken: "v1" }));

    // Le groupe ALERTES ne s'est pas ouvert tout seul.
    expect(screen.queryByText("alerte restante")).not.toBeInTheDocument();
  });

  it("ne DÉFAIT pas le repli manuel d'un groupe", async () => {
    // Le pendant du test ci-dessus : ré-amorcer sur tout changement rouvrirait ce que
    // l'utilisateur vient de fermer. La clé porte l'identité des diagnostics, pas le
    // nombre de rendus — refermer un groupe ne la change pas.
    const user = userEvent.setup();
    render(panel({ diagnostics: [diag("ERROR", "erreur lisible")], openMostSevere: true }));
    expect(screen.getByText("erreur lisible")).toBeInTheDocument();

    await user.click(screen.getByRole("button", { name: /Erreurs/ }));

    expect(screen.queryByText("erreur lisible")).not.toBeInTheDocument();
  });

  it("ne déplie RIEN en boucle de travail — la demande utilisateur d'origine ne bouge pas", () => {
    render(panel({ diagnostics: [diag("ERROR", "erreur lisible")] }));

    expect(screen.queryByText("erreur lisible")).not.toBeInTheDocument();
  });
});

describe("DiagnosticsPanel — un conflict OUVRE le créneau fautif (P4-95)", () => {
  const slot = (over: Partial<Slot>): Slot => ({
    id: "id",
    scheduleId: "s",
    teamId: "t1",
    venueId: "v1",
    coachId: null,
    dayOfWeek: 6,
    startTime: "10:00:00",
    durationMinutes: 90,
    lockLevel: "NONE",
    lockOrigin: null,
    ...over,
  });

  const conflict = (over: Partial<Diagnostic> = {}): Diagnostic =>
    ({ id: "cf", scheduleId: "s", type: "conflict", severity: "ERROR", teamId: null, coachId: null, venueId: "v1", dayOfWeek: 6, startTime: "10:00", message: "conflit gymnase", suggestions: null, ...over }) as Diagnostic;

  const slots = [
    slot({ id: "a", teamId: "t1", venueId: "v1", dayOfWeek: 6, startTime: "10:00:00" }),
    slot({ id: "b", teamId: "t2", venueId: "v1", dayOfWeek: 6, startTime: "10:00:00" }),
    slot({ id: "c", teamId: "t1", venueId: "v1", dayOfWeek: 6, startTime: "18:00:00" }),
  ];

  const lastSet = (fn: ReturnType<typeof vi.fn>): string[] => [...(fn.mock.calls.at(-1)?.[0] as Set<string>)].sort();

  it("clic sur un conflict complet → onOpenSlot(LE créneau) + surlignage resserré", async () => {
    const user = userEvent.setup();
    const onOpenSlot = vi.fn();
    const onHighlight = vi.fn();
    render(panel({ diagnostics: [conflict()], slots, onOpenSlot, onHighlight, openMostSevere: true, seedToken: "v1" }));

    await user.click(screen.getByText("conflit gymnase"));

    // Resserré sur les deux séances de (v1, samedi, 10:00) — pas la séance de 18:00.
    expect(lastSet(onHighlight)).toEqual(["a", "b"]);
    // Ouvre LE premier (ordre jour+heure de concernedSlots).
    expect(onOpenSlot).toHaveBeenCalledWith("a");
  });

  it("P4-95 — un conflit de COACH surligne les DEUX cases et n'en ouvre AUCUNE", async () => {
    // Décision fondateur (2026-08-29) : le choc s'étale sur deux gymnases, l'arbitrage
    // « laquelle je déplace » appartient au gestionnaire. En ouvrir une le déciderait à sa
    // place, et le panneau masquerait l'autre moitié du choc.
    const user = userEvent.setup();
    const onOpenSlot = vi.fn();
    const onHighlight = vi.fn();
    const clashSlots = [
      slot({ id: "ca", teamId: "t1", venueId: "v1", coachId: "c1", dayOfWeek: 3, startTime: "18:00:00" }),
      slot({ id: "cb", teamId: "t2", venueId: "v2", coachId: "c1", dayOfWeek: 3, startTime: "18:00:00" }),
      slot({ id: "cc", teamId: "t1", venueId: "v1", coachId: "c1", dayOfWeek: 5, startTime: "18:00:00" }),
    ];
    // `diag-conflict-coach-*` : coach + jour + heure, JAMAIS de gymnase.
    const coachClash = conflict({ venueId: null, coachId: "c1", dayOfWeek: 3, startTime: "18:00", message: "coach dédoublé" });
    render(panel({ diagnostics: [coachClash], slots: clashSlots, onOpenSlot, onHighlight, openMostSevere: true, seedToken: "v1" }));

    await user.click(screen.getByText("coach dédoublé"));

    // Les deux créneaux du choc — et pas la séance du jeudi.
    expect(lastSet(onHighlight)).toEqual(["ca", "cb"]);
    expect(onOpenSlot).not.toHaveBeenCalled();
  });

  it("un conflict SANS jour/heure n'ouvre rien (dégradation douce), garde le surlignage large", async () => {
    const user = userEvent.setup();
    const onOpenSlot = vi.fn();
    const onHighlight = vi.fn();
    render(panel({ diagnostics: [conflict({ dayOfWeek: null, startTime: null })], slots, onOpenSlot, onHighlight, openMostSevere: true, seedToken: "v1" }));

    await user.click(screen.getByText("conflit gymnase"));

    expect(onOpenSlot).not.toHaveBeenCalled();
    // Correspondance gymnase large : les trois séances de v1.
    expect(lastSet(onHighlight)).toEqual(["a", "b", "c"]);
  });

  it("re-cliquer DÉSÉLECTIONNE (vide le surlignage), sans ré-ouvrir", async () => {
    const user = userEvent.setup();
    const onOpenSlot = vi.fn();
    const onHighlight = vi.fn();
    render(panel({ diagnostics: [conflict()], slots, onOpenSlot, onHighlight, openMostSevere: true, seedToken: "v1" }));

    await user.click(screen.getByText("conflit gymnase"));
    onOpenSlot.mockClear();
    await user.click(screen.getByText("conflit gymnase"));

    expect(lastSet(onHighlight)).toEqual([]);
    expect(onOpenSlot).not.toHaveBeenCalled();
  });
});

describe("DiagnosticsPanel — l'état vide ne ment pas", () => {
  it("dit qu'il LIT tant que la lecture est en vol, jamais que le planning est propre", () => {
    // Le panneau étant ouvert d'emblée au sortir du wizard, son état vide est ce qu'on lit
    // PENDANT le chargement d'une version. « Le planning est propre » y serait une
    // affirmation fausse — et rassurante (revue #350 round 2, doctrine `readState`).
    render(panel({ diagnostics: [], pending: true }));

    expect(screen.getByText(/Lecture des diagnostics/)).toBeInTheDocument();
    expect(screen.queryByText(/planning est propre/)).not.toBeInTheDocument();
  });

  it("annonce un planning propre une fois la lecture faite", () => {
    render(panel({ diagnostics: [] }));

    expect(screen.getByText(/planning est propre/)).toBeInTheDocument();
  });
});

/**
 * P2-28 — la boucle S1 : un diagnostic « règle assouplie » (`implicit_rule_not_honored`) porte
 * un lien « Ajuster cette règle » vers l'onglet Contraintes du wizard, ciblant SA règle par
 * `ruleKey`. Le lien passe par WizardStepLink (Router + auth) : on rend donc avec les providers.
 */
describe("DiagnosticsPanel — lien « Ajuster cette règle » (P2-28)", () => {
  const softened = (over: Partial<Diagnostic> = {}): Diagnostic =>
    ({
      id: "ir",
      scheduleId: "s",
      type: "implicit_rule_not_honored",
      severity: "WARNING",
      teamId: null,
      coachId: null,
      venueId: null,
      dayOfWeek: null,
      startTime: null,
      ruleKey: "coachRestDay",
      message: "Le repos des coachs a été assoupli",
      suggestions: null,
      ...over,
    }) as Diagnostic;

  it("porte un lien vers ?step=constraints&rule=<ruleKey>&from=planning", () => {
    renderWithProviders(<DiagnosticsPanel diagnostics={[softened()]} slots={[]} lookups={lookups} onHighlight={vi.fn()} openMostSevere />);

    const link = screen.getByRole("link", { name: "Ajuster cette règle" });
    const href = link.getAttribute("href") ?? "";
    expect(href).toContain("step=constraints");
    expect(href).toContain("rule=coachRestDay");
    expect(href).toContain("from=planning");
  });

  it("aucun lien quand le diagnostic ne porte pas de ruleKey", () => {
    renderWithProviders(<DiagnosticsPanel diagnostics={[softened({ ruleKey: null })]} slots={[]} lookups={lookups} onHighlight={vi.fn()} openMostSevere />);

    expect(screen.queryByRole("link", { name: "Ajuster cette règle" })).toBeNull();
  });

  it("aucun lien sur un diagnostic d'un autre type (le lien est réservé aux règles assouplies)", () => {
    renderWithProviders(<DiagnosticsPanel diagnostics={[diag("WARNING", "autre alerte")]} slots={[]} lookups={lookups} onHighlight={vi.fn()} openMostSevere />);

    expect(screen.queryByRole("link", { name: "Ajuster cette règle" })).toBeNull();
  });
});

/**
 * P4-99 PR-3 — la CAUSE d'une séance manquante (`session_below_effective_min`) s'affiche et
 * devient cliquable. Le moteur a MESURÉ la cause (contrat 2.8) ; le front l'AFFICHE, il ne la
 * recalcule pas. Chaque cause identifiée par un `constraintId` mène à SA contrainte
 * (`?step=constraints&edit=<id>`, le rail existe déjà — ConstraintsStep). `openCandidates` porte
 * l'info qui a manqué au fondateur le 15 août : des créneaux étaient DISPONIBLES, le planning a
 * placé autre chose — ce n'est pas une cause, elle a sa propre phrase.
 */
describe("DiagnosticsPanel — la cause d'une séance manquante (P4-99)", () => {
  const below = (over: Partial<Diagnostic> = {}): Diagnostic =>
    ({
      id: "sb",
      scheduleId: "s",
      type: "session_below_effective_min",
      severity: "WARNING",
      teamId: "t1",
      coachId: null,
      venueId: null,
      dayOfWeek: null,
      startTime: null,
      ruleKey: null,
      message: "Séance manquante pour SM1",
      suggestions: null,
      causes: [],
      openCandidates: null,
      ...over,
    }) as Diagnostic;

  const render1 = (diagnostic: Diagnostic) =>
    renderWithProviders(<DiagnosticsPanel diagnostics={[diagnostic]} slots={[]} lookups={lookups} onHighlight={vi.fn()} openMostSevere />);

  it("une cause avec constraintId : le NOM de la règle se lit sans cliquer + lien vers ?edit=<id>", () => {
    render1(below({ causes: [{ kind: "time_window", constraintId: "c-123", label: "Fenêtre ligue U13", count: 8 }] }));

    const link = screen.getByRole("link", { name: "Corriger cette règle" });
    const href = link.getAttribute("href") ?? "";
    expect(href).toContain("step=constraints");
    expect(href).toContain("edit=c-123");
    expect(href).toContain("from=planning");
    // Le NOM de la règle est l'information principale — celle qui répond à « quelle contrainte
    // a mangé la séance ? » sans cliquer ; le compte accompagne, l'identifiant interne jamais.
    expect(screen.getByText(/Fenêtre ligue U13/)).toBeInTheDocument();
    expect(screen.getByText(/8 créneaux fermés/)).toBeInTheDocument();
    expect(screen.queryByText(/c-123/)).toBeNull();
  });

  it("deux causes de même kind mais règles différentes restent DISTINGUABLES (le nom, pas la famille)", () => {
    // LE cas qui vide le lot de son sens si on n'affiche que la famille : trois `time_window`
    // issues de trois règles rendraient trois lignes identiques, aux liens divergents.
    render1(
      below({
        causes: [
          { kind: "time_window", constraintId: "c-1", label: "Fenêtre ligue U13", count: 8 },
          { kind: "time_window", constraintId: "c-2", label: "Fenêtre ligue U15", count: 2 },
        ],
      }),
    );

    // Les deux NOMS se lisent — sans eux, deux lignes « une plage horaire » indiscernables.
    expect(screen.getByText(/Fenêtre ligue U13/)).toBeInTheDocument();
    expect(screen.getByText(/Fenêtre ligue U15/)).toBeInTheDocument();
    // Et chaque ligne mène bien à SA contrainte.
    const hrefs = screen.getAllByRole("link", { name: "Corriger cette règle" }).map((l) => l.getAttribute("href") ?? "");
    expect(hrefs.some((h) => h.includes("edit=c-1"))).toBe(true);
    expect(hrefs.some((h) => h.includes("edit=c-2"))).toBe(true);
  });

  it("une cause SANS nom (label null) : repli sur la famille, jamais « null », et sans lien si pas d'id", () => {
    render1(below({ causes: [{ kind: "coach_unavailability", constraintId: null, label: null, count: 3 }] }));

    expect(screen.getByText(/3 créneaux fermés par l'indisponibilité d'un coach/)).toBeInTheDocument();
    expect(screen.queryByText(/null/)).toBeNull();
    expect(screen.queryByRole("link", { name: "Corriger cette règle" })).toBeNull();
  });

  it("openCandidates > 0 dit que des créneaux étaient DISPONIBLES (pas une cause, sa phrase à part)", () => {
    render1(below({ causes: [], openCandidates: 5 }));

    expect(screen.getByText(/5 créneaux restaient disponibles/)).toBeInTheDocument();
  });

  it("openCandidates === 0 (rien n'est resté ouvert) n'affiche PAS la phrase", () => {
    render1(below({ openCandidates: 0 }));

    expect(screen.queryByText(/disponible/)).toBeNull();
  });

  it("openCandidates === null (non mesuré) n'affiche PAS la phrase", () => {
    render1(below({ openCandidates: null }));

    expect(screen.queryByText(/disponible/)).toBeNull();
  });

  it("un kind inconnu (donnée future) ne casse pas le rendu — le compte, jamais le code brut", () => {
    render1(below({ causes: [{ kind: "future_kind_xyz", constraintId: null, label: null, count: 4 }] }));

    // Le diagnostic reste rendu (pas de crash)…
    expect(screen.getByText("Séance manquante pour SM1")).toBeInTheDocument();
    // …le compte s'affiche sans phrase inventée…
    expect(screen.getByText(/4 créneaux fermés/)).toBeInTheDocument();
    // …et jamais le code brut du kind.
    expect(screen.queryByText(/future_kind_xyz/)).toBeNull();
  });

  it("aucun lien « Corriger cette règle » sur un autre type de diagnostic", () => {
    render1(diag("WARNING", "autre alerte"));

    expect(screen.queryByRole("link", { name: "Corriger cette règle" })).toBeNull();
  });
});
