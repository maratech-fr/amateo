import { describe, expect, it } from "vitest";

import { actionableWeeks, addDays, closureWeeksOffer, groupCoverageSlots, holidayWindows, isActionableWeek, mergeSegments, mondayOf, periodAdjustWeeks, periodWeeksToAdjust, segmentLabel, segmentsFromOffer, segmentWeekCount, splitSegment, subtractPlannedWeeks, weeksCovering, buildMonthGrid, daysUntil, isWithin, monthWindow, toISODate, type WeekWindow } from "./date";

describe("cockpit date utils", () => {
  it("builds a 42-cell Monday-first grid", () => {
    // May 2026: 1 May is a Friday.
    const grid = buildMonthGrid(2026, 4);
    expect(grid).toHaveLength(42);
    // First cell is the Monday on/before 1 May 2026 → Mon 27 Apr 2026.
    expect(grid[0].iso).toBe("2026-04-27");
    expect(grid[0].inMonth).toBe(false);
    // 1 May sits at index 4 (Fri).
    const may1 = grid.find((c) => c.iso === "2026-05-01");
    expect(may1?.inMonth).toBe(true);
  });

  it("handles a leap February", () => {
    const grid = buildMonthGrid(2028, 1); // Feb 2028 (leap)
    const feb29 = grid.find((c) => c.iso === "2028-02-29");
    expect(feb29?.inMonth).toBe(true);
  });

  it("does not shift dates across timezones", () => {
    expect(toISODate(new Date(2026, 0, 1))).toBe("2026-01-01");
    expect(toISODate(new Date(2026, 11, 31))).toBe("2026-12-31");
  });

  it("computes the month window from grid bounds", () => {
    const { from, to } = monthWindow(2026, 4);
    expect(from).toBe("2026-04-27");
    expect(to <= "2026-06-08").toBe(true);
    expect(from < to).toBe(true);
  });

  it("checks inclusive range membership", () => {
    expect(isWithin("2026-05-04", "2026-05-04", "2026-05-10")).toBe(true);
    expect(isWithin("2026-05-10", "2026-05-04", "2026-05-10")).toBe(true);
    expect(isWithin("2026-05-11", "2026-05-04", "2026-05-10")).toBe(false);
  });

  it("adds days across month/year boundaries", () => {
    expect(addDays("2026-05-30", 5)).toBe("2026-06-04");
    expect(addDays("2026-12-30", 3)).toBe("2027-01-02");
    expect(addDays("2026-05-04", 300)).toBe("2027-02-28");
  });

  it("counts whole days until", () => {
    expect(daysUntil("2026-05-01", "2026-05-25")).toBe(24);
    expect(daysUntil("2026-05-25", "2026-05-01")).toBe(-24);
    expect(daysUntil("2026-05-01", "2026-05-01")).toBe(0);
  });
});

describe("weeksCovering (P2-5 E1 — la semaine est l'unité hors socle)", () => {
  const season = { startDate: "2026-08-01", endDate: "2027-07-14" };

  it("finds the Monday of any date", () => {
    expect(mondayOf("2026-11-12")).toBe("2026-11-09"); // jeudi → lundi
    expect(mondayOf("2026-11-09")).toBe("2026-11-09"); // lundi → lui-même
    expect(mondayOf("2026-11-15")).toBe("2026-11-09"); // dimanche → lundi d'avant
  });

  it("covers a two-week incident with two full Mon→Sun weeks", () => {
    // L'exemple fondateur : incident 12-18 nov → semaines du 9 et du 16.
    expect(weeksCovering("2026-11-12", "2026-11-18", season)).toEqual([
      { startDate: "2026-11-09", endDate: "2026-11-15", monday: "2026-11-09" },
      { startDate: "2026-11-16", endDate: "2026-11-22", monday: "2026-11-16" },
    ]);
  });

  it("clamps the edge weeks to the season window", () => {
    // Vacances d'été à cheval sur la fin de saison (14 juil) : la dernière
    // semaine est rognée, les semaines entièrement hors saison sont omises.
    const weeks = weeksCovering("2027-07-08", "2027-07-25", season);
    expect(weeks).toEqual([
      { startDate: "2027-07-05", endDate: "2027-07-11", monday: "2027-07-05" },
      { startDate: "2027-07-12", endDate: "2027-07-14", monday: "2027-07-12" },
    ]);
  });

  it("a single-week window yields exactly one week", () => {
    expect(weeksCovering("2026-10-20", "2026-10-22", season)).toHaveLength(1);
  });

  // P2-36 (témoin) — décision fondateur : une semaine créée reste PLEINE (lun→dim), JAMAIS
  // bornée à la fenêtre de l'incident. Ce test échoue si quelqu'un « optimise » weeksCovering
  // en rognant la semaine sur [start, end] : ici l'incident ne dure que mardi→jeudi (20–22),
  // et la semaine rendue doit quand même courir du lundi 19 au dimanche 25.
  it("rend une semaine PLEINE lun→dim, jamais rognée sur la fenêtre de l'incident", () => {
    expect(weeksCovering("2026-10-20", "2026-10-22", season)).toEqual([{ startDate: "2026-10-19", endDate: "2026-10-25", monday: "2026-10-19" }]);
  });
});

describe("periodAdjustWeeks — une semaine est « de vacances » ssi la vacance couvre lun→ven (2026-09-04)", () => {
  const season = { startDate: "2026-08-01", endDate: "2027-07-14" };

  it("écarte la semaine d'entame d'une VACANCE démarrant vendredi (lun→ven non couvert)", () => {
    // Toussaint : vendredi 16 oct → 1er nov. weeksCovering = 12–18 / 19–25 / 26–01.
    // Semaine du 12 : lun 12 → ven 16, la vacance ne couvre que le vendredi → semaine de saison.
    expect(periodAdjustWeeks("2026-10-16", "2026-11-01", season, "holiday")).toEqual([
      { startDate: "2026-10-19", endDate: "2026-10-25", monday: "2026-10-19" },
      { startDate: "2026-10-26", endDate: "2026-11-01", monday: "2026-10-26" },
    ]);
  });

  it("écarte AUSSI la dernière semaine d'une vacance finissant un jeudi (le vendredi reste en saison)", () => {
    // Vacance lun 19 → jeu 22 oct : la seule semaine couvrante a son vendredi 23 en saison → écartée.
    expect(periodAdjustWeeks("2026-10-19", "2026-10-22", season, "holiday")).toEqual([]);
  });

  it("garde une semaine entièrement couverte lun→ven (vacance démarrant lundi)", () => {
    expect(periodAdjustWeeks("2026-10-19", "2026-11-01", season, "holiday")).toEqual(
      weeksCovering("2026-10-19", "2026-11-01", season),
    );
  });

  it("ne s'applique QU'aux vacances : une fermeture démarrant vendredi est inchangée", () => {
    expect(periodAdjustWeeks("2026-10-16", "2026-11-01", season, "closure")).toEqual(
      weeksCovering("2026-10-16", "2026-11-01", season),
    );
  });

  it("une vacance week-end (ven→dim) ne couvre aucun lun→ven → aucune semaine de vacances", () => {
    // Vendredi 16 → dimanche 18 : la seule semaine couvrante n'a aucun jour lun→ven en vacances.
    expect(periodAdjustWeeks("2026-10-16", "2026-10-18", season, "holiday")).toEqual([]);
  });

  it("garde la 1ʳᵉ semaine rognée par le début de saison : ses jours hors saison comptent comme couverts", () => {
    // Saison démarrant un vendredi (2026-08-07) ; vacance clampée à ce vendredi. Semaine du 03/08 :
    // lun–jeu hors saison (couverts), ven 07 en vacances → semaine de vacances (jamais écartée).
    const boundarySeason = { startDate: "2026-08-07", endDate: "2027-07-14" };
    expect(periodAdjustWeeks("2026-08-07", "2026-08-28", boundarySeason, "holiday")).toEqual(
      weeksCovering("2026-08-07", "2026-08-28", boundarySeason),
    );
  });

  it("vacances d'été finissant lundi 31/08 : la semaine du 24 est de vacances (reprise), celle du 31 non", () => {
    const summer = { startDate: "2026-08-01", endDate: "2027-07-14" };
    const mondays = periodAdjustWeeks("2026-07-15", "2026-08-31", summer, "holiday").map((w) => w.monday);
    expect(mondays).toContain("2026-08-24"); // 24–30 août : entièrement de vacances
    expect(mondays).not.toContain("2026-08-31"); // 31/08–06/09 : semaine de saison, absente de la reprise
  });
});

/**
 * P3-13 — UNE SEMAINE EST ACTIONNABLE TANT QU'IL LUI RESTE DES JOURS DEVANT.
 *
 * Le premier jet lisait le besoin comme « la semaine n'a pas COMMENCÉ » (`monday > today`).
 * La revue #344 l'a démonté : « commencé » n'est pas « fini », et confondre les deux rendait
 * une fermeture du mercredi implanifiable dès le lundi. Le critère est la FIN de la semaine
 * — exactement le test que le radar applique déjà aux périodes (`endDate >= today`).
 *
 * Règle PURE : les écrans qui la consomment mockent leurs hooks, seul ce fichier peut
 * réellement l'épingler.
 */
describe("isActionableWeek / actionableWeeks — ce qu'il reste à traiter", () => {
  const week = (monday: string, startDate = monday) => ({ startDate, endDate: addDays(monday, 6), monday });

  it("écarte une semaine RÉVOLUE", () => {
    expect(isActionableWeek(week("2026-01-05"), "2026-02-01")).toBe(false);
    // Le lendemain de sa fin : le dernier jour est passé.
    expect(isActionableWeek(week("2026-01-05"), "2026-01-12")).toBe(false);
  });

  // Le défaut du premier jet, épinglé à l'envers : une semaine ENTAMÉE porte encore du
  // travail. La retirer rendait une fermeture du mercredi implanifiable dès le lundi.
  it("GARDE une semaine entamée dont il reste des jours", () => {
    expect(isActionableWeek(week("2026-01-05"), "2026-01-05")).toBe(true); // le lundi même
    expect(isActionableWeek(week("2026-01-05"), "2026-01-07")).toBe(true); // le mercredi
    expect(isActionableWeek(week("2026-01-05"), "2026-01-11")).toBe(true); // son dernier jour
  });

  it("garde une semaine qui n'a pas commencé", () => {
    expect(isActionableWeek(week("2026-01-12"), "2026-01-07")).toBe(true);
  });

  // Le piège inverse de celui du premier jet : comparer le LUNDI déclarait « commencée »
  // une semaine rognée par le début de saison (saison démarrant un mardi) alors que rien
  // n'en était passé. C'est la FIN qui dit s'il reste quelque chose à faire.
  it("juge sur la FIN, pas sur le lundi rogné par la saison", () => {
    const clamped = week("2026-01-05", "2026-01-08"); // saison démarrant le jeudi
    expect(isActionableWeek(clamped, "2026-01-06")).toBe(true);
  });

  it("actionableWeeks ne garde que ce qui reste, dans l'ordre", () => {
    const weeks = [week("2026-01-05"), week("2026-01-12"), week("2026-01-19")];
    expect(actionableWeeks(weeks, "2026-01-14").map((w: { monday: string }) => w.monday)).toEqual(["2026-01-12", "2026-01-19"]);
  });

  it("rend une liste vide quand tout est derrière (et non la liste entière)", () => {
    expect(actionableWeeks([week("2026-01-05"), week("2026-01-12")], "2026-03-01")).toEqual([]);
  });
});

/**
 * P2-40 — l'UNION des vacances servies (règle d'OFFRE de présentation, écart assumé à la règle
 * d'or : le front dérive ici depuis les données servies, sans miroiter de calcul backend). Deux
 * sources, comme le radar : entrées calendrier de type vacances non ignorées ∪ feed scolaire
 * clampé saison. Une vacance IGNORÉE ne compte pas.
 */
describe("holidayWindows — l'union des vacances servies (P2-40)", () => {
  const season = { startDate: "2026-08-01", endDate: "2027-07-14" };
  const entry = (over: Partial<{ periodType: string | null; status: string; schoolHolidayId: string | null; title: string; startDate: string; endDate: string }>) => ({
    periodType: null as string | null,
    status: "active",
    schoolHolidayId: null as string | null,
    title: "",
    startDate: "",
    endDate: "",
    ...over,
  });

  it("réunit les entrées calendrier de type vacances (non ignorées) et le feed clampé", () => {
    const entries = [
      entry({ periodType: "holiday", title: "Toussaint (mère)", startDate: "2026-10-19", endDate: "2026-11-01" }),
      entry({ periodType: "closure", title: "Gym fermé", startDate: "2026-09-01", endDate: "2026-09-10" }),
    ];
    const feed = [{ id: "ete", label: "Vacances d'été", startDate: "2026-07-04", endDate: "2026-08-31" }];
    expect(holidayWindows(entries, feed, season)).toEqual([
      { label: "Toussaint (mère)", startDate: "2026-10-19", endDate: "2026-11-01" },
      { label: "Vacances d'été", startDate: "2026-08-01", endDate: "2026-08-31" }, // clampé au début de saison
    ]);
  });

  it("écarte une vacance IGNORÉE (matérialisée puis rejetée) du feed", () => {
    const entries = [entry({ periodType: "holiday", status: "ignored", schoolHolidayId: "ete", title: "Été", startDate: "2026-08-01", endDate: "2026-08-31" })];
    const feed = [{ id: "ete", label: "Vacances d'été", startDate: "2026-07-04", endDate: "2026-08-31" }];
    expect(holidayWindows(entries, feed, season)).toEqual([]);
  });

  it("omet une vacance entièrement hors saison", () => {
    const feed = [{ id: "x", label: "Avant saison", startDate: "2026-06-01", endDate: "2026-07-15" }];
    expect(holidayWindows([], feed, season)).toEqual([]);
  });
});

/**
 * P2-40 — l'OFFRE de semaines d'une FERMETURE qui chevauche des vacances : les semaines
 * gouvernées par les vacances sont EXCLUES (pas grisées). Une semaine est exclue ssi son lundi
 * est OFFERT par des vacances (`periodAdjustWeeks(fenêtre, "holiday")` — donc la règle « de
 * vacances » = lundi→vendredi couvert joue : une semaine que la vacance ne couvre pas entièrement
 * lun→ven reste une semaine de saison, offerte par la fermeture). Fonction PURE, foyer unique.
 */
describe("closureWeeksOffer — une fermeture qui chevauche des vacances (P2-40)", () => {
  const season = { startDate: "2026-08-01", endDate: "2027-07-14" };
  const today = "2026-01-01"; // tout est à venir

  it("offre la semaine du 31/08 (vacance finissant lundi 31 → semaine de saison), exclut ce que la vacance couvre", () => {
    // Été jusqu'au lundi 31/08 ; indispo 17/08 → 01/10. La semaine du 31/08 (lun 31 → ven 04/09)
    // n'est PAS entièrement de vacances → semaine de saison, OFFERTE par la fermeture.
    const ete = [{ label: "Vacances d'été", startDate: "2026-08-01", endDate: "2026-08-31" }];
    const { offered, excludedRanges } = closureWeeksOffer("2026-08-17", "2026-10-01", season, today, ete);
    expect(offered.map((w) => w.monday)).toEqual(["2026-08-31", "2026-09-07", "2026-09-14", "2026-09-21", "2026-09-28"]);
    expect(excludedRanges).toEqual([{ startDate: "2026-08-17", endDate: "2026-08-30", labels: ["Vacances d'été"] }]);
  });

  it("conserve la semaine d'entame d'une vacance démarrant VENDREDI (lun→ven non couvert)", () => {
    // Toussaint vendredi 16/10 → 01/11 : la semaine du 12/10 est de saison → offerte par la fermeture.
    const toussaint = [{ label: "Toussaint", startDate: "2026-10-16", endDate: "2026-11-01" }];
    const { offered, excludedRanges } = closureWeeksOffer("2026-10-12", "2026-11-01", season, today, toussaint);
    expect(offered.map((w) => w.monday)).toContain("2026-10-12"); // entame conservée
    expect(offered.map((w) => w.monday)).not.toContain("2026-10-19");
    expect(excludedRanges).toEqual([{ startDate: "2026-10-19", endDate: "2026-11-01", labels: ["Toussaint"] }]);
  });

  it("offre la semaine qu'une vacance finissant MERCREDI ne couvre pas entièrement (jeudi/vendredi en saison)", () => {
    // Vacance 02/11 → mercredi 11/11 : la semaine du 09/11 a jeu 12 et ven 13 en saison → semaine
    // de saison, offerte par la fermeture (avant D4 elle était exclue). Seule la semaine du 02/11
    // (lun→ven en vacances) sort de l'offre.
    const vac = [{ label: "Petites vacances", startDate: "2026-11-02", endDate: "2026-11-11" }]; // 11/11 = mercredi
    const { offered, excludedRanges } = closureWeeksOffer("2026-11-02", "2026-11-22", season, today, vac);
    expect(offered.map((w) => w.monday)).toEqual(["2026-11-09", "2026-11-16"]);
    expect(excludedRanges).toEqual([{ startDate: "2026-11-02", endDate: "2026-11-08", labels: ["Petites vacances"] }]);
  });

  it("vacances absentes → aucune exclusion, identique à periodWeeksToAdjust (comportement inchangé)", () => {
    const offer = closureWeeksOffer("2026-08-17", "2026-10-01", season, today, []);
    expect(offer.excludedRanges).toEqual([]);
    expect(offer.offered).toEqual(periodWeeksToAdjust("2026-08-17", "2026-10-01", season, "closure", today));
  });

  it("respecte l'horizon aujourd'hui (les semaines révolues sont déjà hors offre)", () => {
    const offer = closureWeeksOffer("2026-08-17", "2026-10-01", season, "2026-09-15", []);
    expect(offer.offered.map((w) => w.monday)).toEqual(["2026-09-14", "2026-09-21", "2026-09-28"]);
  });

  it("rend TOUTE la fermeture sous vacances quand chaque semaine est couverte (offre vide)", () => {
    const ete = [{ label: "Vacances d'été", startDate: "2026-07-04", endDate: "2026-08-31" }];
    const offer = closureWeeksOffer("2026-08-03", "2026-08-28", season, today, ete);
    expect(offer.offered).toEqual([]);
    expect(offer.excludedRanges).toHaveLength(1);
    expect(offer.excludedRanges[0].labels).toEqual(["Vacances d'été"]);
  });
});

/**
 * P2-41 PR-C — LE SEGMENT est l'unité hors socle. À partir des semaines OFFERTES (sortie de
 * closureWeeksOffer/periodAdjustWeeks) et de la fenêtre de l'événement, on propose des SEGMENTS aux
 * ruptures GÉOMÉTRIQUES (calculables des données en main, aucune règle solveur redérivée) :
 *  (a) semaine d'entame/de fin PARTIELLE de l'événement → segment de taille 1, le run de semaines
 *      PLEINES adjacent = un segment ;
 *  (b) discontinuité de l'offre (trou vacances P2-40 ou filtre temporel) → chaque run contigu = un
 *      segment.
 * Un run de semaines pleines contiguës sans rupture = UN segment multi-semaines.
 */
describe("segmentsFromOffer — le découpage géométrique en segments (P2-41)", () => {
  const wk = (monday: string, startDate = monday, endDate = addDays(monday, 6)): WeekWindow => ({ startDate, endDate, monday });

  it("exemple normatif : indispo mer 02/09 → dim 27/09 → [entame 31/08] + [bloc 07/09→27/09]", () => {
    const offered = [wk("2026-08-31"), wk("2026-09-07"), wk("2026-09-14"), wk("2026-09-21")];
    const segments = segmentsFromOffer(offered, "2026-09-02", "2026-09-27");
    expect(segments).toEqual([
      { weeks: [wk("2026-08-31")], startDate: "2026-08-31", endDate: "2026-09-06", monday: "2026-08-31", partial: true },
      {
        weeks: [wk("2026-09-07"), wk("2026-09-14"), wk("2026-09-21")],
        startDate: "2026-09-07",
        endDate: "2026-09-27",
        monday: "2026-09-07",
        partial: false,
      },
    ]);
  });

  it("une rupture d'offre au milieu (semaine vacances exclue) → 2 segments contigus", () => {
    // Event 07/09 → 04/10 plein aux deux bords ; la semaine du 21/09 est exclue (trou).
    const offered = [wk("2026-09-07"), wk("2026-09-14"), wk("2026-09-28")];
    const segments = segmentsFromOffer(offered, "2026-09-07", "2026-10-04");
    expect(segments.map((s) => s.monday)).toEqual(["2026-09-07", "2026-09-28"]);
    expect(segments[0].weeks.map((w) => w.monday)).toEqual(["2026-09-07", "2026-09-14"]);
    expect(segments[1].weeks.map((w) => w.monday)).toEqual(["2026-09-28"]);
    // La semaine isolée par le trou est PLEINE (pas entamée) — pas de « (entamée) ».
    expect(segments[1].partial).toBe(false);
  });

  it("entame ET fin partielles → deux segments de taille 1, chacun partiel", () => {
    // Event mer 02/09 → ven 11/09 : la semaine du 31/08 est entamée, celle du 07/09 finit ven.
    const offered = [wk("2026-08-31"), wk("2026-09-07")];
    const segments = segmentsFromOffer(offered, "2026-09-02", "2026-09-11");
    expect(segments).toHaveLength(2);
    expect(segments.every((s) => 1 === s.weeks.length && s.partial)).toBe(true);
  });

  it("offre vide → aucun segment", () => {
    expect(segmentsFromOffer([], "2026-09-02", "2026-09-27")).toEqual([]);
  });

  it("un run unique sans rupture → UN segment multi-semaines", () => {
    const offered = [wk("2026-09-07"), wk("2026-09-14"), wk("2026-09-21")];
    const segments = segmentsFromOffer(offered, "2026-09-07", "2026-09-27");
    expect(segments).toHaveLength(1);
    expect(segments[0].weeks).toHaveLength(3);
    expect(segments[0].partial).toBe(false);
  });

  it("une seule semaine pleine → un segment de taille 1 non partiel", () => {
    const offered = [wk("2026-09-07")];
    const segments = segmentsFromOffer(offered, "2026-09-07", "2026-09-13");
    expect(segments).toEqual([{ weeks: [wk("2026-09-07")], startDate: "2026-09-07", endDate: "2026-09-13", monday: "2026-09-07", partial: false }]);
  });
});

describe("segmentLabel / segmentWeekCount / splitSegment / mergeSegments (P2-41)", () => {
  const wk = (monday: string, startDate = monday, endDate = addDays(monday, 6)): WeekWindow => ({ startDate, endDate, monday });
  const [entame, bloc] = segmentsFromOffer([wk("2026-08-31"), wk("2026-09-07"), wk("2026-09-14"), wk("2026-09-21")], "2026-09-02", "2026-09-27");

  it("nomme un segment entamé, un segment plein isolé et un segment multi-semaines", () => {
    expect(segmentLabel(entame)).toBe("Semaine du 31 août 2026 (entamée)");
    expect(segmentLabel(bloc)).toBe("Semaines du 7 sept. 2026 au 27 sept. 2026 — d'un bloc (3 semaines)");
    const plein = segmentsFromOffer([wk("2026-09-07")], "2026-09-07", "2026-09-13")[0];
    expect(segmentLabel(plein)).toBe("Semaine du 7 sept. 2026");
  });

  // A2 — un libellé DANS la saison courante omet l'année (le radar déborde sinon) ; il la garde
  // dès que la fenêtre sort de la saison affichée, où l'année lève l'ambiguïté.
  it("omet l'année quand la fenêtre tient dans la saison, la garde sinon", () => {
    const season = { startDate: "2026-08-01", endDate: "2027-07-31" };
    expect(segmentLabel(bloc, season)).toBe("Semaines du 7 sept. au 27 sept. — d'un bloc (3 semaines)");
    expect(segmentLabel(entame, season)).toBe("Semaine du 31 août (entamée)");

    // Fenêtre hors de la saison affichée → l'année reste (désambiguïsation).
    const otherSeason = { startDate: "2027-01-01", endDate: "2027-12-31" };
    expect(segmentLabel(bloc, otherSeason)).toBe("Semaines du 7 sept. 2026 au 27 sept. 2026 — d'un bloc (3 semaines)");

    // Sans saison connue → comportement historique (année conservée).
    expect(segmentLabel(bloc)).toBe("Semaines du 7 sept. 2026 au 27 sept. 2026 — d'un bloc (3 semaines)");
  });

  it("compte les semaines d'un segment (span, y compris par-dessus un trou fusionné)", () => {
    expect(segmentWeekCount(entame)).toBe(1);
    expect(segmentWeekCount(bloc)).toBe(3);
  });

  it("scinde un segment multi-semaines en semaines individuelles (pleines)", () => {
    const parts = splitSegment(bloc);
    expect(parts.map((s) => s.monday)).toEqual(["2026-09-07", "2026-09-14", "2026-09-21"]);
    expect(parts.every((s) => 1 === s.weeks.length && !s.partial)).toBe(true);
  });

  it("fusionne deux segments adjacents en un bloc unique (l'entame absorbée dans le bloc)", () => {
    const merged = mergeSegments(entame, bloc);
    expect(merged.monday).toBe("2026-08-31");
    expect(merged.startDate).toBe("2026-08-31");
    expect(merged.endDate).toBe("2026-09-27");
    expect(merged.weeks.map((w) => w.monday)).toEqual(["2026-08-31", "2026-09-07", "2026-09-14", "2026-09-21"]);
    expect(merged.partial).toBe(false);
    expect(segmentWeekCount(merged)).toBe(4);
  });

  it("fusionne par-dessus une rupture d'offre : le span couvre le trou, les semaines offertes non", () => {
    // 07/09+14/09 puis 28/09 (21/09 exclue) : le bloc fusionné va du 07/09 au 04/10 (4 semaines de
    // span), mais ne porte que les 3 semaines OFFERTES.
    const [a, b] = segmentsFromOffer([wk("2026-09-07"), wk("2026-09-14"), wk("2026-09-28")], "2026-09-07", "2026-10-04");
    const merged = mergeSegments(a, b);
    expect(merged.startDate).toBe("2026-09-07");
    expect(merged.endDate).toBe("2026-10-04");
    expect(segmentWeekCount(merged)).toBe(4);
    expect(merged.weeks).toHaveLength(3);
  });
});

/**
 * P2-41 PR-C — la carte de couverture groupe PAR ENFANT : un enfant-segment retrouvé sur N semaines
 * consécutives devient UNE entrée (son libellé du X au Y). Les semaines MANQUANTES (child null)
 * restent individuelles — le geste « + créer » est ponctuel, à la semaine.
 */
describe("groupCoverageSlots — regroupement des semaines par enfant (P2-41)", () => {
  const wk = (monday: string): WeekWindow => ({ startDate: monday, endDate: addDays(monday, 6), monday });
  const child = (id: string) => ({ id });

  it("groupe des semaines consécutives portées par le MÊME enfant en une entrée", () => {
    const seg = child("seg-1");
    const groups = groupCoverageSlots([
      { week: wk("2026-09-07"), child: seg },
      { week: wk("2026-09-14"), child: seg },
      { week: wk("2026-09-21"), child: seg },
    ]);
    expect(groups).toHaveLength(1);
    expect(groups[0].child).toBe(seg);
    expect(groups[0].startDate).toBe("2026-09-07");
    expect(groups[0].endDate).toBe("2026-09-27");
    expect(groups[0].weeks).toHaveLength(3);
  });

  it("garde les semaines MANQUANTES individuelles (une entrée par semaine à créer)", () => {
    const groups = groupCoverageSlots([
      { week: wk("2026-09-07"), child: null },
      { week: wk("2026-09-14"), child: null },
    ]);
    expect(groups).toHaveLength(2);
    expect(groups.every((g) => null === g.child)).toBe(true);
  });

  it("sépare deux enfants distincts et intercale une semaine manquante", () => {
    const a = child("a");
    const b = child("b");
    const groups = groupCoverageSlots([
      { week: wk("2026-09-07"), child: a },
      { week: wk("2026-09-14"), child: a },
      { week: wk("2026-09-21"), child: null },
      { week: wk("2026-09-28"), child: b },
    ]);
    expect(groups.map((g) => g.child?.id ?? null)).toEqual(["a", null, "b"]);
    expect(groups[0].weeks).toHaveLength(2);
  });
});

// P2-38 (prévention) — subtractPlannedWeeks retire les semaines qu'une plage SERVIE (backend)
// recoupe ; les plages ne sont jamais recalculées côté front (règle d'or). Fail-open : aucune plage
// ⇒ l'offre est rendue intacte.
describe("subtractPlannedWeeks (P2-38)", () => {
  const wk = (monday: string): WeekWindow => ({ startDate: monday, endDate: addDays(monday, 6), monday });
  const offered = [wk("2026-11-09"), wk("2026-11-16"), wk("2026-11-23")];

  it("aucune plage → l'offre est rendue intacte (fail-open)", () => {
    expect(subtractPlannedWeeks(offered, [])).toBe(offered);
  });

  it("retire la seule semaine que la plage recoupe (chevauchement inclusif)", () => {
    const result = subtractPlannedWeeks(offered, [{ startDate: "2026-11-16", endDate: "2026-11-22" }]);
    expect(result.map((w) => w.monday)).toEqual(["2026-11-09", "2026-11-23"]);
  });

  it("un chevauchement même partiel suffit à retirer une semaine", () => {
    // La plage empiète d'un seul jour sur la 1ʳᵉ semaine (dimanche 15) et couvre la 2ᵉ.
    const result = subtractPlannedWeeks(offered, [{ startDate: "2026-11-15", endDate: "2026-11-20" }]);
    expect(result.map((w) => w.monday)).toEqual(["2026-11-23"]);
  });

  it("plusieurs plages se cumulent", () => {
    const result = subtractPlannedWeeks(offered, [
      { startDate: "2026-11-09", endDate: "2026-11-15" },
      { startDate: "2026-11-23", endDate: "2026-11-29" },
    ]);
    expect(result.map((w) => w.monday)).toEqual(["2026-11-16"]);
  });
});
