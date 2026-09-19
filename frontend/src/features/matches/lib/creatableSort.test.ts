import { describe, expect, it } from "vitest";

import type { RencontreCreatable } from "../api";
import { sortCreatable } from "./creatableSort";

/** Fabrique une rencontre creatable minimale — seuls les champs de tri comptent. */
function creatable(over: Partial<RencontreCreatable>): RencontreCreatable {
  return {
    rencontreId: over.rencontreId ?? Math.random().toString(36).slice(2),
    competitionNom: "",
    date: over.date ?? "2026-01-01",
    kickoff: over.kickoff ?? null,
    homeAway: "HOME",
    opponentLabel: over.opponentLabel ?? "",
    venueLabel: null,
    numeroJournee: null,
    suggestedTeamId: null,
    ...over,
  };
}

describe("sortCreatable", () => {
  it("trie par date croissante d'abord", () => {
    const out = sortCreatable([
      creatable({ rencontreId: "b", date: "2026-03-10" }),
      creatable({ rencontreId: "a", date: "2026-02-01" }),
      creatable({ rencontreId: "c", date: "2026-04-05" }),
    ]);
    expect(out.map((c) => c.rencontreId)).toEqual(["a", "b", "c"]);
  });

  it("à date égale, trie par heure croissante", () => {
    const out = sortCreatable([
      creatable({ rencontreId: "late", date: "2026-02-01", kickoff: "18:30" }),
      creatable({ rencontreId: "early", date: "2026-02-01", kickoff: "09:00" }),
      creatable({ rencontreId: "mid", date: "2026-02-01", kickoff: "14:05" }),
    ]);
    expect(out.map((c) => c.rencontreId)).toEqual(["early", "mid", "late"]);
  });

  it("place une heure absente en DERNIER du jour, sans déborder sur le jour suivant", () => {
    const out = sortCreatable([
      creatable({ rencontreId: "d2", date: "2026-02-02", kickoff: "10:00" }),
      creatable({ rencontreId: "noon-d1", date: "2026-02-01", kickoff: null }),
      creatable({ rencontreId: "am-d1", date: "2026-02-01", kickoff: "09:00" }),
    ]);
    // Le null reste rangé DANS son jour (après 09:00), jamais après le 02.
    expect(out.map((c) => c.rencontreId)).toEqual(["am-d1", "noon-d1", "d2"]);
  });

  it("à date ET heure égales, départage par adversaire (collation fr)", () => {
    const out = sortCreatable([
      creatable({ rencontreId: "z", date: "2026-02-01", kickoff: "14:00", opponentLabel: "Zoo Basket" }),
      creatable({ rencontreId: "e", date: "2026-02-01", kickoff: "14:00", opponentLabel: "École de Basket" }),
      creatable({ rencontreId: "a", date: "2026-02-01", kickoff: "14:00", opponentLabel: "Amicale" }),
    ]);
    expect(out.map((c) => c.rencontreId)).toEqual(["a", "e", "z"]);
  });

  it("ne mute pas l'entrée", () => {
    const input = [
      creatable({ rencontreId: "b", date: "2026-03-10" }),
      creatable({ rencontreId: "a", date: "2026-02-01" }),
    ];
    const snapshot = input.map((c) => c.rencontreId);
    sortCreatable(input);
    expect(input.map((c) => c.rencontreId)).toEqual(snapshot);
  });
});
