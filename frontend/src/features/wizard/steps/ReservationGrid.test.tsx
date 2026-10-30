import { render, screen, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { describe, expect, it, vi } from "vitest";

import type { Closure } from "@/features/cockpit/api";

import type { Venue, VenueTrainingSlot } from "../api";
import { WEEK } from "../lib/weekGrid";
import { ReservationGrid } from "./ReservationGrid";

const venue: Venue = { id: "v1", name: "Gymnase A", color: "#00aa00", canSplit: false, isActive: true } as Venue;

const sunday: VenueTrainingSlot = { id: "s7", venueId: "v1", dayOfWeek: 7, startTime: "20:00:00", durationMinutes: 90, capacity: 2 } as VenueTrainingSlot;
const monday: VenueTrainingSlot = { id: "s1", venueId: "v1", dayOfWeek: 1, startTime: "20:00:00", durationMinutes: 90, capacity: 2 } as VenueTrainingSlot;

const closure = (over: Partial<Closure> = {}): Closure => ({ constraintId: "c1", venueId: "v1", title: "Travaux", startDate: "2026-05-01", endDate: "2026-05-10", weekdays: [5, 6, 7], ...over });

/**
 * P4-37 — LE DIMANCHE A UNE COLONNE, DONC UN CRÉNEAU DU DIMANCHE S'AFFICHE.
 *
 * Bug latent trouvé en lisant le code : `WEEK.findIndex((d) => d.n === slot.dayOfWeek)`
 * rendait **-1** pour un dimanche, puisque `WEEK` s'arrêtait au samedi. L'onglet Réserver
 * ne rendait donc RIEN pour ce créneau — pas une colonne fausse, une disparition pure :
 * la garde `di < 0` le supprimait silencieusement, alors qu'il était bien servi au solveur.
 */
describe("ReservationGrid — le dimanche", () => {
  it("rend un créneau du dimanche dans la DERNIÈRE colonne", () => {
    render(<ReservationGrid venue={venue} slots={[sunday]} reservedTeams={new Map()} slotKeyOf={() => "k"} capacityOf={() => 2} onSelectSlot={vi.fn()} />);

    const cell = screen.getByRole("button", { name: /^Dim 20:00 · Gymnase A/ });

    // La présence seule ne suffirait pas : c'est la COLONNE qui portait le défaut. Sept
    // jours ⇒ la gouttière occupe la colonne 1 et le dimanche la 8ᵉ.
    expect(cell).toHaveStyle({ gridColumn: `${1 + WEEK.length}` });
  });
});

describe("ReservationGrid — libellé de groupe (P2-17)", () => {
  it("affiche le libellé d'un créneau mutualisé, sans fusion (badge)", () => {
    const labelled: VenueTrainingSlot = { ...sunday, groupLabel: "CEC3" } as VenueTrainingSlot;
    render(<ReservationGrid venue={venue} slots={[labelled]} reservedTeams={new Map()} slotKeyOf={() => "k"} capacityOf={() => 2} onSelectSlot={vi.fn()} />);

    expect(screen.getByText("CEC3")).toBeInTheDocument();
    // Et dans le nom accessible du créneau, pour l'AT.
    expect(screen.getByRole("button", { name: /· CEC3 ·/ })).toBeInTheDocument();
  });

  it("n'affiche aucun libellé quand le créneau n'en porte pas", () => {
    render(<ReservationGrid venue={venue} slots={[sunday]} reservedTeams={new Map()} slotKeyOf={() => "k"} capacityOf={() => 2} onSelectSlot={vi.fn()} />);

    expect(screen.queryByText("CEC3")).toBeNull();
  });
});

/**
 * #12 (retours Rillieux, décision fondateur 2026-10-13 « groupe mutualisé = une équipe » ; libellé
 * validé 2026-10-22) — une case BLOC-COMPLÈTE (toutes les équipes d'un bloc réservées dessus) compte
 * pour UN occupant : la grille affiche « Complet · Groupe <A> + <B> » et JAMAIS le compteur « 2/1 »
 * (le bloc occupe la case en entier, règle b serveur). Le parent transmet les slotKeys bloc-complètes
 * via `blockCompleteSlots` (dérivées par le miroir `blockOccupancy.ts::completeBlockOnCase`).
 */
describe("ReservationGrid — case bloc-complète (#12)", () => {
  const cap1 = (): number => 1;

  it("affiche « Complet · Groupe U15F + U18F » (libellé + nom accessible), jamais « 2/1 »", () => {
    render(
      <ReservationGrid
        venue={venue}
        slots={[monday]}
        reservedTeams={new Map([["k", ["U15F", "U18F"]]])}
        slotKeyOf={() => "k"}
        capacityOf={cap1}
        onSelectSlot={vi.fn()}
        blockCompleteSlots={new Set(["k"])}
      />,
    );

    // E11 (lot 2, ADAPTÉ — je suis propriétaire de ce test) — plus de LISTE EN DOUBLE : la 1ʳᵉ ligne
    // dit « Complet · Groupe » (sans label fourni ici), SANS répéter les équipes ; les équipes ne
    // paraissent qu'UNE fois, en dessous. ROUGE aujourd'hui : la ligne « Complet » porte « Groupe U15F
    // + U18F » ET le pied répète « U15F, U18F ».
    expect(screen.queryByText(/Complet · Groupe U15F/), "E11 : les équipes ne sont PLUS dans la ligne « Complet »").toBeNull();
    const cell = screen.getByRole("button", { name: /Complet/ });
    expect(within(cell).getAllByText(/U15F/), "E11 : les équipes n'apparaissent qu'une fois (en dessous)").toHaveLength(1);
    expect(cell).toHaveAccessibleName(expect.not.stringMatching(/2\/1/));
    expect(screen.queryByText("2/1")).toBeNull();
  });

  it("E11 — la 1ʳᵉ ligne porte le NOM du groupe quand le bloc en a un (« Complet · SM Shooting »)", () => {
    // Le bloc mutualisé a un `label` en base ; la grille le LIT (contrat : prop `blockLabels`,
    // slotKey → label — distinct de `slot.groupLabel`, le libellé de CRÉNEAU P2-17). ROUGE
    // aujourd'hui : la grille ignore le label et affiche « Complet · Groupe U15F + U18F ».
    const RG = ReservationGrid as unknown as (props: Record<string, unknown>) => ReturnType<typeof ReservationGrid>;
    render(
      <RG
        venue={venue}
        slots={[monday]}
        reservedTeams={new Map([["k", ["U15F", "U18F"]]])}
        slotKeyOf={() => "k"}
        capacityOf={cap1}
        onSelectSlot={vi.fn()}
        blockCompleteSlots={new Set(["k"])}
        blockLabels={new Map([["k", "SM Shooting"]])}
      />,
    );

    expect(screen.getByText(/Complet · SM Shooting/), "E11 : le nom du groupe nomme la case bloc-complète").toBeInTheDocument();
    expect(screen.queryByText(/Complet · Groupe/), "E11 : avec un label, plus de « Complet · Groupe » générique").toBeNull();
  });

  it("CONTRÔLE — sans `blockCompleteSlots`, la même case retombe sur le compteur « 2/1 » (comportement hors bloc inchangé)", () => {
    render(
      <ReservationGrid
        venue={venue}
        slots={[monday]}
        reservedTeams={new Map([["k", ["U15F", "U18F"]]])}
        slotKeyOf={() => "k"}
        capacityOf={cap1}
        onSelectSlot={vi.fn()}
      />,
    );

    expect(screen.getByText("2/1")).toBeInTheDocument();
    expect(screen.queryByText(/Complet · Groupe/)).toBeNull();
  });
});

/**
 * D1/D2 (P2-22) — un créneau d'un jour fermé est barré + libellé ; SANS réservation il est
 * désactivé (on ne peut plus rien y ajouter), AVEC réservation il reste cliquable pour le
 * geste correctif (retirer l'épinglage qui bloque la génération).
 */
describe("ReservationGrid — fermetures de gymnase", () => {
  it("barre et DÉSACTIVE un créneau fermé sans réservation", () => {
    render(<ReservationGrid venue={venue} slots={[sunday]} reservedTeams={new Map()} slotKeyOf={() => "k"} capacityOf={() => 2} onSelectSlot={vi.fn()} closures={[closure()]} />);

    const btn = screen.getByRole("button", { name: /Indispo du 1\/5 au 10\/5 — Travaux/ });
    expect(btn).toBeDisabled();
    expect(btn).toHaveClass("line-through");
  });

  it("garde CLIQUABLE un créneau fermé qui porte une réservation (geste correctif)", async () => {
    const onSelectSlot = vi.fn();
    render(<ReservationGrid venue={venue} slots={[sunday]} reservedTeams={new Map([["k", ["SM1"]]])} slotKeyOf={() => "k"} capacityOf={() => 2} onSelectSlot={onSelectSlot} closures={[closure()]} />);

    const btn = screen.getByRole("button", { name: /Indispo du 1\/5 au 10\/5 — Travaux/ });
    expect(btn).not.toBeDisabled();
    await userEvent.click(btn);
    expect(onSelectSlot).toHaveBeenCalledWith(sunday);
  });

  it("laisse intact un créneau d'un jour ouvert (grain JOUR)", () => {
    render(<ReservationGrid venue={venue} slots={[monday]} reservedTeams={new Map()} slotKeyOf={() => "k"} capacityOf={() => 2} onSelectSlot={vi.fn()} closures={[closure({ weekdays: [5, 6, 7] })]} />);

    const btn = screen.getByRole("button", { name: /^Lun 20:00 · Gymnase A/ });
    expect(btn).not.toBeDisabled();
    expect(btn).not.toHaveClass("line-through");
    expect(screen.queryByText(/Indispo/)).toBeNull();
  });
});
