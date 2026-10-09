import { cleanup, render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import type { CalendarEntry } from "@/features/cockpit/api";
import { setTodayOverride } from "@/shared/lib/clock";

import type { CoachWishCampaign } from "./campaignApi";

// La fenêtre RÉSOUT elle-même sa campagne (décision 8) via `useCoachWishCampaigns`, et ses
// doléances via `useCoachWishes` : on pilote les deux lectures par ces objets mutables (même
// idiome que `teamsLate`/`teamsFetching` — propriété lue PAR LA PARESSE dans la factory).
const campaignsState: { data: CoachWishCampaign[] | undefined; isError: boolean } = { data: [], isError: false };
const wishesState: { data: unknown[] | undefined; isError: boolean } = { data: [], isError: false };

const teamsLate = { value: false };
const teamsFetching = { value: false };
const teamCoachesUnread = { value: false };
vi.mock("@/features/wizard/queries", () => ({
  // Rangs posés : t3/U11 en fanion (S) mais SANS coach — elle ne doit apparaître nulle
  // part ; t1/SM1 et t2/U13 en rang B, dans cet ordre de `tierOrder`.
  // `isFetching` compte : la graine attend une lecture POSÉE, pas un cache périmé servi
  // pendant que le refetch tourne (revue #346 round 2).
  useWizardTeams: () => ({
    isFetching: teamsFetching.value,
    data: teamsLate.value
      ? []
      : [
          { id: "t1", name: "SM1", isActive: true, priorityTierId: 3, tierOrder: 0 },
          { id: "t2", name: "U13", isActive: true, priorityTierId: 3, tierOrder: 1 },
          { id: "t3", name: "U11", isActive: true, priorityTierId: 1, tierOrder: 0 },
        ],
  }),
  usePriorityTiers: () => ({ data: [{ id: 1, label: "S", name: "Fanion", color: null }, { id: 3, label: "B", name: "Moyenne", color: null }] }),
  useWizardTeamCoaches: () => ({ data: teamCoachesUnread.value ? undefined : [{ id: "tc1", teamId: "t1", coachId: "c1", role: "MAIN" }, { id: "tc2", teamId: "t2", coachId: "c2", role: "MAIN" }] }),
  useUpdateCoach: () => ({ mutate: vi.fn() }),
}));

const createMut = vi.fn();
const updateMut = vi.fn();
vi.mock("./campaignQueries", () => ({
  useCoachWishCampaigns: () => ({ data: campaignsState.data, isError: campaignsState.isError, isFetching: false, refetch: vi.fn() }),
  useCreateCoachWishCampaign: () => ({ mutate: createMut, isPending: false, isError: false }),
  useUpdateCoachWishCampaign: () => ({ mutate: updateMut, isPending: false, isError: false }),
}));
vi.mock("./queries", () => ({ useCoachWishes: () => ({ data: wishesState.data, isError: wishesState.isError }) }));
vi.mock("@/shared/session/queries", () => ({ useWorkingSeason: () => ({ startDate: "2025-09-01", endDate: "2026-06-30" }) }));

// Les onglets Doléances et Sollicitation sont testés dans leurs propres fichiers
// (`WishesTab.test.tsx`, `SollicitationTab.test.tsx`) : ici on neutralise leur contenu pour
// tester l'ORCHESTRATION du hub (onglets, onglet d'ouverture, pied de modale, compteur).
vi.mock("./WishesTab", () => ({ WishesTab: () => null }));
vi.mock("./SollicitationTab", () => ({ SollicitationTab: () => null }));

import { CoachWishesHub } from "./CoachWishesHub";

const entry: CalendarEntry = {
  id: "e1",
  kind: "period",
  title: "Vacances de février",
  startDate: "2026-02-16",
  endDate: "2026-03-01",
  isDisruptive: false,
  periodType: "holiday",
  schoolHolidayId: null,
  parentEntryId: null,
  status: "active",
  createdBy: null,
  redatable: false, redateNeedsPreview: false,
};

/** Monte le hub en pilotant les deux lectures qu'il résout lui-même (campagne + doléances). */
const renderHub = (opts: { existing?: CoachWishCampaign | null; wishes?: unknown[]; source?: "wizard" | "cockpit" } = {}) => {
  campaignsState.data = opts.existing ? [opts.existing] : [];
  wishesState.data = opts.wishes ?? [];
  return render(<CoachWishesHub mother={entry} weekFilter={null} source={opts.source ?? "cockpit"} onClose={vi.fn()} />);
};

describe("CoachWishesHub", () => {
  // P3-13/P3-15 (c) — la campagne ne propose que les semaines À VENIR. Ces fixtures sont
  // datées (fév. 2026) : sans horloge pilotable, elles deviennent du passé au fil du temps
  // et le test se met à échouer tout seul — il l'était déjà devenu. On ancre « aujourd'hui »
  // deux semaines avant la période plutôt que de repasser les dates en relatif.
  beforeEach(() => {
    setTodayOverride("2026-02-01");
    teamsLate.value = false;
    teamsFetching.value = false;
    teamCoachesUnread.value = false;
    campaignsState.data = [];
    campaignsState.isError = false;
    wishesState.data = [];
    wishesState.isError = false;
    createMut.mockReset();
    updateMut.mockReset();
  });
  afterEach(() => {
    setTodayOverride(null);
    vi.unstubAllGlobals();
  });

  it("crée une campagne avec les semaines et équipes choisies", async () => {
    renderHub();

    await userEvent.click(screen.getByRole("button", { name: /Créer la collecte/ }));

    expect(createMut).toHaveBeenCalledTimes(1);
    const body = createMut.mock.calls[0][0];
    expect(body.calendarEntryId).toBe("e1");
    expect(body.weeks.length).toBeGreaterThan(0);
    expect(body.deadline).toBe("2026-02-16");
  });

  // ── P3-15 (a)(b) : une modale qu'on peut lire (retour terrain 2026-07-31) ──

  // ⚠ L'assertion porte sur le CONTENU envoyé, pas sur `canSave` : une sélection vide
  // laisserait `canSave` faux, mais un test qui ne regarderait que le bouton passerait
  // aussi bien avec un défaut cassé.
  it("démarre une nouvelle collecte avec TOUTES les équipes ayant un coach", async () => {
    renderHub();

    // Le résumé le dit d'une ligne, sans rien déplier.
    expect(screen.getByText(/Toutes les équipes \(2\)/)).toBeInTheDocument();

    await userEvent.click(screen.getByRole("button", { name: /Créer la collecte/ }));
    // t3/U11 n'a pas de coach : elle n'est ni comptée ni envoyée.
    expect(createMut.mock.calls[0][0].teamIds.sort()).toEqual(["t1", "t2"]);
  });

  // Le cœur du besoin : 49 équipes ne s'empilent plus, elles se replient derrière une ligne.
  it("garde le sélecteur d'équipes replié, et le déplie à la demande", async () => {
    renderHub();

    const toggle = screen.getByRole("button", { name: /Modifier les équipes/ });
    expect(toggle).toHaveAttribute("aria-expanded", "false");
    expect(screen.queryByRole("button", { name: "SM1" })).toBeNull();

    await userEvent.click(toggle);
    expect(screen.getByRole("button", { name: "SM1" })).toHaveAttribute("aria-pressed", "true");
  });

  // Agir en masse : c'est ce qui manquait le plus avec une ligne par équipe.
  it("permet de tout décocher puis de tout recocher d'un geste", async () => {
    renderHub();

    await userEvent.click(screen.getByRole("button", { name: /Modifier les équipes/ }));
    await userEvent.click(screen.getByRole("button", { name: "tout décocher" }));
    expect(screen.getByText(/0 équipe sur 2/)).toBeInTheDocument();
    expect(screen.getByRole("button", { name: /Créer la collecte/ })).toBeDisabled();

    await userEvent.click(screen.getByRole("button", { name: "tout cocher" }));
    expect(screen.getByText(/Toutes les équipes \(2\)/)).toBeInTheDocument();
  });

  // (b) Les équipes sont groupées par RANG, comme partout où une équipe se choisit.
  it("groupe les équipes du sélecteur par rang", async () => {
    renderHub();

    await userEvent.click(screen.getByRole("button", { name: /Modifier les équipes/ }));
    expect(screen.getByText("B · Moyenne")).toBeInTheDocument();
    // U11 (rang S) n'a pas de coach : son groupe n'existe pas non plus.
    expect(screen.queryByText("S · Fanion")).toBeNull();
  });

  // Une campagne EXISTANTE rouvre sur SA sélection — jamais sur « toutes », ce qui
  // élargirait la collecte en silence à des équipes que le gestionnaire avait écartées.
  it("rouvre une campagne existante sur sa propre sélection, pas sur « toutes »", async () => {
    const existing: CoachWishCampaign = {
      id: "camp1",
      calendarEntryId: "e1",
      deadline: "2027-06-30",
      weeks: ["2026-02-16"],
      teamIds: ["t1"],
      totalCoachCount: 1,
      respondedCoachCount: 0,
      openWishCount: 0,
      lastReminderAt: null,
      coaches: [],
    };
    renderHub({ existing });

    // Rouverture d'une campagne propre : la fenêtre ouvre sur Doléances (décision 3) ; on passe aux
    // Réglages pour vérifier que la sélection d'équipes rouvre sur SA sélection, jamais « toutes ».
    await userEvent.click(screen.getByRole("tab", { name: "Réglages" }));
    expect(screen.getByText(/1 équipe sur 2/)).toBeInTheDocument();
  });

  // L'onglet « Sollicitation » n'existe que si une campagne existe (décision 2). Et à la
  // ré-ouverture d'une campagne SAINE, la fenêtre ouvre sur Doléances (décision 3), pas sur le suivi.
  it("n'offre l'onglet Sollicitation qu'avec une campagne, et rouvre une campagne saine sur Doléances", () => {
    renderHub();
    expect(screen.queryByRole("tab", { name: /Sollicitation/ })).toBeNull();

    cleanup();
    const existing: CoachWishCampaign = {
      id: "camp1",
      calendarEntryId: "e1",
      deadline: "2027-06-30",
      weeks: ["2026-02-16"],
      teamIds: ["t1"],
      totalCoachCount: 1,
      respondedCoachCount: 0,
      openWishCount: 0,
      lastReminderAt: null,
      coaches: [{ coachId: "c1", firstName: "Maxime", lastName: "Durand", email: null, token: "a".repeat(64), respondedAt: null, sentAt: null }],
    };
    renderHub({ existing });
    expect(screen.getByRole("tab", { name: /Sollicitation/ })).toHaveAttribute("aria-selected", "false");
    expect(screen.getByRole("tab", { name: "Doléances" })).toHaveAttribute("aria-selected", "true");
  });

  // ── P3-15 (c) : on ne sollicite un coach que pour ce qu'il RESTE (retour 2026-07-31) ──

  // « Les semaines passées et la semaine en cours sont proposées ET cochées par défaut » :
  // le gestionnaire envoyait à ses coachs un lien pour dire leurs souhaits sur du révolu.
  // ⚠ RÉVOLU, pas « entamé » (revue #344) : une vacance qui démarre un samedi n'aurait
  // plus pu faire l'objet d'aucune collecte dès le lundi suivant, pour des séances
  // pourtant toutes à venir — et rien d'autre dans l'app ne crée une campagne.
  it("n'offre ni ne coche une semaine révolue, garde celle qui est entamée", () => {
    // Période du 16/02 au 01/03 = deux semaines (16 et 23). « Aujourd'hui » = lundi 23 :
    // la semaine du 16 est finie, celle du 23 court encore.
    setTodayOverride("2026-02-23");
    renderHub();

    expect(screen.queryByLabelText(/Semaine du 16/)).toBeNull();
    const current = screen.getByLabelText(/Semaine du 23/);
    expect(current).toBeInTheDocument();
    expect(current).toBeChecked();
  });

  // Le cas qui rendait la collecte IMPOSSIBLE avec le premier critère : la période
  // n'a plus qu'une semaine, entamée, mais ses jours utiles sont devant.
  it("laisse créer une collecte sur une semaine entamée aux jours encore à venir", () => {
    setTodayOverride("2026-02-25"); // mercredi de la dernière semaine, qui finit le 01/03
    renderHub();

    expect(screen.queryByText(/Aucune semaine disponible/)).toBeNull();
    expect(screen.getByLabelText(/Semaine du 23/)).toBeChecked();
  });

  // ATTEINDRE ≠ CHOISIR : une campagne EXISTANTE peut porter une semaine devenue révolue.
  // La masquer laisserait l'état porter un lundi que l'écran ne montre pas et que
  // l'enregistrement renverrait — un état invisible est un état faux. Le marqueur vit
  // DANS le nom accessible, sinon un lecteur d'écran ne l'entend jamais (revue #344).
  it("garde visible et marquée une semaine déjà retenue devenue révolue", () => {
    setTodayOverride("2026-02-25");
    const existing: CoachWishCampaign = {
      id: "camp1",
      calendarEntryId: "e1",
      deadline: "2026-03-01",
      weeks: ["2026-02-16"],
      teamIds: ["t1"],
      totalCoachCount: 1,
      respondedCoachCount: 0,
      openWishCount: 0,
      lastReminderAt: null,
      coaches: [],
    };
    renderHub({ existing });

    const past = screen.getByLabelText("Semaine du 16/02/2026 (révolue)");
    expect(past).toBeInTheDocument();
    expect(past).toBeChecked();
  });

  // ── Revue #344 round 2 ──

  // La date limite par défaut valait `entry.startDate`, donc DANS LE PASSÉ dès que la
  // période a commencé — le cas que ce lot vient de rendre légitime. Les liens partaient
  // morts (410 « deadline dépassée ») et rien ne le disait au gestionnaire.
  it("ne propose jamais une date limite déjà passée", () => {
    setTodayOverride("2026-02-25"); // la période a commencé le 16
    renderHub();

    const deadline = screen.getByLabelText("Date limite") as HTMLInputElement;
    expect(deadline.value).toBe("2026-02-25");
    expect(deadline.min).toBe("2026-02-25");
  });

  // Une semaine retenue par la campagne peut ne PLUS être émise (période redimensionnée,
  // saison déplacée). La filtrer la laissait invisible tout en la gardant dans l'état, que
  // l'enregistrement renvoyait : on sollicite pour une semaine jamais montrée.
  it("montre une semaine retenue que la période n'émet plus", () => {
    setTodayOverride("2026-02-01");
    const existing: CoachWishCampaign = {
      id: "camp1",
      calendarEntryId: "e1",
      deadline: "2026-03-01",
      weeks: ["2026-02-02"], // hors de la période 16/02 → 01/03 : plus émise du tout
      teamIds: ["t1"],
      totalCoachCount: 1,
      respondedCoachCount: 0,
      openWishCount: 0,
      lastReminderAt: null,
      coaches: [],
    };
    renderHub({ existing });

    const orphan = screen.getByLabelText(/Semaine du 02\/02\/2026/);
    expect(orphan).toBeInTheDocument();
    expect(orphan).toBeChecked();
  });

  // ── Revue #346 : ce que mes propres choix avaient défait ──

  // Une campagne existante peut porter une équipe qui a perdu son coach. Ne rendre que les
  // ÉLIGIBLES la laissait invisible : « tout décocher » restait sans effet sur elle, le
  // résumé annonçait « 0 » et l'enregistrement la postait quand même.
  it("montre, marque et décoche une équipe sélectionnée qui n'a plus de coach", async () => {
    const existing: CoachWishCampaign = {
      id: "camp1",
      calendarEntryId: "e1",
      deadline: "2027-06-30",
      weeks: ["2026-02-23"],
      teamIds: ["t1", "t3"], // t3/U11 n'a AUCUN coach : inéligible, mais retenue
      totalCoachCount: 1,
      respondedCoachCount: 0,
      openWishCount: 0,
      lastReminderAt: null,
      coaches: [],
    };
    renderHub({ existing });

    await userEvent.click(screen.getByRole("tab", { name: /Réglages/ }));
    await userEvent.click(screen.getByRole("button", { name: /Modifier les équipes/ }));
    expect(screen.getByRole("button", { name: /U11 \(ne peut plus être sollicitée\)/ })).toHaveAttribute("aria-pressed", "true");

    await userEvent.click(screen.getByRole("button", { name: "tout décocher" }));
    // Le résumé et l'enregistrement disent la MÊME chose : plus rien n'est sélectionné.
    expect(screen.getByRole("button", { name: /Enregistrer/ })).toBeDisabled();
  });

  // Après création, le gestionnaire vient chercher les liens : on bascule sur l'onglet
  // « Sollicitation » (décision fondateur). Sans ça (revue #346), le bouton changeait de libellé,
  // un onglet apparaissait discrètement, et rien d'autre ne bougeait — on croyait l'échec.
  it("bascule sur l'onglet Sollicitation après la création", async () => {
    createMut.mockImplementation((_body: unknown, opts: { onSuccess: (c: CoachWishCampaign) => void }) =>
      opts.onSuccess({
        id: "camp1",
        calendarEntryId: "e1",
        deadline: "2026-03-01",
        weeks: ["2026-02-23"],
        teamIds: ["t1"],
        totalCoachCount: 1,
        respondedCoachCount: 0,
        openWishCount: 0,
        lastReminderAt: null,
        coaches: [{ coachId: "c1", firstName: "Maxime", lastName: "Durand", email: null, token: "a".repeat(64), respondedAt: null, sentAt: null }],
      }),
    );
    renderHub();

    await userEvent.click(screen.getByRole("button", { name: /Créer la collecte/ }));
    expect(screen.getByRole("tab", { name: /Sollicitation/ })).toHaveAttribute("aria-selected", "true");
  });

  // #344 exigeait qu'une semaine retenue mais révolue reste SOUS LES YEUX. Mon onglet par
  // défaut la reléguait derrière un clic que le chemin fréquent ne déclenche jamais.
  it("ouvre sur Réglages quand une semaine retenue demande une correction", () => {
    setTodayOverride("2026-02-25"); // la semaine du 23 court, celle du 16 est révolue
    const existing: CoachWishCampaign = {
      id: "camp1",
      calendarEntryId: "e1",
      deadline: "2026-03-01",
      weeks: ["2026-02-16"],
      teamIds: ["t1"],
      totalCoachCount: 1,
      respondedCoachCount: 0,
      openWishCount: 0,
      lastReminderAt: null,
      coaches: [],
    };
    renderHub({ existing });

    expect(screen.getByRole("tab", { name: /Réglages/ })).toHaveAttribute("aria-selected", "true");
    // VISIBLE, pas seulement présent : `getByLabelText` ne filtre pas le contenu caché, ce
    // qui laissait les deux gardes de #344 passer dans un panneau `hidden`.
    expect(screen.getByLabelText(/Semaine du 16\/02\/2026 \(révolue\)/)).toBeVisible();
  });

  // Le défaut « toutes les équipes » n'était gardé par RIEN : les mocks rendent la donnée
  // dès le premier rendu, donc la condition même pour laquelle il existe — des équipes qui
  // arrivent APRÈS — n'était jamais simulée (revue #346, prouvé par falsification).
  it("coche toutes les équipes même quand elles arrivent après le premier rendu", async () => {
    teamsLate.value = true;
    const { rerender } = renderHub();
    expect(screen.getByText(/Aucune équipe avec un coach rattaché/)).toBeInTheDocument();

    teamsLate.value = false;
    teamsFetching.value = false;
    teamCoachesUnread.value = false;
    rerender(<CoachWishesHub mother={entry} weekFilter={null} source="cockpit" onClose={vi.fn()} />);
    expect(screen.getByText(/Toutes les équipes \(2\)/)).toBeInTheDocument();

    await userEvent.click(screen.getByRole("button", { name: /Créer la collecte/ }));
    expect(createMut.mock.calls[0][0].teamIds.sort()).toEqual(["t1", "t2"]);
  });

  // ── Revue #346 round 2 : ce que mes correctifs du round 1 avaient laissé passer ──

  // Un cache chaud mais PÉRIMÉ est servi immédiatement pendant que le refetch tourne :
  // semer là-dessus verrouillait une liste incomplète, annoncée comme « toutes ».
  it("attend que la lecture soit POSÉE avant de semer, pas seulement non vide", async () => {
    teamsFetching.value = true; // cache périmé servi, refetch en cours
    const { rerender } = renderHub();
    // Rien n'est semé tant que la lecture n'est pas posée : le résumé dit la vérité
    // (« 0 sur 2 ») plutôt que d'annoncer « toutes » sur une liste peut-être incomplète.
    expect(screen.getByText(/0 équipe sur 2/)).toBeInTheDocument();

    teamsFetching.value = false; // le refetch a répondu : liste complète
    rerender(<CoachWishesHub mother={entry} weekFilter={null} source="cockpit" onClose={vi.fn()} />);
    expect(screen.getByText(/Toutes les équipes \(2\)/)).toBeInTheDocument();

    await userEvent.click(screen.getByRole("button", { name: /Créer la collecte/ }));
    expect(createMut.mock.calls[0][0].teamIds.sort()).toEqual(["t1", "t2"]);
  });

  // On n'ACCUSE que sur une donnée lue : tant que les liens coachs n'ont pas répondu,
  // toutes les équipes s'affichaient « ne peut plus être sollicitée ».
  it("n'accuse aucune équipe tant que les liens coachs ne sont pas lus", async () => {
    teamCoachesUnread.value = true;
    const existing: CoachWishCampaign = {
      id: "camp1",
      calendarEntryId: "e1",
      deadline: "2027-06-30",
      weeks: ["2026-02-23"],
      teamIds: ["t1", "t2"],
      totalCoachCount: 1,
      respondedCoachCount: 0,
      openWishCount: 0,
      lastReminderAt: null,
      coaches: [],
    };
    renderHub({ existing });

    await userEvent.click(screen.getByRole("tab", { name: /Réglages/ }));
    await userEvent.click(screen.getByRole("button", { name: /Modifier les équipes/ }));
    expect(screen.queryByRole("button", { name: /ne peut plus être sollicitée/ })).toBeNull();
  });

  // Une équipe SUPPRIMÉE n'est dans aucune requête : sans un libellé de repli, elle restait
  // dans la sélection, invisible, indécochable, et partait quand même au POST.
  it("rend atteignable une équipe supprimée encore portée par la campagne", async () => {
    const existing: CoachWishCampaign = {
      id: "camp1",
      calendarEntryId: "e1",
      deadline: "2027-06-30",
      weeks: ["2026-02-23"],
      teamIds: ["t1", "tSupprimee"],
      totalCoachCount: 1,
      respondedCoachCount: 0,
      openWishCount: 0,
      lastReminderAt: null,
      coaches: [],
    };
    renderHub({ existing });

    await userEvent.click(screen.getByRole("tab", { name: /Réglages/ }));
    await userEvent.click(screen.getByRole("button", { name: /Modifier les équipes/ }));
    expect(screen.getByRole("button", { name: /Équipe supprimée/ })).toBeInTheDocument();

    await userEvent.click(screen.getByRole("button", { name: "tout décocher" }));
    // Le bouton et la sélection disent la MÊME chose : plus rien ne partira.
    expect(screen.getByRole("button", { name: /Enregistrer/ })).toBeDisabled();
  });

  // Le résumé compte ce qui produira un lien : compter les inéligibles des deux côtés
  // annonçait « Toutes les équipes (3) » quand deux seulement seraient sollicitées.
  it("ne compte dans le résumé que les équipes qui produiront un lien", async () => {
    const existing: CoachWishCampaign = {
      id: "camp1",
      calendarEntryId: "e1",
      deadline: "2027-06-30",
      weeks: ["2026-02-23"],
      teamIds: ["t1", "t2", "t3"], // t3/U11 n'a pas de coach
      totalCoachCount: 1,
      respondedCoachCount: 0,
      openWishCount: 0,
      lastReminderAt: null,
      coaches: [],
    };
    renderHub({ existing });

    await userEvent.click(screen.getByRole("tab", { name: /Réglages/ }));
    expect(screen.getByText(/Toutes les équipes \(2\) · 1 sans coach, à retirer/)).toBeInTheDocument();
  });

  // #344 visait la semaine que la période n'ÉMET PLUS ; mon premier jet ne rattrapait que
  // la semaine révolue, et une orpheline encore future ouvrait donc sur l'onglet Coachs.
  it("ouvre sur Réglages quand une semaine retenue n'est plus émise par la période", () => {
    setTodayOverride("2026-02-01");
    const existing: CoachWishCampaign = {
      id: "camp1",
      calendarEntryId: "e1",
      deadline: "2026-03-01",
      weeks: ["2026-03-09"], // hors de la période 16/02 → 01/03, et encore future
      teamIds: ["t1"],
      totalCoachCount: 1,
      respondedCoachCount: 0,
      openWishCount: 0,
      lastReminderAt: null,
      coaches: [],
    };
    renderHub({ existing });

    expect(screen.getByRole("tab", { name: /Réglages/ })).toHaveAttribute("aria-selected", "true");
  });

  // Le focus suit la bascule : sans ça il retombe sur `<body>`, et le piège à focus comme
  // Échap — qui écoutent sur le panneau de la modale — cessent d'agir.
  it("emporte le focus avec l'onglet après la création", async () => {
    createMut.mockImplementation((_body: unknown, opts: { onSuccess: (c: CoachWishCampaign) => void }) =>
      opts.onSuccess({
        id: "camp1",
        calendarEntryId: "e1",
        deadline: "2026-03-01",
        weeks: ["2026-02-23"],
        teamIds: ["t1"],
        totalCoachCount: 1,
        respondedCoachCount: 0,
        openWishCount: 0,
        lastReminderAt: null,
        coaches: [{ coachId: "c1", firstName: "Maxime", lastName: "Durand", email: null, token: "a".repeat(64), respondedAt: null, sentAt: null }],
      }),
    );
    renderHub();

    await userEvent.click(screen.getByRole("button", { name: /Créer la collecte/ }));
    expect(document.activeElement).toBe(screen.getByRole("tab", { name: /Sollicitation/ }));
  });

  // ── Fenêtre unique (fusion #10, 2026-10-09) : orchestration du hub ──

  const coach = (over: Partial<CoachWishCampaign["coaches"][number]> = {}): CoachWishCampaign["coaches"][number] => ({
    coachId: "c1",
    firstName: "Maxime",
    lastName: "Durand",
    email: null,
    token: "a".repeat(64),
    respondedAt: null,
    sentAt: null,
    ...over,
  });
  const campaignWith = (coaches: CoachWishCampaign["coaches"]): CoachWishCampaign => ({
    id: "camp1",
    calendarEntryId: "e1",
    deadline: "2027-06-30",
    weeks: ["2026-02-16"],
    teamIds: ["t1"],
    totalCoachCount: coaches.length,
    respondedCoachCount: coaches.filter((c) => null !== c.respondedAt).length,
    openWishCount: 0,
    lastReminderAt: null,
    coaches,
  });

  // Décision 1 : le titre est « Doléances des coachs — {période} » PARTOUT, y compris la vue
  // wizard filtrée sur une semaine (plus de variante « — semaine »).
  it("titre « Doléances des coachs — {période} », y compris en vue wizard filtrée semaine", () => {
    campaignsState.data = [];
    wishesState.data = [];
    render(<CoachWishesHub mother={entry} weekFilter="2026-02-16" source="wizard" onClose={vi.fn()} />);
    expect(screen.getByRole("heading", { name: "Doléances des coachs — Vacances de février" })).toBeInTheDocument();
  });

  // Décision 2 : trois onglets, « Sollicitation » (pas « Coachs ») n'existant qu'avec une campagne.
  it("montre trois onglets « Doléances · Sollicitation · Réglages » quand une campagne existe", () => {
    renderHub({ existing: campaignWith([coach()]) });
    expect(screen.getByRole("tab", { name: "Doléances" })).toBeInTheDocument();
    expect(screen.getByRole("tab", { name: /Sollicitation/ })).toBeInTheDocument();
    expect(screen.getByRole("tab", { name: "Réglages" })).toBeInTheDocument();
  });

  // Décision 9 : le libellé de l'onglet COMPTE les coachs sans réponse (« 1 sur 30 ≠ 28 sans
  // réponse sur 30 ») — et ce texte EST le nom accessible de l'onglet.
  it("compte les coachs sans réponse sur le libellé de l'onglet Sollicitation", () => {
    renderHub({ existing: campaignWith([coach({ coachId: "c1", respondedAt: "2026-02-10T10:00:00Z" }), coach({ coachId: "c2", firstName: "Mara", respondedAt: null })]) });
    expect(screen.getByRole("tab", { name: "Sollicitation · 1/2 en attente" })).toBeInTheDocument();
  });

  it("dit « tous ont répondu » quand plus aucun coach n'est en attente", () => {
    renderHub({ existing: campaignWith([coach({ respondedAt: "2026-02-10T10:00:00Z" })]) });
    expect(screen.getByRole("tab", { name: "Sollicitation · tous ont répondu" })).toBeInTheDocument();
  });

  // Décision 3 : spinner tant que les deux lectures (campagnes + doléances) ne sont pas posées.
  it("montre un spinner tant que les lectures ne sont pas posées", () => {
    campaignsState.data = undefined;
    wishesState.data = undefined;
    render(<CoachWishesHub mother={entry} weekFilter={null} source="cockpit" onClose={vi.fn()} />);
    expect(screen.getByLabelText("Chargement")).toBeInTheDocument();
    expect(screen.queryByRole("tab")).toBeNull();
  });

  // Un échec de lecture ne se rend JAMAIS comme « pas de campagne » (règle readState, décision 3) :
  // LoadErrorHint + Réessayer, et AUCUN onglet (sinon l'ouverture tomberait sur Réglages à tort).
  it("montre LoadErrorHint (jamais un « pas de campagne ») quand la lecture échoue", () => {
    campaignsState.data = undefined;
    campaignsState.isError = true;
    wishesState.data = undefined;
    wishesState.isError = true;
    render(<CoachWishesHub mother={entry} weekFilter={null} source="cockpit" onClose={vi.fn()} />);
    expect(screen.getByRole("alert")).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Réessayer" })).toBeInTheDocument();
    expect(screen.queryByRole("tab")).toBeNull();
  });

  // Onglet d'ouverture (décision 3). Depuis le wizard : TOUJOURS Doléances, même si la campagne
  // demande une correction de semaines.
  it("ouvre sur Doléances depuis le wizard, même avec une campagne à corriger", () => {
    setTodayOverride("2026-02-25"); // semaine du 16 révolue → correction attendue côté réglages
    campaignsState.data = [{ ...campaignWith([coach()]), weeks: ["2026-02-16"], deadline: "2026-03-01" }];
    wishesState.data = [];
    render(<CoachWishesHub mother={entry} weekFilter={null} source="wizard" onClose={vi.fn()} />);
    expect(screen.getByRole("tab", { name: "Doléances" })).toHaveAttribute("aria-selected", "true");
  });

  // Depuis le cockpit, sans campagne mais avec des doléances déjà saisies → Doléances.
  it("ouvre sur Doléances (cockpit) sans campagne mais avec des doléances saisies", () => {
    renderHub({ wishes: [{ id: "w1" }] });
    expect(screen.getByRole("tab", { name: "Doléances" })).toHaveAttribute("aria-selected", "true");
    expect(screen.queryByRole("tab", { name: /Sollicitation/ })).toBeNull();
  });
});
