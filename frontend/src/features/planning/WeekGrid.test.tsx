import { fireEvent, render, screen } from "@testing-library/react";
import { describe, expect, it, vi } from "vitest";
import { axe } from "vitest-axe";

import type { Coach, Slot, Team, Venue } from "./api";
import { buildGrid, type Lookups } from "./lib/grid";
import { WeekGrid } from "./WeekGrid";

const lookups: Lookups = {
  teams: new Map<string, Team>([["t1", { id: "t1", name: "U11", sportCategoryId: "c", priorityTierId: 1, tierOrder: 0, sessionsPerWeek: 2 }]]),
  venues: new Map<string, Venue>([["v1", { id: "v1", name: "Gymnase Alpha", color: "#00aa00" }]]),
  coaches: new Map<string, Coach>(),
  teamCoach: new Map<string, string>(),
  teamPlayerCoaches: new Map<string, string[]>(),
};

const slot: Slot = {
  id: "a",
  scheduleId: "s",
  teamId: "t1",
  venueId: "v1",
  coachId: null,
  dayOfWeek: 1,
  startTime: "18:00:00",
  durationMinutes: 90,
  lockLevel: "NONE",
  lockOrigin: null,
};

describe("WeekGrid", () => {
  it("renders day headers, resource and slot; fires selection on click", () => {
    const onSelect = vi.fn();
    const model = buildGrid([slot], "gymnase", lookups);
    const { container } = render(<WeekGrid model={model} selectedSlotId={null} onSelectSlot={onSelect} />);

    expect(screen.getByText("Lun")).toBeInTheDocument();
    // Only Monday has a slot → only its used gymnase column is rendered (empty columns hidden).
    expect(screen.getAllByText("Gymnase Alpha")).toHaveLength(1);

    const cell = screen.getByText("U11");
    cell.click();
    expect(onSelect).toHaveBeenCalledWith("a");
    // P4-95 — la cellule porte son `data-slot-id` pour que « ouvrir le créneau fautif » puisse
    // le retrouver et le scroller à l'écran.
    expect(container.querySelector('[data-slot-id="a"]')).not.toBeNull();
  });

  it("fusionne un créneau mutualisé sous son libellé, chaque équipe restant cliquable (P2-17 D4)", async () => {
    const mergeLookups: Lookups = {
      teams: new Map<string, Team>([
        ["t1", { id: "t1", name: "U11", sportCategoryId: "c", priorityTierId: 1, tierOrder: 0, sessionsPerWeek: 2 }],
        ["t2", { id: "t2", name: "U13", sportCategoryId: "c", priorityTierId: 2, tierOrder: 0, sessionsPerWeek: 2 }],
      ]),
      venues: new Map<string, Venue>([["v1", { id: "v1", name: "Gymnase Alpha", color: "#00aa00" }]]),
      coaches: new Map<string, Coach>(),
      teamCoach: new Map<string, string>(),
      teamPlayerCoaches: new Map<string, string[]>(),
      groupLabels: new Map<string, string>([["v1|1|1080", "CEC3"]]),
    };
    const slots: Slot[] = [
      { ...slot, id: "s1", teamId: "t1" },
      { ...slot, id: "s2", teamId: "t2" },
    ];
    const onSelect = vi.fn();
    const model = buildGrid(slots, "gymnase", mergeLookups);
    const { container } = render(<WeekGrid model={model} selectedSlotId={null} onSelectSlot={onSelect} />);

    // Le libellé titre la carte ; les deux équipes y sont listées.
    expect(screen.getByText("CEC3")).toBeInTheDocument();
    // Chaque équipe est un bouton propre → clic = sélection de SA séance.
    screen.getByRole("button", { name: /U13/ }).click();
    expect(onSelect).toHaveBeenCalledWith("s2");
    // P4-95 — chaque membre d'une carte fusionnée porte AUSSI son `data-slot-id`.
    expect(container.querySelector('[data-slot-id="s1"]')).not.toBeNull();
    expect(container.querySelector('[data-slot-id="s2"]')).not.toBeNull();
    expect(await axe(container)).toHaveNoViolations();
  });

  describe("cadenas sur la carte (toggle en un clic)", () => {
    it("expose un cadenas nommant l'équipe qui bascule le verrou SANS ouvrir le panneau", async () => {
      const onSelect = vi.fn();
      const onToggleLock = vi.fn();
      const model = buildGrid([slot], "gymnase", lookups);
      const { container } = render(<WeekGrid model={model} selectedSlotId={null} onSelectSlot={onSelect} onToggleLock={onToggleLock} />);

      const lock = screen.getByRole("button", { name: "Verrouiller U11" });
      lock.click();
      expect(onToggleLock).toHaveBeenCalledWith("a");
      // Un clic sur le cadenas ne sélectionne PAS le créneau (le cadenas est un bouton frère,
      // pas un bouton dans un bouton).
      expect(onSelect).not.toHaveBeenCalled();
      expect(await axe(container)).toHaveNoViolations();
    });

    it("nomme « Déverrouiller <équipe> » quand le créneau est verrouillé", () => {
      const onToggleLock = vi.fn();
      const model = buildGrid([{ ...slot, lockLevel: "HARD" }], "gymnase", lookups);
      render(<WeekGrid model={model} selectedSlotId={null} onSelectSlot={vi.fn()} onToggleLock={onToggleLock} />);

      expect(screen.getByRole("button", { name: "Déverrouiller U11" })).toBeInTheDocument();
    });

    it("cliquer la CARTE sélectionne le créneau, sans déclencher le verrou", () => {
      const onSelect = vi.fn();
      const onToggleLock = vi.fn();
      const model = buildGrid([slot], "gymnase", lookups);
      render(<WeekGrid model={model} selectedSlotId={null} onSelectSlot={onSelect} onToggleLock={onToggleLock} />);

      screen.getByText("U11").click();
      expect(onSelect).toHaveBeenCalledWith("a");
      expect(onToggleLock).not.toHaveBeenCalled();
    });

    it("lecture seule (pas d'onToggleLock) : aucun cadenas cliquable, même verrouillé", () => {
      const model = buildGrid([{ ...slot, lockLevel: "HARD" }], "gymnase", lookups);
      render(<WeekGrid model={model} selectedSlotId={null} onSelectSlot={vi.fn()} />);

      expect(screen.queryByRole("button", { name: /Déverrouiller/ })).not.toBeInTheDocument();
      expect(screen.queryByRole("button", { name: /Verrouiller/ })).not.toBeInTheDocument();
    });

    it("carte fusionnée : un cadenas par membre, nommant SON équipe", () => {
      const mergeLookups: Lookups = {
        teams: new Map<string, Team>([
          ["t1", { id: "t1", name: "U11", sportCategoryId: "c", priorityTierId: 1, tierOrder: 0, sessionsPerWeek: 2 }],
          ["t2", { id: "t2", name: "U13", sportCategoryId: "c", priorityTierId: 2, tierOrder: 0, sessionsPerWeek: 2 }],
        ]),
        venues: new Map<string, Venue>([["v1", { id: "v1", name: "Gymnase Alpha", color: "#00aa00" }]]),
        coaches: new Map<string, Coach>(),
        teamCoach: new Map<string, string>(),
        teamPlayerCoaches: new Map<string, string[]>(),
        groupLabels: new Map<string, string>([["v1|1|1080", "CEC3"]]),
      };
      const slots: Slot[] = [
        { ...slot, id: "s1", teamId: "t1" },
        { ...slot, id: "s2", teamId: "t2" },
      ];
      const onToggleLock = vi.fn();
      const model = buildGrid(slots, "gymnase", mergeLookups);
      render(<WeekGrid model={model} selectedSlotId={null} onSelectSlot={vi.fn()} onToggleLock={onToggleLock} />);

      screen.getByRole("button", { name: "Verrouiller U13" }).click();
      expect(onToggleLock).toHaveBeenCalledWith("s2");
    });
  });

  describe("lentille verrous (lockLens, PR 3)", () => {
    // Trois créneaux : un manuel, une réservation, un libre — trois jours distincts pour
    // trois colonnes séparées (vue gymnase, même gymnase).
    const lensSlots: Slot[] = [
      { ...slot, id: "manual", dayOfWeek: 1, lockLevel: "HARD", lockOrigin: "MANUAL" },
      { ...slot, id: "reserv", dayOfWeek: 2, lockLevel: "HARD", lockOrigin: "RESERVATION" },
      { ...slot, id: "free", dayOfWeek: 3, lockLevel: "NONE", lockOrigin: null },
    ];

    it("estompe les créneaux SANS verrou et cercle les verrouillés par catégorie", () => {
      const model = buildGrid(lensSlots, "gymnase", lookups);
      const { container } = render(<WeekGrid model={model} selectedSlotId={null} onSelectSlot={vi.fn()} lockLens />);

      // Non verrouillé → estompé (lentille).
      expect(container.querySelector('[data-slot-id="free"]')?.className).toContain("opacity-40");
      // Verrouillés → anneau de leur catégorie (« ring-2 ring-* », distinct du hover base
      // « hover:ring-accent »), jamais estompés.
      expect(container.querySelector('[data-slot-id="manual"]')?.className).toContain("ring-2 ring-accent");
      expect(container.querySelector('[data-slot-id="manual"]')?.className).not.toContain("opacity-40");
      expect(container.querySelector('[data-slot-id="reserv"]')?.className).toContain("ring-warning");
    });

    it("donne un style ET une icône DISTINCTS à MANUAL / RESERVATION / UNKNOWN (jamais la couleur seule)", () => {
      const slots: Slot[] = [
        { ...slot, id: "manual", dayOfWeek: 1, lockLevel: "HARD", lockOrigin: "MANUAL" },
        { ...slot, id: "reserv", dayOfWeek: 2, lockLevel: "HARD", lockOrigin: "RESERVATION" },
        { ...slot, id: "unknown", dayOfWeek: 3, lockLevel: "HARD", lockOrigin: "UNKNOWN" },
      ];
      const model = buildGrid(slots, "gymnase", lookups);
      const { container } = render(<WeekGrid model={model} selectedSlotId={null} onSelectSlot={vi.fn()} lockLens />);

      expect(container.querySelector('[data-slot-id="manual"]')?.className).toContain("ring-2 ring-accent");
      expect(container.querySelector('[data-slot-id="reserv"]')?.className).toContain("ring-warning");
      expect(container.querySelector('[data-slot-id="unknown"]')?.className).toContain("ring-muted-foreground");
      // Une icône par catégorie (pas la couleur seule) — repérée par son data-lens.
      expect(container.querySelector('[data-lens="MANUAL"]')).not.toBeNull();
      expect(container.querySelector('[data-lens="RESERVATION"]')).not.toBeNull();
      expect(container.querySelector('[data-lens="UNKNOWN"]')).not.toBeNull();
    });

    it("le surlignage CONFLIT prime sur la lentille (le refus de move l'emporte)", () => {
      const model = buildGrid(lensSlots, "gymnase", lookups);
      // « free » est le créneau en conflit surligné ; la lentille ne doit PAS colorer.
      const { container } = render(
        <WeekGrid model={model} selectedSlotId={null} onSelectSlot={vi.fn()} lockLens highlightSlotIds={new Set(["free"])} />,
      );

      // Conflit : le hors-conflit est estompé par le CONFLIT (opacity-30), pas par la lentille.
      expect(container.querySelector('[data-slot-id="manual"]')?.className).toContain("opacity-30");
      // La lentille est suffoquée : ni anneau de catégorie ni icône lentille tant qu'un conflit règne.
      expect(container.querySelector('[data-slot-id="manual"]')?.className).not.toContain("ring-2 ring-accent");
      expect(container.querySelector('[data-lens="MANUAL"]')).toBeNull();
    });

    it("lentille inactive (lockLens absent) : ni estompe ni anneau de catégorie", () => {
      const model = buildGrid(lensSlots, "gymnase", lookups);
      const { container } = render(<WeekGrid model={model} selectedSlotId={null} onSelectSlot={vi.fn()} />);

      expect(container.querySelector('[data-slot-id="free"]')?.className).not.toContain("opacity-40");
      expect(container.querySelector('[data-lens="MANUAL"]')).toBeNull();
    });

    // Cartes fusionnées (retour fondateur) : type UNIFORME → UN SEUL picto au niveau carte ;
    // types MIXTES → picto seulement sur les rangées verrouillées.
    const mergeLookups: Lookups = {
      teams: new Map<string, Team>([
        ["t1", { id: "t1", name: "U11", sportCategoryId: "c", priorityTierId: 1, tierOrder: 0, sessionsPerWeek: 2 }],
        ["t2", { id: "t2", name: "U13", sportCategoryId: "c", priorityTierId: 2, tierOrder: 0, sessionsPerWeek: 2 }],
      ]),
      venues: new Map<string, Venue>([["v1", { id: "v1", name: "Gymnase Alpha", color: "#00aa00" }]]),
      coaches: new Map<string, Coach>(),
      teamCoach: new Map<string, string>(),
      teamPlayerCoaches: new Map<string, string[]>(),
      groupLabels: new Map<string, string>([["v1|1|1080", "CEC3"]]),
    };

    it("carte fusionnée UNIFORME : un seul picto de carte, pas un par rangée", () => {
      const slots: Slot[] = [
        { ...slot, id: "s1", teamId: "t1", lockLevel: "HARD", lockOrigin: "RESERVATION" },
        { ...slot, id: "s2", teamId: "t2", lockLevel: "HARD", lockOrigin: "RESERVATION" },
      ];
      const model = buildGrid(slots, "gymnase", mergeLookups);
      const { container } = render(<WeekGrid model={model} selectedSlotId={null} onSelectSlot={vi.fn()} lockLens />);

      expect(container.querySelectorAll('[data-lens="RESERVATION"]')).toHaveLength(1);
    });

    it("carte fusionnée MIXTE : un picto par rangée verrouillée uniquement", () => {
      const slots: Slot[] = [
        { ...slot, id: "s1", teamId: "t1", lockLevel: "HARD", lockOrigin: "RESERVATION" },
        { ...slot, id: "s2", teamId: "t2", lockLevel: "NONE", lockOrigin: null },
      ];
      const model = buildGrid(slots, "gymnase", mergeLookups);
      const { container } = render(<WeekGrid model={model} selectedSlotId={null} onSelectSlot={vi.fn()} lockLens />);

      // Une seule rangée verrouillée → un picto, porté PAR la rangée (dans le bouton du membre).
      const badges = container.querySelectorAll("[data-lens]");
      expect(badges).toHaveLength(1);
      expect(badges[0]?.closest("button")).not.toBeNull();
    });
  });

  describe("mode cible (targetMode, P2-30 PR B geste 1/2)", () => {
    // Une séance placée (lundi) + une fenêtre VIDE (mardi) dans la même vue gymnase.
    const occupied: Slot = { ...slot, id: "a", dayOfWeek: 1 };
    const emptyWindow: Slot = { ...slot, id: "empty:w1", teamId: "", dayOfWeek: 2, startTime: "19:00:00" };
    const buildTargetGrid = (extra: Slot[] = []) => buildGrid([occupied, emptyWindow, ...extra], "gymnase", lookups);

    it("rend les cases VIDES comme des boutons focusables avec un aria-label « Placer ici »", () => {
      const model = buildTargetGrid();
      render(
        <WeekGrid model={model} selectedSlotId={null} onSelectSlot={vi.fn()} targetMode={{ active: true, sourceSlotId: "a", variant: "move" }} onPickTarget={vi.fn()} onCancelTarget={vi.fn()} />,
      );

      const btn = screen.getByRole("button", { name: /Placer ici/ });
      // L'aria-label nomme le gymnase et l'horaire (« Placer ici — Gymnase Alpha, … 19:00–20:30 »).
      expect(btn.getAttribute("aria-label")).toMatch(/Gymnase Alpha/);
      expect(btn.getAttribute("aria-label")).toMatch(/19:00/);
    });

    it("clic sur une case vide → onPickTarget avec l'id du créneau vide", () => {
      const onPickTarget = vi.fn();
      const model = buildTargetGrid();
      render(<WeekGrid model={model} selectedSlotId={null} onSelectSlot={vi.fn()} targetMode={{ active: true, sourceSlotId: "a", variant: "move" }} onPickTarget={onPickTarget} onCancelTarget={vi.fn()} />);

      screen.getByRole("button", { name: /Placer ici/ }).click();
      expect(onPickTarget).toHaveBeenCalledWith("empty:w1");
    });

    it("marque la SOURCE d'un déplacement (repère dédié)", () => {
      const model = buildTargetGrid();
      const { container } = render(<WeekGrid model={model} selectedSlotId={null} onSelectSlot={vi.fn()} targetMode={{ active: true, sourceSlotId: "a", variant: "move" }} onPickTarget={vi.fn()} onCancelTarget={vi.fn()} />);

      const source = container.querySelector('[data-target-source="true"]');
      expect(source).not.toBeNull();
      expect(source?.getAttribute("data-slot-id")).toBe("a");
    });

    it("clic sur une séance OCCUPÉE (non source) → onPickTarget (éviction déléguée à la page)", () => {
      const onPickTarget = vi.fn();
      const onSelectSlot = vi.fn();
      // Une équipe distincte (U13) pour cibler SANS ambiguïté la carte occupée non-source.
      const twoTeams: Lookups = {
        ...lookups,
        teams: new Map<string, Team>([
          ["t1", { id: "t1", name: "U11", sportCategoryId: "c", priorityTierId: 1, tierOrder: 0, sessionsPerWeek: 2 }],
          ["t2", { id: "t2", name: "U13", sportCategoryId: "c", priorityTierId: 2, tierOrder: 0, sessionsPerWeek: 2 }],
        ]),
      };
      const other: Slot = { ...slot, id: "b", teamId: "t2", dayOfWeek: 3 };
      const model = buildGrid([occupied, emptyWindow, other], "gymnase", twoTeams);
      render(<WeekGrid model={model} selectedSlotId={null} onSelectSlot={onSelectSlot} targetMode={{ active: true, sourceSlotId: "a", variant: "move" }} onPickTarget={onPickTarget} onCancelTarget={vi.fn()} />);

      // La carte occupée « b » (U13) devient une cible (plus une simple sélection).
      screen.getByRole("button", { name: /U13/ }).click();
      expect(onPickTarget).toHaveBeenCalledWith("b");
      expect(onSelectSlot).not.toHaveBeenCalled();
    });

    it("une séance occupée VERROUILLÉE n'est pas une cible d'éviction (clic inerte + info verrou)", () => {
      const onPickTarget = vi.fn();
      const locked: Slot = { ...slot, id: "b", dayOfWeek: 3, lockLevel: "HARD", lockOrigin: "RESERVATION" };
      const model = buildTargetGrid([locked]);
      const { container } = render(<WeekGrid model={model} selectedSlotId={null} onSelectSlot={vi.fn()} targetMode={{ active: true, sourceSlotId: "a", variant: "move" }} onPickTarget={onPickTarget} onCancelTarget={vi.fn()} />);

      const card = container.querySelector('[data-slot-id="b"]');
      const button = card?.querySelector("button");
      button?.click();
      expect(onPickTarget).not.toHaveBeenCalled();
      // Le motif du refus (verrou) est lisible en info-bulle.
      expect(card?.querySelector('[title*="verrouill"]')).not.toBeNull();
    });

    it("Échap sort du mode cible (onCancelTarget)", () => {
      const onCancelTarget = vi.fn();
      const model = buildTargetGrid();
      const { container } = render(<WeekGrid model={model} selectedSlotId={null} onSelectSlot={vi.fn()} targetMode={{ active: true, sourceSlotId: "a", variant: "move" }} onPickTarget={vi.fn()} onCancelTarget={onCancelTarget} />);

      fireEvent.keyDown(container.firstChild as Element, { key: "Escape" });
      expect(onCancelTarget).toHaveBeenCalledTimes(1);
    });

    it("suspend la lentille tant que le mode cible est armé", () => {
      const lensSlots: Slot[] = [
        { ...slot, id: "a", dayOfWeek: 1, lockLevel: "HARD", lockOrigin: "MANUAL" },
        { ...slot, id: "empty:w1", teamId: "", dayOfWeek: 2, startTime: "19:00:00" },
      ];
      const model = buildGrid(lensSlots, "gymnase", lookups);
      const { container } = render(<WeekGrid model={model} selectedSlotId={null} onSelectSlot={vi.fn()} lockLens targetMode={{ active: true, sourceSlotId: "a", variant: "move" }} onPickTarget={vi.fn()} onCancelTarget={vi.fn()} />);

      // Lentille suffoquée : aucune icône de catégorie tant que le mode cible règne.
      expect(container.querySelector('[data-lens]')).toBeNull();
    });

    it("mode PLACEMENT (dérive) : pas de source marquée, les cases vides restent des cibles", () => {
      const onPickTarget = vi.fn();
      const model = buildTargetGrid();
      const { container } = render(<WeekGrid model={model} selectedSlotId={null} onSelectSlot={vi.fn()} targetMode={{ active: true, sourceSlotId: null, variant: "place" }} onPickTarget={onPickTarget} onCancelTarget={vi.fn()} />);

      expect(container.querySelector('[data-target-source="true"]')).toBeNull();
      screen.getByRole("button", { name: /Placer ici/ }).click();
      expect(onPickTarget).toHaveBeenCalledWith("empty:w1");
    });
  });

  // P2-44 (PR-4) — écarts au socle DANS la grille : un symbole ⇄ (violet, `--diff`) AVANT le nom,
  // l'origine de saison en `sr-only`, jamais le mot « déplacée » à l'écran. Le conflit prime.
  describe("écart au socle (deviatedSlots, P2-44 PR-4)", () => {
    it("carte déviée : symbole ⇄ AVANT le nom + origine en sr-only ; carte non déviée : rien", () => {
      const model = buildGrid([slot], "gymnase", lookups);
      const { container } = render(<WeekGrid model={model} selectedSlotId={null} onSelectSlot={vi.fn()} deviatedSlots={new Map([["a", "Mar 18h30 Matéo"]])} />);

      const card = container.querySelector('[data-slot-id="a"]');
      const chip = card?.querySelector(".bg-diff");
      expect(chip, "la carte déviée porte la pastille d'écart").not.toBeNull();
      // AVANT le nom : la pastille précède le libellé d'équipe dans le DOM.
      const name = screen.getByText("U11");
      expect(chip && (chip.compareDocumentPosition(name) & Node.DOCUMENT_POSITION_FOLLOWING) !== 0).toBe(true);
      // L'origine (place de saison) est portée pour le lecteur d'écran — jamais le mot « déplacée » à l'écran visuellement.
      expect(screen.getByText(/déplacée — en saison : Mar 18h30 Matéo/)).toHaveClass("sr-only");
    });

    it("aucun deviatedSlots (ou slotId absent du set) : aucune pastille d'écart", () => {
      const model = buildGrid([slot], "gymnase", lookups);
      const { container, rerender } = render(<WeekGrid model={model} selectedSlotId={null} onSelectSlot={vi.fn()} />);
      expect(container.querySelector(".bg-diff")).toBeNull();

      // Un set qui ne contient PAS ce créneau ne marque rien non plus.
      rerender(<WeekGrid model={model} selectedSlotId={null} onSelectSlot={vi.fn()} deviatedSlots={new Map([["autre", "Lun 18h00 X"]])} />);
      expect(container.querySelector(".bg-diff")).toBeNull();
    });

    it("carte déviée ET en conflit (flagged) : aucun anneau `diff` (une carte occupée surlignée n'en porte aucun) — le symbole reste", () => {
      // Deux créneaux (deux jours) pour que le surlignage conflit soit décisif : « a » est surligné.
      const other: Slot = { ...slot, id: "b", dayOfWeek: 3 };
      const model = buildGrid([slot, other], "gymnase", lookups);
      const { container } = render(
        <WeekGrid model={model} selectedSlotId={null} onSelectSlot={vi.fn()} deviatedSlots={new Map([["a", "Mar 18h30 Matéo"]])} highlightSlotIds={new Set(["a"])} />,
      );

      const card = container.querySelector('[data-slot-id="a"]');
      expect(card?.className).not.toContain("ring-warning");
      expect(card?.className).not.toContain("ring-diff");
      // Le surlignage conflit reste ce qu'il a toujours été : l'AUTRE carte s'estompe.
      expect(container.querySelector('[data-slot-id="b"]')?.className).toContain("opacity-30");
      // Le symbole ⇄ subsiste malgré le conflit (l'écart reste vrai).
      expect(card?.querySelector(".bg-diff")).not.toBeNull();
    });

    it("carte déviée SANS conflit : anneau `diff`", () => {
      const model = buildGrid([slot], "gymnase", lookups);
      const { container } = render(<WeekGrid model={model} selectedSlotId={null} onSelectSlot={vi.fn()} deviatedSlots={new Map([["a", "Mar 18h30 Matéo"]])} />);
      expect(container.querySelector('[data-slot-id="a"]')?.className).toContain("ring-diff");
    });

    it("carte fusionnée : chaque membre dévié est marqué INDIVIDUELLEMENT", () => {
      const mergeLookups: Lookups = {
        teams: new Map<string, Team>([
          ["t1", { id: "t1", name: "U11", sportCategoryId: "c", priorityTierId: 1, tierOrder: 0, sessionsPerWeek: 2 }],
          ["t2", { id: "t2", name: "U13", sportCategoryId: "c", priorityTierId: 2, tierOrder: 0, sessionsPerWeek: 2 }],
        ]),
        venues: new Map<string, Venue>([["v1", { id: "v1", name: "Gymnase Alpha", color: "#00aa00" }]]),
        coaches: new Map<string, Coach>(),
        teamCoach: new Map<string, string>(),
        teamPlayerCoaches: new Map<string, string[]>(),
        groupLabels: new Map<string, string>([["v1|1|1080", "CEC3"]]),
      };
      const slots: Slot[] = [
        { ...slot, id: "s1", teamId: "t1" },
        { ...slot, id: "s2", teamId: "t2" },
      ];
      const model = buildGrid(slots, "gymnase", mergeLookups);
      const { container } = render(<WeekGrid model={model} selectedSlotId={null} onSelectSlot={vi.fn()} deviatedSlots={new Map([["s2", "Jeu 19h00 JDR"]])} />);

      // Seul le membre s2 (dévié) porte la pastille, dans SON bouton.
      const s2 = container.querySelector('[data-slot-id="s2"]');
      expect(s2?.querySelector(".bg-diff")).not.toBeNull();
      const s1 = container.querySelector('[data-slot-id="s1"]');
      expect(s1?.querySelector(".bg-diff")).toBeNull();
    });
  });

  it("names the venue as TEXT in every view, not colour only (A11Y-01, WCAG 1.4.1)", async () => {
    // In the team ('equipe') view the venue is no longer a column header — it must
    // still be readable as text on the cell (not conveyed by the border/tint colour
    // alone), so a colourblind or touch user can tell venues apart.
    const model = buildGrid([slot], "equipe", lookups);
    const { container } = render(<WeekGrid model={model} selectedSlotId={null} onSelectSlot={vi.fn()} />);

    expect(screen.getByText("Gymnase Alpha")).toBeInTheDocument();
    expect(await axe(container)).toHaveNoViolations();
  });

  // P2-43 volet (v) — une fenêtre vide sur un couple (gymnase, jour) FERMÉ est MARQUÉE (inerte
  // + nommée), jamais offerte en « Placer ici ». `closedWindows` porte l'état SERVI (clé
  // `venueId|jourISO`) : le grain JOUR est respecté, la grille ne re-dérive rien.
  describe("fenêtres vides fermées (P2-43 volet v)", () => {
    const occupied: Slot = { ...slot, id: "a", dayOfWeek: 1 };
    const emptyWindow: Slot = { ...slot, id: "empty:w1", teamId: "", dayOfWeek: 2, startTime: "19:00:00" };
    const model = buildGrid([occupied, emptyWindow], "gymnase", lookups);
    const closedWindows = new Map<string, string>([["v1|2", "le mardi est fermé (indisponibilité déclarée)"]]);

    it("case fermée : INERTE et NOMMÉE (« fermé » + title « Fermé — … »), jamais un bouton", () => {
      const { container } = render(<WeekGrid model={model} selectedSlotId={null} onSelectSlot={vi.fn()} closedWindows={closedWindows} />);

      const closed = container.querySelector('[title^="Fermé —"]');
      expect(closed).not.toBeNull();
      expect(closed?.getAttribute("title")).toMatch(/mardi est fermé/);
      expect(closed?.textContent).toMatch(/fermé/i);
      // Marquée, pas offerte : plus de « vide » sur ce couple, et aucun bouton pour lui.
      expect(screen.queryByText("vide")).not.toBeInTheDocument();
    });

    it("armé (mode cible), une case fermée NE devient PAS « Placer ici » (offre fail-closed)", () => {
      const onPickTarget = vi.fn();
      render(
        <WeekGrid model={model} selectedSlotId={null} onSelectSlot={vi.fn()} closedWindows={closedWindows} targetMode={{ active: true, sourceSlotId: "a", variant: "move" }} onPickTarget={onPickTarget} onCancelTarget={vi.fn()} />,
      );

      expect(screen.queryByRole("button", { name: /Placer ici/ })).not.toBeInTheDocument();
    });

    it("grain JOUR : le MÊME gymnase un autre jour reste offert « Placer ici »", () => {
      const otherDay: Slot = { ...slot, id: "empty:w2", teamId: "", dayOfWeek: 4, startTime: "19:00:00" };
      const m = buildGrid([occupied, emptyWindow, otherDay], "gymnase", lookups);
      render(
        <WeekGrid model={m} selectedSlotId={null} onSelectSlot={vi.fn()} closedWindows={closedWindows} targetMode={{ active: true, sourceSlotId: "a", variant: "move" }} onPickTarget={vi.fn()} onCancelTarget={vi.fn()} />,
      );

      // Jeudi (v1|4) n'est pas fermé → toujours offert ; mardi (v1|2) ne l'est plus.
      const offers = screen.getAllByRole("button", { name: /Placer ici/ });
      expect(offers).toHaveLength(1);
    });
  });

  // P2-44 (PR-2) — après une transcription, on met les « trous » (fenêtres vides) EN ÉVIDENCE
  // pour que le gestionnaire voie de lui-même où combler ; les cases FERMÉES restent inertes.
  describe("mise en évidence des vides (emphasizeEmpty, P2-44)", () => {
    const emptyWindow: Slot = { ...slot, id: "empty:w1", teamId: "", dayOfWeek: 2, startTime: "19:00:00" };
    const model = buildGrid([emptyWindow], "gymnase", lookups);

    it("emphasizeEmpty : une fenêtre vide devient repérable ET nommée (a11y)", () => {
      render(<WeekGrid model={model} selectedSlotId={null} onSelectSlot={vi.fn()} emphasizeEmpty />);
      expect(screen.getByLabelText(/Créneau vide à combler/)).toBeInTheDocument();
    });

    it("sans emphasizeEmpty : le rendu est inchangé (pas de nom « à combler »)", () => {
      render(<WeekGrid model={model} selectedSlotId={null} onSelectSlot={vi.fn()} />);
      expect(screen.queryByLabelText(/à combler/)).not.toBeInTheDocument();
      expect(screen.getByText("vide")).toBeInTheDocument();
    });

    it("une case FERMÉE n'est JAMAIS mise en évidence, même avec emphasizeEmpty", () => {
      const closed = new Map<string, string>([["v1|2", "le mardi est fermé"]]);
      render(<WeekGrid model={model} selectedSlotId={null} onSelectSlot={vi.fn()} emphasizeEmpty closedWindows={closed} />);
      expect(screen.queryByLabelText(/à combler/)).not.toBeInTheDocument();
      expect(screen.getByText(/fermé/i)).toBeInTheDocument();
    });
  });
});
