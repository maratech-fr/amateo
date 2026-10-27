import { render, screen, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { listboxTrigger, openListbox } from "@/test/pickListboxOption";

import type { Fixture, Venue } from "./api";
import { ReviewQueueRow } from "./ReviewQueueRow";

const venues: Venue[] = [
  { id: "v1", name: "Gymnase Alpha", color: null, externalLabels: [] },
  { id: "v2", name: "Coubertin", color: null, externalLabels: [] },
];

let seq = 0;
function fx(extra: Partial<Fixture> = {}): Fixture {
  seq += 1;
  return {
    id: `fx-${seq}`,
    teamId: "t1",
    seasonId: "s1",
    competitionId: null,
    matchDate: "2026-11-07",
    homeAway: "HOME",
    opponentLabel: "BRON",
    status: "PLACED",
    venueId: null,
    kickoffTime: null,
    externalRef: null,
    fbiVenueLabel: "GYMNASE MATEO",
    placementSource: null,
    unplacedReason: null,
    neutralGround: false,
    reviewState: "NEW",
    reviewedAt: null,
    pendingDeviations: [],
    ffbbRencontreId: null, opponentOrganismeCode: null, opponentTeamKey: null,
    suggestedVenueId: null,
    ...extra,
  };
}

function renderRow(fixture: Fixture, onAttach = vi.fn(), onPlace = vi.fn()) {
  const utils = render(
    <ul>
      <ReviewQueueRow fixture={fixture} venues={venues} onValidateLine={vi.fn()} onResolve={vi.fn()} onPlace={onPlace} onAttach={onAttach} busy={false} />
    </ul>,
  );
  return { onAttach, onPlace, ...utils };
}

const TRIGGER = /Choisir le gymnase pour la rencontre/;

beforeEach(() => {
  seq = 0;
});

describe("ReviewQueueRow — rattacher un gymnase (P4-187b)", () => {
  it("un domicile importé sans salle porte la mention + le bouton « Rattacher »", () => {
    renderRow(fx());
    expect(screen.getByText(/GYMNASE MATEO · non rattaché/)).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Rattacher" })).toBeInTheDocument();
  });

  it("le bloc s'affiche AUSSI sur une ligne déjà traitée (REVIEWED)", () => {
    renderRow(fx({ reviewState: "REVIEWED", reviewedAt: "2026-10-01T10:00:00+00:00" }));
    expect(screen.getByRole("button", { name: "Rattacher" })).toBeInTheDocument();
  });

  it("absent sur une AWAY (jamais un domicile à rattacher)", () => {
    renderRow(fx({ homeAway: "AWAY" }));
    expect(screen.queryByRole("button", { name: "Rattacher" })).not.toBeInTheDocument();
  });

  it("absent sur un domicile déjà rattaché (venueId posé)", () => {
    renderRow(fx({ venueId: "v1" }));
    expect(screen.queryByRole("button", { name: "Rattacher" })).not.toBeInTheDocument();
  });

  it("« Rattacher » ouvre un sélecteur PRÉ-SÉLECTIONNÉ sur la proposition présente dans la liste, Confirmer actif", async () => {
    const user = userEvent.setup();
    renderRow(fx({ suggestedVenueId: "v1" }));
    await user.click(screen.getByRole("button", { name: "Rattacher" }));
    expect(listboxTrigger(TRIGGER)).toHaveTextContent("Gymnase Alpha");
    expect(screen.getByRole("button", { name: "Confirmer" })).toBeEnabled();
  });

  it("sans proposition → placeholder et Confirmer DÉSACTIVÉ tant que rien n'est choisi", async () => {
    const user = userEvent.setup();
    renderRow(fx({ suggestedVenueId: null }));
    await user.click(screen.getByRole("button", { name: "Rattacher" }));
    expect(listboxTrigger(TRIGGER)).toHaveTextContent("Choisir un gymnase");
    expect(screen.getByRole("button", { name: "Confirmer" })).toBeDisabled();
  });

  it("proposition HORS liste (id inconnu) → placeholder, jamais un pari", async () => {
    const user = userEvent.setup();
    renderRow(fx({ suggestedVenueId: "v-fantome" }));
    await user.click(screen.getByRole("button", { name: "Rattacher" }));
    expect(listboxTrigger(TRIGGER)).toHaveTextContent("Choisir un gymnase");
    expect(screen.getByRole("button", { name: "Confirmer" })).toBeDisabled();
  });

  it("Confirmer envoie onAttach avec le libellé BRUT (le serveur normalise)", async () => {
    const user = userEvent.setup();
    const { onAttach } = renderRow(fx({ suggestedVenueId: "v1" }));
    await user.click(screen.getByRole("button", { name: "Rattacher" }));
    await user.click(screen.getByRole("button", { name: "Confirmer" }));
    expect(onAttach).toHaveBeenCalledWith({ venueId: "v1", label: "GYMNASE MATEO" });
  });

  it("un choix manuel (sans proposition) alimente onAttach", async () => {
    const user = userEvent.setup();
    const { onAttach } = renderRow(fx({ suggestedVenueId: null }));
    await user.click(screen.getByRole("button", { name: "Rattacher" }));
    const list = await openListbox(user, TRIGGER);
    await user.click(within(list).getByRole("option", { name: "Coubertin" }));
    await user.click(screen.getByRole("button", { name: "Confirmer" }));
    expect(onAttach).toHaveBeenCalledWith({ venueId: "v2", label: "GYMNASE MATEO" });
  });

  it("Annuler replie le bloc (retour au bouton « Rattacher »)", async () => {
    const user = userEvent.setup();
    renderRow(fx({ suggestedVenueId: "v1" }));
    await user.click(screen.getByRole("button", { name: "Rattacher" }));
    await user.click(screen.getByRole("button", { name: "Annuler" }));
    expect(screen.getByRole("button", { name: "Rattacher" })).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: "Confirmer" })).not.toBeInTheDocument();
  });
});

describe("ReviewQueueRow — domicile/extérieur, heure et salle (PR A)", () => {
  it("HOME → pictogramme maison aria-hidden + le mot « Domicile »", () => {
    const { container } = renderRow(fx({ homeAway: "HOME" }));
    expect(screen.getByText("Domicile")).toBeInTheDocument();
    const house = container.querySelector(".lucide-house");
    expect(house).not.toBeNull();
    expect(house).toHaveAttribute("aria-hidden", "true");
  });

  it("AWAY → pictogramme avion aria-hidden + le mot « Extérieur »", () => {
    const { container } = renderRow(fx({ homeAway: "AWAY" }));
    expect(screen.getByText("Extérieur")).toBeInTheDocument();
    const plane = container.querySelector(".lucide-plane");
    expect(plane).not.toBeNull();
    expect(plane).toHaveAttribute("aria-hidden", "true");
  });

  it("heure + gymnase RÉSOLU (venueId dans la liste) : « 12:30 · <nom du gymnase> »", () => {
    renderRow(fx({ kickoffTime: "12:30", venueId: "v1" }));
    expect(screen.getByText("12:30")).toBeInTheDocument();
    expect(screen.getByText(/Gymnase Alpha/)).toBeInTheDocument();
  });

  it("repli sur le libellé FBI brut quand aucun gymnase n'est rattaché (venueId null)", () => {
    // AWAY : pas de bloc « Rattacher », le libellé n'apparaît que dans la métadonnée.
    renderRow(fx({ homeAway: "AWAY", kickoffTime: "12:30", venueId: null, fbiVenueLabel: "Salle Croix-Luizet" }));
    expect(screen.getByText("12:30")).toBeInTheDocument();
    expect(screen.getByText(/Salle Croix-Luizet/)).toBeInTheDocument();
  });

  it("heure absente (kickoffTime null) → « heure non publiée »", () => {
    renderRow(fx({ kickoffTime: null }));
    expect(screen.getByText("heure non publiée")).toBeInTheDocument();
  });

  it("« Replacer » présent sur un domicile, appelle onPlace", async () => {
    const user = userEvent.setup();
    const fixture = fx({ homeAway: "HOME" });
    const { onPlace } = renderRow(fixture);
    const replace = screen.getByRole("button", { name: "Replacer" });
    await user.click(replace);
    expect(onPlace).toHaveBeenCalledWith(fixture);
  });

  it("« Replacer » absent sur un domicile déjà joué (un match passé ne se replace pas)", () => {
    renderRow(fx({ homeAway: "HOME", matchDate: "2020-01-04" }));
    expect(screen.queryByRole("button", { name: "Replacer" })).not.toBeInTheDocument();
  });

  it("« Replacer » présent sur un domicile à venir", () => {
    renderRow(fx({ homeAway: "HOME", matchDate: "2099-01-04" }));
    expect(screen.getByRole("button", { name: "Replacer" })).toBeInTheDocument();
  });

  it("« Replacer » absent sur un extérieur (jamais un domicile à replacer)", () => {
    renderRow(fx({ homeAway: "AWAY" }));
    expect(screen.queryByRole("button", { name: "Replacer" })).not.toBeInTheDocument();
    expect(screen.queryByRole("button", { name: "Placer" })).not.toBeInTheDocument();
  });
});

describe("ReviewQueueRow — valider / pris en compte / bandeau (P4-199)", () => {
  function renderValidatable(fixture: Fixture, onValidateLine = vi.fn()) {
    render(
      <ul>
        <ReviewQueueRow fixture={fixture} venues={venues} onValidateLine={onValidateLine} onResolve={vi.fn()} onPlace={vi.fn()} onAttach={vi.fn()} busy={false} />
      </ul>,
    );
    return { onValidateLine };
  }

  // Retours Nico (BCCL, 2026-10-27), M3 — la ligne s'ACQUITTE : « Vu » (même mot pour NEW et
  // REVIEWED à alerte), jamais « Valider »/« Pris en compte » (le fondateur ne savait pas « ce que
  // ça fait, où ça mène »). A11Y-28 : le nom accessible reste CONTEXTUALISÉ (contient « vu »), la
  // tournure exacte est à NEO ; on pine le texte visible « Vu » et la disparition des anciens mots.
  it("NEW sans écart : bouton « Vu » (acquittement), appelle onValidateLine", async () => {
    const user = userEvent.setup();
    const fixture = fx({ reviewState: "NEW", homeAway: "AWAY" });
    const { onValidateLine } = renderValidatable(fixture);
    const vu = screen.getByRole("button", { name: /vu/i });
    expect(vu).toHaveTextContent("Vu");
    expect(screen.queryByRole("button", { name: "Valider" })).not.toBeInTheDocument();
    await user.click(vu);
    expect(onValidateLine).toHaveBeenCalledWith(fixture.id);
  });

  it("REVIEWED sans écart : aucun bouton (déjà traitée)", () => {
    renderValidatable(fx({ reviewState: "REVIEWED", homeAway: "AWAY", reviewedAt: "2026-10-01T10:00:00+00:00" }));
    // M3 : plus de bouton d'acquittement « Vu » quand il n'y a rien à acquitter.
    expect(screen.queryByRole("button", { name: /vu/i })).not.toBeInTheDocument();
    expect(screen.queryByRole("button", { name: "Pris en compte" })).not.toBeInTheDocument();
  });

  it("REVIEWED à écart AUTO-APPLIQUÉ : bouton « Pris en compte » + bandeau ancien → nouveau, appelle onValidateLine", async () => {
    const user = userEvent.setup();
    const fixture = fx({
      reviewState: "REVIEWED",
      homeAway: "AWAY",
      reviewedAt: "2026-10-01T10:00:00+00:00",
      pendingDeviations: [{ field: "date", appValue: "2026-11-07", sourceValue: "2026-11-14", channel: "FBI_XLSX", seenAt: "2026-10-01T00:00:00+00:00", autoApplied: true }],
    });
    const { onValidateLine } = renderValidatable(fixture);
    // Bandeau : UNE phrase, dates FORMATÉES (jamais l'ISO brut), ancienne → nouvelle.
    expect(screen.getByText("FBI a déplacé ce match (date) : sam. 7 nov. → sam. 14 nov.")).toBeInTheDocument();
    // Pas d'arbitrage champ par champ sur un écart auto-appliqué.
    expect(screen.queryByRole("button", { name: "Garder Amateo" })).not.toBeInTheDocument();
    // M3 (BCCL 2026-10-27) — une REVIEWED à alerte s'acquitte du même mot qu'une NEW : « Vu »
    // (plus « Pris en compte »).
    const vu = screen.getByRole("button", { name: /vu/i });
    expect(vu).toHaveTextContent("Vu");
    expect(screen.queryByRole("button", { name: "Pris en compte" })).not.toBeInTheDocument();
    await user.click(vu);
    expect(onValidateLine).toHaveBeenCalledWith(fixture.id);
  });

  it("date ET heure auto-appliquées : UN seul bloc « … → nouvelle date à nouvelle heure »", () => {
    renderValidatable(fx({
      reviewState: "REVIEWED",
      homeAway: "AWAY",
      reviewedAt: "2026-10-01T10:00:00+00:00",
      pendingDeviations: [
        { field: "date", appValue: "2026-11-07", sourceValue: "2026-11-14", channel: "FBI_XLSX", seenAt: "2026-10-01T00:00:00+00:00", autoApplied: true },
        { field: "kickoff", appValue: "20:00", sourceValue: "21:00", channel: "FBI_XLSX", seenAt: "2026-10-01T00:00:00+00:00", autoApplied: true },
      ],
    }));
    expect(screen.getByText("FBI a déplacé ce match : sam. 7 nov. → sam. 14 nov. à 21:00.")).toBeInTheDocument();
    // UN seul bloc : la phrase n'apparaît pas deux fois.
    expect(screen.queryAllByText(/a déplacé ce match/)).toHaveLength(1);
  });

  it("heure seule auto-appliquée : « (heure) : 20:00 → 21:00 »", () => {
    renderValidatable(fx({
      reviewState: "REVIEWED",
      homeAway: "AWAY",
      reviewedAt: "2026-10-01T10:00:00+00:00",
      pendingDeviations: [{ field: "kickoff", appValue: "20:00", sourceValue: "21:00", channel: "FBI_XLSX", seenAt: "2026-10-01T00:00:00+00:00", autoApplied: true }],
    }));
    expect(screen.getByText("FBI a déplacé ce match (heure) : 20:00 → 21:00")).toBeInTheDocument();
  });

  it("salle seule auto-appliquée (canal API) : « (salle) : X → Y », la source nommée", () => {
    renderValidatable(fx({
      reviewState: "REVIEWED",
      homeAway: "AWAY",
      reviewedAt: "2026-10-01T10:00:00+00:00",
      pendingDeviations: [{ field: "venue", appValue: "Gymnase Alpha", sourceValue: "Coubertin", channel: "FFBB_API", seenAt: "2026-10-01T00:00:00+00:00", autoApplied: true }],
    }));
    expect(screen.getByText("Canal FFBB a déplacé ce match (salle) : Gymnase Alpha → Coubertin")).toBeInTheDocument();
  });

  it("OUT_OF_SYNC à écart arbitrable : ni « Valider » ni « Pris en compte » (on tranche par champ)", () => {
    renderValidatable(fx({
      reviewState: "OUT_OF_SYNC",
      homeAway: "AWAY",
      pendingDeviations: [{ field: "date", appValue: "2026-11-07", sourceValue: "2026-11-14", channel: "FBI_XLSX", seenAt: "2026-10-01T00:00:00+00:00", autoApplied: false }],
    }));
    // M3 : un écart s'ARBITRE (champ par champ), il ne s'acquitte pas d'un « Vu ».
    expect(screen.queryByRole("button", { name: /vu/i })).not.toBeInTheDocument();
    expect(screen.queryByRole("button", { name: "Pris en compte" })).not.toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Garder Amateo" })).toBeInTheDocument();
  });
});

/**
 * Chantier A PR 4 — l'écart de SALLE s'arbitre par appariement INLINE (fondateur
 * 2026-10-22 Q1 = (b)). CONTRAT que NEO implémente sur `ReviewQueueRow` :
 *  - sur un écart de champ `venue`, « Prendre la source » n'est PAS un bouton direct :
 *    il est précédé d'un sélecteur de gymnase (VenueSelect, aria-label contenant
 *    « gymnase »), offrant en plus une option « Terrain neutre (pas chez nous) » ;
 *  - tant qu'aucun gymnase/neutre n'est choisi, « Prendre … » est DÉSACTIVÉ avec une
 *    raison DÉCOUVRABLE (prop `disabledReason` → `aria-disabled`, clic neutralisé) ;
 *  - un choix de gymnase envoie `onResolve({ fixtureId, field:'venue',
 *    choice:'take_source', venueId:'<uuid>' })` ; « Terrain neutre » envoie
 *    `venueId:'NEUTRAL'`.
 * `ResolveDeviationInput` gagne donc un champ optionnel `venueId?: string`.
 * Promesse : l'arbitrage ne laisse JAMAIS un domicile sans gymnase (ni venueId null
 * silencieux). Les autres champs (date/heure) gardent le bouton direct.
 */
describe("ReviewQueueRow — écart de salle : appariement inline (chantier A PR 4)", () => {
  const VENUE_TRIGGER = /gymnase/i;
  const NEUTRAL_OPTION = "Terrain neutre (pas chez nous)";

  function venueEcart(extra: Partial<Fixture> = {}): Fixture {
    return fx({
      status: "PLACED",
      venueId: "v1", // rattaché → pas de bloc « Rattacher » (isUnattachedHome faux)
      homeAway: "HOME",
      reviewState: "OUT_OF_SYNC",
      pendingDeviations: [{ field: "venue", appValue: "Gymnase Alpha", sourceValue: "GYMNASE INCONNU", channel: "FBI_XLSX", seenAt: "2026-10-01T00:00:00+00:00", autoApplied: false }],
      ...extra,
    });
  }

  function renderEcart(fixture: Fixture, onResolve = vi.fn()) {
    render(
      <ul>
        <ReviewQueueRow fixture={fixture} venues={venues} onValidateLine={vi.fn()} onResolve={onResolve} onPlace={vi.fn()} onAttach={vi.fn()} busy={false} />
      </ul>,
    );
    return { onResolve };
  }

  it("un écart de salle montre un sélecteur de gymnase (jamais un « Prendre » direct)", () => {
    renderEcart(venueEcart());
    expect(listboxTrigger(VENUE_TRIGGER)).toBeInTheDocument();
  });

  it("le sélecteur offre l'option « Terrain neutre (pas chez nous) »", async () => {
    const user = userEvent.setup();
    renderEcart(venueEcart());
    const list = await openListbox(user, VENUE_TRIGGER);
    expect(within(list).getByRole("option", { name: NEUTRAL_OPTION })).toBeInTheDocument();
  });

  it("« Prendre la source » est DÉSACTIVÉ tant qu'aucun gymnase n'est choisi, et le clic n'arbitre rien", async () => {
    const user = userEvent.setup();
    const { onResolve } = renderEcart(venueEcart());
    const take = screen.getByRole("button", { name: /Prendre/ });
    expect(take).toHaveAttribute("aria-disabled", "true");
    await user.click(take);
    expect(onResolve).not.toHaveBeenCalled();
  });

  it("choisir un gymnase puis « Prendre la source » envoie le venueId choisi", async () => {
    const user = userEvent.setup();
    const fixture = venueEcart();
    const { onResolve } = renderEcart(fixture);
    const list = await openListbox(user, VENUE_TRIGGER);
    await user.click(within(list).getByRole("option", { name: "Coubertin" }));
    await user.click(screen.getByRole("button", { name: /Prendre/ }));
    expect(onResolve).toHaveBeenCalledWith({ fixtureId: fixture.id, field: "venue", choice: "take_source", venueId: "v2" });
  });

  it("choisir « Terrain neutre » puis « Prendre la source » envoie venueId « NEUTRAL »", async () => {
    const user = userEvent.setup();
    const fixture = venueEcart();
    const { onResolve } = renderEcart(fixture);
    const list = await openListbox(user, VENUE_TRIGGER);
    await user.click(within(list).getByRole("option", { name: NEUTRAL_OPTION }));
    await user.click(screen.getByRole("button", { name: /Prendre/ }));
    expect(onResolve).toHaveBeenCalledWith({ fixtureId: fixture.id, field: "venue", choice: "take_source", venueId: "NEUTRAL" });
  });
});
