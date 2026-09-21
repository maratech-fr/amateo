import { render, screen, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { describe, expect, it, vi } from "vitest";

import { listboxTrigger, openListbox, pickListboxOption } from "@/test/pickListboxOption";

import type { Fixture, TeamMatchHabit, Venue, VenueMatchWindow, VenueUnavailability } from "./api";
import type { EnvelopeResult } from "./lib/envelope";
import { PlacementPanel } from "./PlacementPanel";

const fixture: Fixture = {
  id: "fx-1",
  teamId: "team-1",
  seasonId: "s",
  // Match de compétition par défaut : les gardes DURES (enveloppe, fenêtre
  // d'accès) s'appliquent. Un amical (competitionId null) les assouplit — testé
  // à part (P4-193).
  competitionId: "comp-1",
  matchDate: "2026-10-03", // a Saturday
  homeAway: "HOME",
  opponentLabel: "Voisins",
  status: "UNPLACED",
  venueId: null,
  kickoffTime: null,
  externalRef: null,
  fbiVenueLabel: null,
  placementSource: null,
  unplacedReason: null,
  reviewState: "NEW" as const,
  reviewedAt: null,
  pendingDeviations: [],
  ffbbRencontreId: null, opponentOrganismeCode: null, opponentTeamKey: null, suggestedVenueId: null,
};
const venues: Venue[] = [
  { id: "venue-1", name: "Gymnase Alpha", color: null, externalLabels: [] },
  { id: "venue-2", name: "Gymnase Beta", color: null, externalLabels: [] },
];

// Mapped envelope: 14:00 is inside, 20:00 is outside.
const mappedEnvelope: EnvelopeResult = {
  mapped: true,
  windows: [{ id: "w", league: "AURA", category: "U13", level: "DEPARTEMENTAL", gender: null, dayOfWeek: 6, kickoffMin: "13:00", kickoffMax: "18:00" }],
  dayOk: true,
  timeOk: (k) => "14:00" === k,
};
const openEnvelope: EnvelopeResult = { mapped: false, windows: [], dayOk: false, timeOk: () => false };

interface Overrides {
  matchWindows?: VenueMatchWindow[];
  unavailabilities?: VenueUnavailability[];
  guardState?: "loading" | "failed" | "ready";
  retry?: () => void;
  habits?: TeamMatchHabit[];
  fixture?: Fixture;
  onUnplace?: () => void;
  onToggleLock?: () => void;
  onStartSwap?: () => void;
  onEdit?: () => void;
  onDelete?: () => void;
  onSubmit?: () => void;
  onReopen?: () => void;
}

function renderPanel(envelope: EnvelopeResult, onPlace = vi.fn(), overrides: Overrides = {}) {
  render(
    <PlacementPanel
      fixture={overrides.fixture ?? fixture}
      venues={venues}
      guards={{
        state: overrides.guardState ?? "ready",
        matchWindows: overrides.matchWindows ?? [],
        unavailabilities: overrides.unavailabilities ?? [],
        retry: overrides.retry ?? vi.fn(),
      }}
      habits={overrides.habits ?? []}
      teamLabel="U13"
      categoryLabel="U13"
      envelope={envelope}
      busy={false}
      onClose={vi.fn()}
      onPlace={onPlace}
      onUnplace={overrides.onUnplace ?? vi.fn()}
      onToggleLock={overrides.onToggleLock ?? vi.fn()}
      onStartSwap={overrides.onStartSwap ?? vi.fn()}
      onEdit={overrides.onEdit ?? vi.fn()}
      onDelete={overrides.onDelete ?? vi.fn()}
      onSubmit={overrides.onSubmit ?? vi.fn()}
      onReopen={overrides.onReopen ?? vi.fn()}
    />,
  );
  return onPlace;
}

describe("PlacementPanel — rappel de la raison venue_lost (P2-52)", () => {
  it("rappelle « le gymnase n'est plus affilié » tourné vers le prochain geste", () => {
    renderPanel(openEnvelope, vi.fn(), { fixture: { ...fixture, unplacedReason: "venue_lost" } });
    expect(screen.getByText(/Le gymnase n'est plus affilié au club — choisissez-en un autre\./)).toBeInTheDocument();
  });

  it("ne rappelle rien quand le match n'a pas perdu sa salle (falsification)", () => {
    renderPanel(openEnvelope, vi.fn(), { fixture: { ...fixture, unplacedReason: null } });
    expect(screen.queryByText(/n'est plus affilié au club/)).toBeNull();
  });
});

describe("PlacementPanel", () => {
  it("blocks placement out of the envelope when the team maps", async () => {
    const user = userEvent.setup();
    const onPlace = renderPanel(mappedEnvelope);

    await pickListboxOption(user, "Gymnase", "Gymnase Alpha");
    await user.type(screen.getByLabelText("Heure de coup d'envoi"), "20:00");

    expect(screen.getByText(/Hors fenêtre autorisée/)).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Placer" })).toBeDisabled();
    expect(onPlace).not.toHaveBeenCalled();
  });

  it("allows and emits a placement inside the envelope", async () => {
    const user = userEvent.setup();
    const onPlace = renderPanel(mappedEnvelope);

    await pickListboxOption(user, "Gymnase", "Gymnase Alpha");
    await user.type(screen.getByLabelText("Heure de coup d'envoi"), "14:00");

    const place = screen.getByRole("button", { name: "Placer" });
    expect(place).toBeEnabled();
    await user.click(place);
    expect(onPlace).toHaveBeenCalledWith({ venueId: "venue-1", kickoffTime: "14:00" });
  });

  it("never blocks an unmapped team (advisory only)", async () => {
    const user = userEvent.setup();
    const onPlace = renderPanel(openEnvelope);

    await pickListboxOption(user, "Gymnase", "Gymnase Alpha");
    await user.type(screen.getByLabelText("Heure de coup d'envoi"), "23:00");

    expect(screen.getByRole("button", { name: "Placer" })).toBeEnabled();
    await user.click(screen.getByRole("button", { name: "Placer" }));
    expect(onPlace).toHaveBeenCalledOnce();
  });

  // ── Capacity guards (P1-4 PR B) ──────────────────────────────────────────

  it("only offers match venues once the club declared windows, and blocks outside them", async () => {
    const user = userEvent.setup();
    const onPlace = renderPanel(openEnvelope, vi.fn(), {
      // venue-1 is a match venue on Saturdays 14:00-18:00; venue-2 is training-only.
      matchWindows: [{ id: "w1", venueId: "venue-1", dayOfWeek: 6, startTime: "14:00", endTime: "18:00" }],
    });

    // The selector masks the CHOICE: training-only venue-2 is not offered (open the listbox to see).
    const list = await openListbox(user, "Gymnase");
    expect(within(list).queryByRole("option", { name: "Gymnase Beta" })).not.toBeInTheDocument();
    await user.click(within(list).getByRole("option", { name: "Gymnase Alpha" }));
    await user.type(screen.getByLabelText("Heure de coup d'envoi"), "20:00");
    expect(screen.getByText(/Hors fenêtre d'accès match \(14:00–18:00\)/)).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Placer" })).toBeDisabled();
    expect(onPlace).not.toHaveBeenCalled();
  });

  it("blocks a day without any access window on the venue", async () => {
    const user = userEvent.setup();
    // Sunday-only window; the fixture is on a Saturday.
    renderPanel(openEnvelope, vi.fn(), {
      matchWindows: [{ id: "w1", venueId: "venue-1", dayOfWeek: 7, startTime: "09:00", endTime: "18:00" }],
    });

    await user.type(screen.getByLabelText("Heure de coup d'envoi"), "14:00");
    expect(screen.getByText(/Pas d'accès match le samedi/)).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Placer" })).toBeDisabled();
  });

  it("prefills venue and kickoff from the team's habit of that weekday — guards stay sovereign", async () => {
    // Saturday habit 15:30 at venue-2: both fields prefilled, hint shown.
    const user = userEvent.setup();
    const onPlace = renderPanel(openEnvelope, vi.fn(), {
      habits: [{ id: "h1", teamId: "team-1", dayOfWeek: 6, kickoffTime: "15:30", venueId: "venue-2" }],
    });

    expect(screen.getByText(/Habitude : 15:30 · Gymnase Beta/)).toBeInTheDocument();
    expect(listboxTrigger("Gymnase")).toHaveTextContent("Gymnase Beta");
    expect(screen.getByLabelText("Heure de coup d'envoi")).toHaveValue("15:30");

    await user.click(screen.getByRole("button", { name: "Placer" }));
    expect(onPlace).toHaveBeenCalledWith({ venueId: "venue-2", kickoffTime: "15:30" });
  });

  it("blocks an unavailable venue and keeps the full list when no window exists", async () => {
    const user = userEvent.setup();
    const onPlace = renderPanel(openEnvelope, vi.fn(), {
      // No window anywhere (club has not adopted the data) → full list, no
      // window guard — but the unavailability still bites.
      unavailabilities: [{ id: "u1", venueId: "venue-1", startDate: "2026-10-01", endDate: "2026-10-05", label: "travaux" }],
    });

    const list = await openListbox(user, "Gymnase");
    expect(within(list).getByRole("option", { name: "Gymnase Beta" })).toBeInTheDocument();
    await user.click(within(list).getByRole("option", { name: "Gymnase Alpha" }));
    await user.type(screen.getByLabelText("Heure de coup d'envoi"), "14:00");
    expect(screen.getByText(/indisponible du 1 oct\. au 5 oct\. \(travaux\)/)).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Placer" })).toBeDisabled();

    // The other venue stays placeable.
    await pickListboxOption(user, "Gymnase", "Gymnase Beta");
    expect(screen.getByRole("button", { name: "Placer" })).toBeEnabled();
    await user.click(screen.getByRole("button", { name: "Placer" }));
    expect(onPlace).toHaveBeenCalledWith({ venueId: "venue-2", kickoffTime: "14:00" });
  });

  // ── Amical : placement libre hors créneau (P4-193) ───────────────────────

  const friendly: Fixture = { ...fixture, competitionId: null };

  it("un amical hors fenêtre d'accès : avertissement NEUTRE, bouton ACTIF (placement libre)", async () => {
    const user = userEvent.setup();
    const onPlace = renderPanel(openEnvelope, vi.fn(), {
      fixture: friendly,
      matchWindows: [{ id: "w1", venueId: "venue-1", dayOfWeek: 6, startTime: "14:00", endTime: "18:00" }],
    });

    const list = await openListbox(user, "Gymnase");
    await user.click(within(list).getByRole("option", { name: "Gymnase Alpha" }));
    await user.type(screen.getByLabelText("Heure de coup d'envoi"), "20:00");

    expect(screen.getByText("Amical hors créneau match — placement libre.")).toBeInTheDocument();
    // Jamais le message dur d'une compétition.
    expect(screen.queryByText(/Hors fenêtre d'accès match/)).toBeNull();
    const place = screen.getByRole("button", { name: "Placer" });
    expect(place).toBeEnabled();
    await user.click(place);
    expect(onPlace).toHaveBeenCalledWith({ venueId: "venue-1", kickoffTime: "20:00" });
  });

  it("un championnat hors fenêtre reste BLOQUÉ (contraste avec l'amical)", async () => {
    const user = userEvent.setup();
    const onPlace = renderPanel(openEnvelope, vi.fn(), {
      fixture: { ...fixture, competitionId: "comp-1" },
      matchWindows: [{ id: "w1", venueId: "venue-1", dayOfWeek: 6, startTime: "14:00", endTime: "18:00" }],
    });

    const list = await openListbox(user, "Gymnase");
    await user.click(within(list).getByRole("option", { name: "Gymnase Alpha" }));
    await user.type(screen.getByLabelText("Heure de coup d'envoi"), "20:00");

    expect(screen.getByText(/Hors fenêtre d'accès match \(14:00–18:00\)/)).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Placer" })).toBeDisabled();
    expect(onPlace).not.toHaveBeenCalled();
  });

  it("un gymnase indisponible bloque même un amical (le seul refus dur pour tous)", async () => {
    const user = userEvent.setup();
    renderPanel(openEnvelope, vi.fn(), {
      fixture: friendly,
      unavailabilities: [{ id: "u1", venueId: "venue-1", startDate: "2026-10-01", endDate: "2026-10-05", label: "travaux" }],
    });

    const list = await openListbox(user, "Gymnase");
    await user.click(within(list).getByRole("option", { name: "Gymnase Alpha" }));
    await user.type(screen.getByLabelText("Heure de coup d'envoi"), "14:00");

    expect(screen.getByText(/indisponible du 1 oct\. au 5 oct\. \(travaux\)/)).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Placer" })).toBeDisabled();
  });

  it("l'enveloppe ligue ne bloque JAMAIS un amical (mapped + hors fenêtre → bouton actif)", async () => {
    const user = userEvent.setup();
    const onPlace = renderPanel(mappedEnvelope, vi.fn(), { fixture: friendly });

    await pickListboxOption(user, "Gymnase", "Gymnase Alpha");
    await user.type(screen.getByLabelText("Heure de coup d'envoi"), "20:00"); // hors enveloppe mappée

    const place = screen.getByRole("button", { name: "Placer" });
    expect(place).toBeEnabled();
    await user.click(place);
    expect(onPlace).toHaveBeenCalledWith({ venueId: "venue-1", kickoffTime: "20:00" });
  });

  // ── Manual loop (P1-4 PR E1) ─────────────────────────────────────────────

  const placedFixture: Fixture = { ...fixture, status: "PLACED", venueId: "venue-1", kickoffTime: "16:00", placementSource: "MANUAL" };

  it("on a placed match the main action is Déplacer, disabled while nothing changed", async () => {
    const user = userEvent.setup();
    const onPlace = renderPanel(openEnvelope, vi.fn(), { fixture: placedFixture });

    const move = screen.getByRole("button", { name: "Déplacer" });
    expect(move).toBeDisabled(); // unchanged placement — nothing to move

    await user.clear(screen.getByLabelText("Heure de coup d'envoi"));
    await user.type(screen.getByLabelText("Heure de coup d'envoi"), "18:00");
    expect(move).toBeEnabled();
    await user.click(move);
    expect(onPlace).toHaveBeenCalledWith({ venueId: "venue-1", kickoffTime: "18:00" });
  });

  it("offers Dé-placer, swap and lock-toggle on a placed match", async () => {
    const user = userEvent.setup();
    const onUnplace = vi.fn();
    const onStartSwap = vi.fn();
    renderPanel(openEnvelope, vi.fn(), { fixture: placedFixture, onUnplace, onStartSwap });

    await user.click(screen.getByRole("button", { name: "Dé-placer" }));
    expect(onUnplace).toHaveBeenCalledOnce();
    await user.click(screen.getByRole("button", { name: "Échanger avec…" }));
    expect(onStartSwap).toHaveBeenCalledOnce();
  });

  it("shows « Rendre au système » on a manual anchor", async () => {
    const user = userEvent.setup();
    const onToggleLock = vi.fn();
    renderPanel(openEnvelope, vi.fn(), { fixture: placedFixture, onToggleLock });
    await user.click(screen.getByRole("button", { name: "Rendre au système" }));
    expect(onToggleLock).toHaveBeenCalledOnce();
  });

  it("shows « Verrouiller » on a SOLVER placement", () => {
    renderPanel(openEnvelope, vi.fn(), { fixture: { ...placedFixture, placementSource: "SOLVER" } });
    expect(screen.getByRole("button", { name: "Verrouiller" })).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: "Rendre au système" })).not.toBeInTheDocument();
  });

  it("deletes only after the confirmation dialog", async () => {
    const user = userEvent.setup();
    const onDelete = vi.fn();
    renderPanel(openEnvelope, vi.fn(), { fixture: placedFixture, onDelete });

    await user.click(screen.getByRole("button", { name: "Supprimer" }));
    expect(onDelete).not.toHaveBeenCalled(); // the dialog gates the gesture
    const dialog = await screen.findByRole("dialog");
    expect(within(dialog).getByText(/Supprimer le match contre/)).toBeInTheDocument();
    await user.click(within(dialog).getByRole("button", { name: "Supprimer" }));
    expect(onDelete).toHaveBeenCalledOnce();
  });

  // ── Weekly loop close (RMM-1 PR 1) ───────────────────────────────────────

  it("offers « Marquer saisi dans FBI » on a placed match and emits onSubmit", async () => {
    const user = userEvent.setup();
    const onSubmit = vi.fn();
    renderPanel(openEnvelope, vi.fn(), { fixture: placedFixture, onSubmit });

    const submit = screen.getByRole("button", { name: "Marquer saisi dans FBI" });
    await user.click(submit);
    expect(onSubmit).toHaveBeenCalledOnce();
  });

  it("does not offer « Marquer saisi dans FBI » on an unplaced match (nothing placed yet)", () => {
    renderPanel(openEnvelope, vi.fn()); // default fixture is UNPLACED
    expect(screen.queryByRole("button", { name: "Marquer saisi dans FBI" })).not.toBeInTheDocument();
  });

  it("on a SUBMITTED match: French status, anchor sentence, and the « Corriger » exit — edit gestures gone", async () => {
    const user = userEvent.setup();
    const onReopen = vi.fn();
    renderPanel(openEnvelope, vi.fn(), { fixture: { ...placedFixture, status: "SUBMITTED" }, onReopen });

    expect(screen.getByText("Saisi dans FBI")).toBeInTheDocument();
    expect(screen.getByText(/les prochaines générations ne le déplaceront plus/)).toBeInTheDocument();

    // The edit gestures stay blocked while submitted.
    expect(screen.queryByRole("button", { name: "Déplacer" })).not.toBeInTheDocument();
    expect(screen.queryByRole("button", { name: "Supprimer" })).not.toBeInTheDocument();
    expect(screen.queryByRole("button", { name: "Marquer saisi dans FBI" })).not.toBeInTheDocument();

    await user.click(screen.getByRole("button", { name: "Corriger — repasser en Placé" }));
    expect(onReopen).toHaveBeenCalledOnce();
  });

  it("on a VALIDATED match: French status, anchored sentence, and the « Corriger » exit (founder decision, lot L) — edit gestures gone", async () => {
    const user = userEvent.setup();
    const onReopen = vi.fn();
    renderPanel(openEnvelope, vi.fn(), { fixture: { ...placedFixture, status: "VALIDATED" }, onReopen });

    expect(screen.getByText("Attesté FBI")).toBeInTheDocument();
    expect(screen.getByText(/ancré sur les date, heure et salle enregistrées côté ligue/)).toBeInTheDocument();

    // The edit gestures stay blocked while validated.
    expect(screen.queryByRole("button", { name: "Marquer saisi dans FBI" })).not.toBeInTheDocument();
    expect(screen.queryByRole("button", { name: "Déplacer" })).not.toBeInTheDocument();
    expect(screen.queryByRole("button", { name: "Supprimer" })).not.toBeInTheDocument();

    // But « Corriger » is now offered — a batch bascule can rest on an auto-matched
    // venue, so the manager must always be able to unwind it (never a dead end).
    await user.click(screen.getByRole("button", { name: "Corriger — repasser en Placé" }));
    expect(onReopen).toHaveBeenCalledOnce();
  });

  it("affiche la date formatée FR sur la ligne « Date », jamais l'ISO brut (UXC-19)", () => {
    // matchDate 2026-10-03 est un samedi. Falsification : rendre `fixture.matchDate` brut casse ce test.
    renderPanel(openEnvelope);

    expect(screen.getByText("sam. 3 oct.")).toBeInTheDocument();
    expect(screen.queryByText("2026-10-03")).toBeNull();
  });
});

// ── « Confirmer ce placement » : un UNPLACED déjà pré-rempli par l'import (lot 1) ────────
describe("PlacementPanel — confirmer un placement repris de l'import (2026-09-17)", () => {
  // UNPLACED mais gymnase + heure déjà connus (repris de l'import).
  const importFx: Fixture = { ...fixture, status: "UNPLACED", venueId: "venue-1", kickoffTime: "14:00" };

  it("intitule le bouton « Confirmer ce placement » + ligne d'aide quand rien n'a changé", () => {
    renderPanel(openEnvelope, vi.fn(), { fixture: importFx });
    expect(screen.getByRole("button", { name: "Confirmer ce placement" })).toBeEnabled();
    expect(screen.getByText("Gymnase et heure repris de l'import — vérifiez, puis confirmez.")).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: "Placer" })).toBeNull();
  });

  it("redevient « Placer » (et masque l'aide) dès qu'une valeur change", async () => {
    const user = userEvent.setup();
    renderPanel(openEnvelope, vi.fn(), { fixture: importFx });
    const kickoff = screen.getByLabelText("Heure de coup d'envoi");
    await user.clear(kickoff);
    await user.type(kickoff, "15:00");
    expect(screen.getByRole("button", { name: "Placer" })).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: "Confirmer ce placement" })).toBeNull();
    expect(screen.queryByText(/repris de l'import — vérifiez/)).toBeNull();
  });

  it("sans gymnase d'origine, rien à confirmer : le bouton reste « Placer » (flux e2e « créer puis placer »)", async () => {
    const user = userEvent.setup();
    // Le fixture par défaut est UNPLACED SANS gymnase ni heure (le match vient d'être créé).
    renderPanel(openEnvelope);
    await pickListboxOption(user, "Gymnase", "Gymnase Alpha");
    await user.type(screen.getByLabelText("Heure de coup d'envoi"), "14:00");
    expect(screen.getByRole("button", { name: "Placer" })).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: "Confirmer ce placement" })).toBeNull();
  });
});

describe("PlacementPanel — D2 : le geste de placement suspendu tant que les gardes ne sont pas prêtes", () => {
  it("failed : alerte de lecture (role=alert), Placer désactivé, Réessayer rappelle retry ; Supprimer reste actif", async () => {
    const user = userEvent.setup();
    const retry = vi.fn();
    const onPlace = renderPanel(openEnvelope, vi.fn(), { guardState: "failed", retry });

    // Le gymnase reste offert (liste complète) et l'heure saisissable : seul le GESTE est neutralisé.
    await pickListboxOption(user, "Gymnase", "Gymnase Alpha");
    await user.type(screen.getByLabelText("Heure de coup d'envoi"), "14:00");

    const alert = screen.getByRole("alert");
    expect(alert).toHaveTextContent(/Impossible de vérifier les accès match/);
    expect(screen.getByRole("button", { name: "Placer" })).toBeDisabled();
    // Les autres gestes de la boucle restent actifs (le refus ne porte que sur le placement).
    expect(screen.getByRole("button", { name: "Supprimer" })).toBeEnabled();

    await user.click(within(alert).getByRole("button", { name: "Réessayer" }));
    expect(retry).toHaveBeenCalledOnce();
    expect(onPlace).not.toHaveBeenCalled();
  });

  it("loading : Placer désactivé + « Vérification des accès match… », aucune alerte", async () => {
    const user = userEvent.setup();
    renderPanel(openEnvelope, vi.fn(), { guardState: "loading" });

    await pickListboxOption(user, "Gymnase", "Gymnase Alpha");
    await user.type(screen.getByLabelText("Heure de coup d'envoi"), "14:00");

    expect(screen.getByText(/Vérification des accès match…/)).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Placer" })).toBeDisabled();
    expect(screen.queryByRole("alert")).toBeNull();
  });

  it("ready avec des listes RÉELLEMENT vides : Placer actif (vacuité réelle, pas fabriquée)", async () => {
    // Témoin qui distingue « la lecture a renvoyé [] » de « la lecture n'a pas abouti » :
    // gardes prêtes + aucun accès/indispo déclaré → rien à imposer → placement autorisé.
    const user = userEvent.setup();
    const onPlace = renderPanel(openEnvelope, vi.fn(), { guardState: "ready", matchWindows: [], unavailabilities: [] });

    await pickListboxOption(user, "Gymnase", "Gymnase Alpha");
    await user.type(screen.getByLabelText("Heure de coup d'envoi"), "14:00");

    expect(screen.queryByRole("alert")).toBeNull();
    const place = screen.getByRole("button", { name: "Placer" });
    expect(place).toBeEnabled();
    await user.click(place);
    expect(onPlace).toHaveBeenCalledWith({ venueId: "venue-1", kickoffTime: "14:00" });
  });
});
